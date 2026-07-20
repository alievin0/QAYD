# Secrets & Key Management — QAYD Security
Version: 1.0
Status: Design Specification
Module: Security
Submodule: SECRETS
---

# Purpose

This document specifies, in security depth, how QAYD custodies its **secrets** — the credentials,
signing keys, encryption keys, and third-party tokens whose disclosure would let an attacker
impersonate the platform, forge identities, decrypt stored data, or move a customer's money. It is the
L8 (secret custody and key management) specialization of the layered model in
[SECURITY_ARCHITECTURE.md](SECURITY_ARCHITECTURE.md), the counterpart that supplies the *custody* half
of the encryption model whose *cryptographic construction* is owned by [ENCRYPTION.md](ENCRYPTION.md),
and the security-hardening companion to the authentication and authorization secrets referenced in
[AUTHENTICATION.md](AUTHENTICATION.md) and [AUTHORIZATION.md](AUTHORIZATION.md).

Custody of these secrets is custody of every identity and every token in the platform: whoever holds
the RS256 JWT signing key can mint a token for any user; whoever holds a field-KEK can decrypt a
tenant's bank numbers; whoever holds a bank-aggregator credential can read a company's transactions.
The security model therefore treats a secret's *storage, distribution, rotation, and revocation* as
first-class controls, not deployment trivia — and assumes that any single copy of a secret may
eventually leak, so no leak of one secret compromises another.

The platform facts this document builds on are fixed: QAYD runs a Laravel 12 (PHP 8.4+) backend and a
separate FastAPI AI engine; the Next.js web client is a public bundle that may contain **only**
`NEXT_PUBLIC_*` values; mobile/partner/AI callers use RS256 JWTs signed by a key that rotates every 90
days with a 14-day verify-only overlap; and encryption keys live in a KMS/vault, never beside the
ciphertext. Everything below hardens those facts against the threat model in
[SECURITY_ARCHITECTURE.md](SECURITY_ARCHITECTURE.md), with the cryptographic key hierarchy itself
deferred to [ENCRYPTION.md](ENCRYPTION.md).

---

# Threat Model / Principles

## The adversary at the secret boundary

Secret management defends against the **infrastructure adversary** (adversary class 5 in
[SECURITY_ARCHITECTURE.md](SECURITY_ARCHITECTURE.md)) — someone who reaches a repository, a CI log, a
container's environment, a backup, a browser bundle, or the secrets store itself. Concretely:

- **Secret in the repo** — an API key or `.env` value committed to git, present in history forever.
- **Secret in the frontend** — a backend key shipped in the Next.js bundle, readable by anyone with
  DevTools.
- **Secret in a log** — a token, password, or key printed into an application log, a CI transcript, or
  an error report.
- **Signing-key theft** — capture of the JWT private key, letting the attacker forge a token for any
  user in any company.
- **Third-party credential theft** — capture of a bank-aggregator token, the AI model-provider key, or
  the email/SMS credential, letting the attacker read financial data or send messages as QAYD.
- **Stale-secret abuse** — a leaked credential that never expires and is never rotated, so a
  compromise is permanent.
- **CI/CD exfiltration** — a malicious dependency or workflow step reading secrets from the build
  environment.

## Principles

- **No secret in the frontend, ever.** The browser bundle contains only public configuration
  (`NEXT_PUBLIC_*`). Every real secret lives server-side; the frontend obtains capability through
  short-lived, server-issued, scoped tokens — never a raw key.
- **No secret in source control.** Secrets resolve from the vault at runtime; a committed secret is a
  blocking CI failure and, if it reaches a remote, an incident (rotate first, investigate second).
- **No secret in a log.** The logging pipeline redacts secret-shaped and Restricted-classified fields;
  a secret that reaches a log is a defect and the secret is rotated regardless of blast-radius belief.
- **Keys never live beside what they protect.** A signing key, a field-KEK, and a third-party token
  live in KMS/vault, distributed to the process at boot or fetched on demand — never in the same repo,
  image, or database row as the data they secure.
