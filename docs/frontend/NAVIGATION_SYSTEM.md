# Navigation System — QAYD Frontend
Version: 1.0
Status: Design Specification
Module: Frontend
Submodule: NAVIGATION_SYSTEM
---

# Purpose

Every screen QAYD ships — Dashboard, Journal Entries, Trial Balance, Bank Reconciliation, the AI Command Center, Payroll Runs, forty-plus routes in total — is reached through exactly one navigation system, never a screen-specific one. This document is the binding specification for that system: the persistent app shell's navigation surfaces (Sidebar, Topbar), the primary module map (which routes exist as first-class nav destinations and under which permission), the Company & Branch Switcher, the ⌘K Command Palette, breadcrumbs and page headers, how the App Router's route tree resolves to shareable deep links, how RBAC hides or disables navigation rather than merely styling it differently, how notifications and AI activity surface in the chrome without becoming a second inbox, how the whole system degrades to mobile, how it mirrors under Arabic RTL without becoming a translated afterthought, and how it operates end-to-end from a keyboard with no mouse at all.

This document sits deliberately downstream of four sibling specifications and does not re-derive what they already own. `LAYOUT_SYSTEM.md` owns the shell's pixel geometry — the CSS Grid regions (Sidebar, Topbar, Content, AI Rail), their exact widths (`272px`/`72px`/`0`, `64px`/`56px`, `360px`), breakpoints, and z-index scale — and this document treats those numbers as fixed inputs. `FRONTEND_ARCHITECTURE.md` owns the App Router route tree, the RSC/client-component boundary, the session model, and `lib/api-client.ts`; this document consumes that route tree as the map of legal destinations and never invents a route that tree does not already define. `COMPONENT_LIBRARY.md` owns the design tokens (`--qayd-*` CSS variables), the `cva` variant system, and the shadcn/ui primitives (`Button`, `Dialog`, `Sheet`, `Command`, `Popover`, `Badge`, `DropdownMenu`) that every navigation affordance below is built from — nothing here introduces a new primitive. `PERMISSION_SYSTEM.md` owns the RBAC model, the roles, and the canonical permission-key grammar (`<area>.<entity>.<action>`); this document owns the *rule* for how a permission a user lacks changes what the nav renders, not the permission keys themselves.

What this document alone is authoritative for: the exact set of items in the primary nav and the route/permission each maps to; the interaction contract for switching company and branch; the ⌘K palette's search-and-action grammar; the breadcrumb-and-page-header pattern every screen inherits; the deep-linking and query-parameter conventions that make a filtered, branch-scoped, or AI-flagged view shareable; the precise hide-vs-disable decision tree for permission-gated nav; how the notification bell and the AI status indicator behave as chrome (as opposed to the full notification system or the full AI Command Center, which are out of scope here); and the mobile, RTL, and keyboard contracts for all of the above.

Two platform-wide rules bind everything below and are restated here because navigation is where a user first encounters both of them. First, **RBAC is a UX courtesy in the client and a hard boundary on the server**: hiding or disabling a nav item spares a user a confusing `403`, but it is never the reason an action is safe — the same permission check that filtered the Sidebar runs again, authoritatively, on every request the API receives. Second, **the AI Rail and the "AI" primary-nav section are ambient and advisory, never a shortcut around approval** — nothing reachable from navigation executes a sensitive action (`bank.transfer`, `payroll.approve`, `tax.submit`, a permission change, a deletion) on a single click regardless of an AI agent's confidence score; every such path terminates at the Approval Center, which is itself a navigable destination, not a bypass of one.

# App Shell (sidebar + topbar layout)

The app shell is the persistent frame `app/(app)/layout.tsx` renders around every authenticated route (`LAYOUT_SYSTEM.md → App Shell Layout`). Of its four regions — Sidebar, Topbar, Content, AI Rail — this document concerns itself with the two that are navigation surfaces (Sidebar, Topbar) and treats Content and the AI Rail only insofar as the Topbar's AI status indicator and the Sidebar's "AI" entry point relate to them. The AI Rail's own content (insight cards, the proposal queue) is `AI_COMMAND_CENTER.md`'s and `LAYOUT_SYSTEM.md`'s territory; this document's stake in it is a single toggle affordance and the fact that opening it never navigates — it is a companion panel, not a route.

## Region contract this document builds on

| Region | Width (expanded / collapsed / mobile) | Owns |
|---|---|---|
| Sidebar | `272px` / `72px` / `0` (Sheet overlay `288px`) | Workspace switcher, primary nav list, bottom utility row — all specified below |
| Topbar | `64px` / — / `56px` | Breadcrumb, Command Palette trigger, AI status pill, notifications bell, theme toggle, user menu |
| Content | `1fr` (remaining space) | The active Page Template — out of scope here |
| AI Rail | `360px` / `0` (Sheet below `2xl`) | Ambient AI companion — out of scope here beyond its toggle |

## Sidebar composition, top to bottom

The Sidebar is not a flat list of ten links. It is three stacked, independently-scrolling-exempt regions inside its own scroll container:

1. **Workspace header (fixed, never scrolls).** The Company & Branch Switcher trigger — see its own section below — occupying the top `56px`, visually consistent with the Topbar's height so the eye reads one continuous header band across the sidebar/topbar seam.
2. **Primary nav list (scrolls if the viewport is short).** The ten module entries from the map below, each optionally expandable into its sub-items when active or pinned open. This is the part of the Sidebar RBAC filters most aggressively — see `# Permission-Aware Nav`.
3. **Bottom utility row (fixed, never scrolls).** Settings, a collapse/expand toggle (`PanelLeftClose`/`PanelLeftOpen`, bound to `Cmd/Ctrl+B` per `LAYOUT_SYSTEM.md`), and — only in the collapsed `72px` state, where the Topbar's user menu becomes harder to reach on a narrow rail — a compact user avatar.

```tsx
// components/layout/sidebar.tsx
'use client';

import { usePathname } from 'next/navigation';
import { useTranslations } from 'next-intl';
import { useShellStore } from '@/stores/use-shell-store';
import { NAV_TREE } from '@/lib/nav/nav-tree';
import { filterNavByPermissions } from '@/lib/nav/filter-nav';
import { WorkspaceSwitcher } from '@/components/layout/workspace-switcher';
import { NavItem } from '@/components/layout/nav-item';
import { Button } from '@/components/ui/button';
import { PanelLeftClose, PanelLeftOpen, Settings } from 'lucide-react';

interface SidebarProps {
  permissions: string[]; // me.active_company.permissions, from GET /api/v1/auth/me
}

export function Sidebar({ permissions }: SidebarProps) {
  const t = useTranslations('nav');
  const pathname = usePathname();
  const collapsed = useShellStore((s) => s.sidebarCollapsed);
  const toggle = useShellStore((s) => s.toggleSidebar);

  const visibleTree = filterNavByPermissions(NAV_TREE, permissions);

  return (
    <nav
      aria-label={t('primaryNavigation')}
      className="flex h-dvh flex-col border-ink-150 [border-inline-end-width:1px] bg-surface"
      data-state={collapsed ? 'collapsed' : 'expanded'}
    >
      <WorkspaceSwitcher collapsed={collapsed} />

      <ul className="flex-1 overflow-y-auto py-2" role="list">
        {visibleTree.map((module) => (
          <NavItem key={module.id} module={module} collapsed={collapsed} activePath={pathname} />
        ))}
      </ul>

      <div className="border-ink-150 flex flex-col gap-1 p-2 [border-block-start-width:1px]">
        <Button variant="ghost" size={collapsed ? 'icon' : 'default'} className="justify-start gap-2" asChild>
          <a href="/settings/company">
            <Settings className="h-4 w-4 shrink-0" />
            {!collapsed && t('settings')}
          </a>
        </Button>
        <Button variant="ghost" size="icon" onClick={toggle} aria-label={t(collapsed ? 'expandSidebar' : 'collapseSidebar')}>
          {collapsed ? <PanelLeftOpen className="h-4 w-4" /> : <PanelLeftClose className="h-4 w-4" />}
        </Button>
      </div>
    </nav>
  );
}
```

The Sidebar receives `permissions` as a prop rather than fetching them itself — `(app)/layout.tsx` already resolved `GET /api/v1/auth/me` server-side (`FRONTEND_ARCHITECTURE.md → Layout nesting`) and passes `active_company.permissions` down through `SessionProvider`. The Sidebar never issues its own permission request; it is a pure function of the permission array and the current path.

## Topbar composition, start to end

Reading order follows the document's logical direction (start → end), which is what actually flips under `dir="rtl"` — see `# RTL Mirroring` for why nothing here uses `left`/`right`.

1. **Mobile menu trigger** (`Menu` icon) — hidden at `lg`+, opens the Sidebar as a Sheet below it.
2. **Breadcrumb** (desktop) or **page title** (mobile) — see `# Breadcrumbs & Page Headers`.
3. **Command Palette trigger** — a pill button, `Search or jump to…` with a right-aligned `⌘K` hint, `me-auto` so everything after it is pushed to the end edge.
4. **AI status indicator** — see `# Notifications & AI Status in nav`.
5. **Notifications bell** — see `# Notifications & AI Status in nav`.
6. **Theme toggle** (`SunMoon`) — light/dark/system, three-state, persisted to `useShellStore` + `PATCH /api/v1/users/me/preferences`.
7. **User menu** — avatar, name, active role, "Switch company," "Profile," "Sign out."

```tsx
// components/layout/topbar.tsx
'use client';

import { Breadcrumbs } from '@/components/layout/breadcrumbs';
import { CommandPaletteTrigger } from '@/components/layout/command-palette-trigger';
import { AiStatusIndicator } from '@/components/layout/ai-status-indicator';
import { NotificationsBell } from '@/components/layout/notifications-bell';
import { ThemeToggle } from '@/components/layout/theme-toggle';
import { UserMenu } from '@/components/layout/user-menu';
import { MobileNavTrigger } from '@/components/layout/mobile-nav-trigger';
import type { BreadcrumbItem } from '@/types/nav';

export function Topbar({ breadcrumb }: { breadcrumb: BreadcrumbItem[] }) {
  return (
    <header className="border-ink-150 flex h-16 items-center gap-3 px-4 [border-block-end-width:1px] backdrop-blur-sm sm:h-14 md:h-16">
      <MobileNavTrigger className="lg:hidden" />
      <Breadcrumbs items={breadcrumb} className="hidden md:flex" />
      <CommandPaletteTrigger className="me-auto" />
      <AiStatusIndicator />
      <NotificationsBell />
      <ThemeToggle />
      <UserMenu />
    </header>
  );
}
```

