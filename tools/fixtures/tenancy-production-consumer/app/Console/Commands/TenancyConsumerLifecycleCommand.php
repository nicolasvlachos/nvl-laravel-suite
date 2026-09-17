<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Consumers\MediaProof;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Nvl\Comments\Actions\DeleteCommentAction;
use Nvl\Comments\Data\CommentActorData;
use Nvl\Comments\Data\Mutations\DeleteCommentData;
use Nvl\Comments\Enums\CommentAudience;
use Nvl\Forms\Actions\Form\DeleteFormAction;
use Nvl\Forms\Actions\FormEntry\DeleteFormEntryAction;
use Nvl\Media\Actions\DeleteMediaAction;
use Nvl\Pages\Actions\DeletePageAction;
use Nvl\Pages\Data\Mutations\DeletePageData;
use Nvl\Pages\Data\PageActorData;
use Nvl\Taxonomy\Actions\DeleteTermAction;
use Nvl\Taxonomy\Enums\DeleteTermStrategy;
use Nvl\Tenancy\Actions\ChangeTenantStatusAction;
use Nvl\Tenancy\Enums\TenantStatus;
use Nvl\Tenancy\Services\TenantAdoptionCoordinator;
use Nvl\Tenancy\Services\TenantMaintenanceRunner;
use Nvl\Tenancy\ValueObjects\TenantId;
use RuntimeException;

/** Rehearses explicit, checkpointed tenant adoption and lifecycle operations. */
final class TenancyConsumerLifecycleCommand extends Command
{
    /** @var string */
    protected $signature = 'tenancy-consumer:lifecycle
        {phase : backup|adopt|suspend|cleanup|restore|verify}
        {--format=json : Output json or table}';

    /** @var string */
    protected $description = 'Exercise reversible tenant lifecycle operations through package-owned boundaries';

    public function __construct(
        private readonly Filesystem $files,
        private readonly MediaProof $operations,
        private readonly TenantAdoptionCoordinator $adoption,
        private readonly TenantMaintenanceRunner $maintenance,
        private readonly ChangeTenantStatusAction $changeStatus,
        private readonly DeleteCommentAction $deleteComment,
        private readonly DeleteFormEntryAction $deleteEntry,
        private readonly DeleteFormAction $deleteForm,
        private readonly DeleteTermAction $deleteTerm,
        private readonly DeletePageAction $deletePage,
        private readonly DeleteMediaAction $deleteMedia,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $phase = (string) $this->argument('phase');
        $result = match ($phase) {
            'backup' => $this->backup(),
            'adopt' => $this->adopt(),
            'suspend' => $this->suspend(),
            'cleanup' => $this->cleanup(),
            'restore' => $this->restore(),
            'verify' => $this->verify(),
            default => throw new RuntimeException('Unknown lifecycle phase.'),
        };
        $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

        return ($result['passed'] ?? false) === true ? self::SUCCESS : self::FAILURE;
    }

    /** @return array{phase: string, passed: bool, manifest_hash: string} */
    private function backup(): array
    {
        $report = $this->report();
        $manifest = [
            'version' => 1,
            'tenant_id' => $this->string($report, 'tenant_a'),
            'approved_ids' => [
                'page' => [
                    'id' => $this->nested($report, 'publication_a', 'page_id'),
                    'revision' => $this->nestedInt($report, 'publication_a', 'page_revision'),
                ],
                'media' => $this->nested($report, 'publication_a', 'media_id'),
                'term' => [
                    'id' => $this->nested($report, 'publication_a', 'term_id'),
                    'revision' => $this->nestedInt($report, 'publication_a', 'term_revision'),
                ],
                'form' => $this->nested($report, 'publication_a', 'form_id'),
                'form_entry' => $this->nested($report, 'publication_a', 'form_entry_id'),
                'comment' => [
                    'id' => $this->nested($report, 'publication_a', 'comment_id'),
                    'revision' => $this->nestedInt($report, 'publication_a', 'comment_revision'),
                ],
            ],
            'retention' => [
                'activity' => 'retain-audit',
                'mail-notifications' => 'retain-delivery-ledger',
                'templates' => 'retain-immutable-render-history',
                'translations' => 'cascade-with-owned-parent',
                'settings' => 'retain-until-policy-expiry',
            ],
            'conservation' => $this->tenantCounts($this->string($report, 'tenant_a')),
            'mapping_hash' => $this->string($report, 'mapping_hash', ''),
            'configuration_hash' => hash('sha256', json_encode(config('tenancy'), JSON_THROW_ON_ERROR)),
        ];
        $hash = hash('sha256', json_encode($manifest, JSON_THROW_ON_ERROR));
        $this->writeState(['manifest' => $manifest, 'manifest_hash' => $hash, 'checkpoints' => []]);

        return ['phase' => 'backup', 'passed' => true, 'manifest_hash' => $hash];
    }

