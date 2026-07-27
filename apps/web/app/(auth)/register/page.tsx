import { redirect } from "next/navigation";

import { hasSessionCookie } from "../../../lib/server/session";
import { RegisterForm } from "./register-form";

/**
 * `/register` — the create-account screen (S1-15). Anonymous-only: a visitor who already holds a session
 * is bounced forward rather than shown a sign-up form. The form itself issues no session — the user
 * verifies their email, then signs in.
 */
export default async function RegisterPage() {
  if (await hasSessionCookie()) {
    redirect("/dashboard");
  }
  return <RegisterForm />;
}
