# Contributing

Keep Templates headless, generic, and free of application-specific workflows.
Add strict types, complete parameter and return types, DTOs at boundaries,
transactions in Actions, and after-commit events. New renderers implement the
public contract and must never evaluate untrusted executable source.

Add Pest coverage for success, authorization, concurrency, failure, queue,
locale, database, Media behavior, payload schema validation, PDF output, and
remote/local PDF resource rejection. Run:

From the monorepo root:

```bash
vendor/bin/pint --test --format agent packages/nvl/templates
php -d memory_limit=1G vendor/bin/phpstan analyse \
    packages/nvl/templates/src \
    packages/nvl/templates/tests/Fixtures \
    -c phpstan.neon.dist \
    --level=max
vendor/bin/pest \
    --test-directory=packages/nvl/templates/tests \
    --configuration=packages/nvl/templates/phpunit.xml.dist \
    --bootstrap=vendor/autoload.php \
    --compact \
    packages/nvl/templates/tests
```

From a standalone package checkout after `composer install`:

```bash
composer quality
```

Update the README, changelog, upgrade guide, and packaged skill whenever a
public contract, command, configuration key, schema, or operational behavior
changes.
