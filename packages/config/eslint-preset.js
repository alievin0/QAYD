// @ts-check
/**
 * Shared flat ESLint config for every QAYD TypeScript workspace package.
 * Consumed as `@qayd/config/eslint`. Non-type-checked (fast, no per-package project
 * service needed) — the strict compile-time contract is TypeScript's job (`tsc`).
 */
import js from "@eslint/js";
import tseslint from "typescript-eslint";
import prettier from "eslint-config-prettier";

export default tseslint.config(
  { ignores: ["dist/**", "node_modules/**", "coverage/**"] },
  js.configs.recommended,
  ...tseslint.configs.recommended,
  {
    // TypeScript already resolves symbols; `no-undef` only produces false positives
    // on ambient globals (fetch, document, localStorage, …) in TS/TSX files.
    files: ["**/*.{ts,tsx,mts,cts}"],
    rules: {
      "no-undef": "off",
      "@typescript-eslint/consistent-type-imports": [
        "error",
        { prefer: "type-imports", fixStyle: "inline-type-imports" },
      ],
      "@typescript-eslint/no-unused-vars": [
        "error",
        { argsIgnorePattern: "^_", varsIgnorePattern: "^_" },
      ],
    },
  },
  // Prettier last: turn off every stylistic rule that would fight the formatter.
  prettier,
);
