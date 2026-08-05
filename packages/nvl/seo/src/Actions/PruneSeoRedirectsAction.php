<?php

declare(strict_types=1);

namespace Nvl\Seo\Actions;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Nvl\Seo\Models\SeoRedirect;

/**
 * Permanently removes soft-deleted redirects after a retention period.
 */
final class PruneSeoRedirectsAction
{
    /**
     * Prune redirects deleted at least the requested number of days ago.
     */
    public function execute(int $retentionDays = 30): int
    {
        if ($retentionDays < 1) {
            throw new InvalidArgumentException('SEO redirect retention must be at least one day.');
        }

        $redirects = (new SeoRedirect)->getTable();
        $deletedBefore = now()->subDays($retentionDays);

        return DB::transaction(
            static fn (): int => DB::table($redirects)
                ->whereNotNull('deleted_at')
                ->where('deleted_at', '<=', $deletedBefore)
                ->delete(),
        );
    }
}
