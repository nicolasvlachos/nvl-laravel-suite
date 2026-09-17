<?php

declare(strict_types=1);

namespace Nvl\Templates\Services;

use Nvl\Content\Contracts\ContentCatalogCopyAccess;
use Nvl\Templates\Models\TemplateTenantGrant;
use Nvl\Templates\Enums\TemplateVersionStatus;
use Nvl\Tenancy\Exceptions\TenantBoundaryViolation;
use Nvl\Tenancy\ValueObjects\TenantId;

final readonly class TemplateContentCopyAccess implements ContentCatalogCopyAccess
{
    public function assertAllowed(string $ownerId, string $group, string $grantId, TenantId $destination): void
    {
        $grant = TemplateTenantGrant::query()->with(['version.template'])->whereKey($grantId)->first();
        if (! $grant instanceof TemplateTenantGrant || $group !== \Nvl\Templates\Models\TemplateVersion::CONTENT_GROUP
            || $grant->template_version_id !== $ownerId || $grant->recipient_tenant_id !== $destination->value
            || $grant->revoked_at !== null || $grant->version->revision !== $grant->source_revision
            || $grant->version->status !== TemplateVersionStatus::Published
            || $grant->version->template->tenant_id !== null || $grant->version->template->ownership_key !== 'platform') {
            throw new TenantBoundaryViolation('Template Content catalog grant is unavailable.');
        }
    }
}
