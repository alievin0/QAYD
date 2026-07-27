import { redirect } from "next/navigation";

import { hasSessionCookie } from "../../../lib/server/session";
import { LoginForm } from "./login-form";

/**
 * `/login` — the sign-in front door (S1-15). An anonymous-only guard runs server-side first: an
 * already-signed-in visitor (session cookie present) is redirected forward rather than shown a sign-in
 * form, so a stale bookmark never flashes a login panel at a live session (LOGIN_FLOW.md → Step 0).
 */
export default async function LoginPage() {
  if (await hasSessionCookie()) {
    redirect("/dashboard");
  }
  return <LoginForm />;
}
