import type { Config } from "tailwindcss";

/**
 * QAYD Tailwind preset — the brass / warm-ink design tokens expressed as Tailwind theme values.
 * Consumed as `@qayd/config/tailwind-preset`; the CSS custom properties it references live in
 * `@qayd/config/tokens.css` (import that once in the app's global stylesheet).
 *
 * Authored from docs/design-system/foundations/{COLORS,TYPOGRAPHY_SCALE,SPACING_SCALE,RADIUS_SCALE,SHADOW_SCALE}.md.
 *
 *   - Raw scale utilities (`bg-ink-2`, `text-accent`, `text-positive`) resolve to the literal-hex
 *     `--qayd-*` variables — solid colors, the ~95% case.
 *   - shadcn semantic utilities (`bg-background`, `bg-primary`, `border-input`, `ring-ring`) resolve to
 *     HSL-triplet variables via `hsl(var(--x) / <alpha-value>)`, so opacity modifiers work.
 *   - Dark mode is class-based (`.dark`), matching the token file.
 */
const preset = {
  darkMode: "class",
  theme: {
    extend: {
      colors: {
        // — Raw QAYD scale (solid, hex-backed) —
        ink: {
          1: "var(--qayd-ink-1)",
          2: "var(--qayd-ink-2)",
          3: "var(--qayd-ink-3)",
          4: "var(--qayd-ink-4)",
          5: "var(--qayd-ink-5)",
          6: "var(--qayd-ink-6)",
          7: "var(--qayd-ink-7)",
          8: "var(--qayd-ink-8)",
          9: "var(--qayd-ink-9)",
          10: "var(--qayd-ink-10)",
          11: "var(--qayd-ink-11)",
          12: "var(--qayd-ink-12)",
        },
        accent: {
          subtle: "var(--qayd-accent-subtle)",
          DEFAULT: "var(--qayd-accent)",
          strong: "var(--qayd-accent-strong)",
          on: "var(--qayd-accent-on)",
        },
        positive: {
          DEFAULT: "var(--qayd-positive)",
          subtle: "var(--qayd-positive-subtle)",
        },
        negative: {
          DEFAULT: "var(--qayd-negative)",
          subtle: "var(--qayd-negative-subtle)",
        },
        warning: {
          DEFAULT: "var(--qayd-warning)",
          subtle: "var(--qayd-warning-subtle)",
        },

        // — shadcn semantic aliases (HSL, opacity-friendly) —
        background: "hsl(var(--background) / <alpha-value>)",
        foreground: "hsl(var(--foreground) / <alpha-value>)",
        card: {
          DEFAULT: "hsl(var(--card) / <alpha-value>)",
          foreground: "hsl(var(--card-foreground) / <alpha-value>)",
        },
        popover: {
          DEFAULT: "hsl(var(--popover) / <alpha-value>)",
          foreground: "hsl(var(--popover-foreground) / <alpha-value>)",
        },
        primary: {
          DEFAULT: "hsl(var(--primary) / <alpha-value>)",
          foreground: "hsl(var(--primary-foreground) / <alpha-value>)",
        },
        secondary: {
          DEFAULT: "hsl(var(--secondary) / <alpha-value>)",
          foreground: "hsl(var(--secondary-foreground) / <alpha-value>)",
        },
        muted: {
          DEFAULT: "hsl(var(--muted) / <alpha-value>)",
          foreground: "hsl(var(--muted-foreground) / <alpha-value>)",
        },
        // NB: shadcn's `--accent` is a NEUTRAL hover tint (COLORS.md "The shadcn --accent trap"); the
        // brand brass is the `accent` scale above. Exposed as `muted-hover` to avoid clobbering brass.
        "muted-hover": {
          DEFAULT: "hsl(var(--accent) / <alpha-value>)",
          foreground: "hsl(var(--accent-foreground) / <alpha-value>)",
        },
        destructive: {
          DEFAULT: "hsl(var(--destructive) / <alpha-value>)",
          foreground: "hsl(var(--destructive-foreground) / <alpha-value>)",
        },
        border: "hsl(var(--border) / <alpha-value>)",
        input: "hsl(var(--input) / <alpha-value>)",
        ring: "hsl(var(--ring) / <alpha-value>)",
      },

      borderRadius: {
        sm: "var(--radius-sm)", // 4px
        md: "var(--radius-md)", // 6px
        lg: "var(--radius-lg)", // 8px — the workhorse
        xl: "var(--radius-xl)", // 10px — the rectangular ceiling
      },

      boxShadow: {
        xs: "var(--shadow-xs)",
        sm: "var(--shadow-sm)",
        md: "var(--shadow-md)",
        lg: "var(--shadow-lg)",
      },

      fontFamily: {
        display: ["var(--font-display)", "var(--font-arabic)", "ui-sans-serif", "system-ui"],
        text: ["var(--font-text)", "var(--font-arabic)", "ui-sans-serif", "system-ui"],
        arabic: ["var(--font-arabic)", "ui-sans-serif", "system-ui"],
        mono: ["var(--font-mono)", "ui-monospace", "SFMono-Regular", "monospace"],
      },

      // Closed, named type scale (TYPOGRAPHY_SCALE.md). Tuple = [size, { lineHeight, letterSpacing, fontWeight }].
      fontSize: {
        "display-2xl": [
          "clamp(2.75rem, 2rem + 3vw, 4rem)",
          { lineHeight: "1.05", letterSpacing: "-0.02em", fontWeight: "600" },
        ],
        "display-xl": [
          "2.5rem",
          { lineHeight: "1.10", letterSpacing: "-0.015em", fontWeight: "600" },
        ],
        "display-lg": ["2rem", { lineHeight: "1.15", letterSpacing: "-0.01em", fontWeight: "600" }],
        "display-md": [
          "1.5rem",
          { lineHeight: "1.20", letterSpacing: "-0.005em", fontWeight: "600" },
        ],
        "display-sm": ["1.25rem", { lineHeight: "1.25", letterSpacing: "0", fontWeight: "600" }],
        "numeral-hero": [
          "clamp(2rem, 1.5rem + 2vw, 3rem)",
          { lineHeight: "1.10", letterSpacing: "-0.01em", fontWeight: "600" },
        ],
        "text-lg": ["1.125rem", { lineHeight: "1.5", letterSpacing: "0", fontWeight: "400" }],
        "text-md": ["1rem", { lineHeight: "1.5", letterSpacing: "0", fontWeight: "400" }],
        "text-sm": ["0.875rem", { lineHeight: "1.43", letterSpacing: "0", fontWeight: "400" }],
        "text-xs": ["0.75rem", { lineHeight: "1.4", letterSpacing: "0.01em", fontWeight: "500" }],
        "numeral-table": [
          "0.875rem",
          { lineHeight: "1.43", letterSpacing: "0", fontWeight: "400" },
        ],
        "code-sm": ["0.8125rem", { lineHeight: "1.4", letterSpacing: "0", fontWeight: "400" }],
      },
    },
  },
} satisfies Partial<Config>;

export default preset;
export { preset };
