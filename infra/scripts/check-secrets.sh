#!/usr/bin/env bash
# SPDX-License-Identifier: Apache-2.0
#
# Fail if a secret-shaped value is about to enter the repository.
#
# This lived only inside .github/workflows/ci.yml, which meant the one gate
# nobody could run locally was the one guarding the thing you cannot take back.
# A lint error is a nuisance; a committed key is a rotation, a force-push and a
# disclosure. Having it here lets `make lint` and the pre-push hook run exactly
# what CI runs.
#
# --untracked, for the same reason check-country-agnostic.sh uses it: a brand
# new file is precisely the file most likely to carry a pasted credential, and a
# check that only reads tracked files passes right up until the moment it
# matters.

set -uo pipefail

cd "$(dirname "$0")/../.."

# Shapes, not values. Anything specific enough to match a real key is specific
# enough to be a secret itself, which is why this file is excluded below.
PATTERN='(sk-[A-Za-z0-9]{20,}|AKIA[0-9A-Z]{16}|-----BEGIN [A-Z ]*PRIVATE KEY-----|xox[baprs]-[A-Za-z0-9-]{10,}|gh[pousr]_[A-Za-z0-9]{36})'

# Prose may quote a key shape while explaining it; code may not. This script is
# excluded because it contains every pattern by definition.
if matches=$(git grep -nIE --untracked "$PATTERN" -- . ':!*.md' ':!infra/scripts/*' 2>/dev/null); then
    if [[ -n "$matches" ]]; then
        echo "FAIL: possible secret committed."
        echo "$matches"
        echo
        echo "If this is a false positive, narrow the match rather than deleting the check."
        exit 1
    fi
fi

echo "OK: no secret-shaped values found."
