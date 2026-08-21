#!/usr/bin/env bash
# SPDX-License-Identifier: Apache-2.0
#
# Retry a command that depends on somebody else's server being up.
#
#     bash infra/scripts/retry.sh 3 composer install --no-interaction
#
# Package installs fail for reasons that have nothing to do with the change
# being tested. One build here died on `HTTP/2 504` while GitHub served a
# zipball of symfony/mailer; the same commit passed on a re-run minutes later
# with no edit. That failure is indistinguishable, on the dashboard, from a real
# one — and a build that cries wolf is a build people stop reading.
#
# Only wrap fetches. A retried *test* hides a flake, which is the opposite of
# what this is for: flakes are real defects and must stay visible.

set -uo pipefail

attempts="${1:?usage: retry.sh <attempts> <command...>}"
shift

for (( i = 1; i <= attempts; i++ )); do
    if "$@"; then
        [[ $i -gt 1 ]] && echo "retry.sh: succeeded on attempt ${i}/${attempts}"
        exit 0
    fi

    if [[ $i -lt $attempts ]]; then
        # Linear backoff. The failures this exists for are seconds-to-minutes
        # outages, not rate limits needing exponential politeness.
        delay=$(( i * 15 ))
        echo "retry.sh: attempt ${i}/${attempts} failed; retrying in ${delay}s: $*" >&2
        sleep "$delay"
    fi
done

echo "retry.sh: all ${attempts} attempts failed: $*" >&2
exit 1
