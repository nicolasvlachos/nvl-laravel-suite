#!/usr/bin/env bash
set -euo pipefail

# Frontend module violation scanner.
#
# Usage:
#   .agents/skills/backend-architecture/scripts/scan_frontend_module.sh module-slug
# Example:
#   .agents/skills/backend-architecture/scripts/scan_frontend_module.sh protocols

MODULE_SLUG=${1:-}

if [[ -z "$MODULE_SLUG" ]]; then
  echo "Usage: .agents/skills/backend-architecture/scripts/scan_frontend_module.sh module-slug" >&2
  echo "  module-slug: lowercase module name (e.g., protocols, bookings, vendors)" >&2
  exit 1
fi

SCRIPT_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
REPO_ROOT=$(cd "$SCRIPT_DIR/../../../.." && pwd)
TARGET="$REPO_ROOT/resources/js/pages/admin/$MODULE_SLUG"

if [[ ! -d "$TARGET" ]]; then
  echo "Module directory not found: $TARGET" >&2
  exit 1
fi

echo "╔══════════════════════════════════════════════════════════════╗"
echo "║  Frontend Module Audit: $MODULE_SLUG"
echo "╚══════════════════════════════════════════════════════════════╝"
echo

# Helper: count matches
count_matches() {
  local count
  count=$(echo "$1" | grep -c "." 2>/dev/null || echo "0")
  echo "$count"
}

# ============================================================================
# §1 — Raw HTML Elements (CRITICAL)
# ============================================================================
echo "━━━ §1 Raw HTML Elements (CRITICAL) ━━━"
echo

echo "→ Typography violations:"
for tag in 'h1' 'h2' 'h3' 'h4' 'h5' 'h6'; do
  rg "<${tag}[\s>]" "$TARGET" -n --glob "*.tsx" 2>/dev/null | head -5 && echo || true
done
rg "<p[\s>]" "$TARGET" -n --glob "*.tsx" 2>/dev/null | head -10 || true
rg "<span[\s>]" "$TARGET" -n --glob "*.tsx" 2>/dev/null | head -10 || true
rg "<a[\s>]" "$TARGET" -n --glob "*.tsx" 2>/dev/null | head -10 || true
rg "<label[\s>]" "$TARGET" -n --glob "*.tsx" 2>/dev/null | head -10 || true
echo

echo "→ Table violations:"
rg "<table[\s>]|<thead[\s>]|<tbody[\s>]|<th[\s>]|<td[\s>]|<tr[\s>]" "$TARGET" -n --glob "*.tsx" 2>/dev/null | head -20 || echo "  (none found)"
echo

# ============================================================================
# §2 — Hardcoded Color Classes (HIGH)
# ============================================================================
echo "━━━ §2 Hardcoded Color Classes (HIGH) ━━━"
echo

for pattern in 'slate-' 'gray-' 'zinc-' 'neutral-'; do
  MATCHES=$(rg "$pattern" "$TARGET" -n --glob "*.tsx" 2>/dev/null | head -10 || true)
  if [[ -n "$MATCHES" ]]; then
    echo "→ $pattern:"
    echo "$MATCHES" | sed 's/^/  /'
    echo
  fi
done

rg "bg-white\b" "$TARGET" -n --glob "*.tsx" 2>/dev/null | head -5 | sed 's/^/→ bg-white: /' || true
rg "text-black\b" "$TARGET" -n --glob "*.tsx" 2>/dev/null | head -5 | sed 's/^/→ text-black: /' || true
echo

# ============================================================================
# §3 — Hardcoded Strings (HIGH)
# ============================================================================
echo "━━━ §3 Hardcoded Strings (HIGH) ━━━"
echo

echo "→ English fallback strings (?? 'text'):"
rg "\?\? '" "$TARGET" -n --glob "*.{ts,tsx}" 2>/dev/null | head -20 || echo "  (none found)"
echo

echo "→ OR fallback strings (|| 'text'):"
rg "\|\| '" "$TARGET" -n --glob "*.{ts,tsx}" 2>/dev/null | rg -v "undefined|null|''" | head -20 || echo "  (none found)"
echo

echo "→ Hand-rolled translation functions:"
rg "function getT|function translate" "$TARGET" -n --glob "*.{ts,tsx}" 2>/dev/null || echo "  (none found)"
echo

# ============================================================================
# §4 — Invalid Component Variants (CRITICAL)
# ============================================================================
echo "━━━ §4 Invalid Component Variants (CRITICAL) ━━━"
echo

echo "→ variant=\"default\" (crashes Button at runtime):"
rg 'variant="default"' "$TARGET" -n --glob "*.tsx" 2>/dev/null || echo "  (none found)"
echo

