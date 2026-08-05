<?php

declare(strict_types=1);

namespace Nvl\Forms\Actions\FormEntry;

use Exception;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Nvl\Forms\Events\FormChangedEvent;
use Nvl\Forms\Models\Form;
use Nvl\Forms\Models\FormEntry;
use Nvl\Forms\Services\FormEntryExportService;

/**
 * Orchestrates form entry export with permissions, progress tracking, and activity logging.
 *
 * Delegates CSV generation and sanitization to FormEntryExportService while
 * managing permission validation, file storage, progress tracking, cleanup,
 * and activity logging.
 *
 * @see FormEntryExportService
 */
final class ExportFormEntriesAction
{
    /**
     * @param  FormEntryExportService  $exportService  CSV generation and sanitization service
     */
    public function __construct(
        private readonly FormEntryExportService $exportService,
    ) {}

    /**
     * Execute the form entries export with permission checking and progress tracking.
     *
     * Validates permissions, cleans up old exports, fetches matching entries,
     * delegates CSV generation to the export service, saves the file, and logs activity.
     *
     * @param  Form|string  $form  Form instance or identifier
     * @param  array<string, mixed>  $options  Export options (date_from, date_to, limit, has_contact_info, include_submission_data, include_sensitive_data)
     * @param  Authenticatable|null  $actor  Authenticated actor requesting the export
     * @return string Absolute path to the generated CSV file
     *
     * @throws Exception When permission denied, no data to export, or export fails
     */
    public function execute(Form|string $form, array $options = [], ?Authenticatable $actor = null): string
    {
        $form = $form instanceof Form ? $form : Form::findOrFail($form);

        $this->validateExportPermissions($form, $actor);
        $this->cleanupOldExportFiles();

        $entries = $this->getEntriesToExport($form, $options);

        if ($entries->isEmpty()) {
            throw new Exception(
                (string) trans('forms::forms/shared.messages.error.no_export_data', [
                    'items' => (string) trans('forms::entries/general.entities.plural'),
                ])
            );
        }

        $rawActorId = $actor?->getAuthIdentifier();
        $actorId = is_string($rawActorId) || is_int($rawActorId) ? $rawActorId : null;
        $exportId = Str::ulid()->toString();
        $progressKey = 'export_progress_'.($actorId !== null ? (string) $actorId : 'guest').'_'.$form->id.'_'.$exportId;

        Cache::put($progressKey, [
            'status' => 'started',
            'progress' => 0,
            'total' => $entries->count(),
            'started_at' => now()->toISOString(),
        ], now()->addHours());

        try {
            $csvContent = $this->exportService->generateCsvContent($form, $entries, $options);

            $filename = $this->exportService->generateFilename($form, $actorId);
            $path = 'exports/forms/'.$filename;

            Storage::disk('local')->put($path, $csvContent);

            Cache::put($progressKey, ['status' => 'completed', 'progress' => 100], now()->addHours());

            event(FormChangedEvent::for(
                form: $form,
                operation: 'entries_exported',
                actor: $actor,
                context: [
                    'entry_count' => $entries->count(),
                    'file_size' => strlen($csvContent),
                ],
            ));

            return Storage::disk('local')->path($path);
        } catch (Exception $e) {
            Cache::put($progressKey, ['status' => 'failed', 'error' => $e->getMessage()], now()->addHours());

            throw $e;
        }
    }

    /**
     * Query entries to export based on filter options.
     *
     * Supports date range filtering, limit, and contact info presence filtering.
     *
     * @param  Form  $form  The form to export entries from
     * @param  array<string, mixed>  $options  Export filter options
     * @return Collection<int, FormEntry>
     */
    private function getEntriesToExport(Form $form, array $options): Collection
    {
        $query = $form->entries()->orderBy('created_at', 'desc');

        if (isset($options['date_from']) && is_string($options['date_from']) && $options['date_from'] !== '') {
            $query->whereDate('created_at', '>=', $options['date_from']);
        }

        if (isset($options['date_to']) && is_string($options['date_to']) && $options['date_to'] !== '') {
            $query->whereDate('created_at', '<=', $options['date_to']);
        }

        $limit = $options['limit'] ?? null;
        if (is_numeric($limit) && (int) $limit > 0) {
            $query->limit((int) $limit);
        }

        $hasContactInformation = filter_var(
            $options['has_contact_info'] ?? false,
            FILTER_VALIDATE_BOOLEAN,
        );
        if ($hasContactInformation) {
            $query->whereRaw('(email IS NOT NULL OR phone IS NOT NULL)');
        }

        /** @var Collection<int, FormEntry> $result */
        $result = $query->get();

        return $result;
    }

    /**
     * Validate that the actor has permission to export entries.
     *
     * @param  Form  $form  The form being exported
     * @param  Authenticatable|null  $actor  The requesting actor
     *
     * @throws Exception When actor is null or lacks export permission
     */
    private function validateExportPermissions(Form $form, ?Authenticatable $actor = null): void
    {
        if ($actor === null) {
            throw new Exception((string) trans('forms::forms/shared.messages.error.authentication_required'));
        }

        if (Gate::forUser($actor)->denies('export', $form)) {
            throw new Exception((string) trans('forms::forms/shared.messages.error.permission_denied'));
        }
    }

    /**
     * Delete export files older than 1 hour to prevent disk accumulation.
     */
    private function cleanupOldExportFiles(): void
    {
        try {
            $exportDir = 'exports/forms';
            /** @var array<int, string> $files */
            $files = Storage::disk('local')->files($exportDir);
            $cutoffTime = now()->subHour();

            foreach ($files as $file) {
                $lastModified = Storage::disk('local')->lastModified($file);
                if ($lastModified < $cutoffTime->timestamp) {
                    Storage::disk('local')->delete($file);
                }
            }
        } catch (Exception $e) {
            report($e);
        }
    }
}
