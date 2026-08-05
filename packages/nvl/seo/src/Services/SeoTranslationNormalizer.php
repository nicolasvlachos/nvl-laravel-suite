<?php

declare(strict_types=1);

namespace Nvl\Seo\Services;

use BackedEnum;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Nvl\Seo\Support\SeoPath;
use Nvl\Seo\Support\StructuredDataLimits;

/**
 * Whitelists and normalizes locale-keyed SEO mutation payloads.
 */
final class SeoTranslationNormalizer
{
    /**
     * @var list<string>
     */
    private const array Fields = [
        'path',
        'title',
        'description',
        'canonical_url',
        'image_url',
        'image_reference',
        'image_alt',
        'open_graph_title',
        'open_graph_description',
        'twitter_title',
        'twitter_description',
        'twitter_card',
        'structured_data',
        'metadata',
    ];

    /**
     * @param  array<string, array<string, mixed>>  $translations
     * @return array<string, array<string, mixed>>
     */
    public function normalize(array $translations): array
    {
        $normalized = [];

        foreach ($translations as $locale => $attributes) {
            $translation = [];

            foreach ($attributes as $key => $value) {
                $field = Str::snake($key);

                if (! in_array($field, self::Fields, true)) {
                    throw new InvalidArgumentException(
                        "SEO translation [{$locale}] contains unknown field [{$key}].",
                    );
                }

                $translation[$field] = $value instanceof BackedEnum ? $value->value : $value;

                if ($field === 'structured_data') {
                    StructuredDataLimits::assert($translation[$field]);
                }
            }

            if (array_key_exists('path', $translation)) {
                $translation['path'] = is_string($translation['path'])
                    ? SeoPath::normalize($translation['path'])
                    : null;
            }

            $normalized[$locale] = $translation;
        }

        return $normalized;
    }
}
