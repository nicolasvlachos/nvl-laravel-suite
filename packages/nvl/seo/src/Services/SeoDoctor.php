<?php

declare(strict_types=1);

namespace Nvl\Seo\Services;

use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Nvl\Seo\Contracts\SeoAuthorization;
use Nvl\Seo\Contracts\SeoImageResolver;
use Nvl\Seo\Contracts\SitemapArtifactStore;
use Nvl\Seo\Data\SeoDoctorCheckData;
use Nvl\Seo\Definitions\Tables\SeoTables;
use Nvl\Seo\Support\HttpUrl;
use Nvl\Seo\Support\SeoRouteConfiguration;
use Nvl\Seo\Support\SeoScope;
use Throwable;

/**
 * Inspects SEO schema, configuration, routes, and extension bindings without mutation.
 */
final readonly class SeoDoctor
{
    /**
     * Create the SEO readiness inspector.
     */
    public function __construct(
        private Container $container,
        private StructuredDataRegistry $structuredData,
        private SeoOwnerRegistry $owners,
        private Repository $cache,
        private SeoAuthorization $authorization,
        private RobotsGenerator $robots,
    ) {}

    /**
     * Return every SEO readiness check.
     *
     * @return list<SeoDoctorCheckData>
     */
    public function inspect(): array
    {
        return [
            ...$this->schemaChecks(),
            $this->bindingCheck(SeoImageResolver::class, 'binding.image-resolver'),
            $this->bindingCheck(SeoAuthorization::class, 'binding.authorization'),
            $this->bindingCheck(SitemapArtifactStore::class, 'binding.sitemap-artifact-store'),
            $this->siteConfigurationCheck(),
            $this->ownerRegistryCheck(),
            $this->structuredDataCheck(),
            $this->sitemapConfigurationCheck(),
            $this->robotsConfigurationCheck(),
            $this->publicRouteCheck(),
            $this->managementRouteCheck(),
        ];
    }

    /**
     * Inspect required tables, columns, identifier types, and indexes.
     *
     * @return list<SeoDoctorCheckData>
     */
    private function schemaChecks(): array
    {
        $tables = [
            SeoTables::Profiles => [
                'columns' => [
                    'id', 'scope', 'seoable_type', 'seoable_id', 'revision',
                    'status', 'archived_at', 'is_indexable', 'is_followable',
                    'max_snippet', 'max_image_preview', 'max_video_preview',
                    'sitemap_included', 'sitemap_priority',
                    'sitemap_change_frequency', 'metadata', 'created_at',
                    'updated_at',
                ],
                'indexes' => [
                    'seo_profiles_scope_owner_unique' => true,
                    'seo_profiles_owner_index' => false,
                    'seo_profiles_sitemap_scan_index' => false,
                ],
            ],
            SeoTables::I18n => [
                'columns' => [
                    'id', 'seo_profile_id', 'scope', 'locale', 'path',
                    'path_hash', 'title', 'description', 'canonical_url',
                    'image_url', 'image_reference', 'image_alt',
                    'open_graph_title', 'open_graph_description',
                    'twitter_title', 'twitter_description', 'twitter_card',
                    'structured_data', 'metadata', 'created_at', 'updated_at',
                ],
                'indexes' => [
                    'seo_profiles_i18n_owner_locale_unique' => true,
                    'seo_profiles_i18n_route_unique' => true,
                ],
            ],
            SeoTables::Redirects => [
                'columns' => [
                    'id', 'scope', 'locale', 'source_path', 'source_hash',
                    'target', 'status_code', 'revision', 'is_active',
                    'expires_at', 'hit_count', 'last_hit_at', 'metadata',
                    'created_at', 'updated_at', 'deleted_at',
                ],
                'indexes' => [
                    'seo_redirects_source_hash_unique' => true,
                ],
            ],
        ];
        $checks = [];

        foreach ($tables as $table => $requirements) {
            try {
                $exists = Schema::hasTable($table);
            } catch (Throwable $exception) {
                $checks[] = new SeoDoctorCheckData(
                    key: "schema.table.{$table}",
                    severity: 'error',
                    passed: false,
                    message: 'The SEO database is unavailable: '.$exception->getMessage(),
                );

                continue;
            }

            $checks[] = new SeoDoctorCheckData(
                key: "schema.table.{$table}",
                severity: 'error',
                passed: $exists,
                message: $exists ? "Table [{$table}] exists." : "Table [{$table}] is missing.",
            );

            if (! $exists) {
                continue;
            }

            $missing = array_values(array_filter(
                $requirements['columns'],
                static fn (string $column): bool => ! Schema::hasColumn($table, $column),
            ));
            $checks[] = new SeoDoctorCheckData(
                key: "schema.columns.{$table}",
                severity: 'error',
                passed: $missing === [],
                message: $missing === []
                    ? "Required columns exist on [{$table}]."
                    : 'Missing columns: '.implode(', ', $missing).'.',
            );

            if (! Schema::hasColumn($table, 'id')) {
                continue;
            }

            $idType = Schema::getColumnType($table, 'id');
            $checks[] = new SeoDoctorCheckData(
                key: "schema.id-type.{$table}",
                severity: 'error',
                passed: in_array($idType, ['uuid', 'guid', 'char', 'varchar', 'string'], true),
                message: "Identifier column on [{$table}] uses [{$idType}].",
            );
            $indexes = collect(Schema::getIndexes($table));

            foreach ($requirements['indexes'] as $name => $unique) {
                $index = null;

                foreach ($indexes as $candidate) {
                    if (is_array($candidate) && ($candidate['name'] ?? null) === $name) {
                        $index = $candidate;

                        break;
                    }
                }
                $checks[] = new SeoDoctorCheckData(
                    key: "schema.index.{$name}",
                    severity: 'error',
                    passed: is_array($index)
                        && (! $unique || ($index['unique'] ?? false) === true),
                    message: is_array($index)
                        ? "Index [{$name}] exists."
                        : "Index [{$name}] is missing.",
                );
            }

            if ($table === SeoTables::Redirects) {
                $targetIndexExists = $indexes->contains(
                    static fn (array $index): bool => ($index['name'] ?? null)
                        === 'seo_redirects_target_index',
                );
                $checks[] = new SeoDoctorCheckData(
                    key: 'schema.index.redirect-target-text',
                    severity: 'error',
                    passed: ! $targetIndexExists,
                    message: $targetIndexExists
                        ? 'The non-portable redirect target TEXT index still exists.'
                        : 'No non-portable redirect target TEXT index exists.',
                );

                if (Schema::hasColumn($table, 'source_hash')) {
                    $invalidHashesExist = DB::table($table)
                        ->whereNull('source_hash')
                        ->orWhere('source_hash', '')
                        ->orWhereRaw('LENGTH(source_hash) <> 64')
                        ->exists();
                    $checks[] = new SeoDoctorCheckData(
                        key: 'schema.redirect-source-hashes',
                        severity: 'error',
                        passed: ! $invalidHashesExist,
                        message: $invalidHashesExist
                            ? 'Redirect source hashes contain null, empty, or invalid-width values.'
                            : 'Redirect source hashes use the required fixed-width identity.',
                    );
                    $duplicatesExist = DB::table($table)
                        ->select('source_hash')
                        ->whereNotNull('source_hash')
                        ->groupBy('source_hash')
                        ->havingRaw('COUNT(*) > 1')
                        ->exists();
                    $checks[] = new SeoDoctorCheckData(
                        key: 'schema.redirect-duplicate-identities',
                        severity: 'error',
                        passed: ! $duplicatesExist,
                        message: $duplicatesExist
                            ? 'Duplicate redirect source hashes must be resolved.'
                            : 'No duplicate redirect source hashes exist.',
                    );
                }
            }

            if ($table === SeoTables::Profiles
                && Schema::hasColumn($table, 'revision')
                && Schema::hasColumn($table, 'status')) {
                $invalidProfilesExist = DB::table($table)
                    ->where('revision', '<', 1)
                    ->orWhereNotIn('status', ['active', 'archived'])
                    ->exists();
                $checks[] = new SeoDoctorCheckData(
                    key: 'schema.profile-row-integrity',
                    severity: 'error',
                    passed: ! $invalidProfilesExist,
                    message: $invalidProfilesExist
                        ? 'SEO profiles contain invalid revisions or lifecycle statuses.'
                        : 'SEO profile revisions and lifecycle statuses are valid.',
                );
            }
        }

        return $checks;
    }

    /**
     * Inspect one required container binding.
     *
     * @param  class-string  $contract
     */
    private function bindingCheck(string $contract, string $key): SeoDoctorCheckData
    {
        if (! $this->container->bound($contract)) {
            return new SeoDoctorCheckData(
                key: $key,
                severity: 'error',
                passed: false,
                message: "Contract [{$contract}] is missing.",
            );
        }

        try {
            $resolved = $this->container->make($contract);
            $healthy = $resolved instanceof $contract;

            return new SeoDoctorCheckData(
                key: $key,
                severity: 'error',
                passed: $healthy,
                message: $healthy
                    ? "Contract [{$contract}] resolves to a compatible implementation."
                    : "Contract [{$contract}] resolves to an incompatible implementation.",
            );
        } catch (Throwable $exception) {
            return new SeoDoctorCheckData(
                key: $key,
                severity: 'error',
                passed: false,
                message: "Contract [{$contract}] cannot be resolved: {$exception->getMessage()}",
            );
        }
    }

    /**
     * Validate the configured site URL.
     */
    private function siteConfigurationCheck(): SeoDoctorCheckData
    {
        $baseUrl = config('seo.site.base_url');
        $healthy = is_string($baseUrl) && HttpUrl::isBase($baseUrl);

        return new SeoDoctorCheckData(
            key: 'configuration.site',
            severity: 'error',
            passed: $healthy,
            message: $healthy
                ? 'The SEO base URL is an absolute HTTP(S) URL without query or fragment.'
                : 'seo.site.base_url must be an absolute HTTP(S) URL without query or fragment.',
        );
    }

    /**
     * Validate every owner alias registration.
     */
    private function ownerRegistryCheck(): SeoDoctorCheckData
    {
        try {
            $owners = $this->owners->configured();
            $registeredMorphTypes = [];

            foreach ($owners as $modelClass) {
                $registeredMorphTypes[] = (new $modelClass)->getMorphClass();
            }

            $storedMorphTypes = Schema::hasTable(SeoTables::Profiles)
                ? DB::table(SeoTables::Profiles)
                    ->distinct()
                    ->orderBy('seoable_type')
                    ->pluck('seoable_type')
                    ->filter(static fn (mixed $value): bool => is_string($value))
                    ->values()
                    ->all()
                : [];
            $missing = array_values(array_diff($storedMorphTypes, $registeredMorphTypes));

            return new SeoDoctorCheckData(
                key: 'configuration.owners',
                severity: $missing === []
                    || ! (bool) config('seo.management.enabled', false)
                    ? 'warning'
                    : 'error',
                passed: $missing === [],
                message: $missing === []
                    ? count($owners).' unique management owner aliases are registered.'
                    : 'Stored owner morph types have no management alias: '
                        .implode(', ', $missing).'.',
            );
        } catch (InvalidArgumentException $exception) {
            return new SeoDoctorCheckData(
                key: 'configuration.owners',
                severity: 'error',
                passed: false,
                message: $exception->getMessage(),
            );
        } catch (Throwable $exception) {
            return new SeoDoctorCheckData(
                key: 'configuration.owners',
                severity: 'error',
                passed: false,
                message: 'Owner aliases could not be inspected: '.$exception->getMessage(),
            );
        }
    }

    /**
     * Validate structured-data mode and report registered providers.
     */
    private function structuredDataCheck(): SeoDoctorCheckData
    {
        $mode = config('seo.structured_data.mode', 'merge');
        $limits = [
            config('seo.structured_data.maximum_bytes'),
            config('seo.structured_data.maximum_depth'),
            config('seo.structured_data.maximum_items'),
        ];
        $healthy = is_string($mode)
            && in_array($mode, ['persisted', 'generated', 'merge'], true)
            && collect($limits)->every(
                static fn (mixed $limit): bool => is_int($limit) && $limit > 0,
            );

        return new SeoDoctorCheckData(
            key: 'configuration.structured-data',
            severity: 'error',
            passed: $healthy,
            message: $healthy
                ? count($this->structuredData->keys())
                    ." resource-aware providers are registered in [{$mode}] mode."
                : 'Structured-data mode and limits must be valid positive values.',
        );
    }

    /**
     * Validate portable sitemap limits and atomic build locking.
     */
    private function sitemapConfigurationCheck(): SeoDoctorCheckData
    {
        $maximumUrls = config('seo.sitemap.max_urls');
        $maximumBytes = config('seo.sitemap.max_bytes');
        $cacheSeconds = config('seo.sitemap.cache_seconds');
        $artifactDirectory = config('seo.sitemap.directory');
        $artifactDisk = config('seo.sitemap.disk');
        $needsLock = is_int($cacheSeconds) && $cacheSeconds > 0;
        $publicScopesAreValid = true;

        try {
            SeoScope::publicSitemapScopes();
        } catch (Throwable) {
            $publicScopesAreValid = false;
        }

        $healthy = is_int($maximumUrls)
            && $maximumUrls > 0
            && $maximumUrls <= 50_000
            && is_int($maximumBytes)
            && $maximumBytes > 0
            && $maximumBytes <= 52_428_800
            && is_int($cacheSeconds)
            && $cacheSeconds >= 0
            && is_string($artifactDirectory)
            && preg_match('#^[A-Za-z0-9][A-Za-z0-9/_-]*$#', $artifactDirectory) === 1
            && ! str_contains($artifactDirectory, '..')
            && ($artifactDisk === null || (is_string($artifactDisk) && $artifactDisk !== ''))
            && (! $needsLock || $this->cache->getStore() instanceof LockProvider)
            && $publicScopesAreValid;

        return new SeoDoctorCheckData(
            key: 'configuration.sitemap',
            severity: 'error',
            passed: $healthy,
            message: $healthy
                ? 'Sitemap URL, byte, artifact-store, cache, and atomic-lock settings are valid.'
                : 'Sitemap limits, artifact storage, or atomic cache locking are invalid.',
        );
    }

    /**
     * Validate robots output, cache duration, and protocol byte limit.
     */
    private function robotsConfigurationCheck(): SeoDoctorCheckData
    {
        $cacheSeconds = config('seo.robots.cache_seconds');
        $maximumBytes = config('seo.robots.maximum_bytes');
        $healthy = is_int($cacheSeconds)
            && $cacheSeconds >= 0
            && is_int($maximumBytes)
            && $maximumBytes > 0
            && $maximumBytes <= 512_000;
        $message = 'Robots directives, cache duration, and byte limit are valid.';

        if ($healthy) {
            try {
                $this->robots->generate();
            } catch (Throwable $exception) {
                $healthy = false;
                $message = $exception->getMessage();
            }
        } else {
            $message = 'Robots cache duration and byte limit must use valid integer bounds.';
        }

        return new SeoDoctorCheckData(
            key: 'configuration.robots',
            severity: 'error',
            passed: $healthy,
            message: $message,
        );
    }

    /**
     * Validate opt-in public crawler routes and route names.
     */
    private function publicRouteCheck(): SeoDoctorCheckData
    {
        try {
            $name = SeoRouteConfiguration::publicName();
            SeoRouteConfiguration::sitemapPath();
            SeoRouteConfiguration::sitemapChunkPath();
            SeoRouteConfiguration::robotsPath();
        } catch (InvalidArgumentException $exception) {
            return new SeoDoctorCheckData(
                key: 'routes.public',
                severity: 'error',
                passed: false,
                message: $exception->getMessage(),
            );
        }

        if (! (bool) config('seo.routes.enabled', false)) {
            return new SeoDoctorCheckData(
                key: 'routes.public',
                severity: 'warning',
                passed: true,
                message: 'Public crawler routes are disabled.',
            );
        }

        $healthy = Route::has($name.'sitemap')
            && Route::has($name.'sitemap.chunk')
            && Route::has($name.'robots');

        return new SeoDoctorCheckData(
            key: 'routes.public',
            severity: 'error',
            passed: $healthy,
            message: $healthy
                ? 'Public crawler routes are registered with configured names.'
                : 'One or more enabled public crawler routes are missing.',
        );
    }

    /**
     * Validate opt-in management routing, middleware, and authorization.
     */
    private function managementRouteCheck(): SeoDoctorCheckData
    {
        try {
            $name = SeoRouteConfiguration::managementName();
            SeoRouteConfiguration::managementPath();
        } catch (InvalidArgumentException $exception) {
            return new SeoDoctorCheckData(
                key: 'routes.management',
                severity: 'error',
                passed: false,
                message: $exception->getMessage(),
            );
        }

        if (! (bool) config('seo.management.enabled', false)) {
            return new SeoDoctorCheckData(
                key: 'routes.management',
                severity: 'warning',
                passed: true,
                message: 'Management routes are disabled.',
            );
        }

        $middleware = array_values(array_filter(
            (array) config('seo.management.middleware', []),
            static fn (mixed $value): bool => is_string($value) && $value !== '',
        ));
        $ability = config('seo.authorization.ability');
        $authorizationConfigured = ! $this->authorization instanceof ConfiguredSeoAuthorization
            || (is_string($ability) && $ability !== '');
        $healthy = $middleware !== []
            && $authorizationConfigured
            && Route::has($name.'profiles.index');

        return new SeoDoctorCheckData(
            key: 'routes.management',
            severity: 'error',
            passed: $healthy,
            message: match (true) {
                $middleware === [] => 'Management routes require middleware.',
                ! $authorizationConfigured => 'Management routes require consumer authorization.',
                ! Route::has($name.'profiles.index') => 'Management routes are enabled but missing.',
                default => 'Management routes are enabled and secured.',
            },
        );
    }
}
