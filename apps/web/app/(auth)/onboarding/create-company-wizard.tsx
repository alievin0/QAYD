"use client";

import { useMemo, useState, useTransition } from "react";
import { useRouter } from "next/navigation";
import { createCompanyInputSchema } from "@qayd/types";
import {
  Button,
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
  Input,
  Label,
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@qayd/ui";

import { useI18n } from "../../../lib/i18n/locale-provider";

/**
 * CreateCompanyWizard — the post-auth Create-Company onboarding wizard (S1-11). A zero-company user is
 * routed here after auth; it collects the company's legal/trade name (EN/AR), base currency, and
 * fiscal-year start month, Zod-validates via `createCompanyInputSchema`, and posts to the BFF `companies`
 * handler (which proxies `POST /api/v1/companies`). On success it lands on `/dashboard`, now scoped to the
 * tenant just created. Sprint-1 scope only — CoA seeding, numbering, and team invites arrive later.
 */

/** A Gulf-first base-currency set; KWD is the default (the platform's home market). */
const CURRENCIES = ["KWD", "SAR", "AED", "BHD", "QAR", "OMR", "USD", "EUR", "GBP"] as const;

const MONTHS = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12] as const;

export function CreateCompanyWizard() {
  const { t, locale } = useI18n();
  const router = useRouter();
  const [pending, startTransition] = useTransition();

  const [legalName, setLegalName] = useState("");
  const [tradeName, setTradeName] = useState("");
  const [nameEn, setNameEn] = useState("");
  const [nameAr, setNameAr] = useState("");
  const [baseCurrency, setBaseCurrency] = useState<string>("KWD");
  const [fiscalMonth, setFiscalMonth] = useState<string>("1");
  const [error, setError] = useState<string | null>(null);

  // Localized month names for free, no i18n keys needed (en → "January", ar → "يناير").
  const monthNames = useMemo(() => {
    const formatter = new Intl.DateTimeFormat(locale === "ar" ? "ar" : "en", {
      month: "long",
    });
    return MONTHS.map((m) => formatter.format(new Date(Date.UTC(2000, m - 1, 1))));
  }, [locale]);

  function onSubmit(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setError(null);

    const parsed = createCompanyInputSchema.safeParse({
      legal_name: legalName.trim(),
      name_en: nameEn.trim(),
      base_currency: baseCurrency,
      fiscal_year_start_month: Number(fiscalMonth),
      trade_name: tradeName.trim() || undefined,
      name_ar: nameAr.trim() || undefined,
    });
    if (!parsed.success) {
      setError(t("auth.onboarding.invalid"));
      return;
    }

    startTransition(async () => {
      try {
        const response = await fetch("/api/auth/companies", {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify(parsed.data),
        });
        if (!response.ok) {
          setError(t("auth.onboarding.error"));
          return;
        }
        router.push("/dashboard");
      } catch {
        setError(t("auth.onboarding.error"));
      }
    });
  }

  return (
    <Card>
      <CardHeader>
        <CardTitle>{t("auth.onboarding.title")}</CardTitle>
        <CardDescription>{t("auth.onboarding.subtitle")}</CardDescription>
      </CardHeader>
      <CardContent>
        <form className="flex flex-col gap-4" onSubmit={onSubmit} noValidate>
          <div className="flex flex-col gap-1.5">
            <Label htmlFor="legal_name">{t("auth.onboarding.legalName")}</Label>
            <Input
              id="legal_name"
              type="text"
              autoFocus
              required
              disabled={pending}
              value={legalName}
              onChange={(event) => setLegalName(event.target.value)}
              aria-describedby="legal-name-hint"
            />
            <p id="legal-name-hint" className="text-xs text-muted-foreground">
              {t("auth.onboarding.legalNameHint")}
            </p>
          </div>

          <div className="flex flex-col gap-1.5">
            <Label htmlFor="trade_name">
              {t("auth.onboarding.tradeName")}{" "}
              <span className="text-muted-foreground">
                ({t("auth.onboarding.optional")})
              </span>
            </Label>
            <Input
              id="trade_name"
              type="text"
              disabled={pending}
              value={tradeName}
              onChange={(event) => setTradeName(event.target.value)}
            />
          </div>

          <div className="flex flex-col gap-1.5">
            <Label htmlFor="name_en">{t("auth.onboarding.nameEn")}</Label>
            <Input
              id="name_en"
              type="text"
              dir="ltr"
              required
              disabled={pending}
              value={nameEn}
              onChange={(event) => setNameEn(event.target.value)}
            />
          </div>

          <div className="flex flex-col gap-1.5">
            <Label htmlFor="name_ar">
              {t("auth.onboarding.nameAr")}{" "}
              <span className="text-muted-foreground">
                ({t("auth.onboarding.optional")})
              </span>
            </Label>
            <Input
              id="name_ar"
              type="text"
              dir="rtl"
              disabled={pending}
              value={nameAr}
              onChange={(event) => setNameAr(event.target.value)}
            />
          </div>

          <div className="flex flex-col gap-1.5">
            <span id="base_currency_label" className="text-sm font-medium text-foreground">
              {t("auth.onboarding.baseCurrency")}
            </span>
            <Select
              value={baseCurrency}
              onValueChange={setBaseCurrency}
              disabled={pending}
            >
              <SelectTrigger aria-labelledby="base_currency_label">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                {CURRENCIES.map((code) => (
                  <SelectItem key={code} value={code}>
                    {code}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>

          <div className="flex flex-col gap-1.5">
            <span id="fiscal_month_label" className="text-sm font-medium text-foreground">
              {t("auth.onboarding.fiscalYearStart")}
            </span>
            <Select
              value={fiscalMonth}
              onValueChange={setFiscalMonth}
              disabled={pending}
            >
              <SelectTrigger aria-labelledby="fiscal_month_label">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                {MONTHS.map((m) => (
                  <SelectItem key={m} value={String(m)}>
                    {monthNames[m - 1]}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>

          {error ? (
            <p className="text-sm text-negative" role="alert">
              {error}
            </p>
          ) : null}

          <Button type="submit" disabled={pending}>
            {pending
              ? t("auth.onboarding.submitting")
              : t("auth.onboarding.submit")}
          </Button>
        </form>
      </CardContent>
    </Card>
  );
}
