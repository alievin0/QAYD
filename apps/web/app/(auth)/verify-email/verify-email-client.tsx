"use client";

import { useEffect, useRef, useState } from "react";
import Link from "next/link";
import {
  Button,
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@qayd/ui";

import { useI18n } from "../../../lib/i18n/locale-provider";

/**
 * VerifyEmailClient — the `/verify-email` landing island (S1-15). It takes the `id`/`hash` (and the
 * `expires`/`signature` URL-signature) params off the mailed link, posts them once to the BFF
 * `verify-email` handler on mount, and resolves to a success or failure state with a link to sign in.
 * Verification sets no session, so the destination is always `/login`.
 */
export interface VerifyEmailClientProps {
  id: string | null;
  hash: string | null;
  expires: string | null;
  signature: string | null;
}

type Status = "verifying" | "success" | "error";

export function VerifyEmailClient({
  id,
  hash,
  expires,
  signature,
}: VerifyEmailClientProps) {
  const { t } = useI18n();
  const numericId = id !== null && id.trim() !== "" ? Number(id) : NaN;
  const hasParams = Number.isInteger(numericId) && Boolean(hash);

  const [status, setStatus] = useState<Status>(
    hasParams ? "verifying" : "error",
  );
  // Guard against React 18/19 StrictMode double-invoking the effect (double POST) in dev.
  const startedRef = useRef(false);

  useEffect(() => {
    if (!hasParams || startedRef.current) return;
    startedRef.current = true;

    let cancelled = false;
    (async () => {
      try {
        const response = await fetch("/api/auth/verify-email", {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({
            id: numericId,
            hash,
            expires: expires ?? undefined,
            signature: signature ?? undefined,
          }),
        });
        if (cancelled) return;
        setStatus(response.ok ? "success" : "error");
      } catch {
        if (!cancelled) setStatus("error");
      }
    })();

    return () => {
      cancelled = true;
    };
  }, [hasParams, numericId, hash, expires, signature]);

  if (status === "verifying") {
    return (
      <Card>
        <CardHeader>
          <CardTitle>{t("app.name")}</CardTitle>
          <CardDescription>{t("auth.verify.verifying")}</CardDescription>
        </CardHeader>
        <CardContent aria-live="polite" />
      </Card>
    );
  }

  const isSuccess = status === "success";
  return (
    <Card>
      <CardHeader>
        <CardTitle>
          {isSuccess
            ? t("auth.verify.successTitle")
            : t("auth.verify.errorTitle")}
        </CardTitle>
        <CardDescription>
          {isSuccess
            ? t("auth.verify.successBody")
            : hasParams
              ? t("auth.verify.errorBody")
              : t("auth.verify.missingParams")}
        </CardDescription>
      </CardHeader>
      <CardContent>
        <Button asChild className="w-full">
          <Link href="/login">{t("auth.verify.continueToLogin")}</Link>
        </Button>
      </CardContent>
    </Card>
  );
}
