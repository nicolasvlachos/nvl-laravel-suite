#!/usr/bin/env bash
# Module Audit — Automated Scan Orchestrator
# Usage: .agents/skills/backend-architecture/scripts/run_module_audit.sh {ModuleName} {module-slug}
#
# Runs all automated scans for a full module audit:
#   - Phase 0: Context gathering (git log, file inventory)
#   - Phase 1: Strategic analysis scans (layer violations, side effects, boundaries)
#   - Phase 2: Pattern compliance (backend, frontend, cross-stack scans)
#   - Phase 3: Specialized checks (builders, service cohesion)
#   - Phase 4: Quality gates (PHPStan, tests)

set -euo pipefail

MODULE_NAME="${1:?Usage: $0 ModuleName module-slug}"
MODULE_SLUG="${2:?Usage: $0 ModuleName module-slug}"
MODULE_DIR="Modules/${MODULE_NAME}"
FRONTEND_DIR="resources/js/pages/admin/${MODULE_SLUG}"

echo "============================================"
echo "  MODULE AUDIT: ${MODULE_NAME}"
echo "  Date: $(date '+%Y-%m-%d %H:%M:%S')"
echo "============================================"
echo ""

# ─── PHASE 0: Context Gathering ──────────────────────────────────────

echo "══════════════════════════════════════════════"
echo "  PHASE 0: Context Gathering"
echo "══════════════════════════════════════════════"
echo ""

echo "── 0.1 Backend File Inventory ──"
echo ""

for dir in Models Actions Data Http/Controllers Services Builders Observers Events Providers; do
  path="${MODULE_DIR}/app/${dir}"
  if [ -d "$path" ]; then
    count=$(find "$path" -name "*.php" -type f 2>/dev/null | wc -l | tr -d ' ')
    echo "  ${dir}: ${count} files"
  fi
done

if [ -d "${MODULE_DIR}/database/migrations" ]; then
  count=$(find "${MODULE_DIR}/database/migrations" -name "*.php" -type f 2>/dev/null | wc -l | tr -d ' ')
  echo "  Migrations: ${count} files"
fi

if [ -d "${MODULE_DIR}/tests" ]; then
  count=$(find "${MODULE_DIR}/tests" -name "*.php" -type f 2>/dev/null | wc -l | tr -d ' ')
  echo "  Tests: ${count} files"
fi

echo ""
echo "── 0.2 Frontend File Inventory ──"
echo ""

if [ -d "$FRONTEND_DIR" ]; then
  tsx_count=$(find "$FRONTEND_DIR" -name "*.tsx" -type f 2>/dev/null | wc -l | tr -d ' ')
  ts_count=$(find "$FRONTEND_DIR" -name "*.ts" -type f 2>/dev/null | wc -l | tr -d ' ')
  echo "  TSX files: ${tsx_count}"
  echo "  TS files: ${ts_count}"
else
  echo "  Frontend directory not found: ${FRONTEND_DIR}"
fi

echo ""
echo "── 0.3 Git Context (last 10 commits) ──"
echo ""

git log --oneline -10 -- "${MODULE_DIR}/" "${FRONTEND_DIR}/" 2>/dev/null || echo "  No commits found"

echo ""
echo "── 0.4 Module Docs ──"
echo ""

if [ -d "${MODULE_DIR}/docs" ]; then
  find "${MODULE_DIR}/docs" -name "*.md" -type f 2>/dev/null | while read -r doc; do
    echo "  Found: ${doc}"
  done
else
  echo "  No module docs directory"
fi

echo ""

# ─── PHASE 1: Strategic Analysis Scans ───────────────────────────────

echo "══════════════════════════════════════════════"
echo "  PHASE 1: Strategic Analysis Scans"
echo "══════════════════════════════════════════════"
echo ""

echo "── 1.1 Controller Layer Violations ──"
echo "  (Controllers should NOT contain DB/model writes)"
echo ""
rg -n "DB::|->save\(\)|::create\(|->forceDelete\(" "${MODULE_DIR}/app/Http/Controllers/" 2>/dev/null || echo "  CLEAN: No controller-level data operations found"

