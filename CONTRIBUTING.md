# Contributing to Qeema

Thank you for considering a contribution. Qeema publishes affordability data that
people may use to make decisions about feeding children in a crisis. That raises
the bar on correctness — particularly anywhere a number reaches the public API.

By contributing you agree that your contribution is licensed under
[Apache-2.0](LICENSE).

---

## Ground rules that are not negotiable

These come from the platform's founding constraints. A pull request that breaks
one will not be merged, however good it otherwise is.

1. **No proprietary or paid service in the runtime path.** No hosted inference
   API, no commercial geocoder, no paid map tiles, no closed vector database.
   Every dependency must be OSI-licensed. A third party must be able to redeploy
   the entire stack with no commercial accounts. `make licenses` regenerates the
   evidence; CI checks it.
2. **Nothing country-specific in code.** Country, currency, locations, basket
   composition, locales and FX source live in `countries/*.yaml` and reach the
   application through the database. `make check-country-agnostic` enforces this.
3. **`docker compose up` must stay a single command.** If your change needs a
   manual step to work on a clean machine, it is not finished.
4. **Imputed values are never presented as observed.** Any value the system
   estimated rather than observed carries `is_imputed: true` through every layer
   — estimator, database, API and UI. There is no acceptable reason to drop that
   flag anywhere.
5. **Raw submissions are immutable.** Corrections supersede; they never
   overwrite. The provenance chain from a published number back to the original
   raw text must stay intact.
6. **Coverage stays at or above 80%** for both services. The gate runs in CI.

---

## Getting set up

You need Docker, and that is genuinely all you need to run the system:

```bash
git clone https://github.com/<org>/qeema.git
cd qeema
make demo
```

For development on the host you additionally need PHP 8.4, Composer, Node 22 and
Python 3.11:

```bash
# PHP side
cd api && composer install && cp .env.example .env && php artisan key:generate

# Python side
cd ml && uv venv --python 3.11 .venv && uv pip install -e ".[dev]"

# a database for the test suite
createdb qeema_test
psql -d qeema_test -c "CREATE EXTENSION vector; CREATE EXTENSION pg_trgm;"
```

Tests require **real PostgreSQL** with `pgvector` and `pg_trgm`. SQLite cannot
provide either, and the matcher is meaningless without them.

For PHP line coverage you need `pcov`. On macOS with Homebrew PHP this needs the
pcre2 headers:

```bash
brew install pcre2
CPPFLAGS="-I$(brew --prefix pcre2)/include" pecl install pcov
```

---

## The development loop

```bash
make verify      # every CI gate except the image build and end-to-end
make test-php    # Pest with the 80% coverage gate
make test-ml     # pytest with the 80% coverage gate
make fix         # auto-fix formatting in both services
```

Run `make verify` before opening a pull request. It runs both linters, PHPStan,
mypy, both suites with their coverage gates and the constraint checks — every gate
CI applies except two, which need Docker or live only in the workflow file: the
compose job that builds the images and runs the end-to-end suite (`make test-e2e`
against a running stack), and a grep for secret-shaped values.

That gap used to be wider. `make verify` omitted mypy and the OpenAPI drift check
while both were failing the pipeline, so a green local run genuinely could go red
in CI.

---

## Writing tests

Write the test alongside the code, not after the feature. The gate is a floor,
not a target — 80% line coverage of a statistical pipeline proves very little on
its own.

What we actually care about:

- **The estimator and index maths** get tests with known inputs and hand-checked
  expected outputs, not just "it returned a number".
- **The provenance chain** gets an end-to-end test: raw submission in, published
  index number out, and back again.
- **Corrections** get a test proving the recomputation ripples correctly.
- **The ML boundary** is covered by contract tests in `contracts/`. PHP tests
  validate the fake client's responses against those schemas; Python tests
  validate the real service's responses against the same files. If you change a
  request or response shape, change the schema — that is what stops the fake
  drifting from reality.
- **Degradation** is tested: with the ML service down, the system must serve
  observed data and report reduced coverage, not return a 500.

---

## Commit and pull request style

- One logical change per commit; a clear, single-sentence subject in the
  imperative mood.
- Reference the phase or issue where it helps a reader.
- In the PR description, say what you verified and how. "Tests pass" is less
  useful than "recomputation after a corrected observation now updates the three
  affected snapshots; added a test that fails on the previous behaviour".
- If you changed anything statistical, say what the numbers did before and after.

---

## Adding a country

You should not need to touch application code. Add `countries/<code>.yaml`,
following `countries/ly.yaml`, then:

```bash
make reseed
```

If you find yourself needing a code change to support a country, that is a bug in
the abstraction — please open an issue describing what was not configurable.

---

## Reporting a security issue

Please do not open a public issue for a vulnerability. See
[SECURITY.md](SECURITY.md) for private disclosure.

Two areas deserve particular care, because the platform is publicly readable by
design: the unauthenticated API surface, and user-submitted photographs, which
can carry location metadata and identifiable people.

---

## Code of conduct

This project follows the [Contributor Covenant](CODE_OF_CONDUCT.md).
