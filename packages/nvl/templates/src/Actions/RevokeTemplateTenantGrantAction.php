<?php

declare(strict_types=1);

namespace Nvl\Templates\Actions;

use Illuminate\Support\Facades\DB;
use Nvl\Templates\Exceptions\StaleTemplateException;
use Nvl\Templates\Models\TemplateTenantGrant;
use Nvl\Templates\Support\TemplatesConfiguration;
use Nvl\Tenancy\Contracts\TenantContext;
use Nvl\Tenancy\Enums\TenantContextMode;
use Nvl\Tenancy\Exceptions\TenantBoundaryViolation;

final readonly class RevokeTemplateTenantGrantAction
{
    public function __construct(private TenantContext $context) {}

    public function execute(string $grantId, int $expectedRevision): TemplateTenantGrant
    {
        if ($this->context->snapshot()->mode !== TenantContextMode::Platform) {
            throw new TenantBoundaryViolation('Template catalog revocation requires platform context.');
        }

        return DB::connection(TemplatesConfiguration::connection())->transaction(function () use ($grantId, $expectedRevision): TemplateTenantGrant {
            $grant = TemplateTenantGrant::query()->lockForUpdate()->findOrFail($grantId);
            if ($grant->revision !== $expectedRevision) {
                throw StaleTemplateException::forResource('template grant', $grantId);
            }
            $grant->forceFill(['revoked_at' => now(), 'revision' => $grant->revision + 1])->save();

            return $grant->refresh();
        });
    }
}