echo ""
echo "── 1.2 Action HTTP Violations ──"
echo "  (Actions should NOT contain HTTP concerns)"
echo ""
rg -n "redirect\(|back\(\)|->with\(|flash\(|session\(\)|request\(\)" "${MODULE_DIR}/app/Actions/" 2>/dev/null || echo "  CLEAN: No HTTP concerns in actions"

echo ""
echo "── 1.3 Model Business Logic Violations ──"
echo "  (Models should NOT contain workflows/side-effects)"
echo ""
rg -n "DB::transaction|dispatch\(|event\(|Mail::|Notification::|Queue::" "${MODULE_DIR}/app/Models/" 2>/dev/null || echo "  CLEAN: No business logic in models"

echo ""
echo "── 1.4 Service HTTP Violations ──"
echo "  (Services should NOT contain HTTP concerns)"
echo ""
if [ -d "${MODULE_DIR}/app/Services" ]; then
  rg -n "request\(\)|redirect\(|flash\(|session\(\)|Inertia::" "${MODULE_DIR}/app/Services/" 2>/dev/null || echo "  CLEAN: No HTTP concerns in services"
else
  echo "  No Services directory"
fi

echo ""
echo "── 1.5 Observer Inventory ──"
echo ""
if [ -d "${MODULE_DIR}/app/Observers" ]; then
  rg -n "class.*Observer" "${MODULE_DIR}/app/Observers/" 2>/dev/null || echo "  No observers found"
else
  echo "  No Observers directory"
fi

echo ""
echo "── 1.6 Events & Side Effects ──"
echo ""
rg -n "event\(|Event::dispatch|dispatch\(new" "${MODULE_DIR}/app/" 2>/dev/null || echo "  No event dispatching found"

echo ""
echo "── 1.7 Cross-Module Imports (from this module) ──"
echo ""
rg -n "use Modules\\\\" "${MODULE_DIR}/app/" 2>/dev/null | grep -v "use Modules\\\\${MODULE_NAME}\\\\" || echo "  No cross-module imports"

echo ""
echo "── 1.8 Reverse Dependencies (who imports this module) ──"
echo ""
rg -n "use Modules\\\\${MODULE_NAME}\\\\" Modules/ --glob="!${MODULE_DIR}/*" 2>/dev/null | head -30 || echo "  No external consumers found"

echo ""
echo "── 1.9 Transaction Boundary Check ──"
echo ""
echo "  Actions with DB::transaction:"
rg -l "DB::transaction" "${MODULE_DIR}/app/Actions/" 2>/dev/null || echo "  None"
echo ""
echo "  Actions with write operations but NO transaction:"
for action in $(find "${MODULE_DIR}/app/Actions" -name "*.php" -type f 2>/dev/null); do
  has_write=$(rg -c "->save\(\)|::create\(|->update\(|->delete\(" "$action" 2>/dev/null || echo 0)
  has_tx=$(rg -c "DB::transaction" "$action" 2>/dev/null || echo 0)
  if [ "$has_write" -gt 0 ] && [ "$has_tx" -eq 0 ]; then
    echo "  WARNING: $action (${has_write} write ops, no transaction)"
  fi
done

echo ""
echo "── 1.10 Missing Builder Check ──"
echo ""
for model in $(find "${MODULE_DIR}/app/Models" -name "*.php" -type f 2>/dev/null); do
  scope_count=$(rg -c "#\[Scope\]|function scope[A-Z]" "$model" 2>/dev/null || echo 0)
  has_builder=$(rg -c "newEloquentBuilder" "$model" 2>/dev/null || echo 0)
  if [ "$scope_count" -ge 5 ] && [ "$has_builder" -eq 0 ]; then
    echo "  BUILDER CANDIDATE: $model (${scope_count} scopes, no custom builder)"
  fi
done
echo "  (Models with <5 scopes or existing builders are fine)"

echo ""
echo "── 1.11 Deep Nesting Detection ──"
echo "  (Service-calling-Service chains — should use Action as glue)"
echo ""
if [ -d "${MODULE_DIR}/app/Services" ]; then
  for service in $(find "${MODULE_DIR}/app/Services" -name "*.php" -type f 2>/dev/null); do
    name=$(basename "$service" .php)
    svc_deps=$(rg -c "Service \$|Service\$" "$service" 2>/dev/null || echo 0)
    if [ "$svc_deps" -ge 3 ]; then
      echo "  DEEP NESTING RISK: ${name} injects ${svc_deps} other services"
    fi
  done
  echo "  (Services with <3 service dependencies are fine)"
