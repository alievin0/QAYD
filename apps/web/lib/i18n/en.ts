/**
 * English (`en`) shell dictionary — the source-of-truth shape for every locale. `ar.ts` is typed
 * `Dictionary` (= `typeof en`), so a missing or extra key fails `tsc`; `i18n:check` re-asserts the same
 * parity at runtime as a standalone CI gate. Only shell-chrome strings live here — no accounting copy,
 * per Sprint-01 scope.
 */
export const en = {
  app: {
    name: "QAYD",
    tagline: "The AI Financial Operating System",
  },
  nav: {
    primary: "Primary navigation",
    dashboard: "Dashboard",
    accounting: "Accounting",
    banking: "Banking",
    sales: "Sales",
    purchasing: "Purchasing",
    inventory: "Inventory",
    payroll: "Payroll",
    tax: "Tax",
    reports: "Reports",
    ai: "AI",
    soon: "Soon",
  },
  shell: {
    skipToContent: "Skip to content",
    collapseSidebar: "Collapse sidebar",
    expandSidebar: "Expand sidebar",
    openMenu: "Open menu",
    closeMenu: "Close menu",
    settings: "Settings",
    mainContent: "Main content",
  },
  company: {
    switcherLabel: "Active company",
    switcherPlaceholder: "No company",
    none: "No companies yet",
    switching: "Switching…",
  },
  language: {
    label: "Language",
    en: "English",
    ar: "العربية",
  },
  theme: {
    toggle: "Toggle theme",
    light: "Light",
    dark: "Dark",
  },
  topbar: {
    search: "Search",
    notifications: "Notifications",
    account: "Account",
    breadcrumbHome: "Home",
  },
  user: {
    menu: "Account menu",
    profile: "Profile",
    settings: "Settings",
    signOut: "Sign out",
  },
  dashboard: {
    title: "Dashboard",
    welcome: "Welcome to {company}",
    emptyTitle: "Nothing to show yet",
    emptyBody:
      "Your company is set up and scoped. Accounting, banking, and AI insight widgets arrive in the next sprint — this is the empty, authenticated home they render into.",
  },
  auth: {
    login: {
      title: "Sign in to QAYD",
      subtitle: "Enter your credentials to access your workspace.",
      email: "Email",
      password: "Password",
      submit: "Sign in",
      todo: "This is a placeholder. The real sign-in screen ships in the next story (S1-15).",
    },
  },
};

/**
 * The canonical dictionary shape every locale must satisfy exactly. `en` is intentionally *not* `as
 * const`, so its leaves widen to `string`: `ar` must match the key structure (compile-time parity) while
 * still holding its own translated values. `i18n:check` guards the same parity at runtime.
 */
export type Dictionary = typeof en;
