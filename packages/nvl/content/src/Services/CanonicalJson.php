<?php

declare(strict_types=1);

namespace Nvl\Content\Services;

/**
 * Stable JSON encoding used for definition hashes and revision snapshots.
 */
final class CanonicalJson
{
    public function encode(mixed $value): string
    {
        return json_encode(
            $this->sort($value),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }

    public function hash(mixed $value): string
    {
        return hash('sha256', $this->encode($value));
    }

    private function sort(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->sort($item), $value);
        }

        ksort($value);

        return array_map(fn (mixed $item): mixed => $this->sort($item), $value);
    }
}
