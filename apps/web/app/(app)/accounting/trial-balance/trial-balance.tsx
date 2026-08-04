"use client";

import { useState } from "react";
import {
  Button,
  Label,
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
  cn,
} from "@qayd/ui";
import type {
  ComputedTrialBalanceResult,
  FiscalPeriod,
  TrialBalanceSnapshot,
} from "@qayd/types";

import { useI18n } from "../../../../lib/i18n/locale-provider";

/**
 * The trial-balance screen (S2-12).
 *
 * The screen computes nothing. Every figure on it — each account's six amounts, the two totals, the
 * variance, and the verdict that the ledger balances — arrives from the server as a decimal STRING and
 * is rendered as received. That is not caution about effort; it is the only way the screen can be right.
 * A trial balance exists to prove the ledger sums to zero, so a browser that re-added the column in
 * floating point and disagreed with Postgres in the fourth decimal would not be showing a display
 * artefact, it would be showing a false proof. `is_balanced` is likewise the server's call, measured
 * against the company's own rounding tolerance, which a client cannot know.
 *
 * Two things live here that look similar and are not. The table is the LIVE ledger, recomputed on every
 * read and never stored. A snapshot is that answer frozen — durable, versioned, and the thing an
 * accountant eventually signs. The layout keeps them apart so neither is mistaken for the other.
 */

export interface TrialBalanceProps {
  periods: FiscalPeriod[];
  selectedPeriodId: number | null;
  balance: ComputedTrialBalanceResult | null;
  /** Whether the server-side read failed at all. */
  loadFailed: boolean;
  /**
   * The server's own message for that failure, when it gave one — rendered as written, never reworded.
   * Null means the request never got an answer worth quoting, and the generic line is used instead.
   */
  loadError: string | null;
}

/** Read the `message` of a coded error envelope, if the response carried one. */
function messageOf(payload: unknown): string | null {
  if (payload === null || typeof payload !== "object") return null;
  const message = (payload as { message?: unknown }).message;
  return typeof message === "string" ? message : null;
}

function dataOf<T>(payload: unknown): T | null {
  if (payload === null || typeof payload !== "object") return null;
  return ((payload as { data?: T }).data ?? null) as T | null;
}

