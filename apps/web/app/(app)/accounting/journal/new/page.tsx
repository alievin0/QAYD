import { cookies } from "next/headers";
import type { Account } from "@qayd/types";

import {
  COMPANY_COOKIE,
  SESSION_COOKIE,
} from "../../../../../lib/auth/session-cookie";
import { createServerClient } from "../../../../../lib/server/sdk";
import { BackToJournal } from "../back-link";
import { JournalEditor } from "../journal-editor";

/**
 * `/accounting/journal/new` — a blank journal entry (S2-11).
 *
 * The account picker is fed only accounts the server says may receive a posting. That is a display
 * filter over what the API returned, not a rule the browser invented: `allow_posting` is maintained by
 * the database, and the posting engine refuses a header line regardless of what this list shows.
 */
export default async function NewJournalEntryPage() {
  const cookieStore = await cookies();
  const token = cookieStore.get(SESSION_COOKIE)?.value;
  const companyId = cookieStore.get(COMPANY_COOKIE)?.value;

  let accounts: Account[] = [];
  let loadFailed = false;

  try {
    const client = createServerClient({ token, companyId });
    const { data } = await client.listAccounts();
    accounts = (data?.accounts ?? []).filter(
      (account) => account.allow_posting && account.status === "active",
    );
  } catch {
    loadFailed = true;
  }

  return (
    <div className="flex flex-col gap-4">
      <BackToJournal />
      <JournalEditor entry={null} accounts={accounts} loadFailed={loadFailed} />
    </div>
  );
}
