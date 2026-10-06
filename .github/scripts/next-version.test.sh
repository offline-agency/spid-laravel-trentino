#!/usr/bin/env bash
# Tests for next-version.sh. Run: bash .github/scripts/next-version.test.sh
set -uo pipefail
cd "$(dirname "$0")"

failures=0
check() { # expected latest title [body]
  local actual
  actual="$(PR_TITLE="$3" PR_BODY="${4:-}" bash ./next-version.sh "$2" 2>&1)"
  if [[ "$actual" == "$1" ]]; then echo "ok   $1 <= '$2' '$3'"; else echo "FAIL expected $1, got '$actual' <= '$2' '$3'"; failures=$((failures + 1)); fi
}

check v2.0.0 ""       "fix: anything"
check v2.0.1 v2.0.0   "fix: handle empty claims"
check v2.0.1 v2.0.0   "chore(deps): bump jumbojett"
check v2.0.1 v2.0.0   "Not a conventional title"
check v2.1.0 v2.0.1   "feat: add back-channel logout"
check v2.1.0 v2.0.1   "feat(routes): configurable prefix"
check v3.0.0 v2.1.0   "feat!: drop Laravel 12"
check v3.0.0 v2.1.0   "fix(auth)!: change session keys"
check v3.0.0 v2.1.0   "refactor: rename config" $'Some text\n\nBREAKING CHANGE: config key renamed'
check v2.1.1 v2.1.0   "docs: mention BREAKING CHANGE in passing" "no footer here"

if PR_TITLE="fix: x" bash ./next-version.sh "release-2" >/dev/null 2>&1; then echo "FAIL accepted a malformed tag"; failures=$((failures + 1)); else echo "ok   rejects malformed tag"; fi

exit "$failures"
