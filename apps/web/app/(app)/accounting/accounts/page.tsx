import { cookies } from "next/headers";
import type { AccountTreeNode, AccountType } from "@qayd/types";

import {
  COMPANY_COOKIE,
  SESSION_COOKIE,
} from "../../../../lib/auth/session-cookie";
import { createServerClient } from "../../../../lib/server/sdk";
import { ChartOfAccounts } from "./chart-of-accounts";

/**
 * `/accounting/accounts` — the chart-of-accounts hub (S2-10).
 *
 * A server component: it holds the session credential the browser never sees, reads the chart and the
 * account-type catalogue through the SDK, and hands both to a client component that renders and
 * mutates. Mutations go back out through the BFF handlers under `/api/accounting/accounts`, so the
 * bearer token stays server-side for the whole round trip.
 *
 * A failed read degrades to an explicit error state rather than throwing: the chart is the first thing
 * an accountant opens, and a blank screen is worse than a sentence saying the load failed.
 */
export default async function ChartOfAccountsPage() {
  const cookieStore = await cookies();
  const token = cookieStore.get(SESSION_COOKIE)?.value;
  const companyId = cookieStore.get(COMPANY_COOKIE)?.value;

  let accounts: AccountTreeNode[] = [];
  let accountTypes: AccountType[] = [];
  let loadFailed = false;

  try {
    const client = createServerClient({ token, companyId });
    const [tree, types] = await Promise.all([
      client.accountTree(),
      client.accountTypes(),
    ]);
    accounts = tree.data?.accounts ?? [];
    accountTypes = types.data?.account_types ?? [];
  } catch {
    loadFailed = true;
  }

  return (
    <ChartOfAccounts
      accounts={accounts}
      accountTypes={accountTypes}
      loadFailed={loadFailed}
    />
  );
}
