<?php

declare(strict_types=1);

namespace Nvl\Forms\Services;

use Illuminate\Database\Eloquent\Collection;
use JsonException;
use Nvl\Forms\Exceptions\FormException;
use Nvl\Forms\Models\Form;
use Nvl\Forms\Models\FormEntry;

/**
 * Handles CSV generation, data sanitization, and dynamic field extraction for form entry exports.
 *
 * Provides the core export logic extracted from the action layer so that
 * ExportFormEntriesAction remains a thin orchestrator.
 */
final class FormEntryExportService
{
    private const string DEFAULT_ACTOR_SEGMENT = 'guest';

    private const string DEFAULT_FORM_SEGMENT = 'form';

    private const string FORMULA_PREFIX_CHARACTERS = '=+-@';

    /**
     * Generate CSV content from a collection of form entries.
     *
     * Builds column headers (including dynamic submission_data keys),
     * iterates entries to produce sanitized CSV rows, and returns the
     * complete CSV string with BOM-free UTF-8 encoding.
     *
     * @param  Form  $form  The form these entries belong to
     * @param  Collection<int, FormEntry>  $entries  Entries to export
     * @param  array<string, mixed>  $options  Export options (include_submission_data, include_sensitive_data)
     * @return string Complete CSV content
     *
     * @throws JsonException When submission data cannot be encoded
     */
    public function generateCsvContent(Form $form, Collection $entries, array $options = []): string
    {
        $includeSubmissionData = (bool) ($options['include_submission_data'] ?? true);
        $includeSensitiveData = (bool) ($options['include_sensitive_data'] ?? false);
        $dynamicFields = $includeSubmissionData ? $this->extractDynamicFields($entries) : [];

        $headers = $this->buildHeaders($includeSensitiveData, $dynamicFields);
        $csv = $this->arrayToCsvLine($headers);

        foreach ($entries as $entry) {
            $row = $this->buildEntryRow($entry, $dynamicFields, $includeSubmissionData, $includeSensitiveData);
            $csv .= $this->arrayToCsvLine($row);
        }

        return $csv;
    }

    /**
     * Extract sorted unique dynamic field keys from submission_data across all entries.
     *
     * Scans all entries' submission_data arrays and collects unique non-empty
     * field names, returning them sorted alphabetically.
     *
     * @param  Collection<int, FormEntry>  $entries  Entries to scan
     * @return array<int, string> Sorted dynamic field names
     */
    public function extractDynamicFields(Collection $entries): array
    {
        $dynamicFields = [];

        foreach ($entries as $entry) {
            if (! is_array($entry->submission_data)) {
                continue;
            }

            foreach ($entry->submission_data as $field => $_value) {
                if ($field === '') {
                    continue;
                }

                if (! in_array($field, $dynamicFields, true)) {
                    $dynamicFields[] = $field;
                }
            }
        }

        sort($dynamicFields);

        return $dynamicFields;
    }

    /**
     * Generate a filename for the export file.
     *
     * Format: {form-handle}_entries_{actor-id}_{timestamp}.csv
     *
     * @param  Form  $form  The form being exported
     * @param  string|int|null  $actorId  Authenticated user identifier
     * @return string Generated filename
     */
    public function generateFilename(Form $form, string|int|null $actorId): string
    {
        $timestamp = now()->format('Y-m-d_H-i-s');
        $formHandle = $this->sanitizeFilenameSegment(
            (string) ($form->handle ?: $form->id),
            self::DEFAULT_FORM_SEGMENT.'-'.$form->id,
        );
        $userId = $this->sanitizeFilenameSegment(
            $actorId !== null ? (string) $actorId : self::DEFAULT_ACTOR_SEGMENT,
            self::DEFAULT_ACTOR_SEGMENT,
        );

        return "{$formHandle}_entries_{$userId}_{$timestamp}.csv";
    }

    /**
     * Sanitize a value for safe CSV output.
     *
     * Removes control characters, prevents CSV injection by prefixing
     * formula-starting characters (=, +, -, @) with a single quote,
     * and JSON-encodes non-scalar values.
     *
     * @param  mixed  $value  Value to sanitize
     * @return string Sanitized string safe for CSV output
     *
     * @throws JsonException When non-scalar value cannot be encoded
     */
    public function sanitizeForExport(mixed $value): string
    {
        $sanitized = is_scalar($value) || $value === null
            ? (string) ($value ?? '')
            : json_encode($value, JSON_THROW_ON_ERROR);

        $sanitized = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $sanitized);

