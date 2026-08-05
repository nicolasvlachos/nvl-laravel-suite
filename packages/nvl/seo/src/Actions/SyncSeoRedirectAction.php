<?php

declare(strict_types=1);

namespace Nvl\Seo\Actions;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Nvl\Seo\Data\Mutations\SeoRedirectPayload;
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
                $model = $redirectId === null
                    ? SeoRedirect::withTrashed()
                        ->where('source_hash', SeoRedirect::sourceHash($scope, $locale, $source))
                        ->lockForUpdate()
                        ->first()
                    : SeoRedirect::withTrashed()->lockForUpdate()->findOrFail($redirectId);
                $wasDeleted = $model?->trashed() ?? false;
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

                return $model->refresh();
            });
        } catch (QueryException $exception) {
            if (DatabaseConstraintViolation::matches($exception, [
                'seo_redirects_source_hash_unique',
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
}
