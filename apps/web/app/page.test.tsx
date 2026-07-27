import { render, screen } from "@testing-library/react";
import { describe, expect, it } from "vitest";
import Home from "./page";

describe("Home page", () => {
  it("renders the QAYD heading", () => {
    render(<Home />);

    expect(
      screen.getByRole("heading", { level: 1, name: /qayd/i }),
    ).toBeInTheDocument();
  });

  it("links to the health probe", () => {
    render(<Home />);

    expect(screen.getByRole("link", { name: "/health" })).toHaveAttribute(
      "href",
      "/health",
    );
  });
});