else
  echo "  No Services directory"
fi

echo ""
echo "── 1.12 DTO Separation Check ──"
echo "  (Mutations/ for input, Display/ for output, no God Objects)"
echo ""
if [ -d "${MODULE_DIR}/app/Data" ]; then
  total_dtos=$(find "${MODULE_DIR}/app/Data" -name "*.php" -type f 2>/dev/null | wc -l | tr -d ' ')
  mutations=$(find "${MODULE_DIR}/app/Data/Mutations" -name "*.php" -type f 2>/dev/null | wc -l | tr -d ' ')
  display=$(find "${MODULE_DIR}/app/Data/Display" -name "*.php" -type f 2>/dev/null | wc -l | tr -d ' ')
  root=$(find "${MODULE_DIR}/app/Data" -maxdepth 1 -name "*.php" -type f 2>/dev/null | wc -l | tr -d ' ')
  echo "  Total DTOs: ${total_dtos}"
  echo "  Data/Mutations/: ${mutations} files"
  echo "  Data/Display/: ${display} files"
  echo "  Data/ (root): ${root} files"
  if [ "$root" -ge 8 ]; then
    echo "  WARNING: ${root} DTOs in root — consider splitting into Mutations/ and Display/"
  fi
fi

echo ""
echo "── 1.13 Service Cohesion Check ──"
echo ""
if [ -d "${MODULE_DIR}/app/Services" ]; then
  for service in $(find "${MODULE_DIR}/app/Services" -name "*.php" -type f 2>/dev/null); do
    pub_count=$(rg -c "public function " "$service" 2>/dev/null || echo 0)
    name=$(basename "$service" .php)
    if [ "$pub_count" -ge 10 ]; then
      echo "  GOD-SERVICE: ${name} (${pub_count} public methods)"
    elif echo "$name" | grep -qiE "Helper|Manager|Utils|Processor"; then
      echo "  NAMING VIOLATION: ${name} — use intent-first names"
    fi
  done
  echo "  (Services with <10 public methods and intent-first names are fine)"
else
  echo "  No Services directory"
fi

echo ""
echo "── 1.14 Side-Effect Predictability ──"
echo "  (Side-effects inside DB::transaction = leak risk)"
echo ""
for action in $(find "${MODULE_DIR}/app/Actions" -name "*.php" -type f 2>/dev/null); do
  name=$(basename "$action" .php)
  # Check for dispatch/event/mail inside transaction blocks (approximate)
  has_tx=$(rg -c "DB::transaction" "$action" 2>/dev/null || echo 0)
  has_sideeffect=$(rg -c "dispatch\(|event\(|Mail::" "$action" 2>/dev/null || echo 0)
  has_aftercommit=$(rg -c "afterCommit" "$action" 2>/dev/null || echo 0)
  if [ "$has_tx" -gt 0 ] && [ "$has_sideeffect" -gt 0 ] && [ "$has_aftercommit" -eq 0 ]; then
    echo "  LEAK RISK: ${name} — has transaction + side-effects but no afterCommit"
  fi
done
echo "  (Actions with afterCommit or no side-effects are fine)"

echo ""

# ─── PHASE 2: Pattern Compliance (delegated) ─────────────────────────

echo "══════════════════════════════════════════════"
echo "  PHASE 2: Pattern Compliance (Delegated)"
echo "══════════════════════════════════════════════"
echo ""

SKILL_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BACKEND_SCRIPT="${SKILL_DIR}/scan_backend_module.sh"
FRONTEND_SCRIPT="${SKILL_DIR}/scan_frontend_module.sh"
FULLSTACK_SCRIPT="${SKILL_DIR}/scan_cross_stack_parity.sh"

if [ -x "$BACKEND_SCRIPT" ]; then
  echo "── Running Backend Compliance Scan ──"
  echo ""
  bash "$BACKEND_SCRIPT" "$MODULE_NAME" 2>/dev/null || echo "  Backend scan completed with warnings"
  echo ""
else
  echo "  Backend scan script not found: ${BACKEND_SCRIPT}"
fi

if [ -x "$FRONTEND_SCRIPT" ]; then
  echo "── Running Frontend Compliance Scan ──"
  echo ""
  bash "$FRONTEND_SCRIPT" "$MODULE_SLUG" 2>/dev/null || echo "  Frontend scan completed with warnings"
  echo ""
