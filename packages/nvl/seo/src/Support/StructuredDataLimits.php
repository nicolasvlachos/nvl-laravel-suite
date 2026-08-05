<?php

declare(strict_types=1);

namespace Nvl\Seo\Support;

use InvalidArgumentException;
use JsonException;

/**
 * Bounds JSON-LD payload shape, size, depth, and total item count.
 */
final class StructuredDataLimits
{
    public static function accepts(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }

        if (! is_array($value)
            || (array_is_list($value)
                && collect($value)->contains(
                    static fn (mixed $item): bool => ! is_array($item) || array_is_list($item),
                ))) {
            return false;
        }

        if (! self::validDocuments($value)) {
            return false;
        }

        try {
            $encoded = json_encode($value, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return false;
        }

        if (strlen($encoded) > SeoConfiguration::positiveInteger(
            'seo.structured_data.maximum_bytes',
            262_144,
        )) {
            return false;
        }

        $items = 0;

        return self::inspect(
            $value,
            0,
            $items,
            SeoConfiguration::positiveInteger('seo.structured_data.maximum_depth', 16),
            SeoConfiguration::positiveInteger('seo.structured_data.maximum_items', 1_000),
        );
    }

    /**
     * Validate the minimal JSON-LD document grammar used by this package.
     *
     * @param  array<array-key, mixed>  $value
     */
    private static function validDocuments(array $value): bool
    {
        $documents = array_is_list($value) ? $value : [$value];

        foreach ($documents as $document) {
            if (! is_array($document) || array_is_list($document)) {
                return false;
            }

            $context = $document['@context'] ?? null;
            if ($context !== null
                && ! in_array($context, ['https://schema.org', 'https://schema.org/'], true)) {
                return false;
            }

            if (isset($document['@graph'])) {
                if (! is_array($document['@graph'])
                    || ! array_is_list($document['@graph'])
                    || $document['@graph'] === []) {
                    return false;
                }

                foreach ($document['@graph'] as $node) {
                    if (! is_array($node) || array_is_list($node) || ! self::validNode($node)) {
                        return false;
                    }
                }

                continue;
            }

            if (! self::validNode($document)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Validate the identity and type grammar of one root JSON-LD node.
     *
     * @param  array<array-key, mixed>  $node
     */
    private static function validNode(array $node): bool
    {
        $type = $node['@type'] ?? null;

        if (is_string($type)) {
            if (preg_match('/^[A-Za-z0-9][A-Za-z0-9]*$/', $type) !== 1) {
                return false;
            }
        } elseif (is_array($type) && array_is_list($type) && $type !== []) {
            foreach ($type as $candidate) {
                if (! is_string($candidate)
                    || preg_match('/^[A-Za-z0-9][A-Za-z0-9]*$/', $candidate) !== 1) {
                    return false;
                }
            }
        } else {
            return false;
        }

        $id = $node['@id'] ?? null;

        return $id === null
            || (is_string($id)
                && (HttpUrl::isAbsolute($id)
                    || preg_match('/^(?:#|urn:)[^\s]+$/', $id) === 1));
    }

    public static function assert(mixed $value): void
    {
        if (! self::accepts($value)) {
            throw new InvalidArgumentException(
                'Structured SEO data exceeds the configured shape, size, depth, or item limits.',
            );
        }
    }

    private static function inspect(
        mixed $value,
        int $depth,
        int &$items,
        int $maximumDepth,
        int $maximumItems,
    ): bool {
        if ($depth > $maximumDepth) {
            return false;
        }

        if (! is_array($value)) {
            return true;
        }

        $items += count($value);

        if ($items > $maximumItems) {
            return false;
        }

        foreach ($value as $child) {
            if (! self::inspect(
                $child,
                $depth + 1,
                $items,
                $maximumDepth,
                $maximumItems,
            )) {
                return false;
            }
        }

        return true;
    }
}
