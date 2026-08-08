# Security Policy

## Supported versions

Security fixes are provided for the current stable `1.x` release line.
`dev-main` is pre-release software and should not be used as a production
dependency.

## Reporting a vulnerability

Use GitHub's private
[security-advisory form](https://github.com/nicolasvlachos/nvl-laravel-suite/security/advisories/new).
Do not open a public issue for a suspected vulnerability.

Include the affected suite version or commit, relevant configuration, a minimal
reproduction, expected impact, and any proposed mitigation. Remove credentials,
access tokens, personal data, storage paths, mail payloads, and other secrets
from the report unless they are essential to reproduction and can be shared
safely through the private advisory.

The maintainer will assess the report privately, prepare regression coverage,
coordinate a fix and release, and disclose details only after consumers have a
reasonable upgrade path.

## Operational responsibility

Several modules expose opt-in routes, queues, storage, scheduled work, or
destructive maintenance commands. Keep management routes disabled unless they
are protected by real application authorization, run each module's strict
doctor command after deployment, and review the module security and operations
documentation before enabling production integrations.
