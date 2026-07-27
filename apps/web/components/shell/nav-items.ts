/**
 * The primary-navigation module map (NAVIGATION_SYSTEM.md § "Primary Navigation"). The order is
 * platform-fixed and follows the money: Dashboard (home) → Accounting → Banking → Sales → Purchasing →
 * Inventory → Payroll → Tax → Reports → AI.
 *
 * Sprint-1 reality: only Dashboard is a built route this sprint. The other nine are the *frame* every
 * later screen renders into; they are shown but marked `available: false` so the shell reads as complete
 * without dead-linking to routes that do not exist yet. RBAC permission-gating (hide vs disable by the
 * caller's permission set) layers on in a later story via `/auth/me` permissions.
 */

export interface NavItem {
  /** Stable id + the `nav.<id>` translation key. */
  id:
    | "dashboard"
    | "accounting"
    | "banking"
    | "sales"
    | "purchasing"
    | "inventory"
    | "payroll"
    | "tax"
    | "reports"
    | "ai";
  href: string;
  /** Lucide-style glyph name (rendered by the shell's inline icon set). */
  icon: NavIconName;
  /** Whether the destination route exists this sprint. Only Dashboard is `true`. */
  available: boolean;
}

export type NavIconName =
  | "dashboard"
  | "accounting"
  | "banking"
  | "sales"
  | "purchasing"
  | "inventory"
  | "payroll"
  | "tax"
  | "reports"
  | "ai";

export const NAV_ITEMS: readonly NavItem[] = [
  { id: "dashboard", href: "/dashboard", icon: "dashboard", available: true },
  {
    id: "accounting",
    href: "/accounting",
    icon: "accounting",
    available: false,
  },
  { id: "banking", href: "/banking", icon: "banking", available: false },
  { id: "sales", href: "/sales", icon: "sales", available: false },
  {
    id: "purchasing",
    href: "/purchasing",
    icon: "purchasing",
    available: false,
  },
  { id: "inventory", href: "/inventory", icon: "inventory", available: false },
  { id: "payroll", href: "/payroll", icon: "payroll", available: false },
  { id: "tax", href: "/tax", icon: "tax", available: false },
  { id: "reports", href: "/reports", icon: "reports", available: false },
  { id: "ai", href: "/ai", icon: "ai", available: false },
] as const;
