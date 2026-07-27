/**
 * Re-export of the shared QAYD Tailwind preset, so an app can pull the tokens through the UI package:
 *
 *   import { tailwindPreset } from "@qayd/ui/tailwind";
 *
 * This keeps `@qayd/ui`'s single hard dependency on `@qayd/config` (the design tokens) explicit.
 */
export { default as tailwindPreset } from "@qayd/config/tailwind-preset";
