<?php

declare(strict_types=1);

namespace Nvl\Templates\Actions;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Nvl\Templates\Enums\TemplateRenderStatus;
use Nvl\Templates\Models\TemplateRender;
use Nvl\Templates\Services\TemplateRenderDispatcher;
use Nvl\Templates\Support\TemplatesConfiguration;

/**
 * Requeues durable renders stalled before or during processing.
 */
final readonly class RecoverStaleTemplateRendersAction
{
    /**
     * Create the bounded render-recovery action.
     */
    public function __construct(private TemplateRenderDispatcher $dispatcher) {}

    /**
     * Recover one bounded batch of stale pending or expired processing renders.
     *
     * @return Collection<int, TemplateRender>
     */
    public function execute(): Collection
    {
        return DB::connection(TemplatesConfiguration::connection())
            ->transaction(function (): Collection {
                $limit = TemplatesConfiguration::positiveInteger(
                    'templates.rendering.recovery_batch_size',
                    100,
                );
                $pendingCutoff = now()->subSeconds(
                    TemplatesConfiguration::positiveInteger(
                        'templates.rendering.pending_recovery_seconds',
                        660,
                    ),
                );
                $renders = TemplateRender::query()
                    ->where(function (Builder $query) use ($pendingCutoff): void {
                        $query->where(function (Builder $processing): void {
                            $processing
                                ->where('status', TemplateRenderStatus::Processing->value)
                                ->whereNotNull('lease_expires_at')
                                ->where('lease_expires_at', '<=', now());
                        })->orWhere(function (Builder $pending) use ($pendingCutoff): void {
                            $pending
                                ->where('status', TemplateRenderStatus::Pending->value)
                                ->where('updated_at', '<=', $pendingCutoff);
                        });
                    })
                    ->orderBy('updated_at')
                    ->limit($limit)
                    ->lockForUpdate()
                    ->get();

                foreach ($renders as $render) {
                    $render->fill([
                        'status' => TemplateRenderStatus::Pending,
                        'dispatch_generation' => $render->dispatch_generation + 1,
                        'processing_token' => null,
                        'lease_expires_at' => null,
                        'failure' => null,
                        'failed_at' => null,
                    ])->save();
                }

                $renderIds = $renders->modelKeys();
                DB::connection(TemplatesConfiguration::connection())
                    ->afterCommit(function () use ($renderIds): void {
                        foreach ($renderIds as $renderId) {
                            $this->dispatcher->dispatch((string) $renderId);
                        }
                    });

                return $renders;
            });
    }
}
