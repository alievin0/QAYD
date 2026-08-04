import { resolveApiBaseUrl } from "@qayd/shared";
import type {
  AccountListResult,
  AccountResult,
  AccountTreeResult,
  AccountTypeListResult,
  AuthMe,
  CreateAccountInput,
  CreateCompanyInput,
  CreateCompanyResult,
  Envelope,
  LoginInput,
  LoginResult,
  LogoutInput,
  ReclassifyAccountInput,
  RefreshInput,
  RefreshResult,
  RegisterInput,
  RegisterResult,
  SwitchCompanyInput,
  SwitchCompanyResult,
  UpdateAccountInput,
  VerifyEmailInput,
  VerifyEmailResult,
} from "@qayd/types";

import { networkError, toApiError } from "./errors.js";

/** A minimal structural `fetch` — the SDK only ever calls it with a string URL. */
export type FetchLike = (input: string, init?: RequestInit) => Promise<Response>;

export interface QaydClientOptions {
  /** API base URL including `/api/v1`. Defaults to `resolveApiBaseUrl()` from `@qayd/shared`. */
  baseUrl?: string;
  /** Optional bearer token (string or lazy getter) for mobile/partner clients. Web uses the cookie. */
  token?: string | (() => string | null | undefined);
  /** Active company UUID → sent as `X-Company-Id` on every request (per-call override available). */
  companyId?: string;
  /** Fetch credentials mode. Defaults to `"include"` so the Sanctum session cookie is sent. */
  credentials?: RequestCredentials;
  /** Injectable fetch (SSR / tests). Defaults to the global `fetch`. */
  fetch?: FetchLike;
  /** `X-Request-Id` generator. Defaults to `crypto.randomUUID()` with a portable fallback. */
  generateRequestId?: () => string;
  /** Extra headers merged into every request (lowest precedence). */
  defaultHeaders?: Record<string, string>;
}

interface RequestConfig {
  method: "GET" | "POST" | "PATCH";
  path: string;
  body?: unknown;
  query?: Record<string, string | number | undefined>;
  /** Per-call company override; falls back to the client's `companyId`. */
  companyId?: string;
}

function defaultRequestId(): string {
  const cryptoObj = (globalThis as { crypto?: { randomUUID?: () => string } }).crypto;
  if (typeof cryptoObj?.randomUUID === "function") return cryptoObj.randomUUID();
  return "xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx".replace(/[xy]/g, (char) => {
    const rand = (Math.random() * 16) | 0;
    const value = char === "x" ? rand : (rand & 0x3) | 0x8;
    return value.toString(16);
  });
}

function buildQuery(query: Record<string, string | number | undefined> | undefined): string {
  if (!query) return "";
  const params = new URLSearchParams();
  for (const [key, value] of Object.entries(query)) {
    if (value !== undefined) params.set(key, String(value));
  }
  const serialized = params.toString();
  return serialized ? `?${serialized}` : "";
}

/**
 * The typed QAYD API client. Every method resolves to the typed response `Envelope<T>` on success and
 * throws a {@link QaydApiError} on any `success:false` / non-2xx response or transport failure.
 */
export class QaydClient {
  private readonly baseUrl: string;
  private readonly credentials: RequestCredentials;
  private readonly fetchImpl: FetchLike;
  private readonly makeRequestId: () => string;
  private readonly defaultHeaders: Record<string, string>;
  private readonly tokenSource?: string | (() => string | null | undefined);
  private companyId?: string;

  constructor(options: QaydClientOptions = {}) {
    this.baseUrl = resolveApiBaseUrl(options.baseUrl);
    this.credentials = options.credentials ?? "include";
    const globalFetch = (globalThis as { fetch?: FetchLike }).fetch;
    const chosenFetch = options.fetch ?? globalFetch;
    if (!chosenFetch) {
      throw new Error("QaydClient: no fetch implementation available; pass options.fetch.");
    }
    this.fetchImpl = chosenFetch;
    this.makeRequestId = options.generateRequestId ?? defaultRequestId;
    this.defaultHeaders = options.defaultHeaders ?? {};
    this.tokenSource = options.token;
    this.companyId = options.companyId;
  }