Both `Sidebar` and `Topbar` are Client Components (they read `usePathname`, Zustand, and — for the bell and AI pill — a realtime subscription), mounted once by the Server Component `(app)/layout.tsx` and never remounted on navigation; only `{children}` swaps. This is why the Sidebar's active-item highlight, the Topbar's breadcrumb, and the AI status pulse never flicker between routes — they are not part of what React tears down and rebuilds on a link click, only the props they read (`pathname`, `breadcrumb`) change.

# Primary Navigation (module map: Dashboard, Accounting, Banking, Sales, Purchasing, Inventory, Payroll, Tax, Reports, AI)

The Sidebar's ten entries are a fixed, platform-defined list — companies do not reorder or rename them (a Purchasing Manager at one tenant and a Purchasing Manager at another see the same word "Purchasing" in the same position), because consistency across QAYD's entire customer base is worth more than per-tenant vanity naming, and it is what lets a support screenshot or an AI-generated walkthrough reference "the Banking section" unambiguously for every company on the platform. What *does* vary per company is which of the ten a given user's role can see at all, governed entirely by `# Permission-Aware Nav` below.

Two of the ten deserve an upfront note on how they differ from the other eight. **Dashboard** carries no single gating permission — every authenticated member of a company can open it — because it is not one report but a grid of independently-permissioned AI Command Center widgets (`AI_COMMAND_CENTER.md`); a Sales Employee and an Owner both land on `/dashboard`, and see structurally different content because each widget's own data endpoint enforces its own permission, not because the nav item itself was conditionally rendered. **AI** is the primary-nav home for the Command Center's *deep, full-page* surfaces (Insights, Recommendations, Risks, Forecast, Ask AI) and is distinct from the **AI Rail** — the `360px` ambient companion panel `LAYOUT_SYSTEM.md` defines as a fourth shell region. The Rail is always one keystroke away regardless of which of the ten sections you are in (it shows the condensed proposal queue for *whatever page you're currently on*); the "AI" nav item is where you go to work through the full backlog of a specific AI surface across the *whole company*, independent of what page you started from. A user can have the AI Rail open while browsing Journal Entries and never touch the "AI" nav item all day, or vice versa — they are complementary, not redundant.

## The module map

| # | Module | Icon (`lucide-react`) | Root route | Visibility permission | Typical roles |
|---|---|---|---|---|---|
| 1 | Dashboard | `LayoutDashboard` | `/dashboard` | *(none — always visible; widgets self-filter)* | All roles |
| 2 | Accounting | `BookOpenCheck` | `/accounting/accounts` | `accounting.read` | Owner, CEO, CFO, Finance Manager, Senior Accountant, Accountant, Auditor, External Auditor |
| 3 | Banking | `Landmark` | `/banking/accounts` | `bank.read` | Owner, CEO, CFO, Finance Manager, Senior Accountant, Accountant, Auditor |
| 4 | Sales | `Receipt` | `/sales/quotations` | `sales.read` | Owner, CEO, CFO, Finance Manager, Sales Manager, Sales Employee, Accountant |
| 5 | Purchasing | `ShoppingCart` | `/purchasing/vendors` | `purchasing.read` | Owner, CEO, CFO, Finance Manager, Purchasing Manager, Purchasing Employee, Accountant |
| 6 | Inventory | `Boxes` | `/inventory/items` | `inventory.read` | Owner, CEO, Finance Manager, Inventory Manager, Warehouse Employee, Purchasing Manager |
| 7 | Payroll | `Users` | `/payroll/employees` | `payroll.read` | Owner, CEO, CFO, HR Manager, Payroll Officer, Finance Manager |
| 8 | Tax | `Percent` | `/tax/returns` | `tax.read` | Owner, CEO, CFO, Finance Manager, Senior Accountant, Auditor, External Auditor |
| 9 | Reports | `BarChart3` | `/reports` | `reports.read` | Owner, CEO, CFO, Finance Manager, Senior Accountant, Auditor, Read Only |
| 10 | AI | `Sparkles` | `/ai/insights` | `reports.read` | Owner, CEO, CFO, Finance Manager, Senior Accountant *(sub-items vary further)* |

Order is fixed and intentional: it follows the money — Accounting (the ledger of record) before Banking (cash) before the two revenue/cost cycles (Sales, Purchasing) before the resources that feed them (Inventory, Payroll) before the compliance layer that sits over all of them (Tax) before the two lenses that look back across everything (Reports, AI). Dashboard leads because it is home; nothing is inserted between it and Accounting for any company, at any plan tier.

## Sub-navigation per module

Every sub-item below cites its App Router path exactly as `FRONTEND_ARCHITECTURE.md`'s route tree defines it and its permission key exactly as the owning module's own spec (`docs/accounting/*.md`, `docs/ai/*.md`) defines it — this document does not invent a route or a permission that another document has not already established. Where a sub-item's most natural nav grouping differs from its URL's own module prefix (Financial Statements lives at the top-level `/financial-statements`, not nested under `/accounting`; Customers and Vendors are owned by the Accounting module's `CUSTOMERS.md`/`VENDORS.md` but are grouped here under Sales/Purchasing because that is where a Sales Employee or a Purchasing Employee expects to find them), the permission key still resolves to the *canonical, owning* module, never a re-derived Sales- or Purchasing-flavored duplicate. This is a deliberate, repeated pattern in this nav system: **grouping is an information-architecture decision; the permission gate always matches the record's true owner.**

**Accounting** (parent gate `accounting.read`):
- Chart of Accounts — `/accounting/accounts` — `accounting.accounts.read`
- Journal Entries — `/accounting/journal-entries` — `accounting.journal.read`
- General Ledger — `/accounting/ledger` — `accounting.read`
- Trial Balance — `/accounting/trial-balance` — `accounting.trial_balance.read`
- Financial Statements — `/financial-statements` — `accounting.report.read`
- Fiscal Periods — `/accounting/fiscal-periods` — `accounting.read` to view, `accounting.period.lock` to close
- Cost Centers & Projects — `/accounting/cost-centers`, `/accounting/projects` — `accounting.read`

**Banking** (parent gate `bank.read`):
- Bank Accounts — `/banking/accounts` — `bank.read`
- Transactions — `/banking/transactions` — `bank.read`
- Reconciliation — `/banking/reconciliations/[id]` (opened from an account, never a bare index) — `bank.reconcile`
- Transfers — `/banking/transfers` — `bank.transfer`, always a full page per `FRONTEND_ARCHITECTURE.md`'s rule that sensitive mutations never render as a quick-create modal

**Sales** (parent gate `sales.read`):
- Customers — `/sales/customers` — `accounting.customer.read` (owning module: Accounting/CRM)
- Quotations — `/sales/quotations` — `sales.read`
- Orders — `/sales/orders` — `sales.read`
- Invoices — `/sales/invoices` — `sales.read`, Credit Notes tab additionally needs `sales.credit_note.read`
- Receipts — `/sales/receipts` — `sales.read`

**Purchasing** (parent gate `purchasing.read`):
- Vendors — `/purchasing/vendors` — `accounting.vendor.read` (owning module: Accounting/AP)
- Purchase Orders — `/purchasing/purchase-orders` — `purchasing.read`, approval action `purchasing.bill.approve`-family
- Bills — `/purchasing/bills` — `purchasing.bill.read`
- Vendor Payments — `/purchasing/vendor-payments` — `purchasing.bill.read` to view, `bank.transfer` to disburse

**Inventory** (parent gate `inventory.read`):
- Products — `/inventory/products` — `products.read` (owning module: Products, a sibling of Inventory)
- Items (stock on hand) — `/inventory/items` — `inventory.read`
- Movements — `/inventory/movements` — `inventory.read`
- Transfers — `/inventory/transfers` — `inventory.adjust`
- Stock Counts — `/inventory/counts/[countId]` — `inventory.read` to view, `inventory.count.approve` to close
- Warehouses — `/inventory/warehouses` — `inventory.read`

**Payroll** (parent gate `payroll.read`):
- Employees — `/payroll/employees` — `payroll.employee.read`
- Payroll Runs — `/payroll/runs/[runId]` (single page, tab per stage: Calculate → Submit → Approve → Post → Disburse) — `payroll.read` to view, `payroll.calculate` / `payroll.approve` per stage
- Payslips — `/payroll/payslips` — `payroll.employee.read`
- Attendance — `/payroll/attendance` — `payroll.read`
- Loans — `/payroll/loans` — `payroll.loan.create` / `payroll.loan.approve`

**Tax** (parent gate `tax.read`):
- Tax Returns — `/tax/returns/[returnId]` — `tax.filing.read` to view, `tax.filing.prepare` to draft, `tax.file_return` to submit
- Tax Codes — `/tax/codes` — `tax.read` to view, `tax.config.manage` to edit

**Reports** (parent gate `reports.read`):
- Report Library — `/reports` — `reports.read`
- Report Detail — `/reports/[definitionId]` — `reports.read`, `reports.export` to export
- Scheduled Runs — `/reports/runs/[runId]` — `reports.read` (streams status live over `private-company.{id}.report-runs.{id}`)

**AI** (parent gate `reports.read` — matching the permission every AI Command Center panel endpoint itself declares, per `AI_COMMAND_CENTER.md`):
- Insights — `/ai/insights` — `reports.read`
- Recommendations — `/ai/recommendations` — `reports.read` to view, the recommended action's own permission key to execute
- Risks — `/ai/risks` — `reports.read` to view, `ai.approve` to dismiss a critical-severity flag
- Forecast — `/ai/forecast` — `reports.read`
- Ask AI — `/ai/chat` — `ai.chat`
- Approval Center — `/approvals` (not nested under `/ai`; a cross-module queue every sensitive action from every module routes through) — visible to any approver on a pending item, or to `ai.approve` broadly

```ts
// lib/nav/nav-tree.ts
export interface NavLeaf {
  id: string;
  labelKey: string;          // next-intl key, e.g. 'nav.accounting.journalEntries'
  href: string;
  permission: string | null; // null = always visible once the parent module is visible
}

export interface NavModule {
  id: string;
  labelKey: string;
  icon: keyof typeof import('lucide-react');
  href: string;
  permission: string | null;
  children?: NavLeaf[];
}

export const NAV_TREE: NavModule[] = [
  { id: 'dashboard', labelKey: 'nav.dashboard', icon: 'LayoutDashboard', href: '/dashboard', permission: null },
  {
    id: 'accounting', labelKey: 'nav.accounting.root', icon: 'BookOpenCheck',
    href: '/accounting/accounts', permission: 'accounting.read',
    children: [
      { id: 'accounting.accounts', labelKey: 'nav.accounting.chartOfAccounts', href: '/accounting/accounts', permission: 'accounting.accounts.read' },
      { id: 'accounting.journal', labelKey: 'nav.accounting.journalEntries', href: '/accounting/journal-entries', permission: 'accounting.journal.read' },
      { id: 'accounting.ledger', labelKey: 'nav.accounting.generalLedger', href: '/accounting/ledger', permission: 'accounting.read' },
      { id: 'accounting.trialBalance', labelKey: 'nav.accounting.trialBalance', href: '/accounting/trial-balance', permission: 'accounting.trial_balance.read' },
      { id: 'accounting.statements', labelKey: 'nav.accounting.financialStatements', href: '/financial-statements', permission: 'accounting.report.read' },
      { id: 'accounting.periods', labelKey: 'nav.accounting.fiscalPeriods', href: '/accounting/fiscal-periods', permission: 'accounting.read' },
    ],
  },
  // banking, sales, purchasing, inventory, payroll, tax, reports, ai — same shape, omitted for brevity
];
```

`filterNavByPermissions` (used by `Sidebar` above and by the Command Palette's nav-search source) walks this tree once per permission-set change, not per render, and is the single implementation both surfaces share — see `# Permission-Aware Nav` for its body.

# Company & Branch Switcher

`COMPANY_STRUCTURE.md` establishes that one user account may belong to many companies, and that switching companies "immediately changes Permissions, Dashboard, Reports, AI Memory, Financial Data" — every one of those is a different server answer, not a client-side relabeling. That is why QAYD treats **switching company** and **switching branch** as two different weights of operation, and this section specifies both precisely, because conflating them is the single easiest way to build a switcher that silently shows stale data.

## Company switch — heavy, session-level, server-confirmed

Company is not a filter; it is the tenancy boundary the httpOnly session cookie encodes and the API's RBAC engine resolves from server-side, one company at a time, per `PERMISSION_SYSTEM.md`'s Permission Flow (`User → Company → Branch → Department → Role → Custom Permissions`). Changing it therefore cannot be a client-only state update — it must round-trip through the session:

1. The trigger lives at the top of the Sidebar (`WorkspaceSwitcher`) and, redundantly, in the Topbar's user menu — the same action, two entry points, because "switch company" is frequent enough for power users (an accountant serving five client companies) to deserve a shortcut from wherever the eye lands.
2. Selecting a different company calls the Next.js Route Handler `POST /api/auth/switch-company` (`FRONTEND_ARCHITECTURE.md`'s route tree, `app/api/auth/switch-company/route.ts`) with `{ company_id }`. The handler proxies to Laravel, receives a new session scoped to that company, and re-issues the httpOnly session cookie — the browser's own JavaScript never holds or reads the bearer token at any point in this flow.
3. On a `2xx`, the client performs three things in order, not interchangeably: `queryClient.clear()` (every cached query — accounts, invoices, permissions, the lot — belonged to the *previous* company and none of it is safe to keep, unlike a branch switch's targeted invalidation below), `router.refresh()` (re-runs the Server Component `(app)/layout.tsx`, which re-fetches `GET /api/v1/auth/me` and re-resolves `active_company`), and a `router.push('/dashboard')` — never a `router.back()` or "stay on this page," because the journal entry or bank reconciliation the user was looking at belongs to a `company_id` that may not even exist, let alone be accessible, in the newly active company.
4. If the outgoing session's current route encodes a resource id (`/accounting/journal-entries/482`), that id is simply abandoned, not carried over and re-resolved — see `# Edge Cases`.

```tsx
// components/layout/workspace-switcher.tsx
'use client';

import { useState } from 'react';
import { useRouter } from 'next/navigation';
import { useQueryClient } from '@tanstack/react-query';
import { useSession } from '@/components/providers/session-provider';
import { Popover, PopoverTrigger, PopoverContent } from '@/components/ui/popover';
import { Command, CommandInput, CommandList, CommandGroup, CommandItem, CommandSeparator } from '@/components/ui/command';
import { Building2, MapPin, Check, ChevronsUpDown, Plus } from 'lucide-react';
import { cn } from '@/lib/utils';

export function WorkspaceSwitcher({ collapsed }: { collapsed: boolean }) {
  const { user, activeCompany, companies, activeBranch } = useSession();
  const [open, setOpen] = useState(false);
  const router = useRouter();
  const queryClient = useQueryClient();

  async function switchCompany(companyId: number) {
    setOpen(false);
    await fetch('/api/auth/switch-company', {
      method: 'POST',
      body: JSON.stringify({ company_id: companyId }),
      headers: { 'Content-Type': 'application/json' },
    });
    queryClient.clear();
    router.refresh();
    router.push('/dashboard');
  }

  return (
    <Popover open={open} onOpenChange={setOpen}>
      <PopoverTrigger asChild>
        <button
          className="flex h-14 w-full items-center gap-2 border-ink-150 px-3 text-start [border-block-end-width:1px] hover:bg-ink-100"
          aria-label="Switch company or branch"
        >
          <Building2 className="h-4 w-4 shrink-0 text-ink-500" />
          {!collapsed && (
            <span className="flex-1 truncate text-sm font-medium text-ink-950">{activeCompany.name_en}</span>
          )}
          {!collapsed && <ChevronsUpDown className="h-3.5 w-3.5 shrink-0 text-ink-500" />}
        </button>
      </PopoverTrigger>
      <PopoverContent className="w-72 p-0" align="start">
        <Command>
          <CommandInput placeholder="Switch company…" />
          <CommandList>
            <CommandGroup heading="Companies">
              {companies.map((c) => (
                <CommandItem key={c.id} onSelect={() => switchCompany(c.id)}>
                  <Check className={cn('me-2 h-4 w-4', c.id === activeCompany.id ? 'opacity-100' : 'opacity-0')} />
                  <span className="truncate">{c.name_en}</span>
                  <span className="ms-auto text-xs text-ink-500">{c.role_name}</span>
                </CommandItem>
              ))}
            </CommandGroup>
            <CommandSeparator />
            <CommandGroup heading={`Branches — ${activeCompany.name_en}`}>
              <CommandItem onSelect={() => router.push(setBranchParam(null))}>
                <Check className={cn('me-2 h-4 w-4', !activeBranch ? 'opacity-100' : 'opacity-0')} />
                All branches
              </CommandItem>
              {activeCompany.branches.map((b) => (
                <CommandItem key={b.id} onSelect={() => router.push(setBranchParam(b.id))}>
                  <MapPin className="me-2 h-4 w-4 text-ink-500" />
                  <span className="truncate">{b.name_en}</span>
                  {b.id === activeBranch?.id && <Check className="ms-auto h-4 w-4" />}
                </CommandItem>
              ))}
            </CommandGroup>
            <CommandSeparator />
            <CommandItem onSelect={() => router.push('/select-company?intent=create')}>
              <Plus className="me-2 h-4 w-4" /> Add or create a company
            </CommandItem>
          </CommandList>
        </Command>
      </PopoverContent>
    </Popover>
  );
}
```

## Branch switch — light, client-side filter, shareable

A branch is a scope *inside* an already-selected company (`COMPANY_STRUCTURE.md`: "Every company may have unlimited branches… Kuwait City, Hawally, Dubai, Riyadh…"), not a re-authentication boundary — the `X-Company-Id` header never changes, and no new session is minted. Selecting Al-Noor Trading's Riyadh office (`branch_id: 3`) from the switcher does three lighter-weight things instead:

1. Writes `activeBranch` into `useShellStore` (extended — see below) so every screen mounted after the switch defaults its own dimensional filter bar (`REPORTS.md`'s "company … branch … date range" filter row) to that branch instead of "All branches."
2. Mirrors the choice into the URL as a `?branch=<id>` search param on navigation, via `router.push`, rather than a silent client state change — this is what makes "Cash Flow Status, Riyadh branch" a link a Finance Manager can paste into a WhatsApp message to a colleague and have it open pre-scoped, exactly as scoped, on the other end.
3. Invalidates only the TanStack Query keys tagged `branchScoped: true` (accounts balances, cash positions, inventory levels) rather than `queryClient.clear()` — company-wide reference data (the Chart of Accounts structure, the user's own permission set, the nav tree itself) does not depend on branch and is not thrown away.

```ts
// stores/use-shell-store.ts (extends the store LAYOUT_SYSTEM.md already defines)
import { create } from 'zustand';
import { persist } from 'zustand/middleware';

interface ShellState {
  sidebarCollapsed: boolean;
  aiRailOpen: boolean;
  activeBranchId: number | null;   // new: light, client-scoped, URL-mirrored
  commandPaletteOpen: boolean;     // new: cross-component trigger for the ⌘K dialog
  toggleSidebar: () => void;
  setAiRailOpen: (v: boolean) => void;
  setActiveBranch: (id: number | null) => void;
  setCommandPaletteOpen: (v: boolean) => void;
}

export const useShellStore = create<ShellState>()(
  persist(
    (set) => ({
      sidebarCollapsed: false,
      aiRailOpen: false,
      activeBranchId: null,
      commandPaletteOpen: false,
      toggleSidebar: () => set((s) => ({ sidebarCollapsed: !s.sidebarCollapsed })),
      setAiRailOpen: (v) => set({ aiRailOpen: v }),
      setActiveBranch: (id) => set({ activeBranchId: id }),
      setCommandPaletteOpen: (v) => set({ commandPaletteOpen: v }),
    }),
    { name: 'qayd.shell', partialize: (s) => ({ sidebarCollapsed: s.sidebarCollapsed, aiRailOpen: s.aiRailOpen }) },
  ),
);
```

Note `partialize` deliberately excludes `activeBranchId` and `commandPaletteOpen` from `localStorage` — branch scope is meant to live in the URL (shareable, bookmarkable) and reset to "All branches" on a fresh visit with no `?branch=`, and the palette's open state is transient UI, not a preference. Only chrome preferences (`sidebarCollapsed`, `aiRailOpen`) persist across sessions, and — per `LAYOUT_SYSTEM.md` — are additionally reconciled to `PATCH /api/v1/users/me/preferences` so they survive a device change too.

Every list and dashboard screen that reads `activeBranchId` also reads `useSearchParams().get('branch')` first and treats the URL as the source of truth on initial mount, falling back to the store only when no `branch` param is present — this ordering (URL wins over store on load; store updates the URL on interaction) is what keeps a shared deep link from being silently overridden by whatever branch a recipient happened to have selected the last time they used QAYD.

# Command Palette (⌘K quick nav + AI actions)

`LAYOUT_SYSTEM.md` introduces the Command Palette only as far as its shell mechanics — a `Dialog` portalled to the document root at `z-(--z-command-palette)`, "how QAYD keeps the Sidebar's information density low." This section is that palette's full behavioral specification: what it searches, how results are grouped and ranked, and how it exposes AI actions without ever letting a keystroke commit something a human should approve first.

## What lives inside ⌘K

The palette has three result groups, always rendered in this order, each capped and individually labeled so a long list never reads as an undifferentiated wall:

| Group | Source | Cap | Example |
|---|---|---|---|
| **Navigate** | The same permission-filtered `NAV_TREE` the Sidebar renders, both parent modules and every sub-item, fuzzy-matched on the localized label | 6 | Typing "trial" surfaces *Accounting → Trial Balance* |
| **Records** | A fan-out to the handful of existing per-module `/search` endpoints (`accounting/journal-entries/search`, `accounting/customers/search`, `products/search`, `inventory/search`) — see below | 5 per source, 15 total | Typing "diyar" surfaces the customer *Diyar Real Estate* and any open invoices referencing it |
| **AI & Actions** | Static action list (below) plus live counts pulled from `GET /api/v1/ai/urgent-actions` and the Approval Center queue | 4 | "3 approvals waiting" jumps straight to `/approvals` |

Records search is a **fan-out, not a unified index** — `TECH_STACK.md` is explicit that a consolidated search layer (Meilisearch) is a Phase 2 item; today's palette calls the same per-resource `/search` endpoints a screen's own search box would call, in parallel, permission-filtered before firing (a Warehouse Employee's palette never issues a request to `accounting/customers/search` at all, because `accounting.customer.read` is absent from their permission set — the request is skipped client-side as a courtesy, and would 403 regardless if it were not).

```tsx
// components/layout/command-palette.tsx
'use client';

import { useEffect, useMemo, useState } from 'react';
import { useRouter } from 'next/navigation';
import { useQueries } from '@tanstack/react-query';
import { useTranslations } from 'next-intl';
import { CommandDialog, CommandInput, CommandList, CommandEmpty, CommandGroup, CommandItem } from '@/components/ui/command';
import { useShellStore } from '@/stores/use-shell-store';
import { usePermissions } from '@/hooks/use-permissions';
import { useDebouncedValue } from '@/hooks/use-debounced-value';
import { NAV_TREE } from '@/lib/nav/nav-tree';
import { filterNavByPermissions, flattenNav, fuzzyMatchNav } from '@/lib/nav/filter-nav';
import { RECORD_SEARCH_SOURCES } from '@/lib/nav/record-search-sources';
import { apiClient } from '@/lib/api-client';
import { Sparkles, ArrowUpRight } from 'lucide-react';

export function CommandPalette() {
  const t = useTranslations('nav.palette');
  const router = useRouter();
  const open = useShellStore((s) => s.commandPaletteOpen);
  const setOpen = useShellStore((s) => s.setCommandPaletteOpen);
  const permissions = usePermissions();
  const [query, setQuery] = useState('');
  const debounced = useDebouncedValue(query, 200);

  useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k') {
        e.preventDefault();
        setOpen(!open);
      }
    };
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, [open, setOpen]);

  const navResults = useMemo(
    () => fuzzyMatchNav(flattenNav(filterNavByPermissions(NAV_TREE, permissions)), debounced).slice(0, 6),
    [permissions, debounced],
  );

  const sources = RECORD_SEARCH_SOURCES.filter((s) => permissions.includes(s.permission));
  const recordQueries = useQueries({
    queries: sources.map((s) => ({
      queryKey: ['palette-search', s.id, debounced],
      queryFn: () => apiClient.get(s.endpoint, { params: { q: debounced, per_page: 5 } }),
      enabled: debounced.length >= 2,
      staleTime: 15_000,
    })),
  });

  function select(href: string) {
    setOpen(false);
    setQuery('');
    router.push(href);
  }

  return (
    <CommandDialog open={open} onOpenChange={setOpen} shouldFilter={false}>
      <CommandInput value={query} onValueChange={setQuery} placeholder={t('placeholder')} />
      <CommandList>
        <CommandEmpty>{t('empty')}</CommandEmpty>

        {navResults.length > 0 && (
          <CommandGroup heading={t('groupNavigate')}>
            {navResults.map((item) => (
              <CommandItem key={item.id} onSelect={() => select(item.href)}>
                <ArrowUpRight className="me-2 h-4 w-4 text-ink-500" />
                {item.breadcrumbLabel}
              </CommandItem>
            ))}
          </CommandGroup>
        )}

        {sources.map((source, i) => {
          const results = recordQueries[i]?.data?.data ?? [];
          if (!results.length) return null;
          return (
            <CommandGroup key={source.id} heading={source.groupLabel}>
              {results.map((r: any) => (
                <CommandItem key={r.id} onSelect={() => select(source.hrefFor(r))}>
                  {source.renderResult(r)}
                </CommandItem>
              ))}
            </CommandGroup>
          );
        })}

        <CommandGroup heading={t('groupAiActions')}>
          <CommandItem onSelect={() => select(`/ai/chat?q=${encodeURIComponent(query)}`)}>
            <Sparkles className="me-2 h-4 w-4 text-accent-600" />
            {t('askAi', { query })}
          </CommandItem>
          <CommandItem onSelect={() => select('/approvals')}>
            {t('viewApprovals')}
          </CommandItem>
          <CommandItem onSelect={() => select('/ai/risks')}>
            {t('viewRisks')}
          </CommandItem>
        </CommandGroup>
      </CommandList>
    </CommandDialog>
  );
}
```

## AI actions are handoffs, never executions

The "AI & Actions" group's every item does one of two things and nothing else: it **navigates** (to `/approvals`, to `/ai/risks`, to a specific flagged record) or it **hands off a free-text query to Ask AI** (`/ai/chat?q=…`, which opens the conversational surface with the palette's typed text pre-filled as the first turn — the palette itself never streams a model response inline). This mirrors `AI_COMMAND_CENTER.md`'s Ask AI panel design (contextual, docked, aware of what you were looking at) rather than duplicating it; the palette is a *fast way into* Ask AI and the Approval Center, not a second, competing chat surface or a way to approve something without opening the record it belongs to. Consistent with `PERMISSION_SYSTEM.md`'s "AI Agents NEVER bypass permissions," no palette action can auto-execute an item from `GET /api/v1/ai/recommendations` — the palette can jump you *to* a recommendation card's page, where that card's own "Do it" / "Send for approval" buttons apply the platform's normal autonomy and confidence rules; it never renders a shortcut version of those buttons itself.

## Interaction and ranking

Typing filters all three groups simultaneously (no per-group tab to switch, consistent with `cmdk`'s single-input model shadcn's `Command` wraps). Within **Navigate**, ranking is: exact label match, then prefix match, then fuzzy subsequence match, then — as a tiebreak among equal-quality matches — the platform's fixed module order from the table above, so "in" reliably surfaces *Inventory* before an unrelated fuzzy hit buried three modules later. Within **Records**, each source's own backend ranking (trigram similarity for `customers`/`journal-entries`, full-text for `products`) is trusted as-is; the palette does not re-rank across sources, it only orders the *groups* (Navigate above Records above AI & Actions) so the cheapest, most-certain answer — "you typed the name of a page" — always sits above a network-dependent record match. With an empty query, the palette shows a fourth, unlabeled top slot: the five most recently visited routes (tracked client-side, per-user, capped at five, cleared on sign-out), because the single most common ⌘K use is "take me back to where I just was," not search at all.

# Breadcrumbs & Page Headers

Every route below the shell renders a `PageHeader` — breadcrumb trail, title, and an optional action slot — as the first element inside `Content`, immediately under the sticky Topbar. The breadcrumb here and the one in the Topbar (`# App Shell`) are the same component in two densities: full trail with a page title beneath it inside the content area (always present, all breakpoints) and a compact, Topbar-hosted version that shows only the trail (desktop, `md`+, hidden on mobile in favor of the page title alone, per `# Mobile Navigation`).

Breadcrumbs are **derived from route segment configuration, never hand-written per page.** Each `page.tsx` exports a small `breadcrumb` config object (or, for dynamic segments, a `generateBreadcrumb(params, data)` function so a journal entry's crumb reads "JE-2026-07-0182," not the literal string `[entryId]`), and a single `Breadcrumbs` component walks the current segment's ancestry to assemble the trail:

```tsx
// app/(app)/accounting/journal-entries/[entryId]/page.tsx
export async function generateBreadcrumb({ params }: { params: { entryId: string } }) {
  const entry = await getJournalEntry(params.entryId); // same fetch the page itself uses, cached by TanStack Query's request de-dupe
  return [
    { labelKey: 'nav.accounting.root', href: '/accounting/accounts' },
    { labelKey: 'nav.accounting.journalEntries', href: '/accounting/journal-entries' },
    { label: entry.journal_number, href: `/accounting/journal-entries/${entry.id}` }, // literal, not a translation key
  ];
}
```

```tsx
// components/layout/breadcrumbs.tsx
import Link from 'next/link';
import { ChevronRight } from 'lucide-react';
import { useTranslations } from 'next-intl';
import type { BreadcrumbItem } from '@/types/nav';
import { cn } from '@/lib/utils';

export function Breadcrumbs({ items, className }: { items: BreadcrumbItem[]; className?: string }) {
  const t = useTranslations();
  return (
    <nav aria-label="Breadcrumb" className={cn('flex items-center gap-1.5 text-sm text-ink-500', className)}>
      <ol className="flex items-center gap-1.5">
        {items.map((item, i) => {
          const isLast = i === items.length - 1;
          const label = item.labelKey ? t(item.labelKey) : item.label;
          return (
            <li key={item.href} className="flex items-center gap-1.5">
              {i > 0 && <ChevronRight className="h-3.5 w-3.5 shrink-0 rtl:rotate-180" aria-hidden />}
              {isLast ? (
                <span className="font-medium text-ink-950" aria-current="page">{label}</span>
              ) : (
                <Link href={item.href} className="hover:text-ink-950 hover:underline">{label}</Link>
              )}
            </li>
          );
        })}
      </ol>
    </nav>
  );
}
```

The trail is always module-rooted — it never starts at `/dashboard` (a journal entry did not conceptually descend from the home screen; it descended from Accounting) — and its last segment is never a link, matching `aria-current="page"` semantics for assistive tech. A `PageHeader`'s title always textually matches the breadcrumb's last segment (no page ever titles itself something the trail didn't already say), and its optional right-aligned action slot (a primary `Button`, e.g. "New Journal Entry") is itself permission-gated: it is omitted, not disabled, when the viewer lacks the create permission, consistent with `COMPONENT_LIBRARY.md`'s `DropdownMenu` row-action precedent — a button whose only function is an action you cannot take is not a helpful disabled affordance, it is noise.

```tsx
// components/layout/page-header.tsx
import { Breadcrumbs } from './breadcrumbs';
import type { BreadcrumbItem } from '@/types/nav';

export function PageHeader({
  breadcrumb, title, description, actions,
}: { breadcrumb: BreadcrumbItem[]; title: string; description?: string; actions?: React.ReactNode }) {
  return (
    <div className="border-ink-150 flex flex-col gap-3 px-6 py-5 [border-block-end-width:1px] md:flex-row md:items-center md:justify-between">
      <div className="space-y-1">
        <Breadcrumbs items={breadcrumb} className="md:hidden" />
        <h1 className="text-heading font-display font-semibold text-ink-950">{title}</h1>
        {description && <p className="text-sm text-ink-500">{description}</p>}
      </div>
      {actions && <div className="flex items-center gap-2">{actions}</div>}
    </div>
  );
}
```

# Routing & Deep Links (App Router)

Navigation only fulfils its job if every state it can put a screen into is representable as a URL another person (or another AI agent, or a `sources[].target` field inside an `ai_decisions` row) can open directly and land in the identical state — no nav interaction is allowed to produce a screen configuration a link cannot reproduce. This section is the deep-linking contract layered on top of `FRONTEND_ARCHITECTURE.md`'s route tree.

## Locale is not in the path

QAYD has no `[locale]` segment anywhere in the route tree. `/accounting/journal-entries/482` is the one and only URL for that entry regardless of whether the viewer reads it in English or Arabic — locale is resolved server-side per request from the user's own `locale` field (`GET /api/v1/auth/me → user.locale`, per `AUTHENTICATION_API.md`'s response shape) via `next-intl`, with an httpOnly-adjacent `NEXT_LOCALE` cookie as the pre-auth fallback on `(auth)` screens. This is a deliberate simplification with one consequence worth stating plainly: a link is portable across users' languages but not self-describing about which language it will render in — pasting a Journal Entries link to a colleague shows *them* Arabic if their account is set to Arabic, even if the sender was looking at it in English. `dir` is likewise resolved server-side from the same locale, not guessed from the URL.

## Query parameters are state, not decoration

Three families of search params are load-bearing across the whole app, validated with `zod` rather than read as untyped strings, because a malformed or attacker-supplied `?branch=drop-table` must fail closed, not silently become `NaN` three components deep:

```ts
// lib/nav/search-params.ts
import { z } from 'zod';

export const branchParamSchema = z.coerce.number().int().positive().nullable();

export const dateRangeParamSchema = z.object({
  from: z.string().date().optional(),
  to: z.string().date().optional(),
  preset: z.enum(['today', 'this_week', 'this_month', 'this_quarter', 'this_year', 'last_12_months', 'custom']).optional(),
});

export const paletteHandoffParamSchema = z.object({
  q: z.string().max(500).optional(),        // pre-fill for /ai/chat
  scenario: z.string().max(100).optional(), // pre-load for /ai/forecast simulation mode
});

export function parseBranchParam(raw: string | null): number | null {
  const result = branchParamSchema.safeParse(raw);
  return result.success ? result.data : null; // fails closed to "All branches," never throws
}
```

- **`?branch=<id>`** — the Branch Switcher's own mirror, read by every branch-aware list/dashboard route on mount (`# Company & Branch Switcher`).
- **`?q=`, `?filter[...]=`, `?sort=`, `?page=`/`?cursor=`** — `DataTable`'s own query-grammar params (`COMPONENT_LIBRARY.md → DataTable`), which this document does not redefine, only confirms are the same params a nav-originated link (a Command Palette record result, a notification's `target`) is expected to populate rather than bypass. A palette result for customer "Diyar Real Estate" does not link to a bespoke customer-detail view instead of the list; it links to `/sales/invoices?filter[customer_id]=4821-cus-1187`, the exact URL the Invoices screen's own filter UI would have produced by hand.
- **`?q=`, `?scenario=`** on `/ai/chat` and `/ai/forecast` — the palette's and a notification's AI-handoff params, validated by `paletteHandoffParamSchema` above. `AI_COMMAND_CENTER.md`'s own worked examples reference a target of `/ai/simulate?scenario=cash_trough_0729`; this application's shipped route tree implements that destination as `/ai/forecast?mode=simulate&scenario=cash_trough_0729` — `/ai/simulate` is kept as a permanent redirect (`next.config.ts` → `redirects()`) to the canonical path rather than a second real route, so neither an already-generated AI narrative nor a bookmarked link ever 404s over a naming difference between the product spec and the implemented tree.

## Every AI-surfaced target is a real, resolvable route

`AI_COMMAND_CENTER.md`'s `actions[].target` and `sources[].label` fields (e.g. `"target": "/ai/forecast"`, a fraud flag's card linking to `/ai/risks/55214`) are not free-text the AI invents per response — they are drawn from the same finite route table this document defines, checked in CI: an integration test resolves every `target` value the AI layer is capable of emitting against the actual Next.js route manifest at build time, so a renamed route is a build failure for the AI layer's fixtures, not a live 404 a user discovers by clicking a stale insight three months later.

## Parallel and intercepted routes stay bookmarkable

Per `FRONTEND_ARCHITECTURE.md`, a quick-create flow like `journal-entries/new` has a modal presentation *and* a full-page one at the identical URL — navigating there directly (a fresh tab, a bookmark, a shared link) always renders the full page; only an in-app link click from the list, carrying Next.js's internal soft-navigation state, renders the intercepted modal. Deep links therefore never need special-casing for "was this meant to be a modal" — there is exactly one canonical resource per URL, and the modal is a presentation nicety on top of it, never a second data path.

# Permission-Aware Nav (hide/disable by RBAC)

`FRONTEND_ARCHITECTURE.md`'s fourth principle states the client-side rule in one sentence: "a permission the current user lacks means the corresponding nav item, button, or field is hidden or disabled in the UI." Navigation is where that single sentence has to become a deterministic algorithm, because "hidden or disabled" is not one behavior — this section is the decision tree that resolves which of the two applies, and it is the same tree `filterNavByPermissions` (referenced in `# Primary Navigation`) implements.

## The decision tree

```ts
// lib/nav/filter-nav.ts
import type { NavModule, NavLeaf } from './nav-tree';

type FilteredModule = NavModule & { children?: NavLeaf[] };

export function filterNavByPermissions(tree: NavModule[], permissions: string[]): FilteredModule[] {
  const has = (perm: string | null) => perm === null || permissions.includes(perm);

  return tree
    .map((module) => {
      const visibleChildren = module.children?.filter((c) => has(c.permission)) ?? [];
      // Rule 1: a module with declared children is shown only if the parent gate passes
      // AND at least one child survives — an empty accordion helps no one.
      if (module.children && module.children.length > 0) {
        if (!has(module.permission) || visibleChildren.length === 0) return null;
        return { ...module, children: visibleChildren };
      }
      // Rule 2: a leaf module (Dashboard) is shown purely on its own gate.
      return has(module.permission) ? module : null;
    })
    .filter((m): m is FilteredModule => m !== null);
}
```

1. **Hide, always, when the answer is "this role never touches this."** A Warehouse Employee's Sidebar has no Payroll entry at all — not a greyed-out one. Rule: any module or leaf whose permission the user lacks, and which the product has no reason to advertise as an upsell or an admin-mediated unlock, is omitted from the DOM entirely, not rendered-then-disabled. This is the default and covers the overwhelming majority of cases in the table under `# Primary Navigation`.
2. **Hide a parent whose every child is hidden.** A Purchasing Employee who can read Bills and Vendors but has no Vendor Payments access still sees "Purchasing" with two sub-items, not three — but a hypothetical role with `purchasing.read` and zero visible children (a misconfigured custom role, see `# Edge Cases`) sees no "Purchasing" entry at all, because a section that opens to reveal nothing is worse than no section.
3. **Disable, with a visible reason, only for the small set of items the product wants to advertise exist.** Two, and only two, categories qualify: a feature gated by **plan tier** rather than role (a "Multi-Branch Consolidation" report on a single-branch plan renders in Reports, disabled, with a `Lock` icon and a tooltip reading "Available on the Growth plan"), and an **integration not yet configured** (Bank Feeds shows in Banking even before a bank connection exists, disabled with "Connect a bank account first" rather than absent, because the user needs to discover the capability to go configure it). Nothing in the RBAC-by-role sense — a Sales Employee lacking `bank.transfer` — ever uses this disabled-with-a-reason treatment; that would advertise a capability to a role that structurally will never receive it, which is not discovery, it is noise plus a support ticket.
4. **Dashboard content-filters instead of nav-gating.** Repeating the point from `# Primary Navigation` because it is the one deliberate exception to "one permission, one nav decision": the Dashboard nav item itself is never hidden, but the widgets rendered inside it are individually gated exactly like any other AI Command Center endpoint, which is why two roles land on the identical `/dashboard` URL and see different content without the nav system doing anything module-level about it.
5. **Row- and record-level actions inside a screen follow `DropdownMenu`'s existing precedent** (`COMPONENT_LIBRARY.md`): omit the menu item, don't disable it. This document's contribution is only extending that same precedent consistently to the nav chrome's `PageHeader` action slot and to Command Palette results — both omit rather than disable, for the same reason.

## Server truth, client courtesy

None of the above is a security control. A Sales Employee who crafts a direct request to `GET /api/v1/payroll/employees` receives a `403` from Laravel regardless of what the Sidebar rendered — the nav filter's entire job is to make that `403` rare in ordinary use, never to be the reason it doesn't happen. `error.tsx` boundaries at the module-layout level (`FRONTEND_ARCHITECTURE.md`'s per-route convention) catch a `403` a stale permission cache or a manually-typed URL produces and render `ErrorState` with a "You don't have access to this" message and a link back to Dashboard — never the Next.js default error screen, and never a silent redirect that could be mistaken for the page simply not existing (see `# Edge Cases` for why that distinction matters).

# Notifications & AI Status in nav

Two live indicators sit in the Topbar, both realtime-driven over Laravel Reverb rather than polled, and both scoped narrowly to "chrome affordance" — the full notification center and the full AI Command Center are each their own product surface; what belongs here is only how a user becomes aware, from anywhere in the app, that either has something waiting.

## Notifications bell

Subscribes to `private-company.{id}.notifications.{user_id}` (`FRONTEND_ARCHITECTURE.md`'s own Topbar signature comment names this exact channel) via a shared `RealtimeProvider` — one Echo connection per session, not one per component — and renders an unread badge whose count is backed by the platform's own `idx_notifications_unread` partial index (`DATABASE_INDEXING.md`), which is precisely why the badge can afford to be sub-millisecond even for a company with years of notification history: the index only ever contains unread rows.

```tsx
// components/layout/notifications-bell.tsx
'use client';

import { useState } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { useRealtimeChannel } from '@/hooks/use-realtime-channel';
import { useSession } from '@/components/providers/session-provider';
import { Popover, PopoverTrigger, PopoverContent } from '@/components/ui/popover';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Bell } from 'lucide-react';
import { apiClient } from '@/lib/api-client';

export function NotificationsBell() {
  const { user, activeCompany } = useSession();
  const [open, setOpen] = useState(false);
  const queryClient = useQueryClient();

  const { data } = useQuery({
    queryKey: ['notifications', 'unread'],
    queryFn: () => apiClient.get('/api/v1/notifications', { params: { 'filter[read_at]': 'null', per_page: 20 } }),
  });

  useRealtimeChannel(`private-company.${activeCompany.id}.notifications.${user.id}`, {
    onEvent: () => queryClient.invalidateQueries({ queryKey: ['notifications', 'unread'] }),
  });

  const unreadCount = data?.meta?.pagination?.total ?? 0;

  return (
    <Popover open={open} onOpenChange={setOpen}>
      <PopoverTrigger asChild>
        <Button variant="ghost" size="icon" className="relative" aria-label={`Notifications, ${unreadCount} unread`}>
          <Bell className="h-4 w-4" />
          {unreadCount > 0 && (
            <Badge tone="danger" className="absolute -top-1 h-4 min-w-4 justify-center p-0 text-[10px] [inset-inline-end:-0.25rem]">
              {unreadCount > 9 ? '9+' : unreadCount}
            </Badge>
          )}
        </Button>
      </PopoverTrigger>
      <PopoverContent className="w-80 p-0" align="end">
        {/* scrollable list of the 20 most recent, each row's onClick marks-read + navigates to its own `target`;
            capped at 20 with infinite scroll inside the popover — a full-history /notifications page is a
            natural future extension, not a route this tree defines today */}
      </PopoverContent>
    </Popover>
  );
}
```

Two notification categories deserve a distinct visual treatment inside the dropdown rather than a uniform list: an item whose `type` marks it AI-generated (a recommendation, an insight surfaced as a notification) renders with the same left-border-plus-badge AI treatment `FRONTEND_ARCHITECTURE.md`'s third principle specifies for AI content anywhere else in the app — the bell is not exempt from "every AI-authored element is visually distinct" just because it is a small chrome affordance.

## AI status indicator

A compact pill (not a bell — deliberately a different shape so the two are never confused at a glance), subscribed to `private-company.{id}.ai` (again, `FRONTEND_ARCHITECTURE.md`'s named channel), with three states:

| State | Visual | Meaning | Data |
|---|---|---|---|
| Idle | Small grey dot, label "AI" | No agent currently running a task for this company | Default; no active `ai_tasks` row |
| Active | Pulsing accent-colored dot, tooltip names the agent | At least one agent is mid-task (e.g. Forecast Agent recomputing after a new bank feed line) | Pushed on `ai_tasks` status transitions to `running` |
| Attention | Amber or red dot with a numeric badge | One or more urgent items need a human — critical fraud hold, SLA-breaching approval | Seeded from `GET /api/v1/ai/urgent-actions` on mount, then incremented/decremented purely by push events, never by re-polling |

```tsx
// components/layout/ai-status-indicator.tsx
'use client';

import { useState } from 'react';
import { Tooltip, TooltipTrigger, TooltipContent } from '@/components/ui/tooltip';
import { useRealtimeChannel } from '@/hooks/use-realtime-channel';
import { useSession } from '@/components/providers/session-provider';
import { useQuery } from '@tanstack/react-query';
import { apiClient } from '@/lib/api-client';
import { useRouter } from 'next/navigation';
import { cn } from '@/lib/utils';

type AiStatus = 'idle' | 'active' | 'attention';

export function AiStatusIndicator() {
  const { activeCompany } = useSession();
  const router = useRouter();
  const [status, setStatus] = useState<AiStatus>('idle');
  const [activeAgentLabel, setActiveAgentLabel] = useState<string | null>(null);

  const { data: urgent } = useQuery({
    queryKey: ['ai', 'urgent-actions', 'count'],
    queryFn: () => apiClient.get('/api/v1/ai/urgent-actions'),
  });
  const urgentCount = urgent?.data?.items?.length ?? 0;

  useRealtimeChannel(`private-company.${activeCompany.id}.ai`, {
    onEvent: (event) => {
      if (event.type === 'ai_task.started') { setStatus('active'); setActiveAgentLabel(event.agent_label); }
      if (event.type === 'ai_task.finished') setStatus(urgentCount > 0 ? 'attention' : 'idle');
      if (event.type === 'ai_risk_flag.raised' && event.severity === 'critical') setStatus('attention');
    },
  });

  const resolved: AiStatus = urgentCount > 0 ? 'attention' : status;

  return (
    <Tooltip>
      <TooltipTrigger asChild>
        <button
          onClick={() => router.push(resolved === 'attention' ? '/ai/risks' : '/ai/insights')}
          className="flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs hover:bg-ink-100"
          aria-label={`AI status: ${resolved}`}
        >
          <span className={cn(
            'h-2 w-2 rounded-full',
            resolved === 'idle' && 'bg-ink-300',
            resolved === 'active' && 'animate-pulse bg-accent-600',
            resolved === 'attention' && 'bg-danger',
          )} />
          <span className="hidden font-medium text-ink-700 sm:inline">AI</span>
          {resolved === 'attention' && urgentCount > 0 && (
            <span className="font-semibold text-danger">{urgentCount}</span>
          )}
        </button>
      </TooltipTrigger>
      <TooltipContent>
        {resolved === 'active' && activeAgentLabel ? `${activeAgentLabel} is working` : resolved === 'attention' ? `${urgentCount} urgent item${urgentCount === 1 ? '' : 's'} need you` : 'No AI activity right now'}
      </TooltipContent>
    </Tooltip>
  );
}
```

`resolved` deliberately lets an "attention" state computed from `urgentCount` override whatever the realtime stream's own `status` variable says — a fetched, server-confirmed count is never allowed to be masked by a stale local `idle` the client happened to be sitting in, matching the platform-wide rule that the client's own cached belief about anything is provisional until the server's answer arrives. Clicking the pill is always a *navigation*, never an in-place expansion — it goes to `/ai/risks` when there is something urgent, `/ai/insights` otherwise — keeping the Topbar's AI affordance consistent with the palette's own AI actions (`# Command Palette`): a handoff to a real page, never a third floating panel competing with the Rail and the palette for the same screen space.

# Mobile Navigation

Below `md` (`LAYOUT_SYSTEM.md`'s breakpoint table), the Sidebar is not a narrower version of itself — it is absent, replaced by two complementary surfaces so the ten-module tree and the deep sub-navigation both stay reachable without a second layout system to maintain.

## Bottom tab bar — the five thumb-reachable destinations

A fixed, `56px`-tall bar pinned to the viewport's block-end edge, always visible (it does not hide on scroll — finance users cross-reference numbers while scrolling constantly, and a bar that disappears mid-read is worse than one that costs a few pixels permanently), holding exactly five slots:

```
┌─────────────────────────────────────┐
│  ☰   QAYD               AI●   🔔³    │  ← Topbar, 56px
├───────────────────────────────────────┤
│                                        │
│         Content — full width,         │
│         single column, the active     │
│         Page Template renders here    │
│                                        │
├───────────────────────────────────────┤
│  Dashboard  Accounting  [+]  Banking  More  │  ← bottom tab bar, 56px, fixed
└─────────────────────────────────────┘
```

Four of the five slots are fixed platform-wide (Dashboard, Accounting, Banking, More) and are not user-customizable, for the same cross-tenant-consistency reason the primary nav order is fixed. The center slot is a raised `+` action button (not a nav destination) that opens a permission-filtered quick-create sheet (New Journal Entry, New Invoice, New Bill — whichever the current role can create), because the second most common mobile action after "check a number" is "record something that just happened," and burying that behind "More → Accounting → Journal Entries → New" on a phone is the single most-cited mobile-usability complaint against legacy Gulf ERP mobile apps this design is reacting against. A role with no create permissions anywhere sees the center slot rendered as a disabled, greyed `+` rather than removed — the one deliberate exception to "hide, don't disable" in this whole document, justified because removing the slot would make the four remaining tabs visually uneven and because the slot's disabled state doubles as a legible signal ("you are read-only here") rather than noise.

**More** opens the full permission-filtered `NAV_TREE` as a bottom sheet (`Sheet`, `side="bottom"`, full-height on small phones) — the same data `Sidebar` renders on desktop, in a scrollable list grouped by module, so Sales, Purchasing, Inventory, Payroll, Tax, Reports, AI, and Settings are always exactly as reachable on a phone as on a laptop, just one extra tap away instead of always-visible.

## Hamburger → Sidebar-as-Sheet, for the same long tail

The Topbar's `Menu` trigger (visible `<lg`) opens the identical Sidebar component used on desktop, unmodified, inside a `Sheet` sliding from the inline-start edge — not a redesigned mobile-specific nav tree. This is a deliberate simplification: "More" (bottom tab) and the hamburger (Topbar) both open functionally the same permission-filtered module tree; they exist as two entry points because user testing across comparable Linear/Stripe-style products consistently shows some users reach for the persistent bottom bar and others reach for the familiar top-left hamburger, and QAYD would rather support both muscle memories than force a re-education.

## What does not change on mobile

The Command Palette (`# Command Palette`) is identically available — the Topbar's search pill collapses to a bare `Search` icon button under `sm` but opens the exact same full-screen `CommandDialog`, because "type to find anything" is, if anything, more valuable on a phone's cramped nav than on desktop. The Company & Branch Switcher likewise renders as the same `Popover`-driven `Command` list, just full-width. Nothing about permission filtering, breadcrumb derivation, or deep-link query-param handling branches on viewport — only the *chrome* that presents them does.

# RTL Mirroring

QAYD ships Arabic as a first-class reading mode, not a mirrored translation pass, and the navigation chrome is where that promise is tested hardest because it is the one part of the screen present on every single route. `LAYOUT_SYSTEM.md`'s foundational rule — logical CSS properties (`ms-*`, `me-*`, `ps-*`, `pe-*`, `start-*`, `end-*`, `text-start`, `text-end`) everywhere a physical property (`ml-*`, `pr-*`, `left-*`) could have been used — is what makes every component in this document flip automatically under `dir="rtl"` with zero conditional logic; every TSX snippet above was written with that discipline specifically so this section can be short and structural rather than a component-by-component patch list.

## What flips automatically

Setting `dir="rtl"` on `<html>` (resolved server-side from `user.locale`, `# Routing & Deep Links`) and having used only logical utilities throughout means the following require no `if (locale === 'ar')` branch anywhere in the nav codebase: the Sidebar moves to the visual right and its `border-inline-end` becomes a right-side border automatically; the Topbar's element order (mobile trigger → breadcrumb → search pill → status icons → user menu) reads visually right-to-left in the same source order; `Sheet`'s slide-from-inline-start direction (`COMPONENT_LIBRARY.md`) becomes slide-from-the-right; `Popover`/`Dropdown` alignment (`align="start"` in the `WorkspaceSwitcher` and `AccountPicker`) flips with no prop change; and every `me-*`/`ms-*` gap between an icon and a label (the `Sparkles` icon before "Ask AI," the `Check` glyph before a selected switcher row) stays on the correct side of the text it annotates.

## What must be handled explicitly

A small, enumerated set of exceptions exists precisely because they are not spatial layout — they are directional *meaning*, which logical properties alone cannot resolve:

| Element | LTR | RTL | Why it needs an explicit rule |
|---|---|---|---|
| Breadcrumb separator | `ChevronRight` | Same glyph, `rtl:rotate-180` | A chevron's direction encodes "the next item is forward in the hierarchy," which is visually leftward in RTL — a mirrored icon, not a re-chosen one, per the `Breadcrumbs` component above |
| Sidebar collapse icon | `PanelLeftClose` / `PanelLeftOpen` | Swapped to `PanelRightClose` / `PanelRightOpen` | These Lucide icons are drawn asymmetrically (a rail on one literal side); flipping the *icon choice*, not just its transform, is required for it to still depict "collapse toward the edge the sidebar is actually on" |
| Amounts and dates inside nav (notification rows, palette record results) | Western Arabic numerals, LTR digit order | **Unchanged** — still Western Arabic numerals, still LTR digit order, per `COMPONENT_LIBRARY.md`'s `AmountCell` (`dir="ltr"` pinned, `numberingSystem: 'latn'`) | A KWD amount or an invoice number reading right-to-left digit-by-digit is not a translation, it is a comprehension hazard; every numeral surfaced anywhere in nav chrome — badge counts, the `⌘K` hint, "3 approvals" — is pinned LTR-shaped exactly like `AmountCell` regardless of surrounding direction |
| `⌘K` keyboard hint | `⌘K` | `⌘K`, un-mirrored, `dir="ltr"` on the hint span specifically | A keyboard chord is a physical key combination, not prose; mirroring it would describe a key sequence that does not exist |
| Company/branch names, AI narrative text | Renders per its own `name_en`/`name_ar` field | Same, direction-neutral | Bilingual data fields already carry their own correct string per locale (`name_en`/`name_ar` on every master-data table, per `DESIGN_CONTEXT`'s bilingual-fields convention) — the nav layer's only job is picking the right field via `locale === 'ar' ? name_ar : name_en`, exactly as `AccountPicker` already does |

## Verification discipline

Per `LAYOUT_SYSTEM.md`, an ESLint rule bans physical-direction Tailwind utilities outside the two documented exceptions (numeral alignment, the debit/credit column order `AmountCell` intentionally fixes) — this document's exceptions table above is the nav system's contribution to that same allow-list, kept exhaustive rather than growing ad hoc, because an undocumented `ml-2` slipped into a nav component is exactly the kind of regression that only surfaces when a Kuwait-based QA pass catches a sidebar rendering flush against the wrong edge in Arabic, weeks after an English-only review approved it.

# Keyboard Navigation

Every navigation surface in this document is fully operable with no pointing device, not as an accessibility add-on audited in afterward but as the same interaction contract Radix's underlying primitives (`Dialog`, `Popover`, `DropdownMenu`, `Command`) already provide for free — `COMPONENT_LIBRARY.md`'s "Foundations" section is explicit that QAYD never edits that wiring out. This section is the nav-specific shortcut map and the roving-tabindex contract for the two custom, non-Radix-primitive constructs the Sidebar and Topbar introduce.

## Global shortcuts

| Shortcut | Action | Scope |
|---|---|---|
| `Cmd/Ctrl+K` | Open/close the Command Palette | Anywhere in `(app)` |
| `Cmd/Ctrl+B` | Toggle Sidebar expanded/collapsed | `lg`+ only, per `LAYOUT_SYSTEM.md` |
| `Cmd/Ctrl+Shift+G` | Toggle the 12-column grid debug overlay | Non-production only |
| `Esc` | Close whichever overlay currently has focus — palette, switcher popover, mobile Sidebar Sheet, notification popover | Context-sensitive, one layer at a time (closing the palette when a `Dialog` is stacked above it closes the top layer only, never both) |
| `G` then a letter (e.g. `G` `A`) | Jump directly to a module's root route (`G A` → Accounting, `G B` → Banking) | Reserved, not yet bound — listed here as the documented next step so a future implementation does not collide with an ad hoc scheme; see `# Edge Cases` |

## Inside the Command Palette

Standard `cmdk`/shadcn `Command` behavior, unmodified: `↑`/`↓` moves the highlighted result across all visible groups as one continuous list (not trapped per-group), `Enter` selects the highlighted item, `Tab` does nothing special (there is only one focusable input; Tab would leave the dialog, which Radix's focus trap prevents until `Esc`), and typing immediately re-filters without a separate "search" confirmation step. The palette's own input retains focus for the dialog's entire lifetime — arrow-key navigation of results never steals focus away from the text box, exactly matching `AccountPicker`'s existing `CommandInput`/`CommandList` pattern.

## Inside the Sidebar — roving tabindex, not sequential tab-stops

A ten-module, several-dozen-leaf nav tree must not cost a Tab-only user thirty-plus key-presses to reach Reports. The Sidebar's `<ul role="list">` implements a single roving tabindex: only the currently-active item (or, on first focus entry, the first item) has `tabIndex={0}`; every sibling has `tabIndex={-1}`. `↓`/`↑` move the roving position between visible top-level modules; `→` (or `←` in RTL — direction-aware, not hardcoded) on a module with children expands it and moves the roving position to its first child; `←`/`→` reversed collapses back to the parent. A single `Tab` press from anywhere else in the page therefore lands on exactly one Sidebar item — whichever is currently "active" — and `Shift+Tab` leaves the Sidebar in one press, not thirty.

```tsx
// components/layout/nav-item.tsx (excerpt — roving tabindex wiring)
'use client';

import { useState, type KeyboardEvent } from 'react';

export function NavItem({ module, activePath }: { module: NavModuleFiltered; activePath: string }) {
  const [expanded, setExpanded] = useState(module.children?.some((c) => activePath.startsWith(c.href)) ?? false);
  const isRoving = activePath.startsWith(module.href);

  function onKeyDown(e: KeyboardEvent) {
    if (e.key === 'ArrowRight' && module.children) { setExpanded(true); }
    if (e.key === 'ArrowLeft' && expanded) { setExpanded(false); }
    // ArrowUp/ArrowDown roving-focus movement across siblings is handled one level up,
    // in the <ul> container, via a shared `useRovingIndex` hook — omitted here for brevity.
  }

  return (
    <li>
      <a
        href={module.href}
        tabIndex={isRoving ? 0 : -1}
        aria-current={isRoving ? 'page' : undefined}
        aria-expanded={module.children ? expanded : undefined}
        onKeyDown={onKeyDown}
        className="focus-visible:ring-2 focus-visible:ring-accent-600 focus-visible:ring-offset-2"
      >
        {/* icon + label */}
      </a>
      {expanded && module.children && (
        <ul role="list">{/* child NavLeaf items, same roving contract, one level deeper */}</ul>
      )}
    </li>
  );
}
```

Focus rings use `focus-visible` exclusively (never bare `:focus`, which would ring every mouse click) at `--qayd-accent-600`, 2px, with a 2px offset — the same token and the same visual treatment `Button`'s `cva` definition already uses (`COMPONENT_LIBRARY.md`), so a keyboard user's focus indicator never looks like a different design system bolted onto the nav chrome.

# States & Examples

## State matrix

Every nav surface in this document ships all five states below as first-class, reviewed states — never as an afterthought discovered in production — per `DESIGN_SYSTEM.md`'s "never blank" rule and `FRONTEND_ARCHITECTURE.md`'s sixth principle (full LTR/RTL and light/dark parity, verified in the same pull request that introduces the component).

| Surface | Loading | Empty | Error | RTL | Dark |
|---|---|---|---|---|---|
| Sidebar | Ten grey `Skeleton` bars (module-shaped) while `GET /api/v1/auth/me` resolves server-side — this is sub-100ms in practice since it runs before first paint, but the skeleton exists for the slow-3G case | N/A — Rule 2 of `# Permission-Aware Nav` guarantees a module with zero visible children never renders open; there is no "Accounting section, no items" empty state to design because that state cannot occur | If `/me` itself fails (session expired, network down), the entire shell falls back to a minimal error shell — logo, "Something went wrong, retry," no Sidebar — never a Sidebar with a red error banner floating inside it | Mirrors per `# RTL Mirroring`; workspace switcher trigger's chevron and truncation direction flip | `--qayd-surface`/`--qayd-ink-*` swap per `COMPONENT_LIBRARY.md`'s dark tokens; active-item accent tint (`--qayd-accent-100`) is re-tuned, not just inverted, for AA contrast on `#0F1613` |
| Command Palette | `Searching…` row inside `CommandList` per source while `useQueries` is in flight (`AccountPicker`'s own pattern, reused) | `CommandEmpty` renders a single line: "No pages or records match '{query}'" plus a fallback "Ask AI about '{query}'" item, so an empty result is never a dead end | A single failed record-search source degrades silently — its group simply does not render — rather than surfacing a toast inside an open dialog; Navigate results are unaffected since they are a pure client-side computation with no network dependency | `CommandInput`'s placeholder and all group headings flip to Arabic strings; result text keeps bilingual fields' own `name_ar` | Dialog surface uses `--qayd-surface-raised`; no separate dark-mode-only asset |
| Breadcrumbs | Trail renders progressively — static ancestor segments (module, sub-section) appear immediately from route config; only the final dynamic segment (an entry number, a customer name) waits on `generateBreadcrumb`'s fetch and shows a small inline `Skeleton` chip in its place, never blocking the rest of the trail | N/A (a breadcrumb always has at least the current route) | If `generateBreadcrumb`'s fetch 404s (see `# Edge Cases`), the trail truncates one segment early rather than showing a broken label | `ChevronRight` rotates per the exceptions table; reading order reverses | No token changes beyond the shared ink scale |
| Notifications bell | Badge shows the last-known cached count (not zero, not a spinner) while the background refetch runs, avoiding a flash-to-zero on every mount | `PopoverContent` shows "You're all caught up" with a checkmark, no badge rendered at all (badge is `unreadCount > 0`-gated, not merely hidden-when-zero — a `0` badge never mounts in the first place) | A failed fetch keeps showing the last successful count with a small inline retry affordance in the popover, rather than resetting to an alarming, possibly-wrong `0` | Popover alignment flips (`align="end"` resolves to the visual left in RTL); notification row text is bilingual per its own payload | Danger-tone badge uses the dark-mode `--qayd-danger-600`, not the light-mode hex, for AA contrast |
| AI status pill | Renders `idle` (grey dot) as its default optimistic state rather than a spinner — an unknown AI state is treated as calm, not alarming, consistent with the platform's "family tone: calm, never alarming" mandate | N/A | If the Reverb connection itself drops, the pill freezes at its last known state and gains a small offline-slash overlay rather than falsely reporting `idle` | Tooltip text and the "AI" label localize; dot position follows `me-*` gap, unaffected by direction beyond ordering | `bg-danger`/`bg-accent-600` dot colors use dark-mode token values automatically via the shared `cn()` class list — no separate dark branch in the component |
| Company/Branch switcher | Trigger shows the last-known active company name instantly (hydrated from the server-rendered session, never a loading flash on normal navigation); the popover's list skeletons only if reopened before the companies list query has ever resolved | An account with exactly one company and no branches renders the trigger as a static, non-interactive label with no chevron — nothing to switch *to* is not styled as a disabled interactive control, it is simply not a control | A failed `switch-company` call rolls the trigger label back to the previous company and surfaces a toast ("Couldn't switch to {company} — try again"); the session cookie was never touched server-side if the Route Handler itself errored, so there is nothing to reconcile client-side beyond the label | Popover and its `Command` list mirror fully; branch names render via `name_ar` | Selected-row check icon and tint use dark accent tokens |

## Worked example — three roles, one morning, three different shells

Reusing `AI_COMMAND_CENTER.md`'s own running example — **Al-Noor Trading & Contracting W.L.L.** (`company_id: 4821`), Kuwait City HQ (`branch_id: 1`), Al-Ahmadi yard (`branch_id: 2`), Riyadh sales office (`branch_id: 3`) — makes the permission-aware nav rules concrete rather than abstract.

**Fahad Al-Ostath, Owner (`user_id: 101`)**, opens QAYD at 07:12. His Sidebar shows all ten modules, every sub-item, and the AI status pill currently reads `attention` (a critical fraud hold is open) with badge `1`. His Topbar breadcrumb on `/dashboard` is simply "Dashboard" (single segment — the home route never grows a longer trail). Opening ⌘K and typing "diyar" returns a **Navigate** hit for nothing (no page is named Diyar), a **Records** hit under a "Customers" group for *Diyar Real Estate*, and — because `urgentCount > 0` — the **AI & Actions** group leads with "1 approval waiting" ahead of the static Ask AI/Risks entries, reordered dynamically per the ranking rule in `# Command Palette`.

**Mariam Al-Sabah, CFO (`user_id: 118`)**, opens the same company the same morning. Her Sidebar is identical to Fahad's — CFO carries effectively the same read breadth as Owner across Accounting, Banking, Reports, and AI, per `PERMISSION_SYSTEM.md`'s role list — but her Payroll section shows Employees and Payroll Runs without the Loans sub-item, because `payroll.loan.approve`/`.create` are not in her role's default grant. She switches her Branch Switcher to "Riyadh" before opening Cash Flow Status; the URL becomes `/dashboard?branch=3`, and if she pastes that link to Khalid Marafie (Finance Manager), his browser opens the identical Riyadh-scoped dashboard — provided `branch_id: 3` is a branch his own role can see (it is; Finance Manager has no branch restriction at Al-Noor Trading).

**Dana Al-Rashidi, External Auditor (`user_id: 155`)**, time-boxed access valid through end of month, sees a visibly smaller Sidebar: Accounting (read-only — Journal Entries list and Trial Balance visible, but the `PageHeader`'s "New Journal Entry" action slot is absent per Rule 5 of `# Permission-Aware Nav`), Tax (read-only), Reports, and nothing else — no Banking, no Sales, no Payroll, no AI. Her Command Palette's Records group therefore never fans out to `sales/customers/search` or `payroll/employees/search` at all, because the permission-filter step in `# Command Palette` drops those sources before a single request fires. Her Topbar shows no AI status pill and no "AI" module — External Auditor is not in the default role list any AI Command Center panel grants `reports.read` to for narrative/recommendation surfaces, only for the underlying financial reports themselves, so the chrome reflects that distinction structurally rather than showing her an AI affordance that would 403 the moment she clicked it.

# Edge Cases

| # | Scenario | Resolution |
|---|---|---|
| 1 | A role's permission grant, after all filtering, leaves the Sidebar with **zero** visible modules (a misconfigured custom role with no `*.read` keys at all). | The Sidebar renders its structural chrome (workspace switcher, bottom utility row) with an empty middle region showing "No sections available — contact your company administrator," never a blank white gap that reads as a loading failure. Dashboard is still reachable (it has no gate) so the user is never fully locked out of the shell itself. |
| 2 | A user's permissions change **while they are actively using the app** (an admin revokes a role mid-session; no full page reload occurs). | The permission array is not re-fetched on a timer. It is invalidated the moment a `permission_changes`-class event arrives on the user's own notification channel (the same `private-company.{id}.notifications.{user_id}` stream), which triggers `queryClient.invalidateQueries(['auth','me'])` and a Sidebar re-filter with no navigation and no data loss on the current screen — the user does not lose their place, but a now-hidden nav item disappears from the Sidebar within the same push-latency window a notification would otherwise arrive in. If they are actively looking at a screen whose permission was just revoked, that screen's own `error.tsx` boundary is what actually removes their access, on their next request, not the nav. |
| 3 | A `?branch=<id>` deep link references a branch that has since been deleted, or belongs to a different company than the one currently active. | `parseBranchParam` (`# Routing & Deep Links`) only validates *shape* (a positive integer); *existence and ownership* are the API's job. The receiving screen's own query simply returns an empty/filtered-to-nothing result for an invalid branch, and the Branch Switcher's popover — which does hold the authoritative branch list for the active company — silently falls back to "All branches" in its own displayed state rather than showing a phantom selected row for an id it doesn't recognize. |
| 4 | A notification or an AI-surfaced `target` link points to a specific record (`/accounting/journal-entries/482`) that has since been deleted, voided, or belongs to a company the user has since lost access to. | The route's own `not-found.tsx` (`FRONTEND_ARCHITECTURE.md`'s convention) renders — and, per that document's stated design, is **deliberately indistinguishable** from "this entry belongs to a different company" from the outside, so a stale link never leaks the fact that a resource exists in a tenant the viewer cannot see into. The nav chrome (breadcrumb, Sidebar) is unaffected; only the content region shows the not-found state. |
| 5 | A user belongs to **50+ companies** (an outsourced accounting firm's staff account, servicing many client companies). | The `WorkspaceSwitcher`'s `Command` list is already search-first, not a scroll-and-scan list, so raw count is a non-issue for discovery. The companies array itself is paginated server-side past a threshold (`GET /api/v1/auth/me` returns the 20 most-recently-active companies plus a `has_more` flag; the switcher's search input, once typed into, calls `GET /api/v1/companies?q=` directly rather than filtering the initial 20 client-side) so the Topbar's initial payload never grows unbounded for a large-book accountant. |
| 6 | The active company or branch name is very long (a legal entity name running 60+ characters) or renders in a script (Latin-only company name) opposite the surrounding UI's direction. | The `WorkspaceSwitcher` trigger truncates with an ellipsis at a fixed max-width and exposes the full name via a native `title` tooltip attribute — never wraps to a second line inside the fixed `56px` header band. A Latin-script company name inside an Arabic-direction shell is wrapped in `dir="auto"` (browser-resolved per-string direction) rather than inheriting the ambient `dir="rtl"`, so "Al-Noor Trading & Contracting W.L.L." reads left-to-right even when every surrounding label is Arabic — the same rule `AmountCell` applies to numerals, generalized to any field whose content, not the UI's language, determines its direction. |
| 7 | `Cmd/Ctrl+K` collides with a browser or OS-level shortcut in some locales/browsers (Firefox's own bookmark search, some Linux window managers). | The listener calls `preventDefault()` unconditionally when the app has focus, which is sufficient in the overwhelming majority of cases; where a browser reserves the combination at a level the page cannot intercept, the Command Palette trigger button in the Topbar remains a fully equivalent, always-visible fallback — the shortcut is an accelerator, never the only path to the feature. |
| 8 | The reserved `G`-then-letter chord (`# Keyboard Navigation`) is typed while focus is inside a text `Input` (e.g., a search box, a journal entry description field). | Single-letter chords are scoped to fire only when `document.activeElement` is not an editable element (`input`, `textarea`, `[contenteditable]`, or a Radix `Command` input already open) — identical to the guard every editor-adjacent app (Linear, Superhuman, Gmail) applies to its own letter shortcuts, preventing "G" from hijacking someone typing the word "Guarantee" into a memo field. |
| 9 | A user's session survives a **company switch's Route Handler call succeeding** but the subsequent `router.refresh()` failing (e.g., the tab loses connectivity for a moment right after the switch). | `queryClient.clear()` already ran, so no stale-company data can leak into a screen even if the refresh is delayed — the UI shows loading states until connectivity returns and the refresh completes, never a hybrid screen mixing the old company's cached data with the new session. |
| 10 | The AI status pill's underlying event stream is noisy — an agent starts and finishes several short tasks within a few seconds, which would otherwise make the dot flicker `idle`/`active`/`idle` rapidly. | Transitions into `idle` are debounced 3 seconds from the last `active` signal (transitions *into* `active` and `attention` are never debounced — urgency is never delayed for smoothness); a burst of short-lived tasks reads as one continuous "active" period rather than a strobing dot, matching the platform's "calm, purposeful micro-motion, never distracting" mandate. |
| 11 | A native mobile shell (Capacitor-style wrapper, mirroring the pattern QAYD's sibling products use for a native app around a web core) deep-links into a route via a push notification while the app is cold-started. | The deep link resolves through the identical Next.js route table this document defines — there is no separate "mobile deep link scheme" with its own path grammar to keep in sync; a push notification's `target` field is the same string an `ai_decisions.actions[].target` or a web notification's `target` would carry. |
| 12 | Two nav-adjacent surfaces are open simultaneously — the Command Palette is open and a realtime event pushes the AI status pill from `idle` to `attention` underneath it. | The palette does not re-render its own AI & Actions group counts mid-keystroke from a background push (that would shift result positions under a user's cursor while they are actively arrow-keying through results); the updated count is picked up the next time the palette is opened fresh, while the Topbar pill itself — being outside the modal — updates immediately, so the two surfaces are allowed to be momentarily out of sync by design rather than fighting to stay pixel-perfectly synchronized while one of them is mid-interaction. |
| 13 | A company's configured primary locale is Arabic, but an individual user's own `locale` field is English (a Kuwait-based company with an expatriate CFO). | Locale resolution is always **per-user**, never per-company, for the nav chrome and for every screen — `COMPANY_STRUCTURE.md`'s "Language" company setting governs defaults for *new* users joining that company and the language AI-generated content is drafted in when no user is present (a scheduled report email), not the signed-in user's own rendered UI direction. |
| 14 | The Sidebar's collapsed (`72px`, icon-only) state is active, and a module's icon alone is ambiguous (Accounting's `BookOpenCheck` versus Reports' `BarChart3` are visually distinct, but a colorblind user relying on shape alone across ten icons is a real access need). | Every icon-only nav item retains a native `title` attribute and a full `aria-label` with the module's text name regardless of collapsed state — the collapsed rail is a density optimization for sighted, icon-literate users, never the sole source of the item's identity for assistive tech or for hover-tooltip disambiguation. |

# End of Document
