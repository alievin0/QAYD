"use client";

import type { ReactNode } from "react";
import type { Locale } from "@qayd/shared";
import { ThemeProvider } from "@qayd/ui";

import { LocaleProvider } from "../lib/i18n/locale-provider";

/**
 * The client provider stack wrapping the whole app: theme (light/dark via the `.dark` class, persisted
 * to `localStorage` by `@qayd/ui`) and locale (EN/AR + RTL, persisted to the `qayd_locale` cookie). Kept
 * as one thin client boundary so the root layout can stay a server component.
 */
export interface ProvidersProps {
  children: ReactNode;
  /** Locale resolved from the request cookie server-side, so first paint matches the user's choice. */
  initialLocale: Locale;
}

export function Providers({ children, initialLocale }: ProvidersProps) {
  return (
    <ThemeProvider defaultTheme="system">
      <LocaleProvider initialLocale={initialLocale}>{children}</LocaleProvider>
    </ThemeProvider>
  );
}
