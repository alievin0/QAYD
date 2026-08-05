import { cookies } from "next/headers";
import { notFound } from "next/navigation";
import type { Account, JournalEntry } from "@qayd/types";

import {
  COMPANY_COOKIE,
  SESSION_COOKIE,
} from "../../../../../lib/auth/session-cookie";
import { createServerClient } from "../../../../../lib/server/sdk";
import { BackToJournal } from "../back-link";
import { JournalEditor } from "../journal-editor";

/**
 * `/accounting/journal/{id}` — an existing entry (S2-11).
 *
 * A cross-tenant id 404s upstream, so this renders Next's not-found rather than an empty editor that
 * would imply the entry exists and is simply blank.
 */
export default async function JournalEntryPage({
  params,
}: {
  params: Promise<{ entry: string }>;
}) {
  const { entry: entryParam } = await params;
  const entryId = Number(entryParam);

  if (!Number.isInteger(entryId) || entryId <= 0) notFound();

  const cookieStore = await cookies();
  const token = cookieStore.get(SESSION_COOKIE)?.value;
  const companyId = cookieStore.get(COMPANY_COOKIE)?.value;

  let entry: JournalEntry | null = null;
  let accounts: Account[] = [];
  let loadFailed = false;

  try {
    const client = createServerClient({ token, companyId });
    const [found, chart] = await Promise.all([
      client.journalEntry(entryId),
      client.listAccounts(),
    ]);
    entry = found.data?.journal_entry ?? null;
    accounts = (chart.data?.accounts ?? []).filter(
      (account) => account.allow_posting && account.status === "active",
    );
  } catch {
    loadFailed = true;
  }

  if (entry === null && !loadFailed) notFound();

  return (
    <div className="flex flex-col gap-4">
      <BackToJournal />
      <JournalEditor
        entry={entry}
        accounts={accounts}
        loadFailed={loadFailed}
      />
    </div>
  );
}
