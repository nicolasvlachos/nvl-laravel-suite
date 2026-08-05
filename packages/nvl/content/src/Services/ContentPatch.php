<?php

declare(strict_types=1);

namespace Nvl\Content\Services;

use Nvl\Content\Support\ContentArrays;

/**
 * JSON Merge Patch semantics with list replacement and explicit null values.
 */
final class ContentPatch
{
    /**
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $patch
     * @return array<string, mixed>
     */
    public function merge(array $current, array $patch): array
    {
        foreach ($patch as $key => $value) {
            $existing = $current[$key] ?? null;

            if (is_array($value)
                && ! array_is_list($value)
                && is_array($existing)
                && ! array_is_list($existing)) {
                $current[$key] = $this->merge(
                    ContentArrays::stringMap($existing, "content patch current.{$key}"),
                    ContentArrays::stringMap($value, "content patch.{$key}"),
                );
            } else {
                $current[$key] = $value;
            }
        }

        return $current;
    }
}
