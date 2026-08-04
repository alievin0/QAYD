"use client";

import Link from "next/link";
import { Button } from "@qayd/ui";
import type { JournalEntry } from "@qayd/types";

import { useI18n } from "../../../../lib/i18n/locale-provider";

/**
 * The journal-entry list (S2-11). Renders what the API returned and computes nothing: the totals shown
 * are the server's own cached header figures, not a client-side sum.
 */
export interface JournalListProps {
  entries: JournalEntry[];
  loadFailed: boolean;
}

export function JournalList({ entries, loadFailed }: JournalListProps) {
  const { t } = useI18n();

  return (
    <section className="flex flex-col gap-6">
      <header className="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div className="flex flex-col gap-1">
          <h1 className="font-display text-display-sm text-ink-12">
            {t("accounting.journal.title")}
          </h1>
          <p className="text-text-sm text-muted-foreground">
            {t("accounting.journal.subtitle")}
          </p>
        </div>
        <Button asChild>
          <Link href="/accounting/journal/new">
            {t("accounting.journal.new")}
          </Link>
        </Button>
      </header>

      {loadFailed ? (
        <p role="alert" className="text-text-sm text-danger-11">
          {t("accounting.journal.loadFailed")}
        </p>
      ) : null}

      <p className="text-text-sm text-muted-foreground">
        {t("accounting.journal.count", { count: entries.length })}
      </p>

      {entries.length === 0 ? (
        <div className="rounded-lg border border-dashed border-line px-6 py-12 text-center">
          <p className="font-display text-text-lg text-ink-12">
            {t("accounting.journal.empty.title")}
          </p>
          <p className="mt-1 text-text-sm text-muted-foreground">
            {t("accounting.journal.empty.body")}
          </p>
        </div>
      ) : (
        <div className="overflow-x-auto rounded-lg border border-line">
          <table className="w-full border-collapse text-text-sm">
            <thead>
              <tr className="border-b border-line text-muted-foreground">
                <th scope="col" className="px-4 py-2 text-start font-medium">
                  {t("accounting.journal.columns.number")}
                </th>
                <th scope="col" className="px-4 py-2 text-start font-medium">
                  {t("accounting.journal.columns.date")}
                </th>
                <th scope="col" className="px-4 py-2 text-start font-medium">
                  {t("accounting.journal.columns.type")}
                </th>
                <th scope="col" className="px-4 py-2 text-start font-medium">
                  {t("accounting.journal.columns.status")}
                </th>
                <th scope="col" className="px-4 py-2 text-end font-medium">
                  {t("accounting.journal.columns.debit")}
                </th>
                <th scope="col" className="px-4 py-2 text-end font-medium">
                  {t("accounting.journal.columns.credit")}
                </th>
              </tr>
            </thead>
            <tbody>
              {entries.map((entry) => (
                <tr
                  key={entry.id}
                  className="border-b border-line/60 last:border-0"
                >
                  <td className="px-4 py-2">
                    <Link
                      href={`/accounting/journal/${entry.id}`}
                      className="font-mono text-ink-12 underline-offset-4 hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                    >
                      {entry.journal_number}
                    </Link>
                  </td>
                  <td className="px-4 py-2 tabular-nums text-muted-foreground">
                    {entry.journal_date}
                  </td>
                  <td className="px-4 py-2 text-muted-foreground">
                    {entry.entry_type}
                  </td>
                  <td className="px-4 py-2 text-muted-foreground">
                    {t(`accounting.journal.status.${entry.status}`)}
                  </td>
                  <td className="px-4 py-2 text-end tabular-nums text-ink-12">
                    {entry.total_debit}
                  </td>
                  <td className="px-4 py-2 text-end tabular-nums text-ink-12">
                    {entry.total_credit}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </section>
  );
}