else
  echo "  Frontend scan script not found: ${FRONTEND_SCRIPT}"
fi

if [ -x "$FULLSTACK_SCRIPT" ]; then
  echo "── Running Cross-Stack Parity Scan ──"
  echo ""
  bash "$FULLSTACK_SCRIPT" "$MODULE_NAME" "$MODULE_SLUG" 2>/dev/null || echo "  Cross-stack scan completed with warnings"
  echo ""
else
  echo "  Cross-stack scan script not found: ${FULLSTACK_SCRIPT}"
fi

echo ""

# ─── PHASE 3: Spatie Data & Full-Stack Parity ────────────────────────

echo "══════════════════════════════════════════════"
echo "  PHASE 3: Spatie Data & Full-Stack Parity"
echo "══════════════════════════════════════════════"
echo ""

echo "── 3.1 DTOs Missing TypeScript Decorator ──"
echo ""
if [ -d "${MODULE_DIR}/app/Data" ]; then
  dtos_total=$(rg -l "extends Data" "${MODULE_DIR}/app/Data/" 2>/dev/null | wc -l | tr -d ' ')
  dtos_ts=$(rg -l "#\[TypeScript\]" "${MODULE_DIR}/app/Data/" 2>/dev/null | wc -l | tr -d ' ')
  echo "  Total DTOs extending Data: ${dtos_total}"
  echo "  DTOs with #[TypeScript]: ${dtos_ts}"
  if [ "$dtos_total" -gt "$dtos_ts" ]; then
    echo "  DTOs missing #[TypeScript]:"
    comm -23 \
      <(rg -l "extends Data" "${MODULE_DIR}/app/Data/" 2>/dev/null | sort) \
      <(rg -l "#\[TypeScript\]" "${MODULE_DIR}/app/Data/" 2>/dev/null | sort) \
      2>/dev/null | while read -r f; do echo "    $f"; done
  fi
fi

echo ""
echo "── 3.2 Display State Detection ──"
echo ""
if [ -d "$FRONTEND_DIR/hooks" ]; then
  hook_count=$(find "$FRONTEND_DIR/hooks" -name "*.ts" -o -name "*.tsx" 2>/dev/null | wc -l | tr -d ' ')
  echo "  Frontend hooks: ${hook_count} files"
  if [ "$hook_count" -ge 3 ]; then
    echo "  EVALUATE: 3+ hooks detected — run Display State evaluation protocol"
    echo "  See: references/display-state-evaluation.md"
  fi
else
  echo "  No hooks directory"
fi

echo ""

# ─── PHASE 4: Quality Gates ──────────────────────────────────────────

echo "══════════════════════════════════════════════"
echo "  PHASE 4: Quality Gates"
echo "══════════════════════════════════════════════"
echo ""

echo "── 4.1 Action/Test Coverage Gap ──"
echo ""
action_count=$(find "${MODULE_DIR}/app/Actions" -name "*.php" -type f 2>/dev/null | wc -l | tr -d ' ')
test_count=$(find "${MODULE_DIR}/tests" -name "*.php" -type f 2>/dev/null | wc -l | tr -d ' ')
echo "  Actions: ${action_count}"
echo "  Test files: ${test_count}"
if [ "$action_count" -gt "$test_count" ]; then
  echo "  WARNING: Potential test coverage gap (fewer test files than actions)"
fi

echo ""
echo "── 4.2 PHPStan (run manually) ──"
echo "  ./vendor/bin/phpstan analyse ${MODULE_DIR}/app --level=4"
echo "  ./vendor/bin/phpstan analyse ${MODULE_DIR}/app --level=5  (after level 4 clean)"

echo ""
echo "── 4.3 Module Tests (run manually) ──"
echo "  php artisan test ${MODULE_DIR}/tests --compact"

echo ""
echo "── 4.4 Frontend TypeScript (run manually) ──"
echo "  npx tsc --noEmit 2>&1 | grep 'pages/admin/${MODULE_SLUG}'"

echo ""
echo "============================================"
echo "  AUDIT SCAN COMPLETE"
echo "  Review findings above, then proceed to"
echo "  manual analysis per SKILL.md phases."
echo "============================================"
