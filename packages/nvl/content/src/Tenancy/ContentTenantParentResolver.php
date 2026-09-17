<?php

declare(strict_types=1);

namespace Nvl\Content\Tenancy;

use Illuminate\Database\Eloquent\Model;
use Nvl\Content\Services\ContentOwnerRegistry;
use Nvl\Tenancy\Contracts\TenantParentResolver;

/** Exposes Content's registered owner morph aliases to the tenant boundary. */
final readonly class ContentTenantParentResolver implements TenantParentResolver
{
    /** Create the resolver from immutable owner registrations. */
    public function __construct(private ContentOwnerRegistry $owners) {}

    /** @return array<string, class-string<Model>> */
    public function types(): array
    {
        $types = [];
        foreach ($this->owners->aliases() as $alias) {
            $types[$alias] = $this->owners->model($alias);
        }

        return $types;
    }
}
