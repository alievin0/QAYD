"use client";

import { useEffect } from "react";
import { useRouter } from "next/navigation";

/**
 * Subscribes to the company's refresh feed and re-reads the page when something is posted (S2-13).
 *
 * The one rule this component exists to honour: **the payload is discarded.** A `journal.posted`
 * message says only "something changed"; the figures then come from the API on the next render, exactly
 * as they would have without a socket. Rendering the amount that arrives here would make the socket a
 * second source of truth for the ledger — the one thing ADR-0006 forbids realtime to become — and a
 * source with no transaction, no RLS, and no ordering guarantee at that.
 *
 * Everything here degrades to nothing. Missing configuration, a socket server that is down, a dropped
 * connection: in each case the screen behaves exactly as it did before this component existed, which is
 * what makes it safe to mount on pages whose correctness must not depend on a WebSocket.
 */
export interface CompanyRefreshProps {
  /** The active company's public UUID — the channel key. Internal ids never reach the client. */
  companyId: string | null;
}

export function CompanyRefresh({ companyId }: CompanyRefreshProps) {
  const router = useRouter();

  useEffect(() => {
    if (companyId === null || companyId === "") return;

    const key = process.env.NEXT_PUBLIC_REVERB_APP_KEY;
    const host = process.env.NEXT_PUBLIC_REVERB_HOST;

    // Realtime is an enhancement; with no socket server configured there is simply nothing to do.
    if (key === undefined || key === "" || host === undefined || host === "") {
      return;
    }

    let cancelled = false;
    let teardown: (() => void) | null = null;

    // Imported lazily so the Echo and Pusher bundles stay out of every other page's payload, and so a
    // server render never touches a browser-only client.
    void (async () => {
      try {
        const [{ default: Echo }, { default: Pusher }] = await Promise.all([
          import("laravel-echo"),
          import("pusher-js"),
        ]);

        if (cancelled) return;

        const echo = new Echo({
          broadcaster: "reverb",
          client: new Pusher(key, {
            wsHost: host,
            wsPort: Number(process.env.NEXT_PUBLIC_REVERB_PORT ?? 8080),
            wssPort: Number(process.env.NEXT_PUBLIC_REVERB_PORT ?? 443),
            forceTLS:
              (process.env.NEXT_PUBLIC_REVERB_SCHEME ?? "https") === "https",
            enabledTransports: ["ws", "wss"],
            cluster: "",
            // The browser holds no token; the BFF signs the subscription using the httpOnly cookie.
            authEndpoint: "/api/broadcasting/auth",
          }),
        });

        const channel = echo.private(`company.${companyId}`);

        // No parameter: the message is a signal, and reading it would turn it into data.
        channel.listen(".journal.posted", () => {
          router.refresh();
        });

        teardown = () => {
          echo.leave(`company.${companyId}`);
          echo.disconnect();
        };
      } catch {
        // A refresh hint that cannot connect is not an error the user needs to hear about.
      }
    })();

    return () => {
      cancelled = true;
      teardown?.();
    };
  }, [companyId, router]);

  return null;
}
