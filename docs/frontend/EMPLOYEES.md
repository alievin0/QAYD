# Employees — QAYD Frontend
Version: 1.0
Status: Design Specification
Module: Frontend
Submodule: EMPLOYEES
---

# Purpose

This document specifies the Next.js 15 frontend for QAYD's Employees screen pair inside the Payroll
module: the employee **List** (`app/(app)/payroll/employees/page.tsx`) and the employee **Profile**
(`app/(app)/payroll/employees/[employeeId]/page.tsx`), plus the **Create/Edit** surface (`EmployeeForm`)
that feeds both. It is the frontend counterpart to `docs/accounting/PAYROLL.md`, which owns the
`employees` table, the `employment_status` state machine, `employee_salary_components`,
`employee_contracts`, `employee_social_insurance`, the bank-account/payment model, and every permission
and business rule this screen renders. Where this document is silent, `FRONTEND_ARCHITECTURE.md`,
`DESIGN_LANGUAGE.md`, `COMPONENT_LIBRARY.md`, `NAVIGATION_SYSTEM.md`, `RESPONSIVE_DESIGN.md`,
`DARK_MODE.md`, and `ACCESSIBILITY.md` govern; where it appears to contradict one of them, that is a
defect to raise, not a decision to resolve unilaterally in code.

Employees is the screen an HR Manager opens on someone's first day to enter their civil ID, passport,
bank account, and starting salary; the screen a Payroll Officer opens the week before a run to confirm
every active employee is "payroll-ready"; the screen a Finance Manager or CFO opens to review
compensation for a promotion; and the screen an Auditor opens to sample HR/AP controls end to end. It is
also, by a wide margin, the most privacy-sensitive master-data screen in QAYD: a single row here can
carry an encrypted national ID, an encrypted passport number, an encrypted IBAN, a nationality, a
marital status, and — gated behind a permission most roles in the company do not hold — an exact salary
figure. Every design decision in this document is downstream of that fact. Concretely, this document
specifies the screen pair that composes:

1. **An Employees `DataTable`** — the searchable, filterable, sortable roster of every `employees` row
   the caller's company holds, with department, branch, employment status, and employment type filters, a
   compact KPI band answering "how many people, how many payroll-ready, how many need attention," and
   permission-gated row actions. This is the screen `NAVIGATION_SYSTEM.md`'s Payroll module (`Users` icon,
   parent gate `payroll.read`) points its "Employees" sub-item at, keyed on `payroll.employee.read`
   (`NAVIGATION_SYSTEM.md → Sub-navigation per module → Payroll`).
2. **An employee profile** (`[employeeId]/page.tsx`) — the complete payroll-relevant employee master
   record, organized into an Overview (identity, employment summary, the payroll-readiness checklist, and
   the employment-status timeline) plus dedicated tabs for **Employment & Contracts**, **Compensation**
   (salary components, gated independently of every other tab), **Bank & Payment**, **Documents**,
   **Emergency Contacts**, **Attendance & Leave**, and **Audit Log**.
3. **A create/edit surface** (`EmployeeForm`) — the identity/employment/compensation-seed form used both
   for onboarding a brand-new hire (a full-page route, because onboarding runs a payroll-readiness gate a
   user needs room to work through) and for editing an existing employee's core profile fields (a `Sheet`
   opened from the profile, for routine field maintenance that carries no lifecycle consequence).
4. **Lifecycle affordances** — permission-gated actions for activate (onboarding → active),
   terminate (with the final-settlement notice), rehire, archive, and unarchive — every one of them a thin
   rendering of the exact state machine `docs/accounting/PAYROLL.md → Employee Lifecycle` defines
   (`applicant → hired → onboarding → active → (promoted | transferred | on_leave)* → terminated →
   (rehired → active | archived)`), never a client-side re-derivation of it.
