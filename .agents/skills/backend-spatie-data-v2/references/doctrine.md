# Backend Spatie Data V2 Doctrine

## Table Of Contents

- Status
- Boundary Classification
- Non-Negotiables
- Mutation Payloads
- Display And Page Payloads
- Stored JSON And Raw Payloads
- DataTransform Contract
- Validation Contract
- Collections
- Wrapping
- Dates And Casts
- Anti-Patterns

## Status

This reference is future proposal material. It is not active doctrine until explicitly adopted.

## Boundary Classification

Classify every Spatie Data class before designing or reviewing it:

| Boundary | Purpose | Naming Shape |
| --- | --- | --- |
| Mutation payload | Client/request input for create, update, action forms | `CreateBookingPayload`, `UpdateBookingPayload`, `ConfirmBookingPayload` |
| Display/page payload | Server-shaped frontend response | `BookingShowPage`, `BookingListItem`, `BookingWorkflowEligibility` |
| Stored JSON | Typed persisted JSON fragments | `BookingMetadata`, `BookingExternalReservationMetadata` |
| Provider/external payload | Normalized or raw third-party API data | `ExternalBookingCreatePayload`, `ExternalBookingServiceSnapshot` |
| Query/filter payload | Search, sort, pagination, filter inputs | `BookingFilterQuery`, `VendorSuggestionQuery` |
| Domain result | Typed result from domain operation | `BookingLifecycleResult`, `ReviewInvitationRunResult` |

## Non-Negotiables

- Extend `Spatie\LaravelData\Data`.
- Declare `strict_types=1`.
- Use constructor-promoted `public readonly` properties unless a Spatie feature requires a sole property.
- Use `App\Traits\DataTransform` for app-owned Data classes unless the class is intentionally not part of frontend/storage transformation.
- Use app-owned camelCase PHP property names for app frontend/backend contracts.
- Use `#[MapInputName(CamelCaseMapper::class)]` and `#[MapOutputName(CamelCaseMapper::class)]` for app-owned contracts.
- Use `#[TypeScript]` for frontend-facing contracts.
- Keep actions and controllers out of manual snake/camel conversion.
- Keep frontend display contracts stable and explicit.

## Mutation Payloads

Mutation payloads represent what the client sent, not what the domain guarantees after persistence.

Use:

```php
public readonly string|Optional|null $voucherId = null;
```

Meanings:

- `Optional`: field was not submitted.
- `null`: field was submitted as null.
- `string`: field was submitted with a value.

This is valid for update forms, partial actions, admin panels, and draft flows.

Do not copy mutation optionality into display/page payloads.

## Display And Page Payloads

Display payloads express business truth. If a booking cannot exist without a voucher, then the show payload should use:

```php
public readonly BookingShowVoucher $voucher;
```

Do not use:

```php
public readonly ?BookingShowVoucher $voucher;
```

unless the UI genuinely supports missing vouchers.

When missing data is a legacy or integrity state, model it explicitly:

```php
final class BookingVoucherState extends Data
{
    public function __construct(
        public readonly string $status, // attached | missing | unavailable
        public readonly ?BookingShowVoucher $voucher,
        public readonly ?string $reason,
    ) {
    }
}
```

Prefer explicit state objects over nullable fields with implicit meaning.

## Stored JSON And Raw Payloads

Use typed Data classes for stable known JSON fragments. Keep unknown keys under `extra`, `raw`, `payload`, or provider-owned leaves.

Allowed raw adapters:

- `fromMetadata()` for persisted JSON columns.
- `fromProviderPayload()` for third-party API responses.
- `fromLegacyPayload()` for temporary compatibility windows.

Do not make normal app-owned DTOs accept both snake_case and camelCase just in case.

## DataTransform Contract

Keep this repository bridge:

```php
$dto->toArray();         // frontend/API contract, camelCase
$dto->toModel();         // model/database contract, snake_case
$dto->toModelFiltered(); // write payload, removes null and Optional
```

When an update must explicitly clear selected nullable fields, override intentionally:

```php
use DataTransform {
    toModelFiltered as baseToModelFiltered;
}

public function toModelFiltered(): array
{
    $attributes = $this->baseToModelFiltered();
    $model = $this->toModel();

    foreach (['external_id', 'external_source'] as $key) {
        if (array_key_exists($key, $model) && $model[$key] === null) {
            $attributes[$key] = null;
        }
    }

    return $attributes;
}
```

Long-term improvement candidate: add a shared helper such as `toModelFilteredKeepingNulls(['external_id'])`.

## Validation Contract

Use complete `rules()` arrays as repository policy.

Important Spatie behavior: manual rules for a property replace inferred rules for that property unless `MergeValidationRules` is used.

Bad:

```php
'name' => ['max:255'],
```

Good:

```php
'name' => ['required', 'string', 'max:255'],
```

Partial update:

```php
'name' => ['nullable', 'string', 'max:255'],
```

Server-owned fields:

```php
'confirmedAt' => ['prohibited'],
'statusChangedAt' => ['prohibited'],
```

Use `Rule::exists`, `Rule::unique`, `Rule::enum`, and `Rule::in` for repository-critical rules.

Use `messages()` and `attributes()` with translated module validation keys.

## Collections

Laravel Data v4 supports ordinary arrays, Laravel collections, paginators, and Spatie Data collections.

Prefer typed arrays/Laravel collections for normal lists:

```php
/**
 * @param array<int, BookingListItem> $items
 */
public function __construct(
    public readonly array $items,
) {
}
```

Use `DataCollection` only when needing:

- Spatie include/exclude/only/except behavior.
- Root collection wrapping.
- DataCollection-specific transformations.
- Existing bridge compatibility that cannot yet be replaced.
- Paginator/cursor collection behavior where the package API requires it.

## Wrapping

Do not add `defaultWrap()` by default.

Spatie wrapping affects Data objects sent as responses/resources. It does not affect ordinary `$dto->toArray()` calls unless explicitly transformed with wrapping enabled.

Add `defaultWrap()` only when the route/controller/API contract actually wraps that Data response.

## Dates And Casts

Prefer global date config for ordinary Carbon/DateTime casts and transforms.

Use local date casts only for boundary-specific parsing:

```php
#[WithCast(DateTimeInterfaceCast::class, format: ['Y-m-d\TH:i:s.uP', DateTimeInterface::ATOM])]
#[WithTransformer(DateTimeInterfaceTransformer::class, format: DateTimeInterface::ATOM)]
public readonly Carbon $approvedDate;
```

Use local timezone parameters only when the boundary has a specific timezone rule.

## Anti-Patterns

- One class serving create, update, display, filters, and API responses.
- `Data` suffix as the only semantic role marker for new v2 classes.
- `DTO` suffix instead of domain role naming.
- Display/page DTOs nullable only because mutation payloads are nullable.
- `#[LiteralTypeScriptType('string')]`, `#[LiteralTypeScriptType('boolean')]`, or `#[LiteralTypeScriptType('number')]` on ordinary primitives.
- `#[TypeScriptType(SomeClass::class)]` on properties already typed as `SomeClass`.
- TypeScript overrides that erase PHP nullability.
- Dynamic `Record<string, unknown>` where frontend relies on known keys.
- `DataCollection` everywhere without needing DataCollection behavior.
- `defaultWrap()` without a real wrapped resource response.
