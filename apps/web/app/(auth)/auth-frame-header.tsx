"use client";

import { ThemeToggle } from "@qayd/ui";

import { LanguageSwitcher } from "../../components/shell/language-switcher";
import { useI18n } from "../../lib/i18n/locale-provider";

/**
 * The `(auth)` frame's slim header: the QAYD wordmark on the inline-start, and the locale + theme
 * controls on the inline-end. Client-side because those two controls are. No navigation, no AI — the
 * auth frame is intentionally minimal.
 */
export function AuthFrameHeader() {
  const { t } = useI18n();
  return (
    <header className="flex items-center justify-between px-4 py-4 sm:px-6">
      <span className="font-display text-display-sm text-ink-12">
        {t("app.name")}
      </span>
      <div className="flex items-center gap-1.5">
        <LanguageSwitcher />
        <ThemeToggle />
      </div>
    </header>
  );
}