- **Least privilege and blast-radius isolation.** Each service holds only the secrets it needs; a
  distinct secret per purpose and per environment means one leak compromises one thing, and rotation of
  one never forces rotation of all.
- **Rotate on a schedule and on suspicion.** Every long-lived secret has a rotation cadence and a
  non-breaking rotation path (overlapping validity); any suspected compromise triggers immediate
  rotation ahead of schedule.
- **Fail closed on a missing secret.** A missing or unfetchable secret aborts the operation loudly; the
  platform never falls back to an insecure default, a disabled check, or an unsigned path.

---

# Controls

## Secret classification and where each lives

QAYD sorts every secret into a small number of custody tiers, because "where a secret lives" is the
control that determines its blast radius.

| Secret class | Examples | Custody | Never in |
|---|---|---|---|
| Public config | `NEXT_PUBLIC_API_URL`, publishable analytics id | Frontend bundle (by definition public) | — |
| App runtime secret | DB password, Redis auth, app key, mail/SMS creds | Vault → injected as env at boot (server only) | Repo, image, frontend, logs |
| Identity/signing key | RS256 JWT private key, MFA secret-encryption key, CSRF/app key | KMS/vault; private key KMS-wrapped in `jwt_signing_keys` | Repo, frontend, logs, DB in cleartext |
| Encryption key material | KMS root CMKs, per-tenant field-KEKs, blind-index HMAC key | KMS (root never exported) — custody here, crypto in [ENCRYPTION.md](ENCRYPTION.md) | Anywhere outside KMS/vault |
| Third-party credential | Bank aggregator token, AI model-provider key, R2/S3 key | Vault; tenant-held aggregator creds encrypted at rest per-tenant | Repo, frontend, logs |
| Customer-provided secret | A tenant's own integration API key | Encrypted at rest (`integration_credentials`), shown once | Cleartext store, logs |

The rule the table encodes: **a secret's custody is chosen so that the worst place it could leak from
still does not compromise anything else.** A frontend value leaking is a non-event (it is public); an
app secret leaking from the vault is contained to one environment; a signing key leaking is the
highest-severity event and is why that key is KMS-wrapped, short-rotated, and never exported.

## No secrets in the frontend bundle

The Next.js web client is a **public artifact**. Anything bundled into it — including "server-only"
env vars accidentally referenced in client code — is readable by any visitor. QAYD enforces the split
structurally:

- Only `NEXT_PUBLIC_*`-prefixed values are available to client code; Next.js excludes all other env
  vars from the browser bundle by construction.
- A backend secret is **never** given a `NEXT_PUBLIC_` name to "make it reachable" — the frontend gets
  *capability*, not *keys*: it calls the Laravel API, which holds the real credential and returns a
  short-lived, scoped, server-issued token (a Sanctum session, a signed URL, an OAuth access token).
- The AI engine, bank aggregators, and model providers are reached **only** through the Laravel
  backend; the frontend never calls them directly and therefore never needs their keys.

```
# .env.local (frontend)  — ONLY public values may appear here.
NEXT_PUBLIC_API_URL=https://api.qayd.app/api/v1
NEXT_PUBLIC_APP_ENV=production
# NEXT_PUBLIC_ANTHROPIC_KEY=...   <-- FORBIDDEN. A model-provider key is a backend secret.
# ANTHROPIC_API_KEY=...           <-- also forbidden here: a non-public var in a frontend .env is a smell.
```

A CI check (below) fails the build if a non-`NEXT_PUBLIC_` secret name is referenced in client-side
code or if a secret-shaped value appears in a `NEXT_PUBLIC_` variable.

## JWT signing keys — RS256, 90-day rotation, 14-day verify overlap

