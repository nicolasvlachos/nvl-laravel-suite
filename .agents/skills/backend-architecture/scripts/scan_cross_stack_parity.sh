#!/usr/bin/env bash
set -euo pipefail

# Cross-stack parity scanner for fullstack module audit.
#
# Usage:
#   .agents/skills/backend-architecture/scripts/scan_cross_stack_parity.sh ModuleName module-slug
# Example:
#   .agents/skills/backend-architecture/scripts/scan_cross_stack_parity.sh Protocols protocols

MODULE_NAME=${1:-}
MODULE_SLUG=${2:-}

if [[ -z "$MODULE_NAME" || -z "$MODULE_SLUG" ]]; then
  echo "Usage: .agents/skills/backend-architecture/scripts/scan_cross_stack_parity.sh ModuleName module-slug" >&2
  echo "  ModuleName: PascalCase (e.g., Protocols)" >&2
  echo "  module-slug: lowercase (e.g., protocols)" >&2
  exit 1
fi

SCRIPT_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
REPO_ROOT=$(cd "$SCRIPT_DIR/../../../.." && pwd)
MODULE="$REPO_ROOT/Modules/$MODULE_NAME"
FRONTEND="$REPO_ROOT/resources/js/pages/admin/$MODULE_SLUG"
GENERATED_TYPES="$REPO_ROOT/resources/js/types/generated.types.d.ts"
TRANSLATION_TYPES="$REPO_ROOT/resources/js/types/translations/${MODULE_SLUG}.translations.d.ts"

collect_backend_routes() {
  find "$MODULE/routes" -name "*.php" 2>/dev/null | sort | while read -r route_file; do
    route_prefix=""

    while IFS= read -r route_name; do
      if [[ "$route_name" == *"." ]]; then
        route_prefix="$route_name"
        continue
      fi

      if [[ -n "$route_prefix" && "$route_name" != "$route_prefix"* ]]; then
        echo "${route_prefix}${route_name}"
      elif [[ "$route_name" == *.* ]]; then
        echo "$route_name"
      else
        echo "${route_prefix}${route_name}"
      fi
    done < <(rg --no-filename -o -r '$1' -- "->name\(['\"]([^'\"]+)" "$route_file" 2>/dev/null || true)
  done | sort -u
}

if [[ ! -d "$MODULE" ]]; then
  echo "Backend module not found: $MODULE" >&2
  exit 1
fi

if [[ ! -d "$FRONTEND" ]]; then
  echo "Frontend module not found: $FRONTEND" >&2
  exit 1
fi

echo "╔══════════════════════════════════════════════════════════════╗"
echo "║  Cross-Stack Parity Audit: $MODULE_NAME / $MODULE_SLUG"
echo "╚══════════════════════════════════════════════════════════════╝"
echo

# ============================================================================
# §C.1 — DTO → TypeScript Type Alignment
# ============================================================================
echo "━━━ §C.1 DTO → TypeScript Type Alignment ━━━"
echo

echo "→ DTOs with #[TypeScript] decorator:"
TS_DTOS=$(rg "#\[TypeScript\]" "$MODULE/app/Data/" -l 2>/dev/null || true)
if [[ -n "$TS_DTOS" ]]; then
  echo "$TS_DTOS" | sed 's/^/  /'
  DTO_COUNT=$(echo "$TS_DTOS" | wc -l | tr -d ' ')
  echo "  Total: $DTO_COUNT DTOs"
else
  echo "  (none found)"
fi
echo

echo "→ Generated TypeScript interfaces for $MODULE_NAME:"
if [[ -f "$GENERATED_TYPES" ]]; then
  NAMESPACE_COUNT=$(rg "namespace.*$MODULE_NAME" "$GENERATED_TYPES" -c 2>/dev/null || echo "0")
  echo "  Namespace references: $NAMESPACE_COUNT"
  rg "namespace.*$MODULE_NAME" "$GENERATED_TYPES" -n 2>/dev/null | head -10 | sed 's/^/  /' || true
else
  echo "  ✗ generated.types.d.ts not found"
