<?php

declare(strict_types=1);

namespace Nvl\Templates\Services;

use InvalidArgumentException;
use Nvl\Templates\Data\MediaTemplateAssetData;

/**
 * Stores validated Media aliases and provides a narrow legacy-alias adoption helper.
 */
final class MediaTemplateAssetRegistry
{
    /** @var array<string, MediaTemplateAssetData> */
    private array $assets = [];

    public function __construct(private readonly TemplateAssetGuard $guard) {}

    public function register(MediaTemplateAssetData $asset): void
    {
        $this->validate($asset);

        if (isset($this->assets[$asset->key])) {
            throw new InvalidArgumentException(
                "Template Media alias [{$asset->key}] is already registered.",
            );
        }

        $this->assets[$asset->key] = $asset;
        ksort($this->assets);
    }

    public function validate(MediaTemplateAssetData $asset): void
    {
        $this->guard->key($asset->key);

        if (trim($asset->mediaId) === '') {
            throw new InvalidArgumentException(
                "Template Media alias [{$asset->key}] requires a media ID.",
            );
        }

        foreach (['scope' => $asset->scope, 'type' => $asset->type] as $field => $value) {
            if (preg_match('/^[a-z0-9][a-z0-9._-]{0,100}$/D', $value) !== 1) {
                throw new InvalidArgumentException(
                    "Template Media alias [{$asset->key}] has an invalid {$field}.",
                );
            }
        }

        if ($asset->variation !== ''
            && preg_match('/^[a-z0-9][a-z0-9._-]{0,100}$/D', $asset->variation) !== 1) {
            throw new InvalidArgumentException(
                "Template Media alias [{$asset->key}] has an invalid variation.",
            );
        }

        if (! in_array($asset->delivery, ['path', 'url'], true)) {
            throw new InvalidArgumentException(
                "Template Media alias [{$asset->key}] delivery must be path or url.",
            );
        }

        if ($asset->expectedRevision !== null && $asset->expectedRevision < 1) {
            throw new InvalidArgumentException(
                "Template Media alias [{$asset->key}] expected revision must be positive.",
            );
        }

    }

    /**
     * Register an explicit source alias-to-Media map during controlled adoption.
     *
     * @param  array<array-key, mixed>  $aliases
     */
    public function registerAdoptionAliases(
        array $aliases,
        string $scope = 'adoption',
        string $type = 'image',
        string $delivery = 'path',
    ): void {
        foreach ($aliases as $key => $mediaId) {
            if (! is_string($key) || ! is_string($mediaId)) {
                throw new InvalidArgumentException(
                    'Legacy template assets must map alias strings to Media ID strings.',
                );
            }

            $this->register(new MediaTemplateAssetData(
                key: $key,
                mediaId: $mediaId,
                scope: $scope,
                type: $type,
                delivery: $delivery,
            ));
        }
    }

    public function get(string $key): ?MediaTemplateAssetData
    {
        return $this->assets[$key] ?? null;
    }

    /**
     * @return array<string, MediaTemplateAssetData>
     */
    public function scope(string $scope, ?string $type = null): array
    {
        return array_filter(
            $this->assets,
            static fn (MediaTemplateAssetData $asset): bool => $asset->scope === $scope
                && ($type === null || $asset->type === $type),
        );
    }

    /**
     * @return array<string, MediaTemplateAssetData>
     */
    public function all(): array
    {
        return $this->assets;
    }
}
