import type { ComponentType, SVGProps } from "react";

import type { NavIconName } from "./nav-items";

/**
 * Inline, dependency-free icon set for the shell chrome (nav modules + topbar utilities). Mirrors the
 * `@qayd/ui` icon approach: 24×24 stroke glyphs that default to `1em` so they scale with text, and are
 * `aria-hidden` because every icon here sits beside (or inside) a labeled control. Icons are glyphs, not
 * meaning — they are never mirrored in RTL.
 */

type IconProps = SVGProps<SVGSVGElement>;

const base = {
  width: "1em",
  height: "1em",
  viewBox: "0 0 24 24",
  fill: "none",
  stroke: "currentColor",
  strokeWidth: 2,
  strokeLinecap: "round",
  strokeLinejoin: "round",
} as const;

function Icon({ children, ...props }: IconProps) {
  return (
    <svg {...base} aria-hidden="true" {...props}>
      {children}
    </svg>
  );
}

// — Nav module glyphs —

function DashboardIcon(props: IconProps) {
  return (
    <Icon {...props}>
      <rect x="3" y="3" width="7" height="9" rx="1" />
      <rect x="14" y="3" width="7" height="5" rx="1" />
      <rect x="14" y="12" width="7" height="9" rx="1" />
      <rect x="3" y="16" width="7" height="5" rx="1" />
    </Icon>
  );
}

function AccountingIcon(props: IconProps) {
  return (
    <Icon {...props}>
      <rect x="4" y="3" width="16" height="18" rx="2" />
      <path d="M8 7h8M8 11h8M8 15h4" />
    </Icon>
  );
}

function BankingIcon(props: IconProps) {
  return (
    <Icon {...props}>
      <path d="M3 10 12 4l9 6" />
      <path d="M5 10v8M9 10v8M15 10v8M19 10v8M3 21h18" />
    </Icon>
  );
}

function SalesIcon(props: IconProps) {
  return (
    <Icon {...props}>
      <path d="M3 3v18h18" />
      <path d="m7 15 4-4 3 3 5-6" />
    </Icon>
  );
}

function PurchasingIcon(props: IconProps) {
  return (
    <Icon {...props}>
      <circle cx="9" cy="20" r="1.4" />
      <circle cx="18" cy="20" r="1.4" />
      <path d="M2 3h3l2.4 12.2a1.5 1.5 0 0 0 1.5 1.2h8.2a1.5 1.5 0 0 0 1.5-1.2L22 7H6" />
    </Icon>
  );
}

function InventoryIcon(props: IconProps) {
  return (
    <Icon {...props}>
      <path d="M3 8 12 3l9 5v8l-9 5-9-5Z" />
      <path d="m3 8 9 5 9-5M12 13v8" />
    </Icon>
  );
}

function PayrollIcon(props: IconProps) {
  return (
    <Icon {...props}>
      <circle cx="12" cy="8" r="4" />
      <path d="M4 21a8 8 0 0 1 16 0" />
    </Icon>
  );
}

function TaxIcon(props: IconProps) {
  return (
    <Icon {...props}>
      <path d="M7 3h10l1 18H6Z" />
      <path d="M9 8h6M9 12h6M9 16h4" />
    </Icon>
  );
}

function ReportsIcon(props: IconProps) {
  return (
    <Icon {...props}>
      <rect x="4" y="3" width="16" height="18" rx="2" />
      <path d="M8 13v4M12 9v8M16 11v6" />
    </Icon>
  );
}

function AiIcon(props: IconProps) {
  return (
    <Icon {...props}>
      <path d="M12 3v3M12 18v3M3 12h3M18 12h3" />
      <rect x="7" y="7" width="10" height="10" rx="2.5" />
      <circle cx="12" cy="12" r="1.6" />
    </Icon>
  );
}

const NAV_ICONS: Record<NavIconName, ComponentType<IconProps>> = {
  dashboard: DashboardIcon,
  accounting: AccountingIcon,
  banking: BankingIcon,
  sales: SalesIcon,
  purchasing: PurchasingIcon,
  inventory: InventoryIcon,
  payroll: PayrollIcon,
  tax: TaxIcon,
  reports: ReportsIcon,
  ai: AiIcon,
};

export function NavIcon({ name, ...props }: IconProps & { name: NavIconName }) {
  const Glyph = NAV_ICONS[name];
  return <Glyph {...props} />;
}

// — Topbar / utility glyphs —

export function MenuIcon(props: IconProps) {
  return (
    <Icon {...props}>
      <path d="M4 6h16M4 12h16M4 18h16" />
    </Icon>
  );
}

export function SearchIcon(props: IconProps) {
  return (
    <Icon {...props}>
      <circle cx="11" cy="11" r="7" />
      <path d="m20 20-3.5-3.5" />
    </Icon>
  );
}

export function BellIcon(props: IconProps) {
  return (
    <Icon {...props}>
      <path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9" />
      <path d="M10.3 21a1.9 1.9 0 0 0 3.4 0" />
    </Icon>
  );
}

export function SettingsIcon(props: IconProps) {
  return (
    <Icon {...props}>
      <circle cx="12" cy="12" r="3" />
      <path d="M19.4 15a1.7 1.7 0 0 0 .3 1.9l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-2.9 1.2V21a2 2 0 1 1-4 0v-.1A1.7 1.7 0 0 0 7 19.4a1.7 1.7 0 0 0-1.9.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0-1.2-2.9H1a2 2 0 1 1 0-4h.1A1.7 1.7 0 0 0 2.3 7a1.7 1.7 0 0 0-.3-1.9l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1A1.7 1.7 0 0 0 7 2.6h.1A1.7 1.7 0 0 0 8 1.7V1a2 2 0 1 1 4 0v.1A1.7 1.7 0 0 0 15 2.6a1.7 1.7 0 0 0 1.9-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.9V7a1.7 1.7 0 0 0 1.2 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1Z" />
    </Icon>
  );
}

export function PanelCollapseIcon(props: IconProps) {
  return (
    <Icon {...props}>
      <rect x="3" y="4" width="18" height="16" rx="2" />
      <path d="M9 4v16" />
    </Icon>
  );
}

export function GlobeIcon(props: IconProps) {
  return (
    <Icon {...props}>
      <circle cx="12" cy="12" r="9" />
      <path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18" />
    </Icon>
  );
}

export function UserIcon(props: IconProps) {
  return (
    <Icon {...props}>
      <circle cx="12" cy="8" r="4" />
      <path d="M4 20a8 8 0 0 1 16 0" />
    </Icon>
  );
}
