# Naming And Shape Guide

## Table Of Contents

- Naming Rules
- Suggested Replacements
- Shape Rules
- Examples
- Legacy Strategy

## Naming Rules

Drop the universal `Data` suffix for new v2 classes. Use names that describe the boundary role.

Do not replace `Data` with `DTO`; both describe implementation instead of business meaning.

Use semantic suffixes when they clarify the contract:

| Role | Preferred Suffix | Example |
| --- | --- | --- |
| Create/update/action input | `Payload` | `CreateBookingPayload`, `ConfirmBookingPayload` |
| Query/filter input | `Query`, `Filters` | `BookingIndexQuery`, `VendorSuggestionQuery` |
| Index row | `Item`, `Row` | `BookingListItem`, `VoucherTableRow` |
| Page root | `Page` | `BookingShowPage`, `ExternalBookingsAdminPage` |
| Display state | Domain name or `State` | `BookingWorkflowEligibility`, `BookingVoucherState` |
| Stored JSON | Domain name | `BookingMetadata`, `BookingMetadataForm` |
| External normalized object | `Snapshot`, `Result`, `Payload`, `State` | `ExternalBookingServiceSnapshot`, `ExternalBookingResult` |

## Suggested Replacements

Bookings examples:

| Current | Future |
| --- | --- |
| `BookingData` | Deprecated; split into focused classes |
| `CreateBookingData` | `CreateBookingPayload` |
| `UpdateBookingData` | `UpdateBookingPayload` |
| `ConfirmBookingData` | `ConfirmBookingPayload` |
| `BookingListData` | `BookingListItem` |
| `BookingShowPageData` | `BookingShowPage` |
| `BookingShowVoucherData` | `BookingShowVoucher` |
| `BookingWorkflowEligibilityData` | `BookingWorkflowEligibility` |
| `BookingMetadataData` | `BookingMetadata` |
| `ExternalBookingServiceData` | `ExternalBookingServiceSnapshot` |

## Shape Rules

- One class has one boundary role.
- Mutation payloads can be optional/nullable.
- Display payloads reflect domain truth.
- Page roots compose display objects; they should not contain validation rules.
- Stored JSON classes should include compact storage methods only when they own a persisted JSON shape.
- Provider classes should clearly state whether they are raw provider payloads or normalized app-owned snapshots.

## Examples

### Mutation payload

```php
final class UpdateBookingPayload extends Data
{
    use DataTransform;

    public function __construct(
        public readonly string|Optional|null $contactName = null,
        public readonly string|Optional|null $voucherId = null,
        public readonly Carbon|Optional|null $requestedPrimaryDate = null,
    ) {
    }

    public static function rules(): array
    {
        return [
            'contactName' => ['nullable', 'string', 'max:255'],
            'voucherId' => ['nullable', 'uuid', Rule::exists(VouchersTables::VOUCHERS, 'id')],
            'requestedPrimaryDate' => ['nullable', 'date'],
        ];
    }
}
```

### Display page

```php
final class BookingShowPage extends Data
{
    use DataTransform;

    public function __construct(
        public readonly BookingShow $booking,
        public readonly BookingShowVoucher $voucher,
        public readonly BookingShowStates $states,
        public readonly array $requestUpdates,
    ) {
    }
}
```

### Explicit exceptional state

```php
final class BookingVoucherState extends Data
{
    use DataTransform;

    public function __construct(
        public readonly string $status,
        public readonly ?BookingShowVoucher $voucher,
        public readonly ?string $reason,
    ) {
    }
}
```

Use this when the UI supports `attached | missing | unavailable`, not when the field is always required by the domain.

## Legacy Strategy

- Do not rename every `*Data` class in one sweep.
- New classes use v2 names immediately when explicitly adopting v2 for that surface.
- Migrate one module and one boundary family at a time.
- Keep class aliases only as temporary compatibility bridges when needed.
- Regenerate TypeScript after each rename because namespace names and frontend imports change.
