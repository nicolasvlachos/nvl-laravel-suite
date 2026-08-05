<?php

declare(strict_types=1);

namespace Nvl\Templates\Services;

use InvalidArgumentException;
use JsonException;
use Nvl\Templates\Support\TemplatesConfiguration;

/**
 * Applies deterministic byte, depth, and item limits to template-owned values.
 */
final class TemplateContentGuard
{
    /**
     * @param  array<array-key, mixed>  $value
     * @return array<string, mixed>
     */
    public function schema(array $value): array
    {
        return $this->assertObjectWithinLimits($value, 'schema');
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @return array<string, mixed>
     */
    public function data(array $value): array
    {
        return $this->assertObjectWithinLimits($value, 'data');
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @return array<string, mixed>
     */
    public function payload(array $value): array
    {
        return $this->assertObjectWithinLimits($value, 'payload');
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @return array<string, mixed>
     */
    public function settings(array $value): array
    {
        return $this->assertObjectWithinLimits($value, 'settings');
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @return array<string, mixed>
     */
    public function metadata(array $value): array
    {
        return $this->assertObjectWithinLimits($value, 'metadata');
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @return array<string, mixed>
     */
    public function rendererOptions(array $value): array
    {
        return $this->assertObjectWithinLimits($value, 'renderer_options');
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @return array<string, mixed>
     */
    private function assertObjectWithinLimits(array $value, string $kind): array
    {
        $object = [];

        foreach ($value as $key => $item) {
            if (! is_string($key)) {
                throw new InvalidArgumentException(
                    "Template {$kind} must be a JSON object.",
                );
            }

            $object[$key] = $item;
        }

        $this->assertJsonValue($object, $kind);

        try {
            $encoded = json_encode($object, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException("Template {$kind} must be valid JSON data.", 0, $exception);
        }

        $maximumBytes = TemplatesConfiguration::limit("{$kind}_bytes", 262_144);

        if (strlen($encoded) > $maximumBytes) {
            throw new InvalidArgumentException("Template {$kind} exceeds {$maximumBytes} bytes.");
        }

        $items = 0;
        $this->walk(
            $object,
            1,
            TemplatesConfiguration::limit("{$kind}_depth", 16),
            TemplatesConfiguration::limit("{$kind}_items", 2_000),
            $items,
            $kind,
        );

        return $object;
    }

    private function assertJsonValue(mixed $value, string $kind): void
    {
        if (is_array($value)) {
            foreach ($value as $item) {
                $this->assertJsonValue($item, $kind);
            }

            return;
        }

        if (is_float($value) && ! is_finite($value)) {
            throw new InvalidArgumentException(
                "Template {$kind} must not contain non-finite numbers.",
            );
        }

        if ($value !== null
            && ! is_string($value)
            && ! is_int($value)
            && ! is_float($value)
            && ! is_bool($value)) {
            throw new InvalidArgumentException(
                "Template {$kind} must contain only JSON scalar, list, and object values.",
            );
        }
    }

    /**
     * @param  array<array-key, mixed>  $value
     */
    private function walk(
        array $value,
        int $depth,
        int $maximumDepth,
        int $maximumItems,
        int &$items,
        string $kind,
    ): void {
        if ($depth > $maximumDepth) {
            throw new InvalidArgumentException("Template {$kind} exceeds depth {$maximumDepth}.");
        }

        foreach ($value as $item) {
            $items++;

            if ($items > $maximumItems) {
                throw new InvalidArgumentException("Template {$kind} exceeds {$maximumItems} items.");
            }

            if (is_array($item)) {
                $this->walk(
                    $item,
                    $depth + 1,
                    $maximumDepth,
                    $maximumItems,
                    $items,
                    $kind,
                );
            }
        }
    }
}
