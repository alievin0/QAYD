"use client";

import { useState, useTransition } from "react";
import { useRouter } from "next/navigation";
import type { AuthMe, CompanySummary } from "@qayd/types";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@qayd/ui";

import { useI18n } from "../../lib/i18n/locale-provider";

/**
 * CompanySwitcher — the workspace header control. Reads the caller's memberships from `/auth/me` (passed
 * down from the server layout, never fetched here) and, on change, re-scopes the active company through
 * the BFF `switch-company` handler, then refreshes so every server component re-renders under the new
 * tenant. Degrades to a disabled placeholder when there are no memberships (the unauthenticated / empty
 * state the shell renders in tests and before login lands).
 */

function companyLabel(company: CompanySummary, locale: string): string {
  if (locale === "ar" && company.name_ar) return company.name_ar;
  return company.name_en;
}

export interface CompanySwitcherProps {
  me: AuthMe | null;
}

export function CompanySwitcher({ me }: CompanySwitcherProps) {
  const { t, locale } = useI18n();
  const router = useRouter();
  const [isPending, startTransition] = useTransition();
  const [error, setError] = useState(false);

  const companies = me?.companies ?? [];
  const activeUuid = me?.active_company?.uuid ?? companies[0]?.uuid;

  // Empty / unauthenticated: a labeled, disabled placeholder — never a crash.
  if (companies.length === 0) {
    return (
      <div
        className="flex h-14 items-center gap-2 border-b border-ink-6 px-4"
        aria-label={t("company.switcherLabel")}
      >
        <span className="grid size-8 shrink-0 place-items-center rounded-md bg-ink-3 text-sm font-semibold text-ink-9">
          Q
        </span>
        <span className="truncate text-sm text-muted-foreground">
          {t("company.switcherPlaceholder")}
        </span>
      </div>
    );
  }

  function onValueChange(nextUuid: string) {
    if (nextUuid === activeUuid) return;
    setError(false);
    startTransition(async () => {
      try {
        const response = await fetch("/api/auth/switch-company", {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ company_id: nextUuid }),
        });
        if (!response.ok) {
          setError(true);
          return;
        }
        router.refresh();
      } catch {
        setError(true);
      }
    });
  }

  return (
    <div className="flex h-14 items-center border-b border-ink-6 px-3">
      <Select
        value={activeUuid}
        onValueChange={onValueChange}
        disabled={isPending}
      >
        <SelectTrigger
          className="h-10 border-transparent bg-transparent shadow-none hover:bg-muted-hover focus:ring-2 focus:ring-ring data-[state=open]:bg-muted-hover"
          aria-label={t("company.switcherLabel")}
        >
          <SelectValue placeholder={t("company.switcherPlaceholder")} />
        </SelectTrigger>
        <SelectContent>
          {companies.map((company) => (
            <SelectItem key={company.uuid} value={company.uuid}>
              {companyLabel(company, locale)}
            </SelectItem>
          ))}
        </SelectContent>
      </Select>
      {isPending ? (
        <span className="sr-only" role="status">
          {t("company.switching")}
        </span>
      ) : null}
      {error ? (
        <span className="ps-2 text-xs text-negative" role="alert">
          !
        </span>
      ) : null}
    </div>
  );
}
