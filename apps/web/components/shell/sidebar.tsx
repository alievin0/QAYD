"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";
import type { AuthMe } from "@qayd/types";
import { Button, cn } from "@qayd/ui";

import { CompanySwitcher } from "./company-switcher";
import { NavIcon, PanelCollapseIcon, SettingsIcon } from "./icons";
import { NAV_ITEMS } from "./nav-items";
import { useI18n } from "../../lib/i18n/locale-provider";

/**
 * Sidebar — the persistent primary-navigation rail (SIDEBAR.md / NAVIGATION_SYSTEM.md). A single `<nav>`
 * landmark with three stacked regions: the fixed workspace header (CompanySwitcher), the scrolling
 * primary nav list, and a fixed bottom utility row (Settings + collapse toggle). The active row uses the
 * brass `accent-subtle` tint; hover is the neutral ink fill (never brass). Fully RTL-aware — the rail's
 * border and row padding use logical properties, so it moves to the inline-end edge under Arabic.
 */

export interface SidebarProps {
  me: AuthMe | null;
  collapsed: boolean;
  onToggleCollapsed: () => void;
  /** Renders the mobile drawer variant (full labels, no collapse control). */
  variant?: "rail" | "drawer";
  className?: string;
}

export function Sidebar({
  me,
  collapsed,
  onToggleCollapsed,
  variant = "rail",
  className,
}: SidebarProps) {
  const { t } = useI18n();
  const pathname = usePathname();
  const isDrawer = variant === "drawer";
  const showLabels = isDrawer || !collapsed;

  return (
    <nav
      aria-label={t("nav.primary")}
      data-state={collapsed && !isDrawer ? "collapsed" : "expanded"}
      className={cn(
        "flex h-full flex-col border-e border-ink-6 bg-ink-2",
        showLabels ? "w-[272px]" : "w-[72px]",
        className,
      )}
    >
      <CompanySwitcher me={me} />

      <ul role="list" className="flex-1 space-y-1 overflow-y-auto p-2">
        {NAV_ITEMS.map((item) => {
          const label = t(`nav.${item.id}`);
          const active =
            item.available &&
            (pathname === item.href || pathname.startsWith(`${item.href}/`));

          const rowClass = cn(
            "flex items-center gap-3 rounded-md px-3 py-2 text-sm font-medium transition-colors",
            showLabels ? "justify-start" : "justify-center",
            active
              ? "bg-accent-subtle text-accent"
              : item.available
                ? "text-ink-11 hover:bg-muted-hover hover:text-ink-12"
                : "cursor-not-allowed text-ink-8",
          );

          const content = (
            <>
              <NavIcon name={item.icon} className="size-5 shrink-0" />
              {showLabels ? <span className="truncate">{label}</span> : null}
              {showLabels && !item.available ? (
                <span className="ms-auto rounded-full bg-ink-3 px-1.5 py-0.5 text-[0.625rem] font-semibold uppercase tracking-wide text-ink-8">
                  {t("nav.soon")}
                </span>
              ) : null}
            </>
          );

          return (
            <li key={item.id}>
              {item.available ? (
                <Link
                  href={item.href}
                  className={rowClass}
                  aria-current={active ? "page" : undefined}
                  title={!showLabels ? label : undefined}
                >
                  {content}
                </Link>
              ) : (
                <span
                  className={rowClass}
                  aria-disabled="true"
                  title={showLabels ? undefined : `${label} — ${t("nav.soon")}`}
                >
                  {content}
                </span>
              )}
            </li>
          );
        })}
      </ul>

      <div className="flex items-center gap-1 border-t border-ink-6 p-2">
        <span
          className={cn(
            "flex flex-1 items-center gap-3 rounded-md px-3 py-2 text-sm font-medium text-ink-8",
            showLabels ? "justify-start" : "justify-center",
          )}
          aria-disabled="true"
          title={t("shell.settings")}
        >
          <SettingsIcon className="size-5 shrink-0" />
          {showLabels ? (
            <span className="truncate">{t("shell.settings")}</span>
          ) : null}
        </span>
        {isDrawer ? null : (
          <Button
            type="button"
            variant="ghost"
            size="icon"
            onClick={onToggleCollapsed}
            aria-label={
              collapsed ? t("shell.expandSidebar") : t("shell.collapseSidebar")
            }
          >
            <PanelCollapseIcon className="size-5" />
          </Button>
        )}
      </div>
    </nav>
  );
}
