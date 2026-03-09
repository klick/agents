#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
DIST_DIR="${ROOT_DIR}/docs/.vitepress/dist"

REMOTE_HOST="${REMOTE_HOST:-marcus@v69630.goserver.host}"
REMOTE_PATH="${REMOTE_PATH:-/var/customers/webs/marcus/marcusscheller.com/html/docs/agents}"

if [[ ! -d "${DIST_DIR}" ]]; then
  echo "Build output not found: ${DIST_DIR}"
  echo "Run: npm run docs:build"
  exit 1
fi

RSYNC_ARGS=(
  -az
  --delete
  --human-readable
)

if [[ "${DRY_RUN:-0}" == "1" ]]; then
  RSYNC_ARGS+=(--dry-run --itemize-changes)
fi

echo "Deploying docs:"
echo "  Source: ${DIST_DIR}/"
echo "  Target: ${REMOTE_HOST}:${REMOTE_PATH}/"
if [[ "${DRY_RUN:-0}" == "1" ]]; then
  echo "  Mode: dry-run"
fi

ssh "${REMOTE_HOST}" "mkdir -p '${REMOTE_PATH}'"
rsync "${RSYNC_ARGS[@]}" "${DIST_DIR}/" "${REMOTE_HOST}:${REMOTE_PATH}/"

echo "Docs deploy complete."
