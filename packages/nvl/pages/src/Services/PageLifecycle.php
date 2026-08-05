<?php

declare(strict_types=1);

namespace Nvl\Pages\Services;

use Nvl\Pages\Enums\PageAbility;
use Nvl\Pages\Enums\PageStatus;

/**
 * Maps real page lifecycle transitions to their additional authorization capability.
 */
final class PageLifecycle
{
    /**
     * Return the capability required to enter a lifecycle state.
     */
    public function ability(?PageStatus $current, PageStatus $target): ?PageAbility
    {
        if ($current === $target) {
            return null;
        }

        return match ($target) {
            PageStatus::Published, PageStatus::Scheduled => PageAbility::Publish,
            PageStatus::Archived => PageAbility::Archive,
            PageStatus::Draft => null,
        };
    }
}
