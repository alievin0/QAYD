# Encryption — QAYD Security
Version: 1.0
Status: Design Specification
Module: Security
Submodule: ENCRYPTION
---

# Purpose

QAYD is a multi-tenant, AI-native accounting platform. A single PostgreSQL cluster, one Redis
fleet, and one Cloudflare R2 bucket namespace hold the general ledgers, bank credentials, payroll
records, and tax identifiers of every company on the platform. The blast radius of a plaintext
leak is regulated financial data across thousands of unrelated tenants. Encryption is the control
that makes a leak of *storage* — a stolen disk, a leaked backup, a mis-scoped object key, a
subpoenaed replica — not automatically a leak of *data*.

This document is the authoritative specification for cryptography across QAYD: what is encrypted,
where, with which primitive, under which key, and how those keys are born, custodied, rotated, and
destroyed. It defines four concentric layers — transport, storage-at-rest, application-level field
encryption, and the key hierarchy that governs the third — and it draws the line, precisely, between
what encryption protects and what it does not. It complements rather than restates the sibling
specifications: transport enforcement as it appears at the API edge is detailed in
[../api/API_SECURITY.md](../api/API_SECURITY.md) and [./API_SECURITY.md](./API_SECURITY.md); tenant
isolation inside the database is [../database/ROW_LEVEL_SECURITY.md](../database/ROW_LEVEL_SECURITY.md);
key custody, the secrets vault, and rotation runbooks are [./SECRETS.md](./SECRETS.md); and the
foundational one-line mandate — *"Everything encrypted"* — is
[../foundation/SECURITY_ARCHITECTURE.md](../foundation/SECURITY_ARCHITECTURE.md). Where those
documents fix a fact (TLS 1.2 floor, AES-256 at rest, Argon2id passwords, the `/api/v1` envelope,
the `X-Company-Id` tenant header), this document specifies how the cryptography is actually
constructed and operated, in code that runs on Laravel 12 / PHP 8.4, PostgreSQL, and the FastAPI AI
engine.

The audience is the backend engineers who annotate Eloquent models with encrypted casts, the
platform engineer who provisions KMS keys and configures at-rest encryption, the security engineer
who owns rotation, and the auditor who must be shown that a stolen backup file is inert.

# Threat Model / Principles

Encryption defends a specific set of threats and is deliberately silent on others. Naming both is
the first control, because the most common cryptographic failure in a system like QAYD is not a
weak cipher — it is encrypting the wrong layer and believing the job is done.

**Threats encryption is responsible for:**

| # | Threat | Layer that answers it |
|---|--------|-----------------------|
| T1 | Passive interception of data on the wire (rogue Wi-Fi, transit provider, internal hop) | Transport (TLS 1.2+/1.3, mTLS service-to-service) |
| T2 | Theft of a physical disk, EBS volume, or database storage snapshot | Storage-at-rest (block/volume encryption) |
| T3 | Leak of an object-storage object or a database backup artifact | At-rest + backup encryption with independent keys |
| T4 | A DBA, support engineer, or read-replica consumer who can `SELECT` a sensitive column but should never see its cleartext | Application-level field encryption |
| T5 | A stolen bearer token or password-hash dump used to impersonate a user offline | Password hashing (Argon2id), not reversible encryption |
| T6 | Tampering with an object URL to reach a file the caller is not entitled to | Signed, expiring URLs (integrity + authorization, not confidentiality alone) |
| T7 | Compromise of the server's TLS private key later used to decrypt captured traffic | Forward secrecy (ECDHE) |

**Threats encryption does NOT answer** — and which are therefore explicitly delegated elsewhere:

- An authenticated request from a legitimately-logged-in user for a company's own data. Encryption
  is transparent to a valid query; confidentiality against a valid caller is the job of RBAC
  ([../foundation/PERMISSION_SYSTEM.md](../foundation/PERMISSION_SYSTEM.md)) and RLS.
- A live application compromise (RCE in the Laravel process). An attacker who owns the running PHP
  process can ask the application to decrypt, exactly as the application legitimately does. Field
  encryption raises the cost (keys are held in a vault, not on disk next to the ciphertext) but does
  not make a live-process compromise harmless. That is the domain of the audit trail, anomaly
  detection, and least-privilege service accounts.
- AI leaking data it was legitimately given. The AI engine only ever receives cleartext the calling
  user is authorized to see; preventing the AI from re-surfacing it improperly is
  [./AI_SECURITY.md](./AI_SECURITY.md) and [./DATA_PRIVACY.md](./DATA_PRIVACY.md), not this document.

