"use client";

import { useState, type ReactNode } from "react";
import type { AuthMe } from "@qayd/types";
import { Button, cn } from "@qayd/ui";

import { Sidebar } from "./sidebar";
import { Topbar } from "./topbar";
import { MenuIcon } from "./icons";
import { useI18n } from "../../lib/i18n/locale-provider";

/**
 * AppShell — the persistent authenticated frame every `(app)` screen renders inside. It composes the
 * Sidebar (primary nav) and Topbar (utility band) around a scrolling `<main>` content region, and owns
 * the two pieces of shell-local UI state that don't belong on the server: the desktop rail's
 * collapsed/expanded flag and the mobile drawer's open/closed flag. It receives the resolved `AuthMe`
 * (or `null`) from the server layout and threads it to the CompanySwitcher and user affordances — it
 * never fetches identity itself.
 */

export interface AppShellProps {
  me: AuthMe | null;
  children: ReactNode;
}

export function AppShell({ me, children }: AppShellProps) {
  const { t } = useI18n();
  const [collapsed, setCollapsed] = useState(false);
  const [mobileNavOpen, setMobileNavOpen] = useState(false);

  return (
    <div className="flex min-h-dvh bg-background text-foreground">
      <a
        href="#main-content"
        className="sr-only focus:not-sr-only focus:absolute focus:start-4 focus:top-4 focus:z-50 focus:rounded-md focus:bg-primary focus:px-4 focus:py-2 focus:text-primary-foreground"
      >
        {t("shell.skipToContent")}
      </a>

      {/* Desktop rail */}
      <div className="hidden lg:block">
        <Sidebar
          me={me}
          collapsed={collapsed}
          onToggleCollapsed={() => setCollapsed((value) => !value)}
        />
      </div>

      {/* Mobile drawer */}
      {mobileNavOpen ? (
        <div className="fixed inset-0 z-40 lg:hidden">
          <button
            type="button"
            className="absolute inset-0 bg-ink-12/40"
            aria-label={t("shell.closeMenu")}
            onClick={() => setMobileNavOpen(false)}
          />
          <div className="absolute inset-y-0 start-0 flex w-[288px] max-w-[85%] flex-col shadow-lg">
            <div className="flex items-center justify-end border-b border-ink-6 bg-ink-2 p-2">
              <Button
                type="button"
                variant="ghost"
                size="icon"
                onClick={() => setMobileNavOpen(false)}
                aria-label={t("shell.closeMenu")}
              >
                <MenuIcon className="size-5" />
              </Button>
            </div>
            <Sidebar
              me={me}
              variant="drawer"
              collapsed={false}
              onToggleCollapsed={() => undefined}
              className="flex-1"
            />
          </div>
        </div>
      ) : null}

      <div className={cn("flex min-w-0 flex-1 flex-col")}>
        <Topbar me={me} onOpenMobileNav={() => setMobileNavOpen(true)} />
        <main id="main-content" className="flex-1 overflow-y-auto p-6 lg:p-8">
          {children}
        </main>
      </div>
    </div>
  );
}
