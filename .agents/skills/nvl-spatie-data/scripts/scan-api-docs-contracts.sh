#!/usr/bin/env bash
set -euo pipefail

MODULE_SLUG="${1:-}"
OLD_TYPE="${2:-}"
NEW_TYPE="${3:-}"

if [[ -z "$MODULE_SLUG" ]]; then
  echo "Usage: $0 <module-slug> [old-type-name] [new-type-name]" >&2
  echo "Example: $0 bookings BookingShowPageData BookingShowPage" >&2
  exit 1
fi

DOCS_DIR="public/docs/api/${MODULE_SLUG}"

echo "API docs contract scan"
echo "Module slug: $MODULE_SLUG"
echo "Docs directory: $DOCS_DIR"
echo

echo "config/api-docs.php module/type references"
echo "------------------------------------------"
rg -n -F "'${MODULE_SLUG}'" config/api-docs.php || true
rg -n "typescript_scope'\\s*=>\\s*'${MODULE_SLUG}'|typescript_file'\\s*=>\\s*'resources/js/types/generated/${MODULE_SLUG}\\.d\\.ts'" config/api-docs.php || true

if [[ -n "$OLD_TYPE" ]]; then
  echo
  echo "Old type references in API docs config/artifacts: $OLD_TYPE"
  echo "-----------------------------------------------------------"
  rg -n -F "$OLD_TYPE" config/api-docs.php "$DOCS_DIR" 2>/dev/null || true
fi

if [[ -n "$NEW_TYPE" ]]; then
  echo
  echo "New type references in API docs config/artifacts: $NEW_TYPE"
  echo "-----------------------------------------------------------"
  rg -n -F "$NEW_TYPE" config/api-docs.php "$DOCS_DIR" 2>/dev/null || true
fi

echo
echo "Generated docs artifacts"
echo "------------------------"
if [[ -d "$DOCS_DIR" ]]; then
  find "$DOCS_DIR" -maxdepth 1 -type f \( -name 'index.html' -o -name 'openapi.yaml' -o -name 'collection.json' \) | sort
else
  echo "Missing docs directory: $DOCS_DIR"
fi

echo
echo "Canonical response field smoke scan"
echo "-----------------------------------"
if [[ -d "$DOCS_DIR" ]]; then
  rg -n -F "success" "$DOCS_DIR"/index.html "$DOCS_DIR"/openapi.yaml "$DOCS_DIR"/collection.json 2>/dev/null || true
  rg -n -F "responseType" "$DOCS_DIR"/index.html "$DOCS_DIR"/openapi.yaml "$DOCS_DIR"/collection.json 2>/dev/null || true
  if rg -n "0[[:space:]]+boolean|1[[:space:]]+boolean|4[[:space:]]+object" "$DOCS_DIR" 2>/dev/null; then
    echo "WARNING: numeric response field output detected"
  fi
fi

echo
echo "Suggested checks"
echo "----------------"
echo "php artisan route:list --path=api/v1/${MODULE_SLUG} --except-vendor -vv"
echo "php artisan api-docs:generate ${MODULE_SLUG} --force"
echo "php artisan test --compact --filter=ApiDocs"
