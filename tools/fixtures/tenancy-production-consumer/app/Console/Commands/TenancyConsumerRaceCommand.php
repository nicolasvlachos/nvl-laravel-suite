<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\TenantArticle;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Cache;
use Nvl\Auth\Actions\Memberships\RevokeMembershipAction;
use Nvl\Auth\Models\TenantMembership;
use Nvl\Auth\ValueObjects\SystemMutationContext;
use Nvl\Forms\Actions\FormEntry\CreateFormEntryAction;
use Nvl\Forms\Data\FormEntryPayload;
use Nvl\Media\Actions\AttachMediaAction;
use Nvl\Media\Models\Media;
use Nvl\Pages\Actions\CreatePageAction;
use Nvl\Pages\Data\Mutations\CreatePageData;
use Nvl\Pages\Data\PageActorData;
use Nvl\Tenancy\Services\TenantRunner;
use Nvl\Tenancy\ValueObjects\TenantId;
use Throwable;

/** Runs one half of a deliberately competing package operation. */
final class TenancyConsumerRaceCommand extends Command
{
    /** @var string */
    protected $signature = 'tenancy-consumer:race
        {race : last-owner|grant-revoke-import|slug-handle-create|media-slot-completion|submission-idempotency}
        {competitor : a|b}
        {--barrier=tenancy-consumer-race}';

    /** @var string */
    protected $description = 'Run one separate-process tenant concurrency contender';

    public function __construct(
        private readonly Filesystem $files,
        private readonly TenantRunner $tenants,
        private readonly RevokeMembershipAction $revokeMembership,
        private readonly CreatePageAction $createPage,
        private readonly CreateFormEntryAction $createEntry,
        private readonly AttachMediaAction $attachMedia,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $report = $this->report();
        $race = (string) $this->argument('race');
        $competitor = (string) $this->argument('competitor');
        $tenant = new TenantId((string) $report['tenant_a']);
        $barrier = Cache::lock((string) $this->option('barrier').':'.$race, 30);
        $barrier->block(10, static fn (): null => null);
        $succeeded = true;
        $error = null;
        try {
            $this->tenants->run($tenant, fn (): mixed => match ($race) {
                'last-owner' => $this->revokeLastOwner($report),
                'slug-handle-create' => $this->createSamePage($competitor),
                'media-slot-completion' => $this->completeSameMediaSlot($report),
                'submission-idempotency' => $this->submitSameEntry($report),
                'grant-revoke-import' => $this->grantRaceSentinel($report),
                default => throw new \InvalidArgumentException('Unknown race fixture.'),
            });
        } catch (Throwable $throwable) {
            $succeeded = false;
            $error = $throwable::class;
        }
        $this->line(json_encode(compact('race', 'competitor', 'succeeded', 'error'), JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }

    /** @param array<string, mixed> $report */
    private function revokeLastOwner(array $report): TenantMembership
    {
        $membership = TenantMembership::query()
            ->where('tenant_id', $report['tenant_a'])
            ->where('subject_id', $report['principal_id'])
            ->firstOrFail();

        return $this->revokeMembership->execute(
            new SystemMutationContext('concurrent last-owner proof', 'tenancy-consumer-race'),
            $membership,
            $membership->revision,
        );
    }

    private function createSamePage(string $competitor): mixed
    {
        return $this->createPage->execute(new CreatePageData(
            key: 'pages.concurrent',
            slug: 'concurrent',
            translations: ['en' => ['title' => "Concurrent {$competitor}"]],
        ), PageActorData::system());
    }

    /** @param array<string, mixed> $report */
    private function completeSameMediaSlot(array $report): mixed
    {
        $publication = (array) $report['publication_a'];

        return $this->attachMedia->execute(
            Media::query()->findOrFail($publication['media_id']),
            TenantArticle::query()->findOrFail(((array) $report['asset_a'])['article_id']),
            collection: 'concurrent-slot',
            dispatchVariations: false,
        );
    }

    /** @param array<string, mixed> $report */
    private function submitSameEntry(array $report): mixed
    {
        $publication = (array) $report['publication_a'];

        return $this->createEntry->execute(FormEntryPayload::from([
            'formId' => $publication['form_id'],
            'subject' => 'Concurrent submission',
            'email' => 'race@tenancy-consumer.test',
            'submissionData' => ['proof' => true],
            'submittedFrom' => 'https://auth-media.tenancy-consumer.test/publication',
        ]), '127.0.0.1', 'race', 'race', User::query()->findOrFail($report['principal_id']), 'shared-race-key');
    }

    /** @param array<string, mixed> $report */
    private function grantRaceSentinel(array $report): int
    {
        return app(\Nvl\Metafields\Actions\GrantMetafieldDefinitionToTenantAction::class)->execute(
            (string) $report['platform_metafield_definition_id'],
            new TenantId((string) $report['tenant_a']),
            (int) $report['platform_metafield_definition_revision'],
        )->revision;
    }

    /** @return array<string, mixed> */
    private function report(): array
    {
        $report = json_decode($this->files->get(storage_path('app/private/tenancy-consumer/report.json')), true, flags: JSON_THROW_ON_ERROR);

        return is_array($report) ? $report : throw new \RuntimeException('The consumer report is invalid.');
    }
}
