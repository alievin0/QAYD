"use client";

import Link from "next/link";
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
 * `/forgot-password` — S1-15 ships the screen, but the password-reset backend is NOT in Sprint 1. The form
 * renders for completeness with a disabled submit and a clear "coming soon" notice; it wires to no
 * endpoint.
 *
 * TODO(tech-debt): wire `POST /auth/password/forgot` (non-committal anti-enumeration confirmation) once the
 * password-reset backend lands. Do not invent the endpoint before it exists. Should be logged as tech debt.
 */
export default function ForgotPasswordPage() {
  const { t } = useI18n();
  return (
    <Card>
      <CardHeader>
        <CardTitle>{t("auth.forgot.title")}</CardTitle>
        <CardDescription>{t("auth.forgot.subtitle")}</CardDescription>
      </CardHeader>
      <CardContent>
        <form
          className="flex flex-col gap-4"
          onSubmit={(event) => event.preventDefault()}
          aria-describedby="forgot-soon"
        >
          <div className="flex flex-col gap-1.5">
            <Label htmlFor="email">{t("auth.forgot.email")}</Label>
            <Input
              id="email"
              type="email"
              dir="ltr"
              autoComplete="username"
              disabled
            />
          </div>
          <Button type="submit" disabled>
            {t("auth.forgot.submit")}
          </Button>
          <p id="forgot-soon" className="text-sm text-muted-foreground">
            {t("auth.forgot.comingSoon")}
          </p>
          <Link
            href="/login"
            className="text-sm text-accent underline-offset-4 hover:underline"
          >
            {t("auth.forgot.backToLogin")}
          </Link>
        </form>
      </CardContent>
    </Card>
  );
}
