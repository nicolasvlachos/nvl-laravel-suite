<?php

declare(strict_types=1);

namespace Nvl\Templates\Support\PdfConfig\Data;

/**
 * Optional PDF permissions and passwords.
 */
final readonly class ProtectionData
{
    /**
     * @param  list<string>  $permissions
     */
    public function __construct(
        public bool $enabled = false,
        public array $permissions = [],
        public ?string $userPassword = null,
        public ?string $ownerPassword = null,
    ) {}
}
