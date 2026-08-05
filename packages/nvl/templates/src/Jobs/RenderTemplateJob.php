<?php

declare(strict_types=1);

namespace Nvl\Templates\Jobs;

use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\FailOnTimeout;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Str;
use Nvl\Templates\Actions\ProcessTemplateRenderAction;
use Nvl\Templates\Enums\TemplateRenderStatus;
use Nvl\Templates\Models\TemplateRender;
use Nvl\Templates\Support\TemplatesConfiguration;
use Throwable;

/**
 * Idempotent queue boundary for persisted template renders.
 */
#[FailOnTimeout]
final class RenderTemplateJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public int $uniqueFor = 600;

    public readonly string $processingToken;

    /**
     * Create one generation-bound queued render delivery.
     */
    public function __construct(
        public readonly string $renderId,
        public readonly int $dispatchGeneration = 0,
    ) {
        $this->processingToken = (string) Str::uuid();
        $this->tries = TemplatesConfiguration::positiveInteger(
            'templates.rendering.tries',
            3,
        );
        $this->timeout = TemplatesConfiguration::positiveInteger(
            'templates.rendering.timeout',
            60,
        );
        $this->uniqueFor = TemplatesConfiguration::positiveInteger(
            'templates.rendering.unique_for',
            600,
        );
    }

    /**
     * Return the persisted render identifier used by Laravel's dispatch lock.
     */
    public function uniqueId(): string
    {
        return $this->renderId;
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        $backoff = config('templates.rendering.backoff', [10, 30, 90]);

        if (! is_array($backoff)) {
            return [10, 30, 90];
        }

        $normalized = array_values(array_filter(
            $backoff,
            static fn (mixed $delay): bool => is_int($delay) && $delay > 0,
        ));

        return $normalized === [] ? [10, 30, 90] : $normalized;
    }

    /**
     * Prevent concurrent processing of the same durable render.
     *
     * @return list<WithoutOverlapping>
     */
    public function middleware(): array
    {
        $releaseAfter = $this->backoff()[0] ?? 10;
        $leaseSeconds = TemplatesConfiguration::positiveInteger(
            'templates.rendering.lease_seconds',
            75,
        );

        return [
            (new WithoutOverlapping("nvl-templates-render:{$this->renderId}"))
                ->releaseAfter($releaseAfter)
                ->expireAfter($leaseSeconds),
        ];
    }

    /**
     * Process the durable render under this job's stable lease token.
     */
    public function handle(ProcessTemplateRenderAction $action): void
    {
        $action->execute(
            $this->renderId,
            $this->processingToken,
            $this->dispatchGeneration,
        );
    }

    /**
     * Mark terminal queue failures without overwriting a newer processor.
     */
    public function failed(?Throwable $exception): void
    {
        $message = mb_substr(
            $exception?->getMessage() ?? 'Template render job failed.',
            0,
            4_000,
        );
        $updates = [
            'status' => TemplateRenderStatus::Failed->value,
            'processing_token' => null,
            'lease_expires_at' => null,
            'failure' => $message,
            'failed_at' => now(),
        ];

        if (! (bool) config('templates.rendering.store_payload', true)) {
            $updates['payload'] = null;
            $updates['settings'] = null;
        }

        TemplateRender::query()
            ->whereKey($this->renderId)
            ->where('dispatch_generation', $this->dispatchGeneration)
            ->where('status', '!=', TemplateRenderStatus::Completed->value)
            ->where(function (Builder $query): void {
                $query->where('processing_token', $this->processingToken)
                    ->orWhereIn('status', [
                        TemplateRenderStatus::Pending->value,
                        TemplateRenderStatus::Failed->value,
                    ]);
            })
            ->update($updates);
    }
}
