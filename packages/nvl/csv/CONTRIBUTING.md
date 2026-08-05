# Contributing to NVL CSV

Changes must preserve the package’s headless, transport-neutral CSV boundary and the established `Nvl\Csv` public API.

Add Pest coverage for quoted delimiters, embedded newlines, headerless files, uneven rows, BOM variants, non-UTF-8 encodings, remote filesystem streams, DTO options, validation, transformations, duplicate policies, transaction outcomes, bounded memory behavior, serialized jobs, cancellation, and failed batches. Do not add application models, host namespaces, routes, or business-specific persistence.

Run Pint, PHPStan at maximum strictness, the isolated Pest suite, Composer validation, dependency analysis, generated TypeScript verification, package-family validation, and the monorepo coverage gate. New public behavior requires accurate README and upgrading guidance.
