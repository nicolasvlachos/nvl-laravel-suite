<?php

declare(strict_types=1);

namespace Nvl\Forms\Actions\FormEntry;

use Nvl\Forms\Models\FormEntry;

/**
 * Retrieves a form entry with full details for display.
 */
final class ShowFormEntryAction
{
    /**
     * Execute the form entry retrieval.
     *
     * @param  FormEntry|string  $formEntry  Form entry instance or identifier
     * @return FormEntry Form entry with loaded relationships
     */
    public function execute(FormEntry|string $formEntry): FormEntry
    {
        // Resolve model if ID provided
        $formEntry = $formEntry instanceof FormEntry
            ? $formEntry
            : FormEntry::findOrFail($formEntry);

        // Load all relationships needed for display
        return $formEntry->load([
            'form:id,handle',
        ]);
    }
}