5. **Salary-privacy masking** — the single rule that shapes more of this screen's implementation than any
   other: `payroll.salary.view` is not implied by `payroll.employee.read`, `accounting.read`, or any
   generic "manager" role (`docs/accounting/PAYROLL.md → Payroll Philosophy`, Principle 5, "Privacy is
   structural, not procedural"). A user who can see that "Fahad Al-Mutairi, Senior Accountant, Finance
   Department" exists may still see nothing but a lock icon where his base salary would render.

This screen, like every screen in QAYD, **owns no business logic**. It never decides whether an employee
is payroll-ready, never computes a payroll-readiness gate, never derives whether a bank account is the
disbursement target, and never lets an AI agent's confidence substitute for the human judgment the
platform reserves for HR and Payroll staff. Every figure rendered here was computed and validated by
Laravel; every mutation this screen triggers is a call to `/api/v1/payroll/employees/...` guarded by the
exact permission key the API itself enforces (`FRONTEND_ARCHITECTURE.md`, Principle 1 and Principle 4).

Employees shares its parent Payroll module with three sibling nav destinations this document does not
own and does not duplicate: **Payroll Runs** (`/payroll/runs/[runId]`), **Payslips**
(`/payroll/payslips`), **Attendance** (`/payroll/attendance`), and **Loans** (`/payroll/loans`) — all four
named in `NAVIGATION_SYSTEM.md → Sub-navigation per module → Payroll`. Where the Employee Profile needs to
show a payslip, a loan balance, or an attendance summary, it renders a read-only, employee-scoped
composition of that sibling's own endpoint and deep-links out to the sibling route for management — the
same "compose, don't duplicate" discipline `docs/frontend/CUSTOMERS.md`'s Transactions tab and
`docs/frontend/VENDORS.md`'s Statement tab both apply to screens that have not yet been documented in
full. This document does not invent a route, a permission, a lifecycle state, or an API shape that
`docs/accounting/PAYROLL.md` has not already established; where this document introduces something
screen-specific — a composed component, a convenience endpoint, or a permission key `PAYROLL.md`'s own
table does not enumerate — it says so explicitly rather than implying it was already specified elsewhere.

Two audiences share this one shell, per the platform's "two audiences, one shell" rule
(`docs/frontend/VENDORS.md → Purpose`): an HR Manager or Payroll Officer doing high-volume, keyboard-
friendly onboarding and day-to-day master-data maintenance (comfortable density, the `EmployeeForm`, the
payroll-readiness checklist), and a Finance Manager, CFO, or Owner making compensation and compliance
decisions (the Compensation tab, the Audit Log, the AI-surfaced fraud/compliance flags). Both are built
from the same components — `DataTable`, `StatusPill`, `AmountCell`, `KpiTile`, `Stepper`, `ConfidenceBadge`
— composed differently; there is no separate "payroll-only mode" build. A third, much narrower audience —
the **Employee** role itself, in self-service — sees a version of the Profile route scoped to their own
`employee_id` only, per `docs/accounting/PAYROLL.md → Permissions`'s `payroll.payslip.view.self` row; that
narrower surface is described inline wherever it diverges from the HR/Finance view, not as a separate
document.

# Route & Access

## App Router path

Matching `FRONTEND_ARCHITECTURE.md → App Router Structure`'s own route-tree sketch, which already shows
`payroll/employees/[employeeId]/page.tsx` verbatim alongside `payroll/runs/`, `payroll/payslips/`,
`payroll/attendance/`, and `payroll/loans/`. This document extends that sketch with the list, create, and
file-convention siblings the top-level tree elides for brevity — the identical, "reasonable extension, not
a departure" move `docs/frontend/VENDORS.md → Route & Access` makes on top of the same platform sketch for
its own `purchasing/vendors/` segment:

```text
app/(app)/payroll/
├── layout.tsx                        # Sub-nav: Employees | Payroll Runs | Payslips | Attendance | Loans
│                                      # (payroll.read gate — NAVIGATION_SYSTEM.md → Payroll)
└── employees/
    ├── page.tsx                      # ★ THIS DOCUMENT — List (DataTable + KPI band)
    ├── loading.tsx                   # List skeleton, streamed while the Server Component fetches
    ├── new/
    │   └── page.tsx                  # ★ THIS DOCUMENT — Full-page create (EmployeeForm mode="create")
    └── [employeeId]/
        ├── page.tsx                  # ★ THIS DOCUMENT — Profile (view), or EmployeeForm in edit mode
        ├── loading.tsx               # Profile skeleton
        └── not-found.tsx             # Cross-company / genuinely-missing employee id
```

`[employeeId]` is not this document's invention — it is the exact dynamic-segment name
`FRONTEND_ARCHITECTURE.md`'s own tree already uses — and it follows the platform's standing convention,
camelCase, named for the entity, never the generic `[id]`. Its API-resource mirror is
`GET /api/v1/payroll/employees/{id}`, the same path `NAVIGATION_SYSTEM.md → Permission-Aware Nav` cites
verbatim when illustrating what a `403` on a direct API hit looks like for a role lacking
`payroll.employee.read`.

**One route, two rendered modes, chosen by server-known editability — not four routes.** As in
`docs/frontend/CUSTOMERS.md → Route & Access`, there is deliberately no separate `/[employeeId]/edit`
route. Routine field edits (contact details, tags, non-lifecycle fields) remain available in every
employment status except `terminated` and `archived` (`docs/accounting/PAYROLL.md → Employee Lifecycle →
Archive`: archiving "only removes the employee from active-employee UI lists and dropdowns," which this
document reads, consistently, as also freezing the record against routine edits — a terminated or archived
person's historical record should not silently drift). The mode switch is driven by an explicit `?edit=1`
query parameter opened from the Profile's "Edit" action, not by status the way a payroll run's draft/
posted split works:

```tsx
// app/(app)/payroll/employees/[employeeId]/page.tsx
import { getEmployee } from "@/lib/api/payroll";
import { EmployeeProfile } from "@/components/payroll/employee-profile";
import { EmployeeForm } from "@/components/payroll/employee-form";
import { notFound } from "next/navigation";

export default async function EmployeePage({
  params, searchParams,
}: {
  params: Promise<{ employeeId: string }>;
  searchParams: Promise<{ edit?: string; tab?: string }>;
}) {
  const { employeeId } = await params;
  const { edit, tab } = await searchParams;
  const employee = await getEmployee(employeeId).catch(() => null);
  if (!employee) notFound();

  if (edit === "1") {
    if (employee.employment_status === "terminated" || employee.is_archived) notFound();
    return <EmployeeForm mode="edit" employeeId={employee.id} defaultValues={employee} />;
  }
  return <EmployeeProfile initialEmployee={employee} initialTab={tab} />;
}
```

A terminated or archived employee's "Edit" action is omitted from the Profile header entirely (per
`# Accessibility`'s "a sensitive action with no permission is omitted, not disabled" rule, extended here —
as `docs/frontend/CUSTOMERS.md` extends it for a blacklisted customer — to "a routine action structurally
inapplicable to this state is omitted too"), so the `?edit=1` guard above is defense-in-depth against a
stale bookmark or a hand-typed URL, never the only barrier: the server's own `UpdateEmployeeRequest`
re-checks the identical condition unconditionally.

## Access gate

The route sits inside the `(app)` route group behind `middleware.ts`'s session check and the resolved
`X-Company-Id` context. A user without `payroll.employee.read` never sees "Employees" under the Payroll
section of the Sidebar, and — per `NAVIGATION_SYSTEM.md → Permission-Aware Nav → Rule 1`, "hide, always,
when the answer is 'this role never touches this'" — a Warehouse Employee's Sidebar has no Payroll entry
at all, not a greyed-out one. A direct hit on `/payroll/employees` or `/payroll/employees/[employeeId]`
without the permission renders the shared `error.tsx` boundary ("You don't have access to this," with a
link back to Dashboard), never a silent redirect and never the Next.js default error screen
(`NAVIGATION_SYSTEM.md → Permission-Aware Nav`). An `employeeId` that exists but belongs to a different
company renders `not-found.tsx` instead of a `403` — the API itself returns `404` for a cross-tenant
record, matching the platform-wide rule (`docs/frontend/CUSTOMERS.md → Route & Access`, restated
identically for Journal Entries and Vendors) that "a `403` would leak the fact that the ID space is
shared; a `404` does not."

A **branch-scoped Payroll Officer's own-branch scoping** is enforced server-side, never re-derived
client-side: `docs/accounting/PAYROLL.md → Security → Salary Privacy` states plainly that a branch-scoped
`payroll.salary.view` holder is restricted, via Postgres row-level security, to `employee_salary_components`
rows within their branch — and this screen extends that same server-side discipline to the employee roster
itself. The List's `GET /payroll/employees` call carries no client-computed branch filter by default; the
API returns whatever set of rows the caller's role and branch scope resolve to, and the frontend renders
exactly that page without assuming it is the whole company.

## Permission surface on this screen

`docs/accounting/PAYROLL.md → Permissions` is the authoritative, company-wide Payroll permission table and
this document reuses every key in it verbatim (`payroll.salary.view`, `payroll.salary.manage`,
`payroll.pii.view`, `payroll.payslip.view`, `payroll.payslip.view.self`, `attendance.read`, `leave.approve`,
`payroll.loan.create`, `payroll.loan.approve`, `reports.payroll.view`). That table, however, is scoped to
payroll **calculation and money movement** — it does not itself enumerate a granular key for employee
master-data CRUD, because `docs/accounting/PAYROLL.md → Employee Master Data`'s own framing is that "HR
module owns the `employees` table's identity/organizational core... and Payroll extends it." Exactly as
`docs/frontend/VENDORS.md → Route & Access` observes for vendor records ("vendors are conceptually part of
the Purchasing/AP domain even though [Accounting] owns the underlying tables"), employee master-data CRUD
is conceptually part of Payroll's own screen even though its lifecycle sits at the HR/Payroll boundary —
and `NAVIGATION_SYSTEM.md → Sub-navigation per module → Payroll` already establishes the base key this
document builds on: `payroll.employee.read`. This document is the canonical source for the remaining
`payroll.employee.*` family, following the platform's `<area>.<entity>.<action>` grammar exactly:

| Permission key | Gates on this screen | Absent behavior |
|---|---|---|
| `payroll.employee.read` | The route itself; every list row; the Profile's Overview/Employment tabs | Route renders the shared `error.tsx` boundary; nav item hidden (`NAVIGATION_SYSTEM.md`) |
| `payroll.employee.create` | "New Employee" button, `/new` route, the `N` shortcut | Button/route removed from the DOM, not disabled |
| `payroll.employee.update` | "Edit" action; inline tag/emergency-contact edits; the payroll-readiness checklist's "Fix now" links | Menu item omitted; affected fields render read-only |
| `payroll.employee.terminate` | "Terminate" action and the final-settlement notice dialog | Action omitted |
| `payroll.employee.rehire` | "Rehire" action on a `terminated` employee | Action omitted |
| `payroll.employee.archive` | "Archive" / "Unarchive" toggle | Action omitted |
| `payroll.salary.view` | Every amount on the Compensation tab; the salary figure on Overview's summary card | Amounts render as `PayrollAmountCell`'s restricted state (a lock icon + "Restricted"), never `0.00` or blank (see `# Components Used`) |
| `payroll.salary.manage` | "Add salary component" / "Supersede" actions on the Compensation tab | Action omitted; existing components (if visible at all) render read-only |
| `payroll.pii.view` | The unmasked national ID, passport number, and full IBAN/account number on Overview and Bank & Payment | Fields render their platform-standard masked form (`***-***-1234`, IBAN last-4 only) regardless of role, per `docs/accounting/PAYROLL.md → Security → Encryption` |
| `payroll.payslip.view` | The Payslips summary on the Profile (any employee) | Section omitted for this employee unless the caller also holds `.view.self` and this is their own record |
| `payroll.payslip.view.self` | The Payslips summary when viewing **one's own** employee record only | Irrelevant for any other employee's profile |
| `attendance.read` | The Attendance & Leave tab's summary cards | Tab renders a "Requires attendance.read" notice in place of the summary |
| `leave.approve` | Pending-leave-request action affordances surfaced from the Attendance & Leave tab (deep link only — approval itself happens on the not-yet-documented Leave screen) | Affordance omitted |
| `payroll.loan.create` / `payroll.loan.approve` | The Compensation tab's "Loans & Advances" summary card and its "Manage in Loans" link | Card omitted entirely rather than shown empty |
| `reports.payroll.view` | The List's "Export" action and the Profile's "Export employee file" action | Button omitted |
| `ai.payroll.override` | Dismiss/acknowledge affordances on Fraud Detection, Compliance, and data-completeness AI flags (`# AI Integration`) | Flags render read-only (visible, no action) |

Default role grants mirror `docs/accounting/PAYROLL.md → Permissions`'s role table exactly for every key
that table already defines, extended with this document's own `payroll.employee.*` family using the
identical role set that table already established (Owner, CEO, CFO, Finance Manager, Payroll Officer,
HR Manager, Senior Accountant, Accountant, Auditor / External Auditor, Employee, AI Agent) so the two
tables never drift:

| Role | employee.read | employee.create/update | employee.terminate/archive | salary.view | pii.view |
|---|---|---|---|---|---|
| Owner | Yes | Yes | Yes | Yes | Yes |
| CEO | Yes | No | No | Yes | Limited |
| CFO | Yes | No | No | Yes | Limited |
| Finance Manager | Yes | No | No | Yes | Limited |
| Payroll Officer | Yes (scoped) | Yes (scoped) | No | Yes (scoped) | Yes (scoped) |
| HR Manager | Yes | Yes | Yes | Yes | Yes |
| Senior Accountant | Yes | No | No | No | No |
| Accountant | Yes | No | No | No | No |
| Auditor / External Auditor | Yes (read-only) | No | No | Yes (read-only) | Yes (read-only) |
| Employee | Own record only | No | No | Own only | Own only |
| AI Agent | Read-only, masked PII | No | No | No (masked) | No |

`usePermission()` is the only place either table's grants resolve against at runtime; this screen's UI
never hand-derives a role check from a role name string.

## Keyboard entry points

Consistent with `ACCESSIBILITY.md → Global keyboard shortcuts`: `G` then `E` navigates to this screen from
anywhere in the app (mirroring `G` `C` for Customers and `G` `V`-family patterns for Vendors); `N`, scoped
to this route, opens `/new` (only reachable when `payroll.employee.create` is held — the shortcut and the
button share one permission check, never two independently-maintained gates). `/` inside the List focuses
the search box; `Cmd/Ctrl+K` opens the Command Palette, which indexes individual employees by
`employee_code` and bilingual name for direct navigation — but, per `NAVIGATION_SYSTEM.md`'s own worked
example for a scoped role, the permission-filter step drops this source entirely for a caller lacking
`payroll.employee.read`, so an Auditor without Payroll access never even sees `payroll/employees/search`
fan out in their palette.

# Layout & Regions

All three sub-surfaces compose `LAYOUT_SYSTEM.md`'s existing page templates verbatim — a screen never
invents a new template shape for Employees.

## List — `employees/page.tsx`

```text
┌───────────────────────────────────────────────────────────────────────────────────────┐
│  Employees   Payroll Runs   Payslips   Attendance   Loans           [sub-nav tabs]     │
├───────────────────────────────────────────────────────────────────────────────────────┤
│  Employees                                    [Import] [Export] [+ New employee]      │
│  214 employees · 6 pending onboarding                                                  │
├───────────────────────────────────────────────────────────────────────────────────────┤
│ ┌───────────────┐ ┌───────────────┐ ┌───────────────┐ ┌───────────────┐               │
│ │ Active         │ │ Onboarding /   │ │ Payroll-ready  │ │ Documents      │  KPI band   │
│ │ 198            │ │ pending gate   │ │ gate failing   │ │ expiring ≤30d  │  (KpiTile×4)│
│ │                │ │ 6              │ │ 3              │ │ 5              │             │
│ └───────────────┘ └───────────────┘ └───────────────┘ └───────────────┘               │
├───────────────────────────────────────────────────────────────────────────────────────┤
│  ┌ AI · Fraud Detection ─────────────────────────────────────────────────────────────┐ │
│  │ ● Same IBAN used by 2 active employees (EMP-000512, EMP-000871) — review before   │ │
│  │   the next payroll run.                        [Dismiss]  [Review →]             │ │
│  └───────────────────────────────────────────────────────────────────────────────────┘ │
├───────────────────────────────────────────────────────────────────────────────────────┤
│  [Search…]  [Department ▾]  [Branch ▾]  [Status ▾]  [Type ▾]          [Density ▾]      │
│  ┌─────────────────────────────────────────────────────────────────────────────────┐  │
│  │   Code       Name                  Department   Status       Type        Ready ⋯ │  │
│  │ ─────────────────────────────────────────────────────────────────────────────── │  │
│  │   EMP-000512 Fahad Al-Mutairi       Finance      ●Active      Full-time   ✓ ⋯   │  │
│  │   EMP-000871 Dana Al-Rashidi        Finance      ●Active      Full-time   ✓ ⋯   │  │
│  │   EMP-001204 Noura Boushehri        Operations   ●Onboarding  Part-time   ✗ ⋯   │  │
│  │   EMP-000330 Khalid Marafie         Sales        ●On leave    Full-time   ✓ ⋯   │  │
│  │   …                                                              [1 2 3 … 9]    │  │
│  └─────────────────────────────────────────────────────────────────────────────────┘  │
└───────────────────────────────────────────────────────────────────────────────────────┘
```

| Region | Contents | Streaming boundary |
|---|---|---|
| Sub-nav | `Tabs`: Employees (active) · Payroll Runs · Payslips · Attendance · Loans, real `<Link>`s per `payroll/layout.tsx` (Server Component) | Not streamed — part of the layout shell |
| Page header | `<h1>Employees</h1>`, a live count + onboarding-pending summary (`meta.pagination.total`), and three permission-gated actions (`Import`, `Export`, `New employee`) | Renders with the page shell |
| KPI band | Four `KpiTile`s: Active, Onboarding/Pending Gate, Payroll-Ready Gate Failing, Documents Expiring ≤30d | Own `<Suspense>` boundary — `GET /payroll/employees/stats` (convenience endpoint, `# Data & State`) |
| AI flags banner | A dismissible `AIProposalPanel`-style card, omitted entirely when the flag queue is empty or the caller lacks `ai.payroll.override` | Own `<Suspense>` boundary — `GET /payroll/employees/ai-flags?limit=1` (count-only probe) |
| Filter bar | Search, Department select, Branch select, Status multi-select (`employment_status`), Type select (`employment_type`), density toggle | Part of `EmployeesTable`'s own client boundary |
| Employees table | `<EmployeesTable/>` (a preset `DataTable`) | Own `<Suspense>` boundary — `GET /payroll/employees` |

Each region streams independently behind its own `<Suspense>` boundary per `FRONTEND_ARCHITECTURE.md →
Rendering Strategy`: a slow AI-flag probe never delays the KPI band or the table from painting, and a
failure in one region renders that region's own `ErrorState` without taking the page down (`# States`).
The **Status** filter's options are exactly the seven `employment_status` values
(`docs/accounting/PAYROLL.md → Database Design`: `applicant, hired, onboarding, active, on_leave,
terminated, archived`), with `archived` excluded from the default view (an "Include archived" toggle
reveals it) — mirroring `docs/frontend/CUSTOMERS.md`'s identical "Archived" handling. The **Ready** column
renders a compact checkmark/cross reflecting `employees.payroll_ready`
(`docs/accounting/PAYROLL.md → Employee Lifecycle → Onboarding`'s payroll-readiness gate — national ID,
bank account, and a validated base salary all present), never independently recomputed on the client.

## Create / Edit — Form Page Template

```text
┌──────────────────────────────────────────────────────┬──────────────────┐
│ New Employee                  [Cancel] [Save Employee]│ Payroll-ready    │
├────────────────────────────────────────────────────────┤  checklist       │
│  Personal Information                                  │  ✗ National ID   │
│  First name (EN) [___________] Last name (EN)[________]│  ✗ Bank account  │
│  First name (AR) [___________] Last name (AR)[________]│  ✗ Base salary   │
│  Date of birth [__/__/____]  Gender [Male ▾]            │                  │
│  Nationality [Kuwait ▾]  Marital status [Single ▾]      ├──────────────────┤
├────────────────────────────────────────────────────────┤  Duplicate check │
│  Identity & Documents                                    │  No match found  │
│  National ID [________________]  Expiry [__/__/____]     │  ✓ Clear to save │
│  Passport no. [______________]  Country [___] Expiry[__] │                  │
├────────────────────────────────────────────────────────┤                  │
│  Employment                                              │                  │
│  Department [Finance ▾]  Branch [Kuwait City ▾]          │                  │
│  Manager [Fahad Al-Mutairi ▾]  Position [Accountant ▾]   │                  │
│  Employment type [Full-time ▾]  Hire date [__/__/____]   │                  │
├────────────────────────────────────────────────────────┤                  │
│  Starting Compensation                                    │                  │
│  Base salary [750.000] KWD  Payment method [Bank ▾]       │                  │
├──────────────────────────────────────────────────────┴──────────────────┤
│ ── sticky footer, appears once header scrolls out of view ──────────────│
│                    [Cancel]              [Save Employee]                 │
└────────────────────────────────────────────────────────────────────────┘
```

- **Page Header** — "New Employee" / "Edit EMP-000512"; Cancel (secondary, confirms discard if dirty);
  "Save Employee" (primary, `payroll.employee.create`/`.update`).
- **Form Body** — four `Card` sections: **Personal Information** (bilingual name fields, `date_of_birth`,
  `gender`, `nationality`, `marital_status`, photo upload), **Identity & Documents** (national ID + expiry,
  passport + country + expiry — all plaintext-in-form-only, encrypted server-side on submit, never logged
  client-side), **Employment** (`department_id`, `branch_id`, `manager_id` via `ManagerPicker`,
  `position_id` via `PositionPicker`, `employment_type`, `hire_date`, `work_schedule_id`), and **Starting
  Compensation** (create mode only — a base-salary amount and currency that the form submits as the
  employee's first `employee_salary_components` row via
  `POST /payroll/employees/{id}/salary-components` immediately after the employee itself is created,
  mirroring `docs/accounting/PAYROLL.md → Employee Lifecycle → Recruitment`'s "seed the new hire's initial
  compensation package before their first day" flow — gated by `payroll.salary.manage` in addition to
  `payroll.employee.create`; a caller holding only the latter sees the section replaced with a "Base salary
  can be added once created" notice rather than a disabled input it cannot use anyway).
- **Side Rail** (3–4/12, collapses above the sticky footer below `xl:`) — the **Payroll-Ready Checklist**
  (`PayrollReadinessChecklist`, `# Components Used`) updating live as required fields are filled, and a
  **Duplicate check** card mirroring `docs/frontend/VENDORS.md → Duplicate Detection`'s hard-block pattern:
  `docs/accounting/PAYROLL.md → Edge Cases`'s `national_id_hash` uniqueness constraint means a create
  attempt with a national ID matching an existing employee returns `409` with a
  `employee.duplicate_national_id` error code, which `EmployeeForm`'s submit handler catches and renders as
  a blocking `AlertDialog` naming the existing employee — never a bare field-level validation error, because
  the resolution is "go look at the existing record," not "type a different number."
- **Sticky Footer Action Bar** — duplicates Cancel/Save once the header scrolls out of view, with the
  header's own buttons `aria-hidden` while it is visible (`LAYOUT_SYSTEM.md`'s single-live-focus-target
  rule).

Edit mode omits **Starting Compensation** entirely (compensation changes after creation flow exclusively
through the Compensation tab's effective-dated components, never through a blunt "edit the number" field
on the core form — `docs/accounting/PAYROLL.md → Payroll Components` is explicit that "the `employees`
table itself never stores a raw salary figure"), and the Identity & Documents section's National ID field
renders read-only once `payroll_ready = true` unless the caller also holds `payroll.pii.view` — a
correction to an already-active employee's national ID is rare enough, and consequential enough for
statutory filings, that this document intentionally does not make it a casual inline edit.

## Profile — `[employeeId]/page.tsx`

```text
┌───────────────────────────────────────────────────────────────────────────────────────┐
│  ← Employees                                                                            │
│  EMP-000512 · Fahad Al-Mutairi  ●Active                    [Edit] [+ Salary] [⋯ Actions]│
│  فهد المطيري · Finance · Senior Accountant · Reports to: Dana Al-Rashidi                 │
├───────────────────────────────────────────────────────────────────────────────────────┤
│  Overview  Employment & Contracts  Compensation  Bank & Payment                         │
│  Documents  Emergency Contacts  Attendance & Leave  Audit Log      [sub-nav, scroll]    │
├────────────────────────────────────────────────────┬────────────────────────────────────┤
│  ┌ Identity ────────────┐ ┌ Employment summary ────┐│ Employment status                 │
│  │ Nationality  Kuwaiti  │ │ Type      Full-time    ││ ○ Applicant ○ Hired ○ Onboarding   │
│  │ National ID  ***-1092 │ │ Hire date 2023-02-11   ││ ● Active                           │
│  │ Passport     ***-4471 │ │ Branch    Kuwait City  ││                                    │
│  └────────────────────────┘ └──────────────────────┘│ Payroll-ready       ✓ Yes           │
│  ┌ AI · Compliance ──────────────────────────────────┐│ Base salary   750.000 KWD          │
│  │  Passport expires in 24 days — flag for renewal   ││ Next payslip  Jul 31 (predicted)   │
│  │  before the Aug payroll run.        [Acknowledge] ││                                    │
│  └────────────────────────────────────────────────────┘ Tags: senior · finance-team        │
└───────────────────────────────────────────────────────────────────────────────────────┘
```

| Region | Contents | Streaming boundary |
|---|---|---|
| Breadcrumb / back link | `← Employees` (`NAVIGATION_SYSTEM.md → Breadcrumbs & Page Headers`) | Part of the page shell |
| Profile header | Employee code, bilingual name, `StatusPill domain="employee"`, department/position/manager line, "Edit" (`payroll.employee.update`, omitted once terminated/archived), "+ Salary" (`payroll.salary.manage`), `⋯ Actions` `DropdownMenu` (Terminate / Rehire / Archive / Unarchive / Export employee file, each permission- and state-filtered) | Renders from the initial `GET /payroll/employees/{id}` fetch |
| Tab sub-nav | 8 tabs, horizontally scrollable at narrower widths, each a real `<Link>` with `?tab=` (deep-linkable) | Not streamed — part of the profile shell |
| Overview: Identity / Employment summary cards | Read-only display of core `employees` fields, masked per `payroll.pii.view` | Renders from the same initial fetch — no extra round trip |
| Overview: AI panel | Compliance/Fraud/data-completeness flags for this employee (`# AI Integration`) | Own `<Suspense>` boundary — embedded in the profile fetch's `ai_flags[]`, rendered in its own error boundary |
| Summary Rail | Employment-status `Stepper`, payroll-ready badge, base-salary `PayrollAmountCell`, Payment Prediction (next payslip date), tags — persists across every tab | Same initial fetch |
| Employment & Contracts tab | `EmployeeContractsList`, position/department/branch history timeline | Embedded in profile fetch; contract documents' signed URLs fetched on demand |
| Compensation tab | `SalaryComponentsTable` (effective-dated), "Loans & Advances" summary card | Own `<Suspense>` boundary — `GET /payroll/employees/{id}/salary-components`; gated wholesale on `payroll.salary.view` |
| Bank & Payment tab | `BankAccountsList` (masked IBAN), `preferred_payment_method`, WPS-eligibility flag | Embedded in profile fetch |
| Documents tab | `EmployeeDocumentsList` — polymorphic `attachments`, signed-URL preview | Own `<Suspense>` boundary — `GET /payroll/employees/{id}/documents` |
| Emergency Contacts tab | `EmergencyContactsList` | Embedded in profile fetch |
| Attendance & Leave tab | 30-day attendance summary, leave balance, deep links to `/payroll/attendance` and the not-yet-documented Leave screen | Own `<Suspense>` boundary — `GET /payroll/attendance?filter[employee_id]=` |
| Audit Log tab | `EmployeeAuditLogTable`, cursor-paginated | Own `<Suspense>` boundary — `GET /payroll/employees/{id}/audit-log` |

Exactly as `docs/frontend/VENDORS.md → Layout & Regions` establishes for its own multi-region profile,
every region here streams independently: a slow Compliance-agent recomputation never delays the Identity
card from painting, and a failure loading the Audit Log tab never affects the Overview tab a user most
likely opened first. The **Summary Rail persists across every tab** (unlike a tab-scoped rail) because an
employee's employment status, payroll-readiness, and base salary are relevant context regardless of which
facet — contracts, bank details, documents — the user is currently reading, the identical reasoning
`docs/frontend/CUSTOMERS.md → Layout & Regions` gives for its own persistent Summary Rail.

### Overview tab

Identity fields (bilingual name, date of birth, gender, nationality, marital status — read-only outside
Edit mode), the masked national ID and passport with an inline "Reveal" action for `payroll.pii.view`
holders only (revealing writes an `audit_logs` entry, per `docs/accounting/PAYROLL.md → Security →
Encryption`), the Employment summary card (type, hire date, branch, department, manager, position), `tags`
as removable chips, `custom_fields` rendered per their `custom_field_definitions` type, and the **AI
panel** surfacing this specific employee's open Compliance/Fraud flags (`# AI Integration`).

### Employment & Contracts tab

`employee_contracts` as a list (`contract_type`, `start_date`/`end_date`, `probation_end_date`,
`notice_period_days`, `renewal_status`, contract document preview), each row editable inline via a `Sheet`,
plus a read-only **position/branch history timeline** rendering `employee_position_history` and
`employee_assignment_history` entries (`docs/accounting/PAYROLL.md → Employee Lifecycle → Promotion` /
`→ Transfer`) as a reverse-chronological list — "Promoted to Senior Accountant, Grade 7 — 2024-01-01,
approved by Dana Al-Rashidi." A `limited` (fixed-term) contract within 60 days of `end_date` surfaces the
same renewal-reminder badge the backend's `notifications` fires, rendered here as a `warning`-toned
`Badge`, never an auto-action.

### Compensation tab

Gated wholesale on `payroll.salary.view` — a caller without it sees the tab itself still listed (so its
existence is not hidden information, matching `docs/frontend/VENDORS.md`'s "Audit Log tab" precedent of
omitting only where mere existence is itself sensitive, which base-salary existence is not) but its content
replaced by a "Requires payroll.salary.view" notice. For a caller who holds it: `SalaryComponentsTable`
lists every `employee_salary_components` row (component name bilingual, amount/rate, `effective_from`/
`effective_to`, `status` — `pending | active | superseded | cancelled`), each amount rendered through
`PayrollAmountCell`, with "Add salary component" (`payroll.salary.manage`) opening a `Sheet` that posts to
`POST /payroll/employees/{id}/salary-components` exactly as `docs/accounting/PAYROLL.md → API` documents
it. A **Loans & Advances** card summarizes `employee_loans.outstanding_balance` (read-only figure sourced
from a filtered `GET /payroll/loans?filter[employee_id]=` call — the natural read counterpart to the
`POST /payroll/loans` endpoint `PAYROLL.md → API` documents, not itself separately enumerated there but
implied by the same resource, flagged here as this document's own reasonable assumption rather than a
verbatim citation) with a "Manage in Loans" link to `/payroll/loans?employee_id=`, gated
`payroll.loan.create`/`.approve` per `NAVIGATION_SYSTEM.md`.

### Bank & Payment tab

`employee_bank_accounts` rows (bank name, masked IBAN — last 4 digits only, ever, regardless of
`payroll.pii.view`, matching the platform-wide IBAN-masking rule in `docs/accounting/PAYROLL.md → Security
→ Encryption` — SWIFT/BIC, branch code, `is_primary`), `preferred_payment_method`, and a WPS-eligibility
indicator (derived server-side from the company's WPS-exemption status and this employee's payment
method). Adding or changing a primary bank account opens a `Sheet` requiring the new IBAN to pass the
Kuwait IBAN checksum client-side (`FRONTEND_ARCHITECTURE.md` Principle 1's fast-fail convenience) before
`POST`ing — the server re-validates unconditionally.

### Documents tab

`attachments` (`attachable_type = 'Employee'`) — scanned IDs, contracts, certificates, offer/termination
letters — each with a signed, time-limited R2 preview URL minted fresh per request (never cached past its
window, per the identical pattern `docs/frontend/CUSTOMERS.md → Documents tab` establishes for
`customer_documents`). Documents expiring within 30/7/0 days show an inline warning/danger badge matching
the notification tiers `docs/accounting/PAYROLL.md → Notifications` already defines for
`national_id_expiry`/`passport_expiry`.

### Emergency Contacts tab

A simple `employee_emergency_contacts` list (`full_name`, `relationship`, `phone_number`, `is_primary`),
editable inline via `Sheet` — the lowest-stakes tab on this profile, included for completeness of the
employee-file model per `docs/accounting/PAYROLL.md → Employee Master Data → Emergency Contacts`.

### Attendance & Leave tab

A read-only 30-day attendance summary (on-time/late/absent day counts, total worked hours) and current
leave balances per `leave_types`, both sourced from filtered reads against the Attendance module's own
endpoints, with "View full attendance" and "Request leave" deep-linking to `/payroll/attendance` and the
not-yet-documented Leave screen respectively — this tab composes, it does not re-implement, exactly as the
Compensation tab's Loans card does for `/payroll/loans`.

### Audit Log tab

The `audit_logs` timeline scoped to this employee (`docs/accounting/PAYROLL.md → Security → Audit Logs`):
every mutation to payroll-relevant employee fields, salary components, and loans, with old/new value diffs,
actor, timestamp, and — where the actor was an AI agent rather than a human — the agent name and
confidence in place of a `user_id`, so the trail always distinguishes "a human decided this" from "AI
suggested this and a human decided."

# Components Used

A note on token naming before the table, restated from `docs/frontend/VENDORS.md → Components Used`
because it applies identically here: this document names design tokens the way the actual primitive and
finance-component implementations in `COMPONENT_LIBRARY.md` reference them (`ink-950`…`ink-0`,
`accent-700/600/500/100`, `success`/`warning`/`danger`/`info`/`accent`) rather than `DESIGN_LANGUAGE.md`'s
newer, differently-numbered `ink-1`…`ink-12`/`accent`/`accent-subtle` block. Reconciling the platform's two
token vocabularies is `COMPONENT_LIBRARY.md` and `DESIGN_LANGUAGE.md`'s own job, not this document's.

| Component | Source | Role on this screen pair |
|---|---|---|
| `DataTable` | `components/shared/data-table.tsx` | Underlies `EmployeesTable` |
| `KpiTile` | `components/dashboard/kpi-tile.tsx` | List's KPI band; Active / Onboarding / Payroll-Ready-Failing / Documents-Expiring |
| `StatusPill` | `components/shared/status-pill.tsx`, domain `employee` (**new**, owned by this document) | Employment status everywhere it appears |
| `AmountCell` | `components/accounting/amount-cell.tsx` | Loan balances, Payroll Cost figures this screen deep-links to |
| `CurrencyTag` | `components/accounting/currency-tag.tsx` | Salary component currency, bank account currency |
| `ConfidenceBadge` | `components/ai/confidence-badge.tsx` | Composed inside AI flag cards and the Payment Prediction figure |
| `AIProposalPanel` | `components/ai/ai-proposal-panel.tsx` | Fraud/Compliance/data-completeness flags on the List banner and Profile Overview |
| `ApprovalCard` | `components/shared/approval-card.tsx` | Not used for a multi-stage workflow (Employees has none — `# Interactions & Flows`), but composed once for the rare case a company enables loan pre-approval on this screen, `kind="payroll_loan"` |
| `Tabs` | `components/ui/tabs.tsx` | The 8-tab Profile sub-nav |
| `Sheet` | `components/ui/sheet.tsx` | Contract/bank-account/emergency-contact inline edit; salary-component add form; mobile row-detail drawer |
| `Dialog` / `AlertDialog` | `components/ui/dialog.tsx`, `components/ui/alert-dialog.tsx` | Terminate/rehire/archive reason capture, duplicate-national-ID hard block, discard-draft confirmation |
| `DropdownMenu` | `components/ui/dropdown-menu.tsx` | Row actions in the List; the Profile header's `⋯ Actions` menu |
| `PermissionGate` | `components/shared/permission-gate.tsx` | Wraps every mutating affordance named in `# Route & Access` |
| `Badge` | `components/ui/badge.tsx` | Tags, employment-type label, expiry warnings — plain informational, distinct from `StatusPill`'s stateful use |
| `Stepper` | `components/shared/stepper.tsx` | Employment-status progression on the Summary Rail |
| `Skeleton` | `components/ui/skeleton.tsx` | Per-region loading placeholders |
| `EmptyState` / `ErrorState` | `components/shared/empty-state.tsx`, `components/shared/error-state.tsx` | Zero-employee onboarding, filtered-empty table, per-region fetch failure |
| Toast (`useApiToast`) | `hooks/use-api-toast.ts` | Every mutation's success/error surfacing, mapped from the API envelope |
| `EmployeesTable` | **New** — `components/payroll/employees-table.tsx` | The List region; a thin preset wrapper over `DataTable` |
| `EmployeeForm` | **New** — `components/payroll/employee-form.tsx` | Create (`new/page.tsx`) and edit (profile `Sheet`) |
| `PayrollAmountCell` | **New** — `components/payroll/payroll-amount-cell.tsx` | Every salary/compensation figure — an additive extension of `AmountCell`, see below |
| `PayrollReadinessChecklist` | **New** — `components/payroll/payroll-readiness-checklist.tsx` | Create form's side rail and the Profile's Overview summary card |
| `SalaryComponentsTable` | **New** — `components/payroll/salary-components-table.tsx` | Compensation tab's effective-dated component list |
| `EmployeeContractsList` / `EmployeeBankAccountsList` / `EmergencyContactsList` / `EmployeeDocumentsList` | **New** — `components/payroll/employee-*-list.tsx` | Child-collection managers, each with its own add/edit `Sheet` |
| `EmployeeLifecycleActionDialog` | **New** — `components/payroll/employee-lifecycle-action-dialog.tsx` | Shared reason(+category) capture for terminate/rehire/archive/unarchive |
| `EmployeeAuditLogTable` | **New** — `components/payroll/employee-audit-log-table.tsx` | Audit Log tab, a `DataTable` read preset |
| `DepartmentPicker` / `BranchPicker` / `ManagerPicker` / `PositionPicker` | **New** — `components/payroll/*-picker.tsx` | `EmployeeForm`'s reference-data fields, each following `AccountPicker`'s exact async-search combobox pattern (`COMPONENT_LIBRARY.md → AccountPicker`) over its own owning module's lookup endpoint |

## `EmployeesTable`

A thin preset over `DataTable`, following `VendorsTable`'s established shape exactly
(`docs/frontend/VENDORS.md → Components Used → VendorsTable`) — this screen never hand-rolls sorting,
pagination, or filtering.

```tsx
// components/payroll/employees-table.tsx
'use client';

import { DataTable } from '@/components/shared/data-table';
import { StatusPill } from '@/components/shared/status-pill';
import { Badge } from '@/components/ui/badge';
import { CheckCircle2, XCircle } from 'lucide-react';
import type { ColumnDef } from '@tanstack/react-table';
import type { Employee } from '@/types/payroll';
import { Eye, Pencil, UserX, UserCheck, Archive, ArchiveRestore } from 'lucide-react';
import { useRouter } from 'next/navigation';

interface EmployeesTableProps {
  defaultFilters?: Record<string, unknown>;
}

export function EmployeesTable({ defaultFilters }: EmployeesTableProps) {
  const router = useRouter();

  const columns: ColumnDef<Employee>[] = [
    { accessorKey: 'employee_code', header: 'Code', cell: ({ row }) => <span className="font-mono text-xs" dir="ltr">{row.original.employee_code}</span> },
    {
      accessorKey: 'display_name', header: 'Name',
      cell: ({ row }) => (
        <div className="min-w-0">
          <p className="truncate font-medium text-ink-950">{row.original.first_name_en} {row.original.last_name_en}</p>
          {row.original.first_name_ar && (
            <span className="text-caption text-ink-500" dir="rtl">{row.original.first_name_ar} {row.original.last_name_ar}</span>
          )}
        </div>
      ),
    },
    { accessorKey: 'department_name', header: 'Department' },
    { accessorKey: 'employment_status', header: 'Status', cell: ({ row }) => <StatusPill domain="employee" status={row.original.employment_status} /> },
    { accessorKey: 'employment_type', header: 'Type', cell: ({ row }) => <Badge tone="neutral" className="capitalize">{row.original.employment_type.replace('_', ' ')}</Badge> },
    {
      accessorKey: 'payroll_ready', header: 'Ready',
      cell: ({ row }) => row.original.payroll_ready
        ? <CheckCircle2 className="h-4 w-4 text-success" aria-label="Payroll ready" />
        : <XCircle className="h-4 w-4 text-ink-400" aria-label="Not payroll ready" />,
    },
  ];

  return (
    <DataTable
      columns={columns}
      resource="payroll/employees"
      paginationMode="page"
      defaultSort="employee_code"
      searchable
      defaultFilters={{ 'filter[employment_status][not_in]': 'archived', ...defaultFilters }}
      onRowClick={(row) => router.push(`/payroll/employees/${row.id}`)}
      getRowId={(row) => String(row.id)}
      emptyState={{ title: 'No employees yet', description: 'Onboard your first employee to start running payroll.' }}
      rowActions={(row) => [
        { label: 'View', icon: Eye, onClick: () => router.push(`/payroll/employees/${row.id}`) },
        { label: 'Edit', icon: Pencil, permission: 'payroll.employee.update', hidden: row.employment_status === 'terminated' || row.is_archived, onClick: () => router.push(`/payroll/employees/${row.id}?edit=1`) },
        { label: 'Terminate', icon: UserX, permission: 'payroll.employee.terminate', hidden: row.employment_status === 'terminated' || row.is_archived, onClick: () => openLifecycleDialog(row.id, 'terminate') },
        { label: 'Rehire', icon: UserCheck, permission: 'payroll.employee.rehire', hidden: row.employment_status !== 'terminated', onClick: () => openLifecycleDialog(row.id, 'rehire') },
        { label: 'Archive', icon: Archive, permission: 'payroll.employee.archive', hidden: row.is_archived || row.employment_status !== 'terminated', onClick: () => openLifecycleDialog(row.id, 'archive') },
        { label: 'Unarchive', icon: ArchiveRestore, permission: 'payroll.employee.archive', hidden: !row.is_archived, onClick: () => openLifecycleDialog(row.id, 'unarchive') },
      ]}
    />
  );
}
```

`StatusPill` gains an `employee` domain lookup table, owned by this document:

```tsx
// components/shared/status-pill.tsx — additions owned by this document
const EMPLOYEE_STATUS: Record<string, { label: string; tone: Tone }> = {
  applicant:   { label: 'Applicant',   tone: 'neutral' },
  hired:       { label: 'Hired',       tone: 'neutral' },
  onboarding:  { label: 'Onboarding',  tone: 'accent'  },
  active:      { label: 'Active',      tone: 'success' },
  on_leave:    { label: 'On leave',    tone: 'warning' },
  terminated:  { label: 'Terminated',  tone: 'neutral' },
  archived:    { label: 'Archived',    tone: 'neutral' },
};

Object.assign(STATUS_TABLES, { employee: EMPLOYEE_STATUS });
```

The tone choices are deliberate, mirroring the reasoning `docs/frontend/CUSTOMERS.md → Extending
StatusPill` gives for its own domain: `active` earns `success` as the fully-realized "good" steady state,
matching `posted`/`customer`'s own use of that tone elsewhere in the platform. `onboarding` gets `accent` —
an in-progress task awaiting completion, not a problem — the same tone Vendors reserves for `approved`
mid-workflow. `on_leave` gets `warning`, not because leave is a bad thing, but because it is a temporary
deviation from normal pay treatment a Payroll Officer needs to notice before a run, exactly the same
"needs awareness, not alarm" reasoning `AgingBar`'s `31-60` bucket carries. Critically, `terminated` gets
the same quiet `neutral` tone as `archived` and `hired`, **not** `danger` — most terminations are ordinary
resignations or end-of-contract events, not disciplinary ones (`docs/accounting/PAYROLL.md → Employee
Lifecycle → Termination` lists `resignation | dismissal | end-of-contract | retirement | death` as
undifferentiated `termination_reason` values), and coloring every departed employee's status pill the same
alarming red a `blacklisted` vendor gets would misrepresent the overwhelming majority of cases. A row
requiring genuine attention (a dismissal, a compliance issue) is instead surfaced through the AI Compliance
panel and the audit trail, never through a status color doing double duty as a value judgment.

## `PayrollAmountCell`

The single component responsible for salary-privacy masking (`# Route & Access`'s `payroll.salary.view`
row). Deliberately a thin wrapper composing `AmountCell` rather than a parallel implementation — every
formatting rule (`NUMERIC(19,4)` precision, `CurrencyTag`, RTL-safe `dir="ltr"` numerals) still comes from
`AmountCell` itself; this component only decides *whether* to render it at all.

**Props**

| Prop | Type | Required | Description |
|---|---|---|---|
| `amount` | `string \| null` | yes | The raw `NUMERIC(19,4)` string, or `null` when the caller's own request was already denied server-side. |
| `currencyCode` | `string` | yes | ISO 4217, `KWD` by default per company base currency. |
| `visible` | `boolean` | yes | Resolved from `usePermission('payroll.salary.view')` at the call site — this component does not call the permission hook itself, so it renders identically in a Storybook fixture and in the live app. |
| `emphasis` | `'default' \| 'strong'` | no | Forwarded to `AmountCell`. |

```tsx
// components/payroll/payroll-amount-cell.tsx
import { AmountCell } from '@/components/accounting/amount-cell';
import { Lock } from 'lucide-react';

interface PayrollAmountCellProps {
  amount: string | null;
  currencyCode: string;
  visible: boolean;
  emphasis?: 'default' | 'strong';
}

export function PayrollAmountCell({ amount, currencyCode, visible, emphasis = 'default' }: PayrollAmountCellProps) {
  if (!visible || amount == null) {
    return (
      <span className="inline-flex items-center gap-1.5 text-caption text-ink-500">
        <Lock className="h-3.5 w-3.5" aria-hidden />
        Restricted
      </span>
    );
  }
  return <AmountCell amount={amount} currencyCode={currencyCode} emphasis={emphasis} />;
}
```

The restricted state is never a blank cell and never `0.000` — both would be misread (a blank as "no
data," a zero as "unpaid"). It is always the same lock glyph plus the literal word "Restricted," matching
`RiskScoreBadge`'s identical "Insufficient history" convention of always rendering *something* legible
rather than an empty space a user could mistake for missing data (`docs/frontend/CUSTOMERS.md →
RiskScoreBadge`). The API itself never sends the raw amount to a caller lacking `payroll.salary.view` in
the first place — `visible` here reflects what the payload actually contains, it does not hide a value the
client already received, which would be a client-side-only control and therefore not a control at all.

## `PayrollReadinessChecklist`

Renders the three-item payroll-readiness gate (`docs/accounting/PAYROLL.md → Employee Lifecycle →
Onboarding`: national ID, a linked bank account, and a validated base-salary component) as a live checklist,
each item linking to the exact form section or tab that resolves it.

```tsx
// components/payroll/payroll-readiness-checklist.tsx
import { CheckCircle2, Circle } from 'lucide-react';
import Link from 'next/link';

interface ReadinessItem { key: string; label: string; met: boolean; href?: string }

export function PayrollReadinessChecklist({ items }: { items: ReadinessItem[] }) {
  const allMet = items.every((i) => i.met);
  return (
    <div className="space-y-2 rounded-lg border border-ink-150 p-4">
      <p className="text-caption font-medium text-ink-700">
        {allMet ? 'Payroll ready' : `${items.filter((i) => !i.met).length} item(s) remaining`}
      </p>
      <ul className="space-y-1.5">
        {items.map((item) => (
          <li key={item.key} className="flex items-center gap-2 text-body-sm">
            {item.met
              ? <CheckCircle2 className="h-4 w-4 shrink-0 text-success" aria-hidden />
              : <Circle className="h-4 w-4 shrink-0 text-ink-400" aria-hidden />}
            {item.href && !item.met ? (
              <Link href={item.href} className="text-accent-600 underline-offset-2 hover:underline">{item.label}</Link>
            ) : (
              <span className={item.met ? 'text-ink-700' : 'text-ink-500'}>{item.label}</span>
            )}
          </li>
        ))}
      </ul>
    </div>
  );
}
```

This mirrors `payroll_ready` being "surfaced to the frontend as a checklist, not a hard block on saving
partial data" (`docs/accounting/PAYROLL.md → Employee Lifecycle → Onboarding`) — `EmployeeForm` lets an HR
Manager save a partially-complete new hire and finish the checklist over the following days; the gate only
blocks *inclusion in a payroll run*, which is Payroll Runs' concern, not this screen's.

## `EmployeeLifecycleActionDialog`

One shared component for terminate/rehire/archive/unarchive, mirroring `docs/frontend/VENDORS.md →
VendorLifecycleActionDialog`'s identical reason-gated pattern rather than four near-identical dialogs.

**Props**

| Prop | Type | Required | Description |
|---|---|---|---|
| `action` | `'terminate' \| 'rehire' \| 'archive' \| 'unarchive'` | yes | Determines copy, required fields, and minimum reason length. |
| `employeeId` | `number` | yes | |
| `onConfirm` | `(payload: { termination_date?: string; termination_reason?: string; reason?: string }) => Promise<void>` | yes | `termination_date` + `termination_reason` (enum: `resignation \| dismissal \| end_of_contract \| retirement \| death`) required for `action==='terminate'`; a free-text `reason` (≥10 characters) required for archive/unarchive. |

```tsx
// components/payroll/employee-lifecycle-action-dialog.tsx (excerpt — the terminate branch)
{action === 'terminate' && (
  <div className="space-y-3">
    <div className="rounded-md bg-warning/10 border border-warning/30 p-3 text-caption text-ink-950">
      Terminating this employee generates a <strong>final settlement</strong> payroll run covering
      unpaid pro-rated salary, accrued leave payout, and end-of-service indemnity
      (docs/accounting/PAYROLL.md → Employee Lifecycle → Termination). This does not itself disburse
      any payment — Payroll will review and approve the settlement run separately.
    </div>
    <Select name="termination_reason" required>
      <SelectItem value="resignation">Resignation</SelectItem>
      <SelectItem value="dismissal">Dismissal</SelectItem>
      <SelectItem value="end_of_contract">End of contract</SelectItem>
      <SelectItem value="retirement">Retirement</SelectItem>
      <SelectItem value="death">Death</SelectItem>
    </Select>
    <DatePicker name="termination_date" required max={new Date()} />
  </div>
)}
```

A `rehire` action on a `terminated` employee additionally renders a one-line, non-blocking notice — "This
restores EMP-000512 to active status and preserves their full salary, loan, and payslip history under the
same employee ID" — mirroring `docs/accounting/PAYROLL.md → Employee Lifecycle → Rehire`'s explicit "QAYD
never creates a duplicate `employees` row for the same national ID" rule; there is no destructive
confirmation here because rehire is additive, never data-destroying.

# Data & State

## TanStack Query hooks and cache keys

```ts
// lib/queries/payroll-employees.ts
export const employeeKeys = {
  all:        ['payroll', 'employees'] as const,
  lists:      () => [...employeeKeys.all, 'list'] as const,
  list:       (filters: EmployeeFilters) => [...employeeKeys.lists(), filters] as const,
  stats:      () => [...employeeKeys.all, 'stats'] as const,
  aiFlags:    () => [...employeeKeys.all, 'ai-flags'] as const,
  details:    () => [...employeeKeys.all, 'detail'] as const,
  detail:     (id: number | string) => [...employeeKeys.details(), id] as const,
  salary:     (id: number | string) => [...employeeKeys.detail(id), 'salary-components'] as const,
  payslips:   (id: number | string) => [...employeeKeys.detail(id), 'payslips'] as const,
  documents:  (id: number | string) => [...employeeKeys.detail(id), 'documents'] as const,
  auditLog:   (id: number | string) => [...employeeKeys.detail(id), 'audit-log'] as const,
  loans:      (id: number | string) => [...employeeKeys.detail(id), 'loans'] as const,
  attendance: (id: number | string) => [...employeeKeys.detail(id), 'attendance'] as const,
};

export function useEmployees(filters: EmployeeFilters) {
  return useQuery({
    queryKey: employeeKeys.list(filters),
    queryFn: () => apiClient.get('/payroll/employees', { params: filters }),
    placeholderData: keepPreviousData, // page N+1 never flashes a blank table while fetching
  });
}

export function useEmployee(id: string) {
  return useQuery({
    queryKey: employeeKeys.detail(id),
    queryFn: () => apiClient.get(`/payroll/employees/${id}`),
  });
}

export function useCreateEmployee() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (payload: EmployeeFormValues) => apiClient.post('/payroll/employees', payload),
    onSuccess: () => qc.invalidateQueries({ queryKey: employeeKeys.lists() }),
  });
}

export function useEmployeeLifecycleAction(id: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (payload: { action: 'terminate' | 'rehire' | 'archive' | 'unarchive'; body: Record<string, unknown> }) =>
      apiClient.post(`/payroll/employees/${id}/${payload.action}`, payload.body),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: employeeKeys.detail(id) });
      qc.invalidateQueries({ queryKey: employeeKeys.lists() });
      qc.invalidateQueries({ queryKey: employeeKeys.stats() });
    },
  });
}
```

No mutation on this screen is optimistic. Unlike a Journal Entry draft or a Customer tag edit, every
mutation here either touches PII, touches money (a salary component), or touches an irreversible lifecycle
transition (terminate) — `FRONTEND_ARCHITECTURE.md`'s optimistic-update guidance is explicitly scoped to
low-consequence, easily-reversible edits, and none of this screen's writes qualify. Every mutation shows a
pending state on its own trigger control and commits the cache update only after the server responds.

## Endpoints this screen consumes

| Method | Path | Permission | Description | Status |
|---|---|---|---|---|
| GET | `/api/v1/payroll/employees` | `payroll.employee.read` | Paginated, filterable, searchable roster | This document's addition — the base list endpoint the screen requires; not separately enumerated in `docs/accounting/PAYROLL.md`'s own endpoint table, which focuses on salary/run/payslip actions |
| POST | `/api/v1/payroll/employees` | `payroll.employee.create` | Create an employee (`applicant`/`hired` per submitted `hire_date`) | This document's addition |
| GET | `/api/v1/payroll/employees/{id}` | `payroll.employee.read` | Full profile fetch, PII/salary fields present only if the caller's grants include them | This document's addition |
| PATCH | `/api/v1/payroll/employees/{id}` | `payroll.employee.update` | Update core/identity/employment fields | This document's addition |
| POST | `/api/v1/payroll/employees/{id}/activate` | `payroll.employee.update` | `onboarding → active`, enforced server-side only once `payroll_ready = true` | This document's addition |
| POST | `/api/v1/payroll/employees/{id}/terminate` | `payroll.employee.terminate` | `active/on_leave → terminated`; triggers final-settlement run creation | This document's addition |
| POST | `/api/v1/payroll/employees/{id}/rehire` | `payroll.employee.rehire` | `terminated → active`, same `employee_id` | This document's addition |
| POST | `/api/v1/payroll/employees/{id}/archive` \| `/unarchive` | `payroll.employee.archive` | Removes/restores from active pickers and default list view | This document's addition |
| GET / POST | `/api/v1/payroll/employees/{id}/salary-components` | `payroll.salary.view` / `.manage` | Verbatim from `docs/accounting/PAYROLL.md → API` | Backend-documented |
| GET | `/api/v1/payroll/employees/{id}/payslips` | `payroll.payslip.view.self` OR `.view` | Verbatim from `docs/accounting/PAYROLL.md → API` | Backend-documented |
| GET | `/api/v1/payroll/employees/{id}/documents` | `payroll.pii.view` for identity documents, `payroll.employee.read` for others | Convenience wrapper over the polymorphic `attachments` resource scoped to `attachable_type=Employee` | This document's addition, thinly wraps an existing backend service |
| GET | `/api/v1/payroll/employees/{id}/audit-log` | `payroll.employee.read` (own fields only) or broader per role | Cursor-paginated `audit_logs` filtered to this employee | This document's addition |
| GET | `/api/v1/payroll/loans?filter[employee_id]=` | `payroll.loan.create` OR `.approve` | Read-only loan summary for the Compensation tab's card | Implied sibling of `POST /payroll/loans` (`docs/accounting/PAYROLL.md → API`), not itself enumerated there |
| GET | `/api/v1/payroll/attendance?filter[employee_id]=` | `attendance.read` | Attendance & Leave tab's 30-day summary | Verbatim resource from `docs/accounting/PAYROLL.md → API`'s `GET /payroll/attendance`, filtered |
| GET | `/api/v1/payroll/employees/stats` | `payroll.employee.read` | KPI band figures (headcount by status, payroll-ready failures, expiring documents) | This document's addition, mirrors `docs/frontend/VENDORS.md`'s `/purchasing/vendors/stats` convenience precedent |
| GET | `/api/v1/payroll/employees/ai-flags` | `payroll.employee.read` (flags themselves respect `ai.payroll.override` for actions) | Fraud/Compliance/data-completeness flag queue | This document's addition, mirrors Vendors' `duplicate-candidates` queue precedent |

## Example — List response (excerpt)

```json
{
  "success": true,
  "data": [
    {
      "id": 512, "employee_code": "EMP-000512",
      "first_name_en": "Fahad", "last_name_en": "Al-Mutairi",
      "first_name_ar": "فهد", "last_name_ar": "المطيري",
      "department_id": 4, "department_name": "Finance",
      "employment_type": "full_time", "employment_status": "active",
      "payroll_ready": true, "is_archived": false
    }
  ],
  "message": "Employees retrieved.",
  "errors": [],
  "meta": { "pagination": { "page": 1, "per_page": 25, "total": 214, "cursor": null } },
  "request_id": "8b1e2c4a-3f9d-4e21-9a10-8b5c7d2e1f11",
  "timestamp": "2026-07-18T06:00:00Z"
}
```

## Example — Profile fetch, salary masked for a caller without `payroll.salary.view`

```json
{
  "success": true,
  "data": {
    "id": 512, "employee_code": "EMP-000512",
    "first_name_en": "Fahad", "last_name_en": "Al-Mutairi",
    "national_id_masked": "***-***-1092", "passport_masked": "***-4471",
    "department_id": 4, "position_id": 31, "manager_id": 88,
    "employment_type": "full_time", "employment_status": "active",
    "hire_date": "2023-02-11", "payroll_ready": true,
    "base_salary_amount": null,
    "base_salary_visible": false,
    "ai_flags": [
      { "type": "compliance", "severity": "warning",
        "message": "Passport expires in 24 days.", "confidence": 0.99 }
    ]
  },
  "message": "Employee retrieved.",
  "errors": [],
  "meta": { "pagination": null },
  "request_id": "1f9a3d7e-6b2c-4a11-9d0e-77a8b1c5d9f3",
  "timestamp": "2026-07-18T06:02:11Z"
}
```

`base_salary_amount: null` paired with `base_salary_visible: false` is the exact contract
`PayrollAmountCell` (`# Components Used`) expects — the API distinguishes "no value" from "you may not see
the value" explicitly rather than overloading `null` for both, so the frontend never has to guess which
case it is in.

## Example — Terminate action, validation error (422)

```json
{
  "success": false,
  "data": null,
  "message": "Validation failed.",
  "errors": [
    { "field": "termination_date", "code": "before_hire_date",
      "message": "termination_date must not be earlier than hire_date." }
  ],
  "meta": { "pagination": null },
  "request_id": "d02f6c1a-9e3b-4a7d-8c11-5b0f2e9a7d44",
  "timestamp": "2026-07-18T06:05:40Z"
}
```
Mirrors `docs/accounting/PAYROLL.md → Database Design`'s own `chk_employees_dates` constraint
(`termination_date IS NULL OR termination_date >= hire_date`) — `EmployeeLifecycleActionDialog`'s
`DatePicker` applies the identical floor client-side as a fast-fail convenience; the server re-validates
unconditionally.

## Realtime

The Profile subscribes to the company's existing Payroll Reverb channel, `private-payroll.{company_id}`
(`docs/accounting/PAYROLL.md → Notifications`'s own channel, reused here rather than a second one), filtered
client-side to events naming this `employee_id`: `employee.updated`, `employee.terminated`,
`employee.ai_flag.created`. A `Compliance` flag arriving while a Finance Manager is already looking at the
Overview tab appears without a page reload — the AI panel re-renders from the pushed event's payload merged
into the existing TanStack Query cache entry via `queryClient.setQueryData`, not a full refetch. The List
does not subscribe per-row (214 employees would mean 214 channel filters); instead it invalidates its
current page's query on any `employee.*` event bearing a `company_id` match, which is cheap because the
List query is already cached and a background refetch is invisible unless the visible page's data actually
changed (`keepPreviousData` keeps the old rows on screen throughout).

# Interactions & Flows

**Onboard a new employee.** HR Manager clicks "New employee" → `EmployeeForm` (create) → fills Personal
Information, Identity & Documents, Employment, optionally Starting Compensation → the `PayrollReadinessChecklist`
in the side rail updates on every keystroke against the same three fields the server's gate checks → on
save, `POST /payroll/employees` runs the duplicate-national-ID check server-side; a `409` renders the
blocking `AlertDialog` naming the existing match, otherwise the new `employees` row lands in `applicant` or
`hired` (based on whether `hire_date` is in the future or today/past) and the user is redirected to the new
Profile. If Starting Compensation was filled, a second call — `POST .../salary-components` — fires
immediately after, and its own failure (e.g., a malformed formula) surfaces as a toast without rolling back
the employee record itself, since the two are genuinely separate resources.

**Complete onboarding.** Over the following days, HR revisits the Profile (still `onboarding`), uploads
the missing national ID scan via the Documents tab, links a bank account via Bank & Payment — each
checklist item flips as its underlying field is saved. Once all three are met, `payroll_ready` flips to
`true` server-side (never client-computed) and an "Activate" action appears, calling
`POST .../activate`, which the server accepts only if `payroll_ready = true`, moving `employment_status` to
`active`.

**Promote or transfer.** Opened from the Employment & Contracts tab's "Record a change" action (`Sheet`),
this captures a new `position_id`/`department_id`/`branch_id`/`grade` effective on a chosen future-or-today
date and a new base-salary amount where applicable — submitted as a combination of an
`employee_position_history`/`employee_assignment_history` row and a new effective-dated
`employee_salary_components` row, per `docs/accounting/PAYROLL.md → Employee Lifecycle → Promotion`/
`→ Transfer`. The prior salary component is never edited in place; the new row's `effective_from` and the
old row's `effective_to` are set atomically server-side.

**Terminate.** `⋯ Actions → Terminate` opens `EmployeeLifecycleActionDialog` (`action="terminate"`) with
the final-settlement notice, a required `termination_reason`, and a `termination_date` no earlier than
`hire_date`. On confirm, `employment_status` moves to `terminated`, `is_archived` remains `false` (archiving
is a separate, later, optional step), and — per `docs/accounting/PAYROLL.md → Employee Lifecycle →
Termination` — a `payroll_runs.run_type = 'final_settlement'` draft run is created for Payroll to review on
its own screen; this screen shows a one-line confirmation toast linking to that run, it does not embed the
settlement calculation itself.

**Rehire.** `⋯ Actions → Rehire` on a `terminated` employee opens the same dialog (`action="rehire"`),
requiring only a new `hire_date`; on confirm, `employment_status` returns to `active` (bypassing
`onboarding`, since the payroll-readiness fields already exist from the prior tenure) under the identical
`employee_id`.

**Archive / Unarchive.** Available only once `terminated`, purely a visibility toggle
(`docs/accounting/PAYROLL.md → Employee Lifecycle → Archive`) — no history is touched, and the action is
reversible at will, unlike terminate.

**Search and filter.** The List's search matches `employee_code`, `first_name_en`/`last_name_en`,
`first_name_ar`/`last_name_ar` server-side (`FRONTEND_ARCHITECTURE.md`'s standard debounced-search
pattern, 300ms). Department/Branch/Status/Type filters compose into `filter[...]` query parameters exactly
per `docs/api/API_FILTERING_SORTING.md`'s grammar, identical to every other `DataTable`-backed screen.

# AI Integration

Every agent below is delivered by the FastAPI AI layer per the platform-wide contract restated in
`docs/accounting/PAYROLL.md → AI Responsibilities`: the AI engine never writes to the database, every
output is a proposal object carrying `confidence` (0.00–1.00), `reasoning`, and `supporting_references`,
and every proposal respects the RBAC the human viewer would need to act on it. This screen surfaces four
of the agents that table already defines, scoped to the employee master-data lens rather than the payroll
run itself:

| Agent | Inputs on this screen | Outputs | Autonomy | Confidence handling |
|---|---|---|---|---|
| Fraud Detection | `employees`, `employee_bank_accounts`, attendance presence, HR case records | Ghost-employee flag (no attendance ever, no self-service login), duplicate IBAN across ≥2 active employees, loan issued then immediate resignation | Suggest-only; ≥0.9 confidence flags additionally notify the Auditor role independent of this screen (`docs/accounting/PAYROLL.md → AI Responsibilities`) | Displayed with `ConfidenceBadge`; a critical flag blocks nothing on this screen (it is not a workflow gate) but persists until a `payroll.pii.view`/`ai.payroll.override` holder acknowledges it |
| Compliance Checks | `national_id_expiry`, `passport_expiry`, active contract terms, garnishment deductions on linked loans | Expiring-document flags, garnishment-cap warnings, overtime-multiplier deviations surfaced from this employee's payroll history | Suggest-only | Reasoning always cites the specific rule or law article, per the identical convention `docs/accounting/PAYROLL.md` uses for payroll-run compliance flags |
| Data Completeness (part of the General Accountant / Payroll Validation agent) | The employee's own field completeness against the payroll-readiness gate and the broader employee-file model | "3 fields commonly present on similar employees are missing here" nudges (e.g., no emergency contact on file) | Suggest-only, purely advisory — never blocks saving, distinct from the hard payroll-readiness gate which is deterministic, not AI-driven | Low-priority, dismissible per-item |
| Payroll Explanation (Approval Assistant) | This employee's most recent `payroll_items`/`payroll_item_lines` | A plain-language, bilingual explanation of their last payslip, available on request from the Compensation tab | Auto-generate on request (read-only, no state change) | Always labeled "AI-generated explanation, verify against payslip" |

No agent above can create, edit, or transition an `employees` row, an `employee_salary_components` row, or
a lifecycle action — every one of them renders through `AIProposalPanel` (List banner, Profile Overview) or
a small inline `Badge`+`ConfidenceBadge` pairing (Documents tab expiry flags), and every "Dismiss"/
"Acknowledge" action is itself a distinct, audited API call (`POST /payroll/employees/{id}/ai-flags/{flagId}/dismiss`,
gated `ai.payroll.override`), never a client-side hide that forgets the flag existed. This mirrors
`docs/accounting/PAYROLL.md → Edge Cases`'s explicit rule that an overridden anomaly's `override_reason` is
retained permanently — the same discipline applies here even though Employees has no formal approval chain
of its own: an acknowledged Compliance flag is retained in `audit_logs`, not merely hidden from view.

```tsx
<AIProposalPanel
  decision={{
    id: flag.id,
    agentCode: 'FRAUD_DETECTION',
    confidenceScore: flag.confidence * 100,
    recommendedAction: 'Review the two employees sharing this IBAN before the next payroll run.',
    alternatives: [{ action: 'Dismiss — confirmed shared household account', tradeoff: 'Both employees remain payable to this account without further review.' }],
    reasoning: flag.reasoning,
    sources: [{ type: 'employees', id: flag.employee_a_id }, { type: 'employees', id: flag.employee_b_id }],
  }}
  autonomyLevel="suggest_only"
  onDismiss={(reason) => dismissFlag.mutateAsync({ id: flag.id, reason })}
/>
```

# States

| Surface | Loading | Empty | Error |
|---|---|---|---|
| List | `Skeleton` rows matching the table's exact column layout (never a generic spinner, per `DESIGN_LANGUAGE.md`'s "never blank" rule) | `EmptyState`: "No employees yet — Onboard your first employee to start running payroll," with the "New employee" CTA repeated inline (only when `payroll.employee.create` is held) | `ErrorState` with "Retry," scoped to the table region only — the KPI band and AI banner remain interactive |
| KPI band | Four `Skeleton` tiles | N/A (a company with zero employees still renders four `0` tiles, not an empty state — a count of zero is itself informative) | Each tile fails independently; a failed tile shows a small inline "—" with a retry icon, never blanking the other three |
| Profile | Full-page `Skeleton` matching the header + tab-nav + two-column body shape | N/A (a fetched employee always has at least its core fields) | `not-found.tsx` for a genuinely missing/cross-company id; a fetch failure after the id resolves renders `ErrorState` in place of the whole profile with "Retry" |
| Compensation tab | `Skeleton` rows | "No salary components yet" (only reachable transiently, between employee creation and the first component being added) | Region-scoped `ErrorState`; other tabs remain usable |
| Documents / Audit Log / Attendance tabs | `Skeleton` per region | "No documents on file yet" / "No activity yet" / "No attendance recorded for this period" | Region-scoped `ErrorState`, isolated per `<Suspense>` boundary — a failed Audit Log fetch never blanks Overview |
| Duplicate-national-ID check (create form) | Inline spinner beside the side-rail card while the debounced check runs | "No similar employee found — clear to save" | Treated as non-blocking if the check itself fails (network hiccup) — the form does not prevent submission on a failed *check*, only on a confirmed `409` from the real submit |

A `403` on this route (missing `payroll.employee.read`) renders the shared `error.tsx` boundary described
in `# Route & Access`, never a blank page and never the Next.js default. A `404` (cross-company id, or a
genuinely deleted employee) renders `not-found.tsx` with a link back to the List — indistinguishable, by
design, from the cross-tenant case, per the platform's standing 403-vs-404 rule.

# Responsive Behavior

`RESPONSIVE_DESIGN.md`'s five-tier breakpoint system governs both surfaces without a bespoke Employees
variant.

- **Desktop (`lg`, 1024px+)** — the layouts shown in `# Layout & Regions` verbatim: the List's full
  filter bar and KPI band in a 4-up row; the Profile's two-column (8/12 main, 4/12 Summary Rail) grid.
- **Tablet (`md`, 768–1023px)** — the KPI band collapses to a horizontally-scrollable strip (`overflow-x:
  auto`, snap points per tile, matching `docs/frontend/VENDORS.md`'s identical KPI-band tablet treatment);
  the Profile's Summary Rail moves above the tab content as a collapsible card rather than a persistent
  side column, since 4/12 at this width would compress the salary figure and status stepper past a
  comfortable tap target.
- **Mobile (`sm`, <768px)** — `EmployeesTable` switches to `DataTable`'s card-list rendering mode (one
  card per employee: name, code, department, status pill, ready indicator), never a horizontally-scrolled
  miniature table — matching the platform-wide `DataTable` mobile contract every other screen in this
  batch relies on. Row actions collapse into a single `⋯` icon opening a bottom `Sheet`. The Profile's tab
  sub-nav becomes horizontally swipeable with a partially-visible next tab as an affordance hint
  (`LAYOUT_SYSTEM.md`'s mobile tab convention). `EmployeeForm`'s sticky footer action bar remains
  full-width and thumb-reachable; the side rail (`PayrollReadinessChecklist`, Duplicate check) renders
  inline above the footer rather than beside the form, exactly as `docs/frontend/CUSTOMERS.md → Create/
  Edit`'s Side Rail collapses below `xl:`.
- **Compact/kiosk widths** are not a target for this screen — Employees is an HR/Finance back-office
  surface, never rendered in the senior/kiosk mode `LAYOUT_SYSTEM.md` reserves for a different product
  surface entirely.

Touch targets on mobile meet the platform's 44×44px minimum for every row action, tab, and checklist link;
`PayrollAmountCell`'s "Restricted" lock glyph is never the sole hit target for anything, since it triggers
no action by itself.

# RTL & Localization

Every string on this screen is a `TR`/i18n key resolved through the shared `useTranslations()` hook — no
hardcoded English or Arabic literal ships in a component, matching the platform-wide convention every
sibling document restates. Arabic renders the entire shell mirrored (`dir="rtl"` on `<html>`, set once at
the root layout per `FRONTEND_ARCHITECTURE.md`), with three deliberate exceptions carried over verbatim
from `docs/frontend/VENDORS.md → RTL`'s identical list, because they are genuinely universal, not
language-specific: `employee_code` (`dir="ltr"` span, since a code like `EMP-000512` reads the same in any
language and reversing its digits would make it unreadable), monetary amounts and their currency codes
(`AmountCell`/`PayrollAmountCell` always render numerals LTR even inside an RTL sentence, per
`DESIGN_LANGUAGE.md`'s numeral-direction rule), and dates (`formatDate()` from `lib/i18n/format` — Gregorian
by default, Hijri-aware only where a company explicitly opts in, matching every other finance screen).

Bilingual name fields (`first_name_en`/`last_name_en` alongside `first_name_ar`/`last_name_ar`) render both
simultaneously wherever space allows (the List's Name column, the Profile header) rather than switching
which one displays based on the active UI locale — an Arabic-locale HR Manager and an English-locale
Finance Manager both see "Fahad Al-Mutairi / فهد المطيري" on the same row, because the *employee's own*
name is data, not UI chrome that translates with the interface. `EmployeeForm`'s Personal Information
section requires the EN pair and treats the AR pair as recommended-but-optional (mirroring
`docs/accounting/PAYROLL.md → Employee Master Data → Personal Information`'s framing that bilingual naming
exists "so payslips and statutory filings render correctly in Arabic without transliteration errors" — an
employee without an Arabic name on file still functions, but their Arabic-language payslip falls back to a
transliteration warning rather than failing to render).

`ManagerPicker`/`DepartmentPicker`/`PositionPicker` search against both `name_en` and `name_ar`
simultaneously regardless of the active locale, exactly as `CUSTOMERS.md`'s own search fields do, so a
bilingual office does not need to know which language a record was originally entered in to find it.

# Dark Mode

Dark mode is system-aware by default with a manual override, per `DARK_MODE.md`, and this screen introduces
no new dark-mode logic — every token used above (`ink-950`…`ink-0`, `accent-700/600/500/100`,
`success`/`warning`/`danger`/`accent`) already has its dark-mode counterpart defined once in
`COMPONENT_LIBRARY.md`'s design-tokens block, and `PayrollAmountCell`'s lock glyph and `EMPLOYEE_STATUS`
tone table both resolve through that same token layer with zero screen-specific dark overrides. The one
rule worth restating because it applies directly to two of this screen's surfaces: **exported PDFs always
render light** (`DARK_MODE.md`, cited identically in `docs/frontend/CUSTOMERS.md → Statement tab`) — the
"Export employee file" action and the Documents tab's signed-URL document previews render in the platform's
fixed light/print palette regardless of the exporting user's active theme, since a payslip or a scanned
civil ID handed to a bank or a ministry must look identical no matter who generated it.

`RiskScoreBadge`/`ConfidenceBadge`-style severity colors (used by the AI panel) keep their light-mode hue
relationships in dark mode — `warning` and `danger` shift lightness, not hue, so a Compliance flag reads as
"the same kind of concern" in both themes, per `DESIGN_LANGUAGE.md`'s color-consistency rule.

# Accessibility

AA contrast minimum across both themes, keyboard-first operation, and `prefers-reduced-motion` honored for
every transition on this screen (tab switches, `Sheet`/`Dialog` open/close, the Stepper's step-completion
animation) — none of which is screen-specific; all are inherited wholesale from `ACCESSIBILITY.md`.

- **Permission-aware rendering follows `ACCESSIBILITY.md`'s two-tier rule exactly**: a control the caller's
  role can never use in any state (Terminate for an Accountant) is omitted from the DOM; a control that is
  contextually inapplicable right now but the caller could use in a different state (Rehire on an `active`
  employee) is likewise omitted rather than disabled, because a disabled Rehire button invites the "why is
  this greyed out" question a screen reader user cannot resolve without extra context. The one exception on
  this screen, mirroring `docs/frontend/VENDORS.md`'s stepper precedent: the Compensation tab itself
  renders **disabled-with-tooltip**, not omitted, for a caller lacking `payroll.salary.view` — because the
  tab's mere existence ("this employee has compensation data") is not itself sensitive information the way
  a specific salary figure is, so hiding the tab entirely would remove more information than the permission
  is meant to protect.
- **`PayrollAmountCell`'s restricted state is screen-reader-legible**, not a visually-hidden icon: the
  rendered text is the literal word "Restricted," with `aria-label="Salary restricted — you do not have
  permission to view this amount"` on the wrapping element, so a screen-reader user hears an explanation,
  not silence.
- **`StatusPill` never carries meaning by color alone** — every tone pairs with the status label as visible
  text (`# Components Used`), satisfying `ACCESSIBILITY.md`'s "a screen should read correctly with color
  removed" rule inherited from `DESIGN_LANGUAGE.md`.
- **Focus management**: opening `EmployeeLifecycleActionDialog`, any `Sheet`, or the duplicate-block
  `AlertDialog` traps focus within it and returns focus to the triggering control on close (shadcn/ui's
  Radix-based primitives provide this by default; this screen does not override it). The sticky footer
  action bar's buttons are `aria-hidden` and `tabindex="-1"` while the header's own buttons are visible,
  preventing a keyboard user from tabbing into two live copies of the same "Save" action
  (`LAYOUT_SYSTEM.md`'s single-live-focus-target rule, restated identically for `CustomerForm`).
- **Keyboard shortcuts never shadow a native browser or OS shortcut** and are all discoverable via `?` (the
  platform's shortcut-help overlay), per `ACCESSIBILITY.md → Global keyboard shortcuts`.
- **Form errors** (Zod validation messages) are associated to their field via `aria-describedby` and
  announced via a live region on submit failure, matching `docs/frontend/CUSTOMERS.md → CustomerForm`'s
  identical pattern for `contacts`/`addresses` field-array errors.

# Performance

- **List**: `GET /payroll/employees` is cursor/page-paginated at 25 rows by default
  (`docs/api/API_PAGINATION.md`'s platform default); search is debounced 300ms; `keepPreviousData` prevents
  a layout flash on page change. The KPI band's `stats` endpoint and the AI-flags probe are independent,
  cheap, cacheable-for-60-seconds queries — neither blocks the table's own `<Suspense>` boundary from
  resolving first if it returns faster.
- **Profile**: The initial `GET /payroll/employees/{id}` fetch is the single most important request on this
  screen and is server-rendered (RSC) so the Overview tab and header paint with zero client-side waterfall;
  every other tab's data (Compensation, Documents, Audit Log, Attendance) is fetched lazily behind its own
  `<Suspense>` boundary only once that tab is selected — `?tab=compensation` on first load prefetches only
  that tab's data, never all eight tabs' data eagerly, keeping the Profile's first paint weight comparable
  to `docs/frontend/VENDORS.md`'s equally eight-tab-shaped profile.
- **Photo assets**: `photo_attachment_id` renders through `next/image` with a blur placeholder and lazy
  loading below the fold (the List's avatar-less design deliberately omits photos from the table entirely
  to avoid 25 concurrent image requests per page — photos appear only on the Profile header, one at a
  time).
- **PII stays server-side**: encrypted fields are decrypted and masked server-side; the masked
  (`***-***-1092`) or fully-restricted (`null` + `visible: false`) representation is the only thing that
  ever reaches the browser bundle — there is no client-side unmasking logic to audit, because there is
  nothing sensitive on the client to unmask.
- **Row-hover prefetch**: hovering a `EmployeesTable` row for >150ms prefetches that employee's `detail`
  query via TanStack Query's `prefetchQuery`, so a click into the Profile usually renders from a warm cache,
  identical to the row-hover prefetch pattern already established for Journal Entries and Vendors.
- **Audit Log virtualization**: `EmployeeAuditLogTable` virtualizes above ~200 rows (`react-virtual`),
  matching `VendorAuditLogTable`'s identical threshold, since a long-tenured employee's audit trail can
  otherwise grow into the thousands of entries.

# Edge Cases

- **Terminated employee, attempted edit via stale bookmark**: `?edit=1` on a `terminated` record returns
  `not-found` client-side (`# Route & Access`) and the server's `UpdateEmployeeRequest` independently
  rejects the same attempt — two independent barriers, not one.
- **Rehire preserves history, never duplicates**: re-activating a `terminated` employee reuses the same
  `employee_id`; the Compensation, Documents, and Audit Log tabs all continue to show the employee's full
  prior-tenure history uninterrupted, per `docs/accounting/PAYROLL.md → Employee Lifecycle → Rehire`.
- **Duplicate national ID at creation**: blocked by the `national_id_hash` unique constraint
  (`docs/accounting/PAYROLL.md → Edge Cases`), surfaced as the blocking `AlertDialog` described in `#
  Layout & Regions`, never a generic "something went wrong" toast.
- **Two active employees sharing one IBAN**: never blocked outright (a shared household account is a
  legitimate, if unusual, arrangement) — surfaced only as a Fraud Detection flag for a human to judge (`#
  AI Integration`), never an automatic hold on either employee's payroll inclusion.
- **Viewing an employee without `payroll.salary.view`**: every salary figure renders `PayrollAmountCell`'s
  restricted state; the Compensation tab itself remains visible-but-disabled (`# Accessibility`) rather
  than removed, so the caller knows compensation data exists without being able to read it.
- **Branch-scoped Payroll Officer**: the List and every profile fetch are silently scoped server-side to
  their `branch_id`; this screen never renders a "you're missing some employees" notice, because from that
  caller's point of view the scoped set *is* the complete roster they are entitled to see.
- **An employee record missing a payroll-readiness field**: never blocks *saving* the record (`#
  Interactions & Flows`) — it only blocks the "Activate" transition and, on the Payroll Runs screen (out of
  this document's scope), inclusion in a calculation, consistent with `docs/accounting/PAYROLL.md`'s own
  framing that the gate is "a checklist, not a hard block on saving partial data."
- **Passport/contract nearing expiry**: surfaced as inline `warning`/`danger` badges on the Overview and
  Documents tabs and as a Compliance AI flag; expiry does not itself change `employment_status` or block
  any action on this screen — Kuwait's own visa/work-permit consequences of an expired document are an
  HR/legal process this screen only warns about, never automates.
- **Photo upload failure**: `EmployeeForm`'s photo field falls back to an initials avatar (derived
  client-side from `first_name_en`/`last_name_en`, never stored) rather than a broken-image icon, matching
  the platform's standard avatar-fallback component used across every profile screen.
- **Self-service `Employee` role**: opening `/payroll/employees/[employeeId]` for any id other than their
  own resolves the same as a cross-company id — a `404`, never a `403` that would confirm another
  employee's id is valid — and their own profile renders with every tab except Compensation, Bank &
  Payment, Documents (identity documents only), and Audit Log replaced by a narrower self-service layout
  showing their own payslips (`payroll.payslip.view.self`) and their own emergency contacts, editable by
  themselves.
- **Bulk import**: `Import` (gated `payroll.employee.create` plus a company-level `payroll.import.enabled`
  flag not otherwise covered by this document) processes a CSV row-by-row server-side and reports per-row
  success/failure rather than an all-or-nothing batch — a single duplicate national ID in row 40 of 200
  does not fail rows 1–39.

# End of Document