**Governing principles:**

1. **Encrypt every layer independently.** Transport, at-rest, and field encryption are additive, not
   alternatives. A field-encrypted column still lives on an at-rest-encrypted volume behind TLS.
   Removing any one layer is a downgrade, never a simplification.
2. **Keys never live beside ciphertext.** The key that decrypts a column is never stored in the same
   PostgreSQL row, table, database, or backup as the ciphertext it protects. It lives in the KMS /
   vault ([./SECRETS.md](./SECRETS.md)).
3. **Envelope everything above the block layer.** Data is encrypted with a short-lived data key; the
   data key is encrypted with a long-lived key-encryption key held in KMS. Rotating the outer key
   never requires re-encrypting the data (see `# Key Hierarchy & Envelope Encryption`).
4. **Authenticated encryption only.** Every symmetric operation uses an AEAD construction
   (AES-256-GCM or XChaCha20-Poly1305). A cipher without an authentication tag is banned platform-wide;
   confidentiality without integrity is treated as no encryption at all.
5. **Hash what you never need back; encrypt what you do.** Passwords and lookup tokens are hashed
   (one-way). Payroll amounts, bank credentials, and tax IDs are encrypted (reversible, because the
   business must display and compute on them). Choosing hashing where encryption is needed, or vice
   versa, is a design defect, not a preference (see `# Hashing vs Encryption`).
6. **Fail closed.** A missing key, a failed KMS call, or an authentication-tag mismatch aborts the
   operation and raises an incident. QAYD never silently returns ciphertext, a null, or a
   best-effort plaintext on a crypto failure (see `# Failure Modes`).

# Controls: Encryption In Transit

QAYD terminates no plaintext HTTP anywhere in the request path. The full TLS posture — the 1.2
floor with 1.3 preferred, the AEAD-only cipher allow-list, ECDHE-mandatory forward secrecy, HSTS
with preload, OCSP stapling, and mobile certificate pinning — is specified once in
[../api/API_SECURITY.md](../api/API_SECURITY.md) § Transport Security and is binding here by
reference. This section records only the crypto-specific facts and the paths that document does not
own.

**TLS floor and rationale (summary):**

| Property | Setting | Why |
|---|---|---|
| Minimum protocol | TLS 1.2; TLS 1.3 negotiated whenever offered | 1.0/1.1 have known weaknesses and are rejected at the edge |
| Cipher policy | AEAD only: `TLS_AES_256_GCM_SHA384`, `TLS_CHACHA20_POLY1305_SHA256`, `TLS_AES_128_GCM_SHA256`; TLS 1.2 fallback `ECDHE-RSA-AES256-GCM-SHA384` | No CBC, no RC4, no 3DES, no static-RSA key exchange |
| Key exchange | ECDHE only | Perfect Forward Secrecy — answers T7 |
| HSTS | `max-age=63072000; includeSubDomains; preload` | Prevents downgrade after first contact |

**The four TLS paths in QAYD, all of which must satisfy the floor:**

1. **Client → edge → Laravel.** Browsers/Flutter/integrations to the Cloudflare edge, which
   terminates TLS and re-originates to origin over TLS. Laravel re-validates `X-Forwarded-Proto` and
   rejects any request that reached PHP-FPM over anything but HTTPS.
2. **Laravel ↔ FastAPI AI engine.** The backend proxies every AI call; the frontend never calls the
   AI engine directly. This hop is TLS *and* mutual-TLS: the AI engine presents a client certificate
   issued by QAYD's internal CA, and Laravel verifies it before routing — so a network-level foothold
   in the private subnet still cannot impersonate the AI engine (defense in depth atop the signed
   service JWT). Symmetric: the AI engine verifies Laravel's server certificate against a pinned
   internal-CA root.
3. **Laravel ↔ PostgreSQL / Redis.** Intra-VPC connections use TLS with `sslmode=verify-full`
   (Postgres) and `tls` with certificate verification (Redis). "Private network" is never accepted as
   a substitute for transport encryption; a compromised neighbor in the VPC must not be able to sniff
   ledger rows in flight.
4. **Laravel ↔ Cloudflare R2 / KMS / Mailgun / Twilio.** All third-party API calls are HTTPS with
   certificate validation on; certificate validation is never disabled "to fix a handshake error" —
   such an error is triaged, not bypassed.

