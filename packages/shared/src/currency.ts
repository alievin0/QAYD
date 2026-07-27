/**
 * Currency formatting for QAYD. The Gulf three-decimal currencies (KWD/BHD/OMR) default to three
 * fraction digits ("the fils"), every other currency to two. Amounts are always rendered with
 * **Latin (Western) digits** and comma grouping — even in an Arabic UI — and the currency is shown as
 * its ISO **code**, never a symbol (docs/design-system/foundations/TYPOGRAPHY_SCALE.md).
 *
 * This is the framework-free numeric core; the app's `<Amount>` primitive (tabular figures, `dir="ltr"`,
 * red/green polarity) is layered on top of it.
 */

/** Currencies whose minor unit is 3 decimal places. Everything else is 2. */
export const THREE_DECIMAL_CURRENCIES = ["KWD", "BHD", "OMR"] as const;

const THREE_DECIMAL_SET = new Set<string>(THREE_DECIMAL_CURRENCIES);

/** The default reporting currency for the Sprint-1 (Kuwait-first) build. */
export const DEFAULT_CURRENCY = "KWD";

/** Number of minor-unit (fraction) digits for a currency code. */
export function minorUnitDigits(currency: string): number {
  return THREE_DECIMAL_SET.has(currency.toUpperCase()) ? 3 : 2;
}

export type NegativeStyle = "minus" | "parentheses";

export interface FormatMoneyOptions {
  /** ISO 4217 alpha-3 code. Defaults to KWD. */
  currency?: string;
  /** Override the decimal places; defaults to the currency's minor unit (3 for KWD/BHD/OMR, else 2). */
  minorUnitDigits?: number;
  /** How to render negatives: a leading minus (default, for editable data) or parentheses (statements). */
  negativeStyle?: NegativeStyle;
  /** Include the currency code prefix ("KWD 1,204.500"). Defaults to true. */
  showCurrency?: boolean;
}

/**
 * Format a numeric amount as a QAYD money string, e.g. `formatMoney(1204.5)` → `"KWD 1,204.500"`.
 * Latin digits and comma grouping are forced via the `en-US` number format regardless of UI locale.
 */
export function formatMoney(value: number, options: FormatMoneyOptions = {}): string {
  const currency = (options.currency ?? DEFAULT_CURRENCY).toUpperCase();
  const digits = options.minorUnitDigits ?? minorUnitDigits(currency);
  const negativeStyle = options.negativeStyle ?? "minus";
  const showCurrency = options.showCurrency ?? true;

  const formatter = new Intl.NumberFormat("en-US", {
    minimumFractionDigits: digits,
    maximumFractionDigits: digits,
    useGrouping: true,
  });

  const isNegative = value < 0;
  const magnitude = formatter.format(Math.abs(value));
  const body = showCurrency ? `${currency} ${magnitude}` : magnitude;

  if (!isNegative) return body;
  return negativeStyle === "parentheses" ? `(${body})` : `-${body}`;
}
