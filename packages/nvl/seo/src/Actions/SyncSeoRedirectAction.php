<?php

declare(strict_types=1);

namespace Nvl\Seo\Actions;

use Illuminate\Database\QueryException;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Nvl\Seo\Data\Mutations\SeoRedirectPayload;
use Nvl\Seo\Definitions\Tables\SeoTables;
use Nvl\Seo\Exceptions\InvalidSeoMutationException;
use Nvl\Seo\Exceptions\StaleSeoRedirectException;
use Nvl\Seo\Models\SeoRedirect;
use Nvl\Seo\Services\SeoMutationValidator;
use Nvl\Seo\Services\SeoRedirectChain;
use Nvl\Seo\Support\DatabaseConstraintViolation;
use Nvl\Seo\Support\SeoPath;
use Nvl\Seo\Support\SeoRedirectTarget;
use Nvl\Seo\Support\SeoScope;
use Nvl\Translatable\Services\LocaleRegistry;
use Spatie\LaravelData\Optional;
use Nvl\Tenancy\Contracts\TenantContext;
use Nvl\Tenancy\Services\TenantBoundary;

/**
 * Creates or updates a redirect with loop detection and chain flattening.
 */
final readonly class SyncSeoRedirectAction
{
    public function __construct(
        private SeoRedirectChain $chains,
        private LocaleRegistry $locales,
        private SeoMutationValidator $validator,
    ) {}

    /**
     * Persist one normalized redirect while enforcing optimistic concurrency.
     */
    public function execute(
        SeoRedirect|string|null $redirect,
        SeoRedirectPayload $data,
        ?string $scope = null,
    ): SeoRedirect {
        $this->validator->redirect($data);
        $scope = SeoScope::normalize($scope);
        $locale = $data->locale === null
            ? null
            : $this->locales->assertSupported($data->locale);
        $source = SeoPath::normalize($data->sourcePath) ?? '/';
        $redirectId = $redirect instanceof SeoRedirect ? $redirect->id : $redirect;

        try {
            return DB::transaction(function () use (
                $data,
                $locale,
                $redirectId,
                $scope,
                $source,
            ): SeoRedirect {
                $this->lockGraph();
                $model = $redirectId === null
                    ? SeoRedirect::withTrashed()
                        ->where('source_hash', SeoRedirect::sourceHash($scope, $locale, $source))
                        ->lockForUpdate()
                        ->first()
                    : SeoRedirect::withTrashed()->lockForUpdate()->findOrFail($redirectId);
                $wasDeleted = $model?->trashed() ?? false;
                $previousScope = $model?->scope;
                $previousSource = $model?->source_path;
                $model ??= new SeoRedirect;

                if (is_int($data->expectedRevision)
                    && (($model->exists && ! $wasDeleted
                        && $model->revision !== $data->expectedRevision)
                        || (! $model->exists && $data->expectedRevision !== 0)
                        || ($wasDeleted && $data->expectedRevision !== 0))) {
                    throw StaleSeoRedirectException::forRedirect(
                        $model->exists ? $model->id : 'new',
                    );
                }

                try {
                    $target = $this->chains->flatten(
                        $scope,
                        $locale,
                        $source,
                        SeoRedirectTarget::normalize($data->target),
                        $model->exists ? $model->id : null,
                    );
                } catch (InvalidArgumentException $exception) {
                    throw InvalidSeoMutationException::forField(
                        'target',
                        $exception->getMessage(),
                        $exception,
                    );
                }
                $attributes = [
                    'scope' => $scope,
                    'locale' => $locale,
                    'source_path' => $source,
                    'target' => $target,
                    'status_code' => $data->statusCode,
                    'is_active' => $data->isActive,
                    'expires_at' => $data->expiresAt,
                ];

                if (! $data->metadata instanceof Optional) {
                    $attributes['metadata'] = $data->metadata;
                }

                $model->fill($attributes);

                if ($wasDeleted) {
                    $model->restore();
                } else {
                    $model->save();
                }

                $this->chains->assertAcyclic($scope, $source);

                if ($previousScope !== null && $previousSource !== null
                    && ($previousScope !== $scope || $previousSource !== $source)) {
                    $this->chains->assertAcyclic($previousScope, $previousSource);
                }

                return $model->refresh();
            });
        } catch (QueryException $exception) {
            if (DatabaseConstraintViolation::matches($exception, [
                'seo_redirects_source_hash_unique',
                'seo_redirects_tenant_source_hash_unique',
                'seo_redirects.source_hash',
            ])) {
                throw StaleSeoRedirectException::forRedirect(
                    $redirectId ?? SeoRedirect::sourceHash($scope, $locale, $source),
                    $exception,
                );
            }

            throw $exception;
        }
    }

    /**
     * Serialize redirect graph writes through the outermost database commit.
     */
    private function lockGraph(): void
    {
        $container = Container::getInstance();
        if ($container->make('config')->get('tenancy.enabled') !== true) {
            DB::table(SeoTables::RedirectLocks)->insertOrIgnore(['name' => 'graph']);
            DB::table(SeoTables::RedirectLocks)->where('name', 'graph')->lockForUpdate()->first();

            return;
        }

        $tenantId = $container->make(TenantContext::class)->requireTenant()->value;
        $name = substr(hash('sha256', $container->make(TenantBoundary::class)->key('seo.redirects', 'graph')), 0, 32);
        DB::table(SeoTables::RedirectLocks)->insertOrIgnore(['tenant_id' => $tenantId, 'name' => $name]);
        DB::table(SeoTables::RedirectLocks)
            ->where('tenant_id', $tenantId)
            ->where('name', $name)
            ->lockForUpdate()
            ->first();
    }
}
