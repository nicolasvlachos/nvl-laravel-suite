<?php

declare(strict_types=1);

namespace Nvl\Templates\Services;

/**
 * Produces stable JSON and digests independent of associative key insertion order.
 */
final class CanonicalJson
{
    public function digest(mixed $value): string
    {
        return hash(
            'sha256',
            json_encode(
                $this->normalize($value),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ),
        );
    }

    private function normalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        foreach ($value as $key => $item) {
            $value[$key] = $this->normalize($item);
        }

        return $value;
    }
}
