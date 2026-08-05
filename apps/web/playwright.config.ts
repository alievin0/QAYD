import { defineConfig, devices } from "@playwright/test";

/**
 * End-to-end configuration (SPRINT_03 Phase 0).
 *
 * Playwright is **additive**. The Vitest suites still own component behaviour, the balance arithmetic,
 * the i18n dictionaries and every render variant, and none of that moves here — an end-to-end test is
 * slower, harder to read on failure, and worse at pinning a specific rule, so it earns its place only
 * where nothing cheaper can look. What only this can see is a whole page assembling: a server component
 * fetching through the BFF, hydrating, and behaving in a real browser.
 *
 * Two servers start together. The stub API stands in for Laravel so runs are deterministic and need no
 * database; Next runs `start` against a production build rather than `dev`, because dev-mode compilation
 * races are exactly the flake that teaches people to re-run a suite instead of read it.
 *
 * Chromium only, one worker, no retries. Retries hide flakes, and a flaky end-to-end test is worth less
 * than none — it costs attention every run and is eventually ignored.
 */
const STUB_API_PORT = 8099;
const WEB_PORT = 3100;
const API_BASE_URL = `http://127.0.0.1:${STUB_API_PORT}/api/v1`;

export default defineConfig({
  testDir: "./e2e",
  testMatch: /.*\.spec\.ts/,

  // A spec needing longer than this is usually waiting on something it should be asserting instead.
  timeout: 30_000,
  expect: { timeout: 5_000 },

  fullyParallel: false,
  workers: 1,
  retries: 0,
  forbidOnly: !!process.env.CI,

  reporter: process.env.CI ? [["github"], ["list"]] : [["list"]],

  use: {
    baseURL: `http://127.0.0.1:${WEB_PORT}`,
    trace: "retain-on-failure",
    screenshot: "only-on-failure",
    video: "off",
  },

  projects: [{ name: "chromium", use: { ...devices["Desktop Chrome"] } }],

  webServer: [
    {
      command: "node e2e/support/stub-api.mjs",
      port: STUB_API_PORT,
      reuseExistingServer: !process.env.CI,
      stdout: "ignore",
      stderr: "pipe",
    },
    {
      command: `pnpm exec next start -p ${WEB_PORT}`,
      port: WEB_PORT,
      reuseExistingServer: !process.env.CI,
      stdout: "ignore",
      stderr: "pipe",
      env: {
        // Read at runtime by the server components and BFF handlers — which is where every API call
        // this app makes actually originates.
        QAYD_API_BASE_URL: API_BASE_URL,
        API_BASE_URL,
      },
    },
  ],
});
