import type { Dictionary } from "./en";

/**
 * Arabic (`ar`) shell dictionary. Typed `Dictionary` so it must mirror `en.ts` key-for-key at compile
 * time; `i18n:check` guards the same parity at runtime. Arabic is the RTL, full-mirror locale.
 */
export const ar: Dictionary = {
  app: {
    name: "قَيد",
    tagline: "نظام التشغيل المالي بالذكاء الاصطناعي",
  },
  nav: {
    primary: "التنقّل الرئيسي",
    dashboard: "لوحة التحكّم",
    accounting: "المحاسبة",
    banking: "البنوك",
    sales: "المبيعات",
    purchasing: "المشتريات",
    inventory: "المخزون",
    payroll: "الرواتب",
    tax: "الضرائب",
    reports: "التقارير",
    ai: "الذكاء الاصطناعي",
    soon: "قريباً",
  },
  shell: {
    skipToContent: "تخطَّ إلى المحتوى",
    collapseSidebar: "طيّ الشريط الجانبي",
    expandSidebar: "توسيع الشريط الجانبي",
    openMenu: "فتح القائمة",
    closeMenu: "إغلاق القائمة",
    settings: "الإعدادات",
    mainContent: "المحتوى الرئيسي",
  },
  company: {
    switcherLabel: "الشركة النشطة",
    switcherPlaceholder: "لا توجد شركة",
    none: "لا توجد شركات بعد",
    switching: "جارٍ التبديل…",
  },
  language: {
    label: "اللغة",
    en: "English",
    ar: "العربية",
  },
  theme: {
    toggle: "تبديل السمة",
    light: "فاتح",
    dark: "داكن",
  },
  topbar: {
    search: "بحث",
    notifications: "الإشعارات",
    account: "الحساب",
    breadcrumbHome: "الرئيسية",
  },
  user: {
    menu: "قائمة الحساب",
    profile: "الملف الشخصي",
    settings: "الإعدادات",
    signOut: "تسجيل الخروج",
  },
  dashboard: {
    title: "لوحة التحكّم",
    welcome: "مرحباً بك في {company}",
    emptyTitle: "لا شيء لعرضه بعد",
    emptyBody:
      "تم إعداد شركتك وتحديد نطاقها. تصل أدوات المحاسبة والبنوك ورؤى الذكاء الاصطناعي في الدورة القادمة — هذه هي الصفحة الرئيسية الفارغة والمُوثَّقة التي ستُعرض داخلها.",
  },
  auth: {
    login: {
      title: "تسجيل الدخول إلى قَيد",
      subtitle: "أدخل بياناتك للوصول إلى مساحة عملك.",
      email: "البريد الإلكتروني",
      password: "كلمة المرور",
      submit: "تسجيل الدخول",
      todo: "هذه صفحة مؤقتة. تصل شاشة تسجيل الدخول الحقيقية في المهمة القادمة (S1-15).",
    },
  },
};
