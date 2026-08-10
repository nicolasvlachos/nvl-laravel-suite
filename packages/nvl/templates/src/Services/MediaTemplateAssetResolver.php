<?php

declare(strict_types=1);

namespace Nvl\Templates\Services;

use Nvl\Media\Enums\MediaLifecycleStatus;
use Nvl\Media\Models\Media;
use Nvl\Templates\Contracts\TemplateAssetResolver;
use Nvl\Templates\Data\MediaTemplateAssetData;
use Nvl\Templates\Exceptions\TemplateResolutionException;

/**
 * Resolves opt-in template aliases through revision-aware NVL Media delivery.
 */
final readonly class MediaTemplateAssetResolver implements TemplateAssetResolver
{
    public function __construct(
        private MediaTemplateAssetRegistry $assets,
        private TemplateAssetGuard $guard,
    ) {}

    public function resolve(string $key): ?string
    {
        $this->guard->key($key);
        $asset = $this->assets->get($key);

        if (! $asset instanceof MediaTemplateAssetData) {
            return null;
        }

        $media = Media::query()
            ->with('imageVariations')
            ->available()
            ->find($asset->mediaId);

        if (! $media instanceof Media) {
            throw new TemplateResolutionException(
                "Template Media alias [{$key}] points to missing or unavailable media [{$asset->mediaId}].",
            );
        }

        if ($asset->expectedRevision !== null && $media->revision !== $asset->expectedRevision) {
            throw new TemplateResolutionException(
                "Template Media alias [{$key}] expected revision {$asset->expectedRevision}, current revision is {$media->revision}.",
            );
        }

        if ($asset->variation !== '') {
            $variation = $media->getVariation($asset->variation);

            if ($variation === null
                || $variation->status !== MediaLifecycleStatus::Available->value
                || $variation->source_revision !== $media->revision) {
                throw new TemplateResolutionException(
                    "Template Media alias [{$key}] variation [{$asset->variation}] is not available for revision {$media->revision}.",
                );
            }
        }

        $value = $asset->delivery === 'url'
            ? $media->getUrl($asset->variation)
            : $media->getPath($asset->variation);

        if ($value === '') {
            throw new TemplateResolutionException(
                "Template Media alias [{$key}] could not produce a safe {$asset->delivery}.",
            );
        }

        $this->guard->value($value);

        return $value;
    }

    /**
     * @return array<string, string>
     */
    public function scope(string $scope, ?string $type = null): array
    {
        $resolved = [];

        foreach (array_keys($this->assets->scope($scope, $type)) as $key) {
            $value = $this->resolve($key);

            if ($value !== null) {
                $resolved[$key] = $value;
            }
        }

        return $resolved;
    }
}
