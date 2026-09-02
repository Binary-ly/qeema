// SPDX-License-Identifier: Apache-2.0
import { expect, test, type Page } from "@playwright/test";

/**
 * The offline path.
 *
 * This is the claim the reporter app lives or dies on: a price entered with no
 * connection must survive, reach the server when signal returns, and arrive
 * exactly once. Everything else in the app is replaceable; this is not.
 *
 * These run against the composed stack, because a service worker plus an
 * IndexedDB outbox behaves differently under a dev server than in a built
 * deployment — and the built deployment is what a reviewer sees.
 */

/** Read the outbox directly, so assertions do not depend on UI wording. */
async function outbox(page: Page) {
  return page.evaluate(async () => {
    const db: IDBDatabase = await new Promise((resolve, reject) => {
      const request = indexedDB.open("qeema-reporter", 1);
      request.onsuccess = () => resolve(request.result);
      request.onerror = () => reject(request.error);
    });

    return new Promise<Array<Record<string, unknown>>>((resolve, reject) => {
      const tx = db.transaction("outbox", "readonly");
      const req = tx.objectStore("outbox").getAll();
      req.onsuccess = () => resolve(req.result ?? []);
      req.onerror = () => reject(req.error);
    });
  });
}

/** Fill the form and tap save. */
async function enterPrice(page: Page, price: string) {
  await page.selectOption("#location", { index: 1 });
  await page.locator(".picker__item").first().click();
  await page.fill("#price", price);
  await page.getByRole("button", { name: /save price|حفظ السعر/i }).click();
}

test.beforeEach(async ({ page }) => {
  await page.goto("/report?locale=en");
  await expect(page.locator(".picker__item").first()).toBeVisible();

  // The app must genuinely be opened once with a connection before it can
  // survive losing one, which is what this setup establishes. The UI says as
  // much when no cached catalogue exists ("Connect once to set up").
  //
  // Wait for the worker to be *controlling*, which is the condition every
  // test here actually depends on. It claims existing clients on activate, so
  // it takes over this page without a navigation.
  //
  // This previously waited for `registration.active` and then reloaded. Both
  // steps were wrong together: `active` is already set while the worker is
  // still in `activating`, so the wait could pass early, and the reload then
  // raced the claim it had not waited for. That is the intermittent failure
  // that went undiagnosed for weeks — reproduced locally at roughly two runs
  // in three, and gone once the reload is removed.
  await page.waitForFunction(
    () => navigator.serviceWorker.controller != null,
    null,
    {
      timeout: 20_000,
    },
  );
  await expect(page.locator(".picker__item").first()).toBeVisible();
});

test("the app shell loads and is usable with no connection", async ({
  page,
  context,
}) => {
  await context.setOffline(true);
  await page.reload();

  // The substantive claim: with the network gone, the service worker still
  // serves the shell and the cached catalogue still populates the pickers, so
  // a reporter can complete a submission.
  await expect(page.locator("#location")).toBeVisible();
  await expect(page.locator(".picker__item").first()).toBeVisible();
  await expect(page.locator("#price")).toBeEditable();
});

test("the status indicator follows connectivity", async ({ page, context }) => {
  // Asserted by driving the browser's own connectivity events rather than
  // by reading state after an offline reload: Playwright's offline emulation
  // does not reliably flip navigator.onLine for an already-loaded document,
  // and a test that depended on that would be asserting the emulation rather
  // than the application.
  await expect(page.locator(".reporter__status")).toHaveClass(/is-online/);

  await context.setOffline(true);
  await page.evaluate(() => window.dispatchEvent(new Event("offline")));
  await expect(page.locator(".reporter__status")).toHaveClass(/is-offline/);

  await context.setOffline(false);
  await page.evaluate(() => window.dispatchEvent(new Event("online")));
  await expect(page.locator(".reporter__status")).toHaveClass(/is-online/);
});

test("a price entered offline is kept and sent on reconnect", async ({
  page,
  context,
}) => {
  await context.setOffline(true);
  await page.reload();

  await enterPrice(page, "12.75");

  // Held locally, not lost.
  await expect.poll(async () => (await outbox(page)).length).toBe(1);
  const queued = await outbox(page);
  expect(queued[0].status).toBe("pending");
  expect(
    (queued[0].payload as Record<string, unknown>).client_idempotency_key,
  ).toBeTruthy();

  // Signal returns.
  await context.setOffline(false);
  await page.evaluate(() => window.dispatchEvent(new Event("online")));

  await expect
    .poll(
      async () =>
        (await outbox(page)).filter((i) => i.status === "synced").length,
      {
        timeout: 20_000,
      },
    )
    .toBe(1);
});

