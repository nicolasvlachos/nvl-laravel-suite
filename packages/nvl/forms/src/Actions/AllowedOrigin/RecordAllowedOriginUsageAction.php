<?php

declare(strict_types=1);

namespace Nvl\Forms\Actions\AllowedOrigin;

use Nvl\Forms\Models\AllowedOrigin;
use Nvl\Tenancy\Services\TenantBoundary;

/**
 * Records usage metadata for an allowed-origin rule.
 */
final class RecordAllowedOriginUsageAction
{
    /** Create the ownership-aware usage recorder. */
    public function __construct(private readonly TenantBoundary $boundary) {}

    /**
     * Increment usage count and update the last-used timestamp atomically.
     *
     * @param  AllowedOrigin  $allowedOrigin  The origin record to update
     */
    public function execute(AllowedOrigin $allowedOrigin): void
    {
        $this->boundary->assertRecord($allowedOrigin, 'forms.origins');
        $allowedOrigin->increment('usage_count', 1, ['last_used_at' => now()]);
    }
}
