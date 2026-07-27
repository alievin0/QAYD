"use client";

import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@qayd/ui";

import { useI18n } from "../../../lib/i18n/locale-provider";

/**
 * The dashboard's empty state, localized client-side so the copy and the company name both follow the
 * active locale. Rendered by the server `page.tsx`, which passes the tenant's EN/AR names down.
 */
export interface DashboardEmptyStateProps {
  companyNameEn: string | null;
  companyNameAr: string | null;
}

export function DashboardEmptyState({
  companyNameEn,
  companyNameAr,
}: DashboardEmptyStateProps) {
  const { t, locale } = useI18n();
  const companyName =
    (locale === "ar" ? companyNameAr : companyNameEn) ??
    companyNameEn ??
    t("app.name");

  return (
    <div className="mx-auto flex max-w-3xl flex-col gap-6">
      <header className="flex flex-col gap-1">
        <h1 className="font-display text-display-md text-ink-12">
          {t("dashboard.title")}
        </h1>
        <p className="text-text-sm text-muted-foreground">
          {t("dashboard.welcome", { company: companyName })}
        </p>
      </header>

      <Card>
        <CardHeader>
          <div
            className="mb-2 grid size-12 place-items-center rounded-lg bg-accent-subtle text-accent"
            aria-hidden="true"
          >
            <svg
              width="1.5em"
              height="1.5em"
              viewBox="0 0 24 24"
              fill="none"
              stroke="currentColor"
              strokeWidth={2}
              strokeLinecap="round"
              strokeLinejoin="round"
            >
              <rect x="3" y="3" width="7" height="9" rx="1" />
              <rect x="14" y="3" width="7" height="5" rx="1" />
              <rect x="14" y="12" width="7" height="9" rx="1" />
              <rect x="3" y="16" width="7" height="5" rx="1" />
            </svg>
          </div>
          <CardTitle>{t("dashboard.emptyTitle")}</CardTitle>
          <CardDescription>{t("dashboard.emptyBody")}</CardDescription>
        </CardHeader>
        <CardContent />
      </Card>
    </div>
  );
}
