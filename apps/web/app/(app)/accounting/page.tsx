import { redirect } from "next/navigation";

/**
 * `/accounting` has no landing page of its own (S2-10) — the module's first surface is the chart of
 * accounts, so the bare route redirects rather than rendering an index nobody would read.
 */
export default function AccountingIndexPage() {
  redirect("/accounting/accounts");
}
