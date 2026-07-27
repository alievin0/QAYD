import { CreateCompanyWizard } from "./create-company-wizard";

/**
 * `/onboarding` — the post-auth Create-Company wizard route (S1-11). A session is required (the middleware
 * gates it as a non-public path); a zero-company user is routed here by `resolvePostAuthDestination` after
 * auth. The wizard itself is a client island — the BFF `companies` handler reads the session server-side —
 * so this route stays a thin server shell.
 */
export default function OnboardingPage() {
  return <CreateCompanyWizard />;
}
