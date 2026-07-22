# ADR-0002: Use Next.js 15 (App Router) for the web application

Status: Accepted

Date: 2026-07

## Context

QAYD's web surface is a data-dense, bilingual (English / Arabic, full RTL), editorial-grade financial
dashboard — accounting, reports, company management, and AI chat. It must render fast on first paint, feel
instant on interaction, work well for SEO on its marketing and public surfaces, and be productive for a small
team shipping an MVP. The official stack ([../../foundation/TECH_STACK.md](../../foundation/TECH_STACK.md))
already names Next.js 15 with React 19, TypeScript, Tailwind, and shadcn/ui as the frontend, and the design
target ([../../developer/ARCHITECTURE_DECISIONS.md](../../developer/ARCHITECTURE_DECISIONS.md), ADR-003) fixes
the web tier as an **unprivileged client** of the API. The open question for the build was which React
framework and rendering model to commit to.

## Decision

The web application in `apps/web` is **Next.js 15 using the App Router**, with **React 19 Server Components**
as the default and Client Components only where interactivity requires them. Data reads happen server-side in
Server Components and Server Actions wherever possible (streaming first paint with real data), forwarding the
signed-in user's own session (Laravel Sanctum cookie) to the Laravel API — the web tier holds **no privileged
credential** and consumes the same versioned `/api/v1` surface any client would through the typed SDK
([ADR-0003](./0003-supabase-as-backend.md), [ADR-0008](./0008-typescript-shared-packages.md)). Server state on
the client is owned by TanStack Query; the design system is Tailwind + shadcn/ui ([ADR-0009](./0009-tailwind-shadcn-design-system.md)).
The editorial design language — near-monochrome ink, one brass accent, a grotesque display face, purposeful
micro-motion, four rendering variants (light/dark × LTR/RTL) — is implemented directly against App Router
layouts and streaming boundaries.

## Consequences

Positive:
- Server Components stream real data on first paint, so a financial dashboard renders without a client-visible spinner, and less JavaScript ships to the browser.
- Server Actions give a clean, type-safe write path from the UI to the backend surface without hand-rolling an API client for every mutation.
- The framework's file-based routing, image and font optimization, and mature ecosystem cut MVP scaffolding time; i18n and RTL are handled at the layout level.
- Containerized (Docker) deployment and the Laravel `/api/v1` both fit the Next.js runtime with minimal glue.

Negative / trade-offs:
- The App Router's Server/Client component boundary and caching model have real learning curves; a misplaced `"use client"` or an unintended cache can cause subtle staleness or bundle bloat.
- React 19 and App Router are relatively new; some libraries lag, and patterns are still stabilizing.
- Server-side rendering with per-request user sessions requires care that no request leaks another tenant's cached data (see [ADR-0005](./0005-multitenancy-rls.md)).

## Alternatives considered

- **Vite + React SPA:** simpler mental model and faster local dev, but no first-class SSR/streaming, weaker SEO, and we would hand-build routing, data-loading, and the server write path that Next.js provides.
- **Remix / React Router 7:** an excellent data-loading story, but a smaller ecosystem for our design-system and component needs and less alignment with the already-fixed stack.
- **Next.js Pages Router:** stable and familiar, but forgoes Server Components and streaming — the exact features that make a data-dense dashboard feel fast — for no lasting benefit on a greenfield build.

## Related

- [ADR-0001](./0001-monorepo-structure.md), [ADR-0003](./0003-supabase-as-backend.md), [ADR-0009](./0009-tailwind-shadcn-design-system.md)
- Design target: [../../developer/ARCHITECTURE_DECISIONS.md](../../developer/ARCHITECTURE_DECISIONS.md) — ADR-003 (unprivileged web client), ADR-005 (TanStack Query), ADR-010 (shadcn/Radix); [../../foundation/TECH_STACK.md](../../foundation/TECH_STACK.md)
