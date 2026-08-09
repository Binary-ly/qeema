#!/usr/bin/env bash
# SPDX-License-Identifier: Apache-2.0
#
# Constraint C3: nothing about the default country may be hardcoded.
#
# Country facts belong in countries/*.yaml and reach the application through the
# database. This check fails the build if a country-specific literal appears in
# source code, which is the failure mode that quietly makes a "country-agnostic"
# platform single-country over time.
#
# Documentation and configuration are allowed to name countries; code is not.

set -uo pipefail

cd "$(dirname "$0")/../.."

# Literals that must never appear in application source.
PATTERNS=(
    'Libya'
    'libya'
    'LYD'
    'Tripoli'
    'Benghazi'
    'Misrata'
    'fulus\.ly'
    'Sudan'
    'SDG'
    'Khartoum'
)

# Country configuration, docs, tests, fixtures and adapter registrations are
# legitimately allowed to name a country.
EXCLUDE_PATHS=(
    ':!countries/**'
    ':!docs/**'
    ':!*.md'
    ':!**/tests/**'
    ':!**/test/**'
    ':!e2e/**'
    ':!infra/scripts/check-country-agnostic.sh'
    ':!**/lang/**'
    ':!**/database/factories/**'
)

failed=0
report=""

for pattern in "${PATTERNS[@]}"; do
    if ! matches=$(git grep -n -I -E "$pattern" -- \
            'api/app' 'api/config' 'api/routes' 'api/database/migrations' \
            'ml/src' 'infra/docker' \
            "${EXCLUDE_PATHS[@]}" 2>/dev/null); then
        continue
    fi

    if [[ -n "$matches" ]]; then
        failed=1
        report+=$'\n'"  pattern '${pattern}':"$'\n'
        while IFS= read -r line; do
            report+="    ${line}"$'\n'
        done <<< "$matches"
    fi
done

if [[ $failed -eq 1 ]]; then
    echo "FAIL: country-specific literals found in application source (constraint C3)."
    echo "Move these into countries/*.yaml and read them from configuration."
    echo "$report"
    exit 1
fi

echo "OK: no country-specific literals in application source (constraint C3)."
