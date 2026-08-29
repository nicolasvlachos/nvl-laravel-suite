# Upgrading NVL Laravel Suite

## From 1.x to 2.0

### Make every legacy module decision explicit

Suite 2.0 changes the fallback for a non-null legacy `modules` map. An omitted
module flag is disabled. In 1.x the same omission was compatibility-enabled.
Explicit `true` roots remain enabled, and their transitive dependencies are
still enabled in canonical order even when a dependency is omitted or set to
`false`.

Before:

```php
return [
    'modules' => [
        'auth' => true,
    ],
];
```

In 2.0 this selects only `support`, `data`, and `auth`; every other module is
disabled. To preserve the 1.x full-suite behavior, replace the partial map with
the full explicit output:

```bash
php artisan nvl:suite:upgrade:check --strict
php artisan nvl:suite:configure --profile=full-suite --full
php artisan nvl:suite:configure --profile=full-suite --full --write --force
php artisan optimize:clear
php artisan config:cache
php artisan nvl:suite:doctor --strict
```

For an intentionally smaller installation, substitute `auth-only`,
`content-platform`, or `communications`, then review every generated boolean.
The replacement file contains all twenty module keys. A forced replacement
creates an exact sibling backup named
`nvl-suite.php.backup-YYYYMMDD-HHMMSS`; no backup is created for a dry run, a
new file, or an already-matching file.

The upgrade checker exits `1` with one `upgrade.module_missing` finding per
omitted key. Each finding states that the omission is requested-disabled and
reports its actual effective 2.0 state: dependencies may be effectively enabled
through closure, while other omissions are effectively disabled. Its
remediation is: `Run nvl:suite:configure with a reviewed profile and --full,
then use --write --force to replace the partial map with explicit decisions.`
Unknown and non-boolean keys remain errors and must be removed or corrected
before generating the replacement.

Profile/include/exclude selection is unchanged. A non-null legacy map remains
runtime-authoritative, and mixing that map with profile/include/exclude remains
an upgrade diagnostic. The shipped configuration continues to select the
`full-suite` profile, so applications without a published suite configuration
retain the complete module set.

Catalog contract:

- `nvl-suite.modules.<omitted>` changed from
  `omitted => enabled by the 1.x compatibility fallback` to
  `omitted => disabled unless dependency-enabled by an explicit root`.
- Replacement API:
  `php artisan nvl:suite:configure --profile=full-suite --full --write --force`.

### Auth role and permission list results

The RBAC list Actions still paginate and keep their invocation signatures, but
their paginator items are immutable display DTOs in 2.0.

| Public symbol | 1.x return | 2.0 return | Replacement API |
| --- | --- | --- | --- |
| `Nvl\Auth\Actions\Rbac\ListRolesAction::execute` | `Illuminate\Contracts\Pagination\LengthAwarePaginator<int, Nvl\Auth\Models\Role>` | `Illuminate\Contracts\Pagination\LengthAwarePaginator<int, Nvl\Auth\Data\Display\RoleListItemData>` | `Nvl\Auth\Actions\Rbac\ListRolesAction::execute(Authenticatable $actor, ?string $search = null, int $perPage = 25)` |
| `Nvl\Auth\Actions\Rbac\ListPermissionsAction::execute` | `Illuminate\Contracts\Pagination\LengthAwarePaginator<int, Nvl\Auth\Models\Permission>` | `Illuminate\Contracts\Pagination\LengthAwarePaginator<int, Nvl\Auth\Data\Display\PermissionListItemData>` | `Nvl\Auth\Actions\Rbac\ListPermissionsAction::execute(Authenticatable $actor, ?string $search = null, ?string $group = null, int $perPage = 25)` |

Before:

~~~php
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Nvl\Auth\Models\Role;

/** @var LengthAwarePaginator<int, Role> $roles */
$roles = $listRoles->execute($actor);
~~~

After:

~~~php
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Nvl\Auth\Data\Display\RoleListItemData;

/** @var LengthAwarePaginator<int, RoleListItemData> $roles */
$roles = $listRoles->execute($actor);
~~~

Apply the equivalent change from `Permission` to `PermissionListItemData` for
the permission paginator. Mutation Actions continue to accept package model
identity where their public signatures require it.

### Pages read results

`PageData` is the established DTO for both single-page and paginated page reads.
There is no `PageListItemData` replacement.

| Public symbol | 1.x return | 2.0 return | Replacement API |
| --- | --- | --- | --- |
| `Nvl\Pages\Actions\GetPageAction::execute` | `Nvl\Pages\Models\Page` | `Nvl\Pages\Data\PageData` | `Nvl\Pages\Actions\GetPageAction::execute(Page|string $page, PageActorData $actor): PageData` |
| `Nvl\Pages\Actions\ListPagesAction::execute` | `Illuminate\Contracts\Pagination\LengthAwarePaginator<int, Nvl\Pages\Models\Page>` | `Illuminate\Contracts\Pagination\LengthAwarePaginator<int, Nvl\Pages\Data\PageData>` | `Nvl\Pages\Actions\ListPagesAction::execute(FilterSet $filters, string $site, PageActorData $actor, int $perPage = 25)` |

Before:

~~~php
use Nvl\Pages\Models\Page;

/** @var Page $page */
$page = $getPage->execute($id, $actor);
~~~

After:

~~~php
use Nvl\Pages\Data\PageData;

/** @var PageData $page */
$page = $getPage->execute($id, $actor);
~~~

