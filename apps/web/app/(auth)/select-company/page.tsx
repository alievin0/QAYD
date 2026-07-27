import { redirect } from "next/navigation";

import { sanitizeNext } from "../../../lib/auth/next-param";
import { getSession } from "../../../lib/server/session";
import { CompanySelectList } from "./select-company-list";

/**
 * `/select-company` — the full-page company picker (S1-15). Unlike the other `(auth)` screens this one
 * *requires* a session: it reads the caller's memberships from `/auth/me` server-side. An absent/invalid
 * session redirects to `/login`; a zero-company session is sent to onboarding; a single-company session
 * skips the picker entirely (a one-row picker never needs choosing).
 */
export default async function SelectCompanyPage({
  searchParams,
}: {
  searchParams: Promise<Record<string, string | string[] | undefined>>;
}) {
  const me = await getSession();
  if (me === null) {
    redirect("/login");
  }
  if (me.companies.length === 0) {
    redirect("/onboarding");
  }

  const params = await searchParams;
  const rawNext = Array.isArray(params.next) ? params.next[0] : params.next;
  const next = sanitizeNext(rawNext ?? null);

  if (me.companies.length === 1) {
    redirect(next ?? "/dashboard");
  }

  return <CompanySelectList companies={me.companies} next={next} />;
}
