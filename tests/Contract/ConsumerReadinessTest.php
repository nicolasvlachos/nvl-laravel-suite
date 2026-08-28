<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Nvl\Activity\Support\ActivitySubjectReference;
use Nvl\Auth\Actions\Rbac\ShowRoleAnalyticsAction;
use Nvl\Comments\Actions\FindLatestTargetCommentAction;
use Nvl\Comments\Data\Queries\CommentSelectorData;
use Nvl\Content\Actions\FindContentBlockByKeyAction;
use Nvl\Content\Actions\FindContentPlacementAction;
use Nvl\Content\Actions\GetOwnerContentEditorAction;
use Nvl\Content\Actions\ListOwnerContentPlacementSummariesAction;
use Nvl\Content\Actions\ReorderContentPlacementsAction;
use Nvl\Content\Actions\ReplaceContentPlacementAction;
use Nvl\MailNotifications\Actions\GetMailNotificationStatisticsAction;
use Nvl\MailNotifications\ValueObjects\MailNotificationAggregate;
use Nvl\MailNotifications\ValueObjects\TrackingContext;
use Nvl\Metafields\Actions\Metafields\ListAuthorizedOwnerMetafieldsAction;
use Nvl\Pages\Actions\CheckPageKeyAvailabilityAction;
use Nvl\Pages\Actions\FindPageByKeyAction;
use Nvl\Pages\Actions\GetPageEditorBootstrapAction;
use Nvl\Pages\Actions\GetPagePublicationProjectionAction;
use Nvl\Pages\Actions\ListPageEditorSummariesAction;
use Nvl\Pages\Actions\ListPageOptionsAction;
use Nvl\Pages\Actions\ListPublicChildPagesAction;
use Nvl\Pages\Data\PageEditorBootstrapData;
use Nvl\Pages\Data\PageEditorSummaryData;
use Nvl\Pages\Data\PageKeyAvailabilityData;
use Nvl\Pages\Data\PageOptionData;
use Nvl\Pages\Enums\PublicChildPageOrder;
use Nvl\Seo\Actions\GetOwnerSeoProfileAction;
use Nvl\Seo\Actions\GetOwnerSeoRevisionAction;
use Nvl\Seo\Actions\ListOwnerSeoProfilesAction;
use Nvl\Seo\Data\SeoOwnerRevisionData;
use Nvl\Settings\Data\SettingSubjectReferenceData;
use Nvl\Settings\Events\SettingChanged;
use Nvl\Suite\Support\SuiteModuleCatalog;
use Nvl\Translations\Actions\Entries\GetTranslationCatalogStatisticsAction;
use Nvl\Translations\Data\TranslationCatalogStatisticsData;
use Nvl\Translations\Services\TranslationEntryFilterSchema;

/**
 * Determine whether an autoloaded symbol exists regardless of symbol kind.
 */
function consumerReadinessSymbolExists(string $symbol): bool
{
    return class_exists($symbol)
        || interface_exists($symbol)
        || trait_exists($symbol)
        || enum_exists($symbol);
}

/**
 * Resolve a GitHub-compatible Markdown heading anchor.
 */
function consumerReadinessAnchor(string $heading): string
{
    $heading = mb_strtolower(trim($heading));
    $heading = str_replace('`', '', $heading);
    $heading = preg_replace('/[^\pL\pN _-]/u', '', $heading) ?? '';

    return preg_replace('/[ _]+/u', '-', $heading) ?? '';
}

/**
 * Assert that one repository-relative evidence reference exists.
 */
function expectConsumerReadinessEvidence(string $root, string $reference): void
{
    [$relativePath, $anchor] = array_pad(explode('#', $reference, 2), 2, null);
    $path = $root.'/'.$relativePath;

    expect($relativePath)->not->toBeEmpty()
        ->and($path)->toBeFile();

    if (! is_string($anchor) || $anchor === '') {
        return;
    }

    $source = (string) file_get_contents($path);
    preg_match_all('/^#{1,6}\s+(.+)$/m', $source, $matches);
    $anchors = array_map(
        static fn (string $heading): string => consumerReadinessAnchor($heading),
        $matches[1] ?? [],
    );

    expect($anchors)->toContain($anchor);
}