```php
// config/database.php — Postgres over verified TLS, never trust-on-first-use.
'pgsql' => [
    'driver'   => 'pgsql',
    'sslmode'  => 'verify-full',                 // verify hostname AND chain
    'sslrootcert' => env('PG_SSL_ROOT_CERT'),     // pinned internal CA bundle
    // ...
],
```

# Controls: Encryption At Rest

At-rest encryption answers T2 and T3: a stolen volume, snapshot, or backup file is ciphertext to
anyone without the KMS key. It is provided by the storage substrate, keyed from KMS, and applies
uniformly to three stores.

| Store | What it holds | At-rest mechanism | Key source |
|---|---|---|---|
| PostgreSQL (primary + replicas) | All tenant ledger/master/payroll/tax data | Encrypted volumes (AWS EBS with KMS in production; LUKS/dm-crypt on Hetzner in dev) | KMS CMK `qayd/db-volume` |
| Cloudflare R2 / S3 | Invoices, PDFs, exports, AI attachments | Server-side encryption (SSE) with a customer-managed key | KMS CMK `qayd/object-store` |
| Redis | Sessions, queue payloads, cache, AI-memory cache | Encrypted volume + TLS; no long-lived secrets persisted in cleartext keyspace | KMS CMK `qayd/cache-volume` |
| Backups (DB + object) | Point-in-time and periodic snapshots | Encrypted independently of the live volume, with a *distinct* key | KMS CMK `qayd/backup` |

**Two facts make this more than a checkbox:**

- **Backups use an independent key.** A backup encrypted with the same key as the live volume is a
  single point of failure: one key compromise loses both. QAYD's backup CMK (`qayd/backup`) is
  distinct, separately access-controlled, and rotated on its own schedule. The hourly/daily/weekly/
  monthly cadence in [../foundation/SECURITY_ARCHITECTURE.md](../foundation/SECURITY_ARCHITECTURE.md)
  inherits this encryption automatically — a restored backup is only usable by a principal that can
  call the backup key.
- **At-rest volume encryption is transparent to queries.** It defends the disk, not the query. A DBA
  with a valid session reads cleartext through the encrypted volume exactly as the application does.
  That gap — the privileged reader — is precisely what the next layer, field encryption, closes for
  the columns that warrant it.

# Controls: Application-Level Field Encryption

Volume encryption stops at the query boundary; a support engineer, a read-replica BI consumer, a
leaked `pg_dump`, or a raw `SELECT` still sees cleartext for every column. For the platform's most
sensitive columns, QAYD encrypts the value *inside the application* before it is written, so the
column holds ciphertext even to a caller who can read the raw row. This answers T4.

**Which columns are field-encrypted.** The rule follows the data classification in
[./DATA_PRIVACY.md](./DATA_PRIVACY.md): every column classified **Restricted**, plus **Confidential**
columns that carry standalone financial-account or government-identifier risk.

| Table.Column | Class | Encrypted | Notes |
|---|---|---|---|
| `bank_accounts.account_number` | Restricted | Yes | Plus a blind index for reconciliation matching (see below) |
| `bank_accounts.iban` | Restricted | Yes | — |
| `bank_credentials.secret` | Restricted | Yes | Online-banking / open-banking tokens; encrypted then the row is RLS-locked to Treasury |
| `employees.national_id` | Restricted | Yes | Civil ID / Iqama / national number |
| `employees.passport_no` | Restricted | Yes | — |
| `employees.bank_account_number` | Restricted | Yes | Payroll disbursement target |
| `payroll_items.gross`, `.net`, `.deductions` | Restricted | Yes | Individual salary figures; aggregates are computed post-decrypt in the Service layer |
| `companies.tax_id`, `parties.tax_id` | Confidential | Yes | Company/vendor/customer tax registration numbers |
| `users.mfa_secret`, `.mfa_recovery_codes` | Restricted | Yes | TOTP seed and recovery codes |
| `integration_credentials.api_key` | Restricted | Yes | Third-party keys stored on behalf of a tenant |

