<?php

declare(strict_types=1);

namespace Nvl\Forms\Services;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Nvl\Forms\Models\Form;
use Nvl\Forms\Models\FormEntry;
use Nvl\Tenancy\Services\TenantBoundary;

/** Reloads a submitted entry beneath its canonical tenant-owned Form. */
final readonly class FormEntryLocator
{
    /** Create the tenant-scoped locator. */
    public function __construct(private TenantBoundary $boundary) {}

    /** Return the exact child or deny ownership mismatch without disclosure. */
    public function forForm(Form $form, string $entryId): FormEntry
    {
        $this->boundary->assertRecord($form, 'forms.forms');
        $entry = $this->boundary->query(FormEntry::query(), 'forms.entries')
            ->where('form_id', $form->getKey())
            ->whereKey($entryId)
            ->first();

        if (! $entry instanceof FormEntry) {
            throw (new ModelNotFoundException)->setModel(FormEntry::class, [$entryId]);
        }

        return $entry;
    }
}
