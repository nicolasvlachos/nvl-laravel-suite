<?php

declare(strict_types=1);

namespace Nvl\Pages\Tests\Fixtures;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Nvl\Pages\Contracts\AbstractPageResourceHandler;
use Nvl\Pages\Contracts\TenantSafePageResourceHandler;
use Nvl\Pages\Data\PageResourceData;
use Nvl\Pages\Data\PageResourceRequestData;

/** Tenant-safe dynamic resource handler used to prove query-before-fetch scoping. */
final class TenantPageResourceHandler extends AbstractPageResourceHandler implements TenantSafePageResourceHandler
{
    public function alias(): string
    {
        return 'tenant-records.detail';
    }

    public function routePattern(): string
    {
        return '{slug}';
    }

    /** @return class-string<Model> */
    public function tenantResourceModel(): string
    {
        return TenantPageResource::class;
    }

    /** @return Builder<TenantPageResource> */
    public function query(PageResourceRequestData $request): Builder
    {
        return TenantPageResource::query()->where('is_public', true);
    }

    public function present(Model $resource, PageResourceRequestData $request): PageResourceData
    {
        return new PageResourceData(
            type: $this->alias(),
            id: (string) $resource->getKey(),
            payload: ['title' => $resource->getAttribute('title')],
        );
    }
}