  /** Set (or clear) the active company sent as `X-Company-Id` on subsequent requests. */
  setCompanyId(companyId: string | undefined): void {
    this.companyId = companyId;
  }

  private resolveToken(): string | undefined {
    const raw = typeof this.tokenSource === "function" ? this.tokenSource() : this.tokenSource;
    return raw ?? undefined;
  }

  private async request<T>(config: RequestConfig): Promise<Envelope<T>> {
    const requestId = this.makeRequestId();
    const url = `${this.baseUrl}${config.path}${buildQuery(config.query)}`;

    const headers: Record<string, string> = {
      Accept: "application/json",
      "X-Request-Id": requestId,
      ...this.defaultHeaders,
    };
    if (config.body !== undefined) headers["Content-Type"] = "application/json";

    const companyId = config.companyId ?? this.companyId;
    if (companyId) headers["X-Company-Id"] = companyId;

    const token = this.resolveToken();
    if (token) headers["Authorization"] = `Bearer ${token}`;

    let response: Response;
    try {
      response = await this.fetchImpl(url, {
        method: config.method,
        headers,
        credentials: this.credentials,
        body: config.body !== undefined ? JSON.stringify(config.body) : undefined,
      });
    } catch (cause) {
      throw networkError(
        cause instanceof Error ? cause.message : "Network request failed.",
        requestId,
      );
    }

    const headerRequestId = response.headers.get("X-Request-Id") ?? requestId;

    // 204 No Content: no envelope body by contract.
    if (response.status === 204) {
      return emptyEnvelope<T>(headerRequestId);
    }

    let envelope: Envelope<T> | null = null;
    try {
      envelope = (await response.json()) as Envelope<T>;
    } catch {
      if (response.ok) {
        return emptyEnvelope<T>(headerRequestId);
      }
      throw toApiError(response.status, null, headerRequestId);
    }

    if (!response.ok || envelope.success === false) {
      throw toApiError(response.status, envelope, headerRequestId);
    }
    return envelope;
  }

  // — Auth (`/api/v1/auth/*`) —

  /** `POST /auth/register` — establish an identity (no session issued; user then verifies email). */
  register(input: RegisterInput): Promise<Envelope<RegisterResult>> {
    return this.request<RegisterResult>({ method: "POST", path: "/auth/register", body: input });
  }

  /** `POST /auth/login` — Sanctum cookie session (web) and/or a bearer token set (mobile/partner). */
  login(input: LoginInput): Promise<Envelope<LoginResult>> {
    return this.request<LoginResult>({ method: "POST", path: "/auth/login", body: input });
  }

  /** `POST /auth/logout` — clear the cookie session and revoke a presented refresh token. */
  logout(input: LogoutInput = {}): Promise<Envelope<null>> {
    return this.request<null>({ method: "POST", path: "/auth/logout", body: input });
  }

  /** `POST /auth/refresh` — rotate a bearer refresh token for a fresh access+refresh pair. */
  refresh(input: RefreshInput): Promise<Envelope<RefreshResult>> {
    return this.request<RefreshResult>({ method: "POST", path: "/auth/refresh", body: input });
  }

  /** `GET /auth/me` — identity, memberships, active company, permissions, and `perms_ver`. */
  me(): Promise<Envelope<AuthMe>> {
    return this.request<AuthMe>({ method: "GET", path: "/auth/me" });
  }

  /** `POST /auth/switch-company` — re-scope the active company; returns the new permission set. */
  switchCompany(input: SwitchCompanyInput): Promise<Envelope<SwitchCompanyResult>> {
    return this.request<SwitchCompanyResult>({
      method: "POST",
      path: "/auth/switch-company",
      body: input,
    });
  }