it('keeps one canonical four-class consumer boundary across machine and rendered guidance', function (): void {
    $root = dirname(__DIR__, 2);
    $catalog = require $root.'/tools/consumer-readiness.php';
    $readiness = (string) file_get_contents($root.'/docs/consumer-readiness.md');
    $adoption = (string) file_get_contents($root.'/docs/adoption-matrix.md');
    $boundary = [
        'allowed' => 'Actions, explicit services, contracts, DTOs, enums, owner traits, and documented identity/result models.',
        'compatibility_1x' => 'Consumer-initiated package model queries and relation aggregates remain supported only where already documented.',
        'forbidden' => 'Consumer writes through package models, builders, raw tables, pivots, or storage paths.',
        'exceptions' => 'Filterable consumer builders, Translatable opted-in scopes, adoption migrations, and documented legacy bridges.',
    ];

    expect($catalog['consumer_boundary'] ?? null)->toBe($boundary);

    foreach ([$readiness, $adoption] as $document) {
        expect($document)->toContain(
            '**Allowed:** '.$boundary['allowed'],
            '**Compatibility-only in 1.x:** '.$boundary['compatibility_1x'],
            '**Forbidden:** '.$boundary['forbidden'],
            '**Explicit exceptions:** '.$boundary['exceptions'],
        );
    }
});

it('keeps development guardrail metadata synchronized with the shipped runtime catalog', function (): void {
    $catalog = require dirname(__DIR__, 2).'/tools/consumer-readiness.php';
    $runtime = new SuiteModuleCatalog(new Repository([
        'nvl-suite' => ['modules' => []],
    ]));

    expect($catalog['runtime_guardrails']['table_definitions'] ?? null)
        ->toBe($runtime->tableDefinitions())
        ->and($catalog['runtime_guardrails']['management_actions'] ?? null)
        ->toBe($runtime->managementActions());
});