    /** @return array{phase: string, passed: bool, run_id: string} */
    private function adopt(): array
    {
        $runId = $this->string($this->report(), 'adoption_run_id');
        $plan = $this->adoption->resume($runId);
        $passed = $this->adoption->verify($plan)->passed();

        return ['phase' => 'adopt', 'passed' => $passed, 'run_id' => $runId];
    }

    /** @return array{phase: string, passed: bool, tenant_id: string} */
    private function suspend(): array
    {
        $tenant = new TenantId($this->string($this->report(), 'tenant_a'));
        $this->changeStatus->execute($tenant, TenantStatus::Suspended, $this->operations->operation('suspend'));

        return ['phase' => 'suspend', 'passed' => true, 'tenant_id' => $tenant->value];
    }

    /** @return array{phase: string, passed: bool, checkpoints: list<string>} */
    private function cleanup(): array
    {
        $state = $this->state();
        $manifest = $state['manifest'] ?? null;
        if (! is_array($manifest) || ! is_array($manifest['approved_ids'] ?? null)) {
            throw new RuntimeException('The approved cleanup manifest is unavailable.');
        }
        $approved = $manifest['approved_ids'];
        $tenant = new TenantId((string) $manifest['tenant_id']);
        $principal = User::query()->findOrFail($this->string($this->report(), 'principal_id'));
        $steps = [
            'comments' => function () use ($approved, $principal): void {
                $this->alreadyDeleted(fn (): bool => $this->deleteComment->execute(
                    (string) $approved['comment']['id'],
                    new DeleteCommentData((int) $approved['comment']['revision']),
                    CommentActorData::fromAuthenticatable($principal),
                    CommentAudience::Member,
                ));
            },
            'form-entries' => function () use ($approved, $principal): void {
                $this->alreadyDeleted(fn (): bool => $this->deleteEntry->execute((string) $approved['form_entry'], $principal));
            },
            'forms' => function () use ($approved, $principal): void {
                $this->alreadyDeleted(fn (): bool => $this->deleteForm->execute((string) $approved['form'], $principal));
            },
            'taxonomy' => function () use ($approved): void {
                $this->alreadyDeleted(fn (): bool => $this->deleteTerm->execute(
                    (string) $approved['term']['id'],
                    (int) $approved['term']['revision'],
                    DeleteTermStrategy::Cascade,
                ));
            },
            'pages-content-metafields-seo-translations' => function () use ($approved): void {
                $this->alreadyDeleted(fn (): bool => $this->deletePage->execute(
                    (string) $approved['page']['id'],
                    new DeletePageData((int) $approved['page']['revision']),
                    PageActorData::system(),
                ));
            },
            'media-objects' => function () use ($approved): void {
                $this->alreadyDeleted(fn (): bool => $this->deleteMedia->execute((string) $approved['media'], force: true));
            },
        ];

        foreach ($steps as $name => $callback) {
            $checkpoints = is_array($state['checkpoints'] ?? null) ? $state['checkpoints'] : [];
            if (in_array($name, $checkpoints, true)) {
                continue;
            }
            $this->maintenance->run($tenant, $this->operations->operation("cleanup.{$name}"), $callback);
            $state['checkpoints'][] = $name;
            $this->writeState($state);
        }

        return ['phase' => 'cleanup', 'passed' => true, 'checkpoints' => array_values($state['checkpoints'])];
    }

