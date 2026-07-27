import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import type { CompanySummary } from "@qayd/types";

const { push } = vi.hoisted(() => ({ push: vi.fn() }));
vi.mock("next/navigation", () => ({
  useRouter: () => ({ push, refresh: vi.fn(), replace: vi.fn() }),
}));

import { CompanySelectList } from "./select-company-list";
import { LocaleProvider } from "../../../lib/i18n/locale-provider";

const companies: CompanySummary[] = [
  { uuid: "c-1", name_en: "Al-Noor Trading", name_ar: "النور", role: "owner" },
  { uuid: "c-2", name_en: "Gulf Logistics", name_ar: null, role: "accountant" },
];

function fakeResponse(status = 200): Response {
  return {
    ok: status < 400,
    status,
    headers: new Headers(),
    json: async () => ({ success: status < 400 }),
  } as unknown as Response;
}

function renderList(next: string | null = null) {
  return render(
    <LocaleProvider initialLocale="en">
      <CompanySelectList companies={companies} next={next} />
    </LocaleProvider>,
  );
}

describe("CompanySelectList", () => {
  beforeEach(() => push.mockReset());
  afterEach(() => vi.restoreAllMocks());

  it("switches company on select and redirects to /dashboard", async () => {
    const fetchMock = vi.fn().mockResolvedValue(fakeResponse());
    vi.stubGlobal("fetch", fetchMock);

    renderList();
    fireEvent.click(screen.getByRole("button", { name: /Al-Noor Trading/ }));

    await waitFor(() => expect(push).toHaveBeenCalledWith("/dashboard"));
    expect(fetchMock).toHaveBeenCalledWith(
      "/api/auth/switch-company",
      expect.objectContaining({ method: "POST" }),
    );
    const body = JSON.parse(
      (fetchMock.mock.calls[0][1] as { body: string }).body,
    );
    expect(body).toEqual({ company_id: "c-1" });
  });

  it("forwards a validated next after switching", async () => {
    vi.stubGlobal("fetch", vi.fn().mockResolvedValue(fakeResponse()));
    renderList("/accounting/journal");
    fireEvent.click(screen.getByRole("button", { name: /Gulf Logistics/ }));
    await waitFor(() =>
      expect(push).toHaveBeenCalledWith("/accounting/journal"),
    );
  });

  it("surfaces an inline access-denied error on a 403 without redirecting", async () => {
    vi.stubGlobal("fetch", vi.fn().mockResolvedValue(fakeResponse(403)));
    renderList();
    fireEvent.click(screen.getByRole("button", { name: /Al-Noor Trading/ }));

    expect(
      await screen.findByText(/no longer have access/i),
    ).toBeInTheDocument();
    expect(push).not.toHaveBeenCalled();
  });
});
