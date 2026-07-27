import { render, screen } from "@testing-library/react";
import { describe, expect, it, vi } from "vitest";
import type { AuthMe } from "@qayd/types";

// The shell's client pieces read the App Router; provide inert stand-ins.
vi.mock("next/navigation", () => ({
  usePathname: () => "/dashboard",
  useRouter: () => ({ refresh: vi.fn(), push: vi.fn(), replace: vi.fn() }),
}));

import { AppShell } from "./app-shell";
import { Providers } from "../providers";

const mockMe: AuthMe = {
  user: {
    uuid: "u-1",
    name: "Sara Al-Sabah",
    email: "sara@al-noor.test",
    locale: "en",
    mfa_enrolled: false,
  },
  companies: [
    {
      uuid: "c-1",
      name_en: "Al-Noor Trading",
      name_ar: "النور للتجارة",
      role: "owner",
    },
    {
      uuid: "c-2",
      name_en: "Gulf Logistics",
      name_ar: null,
      role: "accountant",
    },
  ],
  active_company: { uuid: "c-1", role: "owner" },
  perms_ver: 3,
  permissions: [],
};

function renderShell(me: AuthMe | null) {
  return render(
    <Providers initialLocale="en">
      <AppShell me={me}>
        <p>page content</p>
      </AppShell>
    </Providers>,
  );
}

describe("AppShell", () => {
  it("renders the sidebar, topbar, company switcher, and theme toggle with mocked me data", () => {
    renderShell(mockMe);

    // Sidebar — primary navigation landmark + the one built destination.
    expect(
      screen.getByRole("navigation", { name: /primary navigation/i }),
    ).toBeInTheDocument();
    expect(screen.getByRole("link", { name: /dashboard/i })).toHaveAttribute(
      "href",
      "/dashboard",
    );

    // Topbar band.
    expect(screen.getByRole("banner")).toBeInTheDocument();

    // Company switcher (Radix Select trigger → combobox), labeled.
    expect(
      screen.getByRole("combobox", { name: /active company/i }),
    ).toBeInTheDocument();

    // Language switcher + theme toggle.
    expect(
      screen.getByRole("combobox", { name: /language/i }),
    ).toBeInTheDocument();
    expect(screen.getByRole("button", { name: /theme/i })).toBeInTheDocument();

    // Content region renders the page.
    expect(screen.getByText("page content")).toBeInTheDocument();
  });

  it("degrades gracefully to an empty, disabled switcher when unauthenticated", () => {
    renderShell(null);

    // No membership data → the switcher shows its placeholder, never a combobox or a crash.
    expect(
      screen.queryByRole("combobox", { name: /active company/i }),
    ).toBeNull();
    expect(screen.getByText(/no company/i)).toBeInTheDocument();
    // The rest of the chrome still renders.
    expect(
      screen.getByRole("navigation", { name: /primary navigation/i }),
    ).toBeInTheDocument();
    expect(screen.getByRole("button", { name: /theme/i })).toBeInTheDocument();
  });
});
