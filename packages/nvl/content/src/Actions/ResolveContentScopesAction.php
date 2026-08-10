<?php

declare(strict_types=1);

namespace Nvl\Content\Actions;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Nvl\Content\Contracts\ContentAuthorization;
use Nvl\Content\Contracts\ContentBlockQueryScope;
use Nvl\Content\Data\ContentActorData;
use Nvl\Content\Data\ContentScopeData;
use Nvl\Content\Data\ContentScopeResolutionData;
use Nvl\Content\Enums\ContentAbility;
use Nvl\Content\Exceptions\ContentScopeOverflowException;
use Nvl\Content\Models\ContentBlock;
use Nvl\Content\Services\ContentLocalePolicy;
use Nvl\Content\Services\ContentLocalizedValues;
use Nvl\Content\Support\ContentArrays;
use Nvl\Content\Support\ContentConfiguration;

/**
 * Resolves complete published Content scopes for non-request rendering consumers.
 */
final readonly class ResolveContentScopesAction
{
    public function __construct(
        private ContentAuthorization $authorization,
        private ContentLocalePolicy $locales,
        private ContentLocalizedValues $localizedValues,
    ) {}

    /**
     * Resolve unique block keys using the first matching scope as highest priority.
     *
     * @param  list<ContentScopeData>  $scopes
     */
    public function execute(
        array $scopes,
        string $locale,
        ContentActorData $actor,
        ?int $limit = null,
        bool $publicOnly = true,
    ): ContentScopeResolutionData {
        $scopes = $this->validatedScopes($scopes);
        $locale = $this->locales->assertSupported($locale);
        $limit = $this->limit($limit);
        $this->authorization->authorize(
            ContentAbility::Render,
            $actor,
            context: [
                'scopes' => array_map(
                    static fn (ContentScopeData $scope): array => [
                        'scope' => $scope->scope,
                        'scope_key' => $scope->scopeKey,
                    ],
                    $scopes,
                ),
                'locale' => $locale,
                'public_only' => $publicOnly,
                'limit' => $limit,
            ],
        );

        $query = ContentBlock::query()
            ->with(['definition', 'translations'])
            ->published()
            ->where(function (Builder $query) use ($scopes): void {
                foreach ($scopes as $scope) {
                    $query->orWhere(function (Builder $query) use ($scope): void {
                        $query->where('scope', $scope->scope)
                            ->where('scope_key', $scope->scopeKey);
                    });
                }
            })
            ->orderBy('key')
            ->orderBy('id');

        if ($publicOnly) {
            $query->public();
        }

        if ($this->authorization instanceof ContentBlockQueryScope) {
            $this->authorization->scopeContentBlocks($query, $actor);
        }

        /** @var Collection<int, ContentBlock> $blocks */
        $blocks = $query->limit($limit + 1)->get();

        if ($blocks->count() > $limit) {
            throw new ContentScopeOverflowException(
                "Content scope resolution exceeds the configured limit of {$limit} blocks.",
            );
        }

        $byScope = $blocks->groupBy(
            static fn (ContentBlock $block): string => self::scopeIdentifier(
                $block->scope,
                $block->scope_key,
            ),
        );
        $values = [];
        $sources = [];

        foreach ($scopes as $scope) {
            $identifier = self::scopeIdentifier($scope->scope, $scope->scopeKey);

            foreach ($byScope->get($identifier, collect()) as $block) {
                if (array_key_exists($block->key, $values)) {
                    continue;
                }

                $values[$block->key] = $this->localizedBlockValues($block, $locale);
                $sources[$block->key] = $identifier;
            }
        }

        ksort($values);
        ksort($sources);

        return new ContentScopeResolutionData(
            locale: $locale,
            scopes: $scopes,
            values: $values,
            sources: $sources,
            matched: $blocks->count(),
            limit: $limit,
        );
    }

    /**
     * @param  array<array-key, mixed>  $scopes
     * @return list<ContentScopeData>
     */
    private function validatedScopes(array $scopes): array
    {
        if ($scopes === []) {
            throw new InvalidArgumentException('Content scope resolution requires at least one scope.');
        }

        $maximumScopes = ContentConfiguration::positiveInteger(
            'content.rendering.scope_resolution.maximum_scopes',
            25,
        );

        if (count($scopes) > $maximumScopes) {
            throw new InvalidArgumentException(
                "Content scope resolution accepts at most {$maximumScopes} scopes.",
            );
        }

        $validated = [];
        $seen = [];

        foreach ($scopes as $scope) {
            if (! $scope instanceof ContentScopeData) {
                throw new InvalidArgumentException(
                    'Content scopes must contain only ContentScopeData instances.',
                );
            }

            $scopeName = trim($scope->scope);
            $scopeKey = trim($scope->scopeKey);

            if (preg_match('/^[a-z][a-z0-9_.-]{0,99}$/D', $scopeName) !== 1
                || $scopeKey === ''
                || mb_strlen($scopeKey) > 191) {
                throw new InvalidArgumentException(
                    'Content scope names and keys must satisfy the persisted identity bounds.',
                );
            }

            $identifier = self::scopeIdentifier($scopeName, $scopeKey);

            if (isset($seen[$identifier])) {
                throw new InvalidArgumentException("Content scope [{$identifier}] is duplicated.");
            }

            $seen[$identifier] = true;
            $validated[] = new ContentScopeData($scopeName, $scopeKey);
        }

        return $validated;
    }

    private function limit(?int $requested): int
    {
        $default = ContentConfiguration::positiveInteger(
            'content.rendering.scope_resolution.limit',
            250,
        );
        $maximum = ContentConfiguration::positiveInteger(
            'content.rendering.scope_resolution.maximum_limit',
            1_000,
        );
        $limit = $requested ?? $default;

        if ($limit < 1 || $limit > $maximum) {
            throw new InvalidArgumentException(
                "Content scope resolution limit must be between 1 and {$maximum}.",
            );
        }

        return $limit;
    }

    /**
     * @return array<string, mixed>
     */
    private function localizedBlockValues(ContentBlock $block, string $locale): array
    {
        $base = is_array($block->values)
            ? ContentArrays::stringMap($block->values, "content block {$block->id} values")
            : [];
        $translations = [];

        foreach ($block->translations as $translation) {
            $translationLocale = $translation->getAttribute('locale');
            $translationValues = $translation->getAttribute('values');

            if (is_string($translationLocale) && is_array($translationValues)) {
                $translations[$translationLocale] = ContentArrays::stringMap(
                    $translationValues,
                    "content block {$block->id} translation {$translationLocale}",
                );
            }
        }

        return $this->localizedValues->overlay(
            $block->definition_schema,
            $base,
            $this->localizedValues->resolve(
                $block->definition_schema,
                $translations,
                $locale,
            ),
        );
    }

    private static function scopeIdentifier(string $scope, string $scopeKey): string
    {
        return "{$scope}:{$scopeKey}";
    }
}
