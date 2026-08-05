import type { ReactNode } from "react";
import { cookies } from "next/headers";

import { COMPANY_COOKIE } from "../../../lib/auth/session-cookie";
import { CompanyRefresh } from "../../../components/realtime/company-refresh";
import { AccountingTabs } from "./accounting-tabs";

/**
 * The `accounting/` section layout (S2-10): the module sub-nav wrapped around every accounting route.
 * The tabs live here so adding a section is one entry rather than a nav copy in each page.
 *
 * S2-13 mounts the refresh subscriber here too, for the same reason: every screen in this section reads
 * the ledger, so every one of them goes stale when an entry is posted — including the person's own
 * second tab. One subscription at the section root beats one per page, and because it re-runs the
 * server components rather than patching anything, each page still reads its own figures from the API.
 * The company UUID is lifted from the httpOnly cookie here because the browser cannot read it.
 */
export default async function AccountingLayout({
  children,
}: {
  children: ReactNode;
}) {
  const cookieStore = await cookies();

  return (
    <div className="flex flex-col gap-6">
      <CompanyRefresh
        companyId={cookieStore.get(COMPANY_COOKIE)?.value ?? null}
      />
      <AccountingTabs />
      {children}
    </div>
  );
}
