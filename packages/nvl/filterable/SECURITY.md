# Security Policy

Security fixes are provided for the current `1.x` release line on PHP 8.3–8.5 and Laravel 12–13.

Report vulnerabilities privately through the repository host's security-advisory feature. Include the affected version, database driver, filter definition, input, generated query behavior, and impact.

Never register raw request column names, relation paths, SQL fragments, or unbounded custom handlers. Treat filter definitions as part of the application's authorization and data-exposure boundary.

Use `fromHttpQuery()` at HTTP boundaries, set endpoint-appropriate filter/sort/value/string limits, and declare a stable tie-breaker for paginated queries. Custom handlers receive normalized values but remain responsible for parameterized SQL and bounded query cost.
