<?php

declare(strict_types=1);

use Nvl\Auth\Data\Display\PermissionListItemData;
use Nvl\Auth\Data\Display\RoleListItemData;
use Nvl\Content\Data\ContentBlockData;
use Nvl\Content\Data\ContentPlacementData;
use Nvl\Pages\Data\PageData;
use Nvl\Seo\Data\SeoProfileData;
use Nvl\Suite\Console\Commands\SuiteConfigureCommand;

/**
 * Resolve one GitHub-compatible Markdown heading anchor.
 */
function consumerDeprecationHeadingAnchor(string $heading): string
{
    $heading = mb_strtolower(trim($heading));
    $heading = str_replace('`', '', $heading);
    $heading = preg_replace('/[^\pL\pN _-]/u', '', $heading) ?? '';

    return preg_replace('/[ _]+/u', '-', $heading) ?? '';
}

/**
 * @return array<string, array{
 *     old_return: string,
 *     new_return: string,
 *     replacement: class-string
 * }>
 */
function expectedConsumerApiDeprecations(): array
{
    return [
        'nvl-suite.modules.<omitted>' => [
            'old_return' => 'omitted => enabled by the 1.x compatibility fallback',
            'new_return' => 'omitted => disabled unless dependency-enabled by an explicit root',
            'replacement' => SuiteConfigureCommand::class,
        ],
        'Nvl\\Auth\\Actions\\Rbac\\ListRolesAction::execute' => [
            'old_return' => 'Illuminate\\Contracts\\Pagination\\LengthAwarePaginator<int, Nvl\\Auth\\Models\\Role>',
            'new_return' => 'Illuminate\\Contracts\\Pagination\\LengthAwarePaginator<int, Nvl\\Auth\\Data\\Display\\RoleListItemData>',
            'replacement' => RoleListItemData::class,
        ],
        'Nvl\\Auth\\Actions\\Rbac\\ListPermissionsAction::execute' => [
            'old_return' => 'Illuminate\\Contracts\\Pagination\\LengthAwarePaginator<int, Nvl\\Auth\\Models\\Permission>',
            'new_return' => 'Illuminate\\Contracts\\Pagination\\LengthAwarePaginator<int, Nvl\\Auth\\Data\\Display\\PermissionListItemData>',
            'replacement' => PermissionListItemData::class,
        ],
        'Nvl\\Pages\\Actions\\GetPageAction::execute' => [
            'old_return' => 'Nvl\\Pages\\Models\\Page',
            'new_return' => 'Nvl\\Pages\\Data\\PageData',
            'replacement' => PageData::class,
        ],
        'Nvl\\Pages\\Actions\\ListPagesAction::execute' => [
            'old_return' => 'Illuminate\\Contracts\\Pagination\\LengthAwarePaginator<int, Nvl\\Pages\\Models\\Page>',
            'new_return' => 'Illuminate\\Contracts\\Pagination\\LengthAwarePaginator<int, Nvl\\Pages\\Data\\PageData>',
            'replacement' => PageData::class,
        ],
        'Nvl\\Content\\Actions\\GetContentBlockAction::execute' => [
            'old_return' => 'Nvl\\Content\\Models\\ContentBlock',
            'new_return' => 'Nvl\\Content\\Data\\ContentBlockData',
            'replacement' => ContentBlockData::class,
        ],
        'Nvl\\Content\\Actions\\ListContentBlocksAction::execute' => [
            'old_return' => 'Illuminate\\Contracts\\Pagination\\LengthAwarePaginator<int, Nvl\\Content\\Models\\ContentBlock>',
            'new_return' => 'Illuminate\\Contracts\\Pagination\\LengthAwarePaginator<int, Nvl\\Content\\Data\\ContentBlockData>',
            'replacement' => ContentBlockData::class,
        ],
        'Nvl\\Content\\Actions\\ListContentPlacementsAction::execute' => [
            'old_return' => 'Illuminate\\Support\\Collection<int, Nvl\\Content\\Models\\ContentPlacement>',
            'new_return' => 'Illuminate\\Support\\Collection<int, Nvl\\Content\\Data\\ContentPlacementData>',
            'replacement' => ContentPlacementData::class,
        ],
        'Nvl\\Content\\Content::block' => [
            'old_return' => 'Nvl\\Content\\Models\\ContentBlock',
            'new_return' => 'Nvl\\Content\\Data\\ContentBlockData',
            'replacement' => ContentBlockData::class,
        ],
        'Nvl\\Content\\Content::blocks' => [
            'old_return' => 'Illuminate\\Contracts\\Pagination\\LengthAwarePaginator<int, Nvl\\Content\\Models\\ContentBlock>',
            'new_return' => 'Illuminate\\Contracts\\Pagination\\LengthAwarePaginator<int, Nvl\\Content\\Data\\ContentBlockData>',
            'replacement' => ContentBlockData::class,
        ],
        'Nvl\\Content\\Content::placements' => [
            'old_return' => 'Illuminate\\Support\\Collection<int, Nvl\\Content\\Models\\ContentPlacement>',
            'new_return' => 'Illuminate\\Support\\Collection<int, Nvl\\Content\\Data\\ContentPlacementData>',
            'replacement' => ContentPlacementData::class,
        ],
        'Nvl\\Content\\Facades\\Content::block' => [
            'old_return' => 'Nvl\\Content\\Models\\ContentBlock',
            'new_return' => 'Nvl\\Content\\Data\\ContentBlockData',
            'replacement' => ContentBlockData::class,
        ],
        'Nvl\\Content\\Facades\\Content::blocks' => [
            'old_return' => 'Illuminate\\Contracts\\Pagination\\LengthAwarePaginator<int, Nvl\\Content\\Models\\ContentBlock>',
            'new_return' => 'Illuminate\\Contracts\\Pagination\\LengthAwarePaginator<int, Nvl\\Content\\Data\\ContentBlockData>',
            'replacement' => ContentBlockData::class,
        ],
        'Nvl\\Content\\Facades\\Content::placements' => [
            'old_return' => 'Illuminate\\Support\\Collection<int, Nvl\\Content\\Models\\ContentPlacement>',
            'new_return' => 'Illuminate\\Support\\Collection<int, Nvl\\Content\\Data\\ContentPlacementData>',
            'replacement' => ContentPlacementData::class,
        ],
        'Nvl\\Seo\\Actions\\GetSeoProfileAction::execute' => [
            'old_return' => 'Nvl\\Seo\\Models\\SeoProfile',
            'new_return' => 'Nvl\\Seo\\Data\\SeoProfileData',
            'replacement' => SeoProfileData::class,
        ],
        'Nvl\\Seo\\Actions\\ListSeoProfilesAction::execute' => [
            'old_return' => 'Illuminate\\Contracts\\Pagination\\LengthAwarePaginator<int, Nvl\\Seo\\Models\\SeoProfile>',
            'new_return' => 'Illuminate\\Contracts\\Pagination\\LengthAwarePaginator<int, Nvl\\Seo\\Data\\SeoProfileData>',
            'replacement' => SeoProfileData::class,
        ],
    ];
}

