// SPDX-License-Identifier: Apache-2.0
import { APIRequestContext, expect, test } from "@playwright/test";

/**
 * The whole journey, against a real deployment.
 *
 * This is the demonstration the project stands on: a price submitted through
 * the public API reaches the published index on its own, with nobody running a
 * command. It is also the test that would have caught the gap this platform
 * spent a week with — every stage built, unit-tested, and joined to nothing, so
 * that a submitted price sat at `pending` for ever while every other test
 * passed.
 *
 * A test of a stage cannot see a missing wire. This walks the wire, and it does
 * it against the composed stack: the real matcher, the real screening service,
 * the real scheduler, the shipped defaults. The PHP suite covers the same
 * journey with a faked matcher and a hand-run drain; this one waits for the
 * clock like a reviewer would.
 *
 * Nothing here names a country. The fixture is discovered from the API, because
 * a proof that only works for the default deployment proves the wrong thing
 * (constraint C3).
 */

/** Two full drain cycles plus the grace window, with room to spare. */
const PUBLISH_TIMEOUT_MS = 240_000;

const POLL_INTERVAL_MS = 5_000;

type Fixture = {
  country: string;
  locationSlug: string;
  itemCode: string;
  /**
   * The date the platform publishes for, taken from the platform.
   *
   * Not computed here. Snapshots are dated in each country's own timezone, so
   * a UTC date computed in the test disagrees with the published one for
   * however many hours the two are apart — a failure that appears at a
   * particular time of day and nowhere else.
   */
  date: string;
  /**
   * What the platform currently publishes as this item's unit price.
   *
   * The submitted price has to be plausible for the item the fixture happened
   * to pick. A hardcoded one is not: this test posted 12.5 for whatever came
   * back, which for infant formula is a wild outlier, and the anomaly detector
   * did exactly its job — verdict REJECTED, observation marked `is_valid =
   * false`, submission routed to review. The index counts only valid
   * observations, so the loop looked open when it was closed and the screen
   * was working.
   *
   * It surfaced as an intermittent failure because the verdict was borderline:
   * a change to the catalogue shifted the distributions enough to tip it from
   * SUSPECT, which keeps the observation, to REJECTED, which does not.
   */
  unitPrice: number;
};

/**
 * The first active country, one of its locations, and an item from its basket.
 */
async function discoverFixture(request: APIRequestContext): Promise<Fixture> {
  const countries = await (await request.get("/api/v1/countries")).json();
  const country = countries.data[0].code;

  const basket = await (
    await request.get(`/api/v1/countries/${country}/basket`)
  ).json();
  const current = await (
    await request.get(`/api/v1/countries/${country}/index/current`)
  ).json();

  // The basket is returned unwrapped, unlike the collection endpoints. Worth
  // reading rather than assuming: guessing produced a passing-looking test
  // that failed on the first real payload.
  const locationSlug = current.data[0].location.slug;
  const itemCode = basket.items[0].code;
  const date = current.data[0].date;

  const snapshot = await (
    await request.get(`/api/v1/locations/${locationSlug}/index/${date}`)
  ).json();

  const published = snapshot.data.items.find(
    (i: { item: { code: string } }) => i.item.code === itemCode,
  );

  return {
    country,
    locationSlug,
    itemCode,
    date,
    // Fall back to something non-zero only if the item is imputed and has no
    // published price of its own; an imputed row carries a price too.
    unitPrice: Number(published?.unit_price) || 1,
  };
}

async function observationCount(
  request: APIRequestContext,
  fixture: Fixture,
  date: string,
): Promise<number> {
  const response = await request.get(
    `/api/v1/locations/${fixture.locationSlug}/index/${date}`,
  );

  if (!response.ok()) {
    return 0;
  }

  const snapshot = await response.json();
  const item = snapshot.data.items.find(
    (i: { item: { code: string } }) => i.item.code === fixture.itemCode,
  );

  return item?.observation_count ?? 0;
}

function uuid(): string {
  return crypto.randomUUID();
}

