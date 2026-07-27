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
      submitting: "Signing in…",
      invalid: "The email or password is incorrect.",
      rateLimited:
        "Too many attempts from this network. Please try again shortly.",
      lockout:
        "Too many failed sign-in attempts. Try again in {seconds} seconds.",
      networkError: "Couldn't reach the server. Check your connection and retry.",
      forgotPassword: "Forgot password?",
      noAccount: "New to QAYD?",
      registerLink: "Create an account",
    },
    register: {
      title: "Create your QAYD account",
      subtitle: "Set up your identity, then verify your email to continue.",
      name: "Full name",
      email: "Email",
      password: "Password",
      passwordHint: "At least 8 characters.",
      submit: "Create account",
      submitting: "Creating account…",
      invalid: "Please check the highlighted fields and try again.",
      error: "We couldn't create your account. Please try again.",
      haveAccount: "Already have an account?",
      loginLink: "Sign in",
      checkEmailTitle: "Verify your email",
      checkEmailBody:
        "We've sent a verification link to {email}. Open it to activate your account, then sign in.",
      continueToLogin: "Go to sign in",
    },
    verify: {
      verifying: "Verifying your email…",
      successTitle: "Email verified",
      successBody: "Your email is confirmed. You can sign in now.",
      errorTitle: "Verification failed",
      errorBody:
        "This verification link is invalid or has expired. Request a new one by signing in.",
      missingParams: "This verification link is incomplete.",
      continueToLogin: "Continue to sign in",
    },
    selectCompany: {
      title: "Choose a company",
      subtitle: "Pick which company to work in. You can switch at any time.",
      switching: "Opening…",
      accessDenied:
        "You no longer have access to this company. Contact its owner.",
      error: "Couldn't open this company. Please try again.",
      addCompany: "Add a company",
    },
    onboarding: {
      title: "Create your company",
      subtitle: "This sets up your workspace. You can refine the details later.",
      legalName: "Legal name",
      legalNameHint: "The registered legal entity name.",
      tradeName: "Trade name",
      nameEn: "Company name (English)",
      nameAr: "Company name (Arabic)",
      baseCurrency: "Base currency",
      fiscalYearStart: "Fiscal year starts in",
      optional: "Optional",
      submit: "Create company",
      submitting: "Creating company…",
      invalid: "Please check the highlighted fields and try again.",
      error: "We couldn't create your company. Please try again.",
    },
    forgot: {
      title: "Reset your password",
      subtitle: "Enter your email and we'll send you a reset link.",
      email: "Email",
      submit: "Send reset link",
      comingSoon:
        "Password reset is coming soon. For now, contact support if you're locked out.",
      backToLogin: "Back to sign in",
    },
    reset: {
      title: "Choose a new password",
      subtitle: "Set a new password for your account.",
      password: "New password",
      confirm: "Confirm password",
      submit: "Update password",
      comingSoon:
        "Password reset is coming soon. This screen is not active yet.",
      missingToken: "This reset link is missing its token.",
      backToLogin: "Back to sign in",
    },
    mfa: {
      title: "Verify it's you",
      subtitle: "Two-factor verification.",
      comingSoon:
        "Two-factor verification isn't enabled yet. Continue signing in normally.",
      backToLogin: "Back to sign in",
    },
  },
};

/**
 * The canonical dictionary shape every locale must satisfy exactly. `en` is intentionally *not* `as
 * const`, so its leaves widen to `string`: `ar` must match the key structure (compile-time parity) while
 * still holding its own translated values. `i18n:check` guards the same parity at runtime.
 */
export type Dictionary = typeof en;
