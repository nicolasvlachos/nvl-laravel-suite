<?php

declare(strict_types=1);

namespace Nvl\Forms\Actions\FormEntry;

use Nvl\Forms\Models\Form;
use Nvl\Forms\Services\FormEntryExportService;

/**
 * Generates a safe filename for form entries export.
 *
 * Delegates segment normalization and timestamp format to the export service so
 * all entry export paths share the same filename contract.
 */
final class GenerateExportFilenameAction
{
    /**
     * Create the action with the export service dependency.
     *
     * @param  FormEntryExportService  $exportService  Export filename generator
     */
    public function __construct(
        private readonly FormEntryExportService $exportService,
    ) {}

    /**
     * Execute the filename generation.
     *
     * @param  Form  $form  Form model for export
     * @return string Generated filename
     */
    public function execute(Form $form): string
    {
        return $this->exportService->generateFilename($form, null);
    }
}
