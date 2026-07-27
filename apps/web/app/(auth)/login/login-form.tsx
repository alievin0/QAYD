"use client";

import { useEffect, useState, useTransition } from "react";
import Link from "next/link";
import { useRouter, useSearchParams } from "next/navigation";
import { loginInputSchema } from "@qayd/types";
import {
  Button,
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
  Input,
  Label,
} from "@qayd/ui";

import { resolvePostAuthDestination } from "../../../lib/auth/post-auth";
import { useI18n } from "../../../lib/i18n/locale-provider";

/**
 * LoginForm — the real `/login` sign-in island (S1-15). Submits email + password to the BFF `login`
 * handler (which stores tokens in httpOnly cookies), then routes by company-count via the shared
 * `resolvePostAuthDestination`: zero companies → `/onboarding`, selection required → `/select-company`,
 * otherwise the validated `next` deep-link or `/dashboard`.
 *
 * Security posture the flow requires: invalid credentials surface a single generic message (no
 * user-existence leak), and a server `429` renders a live countdown driven by the response's `Retry-After`
 * header — never a client-guessed lockout. The lockout distinguishes an account lockout from an IP throttle
 * by the error `code`, and keeps the `Forgot password?` link enabled throughout.
 */

interface LoginResponseData {
  companies?: readonly unknown[];
  company_selection_required?: boolean;
}

type LockoutKind = "account" | "ip";

export function LoginForm() {
  const { t } = useI18n();
  const router = useRouter();
  const searchParams = useSearchParams();
  const nextParam = searchParams.get("next");

  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [lockout, setLockout] = useState<{
    seconds: number;
    kind: LockoutKind;
  } | null>(null);
  const [pending, startTransition] = useTransition();

  // An account lockout is the only state that disables the form and runs a countdown; an IP throttle
  // (no per-account Retry-After) shows a calmer banner but leaves the form retryable.
  const accountLocked = lockout?.kind === "account" && lockout.seconds > 0;
  const showLockout =
    lockout !== null && (lockout.kind === "ip" || lockout.seconds > 0);

  // Tick the server-authoritative lockout countdown down to zero, then re-enable the form.
  useEffect(() => {
    if (!accountLocked) return;
    const id = setInterval(() => {
      setLockout((current) =>
        current && current.seconds > 1
          ? { ...current, seconds: current.seconds - 1 }
          : null,
      );
    }, 1000);
    return () => clearInterval(id);
  }, [accountLocked]);

  const disabled = pending || accountLocked;

  function onSubmit(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setError(null);
    setLockout(null);

    const parsed = loginInputSchema.safeParse({ email, password });
    if (!parsed.success) {
      setError(t("auth.login.invalid"));
      return;
    }

    startTransition(async () => {
      try {
        const response = await fetch("/api/auth/login", {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify(parsed.data),
        });

        if (response.status === 429) {
          const header = Number(response.headers.get("Retry-After"));
          let kind: LockoutKind = "account";
          try {
            const body = (await response.json()) as { code?: string };
            if (body?.code === "RATE_LIMITED") kind = "ip";
          } catch {
            // Body is optional context; the status + header are what drive the lockout UI.
          }
          setPassword("");
          setLockout({
            seconds: Number.isFinite(header) && header > 0 ? header : 0,
            kind,
          });
          return;
        }

        if (!response.ok) {
          // Generic on purpose: 401 invalid-credentials must never leak whether the email exists.
          setPassword("");
          setError(t("auth.login.invalid"));
          return;
        }

        const body = (await response.json()) as { data?: LoginResponseData };
        const data = body.data ?? {};
        const destination = resolvePostAuthDestination(
          {
            companies: data.companies ?? [],
            company_selection_required: Boolean(
              data.company_selection_required,
            ),
          },
          nextParam,
        );
        router.push(destination);
      } catch {
        setError(t("auth.login.networkError"));
      }
    });
  }

  return (
    <Card>
      <CardHeader>
        <CardTitle>{t("auth.login.title")}</CardTitle>
        <CardDescription>{t("auth.login.subtitle")}</CardDescription>
      </CardHeader>
      <CardContent>
        <form className="flex flex-col gap-4" onSubmit={onSubmit} noValidate>
          <div className="flex flex-col gap-1.5">
            <Label htmlFor="email">{t("auth.login.email")}</Label>
            <Input
              id="email"
              type="email"
              dir="ltr"
              autoComplete="username"
              autoFocus
              required
              disabled={disabled}
              value={email}
              onChange={(event) => setEmail(event.target.value)}
            />
          </div>

          <div className="flex flex-col gap-1.5">
            <Label htmlFor="password">{t("auth.login.password")}</Label>
            <Input
              id="password"
              type="password"
              autoComplete="current-password"
              required
              disabled={disabled}
              value={password}
              onChange={(event) => setPassword(event.target.value)}
            />
          </div>

          {error ? (
            <p className="text-sm text-negative" role="alert">
              {error}
            </p>
          ) : null}

          {showLockout ? (
            <p
              className="rounded-md border border-warning bg-warning-subtle px-3 py-2 text-sm text-foreground"
              role="alert"
            >
              {lockout.kind === "ip"
                ? t("auth.login.rateLimited")
                : t("auth.login.lockout", { seconds: lockout.seconds })}
            </p>
          ) : null}

          <Button type="submit" disabled={disabled}>
            {pending ? t("auth.login.submitting") : t("auth.login.submit")}
          </Button>

          <div className="flex items-center justify-between text-sm">
            <Link
              href="/forgot-password"
              className="text-accent underline-offset-4 hover:underline"
            >
              {t("auth.login.forgotPassword")}
            </Link>
            <span className="text-muted-foreground">
              {t("auth.login.noAccount")}{" "}
              <Link
                href="/register"
                className="text-accent underline-offset-4 hover:underline"
              >
                {t("auth.login.registerLink")}
              </Link>
            </span>
          </div>
        </form>
      </CardContent>
    </Card>
  );
}