        if ($this->startsWithFormulaPrefix($sanitized)) {
            $sanitized = "'".$sanitized;
        }

        return $sanitized;
    }

    /**
     * Build CSV column headers based on export options and dynamic fields.
     *
     * @param  bool  $includeSensitiveData  Whether to include PII columns
     * @param  array<int, string>  $dynamicFields  Dynamic submission_data field names
     * @return array<int, string> Header row
     */
    private function buildHeaders(bool $includeSensitiveData, array $dynamicFields): array
    {
        $headers = ['ID', 'Subject', 'First Name', 'Last Name', 'Submitted From', 'Submitted At'];

        if ($includeSensitiveData) {
            $headers = array_merge($headers, ['Email', 'Phone', 'Address', 'IP Address']);
        }

        $headers[] = 'Message';

        foreach ($dynamicFields as $fieldName) {
            $headers[] = 'Field: '.$fieldName;
        }

        return $headers;
    }

    /**
     * Build a single CSV data row for an entry.
     *
     * @param  FormEntry  $entry  The entry to convert
     * @param  array<int, string>  $dynamicFields  Dynamic field names for column alignment
     * @param  bool  $includeSubmissionData  Whether to include dynamic field columns
     * @param  bool  $includeSensitiveData  Whether to include PII columns
     * @return array<int, string> Row values
     *
     * @throws JsonException When sanitization fails
     */
    private function buildEntryRow(
        FormEntry $entry,
        array $dynamicFields,
        bool $includeSubmissionData,
        bool $includeSensitiveData,
    ): array {
        $row = [
            $entry->id,
            $this->sanitizeForExport($entry->subject ?? ''),
            $this->sanitizeForExport($entry->first_name ?? ''),
            $this->sanitizeForExport($entry->last_name ?? ''),
            $this->sanitizeForExport($entry->submitted_from ?? ''),
            $entry->created_at->format('Y-m-d H:i:s'),
        ];

        if ($includeSensitiveData) {
            $row = array_merge($row, [
                $this->sanitizeForExport($entry->email ?? ''),
                $this->sanitizeForExport($entry->phone ?? ''),
                $this->sanitizeForExport($entry->address ?? ''),
                $entry->ip_address ?? '',
            ]);
        }

        $row[] = $this->sanitizeForExport($entry->body ?? '');

        if ($includeSubmissionData && $entry->submission_data !== null && $entry->submission_data !== []) {
            foreach ($dynamicFields as $fieldName) {
                $value = $entry->submission_data[$fieldName] ?? '';
                $row[] = $this->sanitizeForExport($value);
            }
        } elseif ($includeSubmissionData) {
            $row = array_merge($row, array_fill(0, count($dynamicFields), ''));
        }

        return $row;
    }

    /**
     * Convert an array of values to a CSV line through PHP's native CSV writer.
     *
     * @param  array<int|string, string>  $array  Values to encode
     * @return string CSV-formatted line with trailing newline
     *
     * @throws FormException When the CSV stream cannot be opened or read
     */
    private function arrayToCsvLine(array $array): string
    {
        $stream = fopen('php://temp', 'r+');

        if ($stream === false) {
            throw new FormException('Unable to open temporary stream for form entry CSV export.');
        }

        $written = fputcsv($stream, array_values($array));

        if ($written === false) {
            fclose($stream);

            throw new FormException('Unable to write form entry CSV row.');
        }

        rewind($stream);

        $line = stream_get_contents($stream);
        fclose($stream);

        if ($line === false) {
            throw new FormException('Unable to read form entry CSV row.');
        }

        return $line;
    }

    /**
     * Normalize a filename segment to filesystem-safe ASCII characters.
     *
     * @param  string  $value  Raw segment value
     * @param  string  $fallback  Fallback segment when sanitization removes all content
     */
    private function sanitizeFilenameSegment(string $value, string $fallback): string
    {
        $segment = preg_replace('/[^A-Za-z0-9_-]+/', '_', $value);
        $segment = trim((string) $segment, '_-');

        return $segment !== '' ? $segment : $fallback;
    }

    /**
     * Determine whether a sanitized value could be interpreted as a spreadsheet formula.
     *
     * @param  string  $value  Sanitized value
     */
    private function startsWithFormulaPrefix(string $value): bool
    {
        $trimmed = ltrim($value);

        return $trimmed !== '' && str_contains(self::FORMULA_PREFIX_CHARACTERS, $trimmed[0]);
    }
}