it('catalogs every 2.0 consumer break with exact final 1.x replacement metadata', function (): void {
    $root = dirname(__DIR__, 2);
    $catalogPath = $root.'/tools/consumer-api-deprecations.php';

    expect($catalogPath)->toBeFile();

    $catalog = require $catalogPath;
    $entries = $catalog['deprecations'] ?? null;
    $expected = expectedConsumerApiDeprecations();

    expect($catalog['schema'] ?? null)->toBe('nvl-consumer-api-deprecations-v1')
        ->and($catalog['final_1x'] ?? null)->toMatchArray([
            'version' => '1.0.7',
            'tag' => 'v1.0.7',
            'warnings' => 'not_published',
        ])
        ->and($entries)->toBeArray()
        ->and(array_keys($entries))->toBe(array_keys($expected))
        ->and(array_unique(array_keys($entries)))->toHaveCount(count($expected));

    foreach ($expected as $symbol => $contract) {
        $entry = $entries[$symbol] ?? null;

        expect($symbol)->toBeString()->not->toBeEmpty()
            ->and($entry)->toBeArray()
            ->and($entry['since'] ?? null)->toBe('1.0.7')
            ->and($entry['removed'] ?? null)->toBe('2.0')
            ->and($entry['old_return'] ?? null)->toBe($contract['old_return'])
            ->and($entry['new_return'] ?? null)->toBe($contract['new_return'])
            ->and($entry['replacement'] ?? null)->toBe($contract['replacement'])
            ->and(class_exists($entry['replacement'] ?? ''))->toBeTrue()
            ->and($entry['replacement_api'] ?? null)->toBeString()->not->toBeEmpty()
            ->and($entry['test_evidence'] ?? null)->toBeArray()->not->toBeEmpty()
            ->and($entry['guide_anchor'] ?? null)->toBeString()->not->toBeEmpty();

        foreach ($entry['test_evidence'] as $evidence) {
            expect($evidence)->toBeString()->not->toBeEmpty()
                ->and($root.'/'.$evidence)->toBeFile();
        }
    }
});

it('keeps controllers out of the public deprecation inventory', function (): void {
    $catalog = require dirname(__DIR__, 2).'/tools/consumer-api-deprecations.php';
    $symbols = array_keys($catalog['deprecations'] ?? []);

    expect($symbols)->not->toBeEmpty();

    foreach ($symbols as $symbol) {
        expect($symbol)->not->toContain('\\Http\\Controllers\\');
    }
});

it('links every catalog entry to a real upgrade-guide section with its exact shapes', function (): void {
    $root = dirname(__DIR__, 2);
    $catalog = require $root.'/tools/consumer-api-deprecations.php';
    $guide = (string) file_get_contents($root.'/UPGRADING.md');
    preg_match_all('/^#{1,6}\s+(.+)$/m', $guide, $headingMatches);
    $anchors = array_map(
        static fn (string $heading): string => consumerDeprecationHeadingAnchor($heading),
        $headingMatches[1] ?? [],
    );

    foreach ($catalog['deprecations'] ?? [] as $symbol => $entry) {
        expect($anchors)->toContain($entry['guide_anchor'])
            ->and($guide)->toContain(
                $symbol,
                $entry['old_return'],
                $entry['new_return'],
                $entry['replacement_api'],
            );
    }
});
