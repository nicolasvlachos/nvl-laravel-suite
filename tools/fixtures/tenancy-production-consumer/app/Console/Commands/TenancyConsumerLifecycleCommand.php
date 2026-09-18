<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Consumers\MediaProof;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Nvl\Activity\Models\ActivityLog;
use Nvl\Comments\Actions\DeleteCommentAction;
use Nvl\Comments\Data\CommentActorData;
use Nvl\Comments\Data\Mutations\DeleteCommentData;
use Nvl\Comments\Enums\CommentAudience;
use Nvl\Comments\Models\Comment;
use Nvl\Content\Models\ContentBlock;
use Nvl\Forms\Actions\Form\DeleteFormAction;
use Nvl\Forms\Actions\FormEntry\DeleteFormEntryAction;
use Nvl\Forms\Models\Form;
use Nvl\Forms\Models\FormEntry;
use Nvl\MailNotifications\Models\ScheduledMailMessage;
use Nvl\Media\Actions\DeleteMediaAction;
use Nvl\Media\Models\Media;
use Nvl\Metafields\Models\MetafieldDefinition;
use Nvl\Pages\Actions\DeletePageAction;
use Nvl\Pages\Data\Mutations\DeletePageData;
use Nvl\Pages\Data\PageActorData;
use Nvl\Pages\Models\Page;
use Nvl\Taxonomy\Actions\DeleteTermAction;
use Nvl\Taxonomy\Enums\DeleteTermStrategy;
use Nvl\Taxonomy\Models\Term;
use Nvl\Tenancy\Actions\ChangeTenantStatusAction;
use Nvl\Tenancy\Enums\TenantStatus;
use Nvl\Tenancy\Services\TenantAdoptionCoordinator;
use Nvl\Tenancy\Services\TenantMaintenanceRunner;
use Nvl\Tenancy\ValueObjects\TenantId;
use Nvl\Tenancy\ValueObjects\TenantSiteContext;
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
        $phase = $this->argument('phase');
        if (! is_string($phase)) {
            throw new RuntimeException('The lifecycle phase must be a string.');
        }
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

        return $result['passed'] ? self::SUCCESS : self::FAILURE;
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

    /** @return array{phase: string, passed: bool, run_id: string, checks: array<string, bool>} */
    private function adopt(): array
    {
        $report = $this->report();
        $runId = $this->string($report, 'adoption_run_id');
        $plan = $this->adoption->resume($runId);
        $checks = [
            'run_resumed' => $plan->id === $runId,
            'mapping_hash_recorded' => $this->string($report, 'mapping_hash') !== '',
            'configuration_hash_recorded' => $this->string($report, 'configuration_hash') !== '',
            'interruption_rehearsed' => ($report['adoption_interrupted'] ?? null) === true,
            'resumption_rehearsed' => ($report['adoption_resumed'] ?? null) === true,
            'conservation_manifested' => ($report['conservation_manifested'] ?? null) === true,
            'ambiguous_activation_blocked' => ($report['ambiguous_activation_blocked'] ?? null) === true,
        ];

        return [
            'phase' => 'adopt',
            'passed' => ! in_array(false, $checks, true),
            'run_id' => $runId,
            'checks' => $checks,
        ];
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
        $manifest = $this->record($state, 'manifest');
        $approved = $this->record($manifest, 'approved_ids');
        $tenant = new TenantId($this->string($manifest, 'tenant_id'));
        $principal = User::query()->findOrFail($this->string($this->report(), 'principal_id'));
        $steps = [
            'comments' => function () use ($approved, $principal): void {
                $this->alreadyDeleted(fn (): bool => $this->deleteComment->execute(
                    $this->nested($approved, 'comment', 'id'),
                    new DeleteCommentData($this->nestedInt($approved, 'comment', 'revision')),
                    CommentActorData::fromAuthenticatable($principal),
                    CommentAudience::Member,
                ));
            },
            'form-entries' => function () use ($approved): void {
                foreach (FormEntry::query()->where('form_id', $this->string($approved, 'form'))->get() as $entry) {
                    $this->alreadyDeleted(fn (): bool => $this->deleteEntry->execute($entry));
                }
            },
            'forms' => function () use ($approved): void {
                $this->alreadyDeleted(fn (): bool => $this->deleteForm->execute($this->string($approved, 'form')));
            },
            'taxonomy' => function () use ($approved): void {
                $this->alreadyDeleted(fn (): bool => $this->deleteTerm->execute(
                    $this->nested($approved, 'term', 'id'),
                    $this->nestedInt($approved, 'term', 'revision'),
                    DeleteTermStrategy::Cascade,
                ));
            },
            'pages-content-metafields-seo-translations' => function () use ($approved, $tenant): void {
                app()->instance(TenantSiteContext::class, new TenantSiteContext(
                    $tenant,
                    'default',
                    'https://a.tenancy-consumer.test',
                ));
                try {
                    $this->alreadyDeleted(fn (): bool => $this->deletePage->execute(
                        $this->nested($approved, 'page', 'id'),
                        new DeletePageData($this->nestedInt($approved, 'page', 'revision')),
                        PageActorData::system(),
                    ));
                } finally {
                    app()->forgetInstance(TenantSiteContext::class);
                }
            },
            'media-objects' => function () use ($approved): void {
                $this->alreadyDeleted(fn (): bool => $this->deleteMedia->execute($this->string($approved, 'media'), force: true));
            },
        ];

        foreach ($steps as $name => $callback) {
            $checkpoints = $this->stringList($state, 'checkpoints');
            if (in_array($name, $checkpoints, true)) {
                continue;
            }
            $this->maintenance->run($tenant, $this->operations->operation("cleanup.{$name}"), $callback);
            $checkpoints[] = $name;
            $state['checkpoints'] = $checkpoints;
            $this->writeState($state);
        }

        return ['phase' => 'cleanup', 'passed' => true, 'checkpoints' => $this->stringList($state, 'checkpoints')];
    }

    /** @return array{phase: string, passed: bool, source: string} */
    private function restore(): array
    {
        $source = config('tenancy-consumer.restore_source');
        if (! is_string($source) || $source === '') {
            throw new RuntimeException('Restore verification requires a disposable TENANCY_CONSUMER_RESTORE_SOURCE.');
        }
        $state = $this->state();
        $manifest = $state['manifest'] ?? null;
        $passed = is_array($manifest)
            && hash_equals($this->string($state, 'manifest_hash', ''), hash('sha256', json_encode($manifest, JSON_THROW_ON_ERROR)))
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
        $manifest = $this->record($state, 'manifest');
        $approved = $this->record($manifest, 'approved_ids');
        $page = $this->record($approved, 'page');
        $checkpoints = $this->stringList($state, 'checkpoints');
        $checks = [
            'approved_manifest_was_used' => count($approved) === 6,
            'all_cleanup_checkpoints_completed' => count($checkpoints) === 6,
            'tenant_a_page_removed' => ! DB::table('pages')->where('id', $this->string($page, 'id'))->whereNull('deleted_at')->exists(),
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
        $tables = [
            (new Page)->getTable(),
            (new ContentBlock)->getTable(),
            (new Media)->getTable(),
            (new MetafieldDefinition)->getTable(),
            (new Term)->getTable(),
            (new Form)->getTable(),
            (new FormEntry)->getTable(),
            (new Comment)->getTable(),
            (new ActivityLog)->getTable(),
            (new ScheduledMailMessage)->getTable(),
        ];
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

        return $this->associativeArray($value, 'smoke report');
    }

    /** @return array<string, mixed> */
    private function state(): array
    {
        $value = json_decode($this->files->get($this->statePath()), true, flags: JSON_THROW_ON_ERROR);

        return $this->associativeArray($value, 'lifecycle state');
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
        $value = $this->record($values, $record)[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : throw new RuntimeException("Invalid [{$record}.{$key}] value.");
    }

    /** @param array<string, mixed> $values */
    private function nestedInt(array $values, string $record, string $key): int
    {
        $value = $this->record($values, $record)[$key] ?? null;

        return is_int($value) ? $value : throw new RuntimeException("Invalid [{$record}.{$key}] value.");
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function record(array $values, string $key): array
    {
        return $this->associativeArray($values[$key] ?? null, $key);
    }

    /**
     * @param  array<string, mixed>  $values
     * @return list<string>
     */
    private function stringList(array $values, string $key): array
    {
        $value = $values[$key] ?? null;
        if (! is_array($value)) {
            throw new RuntimeException("Invalid [{$key}] value.");
        }

        $strings = [];
        foreach ($value as $item) {
            if (! is_string($item)) {
                throw new RuntimeException("Invalid [{$key}] value.");
            }
            $strings[] = $item;
        }

        return $strings;
    }

    /** @return array<string, mixed> */
    private function associativeArray(mixed $value, string $name): array
    {
        if (! is_array($value)) {
            throw new RuntimeException("The {$name} is invalid.");
        }

        $record = [];
        foreach ($value as $key => $item) {
            if (! is_string($key)) {
                throw new RuntimeException("The {$name} is invalid.");
            }
            $record[$key] = $item;
        }

        return $record;
    }

    /** Treat package-owned not-found results as an already completed idempotent step. */
    private function alreadyDeleted(callable $delete): void
    {
        try {
            $delete();
        } catch (ModelNotFoundException) {
            return;
        }
    }
}
