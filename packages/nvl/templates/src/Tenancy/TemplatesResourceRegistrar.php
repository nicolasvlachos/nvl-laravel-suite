<?php

declare(strict_types=1);

namespace Nvl\Templates\Tenancy;

use Nvl\Templates\Models\Template;
use Nvl\Templates\Models\TemplateAssignment;
use Nvl\Templates\Models\TemplateRender;
use Nvl\Templates\Models\TemplateTranslation;
use Nvl\Templates\Models\TemplateVersion;
use Nvl\Tenancy\Enums\TenantResourceKind;
use Nvl\Tenancy\Services\TenantAdoptionRegistry;
use Nvl\Tenancy\Services\TenantResourceRegistry;
use Nvl\Tenancy\ValueObjects\TenantResourceDefinition;

final readonly class TemplatesResourceRegistrar
{
    public function register(TenantResourceRegistry $resources, TenantAdoptionRegistry $adapters): void
    {
        foreach ([
            new TenantResourceDefinition('templates.templates', 'templates', Template::class, allowsPlatformCatalog: true),
            new TenantResourceDefinition('templates.translations', 'templates', TemplateTranslation::class, TenantResourceKind::Inherited, 'templates.templates', 'template'),
            new TenantResourceDefinition('templates.versions', 'templates', TemplateVersion::class, TenantResourceKind::Inherited, 'templates.templates', 'template'),
            new TenantResourceDefinition('templates.assignments', 'templates', TemplateAssignment::class, TenantResourceKind::Inherited, 'templates.templates', 'template'),
            new TenantResourceDefinition('templates.renders', 'templates', TemplateRender::class, TenantResourceKind::Inherited, 'templates.templates', 'template'),
        ] as $resource) {
            $resources->register($resource);
        }
        $adapters->register('templates', TemplatesAdoptionAdapter::class);
    }
}
