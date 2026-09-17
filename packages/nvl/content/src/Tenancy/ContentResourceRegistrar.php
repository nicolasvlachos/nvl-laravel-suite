<?php

declare(strict_types=1);

namespace Nvl\Content\Tenancy;

use Nvl\Content\Models\ContentBlock;
use Nvl\Content\Models\ContentBlockTranslation;
use Nvl\Content\Models\ContentDefinition;
use Nvl\Content\Models\ContentPlacement;
use Nvl\Content\Models\ContentRevision;
use Nvl\Tenancy\Enums\TenantResourceKind;
use Nvl\Tenancy\Services\TenantAdoptionRegistry;
use Nvl\Tenancy\Services\TenantResourceRegistry;
use Nvl\Tenancy\ValueObjects\TenantResourceDefinition;

/** Registers the immutable Content ownership graph and its adopter. */
final class ContentResourceRegistrar
{
    /** Register every Content resource and the package-owned adoption adapter. */
    public function register(TenantResourceRegistry $resources, TenantAdoptionRegistry $adoption): void
    {
        $resources->requireCompatible('content', 'media');
        $resources->registerParentResolver('content.placements', ContentTenantParentResolver::class);
        foreach ([
            new TenantResourceDefinition('content.definitions', 'content', ContentDefinition::class, TenantResourceKind::Platform),
            new TenantResourceDefinition('content.blocks', 'content', ContentBlock::class),
            new TenantResourceDefinition('content.translations', 'content', ContentBlockTranslation::class, TenantResourceKind::Inherited, 'content.blocks', 'block'),
            new TenantResourceDefinition('content.revisions', 'content', ContentRevision::class, TenantResourceKind::Inherited, 'content.blocks', 'block'),
            new TenantResourceDefinition('content.placements', 'content', ContentPlacement::class, TenantResourceKind::Inherited, null, 'owner'),
        ] as $definition) {
            $resources->register($definition);
        }
        $adoption->register('content', ContentAdoptionAdapter::class);
    }
}
