#!/usr/bin/env bash
set -euo pipefail

SOURCE_DIR="${SOURCE_DIR:-docs/wiki}"
DESTINATION="${GITHUB_WIKI_PATH:-${1:-}}"

if [[ -z "$DESTINATION" ]]; then
    REPO_NAME="$(basename "$(pwd)")"
    if [[ -d "../${REPO_NAME}.wiki/.git" ]]; then
        DESTINATION="../${REPO_NAME}.wiki"
    fi
fi

if [[ -z "$DESTINATION" ]]; then
    echo "Usage: scripts/sync-github-wiki.sh <wiki-checkout>"
    echo "Or set GITHUB_WIKI_PATH=/path/to/wiki-checkout"
    exit 1
fi

if [[ ! -d "$SOURCE_DIR" ]]; then
    echo "Source docs directory not found: $SOURCE_DIR"
    exit 1
fi

if [[ ! -d "$DESTINATION/.git" ]]; then
    echo "Destination is not a git checkout: $DESTINATION"
    exit 1
fi

rsync -a --delete --exclude ".git" "${SOURCE_DIR}/" "${DESTINATION}/"

echo "Synced ${SOURCE_DIR} -> ${DESTINATION}"
echo "Review with: git -C ${DESTINATION} status --short"
