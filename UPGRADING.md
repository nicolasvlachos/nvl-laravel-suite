# Upgrading NVL Laravel Suite

## From 1.x to 2.0

### Make every legacy module decision explicit

Suite 2.0 changes the fallback for a non-null legacy `modules` map. An omitted
module flag is disabled. In 1.x the same omission was compatibility-enabled.
Explicit `true` roots remain enabled, and their transitive dependencies are
still enabled in canonical order even when a dependency is omitted or set to
`false`.

Before:

```php
return [
    'modules' => [
        'auth' => true,
    ],
];
```

In 2.0 this selects only `support`, `data`, and `auth`; every other module is
disabled. To preserve the 1.x full-suite behavior, replace the partial map with
the full explicit output:

```bash
php artisan nvl:suite:upgrade:check --strict
php artisan nvl:suite:configure --profile=full-suite --full
php artisan nvl:suite:configure --profile=full-suite --full --write --force
php artisan optimize:clear
php artisan config:cache
php artisan nvl:suite:doctor --strict
```

For an intentionally smaller installation, substitute `auth-only`,
`content-platform`, or `communications`, then review every generated boolean.
The replacement file contains all twenty module keys. A forced replacement
creates an exact sibling backup named
`nvl-suite.php.backup-YYYYMMDD-HHMMSS`; no backup is created for a dry run, a
new file, or an already-matching file.

The upgrade checker exits `1` with one `upgrade.module_missing` finding per
omitted key. Each finding states that the omission is requested-disabled and
reports its actual effective 2.0 state: dependencies may be effectively enabled
through closure, while other omissions are effectively disabled. Its
remediation is: `Run nvl:suite:configure with a reviewed profile and --full,
then use --write --force to replace the partial map with explicit decisions.`
Unknown and non-boolean keys remain errors and must be removed or corrected
before generating the replacement.

Profile/include/exclude selection is unchanged. A non-null legacy map remains
runtime-authoritative, and mixing that map with profile/include/exclude remains
an upgrade diagnostic. The shipped configuration continues to select the
`full-suite` profile, so applications without a published suite configuration
retain the complete module set.
