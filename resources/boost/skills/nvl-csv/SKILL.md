---
name: nvl-csv
description: Implement, integrate, test, or review nvl/csv in Laravel 13. Use for CSV analysis, typed import/export options, field mappings, validation, transformations, streaming, duplicate policies, memory limits, or queued chunk processing.
---

# NVL CSV

Treat CSV input as untrusted, potentially large data whose dialect, encoding, validation policy, and write boundary must be explicit.

## Import

- Configure a source with `CSVImport::make()->fromFile(...)` or `fromDisk(...)`.
- Express schema conversion with `CSVFieldMapping`; keep persistence in the row processor.
- Choose delimiter, encoding, headers, row limits, duplicate strategy, transaction behavior, and error thresholds deliberately.
- Use `stream()` for pull-based processing and `batch()` for bounded synchronous chunks.
- Do not retain complete source files or unbounded error payloads in memory.

## Export

- Configure exact headings and fields before exporting arrays, collections, or Eloquent queries.
- Prefer query chunking for large datasets.
- Choose format, delimiter, encoding, BOM, header, and index behavior through `CSVConfiguration` or `CSVExportOptionsData`.
- Keep exports on a configured Laravel filesystem disk; do not assume a local path.

## Async processing

- Use `CSVAsyncProcessor` only with serializable callbacks and a configured queue batch repository.
- Ensure the worker timeout is lower than the connection's `retry_after`.
- Treat batch status metadata as operational data, not durable business state.

## Verify

Test quoted delimiters, embedded newlines, BOMs, non-UTF-8 encodings, empty files, missing columns, validation failures, duplicate strategies, transaction rollbacks, remote-like disks, large iterables, serialized jobs, cancellation, and failed batches.
