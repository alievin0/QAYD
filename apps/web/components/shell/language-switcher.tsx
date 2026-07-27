"use client";

import { isLocale, LOCALES, type Locale } from "@qayd/shared";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@qayd/ui";

import { GlobeIcon } from "./icons";
import { useI18n } from "../../lib/i18n/locale-provider";

/**
 * LanguageSwitcher — flips the shell between the two Sprint-1 locales. Selecting Arabic re-mirrors the
 * whole layout to RTL (the `LocaleProvider` sets `<html dir="rtl">`) and re-binds `t()`; the choice is
 * persisted to the `qayd_locale` cookie so the next server render starts in the right direction.
 */
export function LanguageSwitcher() {
  const { locale, setLocale, t } = useI18n();

  function onValueChange(value: string) {
    if (isLocale(value) && value !== locale) setLocale(value);
  }

  return (
    <Select value={locale} onValueChange={onValueChange}>
      <SelectTrigger
        className="h-9 w-auto gap-2 border-input bg-transparent px-2.5 text-sm hover:bg-muted-hover"
        aria-label={t("language.label")}
      >
        <GlobeIcon className="size-4 shrink-0 text-muted-foreground" />
        <SelectValue />
      </SelectTrigger>
      <SelectContent>
        {LOCALES.map((code: Locale) => (
          <SelectItem key={code} value={code}>
            {t(`language.${code}`)}
          </SelectItem>
        ))}
      </SelectContent>
    </Select>
  );
}