test("several offline entries all survive and sync together", async ({
  page,
  context,
}) => {
  await context.setOffline(true);
  await page.reload();

  for (const price of ["5.50", "9.25", "31.00"]) {
    await enterPrice(page, price);
  }

  await expect.poll(async () => (await outbox(page)).length).toBe(3);

  await context.setOffline(false);
  await page.evaluate(() => window.dispatchEvent(new Event("online")));

  await expect
    .poll(
      async () =>
        (await outbox(page)).filter((i) => i.status === "synced").length,
      {
        timeout: 30_000,
      },
    )
    .toBe(3);
});

test("replaying the queue does not send the same price twice", async ({
  page,
  context,
}) => {
  // The property that makes a flaky connection harmless rather than a silent
  // distortion of a published figure.
  await context.setOffline(true);
  await page.reload();
  await enterPrice(page, "77.25");

  const payload = (await outbox(page))[0].payload as Record<string, unknown>;

  // The app makes the first send; the fetch below is the replay.
  //
  // This used to clear the outbox and then post twice by hand, so that the
  // app would not be a third sender racing the two. That still raced, one CI
  // run in ten. `flush()` writes each item back with `put()`, and `put()`
  // *inserts* when the key is gone — so a flush already iterating the item
  // resurrected the row the test had just deleted, and the wait for an empty
  // queue never finished. Deleting rows underneath a running flush was the
  // whole mistake, and nothing here deletes anything now.
  //
  // It is also the more honest sequence: what retries a submission in
  // production is the queue, not a test.
  await context.setOffline(false);
  await page.evaluate(() => window.dispatchEvent(new Event("online")));

  await expect
    .poll(async () => (await outbox(page))[0]?.status, { timeout: 30_000 })
    .toBe("synced");

  const accepted = (await outbox(page))[0] as { serverId?: string };
  expect(accepted.serverId).toBeTruthy();

  // Send the very same payload again, exactly as a retry would.
  const replay = await page.evaluate(async (body) => {
    const response = await fetch("/api/v1/submissions", {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        Accept: "application/json",
        "X-CSRF-TOKEN":
          document
            .querySelector("meta[name=csrf-token]")
            ?.getAttribute("content") ?? "",
      },
      body: JSON.stringify(body),
    });

    return { status: response.status, body: await response.json() };
  }, payload);

  // The replay is acknowledged, not rejected: a 4xx would leave the item
  // stuck in the queue being retried forever.
  expect(replay.status).toBe(200);
  expect(replay.body.status).toBe("duplicate");
  expect(replay.body.id).toBe(accepted.serverId);
});

test("the reporter identity is stable across reloads", async ({ page }) => {
  const before = await page.evaluate(() =>
    localStorage.getItem("qeema.reporter_ref"),
  );
  await page.reload();
  const after = await page.evaluate(() =>
    localStorage.getItem("qeema.reporter_ref"),
  );

  expect(before).toBeTruthy();
  expect(after).toBe(before);
});

test("the interface renders right-to-left in Arabic", async ({ page }) => {
  await page.goto("/report?locale=ar");

  await expect(page.locator("html")).toHaveAttribute("dir", "rtl");
  await expect(page.locator("html")).toHaveAttribute("lang", "ar");

  // The price field stays left-to-right inside an RTL layout: a number is not
  // bidirectional text, and mirroring it would render prices wrongly.
  await expect(page.locator("#price")).toHaveCSS("direction", "ltr");
});

test("the app is installable", async ({ page, request }) => {
  const manifestHref = await page
    .locator("link[rel=manifest]")
    .getAttribute("href");
  expect(manifestHref).toBe("/manifest.webmanifest");

  const manifest = await request.get(manifestHref!);
  expect(manifest.ok()).toBeTruthy();

  const body = await manifest.json();
  expect(body.start_url).toBe("/report");
  expect(body.display).toBe("standalone");
});
