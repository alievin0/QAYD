/**
 * `@qayd/shared` — framework-free utilities and constants shared across the web app and packages.
 * No React, no Next, no Node-only APIs: safe in the browser, on the server, and in the SDK.
 */
export {
  API_V1_PATH,
  DEFAULT_API_BASE_URL,
  DEFAULT_API_ORIGIN,
  resolveApiBaseUrl,
  stripTrailingSlash,
} from "./env.js";

export {
  DEFAULT_LOCALE,
  LOCALES,
  isLocale,
  textDirection,
  toLocale,
  type Locale,
  type TextDirection,
} from "./locale.js";

export {
  DEFAULT_CURRENCY,
  THREE_DECIMAL_CURRENCIES,
  formatMoney,
  minorUnitDigits,
  type FormatMoneyOptions,
  type NegativeStyle,
} from "./currency.js";
