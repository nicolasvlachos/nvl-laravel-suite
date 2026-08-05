#!/usr/bin/env bash
set -euo pipefail

# Backend module compliance scanner.
#
# Usage:
#   .agents/skills/backend-architecture/scripts/scan_backend_module.sh ModuleName
# Example:
#   .agents/skills/backend-architecture/scripts/scan_backend_module.sh Protocols

MODULE_NAME=${1:-}

if [[ -z "$MODULE_NAME" ]]; then
  echo "Usage: .agents/skills/backend-architecture/scripts/scan_backend_module.sh ModuleName" >&2
  exit 1
fi

SCRIPT_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
REPO_ROOT=$(cd "$SCRIPT_DIR/../../../.." && pwd)
MODULE="$REPO_ROOT/Modules/$MODULE_NAME"

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
  echo "Module not found: $MODULE" >&2
  exit 1
fi

echo "╔══════════════════════════════════════════════════════════════╗"
echo "║  Backend Module Audit: $MODULE_NAME"
echo "╚══════════════════════════════════════════════════════════════╝"
echo

# ============================================================================
# §1 — Schema & Model Compliance
# ============================================================================
echo "━━━ §1 Schema & Model Compliance ━━━"
echo

echo "→ Models missing TABLE constant:"
rg "class\s+\w+\s+extends\s+Model" "$MODULE/app/Models/" -l 2>/dev/null | while read -r file; do
  if ! rg "const\s+([A-Za-z_\\\\][A-Za-z0-9_\\\\]*\s+)?TABLE\b" "$file" -q 2>/dev/null; then
    echo "  ✗ $file"
  fi
done
echo

echo "→ Legacy \$casts property (should use casts() method or typed property):"
rg "protected \\\$casts\s*=" "$MODULE/app/Models/" -l 2>/dev/null || echo "  (none found)"
echo

echo "→ Relationships missing return types:"
RELATIONSHIP_FINDINGS=""
while IFS=: read -r file line signature; do
  block=$(tail -n +"$line" "$file" | head -n 30)

  if echo "$block" | rg "return\s+\$this->(belongsTo|hasMany|hasOne|morphMany|morphOne|morphTo|belongsToMany|hasManyThrough|hasOneThrough|morphToMany)\(" -q; then
    RELATIONSHIP_FINDINGS+="$file:$line:$signature"$'\n'
  fi
done < <(rg -P "public function\s+\w+\(\)(?!\s*:)" "$MODULE/app/Models/" -n --glob "*.php" 2>/dev/null || true)

if [[ -n "$RELATIONSHIP_FINDINGS" ]]; then
  printf "%s" "$RELATIONSHIP_FINDINGS"
else
  echo "  (none found)"
fi
echo

# ============================================================================
# §2 — DTO Compliance (Spatie Data)
# ============================================================================
echo "━━━ §2 DTO Compliance ━━━"
echo

echo "→ DTOs extending Data without #[TypeScript]:"
rg "extends\s+Data\b" "$MODULE/app/Data/" -l 2>/dev/null | while read -r file; do
  if ! rg "#\[TypeScript\]" "$file" -q 2>/dev/null; then
    echo "  ✗ $file"
  fi
done
echo

echo "→ Hardcoded validation messages (should use translation keys):"
rg "'message'\s*=>\s*'" "$MODULE/app/Data/" -n 2>/dev/null | rg -v "trans\(|__\(|:attribute" || echo "  (none found)"
echo

# ============================================================================
# §3 — Action Compliance
# ============================================================================
echo "━━━ §3 Action Compliance ━━━"
echo

echo "→ Actions without typed execute():"
rg "public function execute" "$MODULE/app/Actions/" -n 2>/dev/null | rg -v ":\s*\w+" || echo "  (all typed)"
echo

echo "→ Business logic in Controllers (should be in Actions):"
for pattern in '->save\(' '->update\(' '->create\(' '->delete\(' '::create\('; do
  matches=$(rg "$pattern" "$MODULE/app/Http/Controllers/" -n 2>/dev/null | rg -v "//|redirect\|route\(" || true)
  if [[ -n "$matches" ]]; then
    echo "  Pattern: $pattern"
    echo "$matches" | sed 's/^/    /'
  fi
done
echo

# ============================================================================
# §4 — Controller Compliance
# ============================================================================
echo "━━━ §4 Controller Compliance ━━━"
echo

echo "→ Raw DB queries in Controllers:"
rg "DB::" "$MODULE/app/Http/Controllers/" -n 2>/dev/null || echo "  (none found)"
echo

