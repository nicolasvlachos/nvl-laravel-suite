<?php

declare(strict_types=1);

namespace Nvl\Seo\Tenancy;

use Illuminate\Database\Eloquent\Model;
use Nvl\Seo\Services\SeoOwnerRegistry;
use Nvl\Tenancy\Contracts\TenantParentResolver;

/** Exposes registered SEO owner morph types to the tenant boundary. */
final readonly class SeoTenantParentResolver implements TenantParentResolver
{
    public function __construct(private SeoOwnerRegistry $owners) {}

    /** @return array<string, class-string<Model>> */
    public function types(): array
    {
        $types = [];
        foreach ($this->owners->configured() as $alias => $model) {
            $types[(new $model)->getMorphClass()] = $model;
        }

        return $types;
    }
}
