<!-- SPDX-License-Identifier: Apache-2.0 -->

# Privacy and data protection

**Who this is for.** Qeema is self-hosted software. Whoever runs a deployment is
the data controller for whatever it collects; the project is not. This document
is not a privacy policy you can adopt — it is the factual basis you need to
write one, and the list of controls the software gives you to honour it.

Everything below was read off the schema and the code, not off an intention. The
migration files are the authority and are cited throughout.

---

## The design principle

The platform collects prices, not people. Every identifying field that a price
reporting app would *normally* have — an account, a phone number, an email
address, a location fix from the device — is absent, and absent by construction
rather than by policy. The result is that most privacy questions have the
uninteresting answer: there is no such column.

That is deliberate and it has a cost. Reporters cannot recover their reputation
if they lose their device, cannot be contacted, and cannot be verified. Those
were accepted in exchange for a system that cannot leak what it never held.

---

## What is collected

### Reporters — `reporters` table

| Field | What it is | Notes |
|---|---|---|
| `external_ref` | A UUID the reporter app generates and keeps in the device's local storage | The only identifier. Not derived from the device, the network or the person — it is random. Anyone who clears their browser storage becomes a new reporter. |
| `display_name` | Optional label | **Never settable from the public API.** It is not in `StoreSubmissionRequest`, so it can only be typed by an operator in the admin panel. If your deployment never types one, no reporter has a name. |
| `country_id`, `location_id` | Where they report | Chosen from the country's configured location list. Not a device location fix. |
| `reputation`, `reputation_alpha`, `reputation_beta`, `submissions_total/accepted/rejected` | Trust scoring | Behavioural, derived from their own submissions. |
| `first_seen_at`, `last_seen_at` | Activity window | |
| `is_blocked`, `blocked_reason` | Moderation state | Set by a human or not at all. |

**Not collected:** name, phone, email, address, date of birth, government ID,
device fingerprint, advertising ID, contacts, or any device-reported
coordinates.

### Submissions — `submissions` table

The price, its unit and quantity, the currency, the free text the reporter
typed, the timestamps, and:

| Field | Notes |
|---|---|
| `device_metadata` | An **allow-list of exactly three** non-identifying values: `platform`, `app_version`, `queued_offline`. Written field-by-field in `app/Actions/RecordSubmission.php`, so anything else a client sends is discarded rather than filtered — a future client cannot widen this by accident. |
| `client_idempotency_key` | A random UUID the client generates per submission, so a retry after a flaky connection is not counted twice. Not linkable across submissions. |
| `photo_path` | Optional. See below. |

**No IP address is stored against a submission or a reporter.** There is no
column for one. IPs are used transiently as rate-limiting keys and are not
persisted.

### Photographs

Optional corroboration of a price tag. Before the file reaches disk, EXIF, XMP,
IPTC and comment blocks are removed from JPEG, and the `eXIf`, `tEXt`, `iTXt`,
`zTXt` and `tIME` chunks from PNG. A photograph whose metadata cannot be removed
is **not stored at all** — the submission is still accepted, because the price
is the contribution.

Photographs are written to the private `local` disk and are reachable only
behind the admin login. They are never served by the public API.

**This narrows the exposure; it does not close it.** A photograph can still show
a face, a shopfront or a licence plate, and stripping metadata does nothing
about the picture itself.

### Operators and administrators — `users` table

Standard Laravel: name, email, hashed password. This is real personal data about
your staff, held under your own policy. It has no connection to reporters.

### Sessions

The shipped configuration uses **Redis** sessions (`SESSION_DRIVER: redis` in
`docker-compose.yml`), which store no IP address. Laravel's `sessions` table
exists in the schema and has `ip_address` and `user_agent` columns; switching
`SESSION_DRIVER` to `database` means choosing to record those for *admin*
sessions. Reporters do not authenticate and have no session.

---

## What is published

The public API and the CSV export carry **aggregates only**: a location's basket
cost, per-item prices, coverage, imputation share, confidence intervals and
exchange rates.

