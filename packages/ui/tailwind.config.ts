import type { Config } from "tailwindcss";

import preset from "@qayd/config/tailwind-preset";

/**
 * Tailwind config for the `@qayd/ui` package itself (e.g. a future Storybook / visual build). The
 * brass design tokens come entirely from the shared `@qayd/config` preset — this file only points
 * Tailwind at the component sources. Consuming apps do the same: add this preset + include
 * `@qayd/ui`'s files in their own `content` globs.
 */
const config: Config = {
  presets: [preset],
  content: ["./src/**/*.{ts,tsx}"],
};

export default config;
