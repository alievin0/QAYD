import { expect, test } from "@playwright/test";

/**
 * The harness itself (SPRINT_03 Phase 0).
 *
 * One spec, deliberately. Its job is not to test the login screen — Vitest already does that, faster and
 * with better failure messages — but to prove the end-to-end apparatus works before Sprint 3's screens
 * depend on it: a production Next build boots, the stub upstream answers, the page renders in a real
 * browser, and client-side behaviour survives hydration.
 *
 * The direction assertion is the one genuinely worth having here. Whether `dir` flips to `rtl` depends on
 * a client effect writing to `document.documentElement` after hydration, which jsdom can only
 * approximate — a real browser is the only place that claim can actually be settled.
 */
test.describe("e2e harness", () => {
  test("serves the sign-in page from a production build", async ({ page }) => {
    await page.goto("/login");

    await expect(page.getByRole("heading", { name: /sign in/i })).toBeVisible();
    await expect(page.getByLabel(/email/i)).toBeVisible();
    await expect(page.getByLabel(/password/i)).toBeVisible();
  });

  test("gates an authenticated route behind the session cookie", async ({
    page,
  }) => {
    // No session, so the middleware should send this to /login rather than render the shell. This is the
    // auth gate observed from outside the app, which is the only place it means anything.
    await page.goto("/accounting/accounts");

    await expect(page).toHaveURL(/\/login/);
  });

  test("mirrors the document to RTL when Arabic is chosen", async ({
    page,
  }) => {
    await page.goto("/login");

    await expect(page.locator("html")).toHaveAttribute("dir", "ltr");

    await page.getByRole("combobox").first().click();
    await page.getByRole("option", { name: "العربية" }).click();

    // The claim only a real browser can settle: a client effect wrote to the document element after
    // hydration, and the whole layout mirrors as a result.
    await expect(page.locator("html")).toHaveAttribute("dir", "rtl");
    await expect(page.locator("html")).toHaveAttribute("lang", "ar");
  });
});
