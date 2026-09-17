<?php
declare(strict_types=1);
namespace Nvl\Content\Contracts;
use Nvl\Tenancy\ValueObjects\TenantId;
interface ContentCatalogCopyAccess
{
    public function assertAllowed(string $ownerId, string $group, string $grantId, TenantId $destination): void;
}
