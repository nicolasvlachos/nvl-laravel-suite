<?php

declare(strict_types=1);

namespace Nvl\Forms\Services;

use Nvl\Translatable\Services\LocaleRegistry;

/**
 * Maps localized form payloads into dedicated translation rows.
 */
final readonly class FormTranslationPayloadMapper
{
    public function __construct(
        private LocaleRegistry $locales,
    ) {}

    /**
     * Normalize localized form content while preserving arbitrary nested fields.
     *
     * @param  array<string, mixed>|null  $translations
     * @return array<string, array<string, mixed>>
     */
    public function rows(
        ?array $translations,
    ): array {
        $rows = [];

        foreach ($translations ?? [] as $locale => $payload) {
            $normalizedLocale = $this->locales->assertSupported($locale);
            if (! is_array($payload)) {
                continue;
            }

            $rows[$normalizedLocale] = $this->translationAttributes(
                $this->stringKeyedMap($payload),
            );
        }

        return $rows;
    }

    /**
     * Extract standard copy while retaining the complete arbitrary payload as content.
     *
     * @param  array<string, mixed>  $content
     * @return array<string, mixed>
     */
    private function translationAttributes(array $content): array
    {
        return [
            'name' => $this->stringValue($content, ['name']),
            'description' => $this->stringValue($content, ['description']),
            'submit_button_label' => $this->stringValue(
                $content,
                ['submitButtonLabel'],
            ),
            'success_title' => $this->stringValue(
                $content,
                ['successTitle'],
            ),
            'success_message' => $this->stringValue(
                $content,
                ['successMessage'],
            ),
            'content' => $content,
        ];
    }

    /**
     * Return the first present string value without treating an empty string as absent.
     *
     * @param  array<string, mixed>  $content
     * @param  list<string>  $keys
     */
    private function stringValue(array $content, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $content) && is_string($content[$key])) {
                return $content[$key];
            }
        }

        return null;
    }

    /**
     * @param  array<array-key, mixed>  $content
     * @return array<string, mixed>
     */
    private function stringKeyedMap(array $content): array
    {
        $normalized = [];

        foreach ($content as $key => $value) {
            if (is_string($key)) {
                $normalized[$key] = $value;
            }
        }

        return $normalized;
    }
}
