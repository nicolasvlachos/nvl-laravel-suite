# Migration Playbook

## Table Of Contents

- Ground Rules
- Discovery
- Pilot Order
- Rename Strategy
- Contract Cleanup Strategy
- Frontend Reference Update
- API Layer And API Docs Update
- Validation Commands
- Rollback Strategy

## Ground Rules

- Do not change active doctrine by accident.
- Migrate one module at a time.
- Prefer one boundary family at a time: mutations, then display/page payloads, then stored JSON/provider payloads.
- Preserve runtime behavior before improving naming.
- Regenerate TypeScript during every module migration, even when the changed class seems backend-only.
- Check frontend consumers after every generated contract change.
- Update API docs response contracts when PHP class names, TypeScript type names, generated files, examples, or endpoint payload shapes change.

## Discovery

Run:

```bash
bash .agents/skills/backend-spatie-data-v2/scripts/scan-spatie-data-patterns.sh Modules/Bookings
bash .agents/skills/backend-spatie-data-v2/scripts/scan-frontend-type-references.sh bookings BookingShowPageData BookingShowPage
bash .agents/skills/backend-spatie-data-v2/scripts/scan-api-docs-contracts.sh bookings BookingShowPageData BookingShowPage
```

Review:

- Data class count.
- `LiteralTypeScriptType` count.
- `TypeScriptType` count.
- nullable `TypeScriptType` candidates.
- `DataCollection` and `DataCollectionOf` usage.
- `WithDeprecatedCollectionMethod` usage.
- `defaultWrap()` usage.
- raw array properties.
- frontend aliases/imports/test factories using old generated type names.
- API docs contracts and generated docs artifacts using old PHP or TypeScript names.

## Pilot Order

For Bookings, start with correctness before style:

1. Fix PHP vs generated TypeScript nullability mismatches.
2. Remove redundant primitive `LiteralTypeScriptType` attributes.
3. Remove redundant `TypeScriptType` attributes where native PHP object/enum types infer correctly.
4. Replace unnecessary `DataCollection` with typed arrays only when output is verified.
5. Rename focused display/page classes.
6. Rename mutation payloads.
7. Retire broad legacy `BookingData` last.

## Rename Strategy

Preferred incremental sequence:

1. Create the new semantic class.
2. Move or copy behavior into the new class.
3. Update one backend caller family.
4. Run `php -d memory_limit=1G artisan typescript:transform`.
5. Update frontend references to generated namespace/type changes.
6. Update API docs response contracts and examples if API routes expose the DTO.
7. Run type checks and focused backend/API docs checks.
8. Remove old class only when no callers, generated references, API docs contracts, or docs artifacts remain.

Avoid giant renames that touch backend, generated TypeScript, frontend imports, tests, and docs in one change.

## Contract Cleanup Strategy

### Nullable display contract

If a display field is nullable only because update payloads can send null, split the contracts:

- Mutation payload keeps `Type|Optional|null`.
- Display payload uses required `Type`.

If a display field is nullable because legacy/corrupt data can exist, model a state:

```php
public readonly BookingVoucherState $voucher;
```

not:

```php
public readonly ?BookingShowVoucher $voucher;
```

### TypeScript attributes

Remove in this order:

1. `#[LiteralTypeScriptType('string')]`
2. `#[LiteralTypeScriptType('boolean')]`
3. `#[LiteralTypeScriptType('number')]`
4. `#[LiteralTypeScriptType('string | null')]` where PHP says `?string`
5. `#[TypeScriptType(SomeClass::class)]` where PHP says `SomeClass` or `?SomeClass`

Run generation after each batch.

### Records

Replace `Record<string, unknown>` or `Record<string, mixed-like>` with named DTOs when frontend uses known keys.

Keep records only for provider-owned or deliberately dynamic payloads.

## Frontend Reference Update

TypeScript generation is mandatory for every module migration:

```bash
php -d memory_limit=1G artisan typescript:transform
```

After generation:

```bash
git diff -- resources/js/types/generated
bash .agents/skills/backend-spatie-data-v2/scripts/scan-frontend-type-references.sh <module-scope> <OldTypeName> <NewTypeName>
npm run types
```

Update:

- Generated type aliases in `resources/js/**/*.ts` and `resources/js/**/*.tsx`.
- Module `*.types.ts` wrapper files.
- Table/form/hook prop types.
- Frontend tests and object factories.
- Any direct namespace references such as `Modules.Bookings.Data.Display.BookingShowPageData`.

Do not use `as any` to bypass generated contract breakage. Fix the backend contract or frontend consumer.

## API Layer And API Docs Update

Read `references/frontend-and-api-docs.md` and use the active `api-docs-generation` skill when a migrated DTO is exposed through API routes or docs.

Before changing API docs config:

```bash
php artisan route:list --path=api/v1/<module-scope> --except-vendor -vv
bash .agents/skills/backend-spatie-data-v2/scripts/scan-api-docs-contracts.sh <module-slug> <OldTypeName> <NewTypeName>
```

Update `config/api-docs.php` when any of these changes:

- PHP class name or namespace.
- Generated TypeScript namespace/type.
- `typescript_file`.
- `typescript_scope`.
- Example payload shape.
- Response type: `simple`, `paginated`, or `error`.
- Named DTO replaces an array shape or vice versa.

Then regenerate module docs:

```bash
php artisan api-docs:generate <module-slug> --force
```

Verify:

- `public/docs/api/<module-slug>/index.html` exists.
- `public/docs/api/<module-slug>/openapi.yaml` exists.
- `public/docs/api/<module-slug>/collection.json` exists.
- Generated docs include the new TypeScript type and `/api/v1/app/types/<scope>` link.
- Generated response fields include canonical root fields and not numeric field names.

## Validation Commands

Backend:

```bash
php -d memory_limit=1G artisan typescript:transform
vendor/bin/pint --dirty --format agent
vendor/bin/phpstan analyse Modules/<Module> -c phpstan.neon --no-progress
php artisan test --compact Modules/<Module>/tests/Feature
```

Frontend:

```bash
npm run types
```

Run scoped frontend lint/build checks when generated contracts touch active React consumers.

API docs when API contracts changed:

```bash
php artisan api-docs:generate <module-slug> --force
php artisan test --compact --filter=ApiDocs
```

Review:

```bash
git diff -- resources/js/types/generated
rg -n "Modules\\.<Module>|<RenamedClass>|OldClassData" resources/js Modules/<Module>/app
bash .agents/skills/backend-spatie-data-v2/scripts/scan-frontend-type-references.sh <module-scope> <OldTypeName> <NewTypeName>
bash .agents/skills/backend-spatie-data-v2/scripts/scan-api-docs-contracts.sh <module-slug> <OldTypeName> <NewTypeName>
```

## Rollback Strategy

If generated TypeScript changes are broader than expected:

1. Stop.
2. Inspect only generated declaration diff first.
3. Revert the smallest DTO batch, not the whole module.
4. Prefer explicit temporary attributes over broad bridge changes.
5. Document the blocked transformer behavior before continuing.