echo "→ Inertia render calls (verify page names match frontend):"
rg "Inertia::render\(|->render\(" "$MODULE/app/Http/Controllers/" -n 2>/dev/null || echo "  (none found)"
echo

# ============================================================================
# §5 — Service Provider Compliance
# ============================================================================
echo "━━━ §5 Service Provider Compliance ━━━"
echo

echo "→ Inline FQCNs (should use ::class imports):"
rg "Modules\\\\\\w+\\\\\\w+\\\\" "$MODULE/app/Providers/" -n 2>/dev/null | rg -v "use |namespace " || echo "  (none found)"
echo

# ============================================================================
# §6 — Route Compliance
# ============================================================================
echo "━━━ §6 Route Compliance ━━━"
echo

echo "→ Route names:"
ROUTE_NAMES=$(collect_backend_routes)
if [[ -n "$ROUTE_NAMES" ]]; then
  echo "$ROUTE_NAMES" | sed 's/^/  /'
else
  echo "  (no named routes)"
fi
echo

# ============================================================================
# §7 — Translation Compliance
# ============================================================================
echo "━━━ §7 Translation Compliance ━━━"
echo

TRANS_SCRIPT="$REPO_ROOT/.agents/skills/backend-translations-i18n/scripts/audit_backend_translations.sh"
if [[ -x "$TRANS_SCRIPT" ]]; then
  echo "→ Running translation audit script..."
  bash "$TRANS_SCRIPT" "$MODULE_NAME" 2>&1 | sed 's/^/  /'
else
  echo "→ EN translation files:"
  find "$MODULE/lang/en" -name "*.php" 2>/dev/null | sort | sed 's/^/  /'
  echo
  echo "→ BG translation files:"
  find "$MODULE/lang/bg" -name "*.php" 2>/dev/null | sort | sed 's/^/  /'
  echo
  echo "→ EN file count vs BG file count:"
  EN_COUNT=$(find "$MODULE/lang/en" -name "*.php" 2>/dev/null | wc -l | tr -d ' ')
  BG_COUNT=$(find "$MODULE/lang/bg" -name "*.php" 2>/dev/null | wc -l | tr -d ' ')
  echo "  EN: $EN_COUNT files, BG: $BG_COUNT files"
  if [[ "$EN_COUNT" != "$BG_COUNT" ]]; then
    echo "  ✗ PARITY MISMATCH"
  else
    echo "  ✓ File count matches"
  fi
fi
echo

# ============================================================================
# §8 — Static Analysis
# ============================================================================
echo "━━━ §8 Static Analysis ━━━"
echo

echo "→ PHPStan suppressions (should be fixed, not suppressed):"
rg "@phpstan-ignore|phpstan-ignore-next-line" "$MODULE/app/" -n 2>/dev/null || echo "  (none found)"
echo

echo "→ Mixed return types:"
rg ": mixed\b" "$MODULE/app/" -n 2>/dev/null || echo "  (none found)"
echo

# ============================================================================
# §9 — Test Coverage
# ============================================================================
echo "━━━ §9 Test Coverage ━━━"
echo

if [[ -d "$MODULE/tests" ]]; then
  TEST_COUNT=$(find "$MODULE/tests" -name "*.php" | wc -l | tr -d ' ')
  echo "→ Test files: $TEST_COUNT"
  echo
  echo "→ Tests using RefreshDatabase:"
  rg "uses\(.*RefreshDatabase" "$MODULE/tests/" -c 2>/dev/null | sed 's/^/  /' || echo "  (none)"
  echo
  echo "→ Test style (it/test):"
  IT_COUNT=$(rg "^\s*it\(" "$MODULE/tests/" -c 2>/dev/null | awk -F: '{sum+=$2} END{print sum+0}')
  TEST_FN_COUNT=$(rg "^\s*test\(" "$MODULE/tests/" -c 2>/dev/null | awk -F: '{sum+=$2} END{print sum+0}')
  echo "  it(): $IT_COUNT, test(): $TEST_FN_COUNT"
else
  echo "  ✗ No tests directory found"
fi
echo

# ============================================================================
# §10 — Ownership & Boundaries
# ============================================================================
echo "━━━ §10 Ownership & Boundaries ━━━"
echo

echo "→ Cross-module imports:"
rg "use Modules\\\\" "$MODULE/app/" -n 2>/dev/null | rg -v "use Modules\\\\$MODULE_NAME\\\\" | head -30 || echo "  (none — module is self-contained)"
echo

echo "╔══════════════════════════════════════════════════════════════╗"
echo "║  Scan complete. Review findings above.                      ║"
echo "╚══════════════════════════════════════════════════════════════╝"