it('catalogs every package and readiness decision exactly once', function (): void {
    $root = dirname(__DIR__, 2);
    $family = require $root.'/tools/package-family.php';
    $catalog = require $root.'/tools/consumer-readiness.php';
    $packages = $catalog['packages'] ?? [];
    $expectedPackages = $family['packages'];
    $actualPackages = array_keys(is_array($packages) ? $packages : []);
    $expectedStateful = $family['stateful'];
    sort($expectedPackages);
    sort($actualPackages);
    sort($expectedStateful);

    expect($catalog['version'] ?? null)->toBe(1)
        ->and($actualPackages)->toBe($expectedPackages);

    $catalogStateful = [];

    foreach ($packages as $package => $policy) {
        expect($policy)->toBeArray()
            ->and($policy['stateful'] ?? null)->toBeBool();

        if ($policy['stateful']) {
            $catalogStateful[] = $package;
        }

        $applicationApi = $policy['application_api'] ?? [];
        $symbols = $applicationApi['symbols'] ?? [];
        $directModelAccess = $applicationApi['direct_model_access'] ?? null;

        expect($symbols)->toBeArray()->not->toBeEmpty()
            ->and($directModelAccess)->toBeIn([
                'compatibility_1x',
                'explicit_exception',
                'not_applicable',
            ]);

        foreach ($symbols as $symbol) {
            expect($symbol)->toBeString()->not->toBeEmpty();
            expect(consumerReadinessSymbolExists($symbol))->toBeTrue();
        }

        expectConsumerReadinessEvidence($root, $applicationApi['documentation']);

        if ($directModelAccess === 'explicit_exception') {
            expect($applicationApi['rationale'] ?? null)->toBeString()->not->toBeEmpty();
        } else {
            expect($applicationApi['rationale'] ?? null)->toBeNull();
        }

        foreach (['performance', 'media_lifecycle', 'locale_fallback', 'boundaries', 'presets', 'operations'] as $pillar) {
            $decision = $policy[$pillar] ?? [];
            $status = $decision['status'] ?? null;
            $evidence = $decision['evidence'] ?? [];

            expect($status)->toBeIn(['pass', 'not_applicable'])
                ->and($evidence)->toBeArray();

            if ($status === 'pass') {
                expect($evidence)->not->toBeEmpty()
                    ->and($decision['rationale'] ?? null)->toBeNull();

                foreach ($evidence as $reference) {
                    expectConsumerReadinessEvidence($root, $reference);
                }
            } else {
                expect($evidence)->toBe([])
                    ->and($decision['rationale'] ?? null)->toBeString()->not->toBeEmpty();
            }
        }

        $performance = $policy['performance'];
        $queryTests = $performance['query_tests'] ?? [];
        $cache = $performance['cache'] ?? [];

        expect($queryTests)->toBeArray()
            ->and($cache['mode'] ?? null)->toBeIn(['none', 'cached']);

        if ($performance['status'] === 'pass') {
            expect($queryTests)->not->toBeEmpty();

            foreach ($queryTests as $queryTest) {
                expectConsumerReadinessEvidence($root, $queryTest);
            }
        } else {
            expect($queryTests)->toBe([]);
        }

        if ($cache['mode'] === 'cached') {
            expect($cache)->toHaveKeys([
                'owner',
                'dimensions',
                'ttl',
                'invalidation',
                'isolation',
                'stampede',
            ]);
            expect(consumerReadinessSymbolExists($cache['owner']))->toBeTrue();

            foreach (['dimensions', 'invalidation'] as $key) {
                expect($cache[$key])->toBeArray()->not->toBeEmpty();
            }

            foreach (['ttl', 'isolation', 'stampede'] as $key) {
                expect($cache[$key])->toBeString()->not->toBeEmpty();
            }
        } else {
            expect($cache['rationale'] ?? null)->toBeString()->not->toBeEmpty();
        }

        $operations = $policy['operations'];
        expect($operations['adoption'] ?? null)->toBeIn([
            'application_api',
            'application_owned',
            'command',
            'documented_bridge',
            'not_applicable',
        ]);
        expectConsumerReadinessEvidence($root, $operations['documentation']);

        if ($policy['stateful']) {
            expect($operations['status'])->toBe('pass')
                ->and($operations['doctor'] ?? null)->toBeArray();
        }

        $doctor = $operations['doctor'] ?? null;

        if (is_array($doctor)) {
            $symbol = $doctor['symbol'] ?? null;
            $command = $doctor['command'] ?? null;
            expect($symbol)->toBeString()
                ->and($command)->toBeString();
            expect(class_exists($symbol))->toBeTrue();

            $signature = (new ReflectionClass($symbol))->getDefaultProperties()['signature'] ?? null;
            expect($signature)->toBeString();
            preg_match('/^\S+/', $signature, $signatureMatch);
            expect($signatureMatch[0] ?? null)->toBe($command);
        }
    }

    sort($catalogStateful);
    expect($catalogStateful)->toBe($expectedStateful);
});

it('limits explicit model query exceptions and built-in presets to reviewed capabilities', function (): void {
    $catalog = require dirname(__DIR__, 2).'/tools/consumer-readiness.php';
    $compatibilityPackages = [];
    $directModelPackages = [];
    $notApplicablePackages = [];
    $presetPackages = [];

    foreach ($catalog['packages'] as $package => $policy) {
        $modelPolicy = $policy['application_api']['direct_model_access'];

        if ($modelPolicy === 'compatibility_1x') {
            $compatibilityPackages[] = $package;
        }

        if ($modelPolicy === 'explicit_exception') {
            $directModelPackages[] = $package;
        }

        if ($modelPolicy === 'not_applicable') {
            $notApplicablePackages[] = $package;
        }

        if ($policy['presets']['status'] === 'pass') {
            $presetPackages[] = $package;
        }
    }

    sort($compatibilityPackages);
    sort($directModelPackages);
    sort($notApplicablePackages);
    sort($presetPackages);

    expect($compatibilityPackages)->toBe([
        'activity',
        'auth',
        'comments',
        'content',
        'forms',
        'mail-notifications',
        'media',
        'metafields',
        'pages',
        'seo',
        'settings',
        'taxonomy',
        'templates',
        'translations',
    ])->and($directModelPackages)->toBe(['filterable', 'translatable'])
        ->and($notApplicablePackages)->toBe(['csv', 'data', 'primitives', 'support'])
        ->and($presetPackages)->toBe(['content', 'media']);
});