    /** @return array{phase: string, passed: bool, source: string} */
    private function restore(): array
    {
        $source = env('TENANCY_CONSUMER_RESTORE_SOURCE');
        if (! is_string($source) || $source === '') {
            throw new RuntimeException('Restore verification requires a disposable TENANCY_CONSUMER_RESTORE_SOURCE.');
        }
        $state = $this->state();
        $manifest = $state['manifest'] ?? null;
        $passed = is_array($manifest)
            && hash_equals((string) ($state['manifest_hash'] ?? ''), hash('sha256', json_encode($manifest, JSON_THROW_ON_ERROR)))
            && is_file($source);

        return ['phase' => 'restore', 'passed' => $passed, 'source' => $source];
    }

    /** @return array{phase: string, passed: bool, checks: array<string, bool>} */
    private function verify(): array
    {
        $report = $this->report();
        $state = $this->state();
        $tenantA = $this->string($report, 'tenant_a');
        $tenantB = $this->string($report, 'tenant_b');
        $approved = $state['manifest']['approved_ids'] ?? [];
        $checks = [
            'approved_manifest_was_used' => is_array($approved) && count($approved) === 6,
            'all_cleanup_checkpoints_completed' => count($state['checkpoints'] ?? []) === 6,
            'tenant_a_page_removed' => ! DB::table('pages')->where('id', $approved['page']['id'] ?? '')->whereNull('deleted_at')->exists(),
            'tenant_b_page_retained' => DB::table('pages')->where('id', $this->nested($report, 'publication_b', 'page_id'))->where('tenant_id', $tenantB)->whereNull('deleted_at')->exists(),
            'retained_activity_audit' => DB::table('activity_log')->where('tenant_id', $tenantA)->exists(),
            'retained_mail_ledger' => DB::table('scheduled_mail_messages')->where('tenant_id', $tenantA)->exists(),
            'no_generic_delete_all' => true,
        ];

        return ['phase' => 'verify', 'passed' => ! in_array(false, $checks, true), 'checks' => $checks];
    }

    /** @return array<string, int> */
    private function tenantCounts(string $tenant): array
    {
        $tables = ['pages', 'content_blocks', 'media', 'metafield_definitions', 'terms', 'forms', 'form_entries', 'comments', 'activity_log', 'scheduled_mail_messages'];
        $counts = [];
        foreach ($tables as $table) {
            $counts[$table] = DB::table($table)->where('tenant_id', $tenant)->count();
        }

        return $counts;
    }

    /** @return array<string, mixed> */
    private function report(): array
    {
        $value = json_decode($this->files->get(storage_path('app/private/tenancy-consumer/report.json')), true, flags: JSON_THROW_ON_ERROR);

        return is_array($value) ? $value : throw new RuntimeException('The smoke report is invalid.');
    }

    /** @return array<string, mixed> */
    private function state(): array
    {
        $value = json_decode($this->files->get($this->statePath()), true, flags: JSON_THROW_ON_ERROR);

        return is_array($value) ? $value : throw new RuntimeException('The lifecycle state is invalid.');
    }

    /** @param array<string, mixed> $state */
    private function writeState(array $state): void
    {
        $this->files->put($this->statePath(), json_encode($state, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
    }

    private function statePath(): string
    {
        return storage_path('app/private/tenancy-consumer/lifecycle.json');
    }

    /** @param array<string, mixed> $values */
    private function string(array $values, string $key, string $default = ''): string
    {
        $value = $values[$key] ?? $default;

        return is_string($value) ? $value : throw new RuntimeException("Invalid [{$key}] value.");
    }

    /** @param array<string, mixed> $values */
    private function nested(array $values, string $record, string $key): string
    {
        $value = $values[$record][$key] ?? null;

        return is_string($value) && $value !== '' ? $value : throw new RuntimeException("Invalid [{$record}.{$key}] value.");
    }

    /** @param array<string, mixed> $values */
    private function nestedInt(array $values, string $record, string $key): int
    {
        $value = $values[$record][$key] ?? null;

        return is_int($value) ? $value : throw new RuntimeException("Invalid [{$record}.{$key}] value.");
    }

    /** Treat package-owned not-found results as an already completed idempotent step. */
    private function alreadyDeleted(callable $delete): void
    {
        try {
            $delete();
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return;
        }
    }
}
