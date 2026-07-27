import type { ReactNode } from "react";

import { AppShell } from "../../components/shell/app-shell";
import { getSession } from "../../lib/server/session";

/**
 * The authenticated `(app)` layout. Resolves the session server-side (`GET /auth/me`, the client's source
 * of truth for identity, memberships, active company, and permissions) and renders the persistent
 * AppShell around every child route. Unauthenticated traffic never reaches here — `middleware.ts` gates
 * it upstream — but the shell still degrades to its empty, zero-company state if the session can't
 * resolve, so nothing throws before the login BFF (S1-15) is wired.
 */
export default async function AppLayout({ children }: { children: ReactNode }) {
  const me = await getSession();
  return <AppShell me={me}>{children}</AppShell>;
}
