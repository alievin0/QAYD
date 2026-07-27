"use client";

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
 * `/mfa` — a placeholder. MFA is out of Sprint-1 scope: the login path is built with the MFA branch stubbed
 * to the non-MFA route (SPRINT_01.md → Out of scope), so a login never issues an `mfa_required` in this
 * sprint and this screen is unreachable in the happy path. It exists so the route resolves rather than
 * 404s, and so the middleware's public-prefix list has a real target.
 *
 * TODO(tech-debt): build the real `MfaVerifyForm` (TOTP/SMS/backup, `POST /auth/mfa/verify`) with the full
 * MFA backend in the security-hardening track. Should be logged as tech debt.
 */
export default function MfaPage() {
  const { t } = useI18n();
  return (
    <Card>
      <CardHeader>
        <CardTitle>{t("auth.mfa.title")}</CardTitle>
        <CardDescription>{t("auth.mfa.subtitle")}</CardDescription>
      </CardHeader>
      <CardContent className="flex flex-col gap-4">
        <p className="text-sm text-muted-foreground">
          {t("auth.mfa.comingSoon")}
        </p>
        <Button asChild>
          <Link href="/login">{t("auth.mfa.backToLogin")}</Link>
        </Button>
      </CardContent>
    </Card>
  );
}
