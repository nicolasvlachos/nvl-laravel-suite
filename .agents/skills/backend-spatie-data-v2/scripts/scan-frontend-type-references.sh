#!/usr/bin/env bash
set -euo pipefail

SCOPE="${1:-}"
OLD_TYPE="${2:-}"
NEW_TYPE="${3:-}"

if [[ -z "$SCOPE" ]]; then
  echo "Usage: $0 <typescript-scope> [old-type-name] [new-type-name]" >&2
  echo "Example: $0 bookings BookingShowPageData BookingShowPage" >&2
  exit 1
fi

GENERATED_FILE="resources/js/types/generated/${SCOPE}.d.ts"
MODULE_NAMESPACE=""

if [[ -f "$GENERATED_FILE" ]]; then
  MODULE_NAMESPACE="$(
    rg -m 1 '^namespace [A-Z][A-Za-z0-9_]+ \{' "$GENERATED_FILE" \
      | sed -E 's/^namespace ([A-Z][A-Za-z0-9_]+) \{/\1/' \
      || true
  )"
fi

echo "Frontend type reference scan"
echo "Scope: $SCOPE"
echo "Generated file: $GENERATED_FILE"
echo "Module namespace: ${MODULE_NAMESPACE:-unknown}"
echo

if [[ -f "$GENERATED_FILE" ]]; then
  echo "Generated declarations containing scope/type keywords"
  echo "----------------------------------------------------"
  if [[ -n "$OLD_TYPE" || -n "$NEW_TYPE" ]]; then
    rg -n -F "${OLD_TYPE:-__never__}" "$GENERATED_FILE" || true
    rg -n -F "${NEW_TYPE:-__never__}" "$GENERATED_FILE" || true
  else
    sed -n '1,80p' "$GENERATED_FILE"
  fi
else
  echo "Missing generated file: $GENERATED_FILE"
fi

echo
echo "User-authored frontend references"
echo "---------------------------------"
if [[ -n "$MODULE_NAMESPACE" ]]; then
  rg -n "Modules\\.${MODULE_NAMESPACE}\\.|/api/v1/app/types/${SCOPE}|types/${SCOPE}|generated/${SCOPE}\\.d\\.ts" resources/js \
    --glob '*.ts' \
    --glob '*.tsx' \
    --glob '!resources/js/types/generated/*.d.ts' \
    --glob '!resources/js/types/generated.types.d.ts' \
    || true
else
  rg -n "/api/v1/app/types/${SCOPE}|types/${SCOPE}|generated/${SCOPE}\\.d\\.ts" resources/js \
    --glob '*.ts' \
    --glob '*.tsx' \
    --glob '!resources/js/types/generated/*.d.ts' \
    --glob '!resources/js/types/generated.types.d.ts' \
    || true
fi

echo
echo "Generated declaration references"
echo "--------------------------------"
if [[ -n "$MODULE_NAMESPACE" ]]; then
  rg -n "Modules\\.${MODULE_NAMESPACE}\\.|generated/${SCOPE}\\.d\\.ts" resources/js/types --glob '*.d.ts' || true
else
  rg -n "generated/${SCOPE}\\.d\\.ts" resources/js/types --glob '*.d.ts' || true
fi

if [[ -n "$OLD_TYPE" ]]; then
  echo
  echo "Old type references: $OLD_TYPE"
  echo "--------------------"
  rg -n -F "$OLD_TYPE" resources/js "$GENERATED_FILE" 2>/dev/null || true
fi

if [[ -n "$NEW_TYPE" ]]; then
  echo
  echo "New type references: $NEW_TYPE"
  echo "--------------------"
  rg -n -F "$NEW_TYPE" resources/js "$GENERATED_FILE" 2>/dev/null || true
fi

echo
echo "Suggested checks"
echo "----------------"
echo "php -d memory_limit=1G artisan typescript:transform"
echo "git diff -- resources/js/types/generated"
echo "npm run types"
