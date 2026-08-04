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
      networkError:
        "Couldn't reach the server. Check your connection and retry.",
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
      subtitle:
        "This sets up your workspace. You can refine the details later.",
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
  accounting: {
    title: "Accounting",
    tabs: {
      label: "Accounting sections",
      accounts: "Chart of accounts",
      journal: "Journal entries",
      ledger: "General ledger",
      trialBalance: "Trial balance",
      soon: "Soon",
    },
    coa: {
      title: "Chart of accounts",
      subtitle: "Every account this company posts to, and how they roll up.",
      count: "{count} accounts",
      viewLabel: "View",
      viewTree: "Tree",
      viewFlat: "List",
      search: "Search by code or name",
      searchLabel: "Search accounts",
      expand: "Expand {name}",
      collapse: "Collapse {name}",
      expandAll: "Expand all",
      collapseAll: "Collapse all",
      columns: {
        code: "Code",
        name: "Name",
        type: "Type",
        normalBalance: "Normal balance",
        status: "Status",
        actions: "Actions",
      },
      normalBalance: {
        debit: "Debit",
        credit: "Credit",
      },
      status: {
        active: "Active",
        inactive: "Inactive",
        archived: "Archived",
      },
      control: "Control account",
      empty: {
        title: "No accounts yet",
        body: "Create the first account to start building this company's chart.",
      },
      noMatches: {
        title: "No accounts match your search",
        body: "Try a different code or name.",
      },
      loadFailed:
        "The chart of accounts could not be loaded. Refresh to try again.",
    },
    account: {
      create: "New account",
      createTitle: "New account",
      createDescription:
        "Codes must be unique within this company. Every rule is enforced by the server.",
      code: "Code",
      codePlaceholder: "1000",
      nameEn: "Name (English)",
      nameAr: "Name (Arabic)",
      type: "Account type",
      typePlaceholder: "Select a type",
      parent: "Parent account",
      parentNone: "No parent (top level)",
      submit: "Create account",
      submitting: "Saving…",
      cancel: "Cancel",
      close: "Close",
      actionsFor: "Actions for {name}",
      reclassify: "Reclassify",
      reclassifyTitle: "Reclassify {name}",
      reclassifyDescription:
        "Move this account to a different type. An account that already carries posted entries cannot be reclassified.",
      reclassifySubmit: "Reclassify",
      deactivate: "Deactivate",
      deactivateTitle: "Deactivate {name}",
      deactivateDescription:
        "A deactivated account can no longer be selected for new postings. Its history is kept.",
      deactivateSubmit: "Deactivate",
      unexpectedError: "Something went wrong. Please try again.",
    },
    journal: {
      title: "Journal entries",
      subtitle: "Every entry this company has drafted, submitted, or posted.",
      count: "{count} entries",
      new: "New entry",
      back: "Back to journal entries",
      loadFailed: "Journal entries could not be loaded. Refresh to try again.",
      columns: {
        number: "Number",
        date: "Date",
        type: "Type",
        status: "Status",
        debit: "Debit",
        credit: "Credit",
      },
      empty: {
        title: "No journal entries yet",
        body: "Create the first entry to start recording this company's transactions.",
      },
      status: {
        draft: "Draft",
        pending_approval: "Pending approval",
        approved: "Approved",
        rejected: "Rejected",
        posted: "Posted",
        reversed: "Reversed",
        voided: "Voided",
        archived: "Archived",
      },
      editor: {
        newTitle: "New journal entry",
        editTitle: "Journal entry {number}",
        date: "Date",
        entryType: "Type",
        currency: "Currency",
        reference: "Reference",
        memo: "Memo",
        memoPlaceholder: "What is this entry for?",
        readOnly:
          "This entry is {status} and can no longer be edited. Posted history is corrected by a reversing entry, never by an edit.",
      },
      grid: {
        caption: "Journal entry lines",
        account: "Account",
        accountPlaceholder: "Select an account",
        description: "Description",
        debit: "Debit",
        credit: "Credit",
        remove: "Remove",
        removeLine: "Remove line {number}",
        addLine: "Add line",
        lineNumber: "Line",
        keyboardHint:
          "Use Tab to move between fields. Entering an amount on one side clears the other.",
      },
      balance: {
        totalDebit: "Total debit",
        totalCredit: "Total credit",
        difference: "Difference",
        balanced: "Balanced",
        outOfBalance: "Out of balance by {difference}",
        advisory:
          "This check is a guide only — the server decides whether an entry may be posted.",
      },
      actions: {
        saveDraft: "Save draft",
        saving: "Saving…",
        submit: "Submit for approval",
        submitting: "Submitting…",
        post: "Post",
        posting: "Posting…",
        saved: "Draft saved.",
        submitted: "Submitted for approval.",
        posted: "Posted.",
      },
      unexpectedError: "Something went wrong. Please try again.",
    },
    trialBalance: {
      title: "Trial balance",
      subtitle:
        "Every account with a balance in the selected period, and the proof that the two sides agree.",
      period: {
        label: "Period",
        placeholder: "Select a period",
        status: {
          future: "Future",
          open: "Open",
          closed: "Closed",
          locked: "Locked",
        },
      },
      asOf: "As of {date}",
      loading: "Computing the trial balance…",
      groups: {
        opening: "Opening",
        movement: "Movement",
        closing: "Closing",
      },
      columns: {
        code: "Code",
        account: "Account",
        debit: "Debit",
        credit: "Credit",
      },
      caption: "Trial balance by account",
      total: "Total",
      abnormal: "Abnormal",
      abnormalHint:
        "This account carries a balance on the opposite side from its normal one.",
      balanced: "Debits equal credits.",
      outOfBalance: "Out of balance by {variance}.",
      serverVerdict:
        "Balanced or not is the server's verdict, computed against this company's rounding tolerance.",
      empty: {
        title: "No fiscal periods yet",
        body: "A trial balance is computed for a period, and this company has none. Periods are created with the fiscal year.",
      },
      noActivity: {
        title: "Nothing posted in this period",
        body: "No account carries a balance yet. Post a journal entry, then compute again.",
      },
      loadFailed:
        "The trial balance could not be loaded. Select a period or refresh to try again.",
      snapshot: {
        generate: "Generate snapshot",
        generating: "Generating…",
        title: "Snapshot #{id}",
        version: "Version {version}",
        type: "Type",
        status: "Status",
        accountCount: "{count} accounts",
        totalDebit: "Total debit",
        totalCredit: "Total credit",
        variance: "Variance",
        // The seven states in App\Models\TrialBalanceSnapshot. Every one is translated: a status the
        // map is missing renders as its own key, which is how this list was found to be wrong.
        statuses: {
          generating: "Generating",
          generated: "Generated",
          validated: "Validated",
          out_of_balance: "Out of balance",
          under_review: "Under review",
          approved: "Approved",
          archived: "Archived",
        },
        queued:
          "This run was handed to the reports queue. The figures appear once it finishes.",
        refresh: "Refresh",
        refreshing: "Refreshing…",
        created: "Snapshot created.",
        explainer:
          "A snapshot freezes these figures so they can be signed. The live view above always reflects the ledger as it stands now.",
      },
      unexpectedError: "Something went wrong. Please try again.",
    },
  },
};

/**
 * The canonical dictionary shape every locale must satisfy exactly. `en` is intentionally *not* `as
 * const`, so its leaves widen to `string`: `ar` must match the key structure (compile-time parity) while
 * still holding its own translated values. `i18n:check` guards the same parity at runtime.
 */
export type Dictionary = typeof en;
