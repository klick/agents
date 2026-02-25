#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"

CRAFT_PROJECT_DIR="${1:-}"
PLUGIN_PATH="${2:-$PLUGIN_ROOT}"
LINK_MODE="${3:-auto}"

if [[ -z "$CRAFT_PROJECT_DIR" ]]; then
  echo "Usage: $0 <craft-project-dir> [plugin-path] [link-mode:auto|symlink|copy]"
  echo "Example: $0 ~/sites/agents-sandbox"
  exit 1
fi

if [[ ! -f "$CRAFT_PROJECT_DIR/composer.json" ]]; then
  echo "ERROR: composer.json not found in $CRAFT_PROJECT_DIR"
  exit 1
fi

PLUGIN_PATH="$(cd "$PLUGIN_PATH" && pwd)"
CRAFT_PROJECT_DIR="$(cd "$CRAFT_PROJECT_DIR" && pwd)"

if [[ "$LINK_MODE" != "auto" && "$LINK_MODE" != "symlink" && "$LINK_MODE" != "copy" ]]; then
  echo "ERROR: link-mode must be one of auto, symlink, copy"
  exit 1
fi

if [[ "$LINK_MODE" == "auto" ]]; then
  case "$PLUGIN_PATH" in
    "$CRAFT_PROJECT_DIR"/*)
      SYMLINK_VALUE="true"
      RESOLVED_MODE="symlink"
      ;;
    *)
      SYMLINK_VALUE="false"
      RESOLVED_MODE="copy"
      ;;
  esac
elif [[ "$LINK_MODE" == "symlink" ]]; then
  SYMLINK_VALUE="true"
  RESOLVED_MODE="symlink"
else
  SYMLINK_VALUE="false"
  RESOLVED_MODE="copy"
fi

cd "$CRAFT_PROJECT_DIR"

echo "Configuring Craft project for local plugin path repository..."
composer config minimum-stability dev
composer config prefer-stable true
composer config repositories.klick-agents --json "{\"type\":\"path\",\"url\":\"$PLUGIN_PATH\",\"options\":{\"symlink\":$SYMLINK_VALUE}}"

if composer show klick/agents >/dev/null 2>&1; then
  composer require klick/agents:@dev --no-interaction --no-update
  composer update klick/agents --no-interaction
else
  composer require klick/agents:@dev --no-interaction
fi

echo ""
echo "Local plugin path repo configured: $PLUGIN_PATH (mode: $RESOLVED_MODE)"
echo "Next: run Craft setup/install if needed, then install plugin:"
if command -v ddev >/dev/null 2>&1 && [[ -f .ddev/config.yaml ]]; then
  echo "  ddev craft plugin/install agents"
else
  echo "  php craft plugin/install agents"
fi
