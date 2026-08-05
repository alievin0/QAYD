import { cookies } from "next/headers";
import type { JournalEntry } from "@qayd/types";

import {
  COMPANY_COOKIE,
  SESSION_COOKIE,
} from "../../../../lib/auth/session-cookie";
import { createServerClient } from "../../../../lib/server/sdk";
import { JournalList } from "./journal-list";

/**
 * `/accounting/journal` — the entry list (S2-11).
 *
 * A server component: it holds the session credential the browser never sees and reads through the SDK.
 * A failed read degrades to an explicit message rather than throwing — a blank screen tells an
 * accountant nothing, and "could not be loaded" tells them to try again.
 */
export default async function JournalEntriesPage() {
  const cookieStore = await cookies();
  const token = cookieStore.get(SESSION_COOKIE)?.value;
  const companyId = cookieStore.get(COMPANY_COOKIE)?.value;

  let entries: JournalEntry[] = [];
  let loadFailed = false;

  try {
    const client = createServerClient({ token, companyId });
    const { data } = await client.listJournalEntries();
    entries = data?.journal_entries ?? [];
  } catch {
    loadFailed = true;
  }

  return <JournalList entries={entries} loadFailed={loadFailed} />;
}
