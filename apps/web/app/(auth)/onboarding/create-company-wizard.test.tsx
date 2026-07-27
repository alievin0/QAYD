import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

const { push } = vi.hoisted(() => ({ push: vi.fn() }));
vi.mock("next/navigation", () => ({
  useRouter: () => ({ push, refresh: vi.fn(), replace: vi.fn() }),
}));

import { CreateCompanyWizard } from "./create-company-wizard";
import { LocaleProvider } from "../../../lib/i18n/locale-provider";

function fakeResponse(status = 200): Response {
  return {
    ok: status < 400,
    status,
    headers: new Headers(),
    json: async () => ({
      success: status < 400,
      data: { company: { uuid: "cmp-1" } },
    }),
  } as unknown as Response;
}

function renderWizard() {
  return render(
    <LocaleProvider initialLocale="en">
      <CreateCompanyWizard />
    </LocaleProvider>,
  );
}

describe("CreateCompanyWizard", () => {
  beforeEach(() => push.mockReset());
  afterEach(() => vi.restoreAllMocks());

  it("validates, submits createCompany, and redirects to /dashboard", async () => {
    const fetchMock = vi.fn().mockResolvedValue(fakeResponse());
    vi.stubGlobal("fetch", fetchMock);

    renderWizard();
    fireEvent.change(screen.getByLabelText(/legal name/i), {
      target: { value: "Al-Noor Trading Company W.L.L." },
    });
    fireEvent.change(screen.getByLabelText(/company name \(english\)/i), {
      target: { value: "Al-Noor Trading" },
    });
    fireEvent.click(screen.getByRole("button", { name: /create company/i }));

    await waitFor(() => expect(push).toHaveBeenCalledWith("/dashboard"));

    expect(fetchMock).toHaveBeenCalledWith(
      "/api/auth/companies",
      expect.objectContaining({ method: "POST" }),
    );
    const body = JSON.parse(
      (fetchMock.mock.calls[0][1] as { body: string }).body,
    );
    // Defaults ride through: KWD base currency, fiscal year starting in month 1.
    expect(body).toMatchObject({
      legal_name: "Al-Noor Trading Company W.L.L.",
      name_en: "Al-Noor Trading",
      base_currency: "KWD",
      fiscal_year_start_month: 1,
    });
  });

  it("blocks submit and shows an error when required fields are empty", async () => {
    const fetchMock = vi.fn();
    vi.stubGlobal("fetch", fetchMock);

    renderWizard();
    fireEvent.click(screen.getByRole("button", { name: /create company/i }));

    await waitFor(() => expect(screen.getByRole("alert")).toBeInTheDocument());
    expect(fetchMock).not.toHaveBeenCalled();
    expect(push).not.toHaveBeenCalled();
  });
});
