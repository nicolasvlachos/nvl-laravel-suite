<?php

declare(strict_types=1);

namespace Nvl\Translations\Services;

use Illuminate\Database\UniqueConstraintViolationException;
use Nvl\Tenancy\Exceptions\TenantBoundaryViolation;
use Nvl\Tenancy\Services\TenantBoundary;
use Nvl\Translations\Contracts\TenantTranslationRepository;
use Nvl\Translations\Exceptions\StaleTranslationWorkspaceException;
use Nvl\Translations\Models\TenantTranslationOverride;

/** Persists allowlisted tenant copy without consulting or mutating source files. */
final readonly class DatabaseTenantTranslationRepository implements TenantTranslationRepository
{
    public function __construct(private TenantBoundary $boundary) {}

    public function get(string $key, string $locale): ?string
    {
        $this->assertAllowed($key);
        $value = $this->query($key, $locale)->value('value');

        return is_string($value) ? $value : null;
    }

    public function set(string $key, string $locale, string $value, int $expectedRevision): void
    {
        $this->assertAllowed($key);
        $this->assertLocale($locale);
        if ($expectedRevision === 0) {
            try {
                TenantTranslationOverride::query()->create([
                    ...$this->boundary->attributes('translations.overrides'),
                    'key' => $key,
                    'locale' => $locale,
                    'value' => $value,
                    'revision' => 1,
                ]);

                return;
            } catch (UniqueConstraintViolationException) {
                throw StaleTranslationWorkspaceException::forEntry($key.':'.$locale);
            }
        }

        $updated = $this->query($key, $locale)->where('revision', $expectedRevision)->update([
            'value' => $value,
            'revision' => $expectedRevision + 1,
            'updated_at' => now(),
        ]);
        if ($updated !== 1) {
            throw StaleTranslationWorkspaceException::forEntry($key.':'.$locale);
        }
    }

    public function forget(string $key, string $locale, int $expectedRevision): void
    {
        $this->assertAllowed($key);
        $this->assertLocale($locale);
        if ($this->query($key, $locale)->where('revision', $expectedRevision)->delete() !== 1) {
            throw StaleTranslationWorkspaceException::forEntry($key.':'.$locale);
        }
    }

    /** @return \Illuminate\Database\Eloquent\Builder<TenantTranslationOverride> */
    private function query(string $key, string $locale): \Illuminate\Database\Eloquent\Builder
    {
        $this->assertLocale($locale);

        return $this->boundary->query(TenantTranslationOverride::query(), 'translations.overrides')
            ->where('key', $key)
            ->where('locale', $locale);
    }

    private function assertAllowed(string $key): void
    {
        $configured = config('translations.tenant_overrides.keys', []);
        $keys = is_array($configured) ? array_values(array_filter($configured, static fn (mixed $item): bool => is_string($item))) : [];
        if ($key === '' || str_contains($key, '/') || str_contains($key, '\\') || str_contains($key, '*') || ! in_array($key, $keys, true)) {
            throw new TenantBoundaryViolation('Translation key is not allowlisted for tenant copy overrides.');
        }
    }

    private function assertLocale(string $locale): void
    {
        if (preg_match('/^[A-Za-z]{2,3}(?:[-_][A-Za-z0-9]{2,8})*$/', $locale) !== 1) {
            throw new TenantBoundaryViolation('Tenant translation locale is invalid.');
        }
    }
}
