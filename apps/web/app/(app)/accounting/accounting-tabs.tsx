"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";
import { cn } from "@qayd/ui";

import { useI18n } from "../../../lib/i18n/locale-provider";

/**
 * The accounting sub-nav (S2-10). Only the chart of accounts is a real route today; the rest are
 * announced but not linked, because a tab that navigates to a 404 is worse than one that says "soon".
 *
 * RTL needs nothing special: the tabs are a flex row, so they reverse with the document, and the active
 * marker is a bottom border rather than a directional inset.
 */
const TABS = [
  { key: "accounts", href: "/accounting/accounts", ready: true },
  { key: "journal", href: "/accounting/journal", ready: true },
  { key: "ledger", href: "/accounting/ledger", ready: false },
  { key: "trialBalance", href: "/accounting/trial-balance", ready: false },
] as const;

export function AccountingTabs() {
  const { t } = useI18n();
  const pathname = usePathname();

  return (
    <nav aria-label={t("accounting.tabs.label")}>
      <ul className="flex flex-wrap items-center gap-1 border-b border-line">
        {TABS.map((tab) => {
          const label = t(`accounting.tabs.${tab.key}`);

          if (!tab.ready) {
            return (
              <li key={tab.key}>
                <span className="flex items-center gap-2 px-3 py-2 text-text-sm text-muted-foreground/60">
                  {label}
                  <span className="rounded-full border border-line px-1.5 py-0.5 text-text-xs">
                    {t("accounting.tabs.soon")}
                  </span>
                </span>
              </li>
            );
          }

          const isActive = pathname.startsWith(tab.href);

          return (
            <li key={tab.key}>
              <Link
                href={tab.href}
                aria-current={isActive ? "page" : undefined}
                className={cn(
                  "-mb-px block border-b-2 px-3 py-2 text-text-sm transition-colors",
                  "focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring",
                  isActive
                    ? "border-brass-9 text-ink-12"
                    : "border-transparent text-muted-foreground hover:text-ink-12",
                )}
              >
                {label}
              </Link>
            </li>
          );
        })}
      </ul>
    </nav>
  );
}