fi
echo

echo "→ DTOs with #[TypeScript] but NO matching TypeScript interface:"
if [[ -n "$TS_DTOS" && -f "$GENERATED_TYPES" ]]; then
  while IFS= read -r dto_file; do
    # Extract class name
    CLASS_NAME=$(rg "class (\w+)" "$dto_file" -o -r '$1' 2>/dev/null | head -1)
    if [[ -n "$CLASS_NAME" ]]; then
      if ! rg "\b$CLASS_NAME\b" "$GENERATED_TYPES" -q 2>/dev/null; then
        echo "  ✗ $CLASS_NAME ($dto_file)"
      fi
    fi
  done <<< "$TS_DTOS"
fi
echo

# ============================================================================
# §C.2 — Controller Data → Page Props Alignment
# ============================================================================
echo "━━━ §C.2 Controller Data → Page Props Alignment ━━━"
echo

echo "→ Inertia render calls in backend controllers:"
rg "Inertia::render\(|->render\(" "$MODULE/app/Http/Controllers/" -n 2>/dev/null | head -20 | sed 's/^/  /' || echo "  (none found)"
echo

echo "→ Page files in frontend:"
find "$FRONTEND" -name "*.page.tsx" 2>/dev/null | sort | sed 's/^/  /'
echo

# ============================================================================
# §C.3 — Translation Key Cross-Stack Parity
# ============================================================================
echo "━━━ §C.3 Translation Key Cross-Stack Parity ━━━"
echo

echo "→ Frontend t() key count:"
FE_KEY_COUNT=$(rg "t\(['\"]" "$FRONTEND" --glob "*.{ts,tsx}" -o 2>/dev/null | wc -l | tr -d ' ')
echo "  $FE_KEY_COUNT t() calls found"
echo

echo "→ Unique translation keys used in frontend:"
UNIQUE_KEYS=$(rg "t\(['\"]([a-zA-Z0-9_\.\-]+)['\"]" "$FRONTEND" --glob "*.{ts,tsx}" -o -r '$1' 2>/dev/null | sort -u)
UNIQUE_COUNT=$(echo "$UNIQUE_KEYS" | grep -c "." 2>/dev/null || echo "0")
echo "  $UNIQUE_COUNT unique keys"
echo

echo "→ EN translation files:"
EN_FILES=$(find "$MODULE/lang/en" -name "*.php" 2>/dev/null | sort)
EN_COUNT=$(echo "$EN_FILES" | grep -c "." 2>/dev/null || echo "0")
echo "  $EN_COUNT files"

echo "→ BG translation files:"
BG_FILES=$(find "$MODULE/lang/bg" -name "*.php" 2>/dev/null | sort)
BG_COUNT=$(echo "$BG_FILES" | grep -c "." 2>/dev/null || echo "0")
echo "  $BG_COUNT files"

if [[ "$EN_COUNT" != "$BG_COUNT" ]]; then
  echo "  ✗ FILE COUNT MISMATCH: EN=$EN_COUNT, BG=$BG_COUNT"
else
  echo "  ✓ File count matches"
fi
echo

echo "→ TypeScript translation type file:"
if [[ -f "$TRANSLATION_TYPES" ]]; then
  TS_TYPE_LINES=$(wc -l < "$TRANSLATION_TYPES" | tr -d ' ')
  echo "  ✓ Found ($TS_TYPE_LINES lines)"
else
  echo "  ✗ NOT FOUND: $TRANSLATION_TYPES"
fi
echo

# Run the dedicated translation audit script if available
TRANS_SCRIPT="$REPO_ROOT/.agents/skills/backend-translations-i18n/scripts/audit_frontend_translations.sh"
if [[ -x "$TRANS_SCRIPT" ]]; then
  echo "→ Running cross-stack translation key audit..."
  bash "$TRANS_SCRIPT" "$MODULE_NAME" "$MODULE_SLUG" "$FRONTEND" 2>&1 | sed 's/^/  /' || true
  echo
fi

