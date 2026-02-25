#!/usr/bin/env bash
set -euo pipefail

CRAFT_PROJECT_DIR="${1:-}"
VERSION_CONSTRAINT="${2:-^0.1.2}"

if [[ -z "$CRAFT_PROJECT_DIR" ]]; then
  echo "Usage: $0 <craft-project-dir> [version-constraint]"
  echo "Example: $0 ~/www/sites/coloursource ^0.1.2"
  exit 1
fi

if [[ ! -f "$CRAFT_PROJECT_DIR/composer.json" ]]; then
  echo "ERROR: composer.json not found in $CRAFT_PROJECT_DIR"
  exit 1
fi

cd "$CRAFT_PROJECT_DIR"

php -r '
$file = "composer.json";
$json = json_decode(file_get_contents($file), true);
if (!is_array($json)) {
    fwrite(STDERR, "ERROR: invalid composer.json\n");
    exit(1);
}

$repos = $json["repositories"] ?? null;
if (!is_array($repos)) {
    exit(0);
}

$isList = array_keys($repos) === range(0, count($repos) - 1);
$filtered = $isList ? [] : [];

foreach ($repos as $key => $repo) {
    $isAgentsPathRepo = is_array($repo)
        && ($repo["type"] ?? null) === "path"
        && isset($repo["url"])
        && preg_match("#(^|/)plugins/agents$#", (string) $repo["url"]) === 1;

    if ($isAgentsPathRepo) {
        continue;
    }

    if ($isList) {
        $filtered[] = $repo;
    } else {
        $filtered[$key] = $repo;
    }
}

if ($filtered !== $repos) {
    $json["repositories"] = $filtered;
    file_put_contents(
        $file,
        json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL
    );
}
'

composer require "klick/agents:${VERSION_CONSTRAINT}" --no-interaction

echo "Restored store-backed install for klick/agents (${VERSION_CONSTRAINT}) in $CRAFT_PROJECT_DIR"
