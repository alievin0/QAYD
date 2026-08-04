"use client";

import { useMemo, useRef, useState } from "react";
import { useRouter } from "next/navigation";
import {
  Button,
  Input,
  Label,
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
  cn,
} from "@qayd/ui";
import type { Account, JournalEntry } from "@qayd/types";

import { useI18n } from "../../../../lib/i18n/locale-provider";
import {
  deriveBalance,
  isCompleteLine,
  type DraftLine,
} from "../../../../lib/accounting/balance";

/**
 * The journal-entry editor (S2-11).
 *
 * The grid is the screen, and one rule governs all of it: **the server decides.** `deriveBalance` runs
 * on every keystroke so the person typing can see their entry does not add up, and it does exactly one
 * thing with that knowledge — it disables Post. It never decides an entry IS postable, never rewrites a
 * server message, and never suppresses one. When the API refuses, its coded message is rendered
 * verbatim, `balance_mismatch` included, because the posting engine re-derives the totals from the
 * persisted lines and is the only authority on the answer.
 *
 * Money never becomes a `number` here. Amounts stay strings from the input to the request body, and the
 * running total is summed in scaled `bigint` — a float would disagree with `NUMERIC(19,4)` in the fourth
 * decimal place, which in a ledger is not a rounding difference but a wrong number.
 *
 * Posting carries an `Idempotency-Key` minted once per attempt and reused across retries, so an
 * impatient second click or a dropped connection cannot produce two postings.
 */

/** Statuses whose entries may still be edited — mirrors the server's own editable set. */
const EDITABLE_STATUSES = ["draft", "rejected"];

const ENTRY_TYPES = ["manual", "adjustment"];

export interface JournalEditorProps {
  entry: JournalEntry | null;
  accounts: Account[];
  loadFailed: boolean;
}

function emptyLine(): DraftLine {
  return { accountId: null, debit: "", credit: "", description: "" };
}

function linesFrom(entry: JournalEntry | null): DraftLine[] {
  if (!entry?.lines || entry.lines.length === 0) {
    return [emptyLine(), emptyLine()];
  }

  return entry.lines.map((line) => ({
    accountId: line.account_id,
    debit: line.debit,
    credit: line.credit,
    description: line.description ?? "",
  }));
}

