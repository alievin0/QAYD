import type { ReactNode } from "react";

import { AuthFrameHeader } from "./auth-frame-header";

/**
 * The `(auth)` layout — a minimal, centered frame for the unauthenticated screens (`/login` and, in
 * S1-15, `/mfa`, `/forgot-password`, `/reset-password`). Deliberately spare: brand, a language/theme
 * control, and a single centered panel. **No AI is present in this shell** — before a session and an
 * active company exist there is no scoped context for an AI surface to reason over, so the platform's
 * "AI is visible and labeled" rule resolves here to "AI is correctly absent" (Epic E).
 */
export default function AuthLayout({ children }: { children: ReactNode }) {
  return (
    <div className="flex min-h-dvh flex-col bg-background text-foreground">
      <AuthFrameHeader />
      <main
        id="main-content"
        className="flex flex-1 items-center justify-center px-4 py-10"
      >
        <div className="w-full max-w-md">{children}</div>
      </main>
    </div>
  );
}
