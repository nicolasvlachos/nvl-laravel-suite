<?php

declare(strict_types=1);

namespace Nvl\Pages\Services;

use Carbon\CarbonImmutable;
use Nvl\Pages\Enums\PageKind;
use Nvl\Pages\Enums\PageStatus;
use Nvl\Pages\Exceptions\InvalidPageMutationException;

/**
 * Normalizes cross-cutting lifecycle, resource, and translation mutation values.
 */
final readonly class PageMutationValues
{
    /**
     * Create the cross-field mutation normalizer.
     */
    public function __construct(private PageResourceRegistry $resources) {}

    /**
     * Assert that a page kind and resource alias form a valid pair.
     */
    public function assertKind(PageKind $kind, ?string $resource): void
    {
        if ($kind === PageKind::Static && $resource !== null) {
            throw new InvalidPageMutationException(
                'Static pages cannot define a resource handler.',
            );
        }

        if ($kind === PageKind::Resource
            && ($resource === null || ! $this->resources->has($resource))) {
            throw new InvalidPageMutationException(
                'Resource pages require a registered resource handler alias.',
            );
        }
    }

    /**
     * Normalize and validate lifecycle timestamps.
     *
     * @return array{published_at: CarbonImmutable|null, expires_at: CarbonImmutable|null}
     */
    public function dates(
        PageStatus $status,
        ?string $publishedAt,
        ?string $expiresAt,
    ): array {
        $published = $publishedAt !== null ? CarbonImmutable::parse($publishedAt) : null;
        $expires = $expiresAt !== null ? CarbonImmutable::parse($expiresAt) : null;

        if ($status === PageStatus::Scheduled && $published === null) {
            throw new InvalidPageMutationException(
                'Scheduled pages require a publication timestamp.',
            );
        }

        if ($published !== null && $expires !== null && $expires <= $published) {
            throw new InvalidPageMutationException(
                'A page expiration timestamp must follow its publication timestamp.',
            );
        }

        return ['published_at' => $published, 'expires_at' => $expires];
    }

    /**
     * Normalize locale-keyed translation field names for Translatable.
     *
     * @param  array<string, array<string, mixed>>  $translations
     * @return array<string, array<string, mixed>>
     */
    public function translations(array $translations): array
    {
        $normalized = [];

        foreach ($translations as $locale => $values) {
            if (isset($values['navigationLabel'])
                && ! isset($values['navigation_label'])) {
                $values['navigation_label'] = $values['navigationLabel'];
                unset($values['navigationLabel']);
            }

            $normalized[$locale] = $values;
        }

        return $normalized;
    }
}
