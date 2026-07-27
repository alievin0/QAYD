import { LoginPlaceholder } from "./login-placeholder";

/**
 * `/login` — a placeholder so the middleware redirect resolves to a real route this sprint. The real
 * sign-in journey (CSRF priming, `POST /auth/login`, MFA branch, `next` deep-link resolution, lockout
 * handling) is the next story.
 *
 * TODO(S1-15): replace this stub with the real LoginForm wired to the auth API per LOGIN_FLOW.md.
 */
export default function LoginPage() {
  return <LoginPlaceholder />;
}
