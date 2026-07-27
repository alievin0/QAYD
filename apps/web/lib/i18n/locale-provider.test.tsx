import { fireEvent, render, screen } from "@testing-library/react";
import { beforeEach, describe, expect, it } from "vitest";

import { LocaleProvider, useI18n } from "./locale-provider";

function Harness() {
  const { locale, dir, setLocale, t } = useI18n();
  return (
    <div>
      <span data-testid="locale">{locale}</span>
      <span data-testid="dir">{dir}</span>
      <span data-testid="dashboard-label">{t("nav.dashboard")}</span>
      <button type="button" onClick={() => setLocale("ar")}>
        to-ar
      </button>
      <button type="button" onClick={() => setLocale("en")}>
        to-en
      </button>
    </div>
  );
}

describe("LocaleProvider — EN/AR + RTL", () => {
  beforeEach(() => {
    document.documentElement.setAttribute("dir", "ltr");
    document.documentElement.setAttribute("lang", "en");
  });

  it("starts LTR in English and translates shell strings", () => {
    render(
      <LocaleProvider initialLocale="en">
        <Harness />
      </LocaleProvider>,
    );

    expect(screen.getByTestId("dir")).toHaveTextContent("ltr");
    expect(screen.getByTestId("dashboard-label")).toHaveTextContent(
      "Dashboard",
    );
    expect(document.documentElement.getAttribute("dir")).toBe("ltr");
  });

  it("mirrors to RTL and re-translates when switched to Arabic", () => {
    render(
      <LocaleProvider initialLocale="en">
        <Harness />
      </LocaleProvider>,
    );

    fireEvent.click(screen.getByRole("button", { name: "to-ar" }));

    expect(screen.getByTestId("locale")).toHaveTextContent("ar");
    expect(screen.getByTestId("dir")).toHaveTextContent("rtl");
    expect(document.documentElement.getAttribute("dir")).toBe("rtl");
    expect(document.documentElement.getAttribute("lang")).toBe("ar");
    expect(screen.getByTestId("dashboard-label")).toHaveTextContent(
      "لوحة التحكّم",
    );
  });

  it("returns to LTR when switched back to English", () => {
    render(
      <LocaleProvider initialLocale="ar">
        <Harness />
      </LocaleProvider>,
    );
    expect(document.documentElement.getAttribute("dir")).toBe("rtl");

    fireEvent.click(screen.getByRole("button", { name: "to-en" }));

    expect(screen.getByTestId("dir")).toHaveTextContent("ltr");
    expect(document.documentElement.getAttribute("dir")).toBe("ltr");
  });
});
