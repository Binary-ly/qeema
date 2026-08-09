# ADR 0002 — Redis 8 under AGPLv3

**Status:** accepted · **Date:** 2026-08-09

## Context

Two project requirements appeared to conflict.

The mandated stack names **Redis** for cache and queue, with Laravel Horizon on
top, and says not to substitute components. Separately, constraint C1 requires
that **every dependency be OSI-licensed**, because the UNICEF Venture Fund
disqualifies open source applications that depend on closed backends.

Redis relicensed in March 2024. Versions 7.4 through 7.x are dual-licensed
RSALv2 / SSPLv1. **Neither is OSI-approved.** Taken at face value, the stack
requirement and the licensing constraint could not both be satisfied.

## Investigation

Checked against Redis's own `LICENSE.txt` rather than secondary sources:

> "contributions are subject to your choice of: (a) the Redis Source Available
> License v2 (RSALv2); or (b) the Server Side Public License v1 (SSPLv1); or
> (c) the GNU Affero General Public License v3 (AGPLv3)."

and

> "Redis Open Source 7.2 and prior releases remain subject to the BSDv3 clause
> license"

Redis 8.0 (May 2025) added **AGPLv3** as a third option. AGPLv3 **is**
OSI-approved. The conflict exists only for 7.4–7.x.

## Decision

Pin **Redis ≥ 8.0** and elect the **AGPLv3** option. Do not substitute the
component.

`docker-compose.yml` pins `redis:8-alpine` through a `REDIS_IMAGE` variable.

## Consequences

**Licence compatibility.** Redis runs as a separate, unmodified network service.
Qeema does not link against it or distribute a modified Redis. AGPLv3's source
provisions attach to Redis itself, not to Qeema's Apache-2.0 code. Shipping both
in one compose file distributes two independent programs, which is fine.

**Operator choice.** Some organisations have policies against AGPL software even
when used at arm's length. Because Valkey (BSD-3-Clause, Linux Foundation) is
wire-compatible, such an operator sets:

```bash
REDIS_IMAGE=valkey/valkey:8 docker compose up
```

No code change. This is documented in `NOTICE` and the deployment guide.

**What we did not do.** Making Valkey the default would have silently
substituted a mandated component. The requirement is satisfiable as written, so
substituting it would have been solving a problem that does not exist.

## Rejected alternatives

- **Pin Redis 7.2 (BSD-3-Clause).** OSI-clean, but parks the platform on a
  release line that stops receiving security fixes.
- **Default to Valkey.** Substitutes a mandated component unnecessarily.
- **Ship SSPL Redis and argue it is "open enough".** SSPL is not OSI-approved.
  This is precisely the kind of claim that would sink a funding application.
