#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"

fail() {
  echo "FAIL: $1"
  exit 1
}

pass() {
  echo "PASS: $1"
}

COMPOSER_FILE="$PLUGIN_ROOT/composer.json"
PLUGIN_FILE="$PLUGIN_ROOT/src/Plugin.php"
MIGRATIONS_DIR="$PLUGIN_ROOT/src/migrations"

[[ -f "$COMPOSER_FILE" ]] || fail "Missing composer.json"
[[ -f "$PLUGIN_FILE" ]] || fail "Missing src/Plugin.php"
[[ -d "$MIGRATIONS_DIR" ]] || fail "Missing src/migrations directory"

composer_version="$(php -r '$j=json_decode(file_get_contents($argv[1]), true); echo $j["version"] ?? "";' "$COMPOSER_FILE")"
schema_version="$(sed -n "s/^[[:space:]]*public string \$schemaVersion = '\([^']*\)';$/\1/p" "$PLUGIN_FILE" | head -n1)"

if [[ -z "$composer_version" ]]; then
  fail "Unable to parse version from composer.json"
fi
if [[ -z "$schema_version" ]]; then
  fail "Unable to parse schemaVersion from src/Plugin.php"
fi
if [[ "$composer_version" != "$schema_version" ]]; then
  fail "Version mismatch (composer.json=$composer_version, Plugin::schemaVersion=$schema_version)"
fi
pass "Plugin schemaVersion matches composer version ($composer_version)"

TMP_DIR="$(mktemp -d)"
trap 'rm -rf "$TMP_DIR"' EXIT

migrations_file="$TMP_DIR/migrations.txt"
seen_classes_file="$TMP_DIR/seen-classes.txt"
touch "$seen_classes_file"

find "$MIGRATIONS_DIR" -maxdepth 1 -type f -name 'm*.php' | sort >"$migrations_file"
if [[ ! -s "$migrations_file" ]]; then
  fail "No migration files found under src/migrations"
fi

prev_basename=""
migration_count=0

while IFS= read -r file; do
  [[ -n "$file" ]] || continue
  migration_count=$((migration_count + 1))

  basename_no_ext="$(basename "$file" .php)"
  if [[ ! "$basename_no_ext" =~ ^m[0-9]{6}_[0-9]{6}_.+$ ]]; then
    fail "Migration filename does not follow expected timestamp format: $file"
  fi

  if [[ -n "$prev_basename" && "$basename_no_ext" < "$prev_basename" ]]; then
    fail "Migration filenames are not sorted monotonically: $prev_basename then $basename_no_ext"
  fi
  prev_basename="$basename_no_ext"

  class_name="$(awk '/^class[[:space:]]+[A-Za-z0-9_]+/ { print $2; exit }' "$file")"
  if [[ -z "$class_name" ]]; then
    fail "Migration class declaration missing in $file"
  fi
  if [[ "$class_name" != "$basename_no_ext" ]]; then
    fail "Migration class/file mismatch ($file declares $class_name)"
  fi

  if grep -Fxq "$class_name" "$seen_classes_file"; then
    fail "Duplicate migration class detected: $class_name"
  fi
  echo "$class_name" >>"$seen_classes_file"

  grep -Fq "function safeUp()" "$file" || fail "Migration missing safeUp(): $file"
done <"$migrations_file"

pass "Migration filenames, classes, and ordering are consistent ($migration_count files)"
echo "Migration safety checks completed."
