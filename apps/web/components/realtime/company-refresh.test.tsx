import { render } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { CompanyRefresh } from "./company-refresh";

const refresh = vi.fn();

vi.mock("next/navigation", () => ({
  useRouter: () => ({ refresh, push: vi.fn() }),
}));

/**
 * The refresh subscriber (S2-13).
 *
 * What matters about this component is what it does NOT do, and that is what is asserted: it renders
 * nothing, and with no socket server configured it stays entirely inert — no import, no connection, no
 * throw. That inertness is the reason it is safe to mount above screens whose correctness must not
 * depend on a WebSocket; were it able to throw or block, every accounting page would inherit that.
 *
 * The socket itself is not tested here. Proving a message crosses a WebSocket needs a running server,
 * and the transport belongs to Laravel and Pusher rather than to us.
 */
describe("CompanyRefresh", () => {
  beforeEach(() => {
    refresh.mockClear();
    vi.unstubAllEnvs();
  });

  afterEach(() => {
    vi.unstubAllEnvs();
  });

  it("renders nothing", () => {
    const { container } = render(<CompanyRefresh companyId="company-uuid" />);
    expect(container.innerHTML).toBe("");
  });

  it("stays inert when no socket server is configured", () => {
    vi.stubEnv("NEXT_PUBLIC_REVERB_APP_KEY", "");
    vi.stubEnv("NEXT_PUBLIC_REVERB_HOST", "");

    expect(() =>
      render(<CompanyRefresh companyId="company-uuid" />),
    ).not.toThrow();
    expect(refresh).not.toHaveBeenCalled();
  });

  it("does nothing without an active company", () => {
    vi.stubEnv("NEXT_PUBLIC_REVERB_APP_KEY", "local-key");
    vi.stubEnv("NEXT_PUBLIC_REVERB_HOST", "localhost");

    expect(() => render(<CompanyRefresh companyId={null} />)).not.toThrow();
    expect(refresh).not.toHaveBeenCalled();
  });

  it("never refreshes merely from mounting", () => {
    // A refresh is a consequence of a posted entry, never of the page opening — otherwise every
    // navigation into the section would cost a second round of server reads.
    render(<CompanyRefresh companyId="company-uuid" />);
    expect(refresh).not.toHaveBeenCalled();
  });
});
