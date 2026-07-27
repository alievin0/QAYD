/**
 * `@qayd/config` — shared configuration for the QAYD TypeScript workspaces.
 *
 * Most consumers import the individual entrypoints directly:
 *   - `@qayd/config/eslint`          — flat ESLint config
 *   - `@qayd/config/prettier`        — Prettier config
 *   - `@qayd/config/tailwind-preset` — Tailwind preset (QAYD brass tokens)
 *   - `@qayd/config/tokens.css`      — the design-token CSS custom properties
 *   - `@qayd/config/tsconfig/base.json` — the strict TS base to `extends`
 *
 * This barrel re-exports the Tailwind preset for programmatic use.
 */
export { default as tailwindPreset, preset as qaydTailwindPreset } from "./tailwind-preset.js";
