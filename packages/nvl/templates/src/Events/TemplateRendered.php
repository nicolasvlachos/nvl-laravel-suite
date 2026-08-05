<?php

declare(strict_types=1);

namespace Nvl\Templates\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Announces a completed durable render.
 */
final class TemplateRendered implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly string $renderId,
        public readonly string $templateId,
        public readonly string $versionId,
    ) {}
}
