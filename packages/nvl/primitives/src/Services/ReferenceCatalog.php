<?php

declare(strict_types=1);

namespace Nvl\Primitives\Services;

use Illuminate\Contracts\Config\Repository;
use Nvl\Primitives\Data\ReferenceOption;
use Nvl\Primitives\Exceptions\InvalidPrimitive;
use Nvl\Primitives\ValueObjects\LocaleCode;
use Symfony\Component\Intl\Countries;
use Symfony\Component\Intl\Currencies;
use Symfony\Component\Intl\Languages;
use Symfony\Component\Intl\Scripts;

/**
 * Searches normalized reference options without imposing an HTTP endpoint.
 */
final readonly class ReferenceCatalog
{
    /**
     * Create the reference catalog.
     */
    public function __construct(
        private Repository $config,
    ) {}

    /**
     * Return country options from the installed ISO catalog.
     *
     * @return list<ReferenceOption>
     */
    public function countries(
        ?string $search = null,
        ?string $displayLocale = null,
        int $limit = 50,
    ): array {
        $options = [];
        $displayLocale = $this->displayLocale($displayLocale);

        foreach (Countries::getNames($displayLocale) as $code => $label) {
            $options[] = new ReferenceOption(
                code: $code,
                label: $label,
                metadata: [],
            );
        }

        return $this->filter($options, $search, $limit);
    }

    /**
     * Return currency options with symbol and fraction metadata.
     *
     * @return list<ReferenceOption>
     */
    public function currencies(
        ?string $search = null,
        ?string $displayLocale = null,
        int $limit = 50,
    ): array {
        $options = [];
        $displayLocale = $this->displayLocale($displayLocale);

        foreach (Currencies::getNames($displayLocale) as $code => $label) {
            $options[] = new ReferenceOption(
                code: $code,
                label: $label,
                metadata: [
                    'symbol' => Currencies::getSymbol($code, $displayLocale),
                    'fractionDigits' => Currencies::getFractionDigits($code),
                ],
            );
        }

        return $this->filter($options, $search, $limit);
    }

    /**
     * Return language options from the installed ISO catalog.
     *
     * @return list<ReferenceOption>
     */
    public function languages(
        ?string $search = null,
        ?string $displayLocale = null,
        int $limit = 50,
    ): array {
        $options = [];
        $displayLocale = $this->displayLocale($displayLocale);

        foreach (Languages::getNames($displayLocale) as $code => $label) {
            $options[] = new ReferenceOption(
                code: $code,
                label: $label,
                metadata: [],
            );
        }

        return $this->filter($options, $search, $limit);
    }

    /**
     * Return application-supported locale options with distinguishing qualifiers.
     *
     * @return list<ReferenceOption>
     */
    public function locales(
        ?string $search = null,
        ?string $displayLocale = null,
        int $limit = 50,
    ): array {
        $configured = $this->config->get('primitives.locales.supported', []);

        if (! is_array($configured)) {
            throw InvalidPrimitive::for('locale catalog', 'supported locales must be an array.');
        }

        $displayLocale = $this->displayLocale($displayLocale);
        $options = [];

        foreach ($configured as $locale) {
            if (! is_string($locale)) {
                throw InvalidPrimitive::for('locale catalog', 'every supported locale must be a string.');
            }

            $code = LocaleCode::from($locale);
            $script = $code->script();
            $region = $code->regionCode();
            $qualifiers = [];

            if ($script !== null) {
                $qualifiers[] = Scripts::getName($script, $displayLocale);
            }

            if ($region !== null) {
                $qualifiers[] = preg_match('/^[A-Z]{2}$/', $region) === 1
                    ? Countries::getName($region, $displayLocale)
                    : $region;
            }

            $label = Languages::getName($code->language(), $displayLocale);

            if ($qualifiers !== []) {
                $label .= ' ('.implode(', ', $qualifiers).')';
            }

            $options[] = new ReferenceOption(
                code: (string) $code,
                label: $label,
                metadata: array_filter([
                    'script' => $script,
                    'region' => $region,
                ], static fn (?string $value): bool => $value !== null),
            );
        }

        return $this->filter($options, $search, $limit);
    }

    /**
     * Return configured deployment-specific city options.
     *
     * @return list<ReferenceOption>
     */
    public function cities(?string $search = null, int $limit = 50): array
    {
        return $this->configured('cities', $search, $limit);
    }

    /**
     * Return configured deployment-specific bank options.
     *
     * @return list<ReferenceOption>
     */
    public function banks(?string $search = null, int $limit = 50): array
    {
        return $this->configured('banks', $search, $limit);
    }

    /**
     * Return a strictly validated configured catalog.
     *
     * @return list<ReferenceOption>
     */
    private function configured(string $catalog, ?string $search, int $limit): array
    {
        $configured = $this->config->get("primitives.reference.{$catalog}", []);

        if (! is_array($configured)) {
            throw InvalidPrimitive::for("{$catalog} catalog", 'the catalog must be an array.');
        }

        $options = [];

        foreach ($configured as $code => $definition) {
            if (
                ! is_string($code)
                || trim($code) === ''
                || ! is_array($definition)
                || ! is_string($definition['label'] ?? null)
                || trim($definition['label']) === ''
            ) {
                throw InvalidPrimitive::for(
                    "{$catalog} catalog",
                    'each entry requires a non-empty string code and label.',
                );
            }

            $metadata = [];

            foreach ($definition as $key => $value) {
                if ($key === 'label') {
                    continue;
                }

                if (
                    ! is_string($key)
                    || (! is_scalar($value) && $value !== null)
                ) {
                    throw InvalidPrimitive::for(
                        "{$catalog} catalog",
                        "metadata for [{$code}] must contain only scalar or null values.",
                    );
                }

                $metadata[$key] = $value;
            }

            /** @var array<string, bool|int|float|string|null> $metadata */
            $options[] = new ReferenceOption(trim($code), trim($definition['label']), $metadata);
        }

        return $this->filter($options, $search, $limit);
    }

    /**
     * Filter and limit reference options deterministically.
     *
     * @param  list<ReferenceOption>  $options
     * @return list<ReferenceOption>
     */
    private function filter(array $options, ?string $search, int $limit): array
    {
        if ($limit < 1 || $limit > 250) {
            throw InvalidPrimitive::for('reference limit', 'the limit must be between 1 and 250.');
        }

        $needle = mb_strtolower(trim($search ?? ''));
        $filtered = array_filter(
            $options,
            static fn (ReferenceOption $option): bool => $needle === ''
                || str_contains(mb_strtolower($option->code), $needle)
                || str_contains(mb_strtolower($option->label), $needle),
        );

        return array_slice(array_values($filtered), 0, $limit);
    }

    /**
     * Validate and normalize an optional display locale.
     */
    private function displayLocale(?string $displayLocale): ?string
    {
        return $displayLocale !== null
            ? (string) LocaleCode::from($displayLocale)
            : null;
    }
}
