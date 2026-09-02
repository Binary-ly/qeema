#!/usr/bin/env bash
# SPDX-License-Identifier: Apache-2.0
#
# Update a bind-mounted deployment to a git ref, without breaking it.
#
#     bash infra/scripts/deploy-pull.sh [ref] [app-container] [base-url]
#
# The compose stack bind-mounts the checkout, and the containers do not run as
# the user who owns it. Git creates files with the *server's* umask, so on a
# host with `umask 007` every file git writes lands mode 660 — unreadable to
# the container. The site then 500s on whichever file the request happens to
# need first, with a permission error nobody associates with `git pull`.
#
# That has now happened twice on the same deployment: once on `api/public`
# after a rebuild, and once on `countries/geometry` after a `git reset --hard`,
# which took the dashboard down while the API and the reporter kept serving 200
# and made it look like an application bug rather than a file mode.
#
# So the chmod is part of pulling, not something to remember afterwards. `a+rX`
# adds read for everyone and traverse on directories only; it grants no write
# and no execute on regular files.

set -euo pipefail

ref="${1:-origin/main}"
container="${2:-qeema-app-1}"
base_url="${3:-http://localhost}"
root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"

cd "$root"

echo "==> fetching"
git fetch --quiet origin

echo "==> checking out ${ref}"
git reset --hard --quiet "$ref"
git --no-pager log --oneline -1

# Everything git just wrote, plus anything left from an earlier build.
echo "==> restoring read access for the container"
chmod -R a+rX "$root"

echo "==> clearing compiled views and config"
docker exec "$container" php artisan view:clear >/dev/null
docker exec "$container" php artisan config:clear >/dev/null

# A deploy that ends without checking is a deploy that reports success while
# serving a stack trace. The dashboard is the page that reads the most files,
# so it is the one that catches a mode problem.
echo "==> verifying"
for path in "/" "/report" "/docs" "/api/v1/health"; do
    # `|| true` because `set -e` otherwise kills the script the moment curl
    # cannot connect — silently, before the line below can say which URL failed
    # and why. A verification step that exits without a word when the thing it
    # verifies is unreachable is worse than no verification step.
    code="$(curl -s -o /dev/null -w '%{http_code}' --max-time 10 "${base_url}${path}" || true)"
    code="${code:-000}"
    printf '    %-18s %s\n' "$path" "$code"

    if [ "$code" != "200" ]; then
        echo "!!! ${path} returned ${code} — deployment is not healthy" >&2
        exit 1
    fi
done

echo "==> ok"
