# Frontend And API Docs Migration Guide

## Table Of Contents

- Frontend Generated Type References
- Frontend Migration Rules
- API Layer Rules
- API Docs Rules
- Response Contract Update Checklist
- Verification Commands

## Frontend Generated Type References

Every module DTO migration must regenerate TypeScript and inspect frontend consumers.

Required command:

```bash
php -d memory_limit=1G artisan typescript:transform
```

Then inspect:

```bash
git diff -- resources/js/types/generated
bash .agents/skills/backend-spatie-data-v2/scripts/scan-frontend-type-references.sh <module-scope> <OldTypeName> <NewTypeName>
npm run types
```

Use the generated declaration file for the module scope, for example:

| Module | Generated Type File |
| --- | --- |
| Bookings | `resources/js/types/generated/bookings.d.ts` |
| Vendors | `resources/js/types/generated/vendors.d.ts` |
| Vouchers | `resources/js/types/generated/vouchers.d.ts` |
| Auth users | `resources/js/types/generated/users.d.ts` |

## Frontend Migration Rules

- Update `import Foo = Modules.X.Y.FooData` aliases to the new generated namespace/type.
- Update local type wrapper files such as `*.types.ts`.
- Update table/form/hook prop types that use renamed generated contracts.
- Update tests and factories that create generated DTO-shaped objects.
- Do not paper over broken generated types with local `as any` casts.
- If TypeScript generation changes optionality/nullability, update frontend null checks to match the new backend truth.
- If a display field becomes required because the domain requires it, remove defensive frontend fallback only when runtime data is proven aligned.
- If a field remains nullable for an integrity/recovery state, keep explicit UI handling and document the state object contract.

## API Layer Rules

When a migrated DTO is used by API routes:

- Inspect route list before editing documentation:
  ```bash
  php artisan route:list --path=api/v1/<module-scope> --except-vendor -vv
  ```
- Confirm runtime response envelopes remain canonical.
- Do not change runtime behavior for docs-only migrations.
- If an API controller returns a renamed DTO, update controller imports and response examples together.
- If response shape changes from raw array to named DTO, update the response contract to the named PHP and TypeScript type.
- If a route returns file downloads, raw generated type files, webhooks, callbacks, or externally-owned public contracts, keep it outside canonical envelope assumptions.

## API Docs Rules

Apply the `api-docs-generation` skill whenever a migration touches:

- `config/api-docs.php`
- `config/scribe.php`
- `app/Support/ApiDocs/**`
- `public/docs/api/**`
- Scribe examples or response field metadata
- TypeScript metadata referenced by API docs

For every included API route, keep response contracts aligned:

```php
[
    'routes' => ['api.bookings.show'],
    'response_type' => 'simple',
    'php_type' => 'Modules\\Bookings\\Data\\Display\\BookingShowPage',
    'typescript_type' => 'Modules.Bookings.Data.Display.BookingShowPage',
    'typescript_file' => 'resources/js/types/generated/bookings.d.ts',
    'typescript_scope' => 'bookings',
    'description' => 'Booking show payload.',
    'example' => $bookingShowExample,
]
```

If the current active code still uses `*Data`, do not document future names until the code and generated TypeScript actually expose those names.

## Response Contract Update Checklist

For each affected route:

- Route name matches exact contract before wildcard contracts.
- `php_type` matches the actual backend Data class or exact PHP shape.
- `typescript_type` matches generated TypeScript exactly.
- `typescript_file` points to the generated declaration file.
- `typescript_scope` points to `/api/v1/app/types/{scope}`.
- Example payload matches the DTO shape and camelCase output.
- Paginated examples are one item from `data.items[]`, not the whole paginator.
- Created responses use `response_type => simple` with `status => 201`.
- Suggestion/selector endpoints use named DTOs when named DTOs exist.
- Exclusions include a reason.

## Verification Commands

After API docs changes for one module:

```bash
php artisan api-docs:generate <module-slug> --force
php artisan test --compact --filter=ApiDocs
```

Smoke-check generated artifacts:

```bash
rg -n -F "Modules.<Module>." public/docs/api/<module-slug>/openapi.yaml public/docs/api/<module-slug>/collection.json
rg -n -F "/api/v1/app/types/<typescript-scope>" public/docs/api/<module-slug>
rg -n -F "success" public/docs/api/<module-slug>/index.html public/docs/api/<module-slug>/openapi.yaml public/docs/api/<module-slug>/collection.json
rg -n -F "responseType" public/docs/api/<module-slug>/index.html public/docs/api/<module-slug>/openapi.yaml public/docs/api/<module-slug>/collection.json
rg -n "0[[:space:]]+boolean|1[[:space:]]+boolean|4[[:space:]]+object" public/docs/api/<module-slug> && exit 1 || true
```

Use:

```bash
bash .agents/skills/backend-spatie-data-v2/scripts/scan-api-docs-contracts.sh <module-slug> <OldTypeName> <NewTypeName>
```

before and after config/docs updates.
