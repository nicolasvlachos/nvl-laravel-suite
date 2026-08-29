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

/**
 * Render one reflected parameter as catalog metadata.
 */
function consumerDeprecationParameterContract(ReflectionParameter $parameter): string
{
    $contract = ($parameter->hasType() ? (string) $parameter->getType().' ' : '')
        .'$'.$parameter->getName();

    if (! $parameter->isDefaultValueAvailable()) {
        return $contract;
    }

    $default = $parameter->getDefaultValue();
    $rendered = match (true) {
        $default === null => 'null',
        is_bool($default) => $default ? 'true' : 'false',
        is_string($default) => "'".$default."'",
        default => (string) $default,
    };

    return $contract.' = '.$rendered;
}

/**
 * Render the method declaration shape stored by the package public-contract baseline.
 */
function consumerDeprecationPublicMethodContract(ReflectionMethod $method): string
{
    $parameters = array_map(
        static fn (ReflectionParameter $parameter): string => str_replace(
            ' = ',
            '=',
            consumerDeprecationParameterContract($parameter),
        ),
        $method->getParameters(),
    );

    return 'public function '.$method->getName().'('.implode(', ', $parameters).'): '
        .($method->hasReturnType() ? (string) $method->getReturnType() : 'mixed');
}

/**
 * Shorten reflected class names to the readable type names used by the guide.
 */
function consumerDeprecationGuideParameterContract(string $parameter): string
{
    return preg_replace(
        '/(?:[A-Z][A-Za-z0-9_]*\\\\)+([A-Za-z_][A-Za-z0-9_]*)/',
        '$1',
        $parameter,
    ) ?? $parameter;
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

it('binds every replacement API to reflected methods and package public contracts', function (): void {
    $root = dirname(__DIR__, 2);
    $catalog = require $root.'/tools/consumer-api-deprecations.php';
    $publicContracts = json_decode(
        file_get_contents($root.'/tools/package-contracts.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    foreach ($catalog['deprecations'] ?? [] as $symbol => $entry) {
        $contract = $entry['contract'] ?? null;

        expect($contract)->toBeArray()
            ->and($contract['surface'] ?? null)->toBeIn([
                'command',
                'method',
                'facade',
            ])
            ->and($contract['class'] ?? null)->toBeString()
            ->and(class_exists($contract['class'] ?? ''))->toBeTrue()
            ->and($contract['method'] ?? null)->toBeString()->not->toBeEmpty()
            ->and($contract['parameters'] ?? null)->toBeArray()
            ->and($contract['return_type'] ?? null)->toBeString()->not->toBeEmpty();

        $targetClass = $contract['target_class'] ?? $contract['class'];
        $method = new ReflectionMethod($targetClass, $contract['method']);
        $parameters = array_map(
            consumerDeprecationParameterContract(...),
            $method->getParameters(),
        );

        expect($method->isPublic())->toBeTrue()
            ->and($parameters)->toBe($contract['parameters'])
            ->and((string) $method->getReturnType())->toBe($contract['return_type']);

        if ($contract['surface'] === 'command') {
            expect($publicContracts['suite']['commands'][$contract['class']] ?? null)
                ->toBe($contract['command_signature'] ?? null)
                ->and($entry['replacement_api'])->toContain('nvl:suite:configure');

            continue;
        }

        $guideParameters = array_map(
            consumerDeprecationGuideParameterContract(...),
            $parameters,
        );
        $guideSignature = $contract['class'].'::'.$contract['method']
            .'('.implode(', ', $guideParameters).')';

        expect($symbol)->toBe($contract['class'].'::'.$contract['method'])
            ->and($entry['replacement_api'])->toStartWith($guideSignature);

        $symbolContracts = $publicContracts['packages'][$entry['package']]['symbols'][$contract['class']] ?? null;

        expect($symbolContracts)->toBeArray();

        if ($contract['surface'] === 'facade') {
            $declaration = $contract['facade_declaration'] ?? null;
            $docComment = (new ReflectionClass($contract['class']))->getDocComment();

            expect($declaration)->toBeString()->not->toBeEmpty()
                ->and($docComment)->toBeString()->toContain($declaration)
                ->and($symbolContracts)->toContain($declaration);
        } else {
            expect($symbolContracts)->toContain(
                consumerDeprecationPublicMethodContract($method),
            );
        }

        $genericReturn = $contract['generic_return'] ?? null;

        if ($genericReturn !== null) {
            expect($method->getDocComment())->toBeString()
                ->toContain('@return '.$genericReturn);
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
