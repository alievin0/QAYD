"use client";

import { useMemo, useState } from "react";
import { useRouter } from "next/navigation";
import { Button, ChevronRightIcon, Input, cn } from "@qayd/ui";
import type { Account, AccountType, AccountTreeNode } from "@qayd/types";

import { useI18n } from "../../../../lib/i18n/locale-provider";
import {
  collectParentIds,
  countAccounts,
  filterTree,
  flattenAll,
  flattenVisible,
  type AccountRow,
} from "../../../../lib/accounting/tree";
import {
  DeactivateAccountDialog,
  NewAccountDialog,
  ReclassifyAccountDialog,
} from "./account-dialogs";

/**
 * The chart-of-accounts hub (S2-10). It renders what the API returned and nothing else: no balance is
 * computed here, no accounting rule is evaluated, and the row actions simply open dialogs that hand the
 * decision back to the server.
 *
 * **Not virtualized, deliberately.** CHART_OF_ACCOUNTS.md puts a seeded chart at 150–400 accounts and
 * the architectural ceiling at 5,000. Rendering a few hundred rows costs nothing, while a virtualizer
 * would add a runtime dependency, compute absolute offsets that need explicit RTL handling, and break
 * find-in-page and table semantics for assistive technology. That trade only becomes worth making if a
 * real chart is measured slow.
 *
 * **Depth is indentation, not nesting.** Rows live in one flat `<tbody>` with a logical
 * `padding-inline-start` per level, so every column stays aligned across levels and mirrors correctly in
 * Arabic — a nested-grid tree does neither.
 */
export interface ChartOfAccountsProps {
  accounts: AccountTreeNode[];
  accountTypes: AccountType[];
  loadFailed: boolean;
}

type View = "tree" | "flat";