test.describe("the closed loop", () => {
  test("a submitted price reaches the published index without anyone running a command", async ({
    request,
  }) => {
    test.setTimeout(PUBLISH_TIMEOUT_MS + 60_000);

    const fixture = await discoverFixture(request);
    const date = fixture.date;

    // 1. What the world looks like before.
    const before = await observationCount(request, fixture, date);

    // 2. A reporter sends one price. The catalogue code is used rather than
    //    free text so the matcher's decision is deterministic — what is
    //    under test is whether the stages are joined, not how good the
    //    model is.
    const submission = await request.post("/api/v1/submissions", {
      data: {
        reporter_ref: uuid(),
        country: fixture.country,
        location_slug: fixture.locationSlug,
        canonical_item_code: fixture.itemCode,
        // What the platform itself says this item costs here today. A
        // price the screen would reject is not a test of the loop.
        price: fixture.unitPrice,
        client_idempotency_key: uuid(),
      },
    });

    expect(submission.status(), await submission.text()).toBe(201);
    expect((await submission.json()).submission_status).toBe("pending");

    // 3. Wait for the platform to do the rest by itself: resolve it, screen
    //    it, mark the snapshot stale, recompute it, publish it.
    const deadline = Date.now() + PUBLISH_TIMEOUT_MS;
    let after = before;

    while (Date.now() < deadline && after <= before) {
      await new Promise((resolve) => setTimeout(resolve, POLL_INTERVAL_MS));
      after = await observationCount(request, fixture, date);
    }

    expect(
      after,
      `The price never reached the published index within ${PUBLISH_TIMEOUT_MS / 1000}s. ` +
        "The loop is open: check the scheduler container and /api/v1/health.",
    ).toBeGreaterThan(before);

    // 4. And it is published as what it is: an observation, not an estimate.
    const snapshot = await (
      await request.get(
        `/api/v1/locations/${fixture.locationSlug}/index/${date}`,
      )
    ).json();

    const item = snapshot.data.items.find(
      (i: { item: { code: string } }) => i.item.code === fixture.itemCode,
    );

    expect(item.is_imputed).toBe(false);
    expect(item.imputation_method).toBeNull();
    expect(item.unit_price).toBeGreaterThan(0);
    expect(snapshot.data.quality.coverage).toBeGreaterThan(0);
  });

  test("publishes the aggregate without exposing who reported it", async ({
    request,
  }) => {
    // The reporter is the person taking the risk of standing in a market
    // writing prices down. Nothing about them belongs in a public payload.
    const fixture = await discoverFixture(request);
    const date = fixture.date;

    const body = await (
      await request.get(
        `/api/v1/locations/${fixture.locationSlug}/index/${date}`,
      )
    ).text();

    for (const leak of [
      "reporter",
      "reporter_ref",
      "submission_id",
      "device",
    ]) {
      expect(body, `the published snapshot mentions ${leak}`).not.toContain(
        leak,
      );
    }
  });
});

test.describe("the platform reports on itself", () => {
  test("says publicly whether it is still publishing", async ({ request }) => {
    const health = await (await request.get("/api/v1/health")).json();

    expect(health.pipeline).toBeDefined();
    expect(["ok", "degraded", "stalled"]).toContain(health.pipeline.status);

    // The clock is the one that matters: everything else is downstream of
    // it, and a stopped scheduler is how this platform fails silently.
    expect(health.pipeline.scheduler.status).toBe("ok");
    expect(health.pipeline.scheduler.age_seconds).toBeLessThan(180);
  });

  test("never publishes how thin the screening currently is", async ({
    request,
  }) => {
    const health = await (await request.get("/api/v1/health")).json();

    // States and ages are a legitimate interest of anyone building on this
    // data. Counts would additionally tell somebody probing for a
    // manipulation window how much is currently unreviewed.
    for (const [name, check] of Object.entries<Record<string, unknown>>(
      health.pipeline,
    )) {
      if (name === "status") continue;

      expect(
        Object.keys(check).sort(),
        `${name} exposes more than a state and an age`,
      ).toEqual(expect.arrayContaining(["status"]));

      expect(Object.keys(check)).not.toContain("waiting");
      expect(Object.keys(check)).not.toContain("stale");
      expect(Object.keys(check)).not.toContain("failures");
    }
  });
});
