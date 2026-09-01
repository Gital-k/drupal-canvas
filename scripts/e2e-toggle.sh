#!/bin/bash

# This script toggles the `_CANVAS_E2E_TESTS` value in `.gitlab-ci.yml`.
#
# Usage:
#   sh scripts/e2e-toggle.sh          # Toggle true <-> false and commit.
#   sh scripts/e2e-toggle.sh status   # Print the current value.

set -e

# Navigate to the repo's root directory.
cd "$(dirname "$0")/../" || { echo "Error: Failed to change directory." >&2; exit 1; }

FILE=".gitlab-ci.yml"

if [ ! -f "$FILE" ]; then
  echo "Error: $FILE not found." >&2
  exit 1
fi

CURRENT=$(grep -E '^  _CANVAS_E2E_TESTS: (true|false)$' "$FILE" | awk '{print $2}')

if [ -z "$CURRENT" ]; then
  echo "Error: Could not find '_CANVAS_E2E_TESTS: true|false' in $FILE." >&2
  exit 1
fi

if [ "$1" = "status" ]; then
  echo "_CANVAS_E2E_TESTS: $CURRENT"
  exit 0
fi

if ! git diff --cached --quiet; then
  echo "Error: There are already staged changes. Commit or unstage them before running this script." >&2
  exit 1
fi

if [ "$CURRENT" = "true" ]; then
  NEW="false"
  MESSAGE="🚧 Disable E2E tests."
else
  NEW="true"
  MESSAGE="🚧 Re-enable E2E tests."
fi

# Portable in-place sed (BSD/macOS and GNU).
sed -i.bak "s/^  _CANVAS_E2E_TESTS: ${CURRENT}$/  _CANVAS_E2E_TESTS: ${NEW}/" "$FILE"
rm -f "${FILE}.bak"

git add "$FILE"

echo
echo "Staged change: _CANVAS_E2E_TESTS: ${CURRENT} -> ${NEW}"
git --no-pager diff --cached -- "$FILE"
echo
printf "Commit with message \"%s\"? [y/N] " "$MESSAGE"
read -r REPLY
case "$REPLY" in
  [yY]|[yY][eE][sS])
    git commit -m "$MESSAGE"
    ;;
  *)
    echo "Aborted. The change is still staged. Run \`git restore --staged $FILE && git checkout -- $FILE\` to revert."
    exit 1
    ;;
esac
