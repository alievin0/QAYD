# QAYD — Manifest

Version: 1.0
Status: Canonical — read this before any other file
Date: 2026-07

---

This is the first file anyone — a developer or an AI — should read before touching QAYD.
It holds the vision and the philosophy. The current state of the project lives in
[PROJECT_STATUS.md](./PROJECT_STATUS.md); the engineering decisions live in
[docs/architecture/FINAL_TECH_STACK.md](./docs/architecture/FINAL_TECH_STACK.md) and
[docs/architecture/adr/](./docs/architecture/adr/).

## What QAYD is

QAYD is an **AI Financial Operating System** that helps companies manage and run their financial
operations intelligently, while preserving accounting accuracy and human oversight.

It is **not** a set of pages. It is **not** CRUD. It is **not** a traditional ERP. It is a financial
operating system with an AI workforce on top of a double-entry core — and it is built **one real
capability at a time**, never all at once.

## How we measure success

A sprint succeeds only when three things hold together:

1. A new capability for the user.
2. The system stays stable and fully runnable.
3. The frozen architecture is not broken.

If one is missing, it is a problem to solve before moving on. Success is **never** measured in files,
ADRs, PRDs, commits, or lines of code. The only question that matters after any change is:

> **What can the user do today that they could not do yesterday?**

## Decision priority (never reversed)

1. System health
2. Architecture clarity
3. Code quality
4. User experience
5. Development speed

## The laws

1. **Code > Documentation.** On a conflict, never silently bend code to an old doc — ask whether the
   doc is stale or the code drifted. A real architecture change goes: **new ADR → update the doc →
   continue**, never the reverse. Frozen docs are not edited directly.
2. **Do not build the future.** Implement only the current sprint's scope — nothing ahead of it,
   however certain it is to be needed later.
3. **Every sprint ends with a usable product** — however small.
4. **Never break `main`.** `main` always builds, passes its tests, and runs; unfinished work stays on
   its branch.
5. **Log tech debt.** Every "fix it later" goes into `TECH_DEBT.md` (or a tracked issue), never into
   memory.
6. **The product is the reference.** Make the product better, not the project bigger.

## What "the first real version" means

Not a working journal entry. Not a working AI agent. Not reports. The first real success is when
someone who has never seen the project can:

1. Start the project.
2. Create an account (or sign in, per the approved authentication flow).
3. Create a company.
4. Enter the dashboard and see the app shell — sidebar, topbar, theme, company switcher.

…with **zero developer intervention**. Only then is QAYD a product, not a project.

## The reference hierarchy

1. **`MANIFEST.md`** — vision and philosophy (this file; read first).
2. **`PROJECT_STATUS.md`** — the current state of the project.
3. **`docs/architecture/FINAL_TECH_STACK.md` + `docs/architecture/adr/`** — the engineering decisions.
   FINAL_TECH_STACK wins any conflict.
4. **`docs/execution/SPRINT_0N.md`** — the scope of the current sprint.

The architecture is frozen at the git tag **`architecture-freeze-v1`** (Option A: Next.js + Laravel +
FastAPI + PostgreSQL, with Supabase used only for managed data services). It is fixed until a new ADR
changes it.

## The one line to remember

> **Every sprint must add a real user capability while keeping a stable architecture and production
> quality.**

# End of Document
