"use client";

import { useState, useTransition } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import type { CompanySummary } from "@qayd/types";
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@qayd/ui";

import { resolveNext } from "../../../lib/auth/next-param";
import { useI18n } from "../../../lib/i18n/locale-provider";

/**
 * CompanySelectList — the `/select-company` full-page picker (S1-15). Rows render from the memberships
 * already in `/auth/me` (no extra fetch). Selecting a row *is* the action: it re-scopes the session through
 * the BFF `switch-company` handler, then routes on via the validated `next` (or `/dashboard`). A `403` on
 * one row surfaces inline on that row only — a suspended membership in one company says nothing about
 * standing in another — while the other rows stay clickable.
 */

function companyLabel(company: CompanySummary, locale: string): string {
  if (locale === "ar" && company.name_ar) return company.name_ar;
  return company.name_en;
}

export interface CompanySelectListProps {
  companies: CompanySummary[];
  /** A same-origin, in-app `next` carried from login; validated again here before use. */
  next: string | null;
}

export function CompanySelectList({ companies, next }: CompanySelectListProps) {
  const { t, locale } = useI18n();
  const router = useRouter();
  const [pending, startTransition] = useTransition();
  const [busyUuid, setBusyUuid] = useState<string | null>(null);
  const [rowError, setRowError] = useState<{
    uuid: string;
    kind: "denied" | "error";
  } | null>(null);

  function onSelect(uuid: string) {
    if (pending) return;
    setRowError(null);
    setBusyUuid(uuid);
    startTransition(async () => {
      try {
        const response = await fetch("/api/auth/switch-company", {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ company_id: uuid }),
        });
        if (!response.ok) {
          setBusyUuid(null);
          setRowError({
            uuid,
            kind: response.status === 403 ? "denied" : "error",
          });
          return;
        }
        router.push(resolveNext(next));
      } catch {
        setBusyUuid(null);
        setRowError({ uuid, kind: "error" });
      }
    });
  }

  return (
    <Card>
      <CardHeader>
        <CardTitle>{t("auth.selectCompany.title")}</CardTitle>
        <CardDescription>{t("auth.selectCompany.subtitle")}</CardDescription>
      </CardHeader>
      <CardContent className="flex flex-col gap-2">
        <ul className="flex flex-col gap-2" aria-busy={pending}>
          {companies.map((company) => {
            const isBusy = busyUuid === company.uuid;
            const hasError = rowError?.uuid === company.uuid;
            return (
              <li key={company.uuid}>
                <button
                  type="button"
                  disabled={pending}
                  onClick={() => onSelect(company.uuid)}
                  className="flex w-full items-center gap-3 rounded-md border border-input bg-background px-3 py-3 text-start ring-offset-background transition-colors hover:bg-muted-hover focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:opacity-50"
                >
                  <span
                    className="grid size-9 shrink-0 place-items-center rounded-md bg-accent-subtle text-sm font-semibold text-accent"
                    aria-hidden="true"
                  >
                    {companyLabel(company, locale).charAt(0)}
                  </span>
                  <span className="flex min-w-0 flex-col">
                    <span className="truncate text-sm font-medium text-foreground">
                      {companyLabel(company, locale)}
                    </span>
                    <span className="truncate text-xs text-muted-foreground">
                      {company.role}
                    </span>
                  </span>
                  {isBusy ? (
                    <span
                      className="ms-auto text-xs text-muted-foreground"
                      role="status"
                    >
                      {t("auth.selectCompany.switching")}
                    </span>
                  ) : null}
                </button>
                {hasError ? (
                  <p className="px-1 pt-1 text-xs text-negative" role="alert">
                    {rowError.kind === "denied"
                      ? t("auth.selectCompany.accessDenied")
                      : t("auth.selectCompany.error")}
                  </p>
                ) : null}
              </li>
            );
          })}
        </ul>

        <Link
          href="/onboarding?intent=new-company"
          className="rounded-md border border-dashed border-input px-3 py-3 text-center text-sm text-accent underline-offset-4 hover:underline"
        >
          {t("auth.selectCompany.addCompany")}
        </Link>
      </CardContent>
    </Card>
  );
}