export function JournalEditor({
  entry,
  accounts,
  loadFailed,
}: JournalEditorProps) {
  const { t, locale } = useI18n();
  const router = useRouter();

  const [entryId, setEntryId] = useState<number | null>(entry?.id ?? null);
  const [version, setVersion] = useState<number>(entry?.version ?? 0);
  const [status, setStatus] = useState<string>(entry?.status ?? "draft");
  const [journalDate, setJournalDate] = useState(
    entry?.journal_date ?? new Date().toISOString().slice(0, 10),
  );
  const [entryType, setEntryType] = useState(entry?.entry_type ?? "manual");
  const [reference, setReference] = useState(entry?.reference ?? "");
  const [memo, setMemo] = useState(entry?.memo ?? "");
  const [lines, setLines] = useState<DraftLine[]>(() => linesFrom(entry));

  const [busy, setBusy] = useState<null | "save" | "submit" | "post">(null);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);

  // One key per posting attempt, reused across retries so a repeat cannot post twice.
  const postKey = useRef<string | null>(null);

  const balance = useMemo(() => deriveBalance(lines), [lines]);
  const readOnly = !EDITABLE_STATUSES.includes(status);
  const accountName = (account: Account) =>
    locale === "ar" ? account.name_ar : account.name_en;

  function patchLine(index: number, patch: Partial<DraftLine>) {
    setLines((current) =>
      current.map((line, i) => (i === index ? { ...line, ...patch } : line)),
    );
  }

  /** An amount on one side clears the other: a line is debit OR credit, never both. */
  function setAmount(index: number, side: "debit" | "credit", value: string) {
    patchLine(
      index,
      side === "debit"
        ? { debit: value, credit: "" }
        : { credit: value, debit: "" },
    );
  }

  async function send(
    url: string,
    method: "POST" | "PATCH",
    body: unknown,
    headers: Record<string, string> = {},
  ): Promise<JournalEntry | null> {
    const response = await fetch(url, {
      method,
      headers: { "content-type": "application/json", ...headers },
      body: JSON.stringify(body),
    });

    const payload: unknown = await response.json().catch(() => null);

    if (!response.ok) {
      // The server's own message, rendered as written — never reworded, never swallowed.
      const message =
        payload !== null &&
        typeof payload === "object" &&
        "message" in payload &&
        typeof (payload as { message: unknown }).message === "string"
          ? (payload as { message: string }).message
          : t("accounting.journal.unexpectedError");
      setError(message);
      return null;
    }

    const data =
      payload !== null && typeof payload === "object" && "data" in payload
        ? (payload as { data: { journal_entry?: JournalEntry } | null }).data
        : null;

    return data?.journal_entry ?? null;
  }

  /** Reconcile local state with what the server actually stored. */
  function adopt(saved: JournalEntry, message: string) {
    setEntryId(saved.id);
    setVersion(saved.version);
    setStatus(saved.status);
    setLines(linesFrom(saved));
    setNotice(message);
    setError(null);
    router.refresh();
  }

  function payload() {
    return {
      journal_date: journalDate,
      entry_type: entryType,
      currency_code: "KWD",
      reference: reference === "" ? null : reference,
      memo: memo === "" ? null : memo,
      lines: lines.filter(isCompleteLine).map((line) => ({
        account_id: line.accountId,
        debit: line.debit === "" ? "0" : line.debit,
        credit: line.credit === "" ? "0" : line.credit,
        description: line.description === "" ? null : line.description,
      })),
    };
  }

  async function saveDraft() {
    setBusy("save");
    setError(null);
    setNotice(null);

    // Optimistic: show the version the server will hand back on success. A failure restores it, so the
    // screen never keeps a version the server did not agree to.
    const previousVersion = version;
    setVersion((current) => current + 1);

    const saved =
      entryId === null
        ? await send("/api/accounting/journal-entries", "POST", payload())
        : await send(`/api/accounting/journal-entries/${entryId}`, "PATCH", {
            ...payload(),
            version: previousVersion,
          });

    setBusy(null);

    if (saved === null) {
      setVersion(previousVersion);
      return;
    }

    adopt(saved, t("accounting.journal.actions.saved"));
  }

  async function submit() {
    if (entryId === null) return;

    setBusy("submit");
    setError(null);
    setNotice(null);

    const saved = await send(
      `/api/accounting/journal-entries/${entryId}/submit`,
      "POST",
      { version },
    );

    setBusy(null);
    if (saved !== null) adopt(saved, t("accounting.journal.actions.submitted"));
  }

  async function post() {
    if (entryId === null) return;

    setBusy("post");
    setError(null);
    setNotice(null);

    postKey.current ??=
      globalThis.crypto?.randomUUID?.() ?? `post-${entryId}-${Date.now()}`;

    const saved = await send(
      `/api/accounting/journal-entries/${entryId}/post`,
      "POST",
      {},
      { "Idempotency-Key": postKey.current },
    );

    setBusy(null);

    if (saved !== null) {
      postKey.current = null;
      adopt(saved, t("accounting.journal.actions.posted"));
    }
  }

  const canPost =
    entryId !== null && balance.isBalanced && !readOnly && busy === null;

  return (
    <section className="flex flex-col gap-6">
      <header className="flex flex-col gap-1">
        <h1 className="font-display text-display-sm text-ink-12">
          {entry === null
            ? t("accounting.journal.editor.newTitle")
            : t("accounting.journal.editor.editTitle", {
                number: entry.journal_number,
              })}
        </h1>
        <p className="text-text-sm text-muted-foreground">
          {t(`accounting.journal.status.${status}`)}
        </p>
      </header>

      {loadFailed ? (
        <p role="alert" className="text-text-sm text-danger-11">
          {t("accounting.journal.loadFailed")}
        </p>
      ) : null}

      {readOnly ? (
        <p className="rounded-md border border-line px-4 py-3 text-text-sm text-muted-foreground">
          {t("accounting.journal.editor.readOnly", {
            status: t(`accounting.journal.status.${status}`),
          })}
        </p>
      ) : null}

      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <div className="flex flex-col gap-1.5">
          <Label htmlFor="je-date">{t("accounting.journal.editor.date")}</Label>
          <Input
            id="je-date"
            type="date"
            value={journalDate}
            disabled={readOnly}
            onChange={(event) => setJournalDate(event.target.value)}
          />
        </div>

        <div className="flex flex-col gap-1.5">
          <Label htmlFor="je-type">
            {t("accounting.journal.editor.entryType")}
          </Label>
          <Select
            value={entryType}
            onValueChange={setEntryType}
            disabled={readOnly}
          >
            <SelectTrigger id="je-type">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              {ENTRY_TYPES.map((type) => (
                <SelectItem key={type} value={type}>
                  {type}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>

        <div className="flex flex-col gap-1.5">
          <Label htmlFor="je-reference">
            {t("accounting.journal.editor.reference")}
          </Label>
          <Input
            id="je-reference"
            value={reference}
            disabled={readOnly}
            onChange={(event) => setReference(event.target.value)}
          />
        </div>

        <div className="flex flex-col gap-1.5">
          <Label htmlFor="je-memo">{t("accounting.journal.editor.memo")}</Label>
          <Input
            id="je-memo"
            value={memo}
            disabled={readOnly}
            placeholder={t("accounting.journal.editor.memoPlaceholder")}
            onChange={(event) => setMemo(event.target.value)}
          />
        </div>
      </div>

      <div className="overflow-x-auto rounded-lg border border-line">
        <table className="w-full border-collapse text-text-sm">
          <caption className="sr-only">
            {t("accounting.journal.grid.caption")}
          </caption>
          <thead>
            <tr className="border-b border-line text-muted-foreground">
              <th scope="col" className="px-3 py-2 text-start font-medium">
                {t("accounting.journal.grid.account")}
              </th>
              <th scope="col" className="px-3 py-2 text-start font-medium">
                {t("accounting.journal.grid.description")}
              </th>
              <th scope="col" className="px-3 py-2 text-end font-medium">
                {t("accounting.journal.grid.debit")}
              </th>
              <th scope="col" className="px-3 py-2 text-end font-medium">
                {t("accounting.journal.grid.credit")}
              </th>
              <th scope="col" className="px-3 py-2 text-end font-medium">
                <span className="sr-only">
                  {t("accounting.journal.grid.remove")}
                </span>
              </th>
            </tr>
          </thead>
          <tbody>
            {lines.map((line, index) => {
              const lineLabel = `${t("accounting.journal.grid.lineNumber")} ${index + 1}`;

              return (
                <tr
                  key={index}
                  className="border-b border-line/60 last:border-0"
                >
                  <td className="px-3 py-2">
                    <Select
                      value={
                        line.accountId === null ? "" : String(line.accountId)
                      }
                      disabled={readOnly}
                      onValueChange={(value) =>
                        patchLine(index, { accountId: Number(value) })
                      }
                    >
                      <SelectTrigger
                        aria-label={`${t("accounting.journal.grid.account")} — ${lineLabel}`}
                      >
                        <SelectValue
                          placeholder={t(
                            "accounting.journal.grid.accountPlaceholder",
                          )}
                        />
                      </SelectTrigger>
                      <SelectContent>
                        {accounts.map((account) => (
                          <SelectItem
                            key={account.id}
                            value={String(account.id)}
                          >
                            {account.code} · {accountName(account)}
                          </SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                  </td>
                  <td className="px-3 py-2">
                    <Input
                      value={line.description}
                      disabled={readOnly}
                      aria-label={`${t("accounting.journal.grid.description")} — ${lineLabel}`}
                      onChange={(event) =>
                        patchLine(index, { description: event.target.value })
                      }
                    />
                  </td>
                  <td className="px-3 py-2">
                    <Input
                      value={line.debit}
                      disabled={readOnly}
                      inputMode="decimal"
                      dir="ltr"
                      className="text-end tabular-nums"
                      aria-label={`${t("accounting.journal.grid.debit")} — ${lineLabel}`}
                      onChange={(event) =>
                        setAmount(index, "debit", event.target.value)
                      }
                    />
                  </td>
                  <td className="px-3 py-2">
                    <Input
                      value={line.credit}
                      disabled={readOnly}
                      inputMode="decimal"
                      dir="ltr"
                      className="text-end tabular-nums"
                      aria-label={`${t("accounting.journal.grid.credit")} — ${lineLabel}`}
                      onChange={(event) =>
                        setAmount(index, "credit", event.target.value)
                      }
                    />
                  </td>
                  <td className="px-3 py-2 text-end">
                    <Button
                      type="button"
                      variant="ghost"
                      size="sm"
                      disabled={readOnly || lines.length <= 1}
                      aria-label={t("accounting.journal.grid.removeLine", {
                        number: index + 1,
                      })}
                      onClick={() =>
                        setLines((current) =>
                          current.filter((_, i) => i !== index),
                        )
                      }
                    >
                      {t("accounting.journal.grid.remove")}
                    </Button>
                  </td>
                </tr>
              );
            })}
          </tbody>
        </table>
      </div>

      <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
        <Button
          type="button"
          variant="secondary"
          size="sm"
          disabled={readOnly}
          onClick={() => setLines((current) => [...current, emptyLine()])}
        >
          {t("accounting.journal.grid.addLine")}
        </Button>
        <p className="text-text-xs text-muted-foreground">
          {t("accounting.journal.grid.keyboardHint")}
        </p>
      </div>

      {/* A live region: the running balance is the number the user watches while typing. */}
      <div
        role="status"
        aria-live="polite"
        className="flex flex-col gap-2 rounded-lg border border-line px-4 py-3 sm:flex-row sm:items-center sm:justify-between"
      >
        <dl className="flex flex-wrap items-center gap-x-6 gap-y-1 text-text-sm">
          <div className="flex items-center gap-2">
            <dt className="text-muted-foreground">
              {t("accounting.journal.balance.totalDebit")}
            </dt>
            <dd className="tabular-nums text-ink-12">{balance.totalDebit}</dd>
          </div>
          <div className="flex items-center gap-2">
            <dt className="text-muted-foreground">
              {t("accounting.journal.balance.totalCredit")}
            </dt>
            <dd className="tabular-nums text-ink-12">{balance.totalCredit}</dd>
          </div>
          <div className="flex items-center gap-2">
            <dt className="text-muted-foreground">
              {t("accounting.journal.balance.difference")}
            </dt>
            <dd className="tabular-nums text-ink-12">{balance.difference}</dd>
          </div>
        </dl>
        <p
          className={cn(
            "text-text-sm font-medium",
            balance.isBalanced ? "text-ink-12" : "text-danger-11",
          )}
        >
          {balance.isBalanced
            ? t("accounting.journal.balance.balanced")
            : t("accounting.journal.balance.outOfBalance", {
                difference: balance.difference,
              })}
        </p>
      </div>

      <p className="text-text-xs text-muted-foreground">
        {t("accounting.journal.balance.advisory")}
      </p>

      {error !== null ? (
        <p role="alert" className="text-text-sm text-danger-11">
          {error}
        </p>
      ) : null}

      {notice !== null ? (
        <p role="status" className="text-text-sm text-ink-12">
          {notice}
        </p>
      ) : null}

      <div className="flex flex-wrap items-center gap-2">
        <Button
          type="button"
          disabled={readOnly || busy !== null}
          onClick={saveDraft}
        >
          {busy === "save"
            ? t("accounting.journal.actions.saving")
            : t("accounting.journal.actions.saveDraft")}
        </Button>

        <Button
          type="button"
          variant="secondary"
          disabled={readOnly || entryId === null || busy !== null}
          onClick={submit}
        >
          {busy === "submit"
            ? t("accounting.journal.actions.submitting")
            : t("accounting.journal.actions.submit")}
        </Button>

        <Button
          type="button"
          variant="secondary"
          disabled={!canPost}
          onClick={post}
        >
          {busy === "post"
            ? t("accounting.journal.actions.posting")
            : t("accounting.journal.actions.post")}
        </Button>
      </div>
    </section>
  );
}
