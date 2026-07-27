import { describe, expect, it } from "vitest";
import { GET } from "./route";

describe("GET /health", () => {
  it("responds 200 with the qayd-web status payload", async () => {
    const response = GET();

    expect(response.status).toBe(200);
    await expect(response.json()).resolves.toEqual({
      status: "ok",
      service: "qayd-web",
    });
  });
});
