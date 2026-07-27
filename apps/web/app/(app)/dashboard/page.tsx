import { getSession } from "../../../lib/server/session";
import { DashboardEmptyState } from "./empty-state";

/**
 * The empty dashboard — Sprint-1's exit surface. Its only job is to prove a scoped, authenticated render:
 * the signed-in user lands inside the shell, on a page that carries zero business data. Accounting,
 * banking, and AI widgets arrive in Sprint 02; deliberately nothing here computes or shows financials.
 * The active company name is read from the resolved session so the empty state is visibly tenant-scoped.
 */
export default async function DashboardPage() {
  const me = await getSession();
  const activeUuid = me?.active_company?.uuid;
  const activeCompany = me?.companies.find(
    (company) => company.uuid === activeUuid,
  );

  return (
    <DashboardEmptyState
      companyNameEn={activeCompany?.name_en ?? null}
      companyNameAr={activeCompany?.name_ar ?? null}
    />
  );
}
