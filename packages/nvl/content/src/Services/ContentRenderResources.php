<?php

declare(strict_types=1);

namespace Nvl\Content\Services;

use Closure;
use Illuminate\Support\Collection;
use Nvl\Media\Models\Media;

/**
 * Per-composition model and resolver cache; never shared across requests or jobs.
 */
final class ContentRenderResources
{
    /** @var array<string, Media> */
    private array $media = [];

    /** @var array<string, true> */
    private array $loadedMedia = [];

    /** @var array<string, array<string, mixed>|null> */
    private array $references = [];

    /**
     * @param  list<string>  $identifiers
     */
    public function preloadMedia(array $identifiers): void
    {
        $missing = array_values(array_diff(
            array_values(array_unique($identifiers)),
            array_keys($this->loadedMedia),
        ));

        if ($missing === []) {
            return;
        }

        foreach ($missing as $identifier) {
            $this->loadedMedia[$identifier] = true;
        }

        Media::query()
            ->with(['translations', 'imageVariations', 'associations'])
            ->whereIn('id', $missing)
            ->get()
            ->each(function (Media $media): void {
                $this->media[$media->id] = $media;
            });
    }

    /**
     * @param  list<string>  $identifiers
     * @return Collection<string, Media>
     */
    public function media(array $identifiers): Collection
    {
        $this->preloadMedia($identifiers);

        return collect($this->media)->only($identifiers);
    }

    /**
     * @param  Closure(): (array<string, mixed>|null)  $resolver
     * @return array<string, mixed>|null
     */
    public function reference(string $key, Closure $resolver): ?array
    {
        if (! array_key_exists($key, $this->references)) {
            $this->references[$key] = $resolver();
        }

        return $this->references[$key];
    }
}
