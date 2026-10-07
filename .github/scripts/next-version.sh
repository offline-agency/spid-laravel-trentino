#!/usr/bin/env bash
# Prints the next release tag from the latest vX.Y.Z tag and the merged PR.
# Usage: PR_TITLE=... PR_BODY=... next-version.sh "<latest tag or empty>"
#   <type>!: or a "BREAKING CHANGE:" footer -> major
#   feat:                                    -> minor
#   anything else                            -> patch
set -euo pipefail

latest="${1:-}"
title="${PR_TITLE:-}"
body="${PR_BODY:-}"

if [[ -z "$latest" ]]; then
  echo "${INITIAL_VERSION:-v2.0.0}"
  exit 0
fi

if [[ ! "$latest" =~ ^v([0-9]+)\.([0-9]+)\.([0-9]+)$ ]]; then
  echo "Unsupported tag format: $latest" >&2
  exit 1
fi
major="${BASH_REMATCH[1]}"
minor="${BASH_REMATCH[2]}"
patch="${BASH_REMATCH[3]}"

if [[ "$title" =~ ^[a-z]+(\([^\)]*\))?!: ]] || grep -Eq '^BREAKING[ -]CHANGE:' <<<"$body"; then
  echo "v$((major + 1)).0.0"
elif [[ "$title" =~ ^feat(\([^\)]*\))?: ]]; then
  echo "v${major}.$((minor + 1)).0"
else
  echo "v${major}.${minor}.$((patch + 1))"
fi
