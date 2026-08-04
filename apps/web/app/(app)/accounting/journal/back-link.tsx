"use client";

import Link from "next/link";

import { useI18n } from "../../../../lib/i18n/locale-provider";

/**
 * The editor's "back to the list" link (S2-11).
 *
 * A client component purely so its label is translated rather than hard-coded — the two editor routes
 * are server components, and a literal string there would have been the one piece of untranslated
 * English on the screen. The arrow is a separate `aria-hidden` glyph so a screen reader announces the
 * destination and not a character it cannot pronounce, and it mirrors with the document direction.
 */
export function BackToJournal() {
  const { t } = useI18n();

  return (
    <Link
      href="/accounting/journal"
      className="inline-flex items-center gap-1.5 text-text-sm text-muted-foreground underline-offset-4 hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
    >
      <span aria-hidden="true" className="rtl:rotate-180">
        ←
      </span>
      {t("accounting.journal.back")}
    </Link>
  );
}
