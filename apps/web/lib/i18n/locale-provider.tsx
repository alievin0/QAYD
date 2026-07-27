"use client";

import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useMemo,
  useState,
  type ReactNode,
} from "react";
import {
  DEFAULT_LOCALE,
  textDirection,
  toLocale,
  type Locale,
} from "@qayd/shared";

import {
  createTranslator,
  type Translator,
  type TranslateVars,
} from "./index";

/**
 * Client-side locale state for the shell. Holds the active `Locale`, exposes a bound `t()` translator,
 * and — as a side effect — keeps `<html lang>` / `<html dir>` and the `qayd_locale` cookie in sync so a
 * server render on the next request starts in the right direction. Switching to `ar` mirrors the layout
 * to full RTL via `textDirection()` from `@qayd/shared`.
 */

/** The cookie the root layout reads to pick the SSR locale/direction. Non-httpOnly by design: it is a UI
 *  preference, carries nothing actionable, and must be readable by this client provider. */
export const LOCALE_COOKIE = "qayd_locale";

interface LocaleContextValue {
  locale: Locale;
  dir: "ltr" | "rtl";
  setLocale: (locale: Locale) => void;
  t: Translator;
}

const LocaleContext = createContext<LocaleContextValue | null>(null);

function persistLocaleCookie(locale: Locale): void {
  if (typeof document === "undefined") return;
  // 1 year, root path, Lax — a durable UI preference, never sensitive.
  document.cookie = `${LOCALE_COOKIE}=${locale}; path=/; max-age=31536000; samesite=lax`;
}

function applyDocumentLocale(locale: Locale): void {
  if (typeof document === "undefined") return;
  const root = document.documentElement;
  root.setAttribute("lang", locale);
  root.setAttribute("dir", textDirection(locale));
}

export interface LocaleProviderProps {
  children: ReactNode;
  /** Locale resolved server-side from the `qayd_locale` cookie (falls back to `DEFAULT_LOCALE`). */
  initialLocale?: Locale;
}

export function LocaleProvider({
  children,
  initialLocale,
}: LocaleProviderProps) {
  const [locale, setLocaleState] = useState<Locale>(() =>
    toLocale(initialLocale, DEFAULT_LOCALE),
  );

  const setLocale = useCallback((next: Locale) => {
    setLocaleState(next);
    persistLocaleCookie(next);
    applyDocumentLocale(next);
  }, []);

  // Keep the document in sync on mount and whenever the locale changes (covers the initial hydration).
  useEffect(() => {
    applyDocumentLocale(locale);
  }, [locale]);

  const value = useMemo<LocaleContextValue>(
    () => ({
      locale,
      dir: textDirection(locale),
      setLocale,
      t: createTranslator(locale),
    }),
    [locale, setLocale],
  );

  return (
    <LocaleContext.Provider value={value}>{children}</LocaleContext.Provider>
  );
}

export function useI18n(): LocaleContextValue {
  const context = useContext(LocaleContext);
  if (context === null) {
    throw new Error("useI18n must be used within a <LocaleProvider>.");
  }
  return context;
}

/** Convenience hook for components that only need the translator. */
export function useTranslator(): Translator {
  return useI18n().t;
}

export type { TranslateVars };
