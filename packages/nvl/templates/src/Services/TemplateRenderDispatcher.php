<?php

declare(strict_types=1);

namespace Nvl\Templates\Services;

use Nvl\Templates\Jobs\RenderTemplateJob;
use Nvl\Templates\Models\TemplateRender;
use Nvl\Tenancy\Contracts\TenantContext;
use Nvl\Tenancy\Services\TenantBoundary;
use Nvl\Tenancy\ValueObjects\TenantJobEnvelope;

/**
 * Dispatches persisted renders onto the package-configured queue boundary.
 */
final readonly class TemplateRenderDispatcher
{
    public function __construct(
        private TenantContext $context,
        private TenantBoundary $boundary,
    ) {}

    /**
     * Dispatch one persisted render after its owning transaction has committed.
     */
    public function dispatch(string $renderId, ?TenantJobEnvelope $envelope = null): void
    {
        $render = $this->boundary->query(TemplateRender::query(), 'templates.renders')
            ->findOrFail($renderId);
        $connection = config('templates.rendering.connection');
        $queue = config('templates.rendering.queue');
        $pending = RenderTemplateJob::dispatch(
            $render->id,
            $render->dispatch_generation,
            $envelope ?? TenantJobEnvelope::capture($this->context),
        );

        if (is_string($connection) && $connection !== '') {
            $pending->onConnection($connection);
        }

        if (is_string($queue) && $queue !== '') {
            $pending->onQueue($queue);
        }
    }
}
