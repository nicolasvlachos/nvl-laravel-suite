<?php

declare(strict_types=1);

namespace Nvl\Content\FieldTypes;

use DateInterval;
use DateTimeImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Nvl\Content\Data\ContentActorData;
use Nvl\Content\Data\RenderedPrivateMediaData;
use Nvl\Content\Enums\ContentVisibility;
use Nvl\Content\Schema\ContentFieldDefinition;
use Nvl\Content\Support\ContentConfiguration;
use Nvl\Content\Validation\ContentValidationContext;
use Nvl\Media\Contracts\MediaAuthorization;
use Nvl\Media\Data\Display\PublicMedia;
use Nvl\Media\Data\MediaActorData;
use Nvl\Media\Enums\MediaAbility;
use Nvl\Media\Enums\MediaVisibility;
use Nvl\Media\Models\Media;

/**
 * Validates media IDs and projects binaries through Media's safe public contracts.
 */
final class MediaFieldTypeAdapter extends AbstractFieldTypeAdapter
{
    public function __construct(
        private readonly bool $multiple,
        private readonly MediaAuthorization $authorization,
    ) {}

    public function alias(): string
    {
        return $this->multiple ? 'media_collection' : 'media';
    }

    /**
     * @return string|list<string>|null
     */
    public function normalize(
        mixed $value,
        ContentFieldDefinition $field,
        ContentValidationContext $context,
    ): string|array|null {
        if ($value === null) {
            return null;
        }

        $ids = $this->multiple ? $value : [$value];

        if (! is_array($ids) || ! array_is_list($ids)) {
            throw new InvalidArgumentException(
                "Content field [{$context->path}] must contain media IDs.",
            );
        }

        $maximum = $field->setting(
            'max_items',
            ContentConfiguration::positiveInteger('content.media.maximum_per_field', 50),
        );

        if (! is_int($maximum) || count($ids) > $maximum) {
            throw new InvalidArgumentException(
                "Content field [{$context->path}] contains too many media items.",
            );
        }

        $normalized = [];

        foreach ($ids as $id) {
            if (! is_string($id) || ! Str::isUuid($id)) {
                throw new InvalidArgumentException(
                    "Content field [{$context->path}] contains an invalid media ID.",
                );
            }

            if (! $context->resolveExternal) {
                $normalized[] = $id;

                continue;
            }

            $media = Media::query()->find($id);

            if (! $media instanceof Media || ! $media->isAvailable()) {
                throw new InvalidArgumentException(
                    "Media [{$id}] for [{$context->path}] is unavailable.",
                );
            }

            $this->assertVisibility($media, $context);
            $this->assertMimeType($media, $field, $context);
            $this->assertAuthorized($media, $context->actor, $context);
            $normalized[] = $media->id;
        }

        $normalized = array_values(array_unique($normalized));

        return $this->multiple ? $normalized : ($normalized[0] ?? null);
    }

    public function render(
        mixed $value,
        ContentFieldDefinition $field,
        ContentValidationContext $context,
    ): mixed {
        if ($value === null) {
            return null;
        }

        $ids = $this->multiple ? $value : [$value];

        if (! is_array($ids)) {
            return null;
        }

        $identifiers = [];

        foreach ($ids as $id) {
            if (is_string($id) && Str::isUuid($id)) {
                $identifiers[] = $id;
            }
        }

        $media = $context->resources?->media($identifiers)
            ?? Media::query()
                ->with(['translations', 'imageVariations', 'associations'])
                ->whereIn('id', $identifiers)
                ->get()
                ->keyBy('id');
        $rendered = [];

        foreach ($ids as $id) {
            if (! is_string($id) || ! Str::isUuid($id)) {
                continue;
            }

            $item = $media->get($id);

            if (! $item instanceof Media || ! $item->isAvailable()) {
                continue;
            }

            if ($item->visibility === MediaVisibility::Public) {
                $public = PublicMedia::fromMedia($item, locale: $context->locale);

                if ($public !== null) {
                    $rendered[] = $public;
                }

                continue;
            }

            if (! $this->authorization->allows(
                $this->mediaActor($context->actor),
                MediaAbility::Download,
                $item,
                $context->owner,
            )) {
                continue;
            }

            $minutes = ContentConfiguration::positiveInteger(
                'content.media.private_url_ttl_minutes',
                15,
            );
            $rendered[] = new RenderedPrivateMediaData(
                id: $item->id,
                type: $item->type->value,
                mimeType: $item->mime_type,
                url: $item->buildPrivateUrl(
                    expiration: (new DateTimeImmutable)->add(
                        new DateInterval("PT{$minutes}M"),
                    ),
                ),
            );
        }

        return $this->multiple ? $rendered : ($rendered[0] ?? null);
    }

    private function assertVisibility(
        Media $media,
        ContentValidationContext $context,
    ): void {
        if ($context->visibility === ContentVisibility::Public
            && (bool) config('content.media.require_public_for_public_blocks', true)
            && $media->visibility !== MediaVisibility::Public) {
            throw new InvalidArgumentException(
                "Public content field [{$context->path}] may only reference public media.",
            );
        }

        if ($context->visibility === ContentVisibility::Private
            && $media->visibility === MediaVisibility::Private
            && ! (bool) config('content.media.allow_private_for_private_blocks', true)) {
            throw new InvalidArgumentException(
                "Private media is disabled for content field [{$context->path}].",
            );
        }
    }

    private function assertMimeType(
        Media $media,
        ContentFieldDefinition $field,
        ContentValidationContext $context,
    ): void {
        $allowed = $field->setting('mime_types', []);

        if (! is_array($allowed)) {
            throw new InvalidArgumentException(
                "Content field [{$context->path}] mime_types must be an array.",
            );
        }

        $patterns = [];

        foreach ($allowed as $mimeType) {
            if (! is_string($mimeType)) {
                throw new InvalidArgumentException(
                    "Content field [{$context->path}] mime_types must contain strings.",
                );
            }

            $patterns[] = $mimeType;
        }

        if ($patterns !== [] && ! Str::is($patterns, $media->mime_type)) {
            throw new InvalidArgumentException(
                "Media [{$media->id}] has an unsupported MIME type for [{$context->path}].",
            );
        }
    }

    private function assertAuthorized(
        Media $media,
        ContentActorData $actor,
        ContentValidationContext $context,
    ): void {
        $ability = $media->visibility === MediaVisibility::Public
            ? MediaAbility::Reuse
            : MediaAbility::Associate;

        if (! $this->authorization->allows($this->mediaActor($actor), $ability, $media)) {
            throw new InvalidArgumentException(
                "Media [{$media->id}] is not reusable by the actor for [{$context->path}].",
            );
        }
    }

    private function mediaActor(ContentActorData $actor): MediaActorData
    {
        return new MediaActorData($actor->type, $actor->id, system: $actor->system);
    }
}
