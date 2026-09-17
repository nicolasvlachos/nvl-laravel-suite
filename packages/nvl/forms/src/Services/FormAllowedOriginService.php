<?php

declare(strict_types=1);

namespace Nvl\Forms\Services;

use Illuminate\Support\Str;
use Nvl\Forms\Models\AllowedOrigin;
use Nvl\Forms\Models\Form;
use Nvl\Forms\Support\AllowedOriginExpression;
use Nvl\Tenancy\Services\TenantBoundary;

/**
 * Manages allowed origin normalization, creation, and synchronization for forms.
 *
 * Provides a single source of truth for origin string normalization (trim, filter,
 * deduplicate by lowercase) and the two write operations: batch create (for new forms)
 * and sync (upsert + deactivate removed for existing forms).
 */
final class FormAllowedOriginService
{
    /** Create the tenant-aware origin service. */
    public function __construct(private readonly TenantBoundary $boundary) {}

    /**
     * Normalize an array of raw origin values into clean, unique strings.
     *
     * Filters non-string values, validates host-only expressions, and
     * deduplicates normalized values by case-insensitive comparison.
     *
     * @param  array<int, mixed>  $origins  Raw origin values from payload
     * @return array<int, string> Normalized, unique origin strings
     */
    public function normalizeOrigins(array $origins): array
    {
        return collect($origins)
            ->filter(fn ($v) => is_string($v))
            ->map(fn (string $v) => AllowedOriginExpression::normalize($v))
            ->unique(fn (string $v) => Str::lower($v))
            ->values()
            ->all();
    }

    /**
     * Create allowed origins for a newly created form.
     *
     * Normalizes the input array and creates active AllowedOrigin records
     * for each unique origin string.
     *
     * @param  Form  $form  The target form
     * @param  array<int, mixed>  $origins  Raw origin values from the mutation payload
     */
    public function createOrigins(Form $form, array $origins): void
    {
        $this->boundary->assertRecord($form, 'forms.forms');
        $normalized = $this->normalizeOrigins($origins);

        foreach ($normalized as $origin) {
            $form->allowedOrigins()->create([
                ...$this->childOwnership($form),
                'origin' => $origin,
                'is_active' => true,
            ]);
        }
    }

    /**
     * Synchronize allowed origins for an existing form.
     *
     * Performs a three-way sync against the current list:
     * - Existing origins in the new list are re-activated with updated values
     * - New origins not yet in the database are created as active
     * - Existing origins not in the new list are deactivated (preserving usage history)
     *
     * @param  Form  $form  The form to sync origins for
     * @param  array<int, mixed>  $origins  Raw origin values from the mutation payload
     */
    public function syncOrigins(Form $form, array $origins): void
    {
        $this->boundary->assertRecord($form, 'forms.forms');
        $incoming = $this->normalizeOrigins($origins);

        $form->loadMissing('allowedOrigins');

        $existingByKey = $form->allowedOrigins
            ->keyBy(fn (AllowedOrigin $o) => Str::lower(trim((string) $o->origin)));

        $incomingKeySet = array_fill_keys(
            collect($incoming)->map(fn (string $v) => Str::lower($v))->all(),
            true,
        );

        // Upsert incoming origins
        foreach ($incoming as $origin) {
            $key = Str::lower($origin);
            $existing = $existingByKey->get($key);

            if ($existing instanceof AllowedOrigin) {
                $existing->update([
                    'origin' => $origin,
                    'is_active' => true,
                ]);

                continue;
            }

            $form->allowedOrigins()->create([
                ...$this->childOwnership($form),
                'origin' => $origin,
                'is_active' => true,
            ]);
        }

        // Deactivate removed origins
        foreach ($existingByKey as $key => $existing) {
            if (! isset($incomingKeySet[(string) $key])) {
                $existing->update(['is_active' => false]);
            }
        }
    }

    /** @return array{tenant_id?:mixed} */
    private function childOwnership(Form $form): array
    {
        return array_key_exists('tenant_id', $form->getAttributes())
            ? ['tenant_id' => $form->getRawOriginal('tenant_id')]
            : [];
    }
}