Ordinary financial data (a posted journal line's amount, an invoice total) is **not** field-encrypted:
it must be aggregated, indexed, and reported on constantly, RLS already scopes it per-tenant, and
encrypting it would forfeit query-ability for a threat (privileged-reader) that the ledger's own
audit trail already covers. Field encryption is reserved for identifiers and credentials whose
cleartext value has direct standalone abuse potential.

**Primitive and construction.** Field encryption uses **XChaCha20-Poly1305** (AEAD, 24-byte random
nonce, no nonce-reuse risk at QAYD's write volume) via `libsodium` (`sodium_crypto_aead_*`), which
ships with PHP 8.4. AES-256-GCM is an accepted alternative where a FIPS-validated module is required
by a specific customer; the cast is pluggable so the primitive is a configuration choice, not a code
rewrite. The per-value **associated data (AAD)** binds the ciphertext to its context —
`company_id : table : column : row_id` — so a ciphertext lifted from one row/company and pasted into
another fails authentication and is rejected. This makes field encryption a tenant-isolation control
as well as a confidentiality one.

```php
// app/Casts/EncryptedField.php — an Eloquent cast that encrypts on write, decrypts on read,
// binds the ciphertext to its row/company context via AAD, and fails closed.
namespace App\Casts;

use App\Crypto\FieldCipher;                       // wraps libsodium + the envelope key manager
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

final class EncryptedField implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }
        // AAD binds this ciphertext to exactly this company + table + column + row.
        $aad = self::aad($model, $key);
        return FieldCipher::decrypt($value, $aad);  // throws on tag mismatch -> fail closed
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        if ($value === null) {
            return [$key => null];
        }
        $aad = self::aad($model, $key);
        return [$key => FieldCipher::encrypt((string) $value, $aad)];
    }

    private static function aad(Model $model, string $key): string
    {
        // company_id is always present on tenant tables; getKey() is the row PK.
        return implode(':', [
            $model->getAttribute('company_id'),
            $model->getTable(),
            $key,
            $model->getKey() ?? 'new',
        ]);
    }
}
```

```php
// app/Models/Employee.php — declaring encrypted fields is a one-line annotation per column.
protected function casts(): array
{
    return [
        'national_id'         => \App\Casts\EncryptedField::class,
        'passport_no'         => \App\Casts\EncryptedField::class,
        'bank_account_number' => \App\Casts\EncryptedField::class,
    ];
}
```

**Searching encrypted columns — the blind index.** A field-encrypted column cannot be queried with
`WHERE account_number = ?` because equal plaintexts produce different ciphertexts (random nonce).
For the few columns that need equality lookup (bank reconciliation matching an incoming statement
line to a stored account), QAYD stores a **blind index**: a keyed HMAC-SHA-256 of the normalized
plaintext in a sibling column (`account_number_bidx`), computed with a *separate* index key. The
index enables exact-match lookup without ever storing or exposing the plaintext, and because the
HMAC key differs from the encryption key, leaking the index does not reveal the values and leaking
the ciphertext does not reveal the index. Range queries and `LIKE` are not supported on encrypted
columns by design — a requirement for either is a signal that the column was mis-classified.

```php
// Deterministic, keyed index for exact-match lookup on an encrypted column.
$bidx = hash_hmac('sha256', FieldCipher::normalize($accountNumber), FieldCipher::indexKey());
$match = BankAccount::where('company_id', $companyId)
    ->where('account_number_bidx', $bidx)         // indexed; never decrypts a whole table
    ->first();
```

# Key Hierarchy & Envelope Encryption

QAYD never encrypts data directly with a long-lived key. It uses a three-tier hierarchy so that the
key protecting the data is short-lived and cheap to rotate, while the root of trust lives in
hardware-backed KMS and is never exported.

```
                +-------------------------------------------------------+
   Tier 0       |  KMS Root / HSM-backed CMK (per purpose):             |
   (root)       |  qayd/master, qayd/db-volume, qayd/object-store,      |
                |  qayd/backup, qayd/field-kek                          |
                |  - never leaves KMS; used only to wrap/unwrap keys    |
                +-----------------------------+-------------------------+
                                              | wraps (encrypts)
                                              v
   Tier 1       +-------------------------------------------------------+
   (KEK)        |  Key-Encryption Keys, per tenant + per column family: |
                |  field-KEK(company_id)  <- unwrapped on demand by KMS |
                +-----------------------------+-------------------------+
                                              | wraps
                                              v
   Tier 2       +-------------------------------------------------------+
   (DEK)        |  Data-Encryption Keys — the key that actually         |
                |  encrypts a ciphertext. Random 256-bit, cached in     |
                |  memory only, per company/column family.              |
                +-------------------------------------------------------+
```

**How an encrypt works (envelope):**

1. The `FieldCipher` needs the Tier-2 DEK for `(company_id, column-family)`. If not in the in-process
   cache, it fetches the *wrapped* DEK from the `data_keys` table (ciphertext only — the wrapped DEK
   is safe to store beside the data because it is itself encrypted).
2. It calls KMS `Decrypt` on the wrapped DEK using the Tier-1 field-KEK for that company. KMS returns
   the plaintext DEK into process memory. KMS never sees the actual ledger data — only key material.
3. The DEK encrypts the value with XChaCha20-Poly1305 + AAD. The DEK is held in a short-TTL memory
   cache (minutes), never written to disk, never logged.

**Why envelope encryption is the design:**

- **Rotation is cheap.** Rotating a Tier-1 KEK re-wraps the Tier-2 DEKs (a few thousand small
  ciphertexts), not the millions of encrypted business values. Rotating the KMS root re-wraps the
  KEKs. The data itself is only ever re-encrypted on a deliberate, throttled background job when a
  DEK is retired — never as an emergency.
- **Per-tenant KEKs enable cryptographic tenant deletion.** Destroying company X's field-KEK renders
  every one of company X's field-encrypted values permanently unrecoverable, everywhere it exists —
  live, replica, and backup — without touching another tenant. This is the mechanism
  [./DATA_PRIVACY.md](./DATA_PRIVACY.md) relies on to satisfy erasure obligations that cannot be met
  by row deletion alone (crypto-shredding).
- **KMS is the single, audited chokepoint.** Every unwrap is a KMS API call, logged in CloudTrail /
  the KMS audit stream and mirrored into QAYD's own audit stream ([./AUDIT_LOGS.md](./AUDIT_LOGS.md)),
  so "who caused a DEK for company X to be unwrapped, when, and from which service" is answerable.

# Key Custody & Rotation

Key custody, the vault topology, IAM grants on each CMK, dual-control for root operations, and the
step-by-step rotation runbooks are owned by [./SECRETS.md](./SECRETS.md). This section fixes only the
cryptographic policy that document implements.

| Key | Tier | Rotation cadence | Rotation type | On compromise |
|---|---|---|---|---|
| KMS root CMKs | 0 | Annual (KMS-managed automatic rotation) | Re-wrap KEKs; no data re-encryption | Disable key version; re-key from HSM; incident |
| `field-KEK(company_id)` | 1 | Annual, or on demand | Re-wrap that company's DEKs | Re-wrap immediately; audit all unwraps in window |
| Data-Encryption Keys | 2 | On KEK rotation, or per policy (≤ 1 year active) | New DEK; lazy re-encrypt on next write; batch job for the rest | Retire DEK; force re-encrypt of its ciphertexts |
| TLS leaf certificates | — | ≤ 90 days (ACME automated) | New cert; two pins shipped to mobile ahead of time | Revoke + reissue; rotate pin set |
| Blind-index HMAC key | — | Rarely (requires full index rebuild) | New key; rebuild `*_bidx` columns in background | Rebuild indexes under new key |
| Password hashes | — | Not rotated (one-way); re-hashed on next login if params change | See `# Password Hashing` | Force reset affected accounts |

**Rotation is always non-breaking.** Every ciphertext carries a header identifying the key version
and algorithm that produced it (`v1:xchacha20poly1305:kek3:...`), so old and new keys coexist during
a rotation window. Decryption selects the key named in the header; encryption always uses the current
key. A value is re-encrypted to the new key opportunistically on its next write, with a throttled
background sweep finishing the long tail. No rotation ever requires downtime or a big-bang
re-encryption.

```
# Ciphertext envelope wire format (base64-encoded fields, ':'-delimited header):
  v1 : xchacha20poly1305 : <kek_id> : <dek_id> : <nonce_b64> : <ciphertext+tag_b64>
  ^ver ^algo                ^which KEK  ^which DEK  ^24 bytes     ^AEAD output
```

# Hashing vs Encryption

The single most consequential crypto decision in QAYD is per-field: hash (one-way, never
recoverable) or encrypt (reversible, because the business must read it back). Getting it backwards
is a security defect either way — an encrypted password is a reversible secret waiting to leak; a
hashed tax ID is a value the payroll module can never display.

| Data | Decision | Primitive | Reason |
|---|---|---|---|
| User login password | **Hash** | Argon2id | Never needs recovery; only verified. Reversibility would be a liability. |
| MFA TOTP seed | **Encrypt** | XChaCha20-Poly1305 | Must be read back to compute the current code. |
| API key / integration token (verification) | **Hash** for lookup + **encrypt** if displayable | SHA-256 (lookup), AEAD (if shown once) | Presented tokens are hashed to compare; a token QAYD must re-display is encrypted. |
| Bank account number, IBAN, national ID, salary | **Encrypt** | XChaCha20-Poly1305 | Must be displayed, matched, and computed on. |
| Blind index for encrypted lookup | **Keyed hash** | HMAC-SHA-256 | One-way equality check without exposing plaintext. |
| Audit-log tamper-evidence chain | **Hash** | SHA-256 (hash chain) | Integrity, not confidentiality — see [./AUDIT_LOGS.md](./AUDIT_LOGS.md). |
| Idempotency key fingerprint | **Hash** | SHA-256 | Deduplication, not secrecy. |

Rule of thumb, stated as a test: *if the business ever needs the original value back, encrypt; if it
only ever needs to confirm a presented value matches, hash.* A column that is hashed but later needs
displaying was mis-designed; a column that is encrypted but only ever compared should have been
hashed (encrypting invites the reversible-secret risk for no benefit).

## Password Hashing

Passwords use **Argon2id**, the mandate fixed in
[../foundation/SECURITY_ARCHITECTURE.md](../foundation/SECURITY_ARCHITECTURE.md), configured through
Laravel's hashing layer. Argon2id is memory-hard, defeating GPU/ASIC cracking rigs in a way that
PBKDF2 and bcrypt do not at equivalent cost.

```php
// config/hashing.php
'driver' => 'argon2id',
'argon'  => [
    'memory'  => 65536,   // 64 MiB per hash — memory-hard, tuned to ~250ms on prod hardware
    'threads' => 4,
    'time'    => 4,        // iterations
],
```

Operational rules: parameters are tuned so a single hash takes ~250ms on production hardware and
re-benchmarked as hardware improves; on successful login the stored hash is checked with
`Hash::needsRehash()` and transparently upgraded if the cost parameters have increased since it was
written; hashes are stored only in `users.password` and are never logged, never returned in an API
response, and are field-classified Restricted; and login comparison uses the constant-time verify
path (`Hash::check`), never a `==` on the hash string.

# Signed URLs

Object storage (R2/S3) is private: no bucket, prefix, or object is publicly readable, and no long-
lived public link is ever minted. Every download or upload of an invoice, export, or AI attachment
is authorized by a **signed, short-lived URL** issued by the Laravel API after the standard
authenticate → resolve-tenant → authorize pipeline has confirmed the caller may touch that specific
object.

```php
// Issue a time-boxed, tenant-checked download URL. The URL grants access to ONE object for
// a few minutes; it is not a capability that outlives the check that produced it.
public function downloadUrl(Attachment $att): string
{
    $this->authorize('documents.read', $att);       // RBAC + ownership; 404 (not 403) on cross-tenant
    abort_unless($att->company_id === activeCompany()->id, 404);

    return Storage::disk('r2')->temporaryUrl(
        $att->object_key,
        now()->addMinutes(5),                        // short TTL — a leaked link expires fast
        ['ResponseContentDisposition' => 'attachment']
    );
}
```

Signed-URL policy: TTL is minutes, never hours or days — a leaked link is a small, expiring window,
not a permanent grant; the object key is an opaque UUID, never a guessable path like
`company/12/payroll/2026-01.pdf`, so a signature cannot be repurposed onto a neighboring object by
editing the path; every signed-URL *issuance* (not the S3 GET itself, which QAYD cannot see) is
audited with actor, object, and expiry, so "who was granted access to payroll file X" is answerable;
uploads use signed `PUT` URLs equally scoped and TTL'd, and the resulting object is virus-scanned and
type-validated before it is linked to any business record; and cross-tenant object requests return
404, never 403, so an attacker cannot confirm an object's existence by probing.

# Crypto Primitives & Libraries

QAYD standardizes on a small, boring, well-reviewed set. Rolling custom cryptography, choosing a
non-AEAD cipher, or using ECB mode anywhere is prohibited and is a blocking finding in review.

| Purpose | Primitive | Library / API | Notes |
|---|---|---|---|
| Transport | TLS 1.2+/1.3, ECDHE, AEAD suites | Edge (Cloudflare) + OpenSSL at origin | Config, not code — see [../api/API_SECURITY.md](../api/API_SECURITY.md) |
| Field encryption | XChaCha20-Poly1305 (AEAD) | `libsodium` (`sodium_crypto_aead_xchacha20poly1305_ietf_*`) in PHP 8.4 | AES-256-GCM alternative behind the same cast |
| Envelope / KMS | AES-256-GCM key wrapping | AWS KMS (prod), Vault Transit (portable) | Root never exported |
| Password hashing | Argon2id | Laravel `Hash` (`argon2id` driver) | Memory-hard, auto-rehash |
| Blind index / MAC | HMAC-SHA-256 | PHP `hash_hmac` | Separate key from encryption |
| Tamper-evidence | SHA-256 hash chain | PHP `hash` | Integrity only |
| Random material | CSPRNG | `random_bytes`, `sodium_*` (never `rand`/`mt_rand`) | All nonces, keys, tokens |
| Signed URLs | HMAC-signed presigned URLs | R2/S3 SDK `temporaryUrl` | Short TTL |
| Python (AI engine) side | XChaCha20-Poly1305, HMAC | `cryptography` / `pynacl` | The AI engine holds NO tenant DEKs; see below |

```python
# AI engine (FastAPI): the AI service never decrypts tenant field-encrypted data. It receives
# only cleartext the Laravel backend already decided the caller may see, over mTLS. Any crypto in
# the AI engine is limited to its OWN secrets (model-provider keys) via the shared vault client.
from cryptography.hazmat.primitives.ciphers.aead import ChaCha20Poly1305  # AEAD, if ever needed
# There is intentionally no code path here that fetches a tenant field-KEK. That is by design:
# the AI trust boundary (see ../ai and ./AI_SECURITY.md) keeps key custody in the backend only.
```

The asymmetry in the last row is deliberate and load-bearing: field-encryption keys live only in the
Laravel backend's KMS grant. The FastAPI AI engine is never given a tenant DEK or field-KEK, so even
a full compromise of the AI service cannot decrypt a single stored bank number or salary — it can
only ever see the specific cleartext the backend chose to hand it for one request. Encryption thus
reinforces the AI trust boundary specified in [./AI_SECURITY.md](./AI_SECURITY.md).

# Enforcement Points

Encryption is enforced by construction and by CI, not by developer discipline alone.

| Point | Enforcement |
|---|---|
| Transport | Edge rejects sub-TLS-1.2; Laravel rejects non-HTTPS `X-Forwarded-Proto`; DB/Redis connections pinned to verified TLS in config |
| At rest | Volumes and buckets provisioned by Terraform with KMS encryption mandatory; a plaintext volume/bucket fails the infra policy check |
| Field columns | A CI check parses migrations: any column whose name matches the Restricted-pattern list (`*national_id`, `*account_number`, `*_secret`, `salary*`, `iban`, `passport*`, `mfa_secret`) MUST have an `EncryptedField` cast on its model, or the build fails |
| Primitives | Static analysis bans `openssl_encrypt` without AEAD mode, `ECB`, `md5`/`sha1` for secrets, and `rand`/`mt_rand`/`uniqid` for security material |
| Keys | Secret-scanning in CI blocks any commit containing key material; keys resolve only from the vault at runtime ([./SECRETS.md](./SECRETS.md)) |
| Signed URLs | Storage facade wrapper forbids `->url()` (permanent) on private disks; only `temporaryUrl` with a TTL passes review |

# Data Handling

- **In memory:** decrypted values live only for the duration of the request/computation that needs
  them and are not cached in Redis in cleartext. DEKs live in a short-TTL in-process cache, never in
  shared cache.
- **In logs:** no encrypted value's plaintext, no key, and no password ever enters a log line. The
  logging pipeline redacts the Restricted-pattern fields ([./DATA_PRIVACY.md](./DATA_PRIVACY.md),
  [../api/API_LOGGING.md](../api/API_LOGGING.md)); a value that reached a log as ciphertext is still
  treated as sensitive and not emitted.
- **In API responses:** Restricted fields are masked by default (e.g. `**** **** **** 4471`) and
  returned in full only to a caller holding the specific reveal permission, on a specific record,
  with the reveal itself audited.
- **In backups:** inherit at-rest encryption plus the independent backup key; a restored backup is
  inert without the backup CMK and, for field-encrypted columns, the per-tenant KEKs.
- **In transit to the AI engine:** only the minimal cleartext the task needs, over mTLS, with PII
  redaction applied first per [./AI_SECURITY.md](./AI_SECURITY.md) and [./DATA_PRIVACY.md](./DATA_PRIVACY.md).

# Failure Modes

QAYD fails closed on every cryptographic error. Silent degradation is prohibited.

| Failure | Behavior | Rationale |
|---|---|---|
| KMS unreachable on encrypt | Abort the write, return `503` envelope error, retry with backoff, alert | Never store a value unencrypted "to not lose it" |
| KMS unreachable on decrypt | Abort the read for that field, return a masked placeholder + surfaced error; the rest of the record may still render | Never fabricate or guess a plaintext |
| AEAD tag mismatch on decrypt | Hard error, do not return bytes, raise a security incident (possible tampering or wrong-key/wrong-AAD — e.g. a lifted ciphertext) | A tag mismatch is potential tampering, T4-adjacent |
| Missing key version in envelope header | Reject; a value referencing a retired/unknown key is quarantined for investigation | Prevents silent data loss and hides key-management bugs |
| Field-encrypted column written without the cast (bug) | CI migration check blocks it pre-merge; runtime write of raw plaintext to a known-encrypted column is rejected by a model observer | Belt and suspenders |
| Blind-index key rotated but index not rebuilt | Lookups miss; monitored as a data-integrity alarm, never a silent empty result treated as "no match" | A silent miss could hide a real record |
| TLS downgrade attempt | Connection reset at edge; logged | No plaintext fallback exists to downgrade to |

# Compliance

Encryption is the technical evidence behind several of QAYD's compliance goals
([../foundation/SECURITY_ARCHITECTURE.md](../foundation/SECURITY_ARCHITECTURE.md) § Compliance Goals).

| Framework | Encryption obligation | How QAYD meets it |
|---|---|---|
| SOC 2 (Confidentiality, Security) | Encrypt data in transit and at rest; manage keys | TLS 1.2+/1.3, KMS-keyed at-rest, envelope field encryption, documented rotation |
| ISO 27001 (A.8 / A.10 cryptography) | A cryptographic-controls policy and key management | This document + [./SECRETS.md](./SECRETS.md) |
| GDPR (Art. 32) | Encryption/pseudonymization of personal data; ability to restore | Field encryption of PII + crypto-shredding + encrypted, restorable backups |
| PCI-adjacent (if card/bank rails added) | Strong crypto for account data, key rotation, no plaintext PAN | Field encryption + blind index + masking; account numbers never in logs/URLs |
| GCC/Kuwait data protection | Protect financial and personal data; support residency | At-rest encryption + regional key/data residency (see [./DATA_PRIVACY.md](./DATA_PRIVACY.md)) |

The cryptographic evidence an auditor is shown: the CMK inventory and their rotation status; the CI
policy that forces encryption on Restricted columns; a live demonstration that a `pg_dump` of a
payroll table yields ciphertext; and the KMS audit stream proving every DEK unwrap is attributable.

# Testing & Verification

Cryptography is tested for correctness, for failure behavior, and adversarially — a green
"it encrypts" test is not sufficient.

**Unit / feature tests:**

- Round-trip: encrypt then decrypt yields the original for every `EncryptedField` column.
- AAD binding: a ciphertext from `(company A, row 1)` fails to decrypt when its bytes are moved to
  `(company B, row 9)` — proving cross-tenant paste is rejected.
- Fail-closed: a simulated KMS outage causes writes/reads to error, never to persist or return
  plaintext.
- Rotation: a value encrypted under key v1 still decrypts after the platform advances to v2, and is
  re-encrypted to v2 on its next write.
- Blind index: two rows with the same account number share a `*_bidx`; different numbers do not;
  the index reveals nothing about the plaintext without the HMAC key.
- Password: `needsRehash` upgrades a low-cost hash on login; hashes never appear in any serializer
  output; login is constant-time.
- Masking: a Restricted field serializes masked by default and unmasked only with the reveal grant,
  and the reveal is audited.

**Integration / infra tests:**

- A `pg_dump` and an R2 object fetched out-of-band are confirmed to be ciphertext for encrypted
  columns/objects.
- TLS scanners (e.g. `testssl.sh`) confirm the cipher allow-list, TLS floor, HSTS, and absence of
  weak suites on every public endpoint; mTLS is confirmed on the Laravel↔FastAPI hop.
- A crypto-shred drill: destroying a test tenant's KEK renders its field-encrypted values
  unrecoverable across live + backup, and no other tenant is affected.

**CI gates (blocking):** the Restricted-column-must-be-encrypted migration check; the banned-primitive
static analysis; secret-scanning on every commit; and a signed-URL lint that forbids permanent public
URLs on private disks.

**Verification cadence:** rotation runbooks are rehearsed quarterly; the crypto-shred and backup-
restore-is-inert drills run at least semi-annually; TLS posture is scanned continuously by the edge
provider and monthly by an independent scanner; and every new Restricted column added to the schema
triggers a review checklist item confirming its cast, its classification, and whether it needs a
blind index.

# End of Document
