<?php

declare(strict_types=1);

namespace Nvl\Content\Services;

use InvalidArgumentException;
use Nvl\Content\Support\ContentConfiguration;

/**
 * Enforces limits for metadata and placement overrides outside field schemas.
 */
final class ContentPayloadGuard
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function metadata(array $metadata): void
    {
        $maximum = ContentConfiguration::positiveInteger(
            'content.validation.maximum_metadata_bytes',
            65_536,
        );

        $this->json($metadata, 'Content metadata', $maximum);
    }

    /**
     * @param  array<string, mixed>  $display
     */
    public function referenceDisplay(array $display): void
    {
        if (array_key_exists('id', $display)) {
            throw new InvalidArgumentException(
                'Content reference display data cannot replace the reserved id field.',
            );
        }

        $maximum = ContentConfiguration::positiveInteger(
            'content.validation.maximum_reference_display_bytes',
            65_536,
        );

        $this->json($display, 'Content reference display data', $maximum);
    }

    public function json(
        mixed $value,
        string $label,
        ?int $maximumBytes = null,
        ?int $maximumDepth = null,
    ): void {
        $maximum = $maximumBytes ?? ContentConfiguration::positiveInteger(
            'content.validation.maximum_payload_bytes',
            524_288,
        );
        $depth = $maximumDepth ?? ContentConfiguration::positiveInteger(
            'content.validation.maximum_depth',
            12,
        );
        $this->assertJsonShape($value, $label, 1, $depth);
        $encoded = json_encode($value, JSON_THROW_ON_ERROR);

        if (strlen($encoded) > $maximum) {
            throw new InvalidArgumentException(
                "{$label} exceeds the configured {$maximum} byte limit.",
            );
        }
    }

    private function assertJsonShape(
        mixed $value,
        string $label,
        int $depth,
        int $maximumDepth,
    ): void {
        if ($depth > $maximumDepth) {
            throw new InvalidArgumentException(
                "{$label} exceeds the configured depth limit.",
            );
        }

        if (is_string($value)) {
            $maximumLength = ContentConfiguration::positiveInteger(
                'content.validation.maximum_string_length',
                100_000,
            );

            if (mb_strlen($value) > $maximumLength) {
                throw new InvalidArgumentException(
                    "{$label} contains an oversized string.",
                );
            }

            return;
        }

        if ($value === null || is_bool($value) || is_int($value)) {
            return;
        }

        if (is_float($value)) {
            if (! is_finite($value)) {
                throw new InvalidArgumentException("{$label} contains a non-finite number.");
            }

            return;
        }

        if (! is_array($value)) {
            throw new InvalidArgumentException(
                "{$label} must contain only JSON scalar and array values.",
            );
        }

        $maximumItems = ContentConfiguration::positiveInteger(
            'content.validation.maximum_items',
            500,
        );

        if (count($value) > $maximumItems) {
            throw new InvalidArgumentException(
                "{$label} contains too many items.",
            );
        }

        foreach ($value as $key => $item) {
            if (is_string($key)) {
                $this->assertJsonShape($key, "{$label} object key", $depth, $maximumDepth);
            }

            $this->assertJsonShape($item, $label, $depth + 1, $maximumDepth);
        }
    }
}
