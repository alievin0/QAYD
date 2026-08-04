import { cookies } from "next/headers";
import { QaydApiError } from "@qayd/sdk";
import type { ComputedTrialBalanceResult, FiscalPeriod } from "@qayd/types";

import {
  COMPANY_COOKIE,
  SESSION_COOKIE,
} from "../../../../lib/auth/session-cookie";
import { createServerClient } from "../../../../lib/server/sdk";
import { TrialBalance } from "./trial-balance";

/**
 * `/accounting/trial-balance` — the trial-balance route (S2-12).
 *
 * A server component, so the bearer token stays behind the BFF: the calendar and the opening figures
 * are read here, and every later read the screen makes goes back out through `/api/accounting/…`.
 *
 * Two reads, in order, because the second depends on the first — a trial balance is computed FOR a
 * period, and period ids are database-generated, so there is nothing to compute until the calendar has
 * been enumerated. A company with no periods is a real state (the fiscal year creates them), handled by
 * rendering the empty screen rather than by guessing an id.
 */

/**
 * The period to open on: the one currently open, else the last in calendar order.
 *
 * "Last" leans on the API's own ordering — fiscal year, then period number — rather than on parsing
 * dates here, so the screen agrees with the server about what follows what.
 */
function defaultPeriod(periods: FiscalPeriod[]): FiscalPeriod | null {
  if (periods.length === 0) return null;
  return (
    periods.find((period) => period.status === "open") ??
    periods[periods.length - 1] ??
    null
  );
}

export default async function TrialBalancePage() {
  const cookieStore = await cookies();
  const token = cookieStore.get(SESSION_COOKIE)?.value;
  const companyId = cookieStore.get(COMPANY_COOKIE)?.value;

  const client = createServerClient({ token, companyId });

  let periods: FiscalPeriod[] = [];
  let selected: FiscalPeriod | null = null;
  let balance: ComputedTrialBalanceResult | null = null;
  let loadFailed = false;
  let loadError: string | null = null;

  try {
    const calendar = await client.fiscalPeriods();
    periods = calendar.data?.fiscal_periods ?? [];

    selected = defaultPeriod(periods);
    if (selected !== null) {
      const computed = await client.computeTrialBalance(selected.id);
      balance = computed.data ?? null;
    }
  } catch (error) {
    loadFailed = true;
    // The server's own words when it gave any: a refusal for want of `accounting.trial_balance.read`
    // says something quite different from a failed round trip, and only the server knows which it was.
    loadError =
      error instanceof QaydApiError && error.message !== ""
        ? error.message
        : null;
  }

  return (
    <TrialBalance
      periods={periods}
      selectedPeriodId={selected?.id ?? null}
      balance={balance}
      loadFailed={loadFailed}
      loadError={loadError}
    />
  );
}
