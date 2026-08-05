<?php

declare(strict_types=1);

namespace Nvl\Forms\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Nvl\Forms\Data\FormEntryPayload;
use Nvl\Forms\Models\FormEntry;

interface CreateFormEntryContract
{
    /**
     * Execute the form entry creation with comprehensive security checks.
     *
     * @param  FormEntryPayload  $data  Validated form entry data
     * @param  string  $ipAddress  Request IP address
     * @param  string|null  $userAgent  Request user agent
     * @param  string|null  $sessionId  Request session identifier
     * @param  Authenticatable|null  $actor  Authenticated actor, if any
     * @return FormEntry The created form entry instance
     */
    public function execute(
        FormEntryPayload $data,
        string $ipAddress,
        ?string $userAgent,
        ?string $sessionId,
        ?Authenticatable $actor = null,
        ?string $idempotencyKey = null,
        ?float $trustedFormLoadTime = null,
    ): FormEntry;
}