### Content read results

The Content Actions, engine, and facade now expose DTO read results. Their
mutation methods retain model identity where the mutation contract requires it.

| Public symbol | 1.x return | 2.0 return | Replacement API |
| --- | --- | --- | --- |
| `Nvl\Content\Actions\GetContentBlockAction::execute` | `Nvl\Content\Models\ContentBlock` | `Nvl\Content\Data\ContentBlockData` | `Nvl\Content\Actions\GetContentBlockAction::execute(ContentBlock|string $block, ContentActorData $actor): ContentBlockData` |
| `Nvl\Content\Actions\ListContentBlocksAction::execute` | `Illuminate\Contracts\Pagination\LengthAwarePaginator<int, Nvl\Content\Models\ContentBlock>` | `Illuminate\Contracts\Pagination\LengthAwarePaginator<int, Nvl\Content\Data\ContentBlockData>` | `Nvl\Content\Actions\ListContentBlocksAction::execute(FilterSet $filterSet, ContentActorData $actor, int $perPage = 25)` |
| `Nvl\Content\Actions\ListContentPlacementsAction::execute` | `Illuminate\Support\Collection<int, Nvl\Content\Models\ContentPlacement>` | `Illuminate\Support\Collection<int, Nvl\Content\Data\ContentPlacementData>` | `Nvl\Content\Actions\ListContentPlacementsAction::execute(Model&ContentOwner $owner, string $group, ContentActorData $actor, bool $includeBlocks = false)` |
| `Nvl\Content\Content::block` | `Nvl\Content\Models\ContentBlock` | `Nvl\Content\Data\ContentBlockData` | `Nvl\Content\Content::block(ContentBlock|string $block, ContentActorData $actor): ContentBlockData` |
| `Nvl\Content\Content::blocks` | `Illuminate\Contracts\Pagination\LengthAwarePaginator<int, Nvl\Content\Models\ContentBlock>` | `Illuminate\Contracts\Pagination\LengthAwarePaginator<int, Nvl\Content\Data\ContentBlockData>` | `Nvl\Content\Content::blocks(FilterSet $filters, ContentActorData $actor, int $perPage = 25)` |
| `Nvl\Content\Content::placements` | `Illuminate\Support\Collection<int, Nvl\Content\Models\ContentPlacement>` | `Illuminate\Support\Collection<int, Nvl\Content\Data\ContentPlacementData>` | `Nvl\Content\Content::placements(Model&ContentOwner $owner, string $group, ContentActorData $actor)` |
| `Nvl\Content\Facades\Content::block` | `Nvl\Content\Models\ContentBlock` | `Nvl\Content\Data\ContentBlockData` | `Nvl\Content\Facades\Content::block(ContentBlock|string $block, ContentActorData $actor): ContentBlockData` |
| `Nvl\Content\Facades\Content::blocks` | `Illuminate\Contracts\Pagination\LengthAwarePaginator<int, Nvl\Content\Models\ContentBlock>` | `Illuminate\Contracts\Pagination\LengthAwarePaginator<int, Nvl\Content\Data\ContentBlockData>` | `Nvl\Content\Facades\Content::blocks(FilterSet $filters, ContentActorData $actor, int $perPage = 25)` |
| `Nvl\Content\Facades\Content::placements` | `Illuminate\Support\Collection<int, Nvl\Content\Models\ContentPlacement>` | `Illuminate\Support\Collection<int, Nvl\Content\Data\ContentPlacementData>` | `Nvl\Content\Facades\Content::placements(Model&ContentOwner $owner, string $group, ContentActorData $actor)` |

Before:

~~~php
use Nvl\Content\Models\ContentBlock;

/** @var ContentBlock $block */
$block = $content->block($id, $actor);
~~~

After:

~~~php
use Nvl\Content\Data\ContentBlockData;

/** @var ContentBlockData $block */
$block = $content->block($id, $actor);
~~~

### SEO read results

SEO management reads now return `SeoProfileData`, including paginator items.

| Public symbol | 1.x return | 2.0 return | Replacement API |
| --- | --- | --- | --- |
| `Nvl\Seo\Actions\GetSeoProfileAction::execute` | `Nvl\Seo\Models\SeoProfile` | `Nvl\Seo\Data\SeoProfileData` | `Nvl\Seo\Actions\GetSeoProfileAction::execute(SeoProfile|string $profile): SeoProfileData` |
| `Nvl\Seo\Actions\ListSeoProfilesAction::execute` | `Illuminate\Contracts\Pagination\LengthAwarePaginator<int, Nvl\Seo\Models\SeoProfile>` | `Illuminate\Contracts\Pagination\LengthAwarePaginator<int, Nvl\Seo\Data\SeoProfileData>` | `Nvl\Seo\Actions\ListSeoProfilesAction::execute(SeoProfileQuery $query)` |

Before:

~~~php
use Nvl\Seo\Models\SeoProfile;

/** @var SeoProfile $profile */
$profile = $getSeoProfile->execute($id);
~~~

After:

~~~php
use Nvl\Seo\Data\SeoProfileData;

/** @var SeoProfileData $profile */
$profile = $getSeoProfile->execute($id);
~~~

After migrating these reads, run the package boundary check in normal and strict
modes. In 2.0 an unallowlisted consumer-initiated package model query is an
error in either mode:

~~~bash
php artisan nvl:suite:consumer-audit --format=json
php artisan nvl:suite:consumer-audit --strict --format=json
~~~
