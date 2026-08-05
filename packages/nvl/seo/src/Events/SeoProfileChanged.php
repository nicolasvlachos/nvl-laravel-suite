<?php

declare(strict_types=1);

namespace Nvl\Seo\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Announces a durable SEO profile mutation.
 */
final class SeoProfileChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly string $profileId,
        public readonly string $scope,
        public readonly string $operation,
    ) {}
}
