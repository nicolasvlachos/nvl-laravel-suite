<?php

declare(strict_types=1);

namespace Nvl\Content\Tenancy;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use Nvl\Content\Contracts\ContentOwner;
use Nvl\Content\Services\ContentOwnerRegistry;
use Nvl\Tenancy\Contracts\TenantParentResolver;

/** Exposes Content's registered owner morph aliases to the tenant boundary. */
final readonly class ContentTenantParentResolver implements TenantParentResolver
{
    /** Create the resolver from immutable owner registrations. */
    public function __construct(
        private ContentOwnerRegistry $owners,
        private Repository $configuration,
    ) {}

    /** @return array<string, class-string<Model>> */
    public function types(): array
    {
        $types = [];
        foreach ($this->owners->aliases() as $alias) {
            $types[$alias] = $this->owners->model($alias);
        }

        if ($types !== []) {
            return $types;
        }

        $configured = $this->configuration->get('content.owners', []);
        if (! is_array($configured)) {
            return [];
        }
        foreach ($configured as $alias => $model) {
            if (is_string($alias) && is_string($model)
                && is_a($model, Model::class, true)
                && is_a($model, ContentOwner::class, true)) {
                $types[$alias] = $model;
            }
        }

        return $types;
    }
}