export function TrialBalance({
  periods,
  selectedPeriodId,
  balance: initialBalance,
  loadFailed,
  loadError,
}: TrialBalanceProps) {
  const { t, locale } = useI18n();

  const [periodId, setPeriodId] = useState<number | null>(selectedPeriodId);
  const [balance, setBalance] = useState<ComputedTrialBalanceResult | null>(
    initialBalance,
  );
  const [snapshot, setSnapshot] = useState<TrialBalanceSnapshot | null>(null);
  const [queued, setQueued] = useState(false);
  const [busy, setBusy] = useState<null | "compute" | "generate" | "refresh">(
    null,
  );
  const [error, setError] = useState<string | null>(
    loadError ?? (loadFailed ? t("accounting.trialBalance.loadFailed") : null),
  );
  const [notice, setNotice] = useState<string | null>(null);

  const accountName = (nameEn: string, nameAr: string | null) =>
    locale === "ar" ? (nameAr ?? nameEn) : nameEn;

  async function read<T>(url: string, init?: RequestInit): Promise<T | null> {
    const response = await fetch(url, init);
    const payload: unknown = await response.json().catch(() => null);

    if (!response.ok) {
      setError(
        messageOf(payload) ?? t("accounting.trialBalance.unexpectedError"),
      );
      return null;
    }

    return dataOf<T>(payload);
  }

  /** Selecting a period re-reads the ledger for it. Nothing is cached: the figures must be current. */
  async function selectPeriod(next: number) {
    setPeriodId(next);
    setBusy("compute");
    setError(null);
    setNotice(null);
    // A snapshot describes the period it was taken for; keeping it on screen here would misread.
    setSnapshot(null);
    setQueued(false);

    const data = await read<ComputedTrialBalanceResult>(
      `/api/accounting/trial-balance?fiscal_period_id=${next}`,
    );

    setBusy(null);
    if (data !== null) setBalance(data);
  }

  async function generate() {
    if (periodId === null) return;

    setBusy("generate");
    setError(null);
    setNotice(null);

    const data = await read<{
      snapshot: TrialBalanceSnapshot;
      queued: boolean;
    }>("/api/accounting/trial-balance", {
      method: "POST",
      headers: { "content-type": "application/json" },
      body: JSON.stringify({ fiscal_period_id: periodId }),
    });

    setBusy(null);
    if (data === null) return;

    setSnapshot(data.snapshot);
    setQueued(data.queued);
    setNotice(t("accounting.trialBalance.snapshot.created"));
  }

  /** Re-read a snapshot the reports queue was still filling when it was handed back. */
  async function refreshSnapshot() {
    if (snapshot === null) return;

    setBusy("refresh");
    setError(null);

    const data = await read<{ snapshot: TrialBalanceSnapshot }>(
      `/api/accounting/trial-balance/${snapshot.id}`,
    );

    setBusy(null);
    if (data === null) return;

    setSnapshot(data.snapshot);
    setQueued(data.snapshot.status === "generating");
  }

  const rows = balance?.lines ?? [];
  const hasPeriods = periods.length > 0;

  return (
    <section className="flex flex-col gap-6">
      <header className="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div className="flex flex-col gap-1">
          <h1 className="font-display text-display-sm text-ink-12">
            {t("accounting.trialBalance.title")}
          </h1>
          <p className="text-text-sm text-muted-foreground">
            {t("accounting.trialBalance.subtitle")}
          </p>
        </div>

        {hasPeriods ? (
          <div className="flex flex-wrap items-end gap-3">
            <div className="flex flex-col gap-1.5">
              <Label htmlFor="tb-period">
                {t("accounting.trialBalance.period.label")}
              </Label>
              <Select
                value={periodId === null ? "" : String(periodId)}
                disabled={busy !== null}
                onValueChange={(value) => void selectPeriod(Number(value))}
              >
                <SelectTrigger id="tb-period" className="min-w-56">
                  <SelectValue
                    placeholder={t(
                      "accounting.trialBalance.period.placeholder",
                    )}
                  />
                </SelectTrigger>
                <SelectContent>
                  {periods.map((period) => (
                    <SelectItem key={period.id} value={String(period.id)}>
                      {period.name} ·{" "}
                      {t(
                        `accounting.trialBalance.period.status.${period.status}`,
                      )}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>

            <Button
              type="button"
              disabled={periodId === null || busy !== null}
              onClick={() => void generate()}
            >
              {busy === "generate"
                ? t("accounting.trialBalance.snapshot.generating")
                : t("accounting.trialBalance.snapshot.generate")}
            </Button>
          </div>
        ) : null}
      </header>

      {error !== null ? (
        <p role="alert" className="text-text-sm text-danger-11">
          {error}
        </p>
      ) : null}

      {!hasPeriods ? (
        <div className="rounded-lg border border-dashed border-line px-6 py-12 text-center">
          <p className="font-display text-text-lg text-ink-12">
            {t("accounting.trialBalance.empty.title")}
          </p>
          <p className="mt-1 text-text-sm text-muted-foreground">
            {t("accounting.trialBalance.empty.body")}
          </p>
        </div>
      ) : busy === "compute" ? (
        <div
          role="status"
          aria-live="polite"
          className="flex flex-col gap-3 rounded-lg border border-line px-6 py-12"
        >
          <p className="text-center text-text-sm text-muted-foreground">
            {t("accounting.trialBalance.loading")}
          </p>
          {/* Skeleton rows: the table's shape, so the layout does not jump when the figures land. */}
          <div className="flex flex-col gap-2" aria-hidden="true">
            {[0, 1, 2, 3].map((row) => (
              <div key={row} className="h-4 rounded bg-ink-3" />
            ))}
          </div>
        </div>
      ) : rows.length === 0 ? (
        <div className="rounded-lg border border-dashed border-line px-6 py-12 text-center">
          <p className="font-display text-text-lg text-ink-12">
            {t("accounting.trialBalance.noActivity.title")}
          </p>
          <p className="mt-1 text-text-sm text-muted-foreground">
            {t("accounting.trialBalance.noActivity.body")}
          </p>
        </div>
      ) : (
        <>
          {balance !== null ? (
            <p className="text-text-sm text-muted-foreground">
              {t("accounting.trialBalance.asOf", { date: balance.as_of_date })}
            </p>
          ) : null}

          <div className="overflow-x-auto rounded-lg border border-line">
            <table className="w-full border-collapse text-text-sm">
              <caption className="sr-only">
                {t("accounting.trialBalance.caption")}
              </caption>
              <thead>
                <tr className="border-b border-line text-muted-foreground">
                  <th
                    scope="col"
                    rowSpan={2}
                    className="px-4 py-2 text-start font-medium"
                  >
                    {t("accounting.trialBalance.columns.code")}
                  </th>
                  <th
                    scope="col"
                    rowSpan={2}
                    className="px-4 py-2 text-start font-medium"
                  >
                    {t("accounting.trialBalance.columns.account")}
                  </th>
                  <th
                    scope="colgroup"
                    colSpan={2}
                    className="px-4 py-2 text-center font-medium"
                  >
                    {t("accounting.trialBalance.groups.opening")}
                  </th>
                  <th
                    scope="colgroup"
                    colSpan={2}
                    className="px-4 py-2 text-center font-medium"
                  >
                    {t("accounting.trialBalance.groups.movement")}
                  </th>
                  <th
                    scope="colgroup"
                    colSpan={2}
                    className="px-4 py-2 text-center font-medium"
                  >
                    {t("accounting.trialBalance.groups.closing")}
                  </th>
                </tr>
                <tr className="border-b border-line text-muted-foreground">
                  {["opening", "movement", "closing"].flatMap((group) => [
                    <th
                      key={`${group}-debit`}
                      scope="col"
                      className="px-4 py-2 text-end font-medium"
                    >
                      {t("accounting.trialBalance.columns.debit")}
                    </th>,
                    <th
                      key={`${group}-credit`}
                      scope="col"
                      className="px-4 py-2 text-end font-medium"
                    >
                      {t("accounting.trialBalance.columns.credit")}
                    </th>,
                  ])}
                </tr>
              </thead>
              <tbody>
                {rows.map((row) => (
                  <tr
                    key={row.account_id}
                    className="border-b border-line/60 last:border-0"
                  >
                    <th
                      scope="row"
                      className="px-4 py-2 text-start font-mono font-normal text-ink-12"
                    >
                      {row.account_code}
                    </th>
                    <td className="px-4 py-2 text-ink-12">
                      {accountName(row.account_name_en, row.account_name_ar)}
                      {row.is_abnormal_balance ? (
                        <span
                          title={t("accounting.trialBalance.abnormalHint")}
                          className="ms-2 rounded-full border border-warning-7 px-1.5 py-0.5 text-text-xs text-warning-11"
                        >
                          {t("accounting.trialBalance.abnormal")}
                        </span>
                      ) : null}
                    </td>
                    <td className="px-4 py-2 text-end tabular-nums text-muted-foreground">
                      {row.opening_debit}
                    </td>
                    <td className="px-4 py-2 text-end tabular-nums text-muted-foreground">
                      {row.opening_credit}
                    </td>
                    <td className="px-4 py-2 text-end tabular-nums text-muted-foreground">
                      {row.period_debit}
                    </td>
                    <td className="px-4 py-2 text-end tabular-nums text-muted-foreground">
                      {row.period_credit}
                    </td>
                    <td className="px-4 py-2 text-end tabular-nums text-ink-12">
                      {row.closing_debit}
                    </td>
                    <td className="px-4 py-2 text-end tabular-nums text-ink-12">
                      {row.closing_credit}
                    </td>
                  </tr>
                ))}
              </tbody>
              {balance !== null ? (
                <tfoot>
                  <tr className="border-t-2 border-line font-medium">
                    <th
                      scope="row"
                      colSpan={6}
                      className="px-4 py-2 text-start"
                    >
                      {t("accounting.trialBalance.total")}
                    </th>
                    <td className="px-4 py-2 text-end tabular-nums text-ink-12">
                      {balance.total_debit}
                    </td>
                    <td className="px-4 py-2 text-end tabular-nums text-ink-12">
                      {balance.total_credit}
                    </td>
                  </tr>
                </tfoot>
              ) : null}
            </table>
          </div>

          {balance !== null ? (
            <div
              role="status"
              aria-live="polite"
              className="flex flex-col gap-1"
            >
              <p
                className={cn(
                  "text-text-sm font-medium",
                  balance.is_balanced ? "text-ink-12" : "text-danger-11",
                )}
              >
                {balance.is_balanced
                  ? t("accounting.trialBalance.balanced")
                  : t("accounting.trialBalance.outOfBalance", {
                      variance: balance.variance,
                    })}
              </p>
              <p className="text-text-xs text-muted-foreground">
                {t("accounting.trialBalance.serverVerdict")}
              </p>
            </div>
          ) : null}
        </>
      )}

      {notice !== null ? (
        <p role="status" className="text-text-sm text-ink-12">
          {notice}
        </p>
      ) : null}

      {snapshot !== null ? (
        <section className="flex flex-col gap-3 rounded-lg border border-line px-4 py-4">
          <header className="flex flex-wrap items-baseline justify-between gap-2">
            <h2 className="font-display text-text-lg text-ink-12">
              {t("accounting.trialBalance.snapshot.title", { id: snapshot.id })}
            </h2>
            <p className="text-text-sm text-muted-foreground">
              {t("accounting.trialBalance.snapshot.version", {
                version: snapshot.version,
              })}
            </p>
          </header>

          <dl className="flex flex-wrap gap-x-6 gap-y-2 text-text-sm">
            <div className="flex items-center gap-2">
              <dt className="text-muted-foreground">
                {t("accounting.trialBalance.snapshot.status")}
              </dt>
              <dd className="text-ink-12">
                {t(
                  `accounting.trialBalance.snapshot.statuses.${snapshot.status}`,
                )}
              </dd>
            </div>
            <div className="flex items-center gap-2">
              <dt className="text-muted-foreground">
                {t("accounting.trialBalance.snapshot.type")}
              </dt>
              <dd className="text-ink-12">{snapshot.type}</dd>
            </div>
            <div className="flex items-center gap-2">
              <dt className="text-muted-foreground">
                {t("accounting.trialBalance.snapshot.totalDebit")}
              </dt>
              <dd className="tabular-nums text-ink-12">
                {snapshot.total_debit}
              </dd>
            </div>
            <div className="flex items-center gap-2">
              <dt className="text-muted-foreground">
                {t("accounting.trialBalance.snapshot.totalCredit")}
              </dt>
              <dd className="tabular-nums text-ink-12">
                {snapshot.total_credit}
              </dd>
            </div>
            <div className="flex items-center gap-2">
              <dt className="text-muted-foreground">
                {t("accounting.trialBalance.snapshot.variance")}
              </dt>
              <dd className="tabular-nums text-ink-12">{snapshot.variance}</dd>
            </div>
          </dl>

          {queued ? (
            <div className="flex flex-wrap items-center gap-3">
              <p className="text-text-sm text-muted-foreground">
                {t("accounting.trialBalance.snapshot.queued")}
              </p>
              <Button
                type="button"
                variant="secondary"
                size="sm"
                disabled={busy !== null}
                onClick={() => void refreshSnapshot()}
              >
                {busy === "refresh"
                  ? t("accounting.trialBalance.snapshot.refreshing")
                  : t("accounting.trialBalance.snapshot.refresh")}
              </Button>
            </div>
          ) : null}

          <p className="text-text-xs text-muted-foreground">
            {t("accounting.trialBalance.snapshot.explainer")}
          </p>
        </section>
      ) : null}
    </section>
  );
}
