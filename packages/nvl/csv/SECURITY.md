# Security Policy

Security fixes are provided for the current `2.x` release line on PHP 8.3–8.4 and Laravel 13.

Report vulnerabilities privately through the repository host’s security-advisory feature. Include the affected version, filesystem/queue driver, source dialect and encoding, minimal reproduction, and impact. Remove confidential row values from the report unless they are essential to reproduce the issue.

CSV files are untrusted input. Consumers must authorize access, enforce upload and source-size policy, scan files where required, validate mappings before persistence, and avoid exposing retained failed-row data. The package does not neutralize spreadsheet formulas; apply an application-specific formula-injection policy before files are opened by spreadsheet software.

Async callbacks must capture only serializable state and perform idempotent work. Keep the queue connection’s `retry_after` above the job timeout and protect queue/batch metadata with the same operational access controls as other job infrastructure.