  /**
   * `POST /auth/email/verify` — confirm an email via its signed link. `id`/`hash` and the
   * `expires`/`signature` URL-signature params travel as the query string the signed middleware checks.
   */
  verifyEmail(input: VerifyEmailInput): Promise<Envelope<VerifyEmailResult>> {
    return this.request<VerifyEmailResult>({
      method: "POST",
      path: "/auth/email/verify",
      query: {
        id: input.id,
        hash: input.hash,
        expires: input.expires,
        signature: input.signature,
      },
    });
  }

  // — Onboarding (`/api/v1/companies`) —

  /** `POST /companies` — an email-verified user with zero companies creates one and becomes its Owner. */
  createCompany(input: CreateCompanyInput): Promise<Envelope<CreateCompanyResult>> {
    return this.request<CreateCompanyResult>({ method: "POST", path: "/companies", body: input });
  }

  // — Accounting: chart of accounts (`/api/v1/accounting/accounts`) —

  /**
   * `GET /accounting/account-types` — the seven global classifications (needs accounting.journal.read).
   * Read-only; the ids are database-generated, so a client that has to send `account_type_id` reads them
   * from here rather than hard-coding them.
   */
  accountTypes(companyId?: string): Promise<Envelope<AccountTypeListResult>> {
    return this.request<AccountTypeListResult>({ method: "GET", path: "/accounting/account-types", companyId });
  }

  /** `GET /accounting/accounts` — the flat chart, ordered by code (needs accounting.journal.read). */
  listAccounts(companyId?: string): Promise<Envelope<AccountListResult>> {
    return this.request<AccountListResult>({ method: "GET", path: "/accounting/accounts", companyId });
  }

  /** `GET /accounting/accounts/tree` — the chart nested under parents (needs accounting.journal.read). */
  accountTree(companyId?: string): Promise<Envelope<AccountTreeResult>> {
    return this.request<AccountTreeResult>({ method: "GET", path: "/accounting/accounts/tree", companyId });
  }

  /** `GET /accounting/accounts/{id}` — one account; a cross-tenant id resolves to 404. */
  getAccount(id: number, companyId?: string): Promise<Envelope<AccountResult>> {
    return this.request<AccountResult>({ method: "GET", path: `/accounting/accounts/${id}`, companyId });
  }

  /** `POST /accounting/accounts` — create an account (needs accounting.coa.manage). */
  createAccount(input: CreateAccountInput, companyId?: string): Promise<Envelope<AccountResult>> {
    return this.request<AccountResult>({ method: "POST", path: "/accounting/accounts", body: input, companyId });
  }

  /** `PATCH /accounting/accounts/{id}` — rename or renumber an account (needs accounting.coa.manage). */
  updateAccount(id: number, input: UpdateAccountInput, companyId?: string): Promise<Envelope<AccountResult>> {
    return this.request<AccountResult>({
      method: "PATCH",
      path: `/accounting/accounts/${id}`,
      body: input,
      companyId,
    });
  }

  /** `POST /accounting/accounts/{id}/reclassify` — change an account's type (needs accounting.coa.manage). */
  reclassifyAccount(id: number, input: ReclassifyAccountInput, companyId?: string): Promise<Envelope<AccountResult>> {
    return this.request<AccountResult>({
      method: "POST",
      path: `/accounting/accounts/${id}/reclassify`,
      body: input,
      companyId,
    });
  }

  /** `POST /accounting/accounts/{id}/deactivate` — deactivate an account (needs accounting.coa.manage). */
  deactivateAccount(id: number, companyId?: string): Promise<Envelope<AccountResult>> {
    return this.request<AccountResult>({
      method: "POST",
      path: `/accounting/accounts/${id}/deactivate`,
      companyId,
    });
  }
}

function emptyEnvelope<T>(requestId: string): Envelope<T> {
  return {
    success: true,
    data: null,
    message: null,
    errors: [],
    meta: { pagination: null },
    request_id: requestId,
    timestamp: new Date().toISOString(),
  };
}

/** Convenience factory mirroring the `createClient({...})` idiom. */
export function createClient(options: QaydClientOptions = {}): QaydClient {
  return new QaydClient(options);
}
