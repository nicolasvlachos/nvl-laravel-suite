<?php

declare(strict_types=1);

namespace Nvl\Templates\Services;

use Nvl\Templates\Jobs\RenderTemplateJob;
use Nvl\Templates\Models\TemplateRender;

/**
 * Dispatches persisted renders onto the package-configured queue boundary.
 */
final class TemplateRenderDispatcher
{
    /**
     * Dispatch one persisted render after its owning transaction has committed.
     */
    public function dispatch(string $renderId): void
    {
        $render = TemplateRender::query()->findOrFail($renderId);
        $connection = config('templates.rendering.connection');
        $queue = config('templates.rendering.queue');
        $pending = RenderTemplateJob::dispatch(
            $render->id,
            $render->dispatch_generation,
        );

        if (is_string($connection) && $connection !== '') {
            $pending->onConnection($connection);
        }

        if (is_string($queue) && $queue !== '') {
            $pending->onQueue($queue);
        }
    }
}
