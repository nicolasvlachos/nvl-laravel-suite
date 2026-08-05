<?php

declare(strict_types=1);

namespace Nvl\Content\Services;

use InvalidArgumentException;
use Nvl\Content\Contracts\ContentFieldPreset;

/**
 * Stores the deterministic allowlist of reusable semantic content field presets.
 */
final class ContentFieldPresetRegistry
{
    /** @var array<string, ContentFieldPreset> */
    private array $presets = [];

    /**
     * Register one semantic field preset under its stable alias.
     */
    public function register(ContentFieldPreset $preset): void
    {
        $alias = $preset->alias();

        if (preg_match('/^[a-z][a-z0-9_.-]{0,99}$/', $alias) !== 1) {
            throw new InvalidArgumentException("Content field preset alias [{$alias}] is invalid.");
        }

        if (isset($this->presets[$alias])) {
            throw new InvalidArgumentException(
                "Content field preset [{$alias}] is already registered.",
            );
        }

        if (trim($preset->name()) === '' || mb_strlen($preset->name()) > 191) {
            throw new InvalidArgumentException(
                "Content field preset [{$alias}] requires a valid name.",
            );
        }

        if ($preset->description() !== null
            && mb_strlen($preset->description()) > 65_000) {
            throw new InvalidArgumentException(
                "Content field preset [{$alias}] has an oversized description.",
            );
        }

        $this->presets[$alias] = $preset;
        ksort($this->presets);
    }

    /**
     * Resolve one registered semantic field preset.
     */
    public function get(string $alias): ContentFieldPreset
    {
        return $this->presets[$alias]
            ?? throw new InvalidArgumentException(
                "Content field preset [{$alias}] is not registered.",
            );
    }

    /**
     * Return every registered semantic field preset in deterministic order.
     *
     * @return list<ContentFieldPreset>
     */
    public function all(): array
    {
        return array_values($this->presets);
    }

    /**
     * Return every registered semantic field preset alias.
     *
     * @return list<string>
     */
    public function aliases(): array
    {
        return array_keys($this->presets);
    }
}
