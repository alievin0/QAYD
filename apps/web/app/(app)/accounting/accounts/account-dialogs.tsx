"use client";

import { useState, type FormEvent, type ReactNode } from "react";
import {
  Button,
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
  Input,
  Label,
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@qayd/ui";
import type { Account, AccountType, AccountTreeNode } from "@qayd/types";

import { useI18n } from "../../../../lib/i18n/locale-provider";
import { flattenAll } from "../../../../lib/accounting/tree";

/**
 * The three chart-of-accounts dialogs (S2-10): create, reclassify, deactivate.
 *
 * They share one rule, which is the point of the file. **No accounting validation happens here.** The
 * dialogs collect input, POST it to the BFF, and render whatever the server said. When the API refuses
 * — a duplicate code, a parent in another company, an account that already carries posted entries — its
 * coded message is shown inline, verbatim. Re-implementing any of those checks in the browser would
 * create a second source of truth that drifts the first time a rule changes server-side.
 *
 * The only client-side gate is `required` on the inputs, which is a form affordance rather than a
 * business rule: it stops an empty submit from becoming a pointless round trip.
 */

/** POST to a BFF route and return the server's message on failure. */
async function submitJson(
  url: string,
  body?: unknown,
): Promise<{ ok: true } | { ok: false; message: string | null }> {
  try {
    const response = await fetch(url, {
      method: "POST",
      headers: { "content-type": "application/json" },
      body: JSON.stringify(body ?? {}),
    });

    if (response.ok) return { ok: true };

    const payload: unknown = await response.json().catch(() => null);
    const message =
      payload !== null &&
      typeof payload === "object" &&
      "message" in payload &&
      typeof (payload as { message: unknown }).message === "string"
        ? (payload as { message: string }).message
        : null;

    return { ok: false, message };
  } catch {
    return { ok: false, message: null };
  }
}

function ErrorNote({ children }: { children: ReactNode }) {
  return (
    <p role="alert" className="text-text-sm text-danger-11">
      {children}
    </p>
  );
}

export interface NewAccountDialogProps {
  accounts: AccountTreeNode[];
  accountTypes: AccountType[];
  onCreated: () => void;
}

/** The New Account dialog — the only way to add an account from this screen. */
export function NewAccountDialog({
  accounts,
  accountTypes,
  onCreated,
}: NewAccountDialogProps) {
  const { t, locale } = useI18n();
  const [open, setOpen] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [typeId, setTypeId] = useState("");
  const [parentId, setParentId] = useState("");

  const parents = flattenAll(accounts);

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const form = new FormData(event.currentTarget);
    setBusy(true);
    setError(null);

    const result = await submitJson("/api/accounting/accounts", {
      account_type_id: Number(typeId),
      code: String(form.get("code") ?? "").trim(),
      name_en: String(form.get("name_en") ?? "").trim(),
      name_ar: String(form.get("name_ar") ?? "").trim(),
      ...(parentId === "" ? {} : { parent_id: Number(parentId) }),
    });

    setBusy(false);

    if (!result.ok) {
      setError(result.message ?? t("accounting.account.unexpectedError"));
      return;
    }

    setOpen(false);
    setTypeId("");
    setParentId("");
    onCreated();
  }

  return (
    <Dialog
      open={open}
      onOpenChange={(next) => {
        setOpen(next);
        if (!next) setError(null);
      }}
    >
      <Button onClick={() => setOpen(true)}>
        {t("accounting.account.create")}
      </Button>

      <DialogContent closeLabel={t("accounting.account.close")}>
        <DialogHeader>
          <DialogTitle>{t("accounting.account.createTitle")}</DialogTitle>
          <DialogDescription>
            {t("accounting.account.createDescription")}
          </DialogDescription>
        </DialogHeader>

        <form onSubmit={handleSubmit} className="mt-4 flex flex-col gap-4">
          <div className="flex flex-col gap-1.5">
            <Label htmlFor="account-code">{t("accounting.account.code")}</Label>
            <Input
              id="account-code"
              name="code"
              required
              placeholder={t("accounting.account.codePlaceholder")}
            />
          </div>

          <div className="flex flex-col gap-1.5">
            <Label htmlFor="account-name-en">
              {t("accounting.account.nameEn")}
            </Label>
            <Input id="account-name-en" name="name_en" required dir="ltr" />
          </div>

          <div className="flex flex-col gap-1.5">
            <Label htmlFor="account-name-ar">
              {t("accounting.account.nameAr")}
            </Label>
            <Input id="account-name-ar" name="name_ar" required dir="rtl" />
          </div>

          <div className="flex flex-col gap-1.5">
            <Label htmlFor="account-type">{t("accounting.account.type")}</Label>
            <Select value={typeId} onValueChange={setTypeId} required>
              <SelectTrigger id="account-type">
                <SelectValue
                  placeholder={t("accounting.account.typePlaceholder")}
                />
              </SelectTrigger>
              <SelectContent>
                {accountTypes.map((type) => (
                  <SelectItem key={type.id} value={String(type.id)}>
                    {locale === "ar" ? type.name_ar : type.name_en}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>

          <div className="flex flex-col gap-1.5">
            <Label htmlFor="account-parent">
              {t("accounting.account.parent")}
            </Label>
            <Select value={parentId} onValueChange={setParentId}>
              <SelectTrigger id="account-parent">
                <SelectValue placeholder={t("accounting.account.parentNone")} />
              </SelectTrigger>
              <SelectContent>
                {parents.map((row) => (
                  <SelectItem
                    key={row.account.id}
                    value={String(row.account.id)}
                  >
                    {row.account.code} ·{" "}
                    {locale === "ar"
                      ? row.account.name_ar
                      : row.account.name_en}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>

          {error !== null ? <ErrorNote>{error}</ErrorNote> : null}

          <DialogFooter>
            <Button
              type="button"
              variant="ghost"
              onClick={() => setOpen(false)}
              disabled={busy}
            >
              {t("accounting.account.cancel")}
            </Button>
            <Button type="submit" disabled={busy}>
              {busy
                ? t("accounting.account.submitting")
                : t("accounting.account.submit")}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}

export interface AccountActionDialogProps {
  account: Account | null;
  accountTypes: AccountType[];
  onClose: () => void;
  onDone: () => void;
}

/** Reclassify — the server refuses if the account already carries posted entries. */
export function ReclassifyAccountDialog({
  account,
  accountTypes,
  onClose,
  onDone,
}: AccountActionDialogProps) {
  const { t, locale } = useI18n();
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [typeId, setTypeId] = useState("");

  const name = account
    ? locale === "ar"
      ? account.name_ar
      : account.name_en
    : "";

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!account) return;

    setBusy(true);
    setError(null);

    const result = await submitJson(
      `/api/accounting/accounts/${account.id}/reclassify`,
      { account_type_id: Number(typeId) },
    );

    setBusy(false);

    if (!result.ok) {
      // The backend's own 422 — rendered as it was written, not reinterpreted.
      setError(result.message ?? t("accounting.account.unexpectedError"));
      return;
    }

    setTypeId("");
    onDone();
  }

  return (
    <Dialog
      open={account !== null}
      onOpenChange={(next) => {
        if (!next) {
          setError(null);
          onClose();
        }
      }}
    >
      <DialogContent closeLabel={t("accounting.account.close")}>
        <DialogHeader>
          <DialogTitle>
            {t("accounting.account.reclassifyTitle", { name })}
          </DialogTitle>
          <DialogDescription>
            {t("accounting.account.reclassifyDescription")}
          </DialogDescription>
        </DialogHeader>

        <form onSubmit={handleSubmit} className="mt-4 flex flex-col gap-4">
          <div className="flex flex-col gap-1.5">
            <Label htmlFor="reclassify-type">
              {t("accounting.account.type")}
            </Label>
            <Select value={typeId} onValueChange={setTypeId} required>
              <SelectTrigger id="reclassify-type">
                <SelectValue
                  placeholder={t("accounting.account.typePlaceholder")}
                />
              </SelectTrigger>
              <SelectContent>
                {accountTypes.map((type) => (
                  <SelectItem key={type.id} value={String(type.id)}>
                    {locale === "ar" ? type.name_ar : type.name_en}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>

          {error !== null ? <ErrorNote>{error}</ErrorNote> : null}

          <DialogFooter>
            <Button
              type="button"
              variant="ghost"
              onClick={onClose}
              disabled={busy}
            >
              {t("accounting.account.cancel")}
            </Button>
            <Button type="submit" disabled={busy}>
              {busy
                ? t("accounting.account.submitting")
                : t("accounting.account.reclassifySubmit")}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}

export type DeactivateAccountDialogProps = Omit<
  AccountActionDialogProps,
  "accountTypes"
>;

/** Deactivate — a confirmation, because it changes what the rest of the product will accept. */
export function DeactivateAccountDialog({
  account,
  onClose,
  onDone,
}: DeactivateAccountDialogProps) {
  const { t, locale } = useI18n();
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const name = account
    ? locale === "ar"
      ? account.name_ar
      : account.name_en
    : "";

  async function handleConfirm() {
    if (!account) return;

    setBusy(true);
    setError(null);

    const result = await submitJson(
      `/api/accounting/accounts/${account.id}/deactivate`,
    );

    setBusy(false);

    if (!result.ok) {
      setError(result.message ?? t("accounting.account.unexpectedError"));
      return;
    }

    onDone();
  }

  return (
    <Dialog
      open={account !== null}
      onOpenChange={(next) => {
        if (!next) {
          setError(null);
          onClose();
        }
      }}
    >
      <DialogContent closeLabel={t("accounting.account.close")}>
        <DialogHeader>
          <DialogTitle>
            {t("accounting.account.deactivateTitle", { name })}
          </DialogTitle>
          <DialogDescription>
            {t("accounting.account.deactivateDescription")}
          </DialogDescription>
        </DialogHeader>

        {error !== null ? (
          <div className="mt-4">
            <ErrorNote>{error}</ErrorNote>
          </div>
        ) : null}

        <DialogFooter>
          <Button
            type="button"
            variant="ghost"
            onClick={onClose}
            disabled={busy}
          >
            {t("accounting.account.cancel")}
          </Button>
          <Button type="button" onClick={handleConfirm} disabled={busy}>
            {busy
              ? t("accounting.account.submitting")
              : t("accounting.account.deactivateSubmit")}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
