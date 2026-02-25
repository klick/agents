#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"

SANDBOX_DIR="${1:-$PLUGIN_ROOT/../agents-sandbox}"
PROJECT_NAME="${2:-agents-sandbox}"

if ! command -v composer >/dev/null 2>&1; then
  echo "ERROR: composer is required."
  exit 1
fi

if [[ -e "$SANDBOX_DIR" ]] && [[ -n "$(ls -A "$SANDBOX_DIR" 2>/dev/null || true)" ]]; then
  echo "ERROR: target directory is not empty: $SANDBOX_DIR"
  exit 1
fi

echo "Creating Craft sandbox at: $SANDBOX_DIR"
composer create-project craftcms/craft "$SANDBOX_DIR"

cd "$SANDBOX_DIR"

if command -v ddev >/dev/null 2>&1; then
  if [[ ! -f .ddev/config.yaml ]]; then
    ddev config --project-type=craftcms --docroot=web --project-name "$PROJECT_NAME" --create-docroot=false
  fi
  ddev start
  echo ""
  echo "Next steps:"
  echo "  1) cd $SANDBOX_DIR"
  echo "  2) ddev craft setup"
  echo "  3) $PLUGIN_ROOT/scripts/dev/configure-local-plugin.sh $SANDBOX_DIR"
  echo "  4) $PLUGIN_ROOT/scripts/dev/apply-fixture-config.sh $SANDBOX_DIR"
  echo "  5) ddev craft plugin/install agents"
else
  echo ""
  echo "DDEV not found. Continue with:"
  echo "  1) cd $SANDBOX_DIR"
  echo "  2) php craft setup"
  echo "  3) $PLUGIN_ROOT/scripts/dev/configure-local-plugin.sh $SANDBOX_DIR"
  echo "  4) $PLUGIN_ROOT/scripts/dev/apply-fixture-config.sh $SANDBOX_DIR"
  echo "  5) php craft plugin/install agents"
fi
