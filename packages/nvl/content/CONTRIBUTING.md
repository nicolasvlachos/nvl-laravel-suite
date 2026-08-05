# Contributing

Use PHP 8.3 or 8.4 and test against Laravel 12 and 13. Keep the package headless,
strictly typed, consumer-neutral, and limited to its declared dependencies.

Run `composer validate --strict`, `composer format`, `composer analyse`, and
`composer test` from this package. New field types require an adapter, boundary
tests, deterministic normalization, documentation, and TypeScript-safe public
DTOs. Schema or persistence changes require SQLite, PostgreSQL, MySQL, clean
install, rollback, and adoption coverage. Public actions own transactions;
events must dispatch after commit.
