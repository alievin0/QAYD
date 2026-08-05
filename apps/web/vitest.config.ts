import react from "@vitejs/plugin-react";
import { defineConfig } from "vitest/config";

export default defineConfig({
  plugins: [react()],
  test: {
    environment: "jsdom",
    globals: true,
    setupFiles: ["./vitest.setup.ts"],

    /*
     * TD-28. Several render tests wait on React effects under jsdom, and at vitest's 5s default they
     * fail on a loaded machine and pass on a quiet one — observed three times now, most recently with
     * six unrelated files red at load average 30 and every one of them green on re-run.
     *
     * Nothing here is slow because of a defect: the work is jsdom rendering, and it takes as long as the
     * machine allows. A suite that goes green when re-run is worse than a slow one, because it teaches
     * everyone to re-run instead of read — and the day a real failure appears, it gets the same shrug.
     * A CI runner is more contended than a laptop, so the margin only narrows from here.
     */
    testTimeout: 20_000,
    hookTimeout: 20_000,
    include: [
      "app/**/*.test.{ts,tsx}",
      "components/**/*.test.{ts,tsx}",
      "lib/**/*.test.{ts,tsx}",
      "middleware.test.ts",
    ],
  },
});
