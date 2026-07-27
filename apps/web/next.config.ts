import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";
import type { NextConfig } from "next";

const currentDir = dirname(fileURLToPath(import.meta.url));

const nextConfig: NextConfig = {
  // apps/web lives two levels below the repo root. Pinning the file-tracing
  // root to the monorepo root silences Next's multi-lockfile / workspace-root
  // inference warning and keeps standalone tracing deterministic.
  outputFileTracingRoot: join(currentDir, "..", ".."),
  // The workspace UI package ships prebuilt "use client" components; transpiling it through Next keeps
  // the client/server boundary and JSX runtime consistent with the app's own build.
  transpilePackages: ["@qayd/ui"],
};

export default nextConfig;