it('publishes bounded Auth role analytics as a consumer-readiness seam', function (): void {
    $catalog = require dirname(__DIR__, 2).'/tools/consumer-readiness.php';
    $auth = $catalog['packages']['auth'];

    expect($auth['application_api']['symbols'])
        ->toContain(ShowRoleAnalyticsAction::class)
        ->and($auth['application_api']['documentation'])
        ->toBe('packages/nvl/auth/README.md#rbac-consumer-reads-and-analytics')
        ->and($auth['performance']['evidence'])
        ->toContain('packages/nvl/auth/README.md#rbac-consumer-reads-and-analytics')
        ->and($auth['performance']['query_tests'])
        ->toContain('packages/nvl/auth/tests/Feature/RbacManagementTest.php');
});

it('publishes bounded Activity event and subject-reference seams', function (): void {
    $catalog = require dirname(__DIR__, 2).'/tools/consumer-readiness.php';
    $activity = $catalog['packages']['activity'];

    expect($activity['application_api']['symbols'])
        ->toContain(ActivitySubjectReference::class)
        ->and($activity['application_api']['documentation'])
        ->toBe('packages/nvl/activity/README.md#bounded-subject-references-and-event-filters')
        ->and($activity['performance']['evidence'])
        ->toContain('packages/nvl/activity/README.md#bounded-subject-references-and-event-filters')
        ->and($activity['performance']['query_tests'])
        ->toContain('packages/nvl/activity/tests/Feature/ActivityBehaviorTest.php');
});

it('publishes bounded Mail aggregate and event-correlation seams', function (): void {
    $catalog = require dirname(__DIR__, 2).'/tools/consumer-readiness.php';
    $mail = $catalog['packages']['mail-notifications'];

    expect($mail['application_api']['symbols'])
        ->toContain(
            GetMailNotificationStatisticsAction::class,
            MailNotificationAggregate::class,
            TrackingContext::class,
        )
        ->and($mail['application_api']['documentation'])
        ->toBe('packages/nvl/mail-notifications/README.md#administrative-delivery-reads')
        ->and($mail['performance']['query_tests'])
        ->toContain('packages/nvl/mail-notifications/tests/Feature/MailNotificationAdministrationTest.php');
});

it('publishes bounded Translation catalog statistics and a model-free filter schema', function (): void {
    $catalog = require dirname(__DIR__, 2).'/tools/consumer-readiness.php';
    $translations = $catalog['packages']['translations'];

    expect($translations['application_api']['symbols'])
        ->toContain(
            GetTranslationCatalogStatisticsAction::class,
            TranslationCatalogStatisticsData::class,
            TranslationEntryFilterSchema::class,
        )
        ->and($translations['application_api']['documentation'])
        ->toBe('packages/nvl/translations/README.md#catalog-statistics-and-shared-filters')
        ->and($translations['performance']['evidence'])
        ->toContain('packages/nvl/translations/README.md#catalog-statistics-and-shared-filters')
        ->and($translations['performance']['query_tests'])
        ->toContain('packages/nvl/translations/tests/Feature/TranslationsConsumerContractsTest.php');
});

it('publishes stable Comments Settings and SEO consumer seams', function (): void {
    $catalog = require dirname(__DIR__, 2).'/tools/consumer-readiness.php';

    expect($catalog['packages']['comments']['application_api']['symbols'])
        ->toContain(FindLatestTargetCommentAction::class, CommentSelectorData::class)
        ->and($catalog['packages']['comments']['application_api']['documentation'])
        ->toBe('packages/nvl/comments/README.md#latest-target-comment-read')
        ->and($catalog['packages']['settings']['application_api']['symbols'])
        ->toContain(SettingChanged::class, SettingSubjectReferenceData::class)
        ->and($catalog['packages']['settings']['application_api']['documentation'])
        ->toBe('packages/nvl/settings/README.md#setting-change-subject-reference')
        ->and($catalog['packages']['seo']['application_api']['symbols'])
        ->toContain(
            GetOwnerSeoProfileAction::class,
            GetOwnerSeoRevisionAction::class,
            ListOwnerSeoProfilesAction::class,
            SeoOwnerRevisionData::class,
        )
        ->and($catalog['packages']['seo']['application_api']['documentation'])
        ->toBe('packages/nvl/seo/README.md#owner-centric-profile-reads')
        ->and($catalog['packages']['seo']['performance']['query_tests'])
        ->toContain('packages/nvl/seo/tests/Feature/SeoConsumerContractsTest.php');
});