# ============================================================================
# §C.4 — Route Parity
# ============================================================================
echo "━━━ §C.4 Route Parity ━━━"
echo

echo "→ Backend named routes:"
BACKEND_ROUTES=$(collect_backend_routes)
if [[ -n "$BACKEND_ROUTES" ]]; then
  echo "$BACKEND_ROUTES" | sed 's/^/  /'
else
  echo "  (none found)"
fi
echo

echo "→ Frontend route() calls:"
rg --no-filename "route\(['\"]([^'\"]+)" "$FRONTEND" --glob "*.{ts,tsx}" -o -r '$1' 2>/dev/null | sort -u | sed 's/^/  /' || echo "  (none found)"
echo

# Check for frontend routes not in backend
echo "→ Frontend routes NOT found in backend routes:"
FRONTEND_ROUTES=$(rg --no-filename "route\(['\"]([^'\"]+)" "$FRONTEND" --glob "*.{ts,tsx}" -o -r '$1' 2>/dev/null | sort -u || true)

if [[ -n "$FRONTEND_ROUTES" ]]; then
  while IFS= read -r fe_route; do
    if [[ -n "$fe_route" ]] && ! echo "$BACKEND_ROUTES" | grep -qF "$fe_route"; then
      echo "  ✗ $fe_route (used in frontend, not found in module routes)"
    fi
  done <<< "$FRONTEND_ROUTES"
fi
echo

# ============================================================================
# §C.5 — Money Field Parity
# ============================================================================
echo "━━━ §C.5 Money Field Parity ━━━"
echo

echo "→ Backend MoneyCast usage:"
rg "MoneyCast" "$MODULE/app/Models/" -n 2>/dev/null | sed 's/^/  /' || echo "  (none)"

echo "→ Backend MoneyData in DTOs:"
rg "MoneyData" "$MODULE/app/Data/" -n 2>/dev/null | head -20 | sed 's/^/  /' || echo "  (none)"

echo "→ Frontend MoneyDisplay usage:"
rg "MoneyDisplay" "$FRONTEND" -n --glob "*.tsx" 2>/dev/null | head -20 | sed 's/^/  /' || echo "  (none)"

echo "→ Frontend MoneyInput usage:"
rg "MoneyInput" "$FRONTEND" -n --glob "*.tsx" 2>/dev/null | head -10 | sed 's/^/  /' || echo "  (none)"

echo "→ Frontend raw money formatting (violation):"
rg "toLocaleString|toFixed|formatCurrency" "$FRONTEND" -n --glob "*.{ts,tsx}" 2>/dev/null | head -10 | sed 's/^/  /' || echo "  (none — good)"
echo

# ============================================================================
# §C.6 — Display State Parity
# ============================================================================
echo "━━━ §C.6 Display State Parity ━━━"
echo

echo "→ Backend Display DTOs:"
find "$MODULE/app/Data/Display" -name "*.php" 2>/dev/null | sort | sed 's/^/  /' || echo "  (no Display/ directory)"

echo "→ Backend StateResolver:"
find "$MODULE/app/Services" -name "*StateResolver*" 2>/dev/null | sort | sed 's/^/  /' || echo "  (none found)"

echo "→ Frontend data.states consumption:"
rg "data\.states" "$FRONTEND" -n --glob "*.{ts,tsx}" 2>/dev/null | head -20 | sed 's/^/  /' || echo "  (not using Display State pattern)"

echo "→ Frontend states.actions consumption:"
rg "states\.actions" "$FRONTEND" -n --glob "*.{ts,tsx}" 2>/dev/null | head -10 | sed 's/^/  /' || echo "  (not using action visibility from Display State)"
echo

echo "╔══════════════════════════════════════════════════════════════╗"
echo "║  Cross-stack parity scan complete.                          ║"
echo "║  Run the full audit with:                                   ║"
echo "║    .agents/skills/backend-architecture/scripts/                     ║"
echo "║      run_module_audit.sh $MODULE_NAME $MODULE_SLUG          ║"
echo "╚══════════════════════════════════════════════════════════════╝"
