import { type Locale } from "@qayd/shared";

import { en, type Dictionary } from "./en";
import { ar } from "./ar";

/**
 * The shell i18n core: the `Locale → Dictionary` map, a dot-path translator with `{var}` interpolation,
 * and a recursive key flattener shared by the `i18n:check` parity gate. Framework-free so it can be used
 * from a server component, a client component, and the CI script alike.
 */

export type { Dictionary } from "./en";

/** Every supported locale's dictionary. Keys are guaranteed identical by the `Dictionary` type + gate. */
export const dictionaries: Record<Locale, Dictionary> = { en, ar };

export function getDictionary(locale: Locale): Dictionary {
  return dictionaries[locale];
}

/** A single `{var}` substitution map for message interpolation. */
export type TranslateVars = Record<string, string | number>;

/** Resolve a dot-path (`"nav.dashboard"`) against a dictionary; returns the key itself if unresolved. */
function resolvePath(dict: Dictionary, path: string): string {
  const value = path.split(".").reduce<unknown>((node, segment) => {
    if (node !== null && typeof node === "object" && segment in node) {
      return (node as Record<string, unknown>)[segment];
    }
    return undefined;
  }, dict);
  return typeof value === "string" ? value : path;
}

/** Interpolate `{name}` tokens in a resolved message. */
function interpolate(message: string, vars?: TranslateVars): string {
  if (!vars) return message;
  return message.replace(/\{(\w+)\}/g, (match, token: string) =>
    token in vars ? String(vars[token]) : match,
  );
}

/** Build a bound translator for a locale: `t("nav.dashboard")`, `t("dashboard.welcome", { company })`. */
export function createTranslator(locale: Locale) {
  const dict = getDictionary(locale);
  return (key: string, vars?: TranslateVars): string =>
    interpolate(resolvePath(dict, key), vars);
}

export type Translator = ReturnType<typeof createTranslator>;

/** Flatten a nested dictionary into its set of leaf dot-paths — the unit the parity gate compares. */
export function flattenKeys(dict: unknown, prefix = ""): string[] {
  if (dict === null || typeof dict !== "object") return prefix ? [prefix] : [];
  return Object.entries(dict as Record<string, unknown>).flatMap(
    ([key, value]) => {
      const path = prefix ? `${prefix}.${key}` : key;
      return typeof value === "object" && value !== null
        ? flattenKeys(value, path)
        : [path];
    },
  );
}
