"use client";

import { useState, useTransition } from "react";
import Link from "next/link";
import { registerInputSchema } from "@qayd/types";
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

import { useI18n } from "../../../lib/i18n/locale-provider";

/**
 * RegisterForm — the `/register` create-account island (S1-15). Submits name + email + password to the BFF
 * `register` handler, which proxies `POST /auth/register`. Registration issues no session; on success the
 * form flips in place to a "verify your email" notice (no redirect), because the next real step is the
 * user opening the mailed verification link. Field-level validation is Zod (`registerInputSchema`).
 */
export function RegisterForm() {
  const { t } = useI18n();

  const [name, setName] = useState("");
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [submittedEmail, setSubmittedEmail] = useState<string | null>(null);
  const [pending, startTransition] = useTransition();

  function onSubmit(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setError(null);

    const parsed = registerInputSchema.safeParse({ name, email, password });
    if (!parsed.success) {
      setError(t("auth.register.invalid"));
      return;
    }

    startTransition(async () => {
      try {
        const response = await fetch("/api/auth/register", {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify(parsed.data),
        });
        if (!response.ok) {
          setError(t("auth.register.error"));
          return;
        }
        setSubmittedEmail(parsed.data.email);
      } catch {
        setError(t("auth.register.error"));
      }
    });
  }

  if (submittedEmail) {
    return (
      <Card>
        <CardHeader>
          <CardTitle>{t("auth.register.checkEmailTitle")}</CardTitle>
          <CardDescription>
            {t("auth.register.checkEmailBody", { email: submittedEmail })}
          </CardDescription>
        </CardHeader>
        <CardContent>
          <Button asChild className="w-full">
            <Link href="/login">{t("auth.register.continueToLogin")}</Link>
          </Button>
        </CardContent>
      </Card>
    );
  }

  return (
    <Card>
      <CardHeader>
        <CardTitle>{t("auth.register.title")}</CardTitle>
        <CardDescription>{t("auth.register.subtitle")}</CardDescription>
      </CardHeader>
      <CardContent>
        <form className="flex flex-col gap-4" onSubmit={onSubmit} noValidate>
          <div className="flex flex-col gap-1.5">
            <Label htmlFor="name">{t("auth.register.name")}</Label>
            <Input
              id="name"
              type="text"
              autoComplete="name"
              autoFocus
              required
              disabled={pending}
              value={name}
              onChange={(event) => setName(event.target.value)}
            />
          </div>

          <div className="flex flex-col gap-1.5">
            <Label htmlFor="email">{t("auth.register.email")}</Label>
            <Input
              id="email"
              type="email"
              dir="ltr"
              autoComplete="username"
              required
              disabled={pending}
              value={email}
              onChange={(event) => setEmail(event.target.value)}
            />
          </div>

          <div className="flex flex-col gap-1.5">
            <Label htmlFor="password">{t("auth.register.password")}</Label>
            <Input
              id="password"
              type="password"
              autoComplete="new-password"
              required
              minLength={8}
              disabled={pending}
              value={password}
              onChange={(event) => setPassword(event.target.value)}
              aria-describedby="password-hint"
            />
            <p id="password-hint" className="text-xs text-muted-foreground">
              {t("auth.register.passwordHint")}
            </p>
          </div>

          {error ? (
            <p className="text-sm text-negative" role="alert">
              {error}
            </p>
          ) : null}

          <Button type="submit" disabled={pending}>
            {pending
              ? t("auth.register.submitting")
              : t("auth.register.submit")}
          </Button>

          <p className="text-sm text-muted-foreground">
            {t("auth.register.haveAccount")}{" "}
            <Link
              href="/login"
              className="text-accent underline-offset-4 hover:underline"
            >
              {t("auth.register.loginLink")}
            </Link>
          </p>
        </form>
      </CardContent>
    </Card>
  );
}
