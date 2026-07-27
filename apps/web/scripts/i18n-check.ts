/**
 * `i18n:check` — the key-parity gate. Fails (exit 1) if any leaf key exists in one shell dictionary but
 * not the other, so an English-only (or Arabic-only) string can never accrete. Run via `tsx` so it reads
 * the same `.ts` dictionaries the app ships, with no build step. The `Dictionary` type already enforces
 * parity at compile time; this is the runtime belt-and-suspenders the sprint's DoD names explicitly.
 */
import { en } from "../lib/i18n/en";
import { ar } from "../lib/i18n/ar";
import { flattenKeys } from "../lib/i18n/index";

const LOCALES = { en, ar } as const;

function diff(a: string[], b: string[]): string[] {
  const setB = new Set(b);
  return a.filter((key) => !setB.has(key)).sort();
}

const keys = Object.fromEntries(
  Object.entries(LOCALES).map(([locale, dict]) => [locale, flattenKeys(dict)]),
) as Record<keyof typeof LOCALES, string[]>;

const missingInAr = diff(keys.en, keys.ar);
const missingInEn = diff(keys.ar, keys.en);

if (missingInAr.length === 0 && missingInEn.length === 0) {
  console.log(`i18n:check ✓  ${keys.en.length} keys, en/ar in parity.`);
  process.exit(0);
}

console.error("i18n:check ✗  dictionary key parity broken.\n");
if (missingInAr.length > 0) {
  console.error(
    `Missing in ar (present in en):\n  ${missingInAr.join("\n  ")}\n`,
  );
}
if (missingInEn.length > 0) {
  console.error(
    `Missing in en (present in ar):\n  ${missingInEn.join("\n  ")}\n`,
  );
}
process.exit(1);
