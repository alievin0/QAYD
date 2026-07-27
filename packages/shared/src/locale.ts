/**
 * The supported QAYD locales and their reading direction. English (LTR) and Arabic (RTL, full mirror)
 * are the two Sprint-1 locales; the backend defaults a new user/company to Arabic
 * (RegisterController / CreateCompanyController), so `DEFAULT_LOCALE` matches.
 */

export const LOCALES = ["en", "ar"] as const;

export type Locale = (typeof LOCALES)[number];

/** Matches the backend's fallback locale for registration and company creation. */
export const DEFAULT_LOCALE: Locale = "ar";

export type TextDirection = "ltr" | "rtl";

export function isLocale(value: unknown): value is Locale {
  return typeof value === "string" && (LOCALES as readonly string[]).includes(value);
}

/** The document/text direction for a locale — Arabic mirrors, English does not. */
export function textDirection(locale: Locale): TextDirection {
  return locale === "ar" ? "rtl" : "ltr";
}

/** Coerce an untrusted value to a supported locale, falling back to the default. */
export function toLocale(value: unknown, fallback: Locale = DEFAULT_LOCALE): Locale {
  return isLocale(value) ? value : fallback;
}
