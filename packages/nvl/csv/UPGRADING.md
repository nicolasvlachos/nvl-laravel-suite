# Upgrading NVL CSV

## Migrating from `App\Lib\CSV` to 1.0

Version 1.0 preserves the original class names and public fluent methods under the package namespace.

1. Replace `App\Lib\CSV\...` imports with `Nvl\Csv\...`.
2. Replace host `App\Traits\DataTransform` assumptions with the package-provided DTO behavior from `nvl/data`; no consumer trait import is required.
3. Keep `CSVFieldMapping::withTransformer(...)` as a static factory.
4. Confirm export paths. Fluent export builders default to the `exports` directory; directly constructed option DTOs without `path` write at the disk root.
5. Confirm duplicate behavior. `SKIP` and `ERROR` are enforced within the source file; persistence-oriented strategies pass duplicates to the application processor.
6. For async processing, create Laravel’s queue batch table, run workers for the `csv-processing` queue, and ensure captured callback state is serializable.
7. Remove calls copied from the legacy prose documentation that never existed in the implementation, including instance `withTransformer`, `StringTransformer::make`, `lowercase` fluent methods, `nullIfEmpty`, `limit`, and importer `withDelimiter`.

The package now honors import/export DTO mappings and format/encoding options, resets reusable service state, handles variable-length BOMs, streams remote disks, and serializes queued callbacks. Strict imports reject uneven rows; lenient imports preserve the legacy pad/truncate behavior. Failure counters remain complete while only the first 1,000 failed-row payloads and error strings are retained. Test encoding, strictness, failure-reporting, and queue behavior when migrating workloads that depended on the previous accidental behavior.