Never published, at any endpoint: reporter identifiers, display names, raw
submitted text, photographs, device metadata, reputation, or any per-submission
record.

One aggregate is treated as personal data even though it names nobody. A
per-item `observation_count` of 1 states that one person reported that product,
in that named town, on that day. Counts below
`QEEMA_MIN_DISCLOSED_OBSERVATIONS` (default 5) are withheld, and the payload
says `observation_count_disclosure: "withheld"` so that a withheld count is
never mistaken for missing data. The reasoning is in
[do-no-harm.md](do-no-harm.md).

---

## Rights, and how to honour them

### Erasure

```bash
php artisan qeema:reporter:forget --ref=<the UUID their device holds> --dry-run
php artisan qeema:reporter:forget --ref=<the UUID their device holds>
```

Deletes the reporter row, the device reference, the display name and the
reputation history, and deletes every photograph they submitted **from disk**,
not merely the reference to it. Their submissions survive with `reporter_id`
null: the prices are anonymous aggregate inputs that other people's published
figures already rest on, and destroying them would silently rewrite history for
every consumer of the index.

`--scrub-text` additionally blanks the raw text, for the case where somebody
typed identifying information into a price field. It is not the default because
raw text is the audit trail that makes a published figure traceable.

**What erasure does not do.** A submission still records that a price was
reported in this town on this afternoon. The identifier is gone; unlinkability
against an observer with independent knowledge of who was where is not
guaranteed, and this document will not claim otherwise.

### Access and portability

There is no self-service export, because there is no authentication to attach
one to — a reporter has a UUID, not an account, and honouring a request from
"whoever holds this UUID" is exactly how you would build a way to read somebody
else's data. An operator can serve a request from the admin panel after
satisfying themselves who is asking.

### Rectification

Raw submissions are immutable by design. Corrections supersede; they never
overwrite. This is a deliberate trade against rectification-by-deletion, and it
is what makes every published figure traceable to what was actually typed.

---

## Retention

`qeema:retention:enforce` runs daily on the shipped schedule and deletes
personal data past its window. Two independent windows, both in days, and
**both disabled by default**:

| Setting | What expires |
|---|---|
| `QEEMA_PHOTO_RETENTION_DAYS` | Reporter photographs — deleted from disk, and `photo_path` nulled. The submission and its price survive. |
| `QEEMA_DORMANT_REPORTER_RETENTION_DAYS` | Reporters who have submitted nothing for that long — erased with the same semantics as erasure on request, their prices kept. |

**Neither window ever touches a price observation, an index snapshot or a
published figure**, whatever it is set to. Those are anonymous aggregates that
other people's decisions already rest on; expiring them would silently rewrite
history for every consumer of the index. There is a test whose whole job is to
fail if that ever stops being true.

**Why disabled by default.** A retention job that starts deleting the moment an
operator upgrades is a data-loss incident wearing a privacy costume, and the
right period depends on your legal basis, your partners and what you told
reporters — none of which this project knows. The mechanism ships working and
scheduled; the policy is yours to set. Run it with `--dry-run` first.

**Still your responsibility.**

- Choosing the periods. Photographs are the highest-risk artefact here by a wide
  margin, and the case for holding one for years is hard to make.
- **Database backups outlive both erasure and retention.** A reporter erased
  today is still in last week's dump. Set a backup retention period too.

---

## Legal basis and jurisdiction

Not stated here, because it cannot be. Qeema is deployable anywhere by anyone,
and the applicable regime is a fact about your deployment and the people you
recruit — not about this software. Nothing in this document is legal advice.

What the software gives you: data minimisation by construction, no special
categories of data collected, purpose limitation (the data exists to compute a
published index and is used for nothing else), a working erasure path, and no
transfer of personal data to any third party — there is none to transfer,
because there is no third-party service in the runtime path at all (constraint
C1).

---

## Related

- [do-no-harm.md](do-no-harm.md) — how this platform could harm people while
  working correctly, and what is done about it
- [SECURITY.md](../SECURITY.md) — threat model, disclosure, operator duties
- [dpg-standard.md](dpg-standard.md) — indicator 7 of that self-assessment
