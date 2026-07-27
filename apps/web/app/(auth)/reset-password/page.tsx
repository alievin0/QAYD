"use client";

import Link from "next/link";
import { useSearchParams } from "next/navigation";
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
 * `/reset-password?token=…` — S1-15 ships the screen, but the password-reset backend is NOT in Sprint 1.
 * The form renders with a disabled submit and a "coming soon" notice; it wires to no endpoint. A missing
 * `?token=` surfaces the missing-token notice (matching the eventual token-invalid state's shape).
 *
 * TODO(tech-debt): wire `POST /auth/password/reset` (sets no session; redirect to
 * `/login?message=password_reset`) once the password-reset backend lands. Should be logged as tech debt.
 */
export default function ResetPasswordPage() {
  const { t } = useI18n();
  const searchParams = useSearchParams();
  const hasToken = Boolean(searchParams.get("token"));

  return (
    <Card>
      <CardHeader>
        <CardTitle>{t("auth.reset.title")}</CardTitle>
        <CardDescription>
          {hasToken ? t("auth.reset.subtitle") : t("auth.reset.missingToken")}
        </CardDescription>
      </CardHeader>
      <CardContent>
        <form
          className="flex flex-col gap-4"
          onSubmit={(event) => event.preventDefault()}
          aria-describedby="reset-soon"
        >
          <div className="flex flex-col gap-1.5">
            <Label htmlFor="password">{t("auth.reset.password")}</Label>
            <Input
              id="password"
              type="password"
              autoComplete="new-password"
              disabled
            />
          </div>
          <div className="flex flex-col gap-1.5">
            <Label htmlFor="confirm">{t("auth.reset.confirm")}</Label>
            <Input
              id="confirm"
              type="password"
              autoComplete="new-password"
              disabled
            />
          </div>
          <Button type="submit" disabled>
            {t("auth.reset.submit")}
          </Button>
          <p id="reset-soon" className="text-sm text-muted-foreground">
            {t("auth.reset.comingSoon")}
          </p>
          <Link
            href="/login"
            className="text-sm text-accent underline-offset-4 hover:underline"
          >
            {t("auth.reset.backToLogin")}
          </Link>
        </form>
      </CardContent>
    </Card>
  );
}
