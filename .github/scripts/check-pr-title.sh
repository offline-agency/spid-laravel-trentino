#!/usr/bin/env bash
# Fails unless PR_TITLE is a Conventional Commit header, e.g. "feat(auth)!: drop Laravel 11".
set -euo pipefail

pattern='^(build|chore|ci|docs|feat|fix|perf|refactor|revert|style|test)(\([a-z0-9._/-]+\))?!?: .+'

if [[ "${PR_TITLE:-}" =~ $pattern ]]; then
  echo "PR title OK"
else
  echo "PR title must follow Conventional Commits (<type>[(scope)][!]: <description>). Got: ${PR_TITLE:-<empty>}" >&2
  exit 1
fi
