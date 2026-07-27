/**
 * Shared Prettier config for the QAYD TypeScript workspaces. Consumed as `@qayd/config/prettier`.
 * Mirrors the settings already in use by `apps/web` (semicolons, double quotes, trailing commas).
 *
 * @type {import("prettier").Config}
 */
export default {
  semi: true,
  singleQuote: false,
  trailingComma: "all",
  printWidth: 100,
  tabWidth: 2,
};
