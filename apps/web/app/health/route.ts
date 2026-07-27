import { NextResponse } from "next/server";

type HealthPayload = {
  status: "ok";
  service: "qayd-web";
};

/**
 * Liveness/readiness probe for the QAYD web app.
 * Returns a small, static JSON payload so uptime checks and the platform
 * gateway can confirm the app is booted and serving.
 */
export function GET(): NextResponse<HealthPayload> {
  return NextResponse.json({ status: "ok", service: "qayd-web" });
}