it('publishes bounded Content editor projections as consumer seams', function (): void {
    $catalog = require dirname(__DIR__, 2).'/tools/consumer-readiness.php';
    $content = $catalog['packages']['content'];

    expect($content['application_api']['symbols'])
        ->toContain(
            FindContentBlockByKeyAction::class,
            FindContentPlacementAction::class,
            GetOwnerContentEditorAction::class,
            ListOwnerContentPlacementSummariesAction::class,
            ReorderContentPlacementsAction::class,
            ReplaceContentPlacementAction::class,
        )
        ->and($content['application_api']['documentation'])
        ->toBe('packages/nvl/content/README.md#editor-projections')
        ->and($content['performance']['evidence'])
        ->toContain(
            'packages/nvl/content/README.md#editor-projections',
            'packages/nvl/content/README.md#placement-editor-workflows',
        )
        ->and($content['performance']['query_tests'])
        ->toContain('packages/nvl/content/tests/Feature/ContentContractRegressionTest.php');
});

it('publishes bounded Page lookup option and public-child projections', function (): void {
    $catalog = require dirname(__DIR__, 2).'/tools/consumer-readiness.php';
    $pages = $catalog['packages']['pages'];

    expect($pages['application_api']['symbols'])
        ->toContain(
            CheckPageKeyAvailabilityAction::class,
            FindPageByKeyAction::class,
            ListPageOptionsAction::class,
            ListPublicChildPagesAction::class,
            PageKeyAvailabilityData::class,
            PageOptionData::class,
            PublicChildPageOrder::class,
        )
        ->and($pages['application_api']['documentation'])
        ->toBe('packages/nvl/pages/README.md#editor-and-publication-projections')
        ->and($pages['performance']['evidence'])
        ->toContain('packages/nvl/pages/README.md#bounded-page-reads')
        ->and($pages['performance']['query_tests'])
        ->toContain('packages/nvl/pages/tests/Feature/PagesPackageTest.php');
});

it('publishes authorized metafield and complete Page composition reads', function (): void {
    $catalog = require dirname(__DIR__, 2).'/tools/consumer-readiness.php';
    $metafields = $catalog['packages']['metafields'];
    $pages = $catalog['packages']['pages'];

    expect($metafields['application_api']['symbols'])
        ->toContain(ListAuthorizedOwnerMetafieldsAction::class)
        ->and($metafields['application_api']['documentation'])
        ->toBe('packages/nvl/metafields/README.md#querying')
        ->and($metafields['performance']['query_tests'])
        ->toContain('packages/nvl/metafields/tests/Feature/MetafieldConsumerWorkflowTest.php')
        ->and($pages['application_api']['symbols'])
        ->toContain(
            GetPageEditorBootstrapAction::class,
            GetPagePublicationProjectionAction::class,
            ListPageEditorSummariesAction::class,
            PageEditorBootstrapData::class,
            PageEditorSummaryData::class,
        )
        ->and($pages['performance']['evidence'])
        ->toContain('packages/nvl/pages/README.md#editor-and-publication-projections')
        ->and($pages['performance']['query_tests'])
        ->toContain('packages/nvl/pages/tests/Feature/PagesPackageTest.php');
});

