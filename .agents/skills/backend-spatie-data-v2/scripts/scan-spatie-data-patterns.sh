#!/usr/bin/env bash
set -euo pipefail

TARGET="${1:-Modules/Bookings}"

if [[ ! -d "$TARGET" ]]; then
  echo "Target directory does not exist: $TARGET" >&2
  exit 1
fi

DATA_ROOT="$TARGET/app/Data"

if [[ ! -d "$DATA_ROOT" ]]; then
  echo "Data directory does not exist: $DATA_ROOT" >&2
  exit 1
fi

count() {
  local pattern="$1"
  local path="${2:-$DATA_ROOT}"
  rg -n "$pattern" "$path" 2>/dev/null | wc -l | tr -d ' '
}

files_count() {
  rg --files "$DATA_ROOT" 2>/dev/null | wc -l | tr -d ' '
}

echo "Spatie Data pattern scan"
echo "Target: $TARGET"
echo
echo "Counts"
echo "------"
printf "Data files: %s\n" "$(files_count)"
printf "Classes extending Data: %s\n" "$(count 'extends Data')"
printf "TypeScript classes: %s\n" "$(count '#\[TypeScript\]')"
printf "DataTransform usage: %s\n" "$(count 'use DataTransform')"
printf "LiteralTypeScriptType: %s\n" "$(count 'LiteralTypeScriptType')"
printf "TypeScriptType: %s\n" "$(count 'TypeScriptType')"
printf "TypeScriptOptional: %s\n" "$(count 'TypeScriptOptional|Attributes\\\\Optional as TypeScriptOptional')"
printf "Optional unions: %s\n" "$(count '\|Optional|Optional\|')"
printf "DataCollectionOf: %s\n" "$(count 'DataCollectionOf')"
printf "DataCollection type usage: %s\n" "$(count 'DataCollection')"
printf "Public array properties: %s\n" "$(count 'public readonly array|public array')"
printf "rules() methods: %s\n" "$(count 'static function rules\(')"
printf "messages() methods: %s\n" "$(count 'static function messages\(')"
printf "attributes() methods: %s\n" "$(count 'static function attributes\(')"
printf "defaultWrap() methods: %s\n" "$(count 'function defaultWrap\(')"
printf "WithDeprecatedCollectionMethod: %s\n" "$(count 'WithDeprecatedCollectionMethod')"
echo

echo "Nullable TypeScriptType candidates"
echo "-------------------------------"
rg -n -U '#\[TypeScriptType\([^\]]+::class\)\]\n\s*public readonly \?' "$DATA_ROOT" || true
rg -n -U '#\[TypeScriptType\([^\]]+::class\)\]\n\s*#\[Nullable\]\n\s*public readonly [^\n]+\|Optional\|null' "$DATA_ROOT" || true
rg -n -U '#\[TypeScriptType\([^\]]+::class\)\]\n\s*public readonly [^\n]+\|Optional\|null' "$DATA_ROOT" || true
echo

echo "Redundant primitive literal candidates"
echo "-------------------------------------"
rg -n "#\\[LiteralTypeScriptType\\('(string|boolean|number|string \\| null|boolean \\| null|number \\| null)'\\)\\]" "$DATA_ROOT" || true
echo

echo "Dynamic record candidates"
echo "-------------------------"
rg -n "LiteralTypeScriptType\\('Record<|Record<string, unknown>|Record<string, mixed>|array<string, mixed>" "$DATA_ROOT" || true
echo

echo "Deprecated collection candidates"
echo "--------------------------------"
rg -n "WithDeprecatedCollectionMethod|::collection\\(" "$TARGET/app" "$TARGET/tests" 2>/dev/null || true
