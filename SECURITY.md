# Security Policy

## Reporting a vulnerability

Please report security issues privately, not through a public issue.

Use GitHub's private vulnerability reporting on this repository
("Security" → "Report a vulnerability"), or email the maintainers listed in
`NOTICE`.

Please include: what you found, how to reproduce it, and what an attacker could
achieve. We will acknowledge within a few days and keep you informed as we work
on a fix. If you would like credit in the release notes, say so.

Please give us reasonable time to ship a fix before disclosing publicly.

## Scope and threat model

Qeema is deliberately public: the read API and dashboard are unauthenticated,
because the data being open **is** the point. That shapes what counts as a
vulnerability.

**In scope**

- Anything that lets an unauthenticated user write, modify or delete data.
- Anything that exposes the admin surface, reporter identities, or the
  `qeema_eval` ground-truth schema through the public API.
- Data poisoning at scale: a way to move a published index number through
  crafted submissions that defeats the anomaly and reputation layers.
- Personal data leaking through submitted photographs — EXIF GPS, or
  identifiable people visible in an image reachable without authentication.
- Injection, SSRF (particularly through the FX provider and scraper
  configuration, which take operator-supplied URLs), and path traversal in the
  partner file upload path.
- Resource exhaustion through the bulk export or a costly index query.

**Not in scope**

- The read API returning data. It is public on purpose.
- Rate limits being tunable by the operator. That is configuration.
- Missing security headers on a purely static asset route.
- Reports from automated scanners with no demonstrated impact.

## Operator responsibilities

Qeema is self-hosted, so some of the security posture is yours:

- Set a unique `APP_KEY`. The compose file ships a **demo key for local use
  only**; running it in production with that key is not safe.
- Put TLS in front of the stack. The compose file serves plain HTTP.
- Change the default `qeema` / `qeema` database credentials.
- Do not expose the Postgres or Redis ports publicly.
- Decide a retention policy for reporter photographs before you expose any of
  them. Location metadata is already removed for you — see below — but a
  photograph can still show a face, a shopfront or a number plate, and no
  software decides how long that should be kept.
- Keep base images current; `docker compose build --pull` picks up upstream
  security fixes.

## Photographs are stripped on ingest

A reporter photographing a price tag is usually holding a phone that writes the
coordinates, the timestamp to the second and often a device identifier into the
file. That is a record of where a particular person stood on a particular
afternoon.

The platform removes it before the file is written to disk, rather than asking
the operator to remember: EXIF, XMP, IPTC and comment blocks from JPEG, and the
`eXIf`, `tEXt`, `iTXt`, `zTXt` and `tIME` chunks from PNG. The picture data
itself is passed through untouched — no re-encoding, because a reviewer is
reading small print off a price tag.

A photograph whose metadata cannot be removed is not stored at all; the
submission is still accepted, because the price is the contribution and the
picture is corroboration. Uploads are restricted to JPEG and PNG: SVG is a
document that can carry script, and has no picture data to separate metadata
from.

This narrows but does not close the exposure. A photograph can still show a
face, a shopfront, or a licence plate, and stripping metadata does nothing about
that. Retention and access remain an operator decision, and reporter photographs
stay behind the admin login.

## No secrets, by construction

Qeema depends on no paid or proprietary service, so a correct deployment has no
third-party API keys to leak. If you find a credential committed to this
repository, treat it as a vulnerability and report it — CI scans for
secret-shaped values, but scanners miss things.

One deliberate exception exists, and it is opt-in. An operator may configure an
exchange-rate source of their own, and some of those require a key. When they
do, the country file names the *environment variable* holding the token and
never the token itself, precisely so that a configuration file under version
control cannot become a published credential. Nothing in this repository is
configured with such a source; the shipped default for every country is manual
entry.

Outbound requests made on operator instruction — the rate source and the
open-data scraper — are restricted to public http/https addresses. A URL that
resolves to a private, loopback or link-local address is refused, which is what
stops configuration being turned into a way to read the host's cloud metadata
service. The residual gap is DNS rebinding, since the address is checked and
then resolved again when connecting; closing it would require pinning the
resolved address into the connection, which the HTTP client does not expose.