Mobile, partner, and AI-engine callers present **RS256** JWTs. RS256 (asymmetric) is chosen over HS256
(symmetric) deliberately: the **private** key that *signs* tokens lives only in the backend's KMS
grant, while the **public** key that *verifies* them can be distributed freely (to the AI engine, to a
partner's introspection). A verifier that is compromised cannot mint tokens — it holds only the public
half. The full JWT claim set and refresh mechanics are owned by
[../api/AUTHENTICATION_API.md](../api/AUTHENTICATION_API.md); this document owns the *key custody and
rotation*.

**Key storage.** Signing keys are versioned rows in `jwt_signing_keys`; the private key is stored
**KMS-wrapped** (`private_key_enc`), never in cleartext, and is unwrapped into process memory only at
sign time. The public key is stored and served in cleartext (it is public).

```sql
CREATE TABLE jwt_signing_keys (
    id              BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    kid             VARCHAR(64)  NOT NULL UNIQUE,     -- key id echoed in the JWT header for verifier selection
    algorithm       VARCHAR(16)  NOT NULL DEFAULT 'RS256',
    public_key      TEXT         NOT NULL,            -- PEM; safe to publish (JWKS)
    private_key_enc  BYTEA       NOT NULL,            -- KMS-wrapped private key; unwrapped only at sign time
    status          VARCHAR(16)  NOT NULL DEFAULT 'active'
                      CHECK (status IN ('active','verify_only','retired')),
    activated_at    TIMESTAMPTZ  NOT NULL DEFAULT now(),
    verify_until    TIMESTAMPTZ  NULL,                -- set when demoted to verify_only (activation + 14 days)
    retired_at      TIMESTAMPTZ  NULL,
    created_at      TIMESTAMPTZ  NOT NULL DEFAULT now()
);
```

**Rotation cadence — 90 days, with a 14-day verify-only overlap.** A new keypair is generated (private
half wrapped by KMS) and promoted to `active`; the previous key is demoted to `verify_only` for 14 days
(`verify_until = now() + 14 days`) so tokens it already signed keep validating until they naturally
expire, then it is `retired`. New tokens are always signed by the single `active` key; verification
tries the `kid` named in the token header, accepting any `active` or non-expired `verify_only` key.
This makes rotation **non-breaking**: no in-flight token is invalidated by a rotation.

```php
// app/Services/Auth/JwtKeyManager.php — sign with the active key; verify against active + overlap.
public function sign(array $claims): string
{
    $key = $this->activeKey();                                  // exactly one row status='active'
    $privatePem = Kms::unwrap($key->private_key_enc);           // in-memory only, never logged/persisted
    return JWT::encode($claims + ['iss' => 'qayd', 'kid' => $key->kid], $privatePem, 'RS256', $key->kid);
}

public function verify(string $token): object
{
    $kid = JwtHeader::kid($token);
    $key = SigningKey::where('kid', $kid)
        ->where(fn ($q) => $q->where('status', 'active')
            ->orWhere(fn ($q2) => $q2->where('status', 'verify_only')->where('verify_until', '>', now())))
        ->firstOrFail();                                        // unknown/retired kid -> reject
    return JWT::decode($token, new Key($key->public_key, 'RS256'));
}
```

**On compromise.** A suspected signing-key leak triggers *immediate* rotation ahead of schedule: a new
`active` key is promoted, the suspect key is moved straight to `retired` (skipping the verify overlap),
and every outstanding token is invalidated by the session/`jti` revocation path in
[AUTHENTICATION.md](AUTHENTICATION.md). The public JWKS is republished so verifiers drop the retired
`kid`.

## Third-party credential storage

QAYD holds credentials for services it calls on a tenant's or the platform's behalf. Each is custodied
by blast radius:

| Credential | Held by | At-rest custody | Notes |
|---|---|---|---|
| Bank / open-banking aggregator token (per tenant) | Backend, per company | Encrypted at rest, RLS-locked to Treasury (see [ENCRYPTION.md](ENCRYPTION.md) `bank_credentials.secret`) | Reads a company's transactions; highest-value tenant secret |
| AI model-provider key (e.g. Anthropic) | Backend + AI engine via vault client | Vault; injected to the FastAPI process at boot | Never in the frontend; the browser reaches the model only through Laravel |
| Email / SMS provider (Mailgun / Twilio) | Backend | Vault → env at boot | Can send as QAYD; rotate on staff offboarding |
| R2 / S3 object-store key | Backend | Vault → env at boot | Signs short-TTL URLs; never handed to a client |
| Customer-supplied integration key | Backend, per company | `integration_credentials.api_key` encrypted; shown once | A tenant's own third-party key stored on their behalf |

Two rules govern all of them: **a third-party credential is never given to the frontend** (the browser
always goes through the backend), and **a tenant-held credential is encrypted per-tenant** so a
database leak yields ciphertext and a per-tenant crypto-shred (via the tenant's field-KEK) renders it
unrecoverable. The bank-aggregator secret is additionally RLS-locked to the Treasury function, so even
within the tenant only entitled roles can cause it to be used.

## Encryption-key custody

The cryptographic key hierarchy — KMS root CMKs, per-tenant field-KEKs, data-encryption keys, the
blind-index HMAC key, envelope construction, and the rotation *mechanics* — is owned in full by
[ENCRYPTION.md](ENCRYPTION.md). This document is the authority only for the **custody policy** that
document's crypto relies on:

- **The KMS root never leaves KMS.** It is used only to wrap/unwrap keys; QAYD code never sees the root
  in cleartext. Root operations (disable, re-key) require dual control.
- **Per-tenant field-KEKs enable cryptographic tenant deletion.** Destroying a company's field-KEK
  renders every one of that company's field-encrypted values permanently unrecoverable, everywhere —
  live, replica, and backup — the mechanism [ENCRYPTION.md](ENCRYPTION.md) relies on for erasure that
  row-deletion alone cannot satisfy.
- **Backups use an independent key.** The backup CMK is distinct from the live-volume CMK and rotated
  on its own schedule, so one key compromise never loses both live data and its backups.
- **Every KMS unwrap is audited.** Each unwrap is a KMS API call logged in the provider audit stream
  and mirrored into QAYD's audit log, so "who caused a DEK/KEK to be unwrapped, when, from which
  service" is answerable.

The rotation cadence table (root CMK annual, field-KEK annual/on-demand, DEK ≤1 year, TLS leaf ≤90
days, blind-index HMAC rarely-with-rebuild) is the shared contract in [ENCRYPTION.md](ENCRYPTION.md) §
Key Custody & Rotation, implemented by the runbooks in this document.

## Per-service vault access — least privilege by identity

A secrets store is only as strong as the access policy on it: if every process can read every secret,
one compromised process leaks all of them. QAYD grants each runtime identity a **read path to only the
secrets it needs**, so the blast radius of a single compromised service is bounded to that service's
own secrets.

| Runtime identity | May read | May never read |
|---|---|---|
| Laravel backend | App runtime secrets, JWT signing keys (unwrap grant), all third-party creds, KMS grants for field-KEKs | — (the backend is the trusted core; but see below) |
| FastAPI AI engine | Its own model-provider key + its mTLS client cert (via the shared vault client) | JWT private signing key, tenant field-KEKs, bank-aggregator creds, DB password |
| Queue workers (`qayd_worker`) | The subset needed for background work (DB, Redis, mail/SMS, R2) | JWT private signing key (workers do not mint tokens) |
| CI/CD deploy identity | Deploy-time secrets + the migrator DB path, via OIDC | Long-lived cloud keys, production app runtime secrets it does not deploy |

The load-bearing asymmetry is the **AI engine's grant**: it is deliberately *narrow*. The FastAPI
service is never given the JWT signing key, a tenant field-KEK, or a bank credential — so even a full
compromise of the AI engine (its larger, fuzzier attack surface) cannot forge a token, decrypt a
stored bank number, or read a company's transactions. It holds only its own model-provider key and its
mTLS client certificate, and reaches every business capability by calling the Laravel API as the acting
user (see [AUTHORIZATION.md](AUTHORIZATION.md) § the AI agent's scope). This is the secret-custody half
of the "AI is on the untrusted side of B2" posture in
[SECURITY_ARCHITECTURE.md](SECURITY_ARCHITECTURE.md).

Vault access itself is authenticated by workload identity (the OIDC-federated service identity, not a
static token), scoped by policy to the paths above, and every secret read is logged, so "which service
read which secret, when" is answerable during an incident.

## CI/CD secret handling

Build and deploy pipelines are a prime exfiltration target because they hold the keys to production.
QAYD's discipline:

- **Secrets are injected, never committed.** CI reads secrets from the platform secret store (GitHub
  Actions encrypted secrets / OIDC-federated cloud roles), never from the repo. The deploy step
  fetches runtime secrets from the vault at deploy time and injects them into the container
  environment; the image itself contains no secrets.
- **OIDC federation over long-lived keys.** The pipeline authenticates to the cloud/KMS with a
  short-lived, workflow-scoped OIDC token, so there is no long-lived cloud key in CI to steal.
- **Least-privilege pipeline identity.** The deploy credential can read only the secrets that
  deployment needs and can assume the `qayd_migrator` DB role only from the pipeline's firewalled path,
  never from the app tier.
- **Secrets are masked in logs.** CI is configured to mask registered secret values in transcripts; a
  step that echoes a secret is a defect caught in review.
- **No secret in build artifacts.** Source maps, bundles, and container layers are scanned; a secret in
  an artifact fails the release.

## `.env` / `.dev.vars` discipline

Local development mirrors production discipline so habits transfer:

- **`.env`, `.env.local`, `.dev.vars` are git-ignored** and never committed; a committed one is a
  blocking CI failure.
- **`.env.example` is the only committed template** — it lists every required variable name with a
  placeholder value and a comment, and contains **no real secret**. It is the contract for what a
  developer must obtain from the vault, not a source of values.
- **Local secrets are development-only** and distinct from any production value; a production secret
  never appears in a developer's `.env`. Where a shared dev secret is needed, it is fetched from a dev
  vault namespace, not passed around.
- **The FastAPI engine's local secrets** live in its own git-ignored env file, resolved from the same
  vault in production; the model-provider key is never shared into the frontend or committed.

## Secret detection and pre-commit hygiene

Detection is layered so a secret is caught before it ever reaches a remote:

- A **pre-commit hook** (`gitleaks`) blocks a commit containing a secret-shaped value locally.
- A **CI secret scan** (`gitleaks` on every push, plus provider-native scanning) fails the build and
  alerts if a secret reaches a remote, even on a feature branch.
- **Prefixed, greppable credentials.** QAYD-issued keys carry environment-tagged prefixes
  (`qak_live_…` / `qak_test_…`) so a leaked key is instantly greppable across logs and repos and its
  environment is obvious — and is hashed at rest, so QAYD never holds a usable copy to leak in the
  first place.

---

# Implementation

Secret flow at runtime is a single, auditable path from the vault to the process, and never the
reverse.

```
   Vault / KMS (source of truth; root never exported)
        │  boot-time injection (app runtime secrets)        on-demand unwrap (keys)
        ▼                                                    ▼
   Laravel process env  ── resolves ──▶  config/*.php  ── never echoed to logs/responses ──▶ use
        │                                                    │
        │  (RS256 sign: unwrap private_key_enc in-memory only)
        ▼
   Short-lived, scoped, server-issued tokens ──▶  Frontend / mobile / partner / AI
        (Sanctum session, signed URL, OAuth access token — capability, never a raw key)
```

- **The frontend receives capability, never keys.** Every browser-reachable secret path terminates at a
  server-issued short-lived token; the raw credential stays in the backend.
- **Keys are used, not moved.** A signing key's private half is unwrapped into memory at sign time and
  discarded; a field-KEK is unwrapped at encrypt/decrypt time (see [ENCRYPTION.md](ENCRYPTION.md)).
  Neither is written to disk, a log, or a response.
- **Every secret has an owner and a rotation clock.** The vault records each secret's purpose,
  environment, owner, and rotation cadence; a secret past its cadence is flagged.

---

# Enforcement Points

| Enforcement point | Mechanism | Defends | Detail in |
|---|---|---|---|
| Next.js bundle boundary | Only `NEXT_PUBLIC_*` reach the browser; CI rejects secret-shaped public vars | Secret-in-frontend | This document |
| Vault → env injection at boot | Secrets resolve at runtime, not from the image/repo | Secret-in-repo, secret-in-image | This document |
| `gitleaks` pre-commit + CI scan | Blocks secret-shaped values locally and on push | Secret committed to git | [SECURITY_ARCHITECTURE.md](SECURITY_ARCHITECTURE.md) § Testing |
| Log redaction pipeline | Restricted/secret-shaped fields scrubbed from every log line | Secret-in-log | [../api/API_LOGGING.md](../api/API_LOGGING.md), [ENCRYPTION.md](ENCRYPTION.md) |
| `JwtKeyManager` (KMS-wrapped `private_key_enc`) | Private key unwrapped in-memory only; RS256 asymmetric split | Signing-key theft, token forgery | [../api/AUTHENTICATION_API.md](../api/AUTHENTICATION_API.md) |
| KMS root non-export + dual control | Root never in code; dual-control on root ops | Root-key compromise | [ENCRYPTION.md](ENCRYPTION.md) |
| Per-tenant encrypted third-party creds | `bank_credentials.secret`/`integration_credentials.api_key` encrypted, RLS-locked | Third-party credential theft | [ENCRYPTION.md](ENCRYPTION.md), [TENANT_ISOLATION.md](TENANT_ISOLATION.md) |
| OIDC-federated CI identity | Short-lived, workflow-scoped; no long-lived cloud key in CI | CI/CD exfiltration | This document |
| `.env.example` only committed template | Real `.env`/`.dev.vars` git-ignored | Dev-secret leakage | This document |

The secret-custody layer (L8) underpins the fixed pipeline rather than sitting in it: authentication
verifies tokens signed by keys this document custodies, encryption uses keys whose custody this
document owns, and tenant-held credentials are protected by the isolation
[TENANT_ISOLATION.md](TENANT_ISOLATION.md) enforces.

---

# Failure Modes

The invariant is **fail closed**: a missing or unverifiable secret aborts the operation loudly; the
platform never degrades to an insecure default.

| Event | Fail-closed behavior |
|---|---|
| Vault unreachable at boot | Process refuses to start rather than run with missing/placeholder secrets |
| KMS unreachable at sign time | Token issuance aborts (`503`); no unsigned or weakly-signed token is emitted |
| JWT presented with an unknown/retired `kid` | Verification rejects the token (`401`); never accept an unrecognized signer |
| Signing key past 90 days, rotation not run | Alarm; new signing halts on the stale key rather than continuing indefinitely |
| Secret detected in a commit | Pre-commit/CI blocks; if already pushed, rotate the secret first, then investigate |
| Non-`NEXT_PUBLIC_` secret referenced in client code | Build fails; the bundle never ships with a backend secret |
| Third-party credential fetch fails | The dependent action fails loudly; never proceed with a blank/insecure credential |
| Secret would be logged | Redaction scrubs it; a bypass is a defect and the secret is rotated regardless |

Two failure modes deserve emphasis:

- **A leaked secret is rotated before it is understood.** The incident response (below) rotates first
  and investigates second, because the window between leak and rotation is the window of exposure —
  belief about blast radius is not a reason to delay rotation.
- **A missing secret never becomes an insecure default.** There is no code path where an absent signing
  key produces an unsigned token, an absent encryption key produces cleartext storage, or an absent
  third-party credential produces an unauthenticated call. Every such path aborts.

## Leaked-secret incident response

A documented, rehearsed runbook governs a suspected or confirmed secret exposure:

1. **Contain — rotate immediately.** Generate and promote a replacement; move the exposed secret to
   revoked/retired ahead of any scheduled cadence. For a signing key, promote a new `active` key and
   `retire` the suspect one skipping the verify overlap, then invalidate outstanding tokens via the
   revocation path in [AUTHENTICATION.md](AUTHENTICATION.md). For a tenant-held credential, revoke it
   with the third party and re-provision.
2. **Assess — scope the exposure.** Determine where the secret leaked (repo history, log, bundle, CI),
   what it could reach, and the exposure window from the audit trail (KMS unwrap stream, access logs).
3. **Eradicate — purge the copy.** Remove the secret from git history where applicable, purge affected
   logs/artifacts, and confirm the new secret is the only live one.
4. **Notify.** Trigger the platform's breach-handling process where regulated data or a customer's
   third-party credential was in scope, per the compliance obligations below.
5. **Prevent.** Add or tighten the detection that missed it (a new `gitleaks` rule, a redaction pattern,
   a bundle-scan case) and record the lesson.

A quarterly tabletop rehearses this runbook end to end; the rotation drills for the JWT keypair and the
DB credentials are part of the same cadence (see [SECURITY_ARCHITECTURE.md](SECURITY_ARCHITECTURE.md) §
Testing).

---

# Compliance

| Framework expectation | Control in this document |
|---|---|
| SOC 2 — logical access, secret management, change management | Vault-custodied secrets, least-privilege pipeline identity, rotation cadences |
| ISO 27001 (A.9 / A.10) — key management, cryptographic controls | KMS-wrapped signing keys, documented rotation, key-custody policy for [ENCRYPTION.md](ENCRYPTION.md) |
| GDPR / GCC data-protection — breach handling | Leaked-secret incident-response runbook; per-tenant encrypted third-party creds; crypto-shred |
| PCI-adjacent posture | No secrets in the frontend; account credentials encrypted, never in logs/URLs; rotation |
| Kuwait / GCC regimes | Regional key/data residency (deferred to [ENCRYPTION.md](ENCRYPTION.md)); auditable key access |

QAYD does not claim certification here; it claims that the controls a certification would audit —
secrets out of source and the frontend, keys in KMS with documented rotation, a rehearsed leaked-secret
runbook, and least-privilege CI — are specified, enforced, and testable. The secret-classification
table is the spine an auditor traces from "manage cryptographic keys and credentials" to the exact
custody, cadence, and revocation path for each secret.

---

# Testing & Verification

Secret hygiene is proven by continuous scanning and rehearsed drills, not by trust.

**Static gates (CI, block merge):**

- `gitleaks` runs pre-commit and on every push; a secret-shaped value fails the build.
- A frontend-bundle scan asserts no non-`NEXT_PUBLIC_` secret name is referenced in client code and no
  `NEXT_PUBLIC_` variable holds a secret-shaped value.
- A build-artifact scan (bundles, source maps, container layers) fails the release if a secret is
  present.
- A config lint asserts production resolves every secret from the vault (no committed `.env` values,
  `APP_DEBUG=false`, `.env`/`.dev.vars` git-ignored, `.env.example` free of real values).

**Dynamic / behavioral tests (must be green):**

- **JWT rotation:** a token signed by a key demoted to `verify_only` still verifies within the 14-day
  window and is rejected after `verify_until`; a token with a `retired`/unknown `kid` is rejected; new
  tokens are signed only by the single `active` key.
- **Fail-closed:** a simulated vault/KMS outage causes the process to refuse startup / token issuance
  rather than run with missing or placeholder secrets.
- **Log redaction:** no key, token, password, or Restricted-classified value appears in any log
  formatter output (asserted via a log-scrubbing test).
- **Frontend leakage:** a build that references a backend secret in client code fails; the produced
  bundle is asserted to contain no secret-shaped value.

**Periodic verification:** quarterly rotation drills for the JWT keypair and DB credentials; a quarterly
leaked-secret tabletop exercising the incident-response runbook end to end; a review of the vault's
secret inventory confirming each secret has an owner and is within its rotation cadence; and external
penetration testing scoped to secret exposure (repo history, bundle, CI logs) before major releases.

The acceptance bar: every secret can be traced to its custody tier, its rotation clock, and its
revocation path; a scan proves no secret is in source, the frontend, or a log; and the leaked-secret
runbook has been rehearsed. Where any of these cannot be shown, the gap is a defect, not a
documentation nicety.

---

# End of Document
