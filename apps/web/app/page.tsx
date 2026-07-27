import { redirect } from "next/navigation";

import { DEFAULT_POST_AUTH_PATH } from "../lib/auth/next-param";

/**
 * Root `/` is the entry hop, not a screen. It forwards to the default in-app destination; the auth-gate
 * middleware then either lets the request through (session present) or bounces it to `/login`. The full
 * post-auth resolver (onboarding gate, company selection, AI-home vs conventional dashboard) lands with
 * the auth screens in S1-15.
 */
export default function RootPage(): never {
  redirect(DEFAULT_POST_AUTH_PATH);
}