export function ChartOfAccounts({
  accounts,
  accountTypes,
  loadFailed,
}: ChartOfAccountsProps) {
  const { t, locale } = useI18n();
  const router = useRouter();

  const [view, setView] = useState<View>("tree");
  const [query, setQuery] = useState("");
  const [expanded, setExpanded] = useState<Set<number>>(
    () => new Set(collectParentIds(accounts)),
  );
  const [reclassifying, setReclassifying] = useState<Account | null>(null);
  const [deactivating, setDeactivating] = useState<Account | null>(null);

  const filtered = useMemo(() => filterTree(accounts, query), [accounts, query]);

  // A search shows what it found: collapsing matches back out of view would defeat the search.
  const rows: AccountRow[] = useMemo(
    () =>
      view === "flat" || query.trim() !== ""
        ? flattenAll(filtered)
        : flattenVisible(filtered, expanded),
    [filtered, view, query, expanded],
  );

  const total = countAccounts(accounts);
  const parentIds = collectParentIds(accounts);
  const allExpanded = parentIds.length > 0 && expanded.size >= parentIds.length;

  function toggle(id: number) {
    setExpanded((current) => {
      const next = new Set(current);
      if (next.has(id)) next.delete(id);
      else next.add(id);
      return next;
    });
  }

  function refresh() {
    setReclassifying(null);
    setDeactivating(null);
    router.refresh();
  }

  const name = (account: Account) =>
    locale === "ar" ? account.name_ar : account.name_en;

  return (
    <section className="flex flex-col gap-6">
      <header className="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div className="flex flex-col gap-1">
          <h1 className="font-display text-display-sm text-ink-12">
            {t("accounting.coa.title")}
          </h1>
          <p className="text-text-sm text-muted-foreground">
            {t("accounting.coa.subtitle")}
          </p>
        </div>
        <NewAccountDialog
          accounts={accounts}
          accountTypes={accountTypes}
          onCreated={refresh}
        />
      </header>

      {loadFailed ? (
        <p role="alert" className="text-text-sm text-danger-11">
          {t("accounting.coa.loadFailed")}
        </p>
      ) : null}

      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div className="flex items-center gap-2">
          <Input
            type="search"
            value={query}
            onChange={(event) => setQuery(event.target.value)}
            placeholder={t("accounting.coa.search")}
            aria-label={t("accounting.coa.searchLabel")}
            className="w-full sm:w-72"
          />
          <span className="whitespace-nowrap text-text-sm text-muted-foreground">
            {t("accounting.coa.count", { count: total })}
          </span>
        </div>

        <div
          role="group"
          aria-label={t("accounting.coa.viewLabel")}
          className="flex items-center gap-1"
        >
          <Button
            variant={view === "tree" ? "secondary" : "ghost"}
            size="sm"
            aria-pressed={view === "tree"}
            onClick={() => setView("tree")}
          >
            {t("accounting.coa.viewTree")}
          </Button>
          <Button
            variant={view === "flat" ? "secondary" : "ghost"}
            size="sm"
            aria-pressed={view === "flat"}
            onClick={() => setView("flat")}
          >
            {t("accounting.coa.viewFlat")}
          </Button>
          {view === "tree" && query.trim() === "" ? (
            <Button
              variant="ghost"
              size="sm"
              onClick={() =>
                setExpanded(allExpanded ? new Set() : new Set(parentIds))
              }
            >
              {allExpanded
                ? t("accounting.coa.collapseAll")
                : t("accounting.coa.expandAll")}
            </Button>
          ) : null}
        </div>
      </div>

      {rows.length === 0 ? (
        <EmptyState
          title={
            total === 0
              ? t("accounting.coa.empty.title")
              : t("accounting.coa.noMatches.title")
          }
          body={
            total === 0
              ? t("accounting.coa.empty.body")
              : t("accounting.coa.noMatches.body")
          }
        />
      ) : (
        <div className="overflow-x-auto rounded-lg border border-line">
          <table className="w-full border-collapse text-text-sm">
            <thead>
              <tr className="border-b border-line text-muted-foreground">
                <th scope="col" className="px-4 py-2 text-start font-medium">
                  {t("accounting.coa.columns.code")}
                </th>
                <th scope="col" className="px-4 py-2 text-start font-medium">
                  {t("accounting.coa.columns.name")}
                </th>
                <th scope="col" className="px-4 py-2 text-start font-medium">
                  {t("accounting.coa.columns.type")}
                </th>
                <th scope="col" className="px-4 py-2 text-start font-medium">
                  {t("accounting.coa.columns.normalBalance")}
                </th>
                <th scope="col" className="px-4 py-2 text-start font-medium">
                  {t("accounting.coa.columns.status")}
                </th>
                <th scope="col" className="px-4 py-2 text-end font-medium">
                  {t("accounting.coa.columns.actions")}
                </th>
              </tr>
            </thead>
            <tbody>
              {rows.map((row) => {
                const account = row.account;
                const accountName = name(account);

                return (
                  <tr
                    key={account.id}
                    className="border-b border-line/60 last:border-0"
                  >
                    <td className="px-4 py-2 font-mono tabular-nums text-ink-12">
                      {account.code}
                    </td>
                    <td className="px-4 py-2">
                      <span
                        className="flex items-center gap-1.5"
                        style={{ paddingInlineStart: `${row.depth * 1.25}rem` }}
                      >
                        {row.hasChildren && view === "tree" ? (
                          <button
                            type="button"
                            onClick={() => toggle(account.id)}
                            aria-expanded={row.isExpanded}
                            aria-label={
                              row.isExpanded
                                ? t("accounting.coa.collapse", {
                                    name: accountName,
                                  })
                                : t("accounting.coa.expand", {
                                    name: accountName,
                                  })
                            }
                            className="rounded-sm p-0.5 text-muted-foreground hover:bg-ink-hover hover:text-ink-12 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                          >
                            <ChevronRightIcon
                              className={cn(
                                "size-3.5 transition-transform rtl:rotate-180",
                                row.isExpanded && "rotate-90 rtl:rotate-90",
                              )}
                            />
                          </button>
                        ) : (
                          <span className="inline-block size-4" aria-hidden />
                        )}
                        <span className="text-ink-12">{accountName}</span>
                        {account.is_control_account ? (
                          <span className="rounded-full border border-line px-1.5 py-0.5 text-text-xs text-muted-foreground">
                            {t("accounting.coa.control")}
                          </span>
                        ) : null}
                      </span>
                    </td>
                    <td className="px-4 py-2 text-muted-foreground">
                      {account.account_type
                        ? locale === "ar"
                          ? account.account_type.name_ar
                          : account.account_type.name_en
                        : "—"}
                    </td>
                    <td className="px-4 py-2 text-muted-foreground">
                      {t(
                        `accounting.coa.normalBalance.${account.normal_balance}`,
                      )}
                    </td>
                    <td className="px-4 py-2 text-muted-foreground">
                      {t(`accounting.coa.status.${account.status}`)}
                    </td>
                    <td className="px-4 py-2">
                      <span
                        className="flex items-center justify-end gap-1"
                        aria-label={t("accounting.account.actionsFor", {
                          name: accountName,
                        })}
                      >
                        <Button
                          variant="ghost"
                          size="sm"
                          onClick={() => setReclassifying(account)}
                        >
                          {t("accounting.account.reclassify")}
                        </Button>
                        <Button
                          variant="ghost"
                          size="sm"
                          onClick={() => setDeactivating(account)}
                        >
                          {t("accounting.account.deactivate")}
                        </Button>
                      </span>
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
      )}

      <ReclassifyAccountDialog
        account={reclassifying}
        accountTypes={accountTypes}
        onClose={() => setReclassifying(null)}
        onDone={refresh}
      />
      <DeactivateAccountDialog
        account={deactivating}
        onClose={() => setDeactivating(null)}
        onDone={refresh}
      />
    </section>
  );
}

function EmptyState({ title, body }: { title: string; body: string }) {
  return (
    <div className="rounded-lg border border-dashed border-line px-6 py-12 text-center">
      <p className="font-display text-text-lg text-ink-12">{title}</p>
      <p className="mt-1 text-text-sm text-muted-foreground">{body}</p>
    </div>
  );
}