it('keeps the rendered matrix aligned with every catalog classification', function (): void {
    $root = dirname(__DIR__, 2);
    $catalog = require $root.'/tools/consumer-readiness.php';
    $document = (string) file_get_contents($root.'/docs/consumer-readiness.md');
    preg_match_all(
        '/^\| `([^`]+)` \| (Pass|N\/A) \| (Pass|N\/A) \| (Pass|N\/A) \| (Pass|N\/A) \| (Pass|N\/A) \| (Pass|N\/A) \| (Pass|N\/A) \|$/m',
        $document,
        $rows,
        PREG_SET_ORDER,
    );

    $rendered = [];

    foreach ($rows as $row) {
        $rendered[$row[1]] = array_slice($row, 2);
    }

    expect(array_keys($rendered))->toHaveCount(count($catalog['packages']));

    foreach ($catalog['packages'] as $package => $policy) {
        $status = static fn (array $decision): string => $decision['status'] === 'pass'
            ? 'Pass'
            : 'N/A';

        expect($rendered[$package] ?? null)->toBe([
            'Pass',
            $status($policy['performance']),
            $status($policy['media_lifecycle']),
            $status($policy['locale_fallback']),
            $status($policy['boundaries']),
            $status($policy['presets']),
            $status($policy['operations']),
        ]);
    }
});

it('renders every package model policy with its canonical classification', function (): void {
    $root = dirname(__DIR__, 2);
    $catalog = require $root.'/tools/consumer-readiness.php';
    $document = (string) file_get_contents($root.'/docs/consumer-readiness.md');
    preg_match_all(
        '/^\| `([^`]+)` \| [^|]+ \| ([^|]+) \|$/m',
        $document,
        $rows,
        PREG_SET_ORDER,
    );
    $renderedPolicies = [];

    foreach ($rows as $row) {
        $renderedPolicies[$row[1]] = trim($row[2]);
    }

    expect(array_keys($renderedPolicies))->toHaveCount(count($catalog['packages']));

    foreach ($catalog['packages'] as $package => $policy) {
        $prefix = match ($policy['application_api']['direct_model_access']) {
            'compatibility_1x' => 'Compatibility-only in 1.x:',
            'explicit_exception' => 'Explicit exception:',
            'not_applicable' => 'N/A:',
        };

        expect($renderedPolicies[$package] ?? null)->toStartWith($prefix);
    }
});

it('rejects raw writes to tables owned by another package', function (): void {
    $root = dirname(__DIR__, 2);
    $family = require $root.'/tools/package-family.php';
    $tableOwners = [];
    $tableReferences = [];

    foreach ($family['packages'] as $package) {
        $namespace = str_replace(' ', '', ucwords(str_replace('-', ' ', $package)));
        $class = "Nvl\\{$namespace}\\Definitions\\Tables\\{$namespace}Tables";

        if (! class_exists($class)) {
            continue;
        }

        $reflection = new ReflectionClass($class);

        foreach ($reflection->getConstants() as $name => $table) {
            if (! is_string($table) || preg_match('/^[A-Z][A-Za-z0-9]*$/D', $name) !== 1) {
                continue;
            }

            $tableOwners[$table] = $package;
            $tableReferences[$reflection->getShortName().'::'.$name] = $table;
        }
    }

    expect($tableOwners)->not->toBeEmpty();

    foreach ($family['packages'] as $package) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
            $root.'/packages/nvl/'.$package.'/src',
            FilesystemIterator::SKIP_DOTS,
        ));

        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());
            preg_match_all(
                '/DB::table\((?:[\'\"]([^\'\"]+)[\'\"]|([A-Z][A-Za-z0-9]*Tables)::([A-Z][A-Za-z0-9]*))\)/',
                $source,
                $matches,
                PREG_SET_ORDER | PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL,
            );

            foreach ($matches as $match) {
                $reference = $match[2][0] !== null && $match[3][0] !== null
                    ? $match[2][0].'::'.$match[3][0]
                    : null;
                $table = $match[1][0] ?? ($reference !== null ? ($tableReferences[$reference] ?? null) : null);

                if (! is_string($table)) {
                    continue;
                }

                $owner = $tableOwners[$table] ?? null;

                if (! is_string($owner) || $owner === $package) {
                    continue;
                }

                $chain = substr($source, (int) $match[0][1], 800);
                $writes = preg_match('/->(?:insert|insertOrIgnore|upsert|update|delete)\s*\(/', $chain) === 1;

                expect($writes)->toBeFalse();
            }
        }
    }
});
