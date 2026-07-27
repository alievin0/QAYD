"use client";

import type { AuthMe } from "@qayd/types";
import { Button, ThemeToggle, cn } from "@qayd/ui";

import { BellIcon, MenuIcon, SearchIcon, UserIcon } from "./icons";
import { LanguageSwitcher } from "./language-switcher";
import { useI18n } from "../../lib/i18n/locale-provider";

/**
 * Topbar — the sticky top band of the authenticated shell (TOPBAR.md). A single `<header>` laid out as a
 * horizontal flex row whose reading order follows the document direction, so it mirrors cleanly under
 * RTL. Slots (inline-start → inline-end): the mobile menu trigger, a command-palette/search affordance,
 * then the utility cluster — notifications, language switcher, theme toggle, and the user button. It is
 * chrome only: nothing here executes a sensitive action on a click.
 */

export interface TopbarProps {
  me: AuthMe | null;
  /** Opens the mobile nav drawer (shown below the `lg` breakpoint). */
  onOpenMobileNav: () => void;
  className?: string;
}

export function Topbar({ me, onOpenMobileNav, className }: TopbarProps) {
  const { t } = useI18n();
  const userName = me?.user.name;

  return (
    <header
      className={cn(
        "sticky top-0 z-30 flex h-16 items-center gap-2 border-b border-ink-6 bg-ink-1/85 px-4 backdrop-blur",
        className,
      )}
    >
      <Button
        type="button"
        variant="ghost"
        size="icon"
        className="lg:hidden"
        onClick={onOpenMobileNav}
        aria-label={t("shell.openMenu")}
      >
        <MenuIcon className="size-5" />
      </Button>

      <button
        type="button"
        className="flex h-9 flex-1 items-center gap-2 rounded-md border border-input bg-background px-3 text-sm text-muted-foreground ring-offset-background transition-colors hover:bg-muted-hover focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 sm:max-w-xs"
        aria-label={t("topbar.search")}
      >
        <SearchIcon className="size-4 shrink-0" />
        <span className="truncate">{t("topbar.search")}</span>
      </button>

      <div className="flex items-center gap-1.5 ms-auto">
        <Button
          type="button"
          variant="ghost"
          size="icon"
          aria-label={t("topbar.notifications")}
        >
          <BellIcon className="size-5" />
        </Button>

        <LanguageSwitcher />

        <ThemeToggle />

        <Button
          type="button"
          variant="ghost"
          size="icon"
          aria-label={t("user.menu")}
          title={userName ?? t("topbar.account")}
        >
          <UserIcon className="size-5" />
        </Button>
      </div>
    </header>
  );
}
