"use client";

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
 * Non-functional login placeholder (client-side for the localized copy). It renders the shape of the
 * sign-in panel — email, password, submit — but the form does not authenticate; the real wiring lands in
 * S1-15. Present so the auth-gate redirect has somewhere real to resolve.
 */
export function LoginPlaceholder() {
  const { t } = useI18n();
  return (
    <Card>
      <CardHeader>
        <CardTitle>{t("auth.login.title")}</CardTitle>
        <CardDescription>{t("auth.login.subtitle")}</CardDescription>
      </CardHeader>
      <CardContent>
        <form
          className="flex flex-col gap-4"
          onSubmit={(event) => event.preventDefault()}
          aria-describedby="login-todo"
        >
          <div className="flex flex-col gap-1.5">
            <Label htmlFor="email">{t("auth.login.email")}</Label>
            <Input id="email" type="email" autoComplete="email" disabled />
          </div>
          <div className="flex flex-col gap-1.5">
            <Label htmlFor="password">{t("auth.login.password")}</Label>
            <Input
              id="password"
              type="password"
              autoComplete="current-password"
              disabled
            />
          </div>
          <Button type="submit" disabled>
            {t("auth.login.submit")}
          </Button>
          <p id="login-todo" className="text-text-xs text-muted-foreground">
            {t("auth.login.todo")}
          </p>
        </form>
      </CardContent>
    </Card>
  );
}
