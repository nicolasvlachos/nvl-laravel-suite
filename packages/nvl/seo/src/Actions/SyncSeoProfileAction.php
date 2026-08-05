<?php

declare(strict_types=1);

namespace Nvl\Seo\Actions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Nvl\Seo\Data\Mutations\SeoProfilePayload;
use Nvl\Seo\Events\SeoProfileChanged;
use Nvl\Seo\Exceptions\InvalidSeoMutationException;
use Nvl\Seo\Exceptions\SeoPathConflictException;
use Nvl\Seo\Exceptions\StaleSeoProfileException;
use Nvl\Seo\Models\SeoProfile;
use Nvl\Seo\Services\SeoMutationValidator;
use Nvl\Seo\Services\SeoPathConflictResolver;
use Nvl\Seo\Services\SeoTranslationNormalizer;
use Nvl\Seo\Services\SitemapCache;
use Nvl\Seo\Support\DatabaseConstraintViolation;
use Nvl\Seo\Support\SeoModelIdentifier;
use Nvl\Seo\Support\SeoScope;
use Nvl\Translatable\Enums\TranslationSyncMode;
use Nvl\Translatable\Services\TranslationWriter;
use Spatie\LaravelData\Optional;

/**
 * Creates or updates one owner's profile and locale rows atomically.
 *
 * The owner is intentionally model-only because its concrete type is the
 * polymorphic identity and cannot be safely inferred from an untyped string.
 */
final readonly class SyncSeoProfileAction
{
    public function __construct(
        private TranslationWriter $translations,
        private SeoTranslationNormalizer $translationNormalizer,
        private SeoPathConflictResolver $pathConflicts,
        private SeoMutationValidator $validator,
        private SitemapCache $sitemapCache,
    ) {}

    public function execute(
        Model $owner,
        SeoProfilePayload $data,
        ?string $scope = null,
        TranslationSyncMode $translationMode = TranslationSyncMode::Patch,
    ): SeoProfile {
        if (! $owner->exists || $owner->getKey() === null) {
            throw new InvalidArgumentException('SEO can only be attached to a persisted model.');
        }

        $this->validator->profile($data);
        $scope = SeoScope::normalize($scope);

        try {
            return DB::transaction(function () use ($owner, $data, $scope, $translationMode): SeoProfile {
                $profile = SeoProfile::query()
                    ->forOwner($owner, $scope)
                    ->lockForUpdate()
                    ->first();

                if (! $profile instanceof SeoProfile) {
                    if (is_int($data->expectedRevision) && $data->expectedRevision !== 0) {
                        throw StaleSeoProfileException::forProfile('new');
                    }

                    $profile = new SeoProfile([
                        'scope' => $scope,
                        'seoable_type' => $owner->getMorphClass(),
                        'seoable_id' => SeoModelIdentifier::required($owner),
                    ]);
                } elseif (is_int($data->expectedRevision)
                    && $profile->revision !== $data->expectedRevision) {
                    throw StaleSeoProfileException::forProfile($profile->id);
                }

                $profile->fill($data->except('translations', 'expectedRevision')->toModelPatch());

                if ($profile->exists) {
                    $profile->revision++;
                }

                $profile->save();

                if (! $data->translations instanceof Optional) {
                    try {
                        $translations = $this->translationNormalizer->normalize(
                            $data->translations,
                        );
                    } catch (InvalidArgumentException $exception) {
                        throw InvalidSeoMutationException::forField(
                            'translations',
                            $exception->getMessage(),
                            $exception,
                        );
                    }

                    $this->pathConflicts->assertAvailable(
                        $profile->id,
                        $profile->scope,
                        $translations,
                    );
                    $this->translations->sync(
                        $profile,
                        $translations,
                        $translationMode,
                    );
                } elseif ($translationMode === TranslationSyncMode::Replace) {
                    $this->translations->replace($profile, []);
                }

                $profile->refresh()->load('translations');
                DB::afterCommit(function () use ($profile): void {
                    $this->sitemapCache->forget($profile->scope);
                });
                SeoProfileChanged::dispatch($profile->id, $profile->scope, 'synced');

                return $profile;
            });
        } catch (QueryException $exception) {
            if (DatabaseConstraintViolation::matches($exception, [
                'seo_profiles_i18n_route_unique',
                'seo_profiles_i18n.scope, seo_profiles_i18n.locale, seo_profiles_i18n.path_hash',
            ])) {
                throw SeoPathConflictException::concurrent($scope, $exception);
            }

            if (DatabaseConstraintViolation::matches($exception, [
                'seo_profiles_scope_owner_unique',
                'seo_profiles.scope, seo_profiles.seoable_type, seo_profiles.seoable_id',
            ])) {
                throw StaleSeoProfileException::forProfile('new', $exception);
            }

            throw $exception;
        }
    }
}
