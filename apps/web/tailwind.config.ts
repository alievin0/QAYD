import type { Config } from "tailwindcss";
import qaydPreset from "@qayd/config/tailwind-preset";

/**
 * Tailwind for apps/web. All theme values (the brass `accent`, the warm-ink `ink-1…12` neutrals, the
 * semantic `background`/`primary`/`border`/`ring` HSL aliases, radii, shadows, and the closed type
 * scale) come from the shared `@qayd/config` preset — this file only declares what to scan. The `@qayd/ui`
 * source is included in `content` so the class names its compiled primitives emit are generated here.
 */
const config: Config = {
  presets: [qaydPreset],
  content: [
    "./app/**/*.{ts,tsx}",
    "./components/**/*.{ts,tsx}",
    "./lib/**/*.{ts,tsx}",
    "../../packages/ui/src/**/*.{ts,tsx}",
  ],
};

export default config;
