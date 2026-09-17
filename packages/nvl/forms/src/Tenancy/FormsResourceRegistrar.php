<?php

declare(strict_types=1);

namespace Nvl\Forms\Tenancy;

use Nvl\Forms\Models\AllowedOrigin;
use Nvl\Forms\Models\Form;
use Nvl\Forms\Models\FormAnalytic;
use Nvl\Forms\Models\FormEntry;
use Nvl\Forms\Models\FormRateLimit;
use Nvl\Forms\Models\FormSubmissionReceipt;
use Nvl\Forms\Models\FormTranslation;
use Nvl\Tenancy\Enums\TenantResourceKind;
use Nvl\Tenancy\Services\TenantAdoptionRegistry;
use Nvl\Tenancy\Services\TenantResourceRegistry;
use Nvl\Tenancy\ValueObjects\TenantResourceDefinition;

/** Registers the canonical Form ownership graph and package adopter. */
final readonly class FormsResourceRegistrar
{
    /** Register root and inherited form resources. */
    public function register(TenantResourceRegistry $resources, TenantAdoptionRegistry $adapters): void
    {
        foreach ([
            new TenantResourceDefinition('forms.forms', 'forms', Form::class),
            new TenantResourceDefinition('forms.entries', 'forms', FormEntry::class, TenantResourceKind::Inherited, 'forms.forms', 'form'),
            new TenantResourceDefinition('forms.receipts', 'forms', FormSubmissionReceipt::class, TenantResourceKind::Inherited, 'forms.forms', 'form'),
            new TenantResourceDefinition('forms.origins', 'forms', AllowedOrigin::class, TenantResourceKind::Inherited, 'forms.forms', 'form'),
            new TenantResourceDefinition('forms.rates', 'forms', FormRateLimit::class, TenantResourceKind::Inherited, 'forms.forms', 'form'),
            new TenantResourceDefinition('forms.analytics', 'forms', FormAnalytic::class, TenantResourceKind::Inherited, 'forms.forms', 'form'),
            new TenantResourceDefinition('forms.translations', 'forms', FormTranslation::class, TenantResourceKind::Inherited, 'forms.forms', 'form'),
        ] as $resource) {
            $resources->register($resource);
        }

        $adapters->register('forms', FormsAdoptionAdapter::class);
    }
}
