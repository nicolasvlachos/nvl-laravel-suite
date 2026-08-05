<?php

declare(strict_types=1);

namespace Nvl\Pages\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Nvl\Pages\Contracts\PageAuthorization;
use Nvl\Pages\Enums\PageKind;
use Nvl\Pages\Enums\PageStatus;
use Nvl\Pages\Models\Page;
use Nvl\Pages\Services\ConfiguredPageAuthorization;
use Nvl\Pages\Services\PageResourceRegistry;
use Nvl\Pages\Support\PagePath;
use Nvl\Pages\Support\PagesConfiguration;
use Nvl\Pages\Support\PagesRouteConfiguration;

/**
 * Performs non-mutating installation, registry, route, and hierarchy diagnostics.
 */
final class PagesDoctorCommand extends Command
{
    /** @var string */
    protected $signature = 'nvl:pages:doctor {--strict} {--format=text}';

    /** @var string */
    protected $description = 'Inspect the NVL Pages installation without changing state';

    /**
     * Run all non-mutating Pages installation and data-integrity diagnostics.
     */
    public function handle(
        PageResourceRegistry $resources,
        PageAuthorization $authorization,
    ): int {
        $schema = Schema::connection(PagesConfiguration::connection());
        $pages = PagesConfiguration::table('pages', 'pages');
        $i18n = PagesConfiguration::table('pages_i18n', 'pages_i18n');
        $treeLocks = PagesConfiguration::table('page_tree_locks', 'page_tree_locks');
        $publicRoutesEnabled = (bool) config('pages.routes.public.enabled', false);
        $managementRoutesEnabled = (bool) config('pages.routes.management.enabled', false);
        $checks = [
            'table.pages' => $schema->hasTable($pages),
            'table.pages_i18n' => $schema->hasTable($i18n),
            'table.page_tree_locks' => $schema->hasTable($treeLocks),
            'hierarchy.maximum_depth' => PagesConfiguration::maximumDepth(),
            'resources' => $resources->aliases(),
            'routes.public.enabled' => $publicRoutesEnabled,
            'routes.management.enabled' => $managementRoutesEnabled,
            'routes.public.middleware' => $this->hasValidMiddleware('public'),
            'routes.management.middleware' => $this->hasValidMiddleware('management'),
            'routes.management.authorization' => ! $managementRoutesEnabled
                || ! $authorization instanceof ConfiguredPageAuthorization,
            'routes.public.registered' => Route::has(
                PagesRouteConfiguration::name('public').'resolve',
            ),
            'routes.management.registered' => Route::has(
                PagesRouteConfiguration::name('management').'index',
            ),
        ];

        if ($schema->hasTable($pages)) {
            $required = [
                'id',
                'parent_id',
                'key',
                'site',
                'slug',
                'path',
                'path_hash',
                'kind',
                'resource',
                'status',
                'revision',
            ];

            foreach ($required as $column) {
                $checks["column.pages.{$column}"] = $schema->hasColumn($pages, $column);
            }

            $pageRows = Page::query()
                ->withTrashed()
                ->get([
                    'id',
                    'parent_id',
                    'parent_key',
                    'site',
                    'slug',
                    'path',
                    'path_hash',
                ]);
            $checks['data.path_hash_drift'] = $pageRows->contains(
                static fn (Page $page): bool => ! hash_equals(
                    $page->path_hash,
                    PagePath::hash($page->site, $page->path),
                ),
            );
            $hierarchy = $this->hierarchyIssues($pageRows);
            $checks['data.excessive_depth'] = $hierarchy['excessive_depth'];
            $checks['data.cycle'] = $hierarchy['cycle'];
            $checks['data.orphaned_parent'] = $hierarchy['orphaned_parent'];
            $checks['data.parent_site_drift'] = $hierarchy['parent_site_drift'];
            $checks['data.parent_key_drift'] = $hierarchy['parent_key_drift'];
            $checks['data.parent_path_drift'] = $hierarchy['parent_path_drift'];
            $checks['data.unknown_resource'] = Page::query()
                ->withTrashed()
                ->where('kind', PageKind::Resource)
                ->whereNotNull('resource')
                ->pluck('resource')
                ->filter(
                    static fn (mixed $alias): bool => is_string($alias)
                        && ! $resources->has($alias),
                )
                ->isNotEmpty();
            $checks['data.invalid_kind_resource'] = Page::query()
                ->withTrashed()
                ->where(static function ($query): void {
                    $query
                        ->where(static function ($query): void {
                            $query
                                ->where('kind', PageKind::Static)
                                ->whereNotNull('resource');
                        })
                        ->orWhere(static function ($query): void {
                            $query
                                ->where('kind', PageKind::Resource)
                                ->whereNull('resource');
                        });
                })
                ->exists();
            $checks['data.invalid_status'] = Page::query()
                ->withTrashed()
                ->whereNotIn('status', array_column(PageStatus::cases(), 'value'))
                ->exists();
            $checks['data.invalid_lifecycle_dates'] = Page::query()
                ->withTrashed()
                ->where(static function ($query): void {
                    $query
                        ->where(static function ($query): void {
                            $query
                                ->where('status', PageStatus::Scheduled)
                                ->whereNull('published_at');
                        })
                        ->orWhere(static function ($query): void {
                            $query
                                ->whereNotNull('published_at')
                                ->whereNotNull('expires_at')
                                ->whereColumn('expires_at', '<=', 'published_at');
                        });
                })
                ->exists();
        }

        if ($schema->hasTable($i18n)) {
            foreach (['id', 'page_id', 'locale', 'title'] as $column) {
                $checks["column.pages_i18n.{$column}"] = $schema->hasColumn($i18n, $column);
            }
        }

        if ($schema->hasTable($treeLocks)) {
            $checks['column.page_tree_locks.site'] = $schema->hasColumn(
                $treeLocks,
                'site',
            );
        }

        $healthy = collect($checks)->every(
            static fn (mixed $value, string $key): bool => match (true) {
                str_starts_with($key, 'table.') => $value === true,
                str_starts_with($key, 'column.') => $value === true,
                str_starts_with($key, 'data.') => $value === false,
                str_ends_with($key, '.middleware') => $value === true,
                $key === 'routes.management.authorization' => $value === true,
                $key === 'routes.public.registered' => $value
                    === $publicRoutesEnabled,
                $key === 'routes.management.registered' => $value
                    === $managementRoutesEnabled,
                default => true,
            },
        );
        $checks['healthy'] = $healthy;

        if ($this->option('format') === 'json') {
            $this->line((string) json_encode($checks, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        } else {
            foreach ($checks as $check => $value) {
                $this->line(sprintf(
                    '%-40s %s',
                    $check,
                    json_encode($value, JSON_THROW_ON_ERROR),
                ));
            }
        }

        return $healthy || ! $this->option('strict') ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Return all hierarchy integrity flags in one bounded in-memory tree pass.
     *
     * @param  Collection<int, Page>  $pages
     * @return array{
     *     excessive_depth: bool,
     *     cycle: bool,
     *     orphaned_parent: bool,
     *     parent_site_drift: bool,
     *     parent_key_drift: bool,
     *     parent_path_drift: bool
     * }
     */
    private function hierarchyIssues(Collection $pages): array
    {
        $byId = $pages->keyBy('id');
        $issues = [
            'excessive_depth' => false,
            'cycle' => false,
            'orphaned_parent' => false,
            'parent_site_drift' => false,
            'parent_key_drift' => false,
            'parent_path_drift' => false,
        ];

        foreach ($pages as $page) {
            $expectedParentKey = $page->parent_id ?? '__root__';

            if ($page->parent_key !== $expectedParentKey) {
                $issues['parent_key_drift'] = true;
            }

            if ($page->parent_id === null && $page->path !== $page->slug) {
                $issues['parent_path_drift'] = true;
            }

            $depth = 1;
            $visited = [];
            $cursor = $page;

            while ($cursor->parent_id !== null) {
                if (isset($visited[$cursor->id])) {
                    $issues['cycle'] = true;

                    break;
                }

                $visited[$cursor->id] = true;
                $parent = $byId->get($cursor->parent_id);

                if (! $parent instanceof Page) {
                    $issues['orphaned_parent'] = true;

                    break;
                }

                if ($cursor->site !== $parent->site) {
                    $issues['parent_site_drift'] = true;
                }

                if ($cursor->path !== $parent->path.'/'.$cursor->slug) {
                    $issues['parent_path_drift'] = true;
                }

                $depth++;
                $cursor = $parent;
            }

            if ($depth > PagesConfiguration::maximumDepth()) {
                $issues['excessive_depth'] = true;
            }
        }

        return $issues;
    }

    /**
     * Determine whether one route group has a valid non-empty middleware list.
     */
    private function hasValidMiddleware(string $group): bool
    {
        try {
            PagesRouteConfiguration::middleware($group);
        } catch (InvalidArgumentException) {
            return false;
        }

        return true;
    }
}
