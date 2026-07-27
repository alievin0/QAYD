import type { Metadata } from "next";
import type { ReactNode } from "react";
import { cookies } from "next/headers";
import { textDirection, toLocale } from "@qayd/shared";

import { Providers } from "../components/providers";
import { LOCALE_COOKIE } from "../lib/i18n/locale-provider";
import "./globals.css";

export const metadata: Metadata = {
  title: "QAYD",
  description: "QAYD — the AI Financial Operating System.",
};

/**
 * Sets the `.dark` class on `<html>` before first paint, from the theme `@qayd/ui` persisted to
 * `localStorage`, so a dark-mode user never sees a light flash. Mirrors the ThemeProvider's resolution
 * (explicit choice, else the OS preference).
 */
const NO_FLASH_THEME = `
(function () {
  try {
    var stored = localStorage.getItem("qayd-theme");
    var dark = stored === "dark" || ((!stored || stored === "system") &&
      window.matchMedia("(prefers-color-scheme: dark)").matches);
    if (dark) document.documentElement.classList.add("dark");
  } catch (e) {}
})();
`;

export default async function RootLayout({
  children,
}: Readonly<{ children: ReactNode }>) {
  // Resolve the SSR locale/direction from the request cookie so first paint matches the user's choice.
  // No cookie yet → English (LTR): a neutral baseline the user switches to Arabic from. The backend
  // `DEFAULT_LOCALE` (ar) becomes the driver once `/auth/me` supplies `user.locale` in a later story.
  const cookieStore = await cookies();
  const locale = toLocale(cookieStore.get(LOCALE_COOKIE)?.value, "en");
  const dir = textDirection(locale);

  return (
    <html lang={locale} dir={dir} suppressHydrationWarning>
      <head>
        <script dangerouslySetInnerHTML={{ __html: NO_FLASH_THEME }} />
      </head>
      <body>
        <Providers initialLocale={locale}>{children}</Providers>
      </body>
    </html>
  );
}
