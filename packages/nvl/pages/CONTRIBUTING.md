# Contributing

Contributions should preserve the package’s headless boundary and Laravel 12–13 compatibility.

Before submitting a change:

1. Keep strict types, typed parameters and returns, complete generics, thin controllers, lean models, and one public `execute()` method per Action.
2. Do not duplicate Content, SEO, Metafields, Media, or Translatable persistence.
3. Keep dynamic resources behind stable registered aliases and sanitized DTOs.
4. Add Pest coverage for behavior and failure paths.
5. Run Pint, PHPStan at max level, the Pages test suite, and family validation.

Changes to public DTOs, Actions, contracts, migrations, configuration, routes, or events require README, changelog, and upgrading notes.
