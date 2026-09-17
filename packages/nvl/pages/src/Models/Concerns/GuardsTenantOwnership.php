<?php

declare(strict_types=1);

namespace Nvl\Pages\Models\Concerns;

use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Nvl\Tenancy\Enums\TenantResourceKind;
use Nvl\Tenancy\Exceptions\TenantBoundaryViolation;
use Nvl\Tenancy\Services\TenantBoundary;
use Nvl\Tenancy\Services\TenantResourceRegistry;

/** Applies Pages' mandatory tenant predicate and immutable ownership stamps. */
trait GuardsTenantOwnership
{
    /** Register the package-owned query and mutation boundary. */
    protected static function bootGuardsTenantOwnership(): void
    {
        static::addGlobalScope('tenant-boundary', static function (Builder $query): void {
            Container::getInstance()->make(TenantBoundary::class)->query($query, self::TENANT_RESOURCE);
        });
        static::creating(static function (Model $model): void {
            $container = Container::getInstance();
            if ($container->make('config')->get('tenancy.enabled') !== true) {
                return;
            }
            $boundary = $container->make(TenantBoundary::class);
            $definition = $container->make(TenantResourceRegistry::class)->get(self::TENANT_RESOURCE);
            if ($definition->kind === TenantResourceKind::Root) {
                $model->forceFill($boundary->attributes(self::TENANT_RESOURCE));

                return;
            }
            $relation = Relation::noConstraints(static fn () => $model->{$definition->parentRelation}());
            $parent = $relation->getResults();
            if (! $parent instanceof Model) {
                throw new TenantBoundaryViolation('Page child ownership requires a canonical parent.');
            }
            $parentDefinition = $container->make(TenantResourceRegistry::class)->forModel($parent);
            $boundary->assertRecord($parent, $parentDefinition->key);
            $tenantId = $parent->getRawOriginal('tenant_id') ?? $parent->getAttribute('tenant_id');
            if (! is_string($tenantId)) {
                throw new TenantBoundaryViolation('Page child ownership requires a tenant parent.');
            }
            $model->forceFill(['tenant_id' => $tenantId]);
        });
        static::updating(static function (Model $model): void {
            if ($model->isDirty('tenant_id')) {
                throw new TenantBoundaryViolation('Persisted Page ownership is immutable.');
            }
            Container::getInstance()->make(TenantBoundary::class)->assertRecord($model, self::TENANT_RESOURCE);
        });
    }
}
