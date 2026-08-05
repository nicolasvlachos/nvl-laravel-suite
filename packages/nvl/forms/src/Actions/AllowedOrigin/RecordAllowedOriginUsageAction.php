<?php

declare(strict_types=1);

namespace Nvl\Forms\Actions\AllowedOrigin;

use Nvl\Forms\Models\AllowedOrigin;

/**
 * Records usage metadata for an allowed-origin rule.
 */
final class RecordAllowedOriginUsageAction
{
    /**
     * Increment usage count and update the last-used timestamp atomically.
     *
     * @param  AllowedOrigin  $allowedOrigin  The origin record to update
     */
    public function execute(AllowedOrigin $allowedOrigin): void
    {
        $allowedOrigin->increment('usage_count', 1, ['last_used_at' => now()]);
    }
}
