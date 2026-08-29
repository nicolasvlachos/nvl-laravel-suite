# Security Policy

Security fixes are provided for the current `2.x` release line on PHP 8.3–8.5 and Laravel 13.

Report vulnerabilities privately through the repository host's security-advisory feature. Include route configuration, payload shape, origin, token or idempotency behavior, impact, and a minimal reproduction without personal submission data.

Public and management routes are disabled by default. Before enabling submissions, configure origin policy, typed CORS settings, CSRF or signed tokens, rate limits, payload bounds, repeat-registration identity, idempotency, spam handling, retention, and safe error responses.

Do not accept submission origin from payload data. Restricted public requests must pass the package origin matcher; iframe headers are not proof of embedding. Apply the generated CSP `frame-ancestors` value in the host application's response-security middleware.

Custom handlers can perform external side effects that a database transaction cannot roll back. Use the package receipt and an application-level idempotency key in the downstream system. A failed or in-progress receipt intentionally conflicts instead of automatically replaying unknown work.

Entry lifecycle events are sanitized. Keep complete entry payloads out of logs, queues, exception metadata, and custom events unless the destination has an explicit PII retention policy.