echo "→ variant=\"destructive\" (crashes Button at runtime):"
rg 'variant="destructive"' "$TARGET" -n --glob "*.tsx" 2>/dev/null || echo "  (none found)"
echo

echo "→ variant=\"outline\" (invalid on Badge):"
rg 'variant="outline"' "$TARGET" -n --glob "*.tsx" 2>/dev/null || echo "  (none found)"
echo

# ============================================================================
# §5 — Wrong Import Paths (MODERATE)
# ============================================================================
echo "━━━ §5 Wrong Import Paths (MODERATE) ━━━"
echo

echo "→ Legacy card imports:"
rg "from '@/components/composed/cards'|from '@/components/ui/composed/cards'" "$TARGET" -n --glob "*.{ts,tsx}" 2>/dev/null || echo "  (none found)"

echo "→ Legacy typography imports:"
rg "from '@/components/ui/typography'" "$TARGET" -n --glob "*.{ts,tsx}" 2>/dev/null || echo "  (none found)"

echo "→ Legacy form imports:"
rg "from '@/components/ui/forms'|from '@/components/ui/composed/forms'" "$TARGET" -n --glob "*.{ts,tsx}" 2>/dev/null || echo "  (none found)"

echo "→ Legacy display imports:"
rg "from '@/components/ui/display'" "$TARGET" -n --glob "*.{ts,tsx}" 2>/dev/null || echo "  (none found)"

echo "→ AppMainLayout (should be AdminLayout):"
rg "AppMainLayout" "$TARGET" -n --glob "*.{ts,tsx}" 2>/dev/null || echo "  (none found)"

echo "→ Stale layout/structure import:"
rg "from '@/components/layout/structure'" "$TARGET" -n --glob "*.{ts,tsx}" 2>/dev/null || echo "  (none found)"
echo

# ============================================================================
# §6 — CoreProvider Double-Wrapping (MODERATE)
# ============================================================================
echo "━━━ §6 CoreProvider Double-Wrapping (MODERATE) ━━━"
echo

rg "<CoreProvider>" "$TARGET" -n --glob "*.tsx" 2>/dev/null || echo "  (none found — AdminLayout provides CoreProvider)"
echo

# ============================================================================
# §7 — Translation Hook Violations (HIGH)
# ============================================================================
echo "━━━ §7 Translation Hook Violations (HIGH) ━━━"
echo

echo "→ useTranslations ANYWHERE (must be useServiceContainer().i18n — zero exceptions):"
rg "useTranslations" "$TARGET" -n --glob "*.{ts,tsx}" 2>/dev/null || echo "  (none found)"
echo

# ============================================================================
# §8 — Form Pattern Violations (MODERATE)
# ============================================================================
echo "━━━ §8 Form Pattern Violations (MODERATE) ━━━"
echo

echo "→ Raw <Label> in forms (should use ControlledFormField):"
rg "<Label" "$TARGET/forms/" -n --glob "*.tsx" 2>/dev/null || echo "  (none found)"
echo

# ============================================================================
# §9 — Action Pattern Violations (MODERATE)
# ============================================================================
echo "━━━ §9 Action Pattern Violations (MODERATE) ━━━"
echo

echo "→ Legacy useInertiaOperation:"
rg "useInertiaOperation" "$TARGET" -n --glob "*.{ts,tsx}" 2>/dev/null || echo "  (none found)"

echo "→ Legacy defineActions:"
rg "defineActions" "$TARGET" -n --glob "*.{ts,tsx}" 2>/dev/null || echo "  (none found)"

echo "→ Legacy ActionRecord:"
rg "ActionRecord" "$TARGET" -n --glob "*.{ts,tsx}" 2>/dev/null || echo "  (none found)"

echo "→ Legacy action-context:"
rg "action-context" "$TARGET" -n --glob "*.{ts,tsx}" 2>/dev/null || echo "  (none found)"
echo

# ============================================================================
# §10 — Card Surface Violations (LOW)
# ============================================================================
echo "━━━ §10 Card Surface Violations (LOW) ━━━"
echo

echo "→ Hand-rolled card divs:"
rg "rounded.*border.*p-" "$TARGET" -n --glob "*.tsx" 2>/dev/null | head -10 || echo "  (none found)"

echo "→ Inline badge in SmartCard title:"
rg 'title=\{<span' "$TARGET" -n --glob "*.tsx" 2>/dev/null || echo "  (none found)"
echo

# ============================================================================
# Summary
# ============================================================================
echo "╔══════════════════════════════════════════════════════════════╗"
echo "║  Scan complete. Review findings above.                      ║"
echo "║  Run translation key audit separately with:                 ║"
echo "║  .agents/skills/backend-translations-i18n/scripts/          ║"
echo "║    audit_frontend_translations.sh ModuleName $MODULE_SLUG   ║"
echo "╚══════════════════════════════════════════════════════════════╝"
