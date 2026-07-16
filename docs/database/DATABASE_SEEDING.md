# Database Seeding — QAYD Database Layer
Version: 1.0
Status: Design Specification
Module: Database
Submodule: DATABASE_SEEDING
---

# Purpose

This document specifies the complete database seeding subsystem for QAYD, the AI Financial
Operating System. Seeding is the mechanism by which the PostgreSQL 15+ database is populated
with data that the application cannot function without (reference/lookup data), data that
accelerates evaluation and onboarding (demo companies), data that developers need on their
local machines to exercise every module (development seeds), and data that the automated test
suite consumes to produce deterministic, repeatable assertions (testing seeds).

Seeding in QAYD is not an afterthought bolted onto migrations. It is a first-class layer of the
database design with its own lifecycle, its own safety rules, and its own idempotency contract.
Every seeder in this document is a Laravel 12 `Seeder` class (PHP 8.4+), invoked through
`php artisan db:seed` or through the standard `DatabaseSeeder` orchestration class, and every
seeder is written to be safely re-run without creating duplicate rows or corrupting existing
tenant data. This matters acutely for QAYD because the platform is multi-tenant (every business
row carries a `company_id`) and financial (posted accounting rows are immutable) — a naive
seeder that truncates tables or blindly inserts rows would either destroy real customer ledgers
or produce financially invalid data (unbalanced journal entries, orphaned foreign keys, currency
mismatches).

This document covers, exhaustively:

1. The philosophy and layering strategy for all seed data (reference vs. demo vs. development
   vs. testing), and how each layer maps to a Laravel seeder class and an environment gate.
2. Concrete PostgreSQL DDL and Laravel migration definitions for every reference table this
   document owns: `countries`, `currencies`, `languages`, `permissions`, `roles`,
   `role_permissions` (pivot), `coa_templates` / `coa_template_lines`, and `industries`.
3. Fully worked Laravel `Seeder` classes — real PHP code, not pseudo-code — for every table
   above, plus a demo-company seeder that assembles a working tenant end to end (company,
   branches, users, roles, chart of accounts, customers, vendors, products, and a handful of
   posted journal entries that balance to the cent).
4. Development seeders that use Model Factories and Faker to generate volume data for local
   exploratory testing, and testing seeders that produce minimal, deterministic fixtures for
   Pest/PHPUnit.
5. Production safety rules — the concrete guardrails (environment checks, confirmation prompts,
   seeder allow-lists, CI/CD gating) that prevent a destructive or non-idempotent seeder from
   ever running against a production database.
6. The idempotency contract every seeder in QAYD must satisfy, with the specific Eloquent/SQL
   patterns (`updateOrCreate`, `upsert`, natural-key uniqueness, checksums) used to guarantee it.
7. End-to-end worked examples: the full `DatabaseSeeder.php` orchestration class, the exact
   `artisan` invocations per environment, and sample console output.

This document does not re-define the tables owned by other module docs (`accounts`,
`journal_entries`, `journal_lines`, `customers`, `vendors`, `products`, `invoices`, `bills`,
`bank_accounts`, `inventory_items`, `payroll_runs`, `tax_transactions`, etc.) — see the
Accounting, Sales, Purchasing, Banking, Inventory, Payroll, and Tax module docs for their DDL.
This document reuses those exact table and column names when seeders populate them, and it is
authoritative for the *reference* tables and the *seeding process* that populate every module.

# Seed Philosophy

QAYD's seed data is organized into four strictly separated layers. Each layer has a different
audience, a different environment gate, and a different mutation contract. Mixing them — for
example, letting a demo-company seeder run in production, or letting a development factory
seeder truncate a reference table — is the single most common cause of seed-related incidents
in ERP systems, and QAYD's architecture is designed specifically to make that mistake structurally
difficult, not just discouraged by convention.

**Layer 1 — Reference Data.** Global, tenant-agnostic lookup data that every company in the
system shares: `countries`, `currencies`, `languages`, `permissions`, `industries`, and the
`coa_templates` catalog (the template *definitions*, not any company's actual chart of accounts).
Reference data has no `company_id` — it lives outside tenant isolation because it describes the
world (ISO country codes, ISO currency codes) or describes the product (the permission catalog,
the built-in COA templates). Reference-data seeders are **safe to run in every environment,
including production**, and are expected to run on every deploy as part of the release pipeline.
They are strictly additive/upsert-only: never a `DELETE`, never a `TRUNCATE`.

**Layer 2 — Demo Companies.** Fully-formed, realistic tenant data (a company, its branches,
users, roles, a real chart of accounts cloned from a template, customers, vendors, products, and
a handful of posted transactions) used for product demos, sales trials, and the "Try QAYD" onboarding
flow. Demo companies are real rows in the multi-tenant schema, flagged with `is_demo = true` on
`companies`, and they are **never seeded automatically in production** — they are seeded once,
explicitly, by an operator running `php artisan db:seed --class=DemoCompanySeeder --force`, and
the resulting company IDs are recorded so that a scheduled job can reset (not delete — reset
means re-seed a fresh copy and soft-delete the stale one) them nightly for the public trial
environment.

**Layer 3 — Development Seeds.** High-volume, randomized (Faker-driven) data generated only in
local/dev/staging environments to let engineers click through every screen with realistic
quantities of records (hundreds of invoices, thousands of ledger lines, dozens of employees).
Development seeders are the only layer permitted to use `Model::factory()->count(n)` at scale,
and they are hard-blocked from running when `app()->environment('production')` is true — the
seeder's `run()` method aborts immediately with a thrown exception if that guard is not met.

**Layer 4 — Testing Seeds.** Minimal, deterministic, hand-specified fixtures consumed by
Pest/PHPUnit feature and unit tests. Testing seeds never use `rand()`/Faker for values that
assertions depend on (IDs, amounts, dates are fixed literals) so that test runs are byte-for-byte
reproducible. Testing seeds run inside a transaction-wrapped `RefreshDatabase` trait per test and
are torn down automatically; they are never invoked against a persistent database.

Cross-cutting rules that apply to every layer:

- **Idempotency first.** Every seeder can be run twice in a row without changing the resulting
  row count or violating a unique constraint. See `# Idempotency` for the exact mechanics.
- **Company scoping is explicit.** Any seeder that inserts into a tenant table must set
  `company_id` explicitly — there is no "seed without a company" for business tables.
- **Seeders never bypass Services.** Where a module has a Service class encoding business rules
  (e.g. `JournalPostingService::post()` to guarantee debits = credits), seeders call that Service
  rather than inserting rows that skip the invariant. This is the same "no business logic outside
  the Service layer" rule the platform applies to controllers, extended to seeders.
- **Deterministic ordering.** `DatabaseSeeder::run()` calls child seeders in a fixed dependency
  order (reference data → permissions/roles → demo/dev company shells → dependent business data)
  so that foreign keys always resolve.
- **Seeders are Laravel classes, not raw SQL scripts**, with one narrow exception: the initial
  bulk-load of `countries` (250 rows) and `currencies` (170+ rows) is stored as versioned JSON
  fixture files under `database/seeders/data/*.json` and loaded by the seeder class, rather than
  hard-coded as PHP array literals, so that reference-data updates are reviewable diffs.

# Reference Data

Reference data is loaded by a single umbrella seeder, `ReferenceDataSeeder`, which is the one
seeder QAYD guarantees is safe to run unconditionally in any environment on every deploy. It
delegates to the per-table seeders documented in the following sections, in this fixed order:

```php
<?php
// database/seeders/ReferenceDataSeeder.php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class ReferenceDataSeeder extends Seeder
{
    /**
     * Safe to run in every environment, including production, on every deploy.
     * Strictly additive/upsert-only — never truncates, never deletes.
     */
    public function run(): void
    {
        $this->call([
            CountrySeeder::class,
            CurrencySeeder::class,
            LanguageSeeder::class,
            IndustrySeeder::class,
            AccountTypeSeeder::class,      // owned by Accounting module doc; referenced here for order
            PermissionSeeder::class,
            RoleSeeder::class,
            CoaTemplateSeeder::class,
        ]);

        $this->command?->info('Reference data seeded/updated: '
            . 'countries, currencies, languages, industries, account types, '
            . 'permissions, roles, COA templates.');
    }
}
```

The deploy pipeline invokes this seeder automatically as a post-migration release step:

```bash
php artisan migrate --force
php artisan db:seed --class=ReferenceDataSeeder --force
php artisan config:cache
php artisan route:cache
```

Reference tables share a lighter version of the platform's standard columns — they omit
`branch_id` (reference data is never branch-scoped) and `company_id` (it is global), but they
keep `created_at`/`updated_at` for auditability and `deleted_at` for soft-delete safety (a
country or currency is never hard-deleted even if it becomes inactive — it is flagged
`is_active = false` so historical records that reference it, e.g. an invoice in a currency that
was later delisted, remain valid). All reference tables also carry a `code` column that is the
true natural key (ISO code, permission key, role slug) and enforce a `UNIQUE` constraint on it;
`id` remains the surrogate FK target for join performance, but every seeder resolves on `code`
via `updateOrCreate`, never on `id`.

# Countries

## Database Design

```sql
CREATE TABLE countries (
    id              BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    iso2            CHAR(2)        NOT NULL,               -- ISO 3166-1 alpha-2, e.g. 'KW'
    iso3            CHAR(3)        NOT NULL,                -- ISO 3166-1 alpha-3, e.g. 'KWT'
    numeric_code    CHAR(3)        NOT NULL,                -- ISO 3166-1 numeric, e.g. '414'
    name_en         VARCHAR(120)   NOT NULL,
    name_ar         VARCHAR(120)   NOT NULL,
    phone_code      VARCHAR(8)     NOT NULL,                -- e.g. '+965'
    default_currency_code CHAR(3)  NOT NULL REFERENCES currencies(code),
    region          VARCHAR(60)    NOT NULL,                -- 'GCC', 'MENA', 'Europe', ...
    timezone        VARCHAR(60)    NOT NULL,                -- IANA tz, e.g. 'Asia/Kuwait'
    is_gcc          BOOLEAN        NOT NULL DEFAULT false,
    is_active       BOOLEAN        NOT NULL DEFAULT true,
    created_at      TIMESTAMPTZ    NOT NULL DEFAULT now(),
    updated_at      TIMESTAMPTZ    NOT NULL DEFAULT now(),
    deleted_at      TIMESTAMPTZ    NULL,
    CONSTRAINT uq_countries_iso2 UNIQUE (iso2),
    CONSTRAINT uq_countries_iso3 UNIQUE (iso3)
);

CREATE INDEX idx_countries_region   ON countries (region) WHERE deleted_at IS NULL;
CREATE INDEX idx_countries_is_gcc   ON countries (is_gcc) WHERE deleted_at IS NULL;
CREATE INDEX idx_countries_active   ON countries (is_active) WHERE deleted_at IS NULL;
```

Laravel migration equivalent:

```php
<?php
// database/migrations/2026_01_01_000001_create_countries_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('countries', function (Blueprint $table) {
            $table->id();
            $table->char('iso2', 2)->unique();
            $table->char('iso3', 3)->unique();
            $table->char('numeric_code', 3);
            $table->string('name_en', 120);
            $table->string('name_ar', 120);
            $table->string('phone_code', 8);
            $table->char('default_currency_code', 3);
            $table->string('region', 60);
            $table->string('timezone', 60);
            $table->boolean('is_gcc')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('default_currency_code')
                  ->references('code')->on('currencies');
            $table->index('region');
            $table->index('is_gcc');
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('countries');
    }
};
```

## Seeder

The full 250-country data set lives in `database/seeders/data/countries.json` (one JSON array of
objects keyed exactly as the columns above). The seeder below shows the loader plus the GCC
subset inline for documentation completeness — in the real fixture file all 250 rows are present.

```php
<?php
// database/seeders/CountrySeeder.php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class CountrySeeder extends Seeder
{
    public function run(): void
    {
        $path = database_path('seeders/data/countries.json');
        $rows = json_decode(File::get($path), true);

        foreach (array_chunk($rows, 100) as $chunk) {
            $now = now();
            $payload = array_map(function (array $row) use ($now) {
                $row['created_at'] = $row['created_at'] ?? $now;
                $row['updated_at'] = $now;
                return $row;
            }, $chunk);

            DB::table('countries')->upsert(
                $payload,
                ['iso2'],                      // natural-key conflict target
                [
                    'iso3', 'numeric_code', 'name_en', 'name_ar', 'phone_code',
                    'default_currency_code', 'region', 'timezone', 'is_gcc',
                    'is_active', 'updated_at',
                ]
            );
        }

        $this->command?->info(sprintf('Seeded %d countries.', count($rows)));
    }
}
```

Representative excerpt of `countries.json` (GCC + key MENA/global trading partners — the full
file carries all 250 ISO-recognized countries):

```json
[
  { "iso2": "KW", "iso3": "KWT", "numeric_code": "414", "name_en": "Kuwait", "name_ar": "الكويت",
    "phone_code": "+965", "default_currency_code": "KWD", "region": "GCC",
    "timezone": "Asia/Kuwait", "is_gcc": true, "is_active": true },
  { "iso2": "SA", "iso3": "SAU", "numeric_code": "682", "name_en": "Saudi Arabia", "name_ar": "السعودية",
    "phone_code": "+966", "default_currency_code": "SAR", "region": "GCC",
    "timezone": "Asia/Riyadh", "is_gcc": true, "is_active": true },
  { "iso2": "AE", "iso3": "ARE", "numeric_code": "784", "name_en": "United Arab Emirates", "name_ar": "الإمارات",
    "phone_code": "+971", "default_currency_code": "AED", "region": "GCC",
    "timezone": "Asia/Dubai", "is_gcc": true, "is_active": true },
  { "iso2": "QA", "iso3": "QAT", "numeric_code": "634", "name_en": "Qatar", "name_ar": "قطر",
    "phone_code": "+974", "default_currency_code": "QAR", "region": "GCC",
    "timezone": "Asia/Qatar", "is_gcc": true, "is_active": true },
  { "iso2": "BH", "iso3": "BHR", "numeric_code": "048", "name_en": "Bahrain", "name_ar": "البحرين",
    "phone_code": "+973", "default_currency_code": "BHD", "region": "GCC",
    "timezone": "Asia/Bahrain", "is_gcc": true, "is_active": true },
  { "iso2": "OM", "iso3": "OMN", "numeric_code": "512", "name_en": "Oman", "name_ar": "عمان",
    "phone_code": "+968", "default_currency_code": "OMR", "region": "GCC",
    "timezone": "Asia/Muscat", "is_gcc": true, "is_active": true },
  { "iso2": "EG", "iso3": "EGY", "numeric_code": "818", "name_en": "Egypt", "name_ar": "مصر",
    "phone_code": "+20", "default_currency_code": "EGP", "region": "MENA",
    "timezone": "Africa/Cairo", "is_gcc": false, "is_active": true },
  { "iso2": "JO", "iso3": "JOR", "numeric_code": "400", "name_en": "Jordan", "name_ar": "الأردن",
    "phone_code": "+962", "default_currency_code": "JOD", "region": "MENA",
    "timezone": "Asia/Amman", "is_gcc": false, "is_active": true },
  { "iso2": "US", "iso3": "USA", "numeric_code": "840", "name_en": "United States", "name_ar": "الولايات المتحدة",
    "phone_code": "+1", "default_currency_code": "USD", "region": "Americas",
    "timezone": "America/New_York", "is_gcc": false, "is_active": true },
  { "iso2": "GB", "iso3": "GBR", "numeric_code": "826", "name_en": "United Kingdom", "name_ar": "المملكة المتحدة",
    "phone_code": "+44", "default_currency_code": "GBP", "region": "Europe",
    "timezone": "Europe/London", "is_gcc": false, "is_active": true }
]
```

Note the `countries` table has a forward reference to `currencies(code)`. This is why the actual
execution order in `ReferenceDataSeeder`/`DatabaseSeeder` runs `CurrencySeeder` before
`CountrySeeder` — see `# Examples` for the exact, dependency-safe call order. The narrative order
in this document (Countries before Currencies) follows domain reading order, not execution order.

# Currencies

## Database Design

```sql
CREATE TABLE currencies (
    id                  BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    code                CHAR(3)        NOT NULL,                 -- ISO 4217, e.g. 'KWD'
    numeric_code        CHAR(3)        NOT NULL,                 -- ISO 4217 numeric, e.g. '414'
    name_en             VARCHAR(80)    NOT NULL,
    name_ar             VARCHAR(80)    NOT NULL,
    symbol              VARCHAR(8)     NOT NULL,                 -- e.g. 'د.ك', '$', 'ر.س'
    decimal_places      SMALLINT       NOT NULL DEFAULT 2,       -- KWD/BHD/OMR use 3
    is_active           BOOLEAN        NOT NULL DEFAULT true,
    is_gcc_currency     BOOLEAN        NOT NULL DEFAULT false,
    created_at          TIMESTAMPTZ    NOT NULL DEFAULT now(),
    updated_at          TIMESTAMPTZ    NOT NULL DEFAULT now(),
    deleted_at          TIMESTAMPTZ    NULL,
    CONSTRAINT uq_currencies_code UNIQUE (code),
    CONSTRAINT ck_currencies_decimal_places CHECK (decimal_places BETWEEN 0 AND 4)
);

CREATE INDEX idx_currencies_active ON currencies (is_active) WHERE deleted_at IS NULL;
```

Every company references exactly one row in `currencies` as its base currency
(`companies.base_currency_code CHAR(3) NOT NULL REFERENCES currencies(code)`), and every
multi-currency-capable document (invoices, bills, receipts, journal_lines) stores its own
`currency_code` alongside `exchange_rate` and the computed base-currency amount, per the
Multi-Currency rule in the platform-wide design context. `decimal_places` matters concretely for
Kuwait: KWD is a 3-decimal currency (1 fils = 1/1000 KWD), unlike USD/SAR/AED/QAR which use 2. All
money columns remain `NUMERIC(19,4)` regardless of `decimal_places` — the column controls
*display rounding* only, never storage precision. This is enforced in the `MoneyFormatter`
service (not a seeding concern, but noted here so the seeded metadata is interpreted correctly
downstream).

## Seeder

```php
<?php
// database/seeders/CurrencySeeder.php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CurrencySeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        $currencies = [
            ['code' => 'KWD', 'numeric_code' => '414', 'name_en' => 'Kuwaiti Dinar',   'name_ar' => 'دينار كويتي',   'symbol' => 'د.ك',  'decimal_places' => 3, 'is_gcc_currency' => true],
            ['code' => 'SAR', 'numeric_code' => '682', 'name_en' => 'Saudi Riyal',     'name_ar' => 'ريال سعودي',    'symbol' => 'ر.س',  'decimal_places' => 2, 'is_gcc_currency' => true],
            ['code' => 'AED', 'numeric_code' => '784', 'name_en' => 'UAE Dirham',      'name_ar' => 'درهم إماراتي',  'symbol' => 'د.إ',  'decimal_places' => 2, 'is_gcc_currency' => true],
            ['code' => 'QAR', 'numeric_code' => '634', 'name_en' => 'Qatari Riyal',    'name_ar' => 'ريال قطري',     'symbol' => 'ر.ق',  'decimal_places' => 2, 'is_gcc_currency' => true],
            ['code' => 'BHD', 'numeric_code' => '048', 'name_en' => 'Bahraini Dinar',  'name_ar' => 'دينار بحريني',  'symbol' => 'د.ب',  'decimal_places' => 3, 'is_gcc_currency' => true],
            ['code' => 'OMR', 'numeric_code' => '512', 'name_en' => 'Omani Rial',      'name_ar' => 'ريال عماني',    'symbol' => 'ر.ع',  'decimal_places' => 3, 'is_gcc_currency' => true],
            ['code' => 'USD', 'numeric_code' => '840', 'name_en' => 'US Dollar',       'name_ar' => 'دولار أمريكي',  'symbol' => '$',    'decimal_places' => 2, 'is_gcc_currency' => false],
            ['code' => 'EUR', 'numeric_code' => '978', 'name_en' => 'Euro',            'name_ar' => 'يورو',          'symbol' => '€',    'decimal_places' => 2, 'is_gcc_currency' => false],
            ['code' => 'GBP', 'numeric_code' => '826', 'name_en' => 'Pound Sterling',  'name_ar' => 'جنيه إسترليني', 'symbol' => '£',    'decimal_places' => 2, 'is_gcc_currency' => false],
            ['code' => 'EGP', 'numeric_code' => '818', 'name_en' => 'Egyptian Pound',  'name_ar' => 'جنيه مصري',     'symbol' => 'ج.م',  'decimal_places' => 2, 'is_gcc_currency' => false],
            ['code' => 'JOD', 'numeric_code' => '400', 'name_en' => 'Jordanian Dinar', 'name_ar' => 'دينار أردني',   'symbol' => 'د.ا',  'decimal_places' => 3, 'is_gcc_currency' => false],
            ['code' => 'INR', 'numeric_code' => '356', 'name_en' => 'Indian Rupee',    'name_ar' => 'روبية هندية',   'symbol' => '₹',    'decimal_places' => 2, 'is_gcc_currency' => false],
            ['code' => 'PHP', 'numeric_code' => '608', 'name_en' => 'Philippine Peso', 'name_ar' => 'بيزو فلبيني',   'symbol' => '₱',    'decimal_places' => 2, 'is_gcc_currency' => false],
            ['code' => 'CNY', 'numeric_code' => '156', 'name_en' => 'Chinese Yuan',    'name_ar' => 'يوان صيني',     'symbol' => '¥',    'decimal_places' => 2, 'is_gcc_currency' => false],
        ];

        $payload = array_map(function (array $c) use ($now) {
            $c['is_active'] = true;
            $c['created_at'] = $now;
            $c['updated_at'] = $now;
            return $c;
        }, $currencies);

        DB::table('currencies')->upsert(
            $payload,
            ['code'],
            ['numeric_code', 'name_en', 'name_ar', 'symbol', 'decimal_places',
             'is_gcc_currency', 'is_active', 'updated_at']
        );

        $this->command?->info(sprintf('Seeded %d currencies.', count($currencies)));
    }
}
```

# Languages

## Database Design

```sql
CREATE TABLE languages (
    id            BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    code          VARCHAR(5)    NOT NULL,          -- ISO 639-1, e.g. 'en', 'ar'
    name_en       VARCHAR(60)   NOT NULL,
    name_native   VARCHAR(60)   NOT NULL,
    direction     VARCHAR(3)    NOT NULL DEFAULT 'ltr',   -- 'ltr' | 'rtl'
    is_active     BOOLEAN       NOT NULL DEFAULT true,
    created_at    TIMESTAMPTZ   NOT NULL DEFAULT now(),
    updated_at    TIMESTAMPTZ   NOT NULL DEFAULT now(),
    deleted_at    TIMESTAMPTZ   NULL,
    CONSTRAINT uq_languages_code UNIQUE (code),
    CONSTRAINT ck_languages_direction CHECK (direction IN ('ltr', 'rtl'))
);
```

`companies.default_language_code` and `users.preferred_language_code` both reference this table.
The frontend's i18n layer (Next.js `next-intl`) keys off `code`; RTL-aware Tailwind utilities key
off `direction`.

## Seeder

```php
<?php
// database/seeders/LanguageSeeder.php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class LanguageSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        $languages = [
            ['code' => 'ar', 'name_en' => 'Arabic',  'name_native' => 'العربية',  'direction' => 'rtl'],
            ['code' => 'en', 'name_en' => 'English', 'name_native' => 'English', 'direction' => 'ltr'],
            ['code' => 'fr', 'name_en' => 'French',  'name_native' => 'Français', 'direction' => 'ltr'],
        ];

        $payload = array_map(function (array $l) use ($now) {
            $l['is_active'] = true;
            $l['created_at'] = $now;
            $l['updated_at'] = $now;
            return $l;
        }, $languages);

        DB::table('languages')->upsert(
            $payload,
            ['code'],
            ['name_en', 'name_native', 'direction', 'is_active', 'updated_at']
        );

        $this->command?->info(sprintf('Seeded %d languages.', count($languages)));
    }
}
```

# Permissions

## Database Design

```sql
CREATE TABLE permissions (
    id            BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    key           VARCHAR(120)  NOT NULL,           -- '<area>.<action>' or '<area>.<entity>.<action>'
    area          VARCHAR(60)   NOT NULL,            -- 'accounting', 'bank', 'payroll', ...
    label_en      VARCHAR(150)  NOT NULL,
    label_ar      VARCHAR(150)  NOT NULL,
    description   TEXT          NULL,
    is_sensitive  BOOLEAN       NOT NULL DEFAULT false,   -- requires approval chain per design context §6
    created_at    TIMESTAMPTZ   NOT NULL DEFAULT now(),
    updated_at    TIMESTAMPTZ   NOT NULL DEFAULT now(),
    deleted_at    TIMESTAMPTZ   NULL,
    CONSTRAINT uq_permissions_key UNIQUE (key)
);

CREATE INDEX idx_permissions_area ON permissions (area) WHERE deleted_at IS NULL;

CREATE TABLE role_permissions (
    id             BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    role_id        BIGINT NOT NULL REFERENCES roles(id) ON DELETE CASCADE,
    permission_id  BIGINT NOT NULL REFERENCES permissions(id) ON DELETE CASCADE,
    created_at     TIMESTAMPTZ NOT NULL DEFAULT now(),
    CONSTRAINT uq_role_permissions UNIQUE (role_id, permission_id)
);

CREATE INDEX idx_role_permissions_role ON role_permissions (role_id);
CREATE INDEX idx_role_permissions_perm ON role_permissions (permission_id);
```

QAYD does not use a `roles` bit-flag or JSON blob — role-to-permission is a normalized many-to-
many pivot so it can be queried, audited, and modified per company. A company may customize which
permissions its "Accountant" role grants, layered on top of the system default via
`company_role_permission_overrides` (owned by the Authorization module doc); this document only
seeds the system defaults.

## Seeder

Rather than hand-writing 150+ permission rows, the seeder builds the catalog from a declarative
matrix of areas × actions, mirroring the naming convention in the platform design context
(`<area>.<action>` and `<area>.<entity>.<action>`), then overlays a short list of irregular /
compound keys that don't fit the matrix cleanly (e.g. `bank.transfer`, `tax.submit`).

```php
<?php
// database/seeders/PermissionSeeder.php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PermissionSeeder extends Seeder
{
    /**
     * Standard CRUD-style verbs applied per area to build the bulk of the catalog.
     */
    private const STANDARD_ACTIONS = ['read', 'create', 'update', 'delete', 'export'];

    /**
     * area => [label_en, label_ar, extra non-standard actions[], sensitive actions[]]
     */
    private const AREAS = [
        'accounting' => ['Accounting', 'المحاسبة', ['journal.create', 'journal.post', 'journal.reverse', 'journal.void', 'period.close'], ['journal.post', 'journal.void', 'period.close']],
        'bank'       => ['Banking', 'البنوك', ['reconcile', 'transfer', 'statement.import'], ['transfer']],
        'sales'      => ['Sales', 'المبيعات', ['invoice.issue', 'credit_note.issue', 'quotation.convert'], []],
        'purchasing' => ['Purchasing', 'المشتريات', ['po.approve', 'bill.approve', 'payment.release'], ['payment.release']],
        'inventory'  => ['Inventory', 'المخزون', ['adjust', 'transfer', 'count.reconcile'], ['adjust']],
        'payroll'    => ['Payroll', 'الرواتب', ['calculate', 'approve', 'release'], ['approve', 'release']],
        'tax'        => ['Tax', 'الضرائب', ['submit', 'file_return'], ['submit', 'file_return']],
        'reports'    => ['Reports', 'التقارير', ['export', 'schedule'], []],
        'settings'   => ['Company Settings', 'إعدادات الشركة', ['manage', 'billing.manage'], ['manage', 'billing.manage']],
        'users'      => ['Users & Permissions', 'المستخدمون والصلاحيات', ['invite', 'role.assign', 'permission.change'], ['role.assign', 'permission.change']],
        'audit'      => ['Audit', 'التدقيق', ['view_logs'], []],
        'ai'         => ['AI Agents', 'وكلاء الذكاء الاصطناعي', ['approve_action', 'configure'], ['approve_action']],
    ];

    public function run(): void
    {
        $now = now();
        $rows = [];

        foreach (self::AREAS as $area => [$labelEn, $labelAr, $extraActions, $sensitiveActions]) {
            foreach (self::STANDARD_ACTIONS as $action) {
                $rows[] = $this->row($area, $action, "$labelEn: " . ucfirst($action), $labelAr, false, $now);
            }
            foreach ($extraActions as $action) {
                $isSensitive = in_array($action, $sensitiveActions, true);
                $rows[] = $this->row($area, $action, "$labelEn: " . str_replace(['.', '_'], ' ', $action), $labelAr, $isSensitive, $now);
            }
        }

        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('permissions')->upsert(
                $chunk,
                ['key'],
                ['area', 'label_en', 'label_ar', 'description', 'is_sensitive', 'updated_at']
            );
        }

        $this->command?->info(sprintf('Seeded %d permissions across %d areas.', count($rows), count(self::AREAS)));
    }

    private function row(string $area, string $action, string $labelEn, string $labelAr, bool $sensitive, $now): array
    {
        return [
            'key'          => "{$area}.{$action}",
            'area'         => $area,
            'label_en'     => $labelEn,
            'label_ar'     => $labelAr,
            'description'  => null,
            'is_sensitive' => $sensitive,
            'created_at'   => $now,
            'updated_at'   => $now,
        ];
    }
}
```

This produces canonical keys exactly matching the design context's examples —
`accounting.read`, `accounting.journal.create`, `accounting.journal.post`, `bank.reconcile`,
`bank.transfer`, `payroll.calculate`, `payroll.approve`, `inventory.adjust`, `tax.submit`,
`reports.export` — plus the full standard CRUD set (`.read/.create/.update/.delete/.export`) per
area, and marks the sensitive-operation subset (`accounting.journal.post`,
`accounting.journal.void`, `accounting.period.close`, `bank.transfer`,
`purchasing.payment.release`, `payroll.approve`, `payroll.release`, `tax.submit`,
`tax.file_return`, `settings.manage`, `settings.billing.manage`, `users.role.assign`,
`users.permission.change`, `ai.approve_action`) as `is_sensitive = true`, which the Authorization
middleware and the approval-chain workflow both read to decide whether an action requires a
second human approver, per design-context §6.

# Roles

## Database Design

```sql
CREATE TYPE role_scope AS ENUM ('system', 'company');

CREATE TABLE roles (
    id            BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id    BIGINT NULL REFERENCES companies(id),   -- NULL for system (template) roles
    slug          VARCHAR(60)   NOT NULL,                  -- 'owner', 'cfo', 'accountant', ...
    name_en       VARCHAR(100)  NOT NULL,
    name_ar       VARCHAR(100)  NOT NULL,
    description   TEXT          NULL,
    scope         role_scope    NOT NULL DEFAULT 'system',
    is_default    BOOLEAN       NOT NULL DEFAULT false,   -- assigned automatically to the company creator
    created_by    BIGINT NULL REFERENCES users(id),
    updated_by    BIGINT NULL REFERENCES users(id),
    created_at    TIMESTAMPTZ   NOT NULL DEFAULT now(),
    updated_at    TIMESTAMPTZ   NOT NULL DEFAULT now(),
    deleted_at    TIMESTAMPTZ   NULL,
    CONSTRAINT uq_roles_slug_scope UNIQUE (slug, company_id)
);

CREATE INDEX idx_roles_company ON roles (company_id) WHERE deleted_at IS NULL;
CREATE INDEX idx_roles_scope   ON roles (scope) WHERE deleted_at IS NULL;
```

System roles (`scope = 'system'`, `company_id IS NULL`) are the 17 default roles named in the
platform design context. They act as **templates**: when a company is created,
`CompanyProvisioningService::cloneSystemRolesForCompany($company)` copies each system role into a
company-scoped row (`scope = 'company'`, `company_id = $company->id`) along with its
`role_permissions`, so that a company can subsequently customize its own copy (rename it, strip a
permission) without mutating the global template. This document seeds only the system-role
templates and their default permission grants; the per-company cloning is a Service-layer
concern triggered at company creation, not a seeder concern — but the Demo Company seeder (see
`# Demo Companies`) exercises that exact code path so the demo tenants have realistic,
independently-editable roles.

## Seeder

```php
<?php
// database/seeders/RoleSeeder.php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class RoleSeeder extends Seeder
{
    /**
     * slug => [name_en, name_ar, permission key patterns[]]
     * A pattern ending in '.*' grants every permission whose key starts with that prefix.
     */
    private const ROLES = [
        'owner' => ['Owner', 'المالك', ['*']],
        'ceo' => ['CEO', 'الرئيس التنفيذي', ['*']],
        'cfo' => ['CFO', 'المدير المالي', [
            'accounting.*', 'bank.*', 'reports.*', 'tax.*', 'payroll.approve', 'payroll.release',
            'purchasing.payment.release', 'settings.billing.manage', 'audit.view_logs',
        ]],
        'finance_manager' => ['Finance Manager', 'مدير الشؤون المالية', [
            'accounting.*', 'bank.reconcile', 'bank.statement.import', 'reports.*', 'tax.read', 'tax.submit',
        ]],
        'senior_accountant' => ['Senior Accountant', 'محاسب أول', [
            'accounting.read', 'accounting.create', 'accounting.update', 'accounting.export',
            'accounting.journal.create', 'accounting.journal.post', 'accounting.journal.reverse',
            'bank.read', 'bank.reconcile', 'reports.read', 'reports.export',
        ]],
        'accountant' => ['Accountant', 'محاسب', [
            'accounting.read', 'accounting.create', 'accounting.update',
            'accounting.journal.create', 'bank.read', 'reports.read',
        ]],
        'auditor' => ['Auditor', 'مدقق داخلي', [
            'accounting.read', 'bank.read', 'reports.read', 'reports.export', 'audit.view_logs',
        ]],
        'hr_manager' => ['HR Manager', 'مدير الموارد البشرية', [
            'payroll.read', 'payroll.create', 'payroll.update', 'payroll.calculate', 'payroll.approve',
            'users.read', 'users.invite',
        ]],
        'payroll_officer' => ['Payroll Officer', 'مسؤول الرواتب', [
            'payroll.read', 'payroll.create', 'payroll.update', 'payroll.calculate',
        ]],
        'inventory_manager' => ['Inventory Manager', 'مدير المخزون', [
            'inventory.*', 'reports.read',
        ]],
        'warehouse_employee' => ['Warehouse Employee', 'موظف المستودع', [
            'inventory.read', 'inventory.update', 'inventory.transfer', 'inventory.count.reconcile',
        ]],
        'sales_manager' => ['Sales Manager', 'مدير المبيعات', [
            'sales.*', 'reports.read', 'reports.export',
        ]],
        'sales_employee' => ['Sales Employee', 'موظف مبيعات', [
            'sales.read', 'sales.create', 'sales.update', 'sales.invoice.issue', 'sales.quotation.convert',
        ]],
        'purchasing_manager' => ['Purchasing Manager', 'مدير المشتريات', [
            'purchasing.*', 'reports.read',
        ]],
        'purchasing_employee' => ['Purchasing Employee', 'موظف مشتريات', [
            'purchasing.read', 'purchasing.create', 'purchasing.update', 'purchasing.po.approve',
        ]],
        'read_only' => ['Read Only', 'مستخدم للقراءة فقط', [
            'accounting.read', 'bank.read', 'sales.read', 'purchasing.read', 'inventory.read',
            'payroll.read', 'tax.read', 'reports.read',
        ]],
        'external_auditor' => ['External Auditor', 'مدقق خارجي', [
            'accounting.read', 'accounting.export', 'bank.read', 'reports.read', 'reports.export',
            'audit.view_logs',
        ]],
    ];

    public function run(): void
    {
        $now = now();

        foreach (self::ROLES as $slug => [$nameEn, $nameAr, $permissionPatterns]) {
            $roleId = DB::table('roles')->where('slug', $slug)->whereNull('company_id')->value('id');

            if ($roleId === null) {
                $roleId = DB::table('roles')->insertGetId([
                    'company_id'  => null,
                    'slug'        => $slug,
                    'name_en'     => $nameEn,
                    'name_ar'     => $nameAr,
                    'description' => null,
                    'scope'       => 'system',
                    'is_default'  => $slug === 'owner',
                    'created_at'  => $now,
                    'updated_at'  => $now,
                ]);
            } else {
                DB::table('roles')->where('id', $roleId)->update([
                    'name_en' => $nameEn, 'name_ar' => $nameAr, 'updated_at' => $now,
                ]);
            }

            $permissionIds = $this->resolvePermissionIds($permissionPatterns);

            $pivotRows = array_map(fn (int $pid) => [
                'role_id'       => $roleId,
                'permission_id' => $pid,
                'created_at'    => $now,
            ], $permissionIds);

            DB::table('role_permissions')->where('role_id', $roleId)->delete();
            foreach (array_chunk($pivotRows, 500) as $chunk) {
                DB::table('role_permissions')->insert($chunk);
            }
        }

        $this->command?->info(sprintf('Seeded %d system roles.', count(self::ROLES)));
    }

    private function resolvePermissionIds(array $patterns): array
    {
        if (in_array('*', $patterns, true)) {
            return DB::table('permissions')->pluck('id')->all();
        }

        $ids = [];
        foreach ($patterns as $pattern) {
            if (str_ends_with($pattern, '.*')) {
                $prefix = substr($pattern, 0, -1); // keep trailing '.'
                $ids = array_merge($ids, DB::table('permissions')
                    ->where('key', 'like', $prefix . '%')
                    ->pluck('id')->all());
            } else {
                $ids = array_merge($ids, DB::table('permissions')
                    ->where('key', $pattern)
                    ->pluck('id')->all());
            }
        }

        return array_values(array_unique($ids));
    }
}
```

Re-running `RoleSeeder` is safe: it resolves each role by `(slug, company_id IS NULL)`, updates
its labels if it already exists rather than inserting a duplicate, and replaces its permission
grant set atomically (delete-then-insert inside the same request) so that a change to the
`ROLES` matrix in a new release is reflected exactly, without leaving orphaned grants from a
previous version of the matrix.

# Chart of Accounts Templates (Gulf/Kuwait)

## Database Design

A **template** is a reusable, industry- and jurisdiction-flavored chart of accounts blueprint
that `CompanyProvisioningService::applyCoaTemplate()` clones into a company's real `accounts`
table (owned by the Accounting module doc) at company-creation time. Templates themselves live in
two tables owned by this document:

```sql
CREATE TABLE coa_templates (
    id              BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    code            VARCHAR(60)    NOT NULL,             -- 'kw_general_trading', 'kw_retail', ...
    name_en         VARCHAR(150)   NOT NULL,
    name_ar         VARCHAR(150)   NOT NULL,
    country_iso2    CHAR(2)        NULL REFERENCES countries(iso2),   -- NULL = jurisdiction-agnostic
    industry_code   VARCHAR(60)    NULL REFERENCES industries(code), -- NULL = general-purpose
    description     TEXT           NULL,
    is_default      BOOLEAN        NOT NULL DEFAULT false,
    is_active       BOOLEAN        NOT NULL DEFAULT true,
    created_at      TIMESTAMPTZ    NOT NULL DEFAULT now(),
    updated_at      TIMESTAMPTZ    NOT NULL DEFAULT now(),
    deleted_at      TIMESTAMPTZ    NULL,
    CONSTRAINT uq_coa_templates_code UNIQUE (code)
);

CREATE TABLE coa_template_lines (
    id              BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    template_id     BIGINT         NOT NULL REFERENCES coa_templates(id) ON DELETE CASCADE,
    parent_code     VARCHAR(20)    NULL,                 -- self-referential by 'code', resolved at clone time
    code            VARCHAR(20)    NOT NULL,              -- account code, e.g. '1000', '1100', '4000'
    name_en         VARCHAR(150)   NOT NULL,
    name_ar         VARCHAR(150)   NOT NULL,
    account_type_code VARCHAR(30)  NOT NULL REFERENCES account_types(code),  -- owned by Accounting doc
    normal_balance  VARCHAR(6)     NOT NULL,              -- 'debit' | 'credit'
    is_control_account BOOLEAN     NOT NULL DEFAULT false, -- AR/AP control accounts, cash, etc.
    sort_order      INTEGER        NOT NULL DEFAULT 0,
    created_at      TIMESTAMPTZ    NOT NULL DEFAULT now(),
    updated_at      TIMESTAMPTZ    NOT NULL DEFAULT now(),
    CONSTRAINT uq_coa_template_lines UNIQUE (template_id, code),
    CONSTRAINT ck_coa_template_lines_balance CHECK (normal_balance IN ('debit', 'credit'))
);

CREATE INDEX idx_coa_template_lines_template ON coa_template_lines (template_id);
CREATE INDEX idx_coa_template_lines_parent   ON coa_template_lines (template_id, parent_code);
```

`account_types` (owned by the Accounting module doc) is reused verbatim: its rows are
`asset`, `liability`, `equity`, `revenue`, `expense` with a fixed `normal_balance`. This document
does not re-seed `account_types` — `AccountTypeSeeder` (Accounting module) runs earlier in
`ReferenceDataSeeder`'s call order and this template seeder depends on its output.

## Seeder — Kuwait General Trading Template

The seeder below defines `kw_general_trading`, the default template offered to every new Kuwaiti
company during onboarding, and `kw_retail`, an industry variant. Both follow the standard 5-digit
numbering block convention: **1xxxx Assets, 2xxxx Liabilities, 3xxxx Equity, 4xxxx Revenue,
5xxxx Expenses** (a 4-digit legacy-compatible numbering is also supported by simply shortening the
codes; QAYD ships 4-digit codes by default to match Kuwaiti SME bookkeeping conventions and
widens to 5+ digits only for companies with more than 200 leaf accounts, handled by the
provisioning service, not this seeder).

```php
<?php
// database/seeders/CoaTemplateSeeder.php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CoaTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        $templateId = $this->upsertTemplate('kw_general_trading', 'Kuwait — General Trading',
            'الكويت — تجارة عامة', 'KW', null, true, $now);

        $this->upsertLines($templateId, $this->generalTradingLines(), $now);

        $retailId = $this->upsertTemplate('kw_retail', 'Kuwait — Retail',
            'الكويت — تجزئة', 'KW', 'retail', false, $now);

        $this->upsertLines($retailId, $this->retailLines(), $now);

        $this->command?->info('Seeded Kuwait COA templates: kw_general_trading, kw_retail.');
    }

    private function upsertTemplate(string $code, string $nameEn, string $nameAr, ?string $countryIso2,
        ?string $industryCode, bool $isDefault, $now): int
    {
        $id = DB::table('coa_templates')->where('code', $code)->value('id');
        $data = [
            'code' => $code, 'name_en' => $nameEn, 'name_ar' => $nameAr,
            'country_iso2' => $countryIso2, 'industry_code' => $industryCode,
            'is_default' => $isDefault, 'is_active' => true, 'updated_at' => $now,
        ];
        if ($id === null) {
            $data['created_at'] = $now;
            return DB::table('coa_templates')->insertGetId($data);
        }
        DB::table('coa_templates')->where('id', $id)->update($data);
        return $id;
    }

    private function upsertLines(int $templateId, array $lines, $now): void
    {
        foreach ($lines as $sort => $line) {
            DB::table('coa_template_lines')->updateOrInsert(
                ['template_id' => $templateId, 'code' => $line['code']],
                [
                    'parent_code'         => $line['parent_code'] ?? null,
                    'name_en'             => $line['name_en'],
                    'name_ar'             => $line['name_ar'],
                    'account_type_code'   => $line['type'],
                    'normal_balance'      => $line['balance'],
                    'is_control_account'  => $line['control'] ?? false,
                    'sort_order'          => $sort,
                    'updated_at'          => $now,
                    'created_at'          => $now,
                ]
            );
        }
    }

    /** @return array<int, array{code:string,parent_code:?string,name_en:string,name_ar:string,type:string,balance:string,control?:bool}> */
    private function generalTradingLines(): array
    {
        return [
            // ---- 1000 Assets ----
            ['code' => '1000', 'parent_code' => null, 'name_en' => 'Assets', 'name_ar' => 'الأصول', 'type' => 'asset', 'balance' => 'debit'],
            ['code' => '1100', 'parent_code' => '1000', 'name_en' => 'Current Assets', 'name_ar' => 'الأصول المتداولة', 'type' => 'asset', 'balance' => 'debit'],
            ['code' => '1110', 'parent_code' => '1100', 'name_en' => 'Cash on Hand', 'name_ar' => 'النقد في الصندوق', 'type' => 'asset', 'balance' => 'debit'],
            ['code' => '1120', 'parent_code' => '1100', 'name_en' => 'Bank Accounts — KWD', 'name_ar' => 'حسابات بنكية — دينار كويتي', 'type' => 'asset', 'balance' => 'debit', 'control' => true],
            ['code' => '1121', 'parent_code' => '1100', 'name_en' => 'Bank Accounts — Foreign Currency', 'name_ar' => 'حسابات بنكية — عملة أجنبية', 'type' => 'asset', 'balance' => 'debit', 'control' => true],
            ['code' => '1130', 'parent_code' => '1100', 'name_en' => 'Accounts Receivable — Trade', 'name_ar' => 'ذمم مدينة — تجارية', 'type' => 'asset', 'balance' => 'debit', 'control' => true],
            ['code' => '1140', 'parent_code' => '1100', 'name_en' => 'Allowance for Doubtful Debts', 'name_ar' => 'مخصص الديون المشكوك في تحصيلها', 'type' => 'asset', 'balance' => 'credit'],
            ['code' => '1150', 'parent_code' => '1100', 'name_en' => 'Inventory', 'name_ar' => 'المخزون', 'type' => 'asset', 'balance' => 'debit'],
            ['code' => '1160', 'parent_code' => '1100', 'name_en' => 'Prepaid Expenses', 'name_ar' => 'مصروفات مدفوعة مقدماً', 'type' => 'asset', 'balance' => 'debit'],
            ['code' => '1170', 'parent_code' => '1100', 'name_en' => 'Employee Advances', 'name_ar' => 'سلف الموظفين', 'type' => 'asset', 'balance' => 'debit'],
            ['code' => '1180', 'parent_code' => '1100', 'name_en' => 'VAT Receivable / Input Tax', 'name_ar' => 'ضريبة القيمة المضافة المدينة', 'type' => 'asset', 'balance' => 'debit'],
            ['code' => '1200', 'parent_code' => '1000', 'name_en' => 'Non-Current Assets', 'name_ar' => 'الأصول غير المتداولة', 'type' => 'asset', 'balance' => 'debit'],
            ['code' => '1210', 'parent_code' => '1200', 'name_en' => 'Furniture & Fixtures', 'name_ar' => 'أثاث وتجهيزات', 'type' => 'asset', 'balance' => 'debit'],
            ['code' => '1220', 'parent_code' => '1200', 'name_en' => 'Motor Vehicles', 'name_ar' => 'وسائل نقل', 'type' => 'asset', 'balance' => 'debit'],
            ['code' => '1230', 'parent_code' => '1200', 'name_en' => 'Computer Equipment', 'name_ar' => 'معدات حاسوبية', 'type' => 'asset', 'balance' => 'debit'],
            ['code' => '1240', 'parent_code' => '1200', 'name_en' => 'Leasehold Improvements', 'name_ar' => 'تحسينات على عقارات مستأجرة', 'type' => 'asset', 'balance' => 'debit'],
            ['code' => '1290', 'parent_code' => '1200', 'name_en' => 'Accumulated Depreciation', 'name_ar' => 'مجمع الاستهلاك', 'type' => 'asset', 'balance' => 'credit'],

            // ---- 2000 Liabilities ----
            ['code' => '2000', 'parent_code' => null, 'name_en' => 'Liabilities', 'name_ar' => 'الالتزامات', 'type' => 'liability', 'balance' => 'credit'],
            ['code' => '2100', 'parent_code' => '2000', 'name_en' => 'Current Liabilities', 'name_ar' => 'الالتزامات المتداولة', 'type' => 'liability', 'balance' => 'credit'],
            ['code' => '2110', 'parent_code' => '2100', 'name_en' => 'Accounts Payable — Trade', 'name_ar' => 'ذمم دائنة — تجارية', 'type' => 'liability', 'balance' => 'credit', 'control' => true],
            ['code' => '2120', 'parent_code' => '2100', 'name_en' => 'Accrued Expenses', 'name_ar' => 'مصروفات مستحقة', 'type' => 'liability', 'balance' => 'credit'],
            ['code' => '2130', 'parent_code' => '2100', 'name_en' => 'VAT Payable / Output Tax', 'name_ar' => 'ضريبة القيمة المضافة الدائنة', 'type' => 'liability', 'balance' => 'credit'],
            ['code' => '2140', 'parent_code' => '2100', 'name_en' => 'Salaries & Wages Payable', 'name_ar' => 'رواتب وأجور مستحقة', 'type' => 'liability', 'balance' => 'credit'],
            ['code' => '2150', 'parent_code' => '2100', 'name_en' => 'End-of-Service Indemnity Provision', 'name_ar' => 'مخصص مكافأة نهاية الخدمة', 'type' => 'liability', 'balance' => 'credit'],
            ['code' => '2160', 'parent_code' => '2100', 'name_en' => 'Customer Deposits / Advances', 'name_ar' => 'دفعات مقدمة من العملاء', 'type' => 'liability', 'balance' => 'credit'],
            ['code' => '2200', 'parent_code' => '2000', 'name_en' => 'Non-Current Liabilities', 'name_ar' => 'الالتزامات غير المتداولة', 'type' => 'liability', 'balance' => 'credit'],
            ['code' => '2210', 'parent_code' => '2200', 'name_en' => 'Long-Term Loans', 'name_ar' => 'قروض طويلة الأجل', 'type' => 'liability', 'balance' => 'credit'],

            // ---- 3000 Equity ----
            ['code' => '3000', 'parent_code' => null, 'name_en' => 'Equity', 'name_ar' => 'حقوق الملكية', 'type' => 'equity', 'balance' => 'credit'],
            ['code' => '3100', 'parent_code' => '3000', 'name_en' => 'Share Capital', 'name_ar' => 'رأس المال', 'type' => 'equity', 'balance' => 'credit'],
            ['code' => '3200', 'parent_code' => '3000', 'name_en' => 'Statutory Reserve', 'name_ar' => 'الاحتياطي القانوني', 'type' => 'equity', 'balance' => 'credit'],
            ['code' => '3300', 'parent_code' => '3000', 'name_en' => 'Retained Earnings', 'name_ar' => 'الأرباح المرحلة', 'type' => 'equity', 'balance' => 'credit'],
            ['code' => '3400', 'parent_code' => '3000', 'name_en' => 'Current Year Earnings', 'name_ar' => 'أرباح السنة الحالية', 'type' => 'equity', 'balance' => 'credit'],
            ['code' => '3500', 'parent_code' => '3000', 'name_en' => "Partners'/Owner's Drawings", 'name_ar' => 'مسحوبات الشركاء/المالك', 'type' => 'equity', 'balance' => 'debit'],

            // ---- 4000 Revenue ----
            ['code' => '4000', 'parent_code' => null, 'name_en' => 'Revenue', 'name_ar' => 'الإيرادات', 'type' => 'revenue', 'balance' => 'credit'],
            ['code' => '4100', 'parent_code' => '4000', 'name_en' => 'Sales Revenue', 'name_ar' => 'إيرادات المبيعات', 'type' => 'revenue', 'balance' => 'credit'],
            ['code' => '4200', 'parent_code' => '4000', 'name_en' => 'Service Revenue', 'name_ar' => 'إيرادات الخدمات', 'type' => 'revenue', 'balance' => 'credit'],
            ['code' => '4300', 'parent_code' => '4000', 'name_en' => 'Sales Returns & Allowances', 'name_ar' => 'مردودات ومسموحات المبيعات', 'type' => 'revenue', 'balance' => 'debit'],
            ['code' => '4400', 'parent_code' => '4000', 'name_en' => 'Sales Discounts', 'name_ar' => 'خصم مسموح به', 'type' => 'revenue', 'balance' => 'debit'],
            ['code' => '4900', 'parent_code' => '4000', 'name_en' => 'Other Income', 'name_ar' => 'إيرادات أخرى', 'type' => 'revenue', 'balance' => 'credit'],

            // ---- 5000 Expenses ----
            ['code' => '5000', 'parent_code' => null, 'name_en' => 'Expenses', 'name_ar' => 'المصروفات', 'type' => 'expense', 'balance' => 'debit'],
            ['code' => '5100', 'parent_code' => '5000', 'name_en' => 'Cost of Goods Sold', 'name_ar' => 'تكلفة البضاعة المباعة', 'type' => 'expense', 'balance' => 'debit'],
            ['code' => '5200', 'parent_code' => '5000', 'name_en' => 'Salaries & Wages', 'name_ar' => 'الرواتب والأجور', 'type' => 'expense', 'balance' => 'debit'],
            ['code' => '5210', 'parent_code' => '5000', 'name_en' => 'Staff Housing & Transport Allowance', 'name_ar' => 'بدل سكن ونقل الموظفين', 'type' => 'expense', 'balance' => 'debit'],
            ['code' => '5220', 'parent_code' => '5000', 'name_en' => 'End-of-Service Indemnity Expense', 'name_ar' => 'مصروف مكافأة نهاية الخدمة', 'type' => 'expense', 'balance' => 'debit'],
            ['code' => '5300', 'parent_code' => '5000', 'name_en' => 'Rent Expense', 'name_ar' => 'مصروف الإيجار', 'type' => 'expense', 'balance' => 'debit'],
            ['code' => '5400', 'parent_code' => '5000', 'name_en' => 'Utilities Expense', 'name_ar' => 'مصروف المرافق (كهرباء وماء)', 'type' => 'expense', 'balance' => 'debit'],
            ['code' => '5500', 'parent_code' => '5000', 'name_en' => 'Marketing & Advertising', 'name_ar' => 'التسويق والدعاية', 'type' => 'expense', 'balance' => 'debit'],
            ['code' => '5600', 'parent_code' => '5000', 'name_en' => 'Depreciation Expense', 'name_ar' => 'مصروف الاستهلاك', 'type' => 'expense', 'balance' => 'debit'],
            ['code' => '5700', 'parent_code' => '5000', 'name_en' => 'Bank Charges', 'name_ar' => 'رسوم بنكية', 'type' => 'expense', 'balance' => 'debit'],
            ['code' => '5800', 'parent_code' => '5000', 'name_en' => 'Professional Fees', 'name_ar' => 'أتعاب مهنية', 'type' => 'expense', 'balance' => 'debit'],
            ['code' => '5810', 'parent_code' => '5000', 'name_en' => 'Government Fees & Licenses', 'name_ar' => 'رسوم حكومية ورخص', 'type' => 'expense', 'balance' => 'debit'],
            ['code' => '5900', 'parent_code' => '5000', 'name_en' => 'Bad Debt Expense', 'name_ar' => 'مصروف ديون معدومة', 'type' => 'expense', 'balance' => 'debit'],
            ['code' => '5990', 'parent_code' => '5000', 'name_en' => 'Miscellaneous Expense', 'name_ar' => 'مصروفات متنوعة', 'type' => 'expense', 'balance' => 'debit'],
        ];
    }

    /** Retail overlay: adds POS/merchandising accounts on top of the general trading base. */
    private function retailLines(): array
    {
        $base = $this->generalTradingLines();
        $retailExtras = [
            ['code' => '1151', 'parent_code' => '1150', 'name_en' => 'Inventory — In Transit', 'name_ar' => 'مخزون بالطريق', 'type' => 'asset', 'balance' => 'debit'],
            ['code' => '1152', 'parent_code' => '1150', 'name_en' => 'Inventory — Damaged/Obsolete Reserve', 'name_ar' => 'مخصص مخزون تالف/راكد', 'type' => 'asset', 'balance' => 'credit'],
            ['code' => '4110', 'parent_code' => '4100', 'name_en' => 'POS Sales — Cash', 'name_ar' => 'مبيعات نقاط البيع — نقدي', 'type' => 'revenue', 'balance' => 'credit'],
            ['code' => '4120', 'parent_code' => '4100', 'name_en' => 'POS Sales — Card/KNET', 'name_ar' => 'مبيعات نقاط البيع — بطاقة/كي نت', 'type' => 'revenue', 'balance' => 'credit'],
            ['code' => '5150', 'parent_code' => '5100', 'name_en' => 'Shrinkage / Inventory Loss', 'name_ar' => 'هدر ونقص المخزون', 'type' => 'expense', 'balance' => 'debit'],
            ['code' => '5710', 'parent_code' => '5700', 'name_en' => 'Payment Gateway Fees (KNET/Card)', 'name_ar' => 'رسوم بوابات الدفع (كي نت/بطاقات)', 'type' => 'expense', 'balance' => 'debit'],
        ];
        return array_merge($base, $retailExtras);
    }
}
```

The `applyCoaTemplate()` provisioning routine clones template lines into the real `accounts`
table by resolving `parent_code` → the newly-created parent's `id` in two passes (insert all
lines with `parent_id = NULL` first, keyed by a temporary `code → id` map, then a second pass
`UPDATE accounts SET parent_id = ... WHERE code = ...` using that map) — this is a Service
concern documented fully in the Accounting module's own Workflow section; it is referenced here
only to clarify how a template becomes a company's live chart of accounts.

# Industries

## Purpose Within Seeding

`industries` is a small, flat reference table, but it fans out into three unrelated consumers,
which is why it earns its own top-level section rather than a paragraph inside `# Reference Data`:

1. **Onboarding.** `CompanyProvisioningService::suggestCoaTemplate(Company $company)` looks up
   `coa_templates` WHERE `industry_code = $company->industry_code` (falling back to the
   jurisdiction-only, industry-agnostic template, e.g. `kw_general_trading`, when no industry-
   specific template exists) to pre-select the chart of accounts a new company sees during setup.
2. **Classification on business partners.** `customers.industry_code` and `vendors.industry_code`
   (owned by the Sales and Purchasing module docs respectively) are nullable foreign keys into
   this same catalog, so a company can tag "this customer is in Construction" without inventing a
   second, inconsistent industry list.
3. **AI grounding.** The Forecast Agent and the Reporting Agent (see the platform-wide AI
   Responsibilities conventions) use `industry_code` as a segmentation dimension for peer
   benchmarking ("your gross margin vs. other General Trading companies on QAYD"), and the Fraud
   Detection agent uses it to calibrate industry-appropriate anomaly thresholds — a restaurant
   with 40% daily cash receipts is normal; a professional-services firm with 40% cash is a
   fraud-review trigger.

## Database Design

```sql
CREATE TABLE industries (
    id              BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    code            VARCHAR(60)    NOT NULL,          -- 'general_trading', 'retail', 'construction_contracting', ...
    name_en         VARCHAR(120)   NOT NULL,
    name_ar         VARCHAR(120)   NOT NULL,
    sector          VARCHAR(40)    NOT NULL,          -- 'commercial' | 'industrial' | 'services' | 'financial' | 'primary'
    description     TEXT           NULL,
    is_active       BOOLEAN        NOT NULL DEFAULT true,
    created_at      TIMESTAMPTZ    NOT NULL DEFAULT now(),
    updated_at      TIMESTAMPTZ    NOT NULL DEFAULT now(),
    deleted_at      TIMESTAMPTZ    NULL,
    CONSTRAINT uq_industries_code UNIQUE (code),
    CONSTRAINT ck_industries_sector CHECK (sector IN ('commercial', 'industrial', 'services', 'financial', 'primary'))
);

CREATE INDEX idx_industries_sector ON industries (sector) WHERE deleted_at IS NULL;
```

Deliberately, `industries` does **not** carry a foreign key back to `coa_templates`. A naive
design would add `industries.default_coa_template_code REFERENCES coa_templates(code)`, but
`coa_templates.industry_code` already points the other way (template → industry), and a mutual
foreign key between two reference tables forces every future insert to either defer constraint
checking or insert with a temporary NULL and back-fill — extra ceremony for zero benefit, since
"what is the default template for industry X" is already a one-line query:

```sql
SELECT * FROM coa_templates WHERE industry_code = :industry_code AND is_active = true
ORDER BY is_default DESC LIMIT 1;
```

`CoaTemplateSeeder` therefore must run after `IndustrySeeder` in the dependency order (it already
does — see `# Reference Data` — `IndustrySeeder::class` precedes `CoaTemplateSeeder::class` in
`ReferenceDataSeeder::run()`).

## Seeder

```php
<?php
// database/seeders/IndustrySeeder.php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class IndustrySeeder extends Seeder
{
    /** code => [name_en, name_ar, sector] */
    private const INDUSTRIES = [
        'general_trading'          => ['General Trading', 'التجارة العامة', 'commercial'],
        'retail'                   => ['Retail', 'تجارة التجزئة', 'commercial'],
        'wholesale_trade'          => ['Wholesale Trade', 'تجارة الجملة', 'commercial'],
        'ecommerce'                => ['E-Commerce', 'التجارة الإلكترونية', 'commercial'],
        'construction_contracting' => ['Construction & Contracting', 'المقاولات والإنشاءات', 'industrial'],
        'real_estate'              => ['Real Estate', 'العقارات', 'financial'],
        'manufacturing'            => ['Manufacturing & Light Industry', 'الصناعات التحويلية', 'industrial'],
        'oil_gas_services'         => ['Oil & Gas Services', 'خدمات النفط والغاز', 'industrial'],
        'food_beverage'            => ['Food & Beverage / Restaurants', 'الأغذية والمشروبات والمطاعم', 'services'],
        'hospitality_tourism'      => ['Hospitality & Tourism', 'الضيافة والسياحة', 'services'],
        'healthcare_clinics'       => ['Healthcare & Clinics', 'الرعاية الصحية والعيادات', 'services'],
        'professional_services'    => ['Professional Services (Legal/Consulting/Accounting)', 'الخدمات المهنية (قانونية، استشارية، محاسبية)', 'services'],
        'logistics_transportation' => ['Logistics & Transportation', 'الخدمات اللوجستية والنقل', 'services'],
        'information_technology'   => ['Information Technology', 'تقنية المعلومات', 'services'],
        'media_marketing'          => ['Media & Marketing', 'الإعلام والتسويق', 'services'],
        'education_training'       => ['Education & Training', 'التعليم والتدريب', 'services'],
        'financial_services'       => ['Financial Services', 'الخدمات المالية', 'financial'],
        'automotive'               => ['Automotive Sales & Service', 'بيع وصيانة السيارات', 'commercial'],
        'beauty_wellness'          => ['Beauty & Wellness', 'التجميل والعناية', 'services'],
        'agriculture_fisheries'    => ['Agriculture & Fisheries', 'الزراعة والثروة السمكية', 'primary'],
    ];

    public function run(): void
    {
        $now = now();
        $payload = [];

        foreach (self::INDUSTRIES as $code => [$nameEn, $nameAr, $sector]) {
            $payload[] = [
                'code' => $code, 'name_en' => $nameEn, 'name_ar' => $nameAr, 'sector' => $sector,
                'description' => null, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
            ];
        }

        DB::table('industries')->upsert(
            $payload,
            ['code'],
            ['name_en', 'name_ar', 'sector', 'is_active', 'updated_at']
        );

        $this->command?->info(sprintf('Seeded %d industries.', count($payload)));
    }
}
```

## Reference Table

| Code | English | Arabic | Sector |
|---|---|---|---|
| `general_trading` | General Trading | التجارة العامة | commercial |
| `retail` | Retail | تجارة التجزئة | commercial |
| `wholesale_trade` | Wholesale Trade | تجارة الجملة | commercial |
| `ecommerce` | E-Commerce | التجارة الإلكترونية | commercial |
| `construction_contracting` | Construction & Contracting | المقاولات والإنشاءات | industrial |
| `real_estate` | Real Estate | العقارات | financial |
| `manufacturing` | Manufacturing & Light Industry | الصناعات التحويلية | industrial |
| `oil_gas_services` | Oil & Gas Services | خدمات النفط والغاز | industrial |
| `food_beverage` | Food & Beverage / Restaurants | الأغذية والمشروبات والمطاعم | services |
| `hospitality_tourism` | Hospitality & Tourism | الضيافة والسياحة | services |
| `healthcare_clinics` | Healthcare & Clinics | الرعاية الصحية والعيادات | services |
| `professional_services` | Professional Services | الخدمات المهنية | services |
| `logistics_transportation` | Logistics & Transportation | الخدمات اللوجستية والنقل | services |
| `information_technology` | Information Technology | تقنية المعلومات | services |
| `media_marketing` | Media & Marketing | الإعلام والتسويق | services |
| `education_training` | Education & Training | التعليم والتدريب | services |
| `financial_services` | Financial Services | الخدمات المالية | financial |
| `automotive` | Automotive Sales & Service | بيع وصيانة السيارات | commercial |
| `beauty_wellness` | Beauty & Wellness | التجميل والعناية | services |
| `agriculture_fisheries` | Agriculture & Fisheries | الزراعة والثروة السمكية | primary |

`kw_retail`'s `industry_code` (seeded in `# Chart of Accounts Templates`) resolves to `retail` in
this table; every other built-in template ships with `industry_code = NULL` (jurisdiction-only,
industry-agnostic) except that `kw_general_trading` is the default fallback template rather than
an industry-specific one — the *industry* named `general_trading` in this table still exists so
companies and demo tenants can tag themselves with it for benchmarking purposes, decoupled from
which COA template they happen to use.

# Demo Companies

## Purpose

Demo companies exist to answer one product question with real, interactive data instead of a
slide deck: "if I were already a QAYD customer three months in, what would my books look like?"
Every demo tenant is a fully real row in every multi-tenant table — same schema, same
`company_id` scoping at the query layer, same validation, same double-entry invariants — flagged
only by `companies.is_demo = true`. Nothing about a demo company's *data* is fake in structure;
only its *origin* (seeded, not customer-entered) and its *lifecycle* (nightly reset) differ from a
paying customer's tenant.

## Schema Extension

The foundation `companies` table (owned by the core platform doc, not redefined here) needs two
columns to support the reset lifecycle below. This document owns the additive migration for them
because they exist specifically to serve seeding:

```sql
ALTER TABLE companies ADD COLUMN IF NOT EXISTS is_demo       BOOLEAN     NOT NULL DEFAULT false;
ALTER TABLE companies ADD COLUMN IF NOT EXISTS demo_reset_at TIMESTAMPTZ NULL;

CREATE INDEX IF NOT EXISTS idx_companies_is_demo
    ON companies (is_demo)
    WHERE deleted_at IS NULL AND is_demo = true;
```

```php
<?php
// database/migrations/2026_02_01_000001_add_demo_columns_to_companies_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->boolean('is_demo')->default(false)->after('base_currency_code');
            $table->timestampTz('demo_reset_at')->nullable()->after('is_demo');
            $table->index(['is_demo'], 'idx_companies_is_demo');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropIndex('idx_companies_is_demo');
            $table->dropColumn(['is_demo', 'demo_reset_at']);
        });
    }
};
```

Every notification dispatcher in the platform (SMS, e-mail, WhatsApp, push, webhook — each owned
by its respective doc) checks `company.is_demo` before sending and short-circuits to a no-op
logger instead: a prospect clicking around a trial company must never cause a real Twilio SMS or
a real webhook to fire against a third party. This is enforced once, centrally, in
`NotificationDispatcher::send()`'s base path, not per-channel, so a new notification channel is
demo-safe by default without its author needing to remember the rule.

## Seeder

`DemoCompanySeeder` builds one complete, internally consistent tenant: a company, two branches,
five users covering five different roles, a chart of accounts cloned from `kw_general_trading`,
three customers, three vendors, five products with a category and unit of measure, a balanced
opening-balance journal entry, and two realistic follow-on transactions (a sales invoice and a
purchase bill). Every step that touches the ledger goes through `JournalPostingService::post()` —
the same Service a real company's Sales/Purchasing/Banking domain-event listeners call — so demo
data is provably balanced by construction, not by a human eyeballing a JSON fixture.

```php
<?php
// database/seeders/DemoCompanySeeder.php

namespace Database\Seeders;

use App\Models\{Company, Branch, User, Role, Account, Customer, Vendor,
    Product, ProductCategory, UnitOfMeasure};
use App\Services\Accounting\JournalPostingService;
use App\Services\Company\CompanyProvisioningService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DemoCompanySeeder extends Seeder
{
    private const COMPANY_CODE = 'DEMO-KW-001';

    public function run(): void
    {
        if (app()->environment('production') && ! ($this->command?->option('force') ?? false)) {
            throw new \RuntimeException(
                'DemoCompanySeeder requires --force in production and must only be invoked '.
                'by the ring-fenced demo-tenant provisioning runbook (see Production Safety).'
            );
        }

        DB::transaction(function () {
            $company  = $this->provisionCompany();
            $branches = $this->provisionBranches($company);
            $users    = $this->provisionUsers($company, $branches['hq']);

            app(CompanyProvisioningService::class)->applyCoaTemplate($company, 'kw_general_trading');

            $this->provisionCustomersVendorsProducts($company);
            $this->provisionOpeningBalances($company, $branches['hq'], $users['owner']);
            $this->provisionSampleTransactions($company, $branches['hq'], $users['accountant']);
        });

        $this->command?->info('Demo company "QAYD Demo Trading Co. W.L.L." (DEMO-KW-001) seeded end-to-end.');
    }

    private function provisionCompany(): Company
    {
        return Company::updateOrCreate(
            ['code' => self::COMPANY_CODE],
            [
                'name_en'                 => 'QAYD Demo Trading Co. W.L.L.',
                'name_ar'                 => 'شركة قيد التجريبية للتجارة ذ.م.م',
                'country_iso2'            => 'KW',
                'base_currency_code'      => 'KWD',
                'default_language_code'   => 'ar',
                'industry_code'           => 'general_trading',
                'fiscal_year_start_month' => 1,
                'is_demo'                 => true,
                'demo_reset_at'           => now(),
            ]
        );
    }

    private function provisionBranches(Company $company): array
    {
        $hq = Branch::updateOrCreate(
            ['company_id' => $company->id, 'code' => 'HQ'],
            ['name_en' => 'Head Office — Kuwait City', 'name_ar' => 'المكتب الرئيسي — مدينة الكويت', 'is_active' => true]
        );
        $hawally = Branch::updateOrCreate(
            ['company_id' => $company->id, 'code' => 'HWL'],
            ['name_en' => 'Hawally Branch', 'name_ar' => 'فرع حولي', 'is_active' => true]
        );

        return ['hq' => $hq, 'hawally' => $hawally];
    }

    private function provisionUsers(Company $company, Branch $hq): array
    {
        $specs = [
            'owner'      => ['Ahmad Al-Sabah',   'owner'],
            'cfo'        => ['Fatima Al-Rashid', 'cfo'],
            'accountant' => ['Yousef Al-Enezi',  'accountant'],
            'sales'      => ['Mariam Al-Fadhli', 'sales_employee'],
            'warehouse'  => ['Salem Al-Mutairi',  'warehouse_employee'],
        ];

        $users = [];
        foreach ($specs as $key => [$name, $roleSlug]) {
            $user = User::updateOrCreate(
                ['email' => Str::slug($key).'@demo.qayd.app'],
                ['name' => $name, 'password' => bcrypt(Str::random(32)), 'is_demo' => true]
            );
            $user->companies()->syncWithoutDetaching([$company->id => ['branch_id' => $hq->id]]);

            $companyRole = Role::firstOrCreate(
                ['company_id' => $company->id, 'slug' => $roleSlug],
                fn () => app(CompanyProvisioningService::class)->cloneSystemRoleForCompany($company, $roleSlug)
            );
            $user->roles()->syncWithoutDetaching([$companyRole->id => ['company_id' => $company->id]]);

            $users[$key] = $user;
        }

        return $users;
    }

    private function provisionCustomersVendorsProducts(Company $company): void
    {
        $customers = [
            ['code' => 'CUST-001', 'name_en' => 'Al-Bairaq Trading Co.',       'name_ar' => 'شركة البيرق التجارية',       'industry_code' => 'retail'],
            ['code' => 'CUST-002', 'name_en' => 'Gulf Star Restaurants Group', 'name_ar' => 'مجموعة نجمة الخليج للمطاعم', 'industry_code' => 'food_beverage'],
            ['code' => 'CUST-003', 'name_en' => 'Marina Real Estate LLC',      'name_ar' => 'مارينا للعقارات',            'industry_code' => 'real_estate'],
        ];
        foreach ($customers as $c) {
            Customer::updateOrCreate(['company_id' => $company->id, 'code' => $c['code']], $c + [
                'currency_code' => 'KWD', 'payment_terms_days' => 30, 'is_active' => true,
            ]);
        }

        $vendors = [
            ['code' => 'VEND-001', 'name_en' => 'Kuwait Office Supplies Co.', 'name_ar' => 'شركة الكويت للأدوات المكتبية'],
            ['code' => 'VEND-002', 'name_en' => 'Gulf Logistics & Freight',   'name_ar' => 'الخليج للخدمات اللوجستية والشحن'],
            ['code' => 'VEND-003', 'name_en' => 'Al-Waha Import & Export',    'name_ar' => 'الواحة للاستيراد والتصدير'],
        ];
        foreach ($vendors as $v) {
            Vendor::updateOrCreate(['company_id' => $company->id, 'code' => $v['code']], $v + [
                'currency_code' => 'KWD', 'payment_terms_days' => 30, 'is_active' => true,
            ]);
        }

        $uom = UnitOfMeasure::updateOrCreate(
            ['company_id' => $company->id, 'code' => 'PCS'],
            ['name_en' => 'Pieces', 'name_ar' => 'قطعة']
        );
        $category = ProductCategory::updateOrCreate(
            ['company_id' => $company->id, 'code' => 'GEN'],
            ['name_en' => 'General Merchandise', 'name_ar' => 'بضائع عامة']
        );

        $products = [
            ['code' => 'PRD-1001', 'name_en' => 'A4 Copy Paper (Box of 5 Reams)', 'name_ar' => 'ورق تصوير A4 (5 رزم)',        'cost' => '4.5000',  'price' => '7.9000'],
            ['code' => 'PRD-1002', 'name_en' => 'Office Chair — Ergonomic',       'name_ar' => 'كرسي مكتب مريح',              'cost' => '28.0000', 'price' => '45.0000'],
            ['code' => 'PRD-1003', 'name_en' => 'LED Desk Lamp',                 'name_ar' => 'مصباح مكتب LED',              'cost' => '5.2000',  'price' => '9.5000'],
            ['code' => 'PRD-1004', 'name_en' => 'Wireless Keyboard & Mouse Set', 'name_ar' => 'طقم لوحة مفاتيح وماوس لاسلكي', 'cost' => '9.0000',  'price' => '15.0000'],
            ['code' => 'PRD-1005', 'name_en' => 'Filing Cabinet (3-Drawer)',     'name_ar' => 'خزانة ملفات (3 أدراج)',       'cost' => '22.0000', 'price' => '38.0000'],
        ];
        foreach ($products as $p) {
            Product::updateOrCreate(['company_id' => $company->id, 'code' => $p['code']], $p + [
                'category_id' => $category->id, 'uom_id' => $uom->id, 'currency_code' => 'KWD', 'is_active' => true,
            ]);
        }
    }

    /** Opening balances are posted through the same JournalPostingService a real company uses. */
    private function provisionOpeningBalances(Company $company, Branch $hq, User $owner): void
    {
        $cash   = Account::where('company_id', $company->id)->where('code', '1110')->firstOrFail();
        $bank   = Account::where('company_id', $company->id)->where('code', '1120')->firstOrFail();
        $ar     = Account::where('company_id', $company->id)->where('code', '1130')->firstOrFail();
        $ap     = Account::where('company_id', $company->id)->where('code', '2110')->firstOrFail();
        $equity = Account::where('company_id', $company->id)->where('code', '3100')->firstOrFail();

        app(JournalPostingService::class)->post([
            'company_id' => $company->id,
            'branch_id'  => $hq->id,
            'entry_date' => now()->subMonths(3)->toDateString(),
            'reference'  => 'OPEN-2026-0001',
            'memo_en'    => 'Opening balances — demo company incorporation',
            'memo_ar'    => 'الأرصدة الافتتاحية — تأسيس الشركة التجريبية',
            'created_by' => $owner->id,
            'lines' => [
                ['account_id' => $cash->id,   'debit' => '2000.0000',  'credit' => '0.0000'],
                ['account_id' => $bank->id,   'debit' => '45000.0000', 'credit' => '0.0000'],
                ['account_id' => $ar->id,     'debit' => '8500.0000',  'credit' => '0.0000'],
                ['account_id' => $ap->id,     'debit' => '0.0000',     'credit' => '5500.0000'],
                ['account_id' => $equity->id, 'debit' => '0.0000',     'credit' => '50000.0000'],
            ],
            'auto_post' => true,
        ]);
    }

    /** Sales and purchasing sample activity — each posts via its own module's Service, never raw inserts. */
    private function provisionSampleTransactions(Company $company, Branch $hq, User $accountant): void
    {
        app(\App\Services\Sales\InvoiceService::class)->createAndIssue([
            'company_id' => $company->id, 'branch_id' => $hq->id,
            'customer_code' => 'CUST-001', 'currency_code' => 'KWD',
            'issue_date' => now()->subDays(10)->toDateString(), 'due_date' => now()->addDays(20)->toDateString(),
            'created_by' => $accountant->id,
            'items' => [
                ['product_code' => 'PRD-1001', 'qty' => '10.0000', 'unit_price' => '7.9000'],
                ['product_code' => 'PRD-1002', 'qty' => '4.0000',  'unit_price' => '45.0000'],
            ],
        ]);

        app(\App\Services\Purchasing\BillService::class)->createAndApprove([
            'company_id' => $company->id, 'branch_id' => $hq->id,
            'vendor_code' => 'VEND-001', 'currency_code' => 'KWD',
            'bill_date' => now()->subDays(15)->toDateString(), 'due_date' => now()->addDays(15)->toDateString(),
            'created_by' => $accountant->id,
            'items' => [
                ['product_code' => 'PRD-1001', 'qty' => '50.0000', 'unit_cost' => '4.5000'],
            ],
        ]);
    }
}
```

## Demo Tenant Reset Lifecycle

Demo companies are reachable from the public "Try QAYD" trial link and are therefore the only
tenants a completely anonymous visitor can mutate (create an invoice, delete a customer, void a
journal entry). QAYD does not sandbox those writes at the query layer — it lets them happen
exactly as they would for a real customer, so the demo genuinely reflects the product — and
instead resets the blast radius on a fixed schedule:

```php
<?php
// app/Console/Commands/ResetDemoCompanies.php

namespace App\Console\Commands;

use App\Models\Company;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

class ResetDemoCompanies extends Command
{
    protected $signature = 'demo:reset';
    protected $description = 'Soft-delete stale demo companies and re-provision a fresh copy.';

    public function handle(): int
    {
        $stale = Company::where('is_demo', true)
            ->where('demo_reset_at', '<', now()->subHours(24))
            ->get();

        foreach ($stale as $company) {
            $company->delete(); // soft delete — deleted_at set, never a hard DELETE, per platform rule
            $this->info("Soft-deleted stale demo company #{$company->id} ({$company->code}).");
        }

        Artisan::call('db:seed', ['--class' => \Database\Seeders\DemoCompanySeeder::class, '--force' => true]);
        $this->info('Fresh demo company provisioned.');

        return self::SUCCESS;
    }
}
```

```php
// routes/console.php (Laravel 12 scheduling)
use Illuminate\Support\Facades\Schedule;

Schedule::command('demo:reset')->dailyAt('03:00')->timezone('Asia/Kuwait')->onOneServer();
```

## What Gets Seeded — Summary

| Entity | Count | Notes |
|---|---|---|
| Company | 1 | `DEMO-KW-001`, `is_demo = true`, base currency KWD |
| Branches | 2 | HQ (Kuwait City), Hawally |
| Users | 5 | Owner, CFO, Accountant, Sales Employee, Warehouse Employee — one per key role |
| Chart of Accounts | ~45 accounts | Cloned from `kw_general_trading` template |
| Customers | 3 | Retail, F&B, Real Estate industries (cross-industry for demo variety) |
| Vendors | 3 | Office supplies, logistics, import/export |
| Products | 5 | One category, one unit of measure |
| Opening journal entry | 1 entry, 5 lines | Balances to the cent: 55,500.0000 debit = 55,500.0000 credit |
| Sample sales invoice | 1 | Posts AR + Revenue + VAT output via `InvoiceService` |
| Sample purchase bill | 1 | Posts AP + Inventory/Expense + VAT input via `BillService` |

# Development Seeds

## Purpose

Development seeds exist so an engineer can clone the repository, run one command, and immediately
click through every screen with data at realistic volume — hundreds of invoices, enough rows that
a paginated table, a slow N+1 query, or a chart with too few points to look meaningful are all
caught locally, before code review. Development seeds are the only layer allowed to use
Faker-driven `Model::factory()->count(n)` at scale, and the only layer allowed to spend real
wall-clock time: a full `DevelopmentSeeder` run is budgeted at under 90 seconds on a modern
laptop — slower than that is treated as a performance bug in the seeder, not accepted as "seeding
is just slow."

## Environment Guard and Orchestration

```php
<?php
// database/seeders/DevelopmentSeeder.php

namespace Database\Seeders;

use App\Models\{Company, Customer, Vendor, Product, Invoice, InvoiceItem, JournalEntry};
use Illuminate\Database\Seeder;

class DevelopmentSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production')) {
            throw new \RuntimeException('DevelopmentSeeder must never run in production. See # Production Safety.');
        }

        $this->call(DemoCompanySeeder::class); // reuse the same realistic base tenant

        $company = Company::where('code', 'DEMO-KW-001')->firstOrFail();

        $this->seedVolumeCustomers($company, 150);
        $this->seedVolumeVendors($company, 60);
        $this->seedVolumeProducts($company, 300);
        $this->seedVolumeInvoices($company, 500);
        $this->seedVolumeJournalEntries($company, 400);

        $this->command?->info('Development volume data seeded: 150 customers, 60 vendors, '
            .'300 products, 500 invoices, 400 standalone journal entries.');
    }

    private function seedVolumeCustomers(Company $company, int $count): void
    {
        Customer::factory()->count($count)->create(['company_id' => $company->id]);
    }

    private function seedVolumeVendors(Company $company, int $count): void
    {
        Vendor::factory()->count($count)->create(['company_id' => $company->id]);
    }

    private function seedVolumeProducts(Company $company, int $count): void
    {
        Product::factory()->count($count)->create(['company_id' => $company->id]);
    }

    private function seedVolumeInvoices(Company $company, int $count): void
    {
        $customerIds = Customer::where('company_id', $company->id)->pluck('id');
        Invoice::factory()
            ->count($count)
            ->has(InvoiceItem::factory()->count(3), 'items')
            ->create(['company_id' => $company->id, 'customer_id' => fn () => $customerIds->random()]);
    }

    /** Randomized but always balanced: the factory forces sum(debit) = sum(credit) per entry. */
    private function seedVolumeJournalEntries(Company $company, int $count): void
    {
        JournalEntry::factory()->count($count)->balanced()->create(['company_id' => $company->id]);
    }
}
```

## Factories That Encode the Double-Entry Invariant

Financial-row factories cannot randomize every column independently — a randomized `JournalLine`
factory would violate the platform's core invariant (debits = credits) on almost every row. QAYD's
factories encode the invariant into a named factory *state*, not into after-the-fact validation:

```php
<?php
// database/factories/JournalEntryFactory.php

namespace Database\Factories;

use App\Models\{Account, JournalEntry, JournalLine};
use Illuminate\Database\Eloquent\Factories\Factory;

class JournalEntryFactory extends Factory
{
    protected $model = JournalEntry::class;

    public function definition(): array
    {
        return [
            'entry_date' => $this->faker->dateTimeBetween('-1 year', 'now'),
            'reference'  => 'JE-DEV-'.$this->faker->unique()->numerify('######'),
            'memo_en'    => $this->faker->sentence(6),
            'memo_ar'    => 'قيد تجريبي للتطوير',
            'status'     => 'posted',
        ];
    }

    /** Guarantees the minimal balanced case: one random debit account, one random credit account, equal amounts. */
    public function balanced(): static
    {
        return $this->afterCreating(function (JournalEntry $entry) {
            $amount = $this->faker->randomFloat(4, 50, 5000);
            $debitAccount  = Account::where('company_id', $entry->company_id)->where('normal_balance', 'debit')->inRandomOrder()->firstOrFail();
            $creditAccount = Account::where('company_id', $entry->company_id)->where('normal_balance', 'credit')->inRandomOrder()->firstOrFail();

            JournalLine::factory()->create([
                'journal_entry_id' => $entry->id, 'account_id' => $debitAccount->id,
                'debit' => $amount, 'credit' => '0.0000',
            ]);
            JournalLine::factory()->create([
                'journal_entry_id' => $entry->id, 'account_id' => $creditAccount->id,
                'debit' => '0.0000', 'credit' => $amount,
            ]);
        });
    }
}
```

```php
<?php
// database/factories/CustomerFactory.php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class CustomerFactory extends Factory
{
    public function definition(): array
    {
        return [
            'code'               => 'CUST-'.$this->faker->unique()->numerify('#####'),
            'name_en'            => $this->faker->company(),
            'name_ar'            => $this->faker->company(),
            'currency_code'      => $this->faker->randomElement(['KWD', 'SAR', 'USD']),
            'payment_terms_days' => $this->faker->randomElement([0, 15, 30, 45, 60]),
            'industry_code'      => $this->faker->randomElement([
                'general_trading', 'retail', 'construction_contracting', 'food_beverage', 'real_estate',
            ]),
            'is_active' => true,
        ];
    }
}
```

## Performance Practices for Volume Seeding

- **Disable model events during bulk writes.** `Model::withoutEvents(fn () => Invoice::factory()->count(500)->create())`
  avoids firing 500 individual `invoice.created` domain events (and therefore 500 queued webhook
  dispatch jobs) during a local seed. Development seeders wrap high-volume factory calls in
  `withoutEvents()`; only the small "sample" records in `DemoCompanySeeder` go through the real
  event pipeline, intentionally, so a developer can also verify that pipeline works end to end.
- **Chunk inserts, don't accumulate collections.** For very large counts (1,000+ rows) prefer
  `Model::insert($rows)` in chunks of 500 with hand-built arrays over `factory()->count()->create()`,
  accepting that factory-level casts/mutators are bypassed on that bulk path only.
- **Fake the queue.** Local seeding sets `QUEUE_CONNECTION=sync` off — sync would run every queued
  job, including notification jobs, inline and serially, which is both slow and semantically wrong
  for a dev seed. `Queue::fake()` is used instead so any queued side effect a factory's model
  events trigger is captured, not executed.
- **One transaction per seeder method, not per row, and not one giant transaction.** Wrapping an
  entire 1,000+ row `DevelopmentSeeder::run()` in one outer transaction holds locks for tens of
  seconds and increases the chance of a local deadlock against a concurrently running dev-server
  request. Each `seedVolumeX()` private method owns its own (small, fast) transaction boundary.

# Testing Seeds

## Purpose

Testing seeds are the strictest layer: no Faker, no random amounts, no random dates for any value
an assertion touches. A Pest test that fails once in two hundred runs because a random invoice
total happened to round differently is worse than no test at all — it teaches engineers to ignore
red CI. Every testing fixture in QAYD is a fixed, literal, hand-specified row set, defined once as
a reusable trait, and torn down automatically by Laravel's `RefreshDatabase` per test.

## Reference Data in Tests

Reference tables (`countries`, `currencies`, `languages`, `industries`, `permissions`, `roles`,
`coa_templates`) are real product data, not test fixtures — tests seed them once, the same way
production does, rather than hand-rolling a parallel "test currencies" list that can drift from
the real `CurrencySeeder`. QAYD's base test case runs `ReferenceDataSeeder` exactly once per test
process using a static guard, then relies on `RefreshDatabase`'s per-test transaction for
everything else:

```php
<?php
// tests/TestCase.php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Artisan;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    private static bool $referenceDataSeeded = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (! self::$referenceDataSeeded) {
            Artisan::call('db:seed', ['--class' => 'Database\\Seeders\\ReferenceDataSeeder']);
            self::$referenceDataSeeded = true;
        }
    }
}
```

`RefreshDatabase` migrates the schema fresh at the start of the suite and wraps each test in a
transaction that rolls back at teardown; because the guard above seeds reference data only the
*first* time `setUp()` runs per process, reference data is seeded once per worker (Pest/PHPUnit
parallel workers each get their own process and their own guard) and then persists for every
test's transaction in that worker — exactly the semantics a test needs.

## Deterministic Fixture Trait

```php
<?php
// tests/Fixtures/SeedsTestCompany.php

namespace Tests\Fixtures;

use App\Models\{Account, Company, Customer, JournalEntry, User};
use App\Services\Accounting\JournalPostingService;
use App\Services\Company\CompanyProvisioningService;

trait SeedsTestCompany
{
    protected function seedTestCompany(): Company
    {
        $company = Company::create([
            'code' => 'TEST-CO-0001',
            'name_en' => 'Test Co', 'name_ar' => 'شركة اختبار',
            'country_iso2' => 'KW', 'base_currency_code' => 'KWD',
            'default_language_code' => 'en', 'industry_code' => 'general_trading',
            'is_demo' => false,
        ]);

        app(CompanyProvisioningService::class)->applyCoaTemplate($company, 'kw_general_trading');

        $owner = User::factory()->create(['email' => 'owner@test.qayd.local']);
        $owner->companies()->attach($company->id);

        Customer::create([
            'company_id' => $company->id, 'code' => 'CUST-TEST-01',
            'name_en' => 'Test Customer', 'name_ar' => 'عميل اختبار',
            'currency_code' => 'KWD', 'payment_terms_days' => 30, 'is_active' => true,
        ]);

        return $company;
    }

    /** Fixed amounts on purpose: 1000.0000 debit to Cash, 1000.0000 credit to Share Capital. */
    protected function seedTestOpeningBalance(Company $company, User $owner): JournalEntry
    {
        $cash   = Account::where('company_id', $company->id)->where('code', '1110')->firstOrFail();
        $equity = Account::where('company_id', $company->id)->where('code', '3100')->firstOrFail();

        return app(JournalPostingService::class)->post([
            'company_id' => $company->id, 'entry_date' => '2026-01-01',
            'reference' => 'TEST-OPEN-0001', 'memo_en' => 'Test opening balance', 'memo_ar' => 'رصيد افتتاحي اختباري',
            'created_by' => $owner->id,
            'lines' => [
                ['account_id' => $cash->id,   'debit' => '1000.0000', 'credit' => '0.0000'],
                ['account_id' => $equity->id, 'debit' => '0.0000',    'credit' => '1000.0000'],
            ],
            'auto_post' => true,
        ]);
    }
}
```

## Worked Pest Test

```php
<?php
// tests/Feature/Accounting/TrialBalanceTest.php

use App\Services\Accounting\TrialBalanceService;
use Tests\Fixtures\SeedsTestCompany;

uses(SeedsTestCompany::class);

it('produces a trial balance that sums to zero after a balanced opening entry', function () {
    $company = $this->seedTestCompany();
    $owner   = $company->users()->first();

    $this->seedTestOpeningBalance($company, $owner);

    $trialBalance = app(TrialBalanceService::class)->generate($company, asOf: '2026-01-31');

    $totalDebits  = collect($trialBalance)->sum('debit');
    $totalCredits = collect($trialBalance)->sum('credit');

    expect($totalDebits)->toBe('1000.0000');
    expect($totalCredits)->toBe('1000.0000');
    expect(bccomp($totalDebits, $totalCredits, 4))->toBe(0);
});
```

Because every literal in the fixture (`1000.0000`, account code `1110`, date `2026-01-01`) is
fixed, this test is byte-for-byte reproducible on every run, in every environment, forever — the
defining property of a testing seed, as distinct from a development seed that intentionally wants
different random data on every invocation.

## Isolation From Other Layers

Testing seeds never call `DemoCompanySeeder` or `DevelopmentSeeder` directly, even though
`SeedsTestCompany::seedTestCompany()` looks structurally similar to
`DemoCompanySeeder::provisionCompany()` — the duplication is intentional. A test suite that
depended on the demo seeder would break every time product decides to add a sixth demo user or
rename a demo product, for reasons that have nothing to do with the behavior under test. The two
stay decoupled on purpose, at the cost of a small amount of duplicated setup code.

# Production Safety

## Guardrail Matrix

| Seeder | Allowed Environments | Requires `--force` | Requires Human Approval | Mutation Style |
|---|---|---|---|---|
| `ReferenceDataSeeder` (+ children: Country/Currency/Language/Industry/Permission/Role/CoaTemplate) | local, staging, production | No (safe by design) | No | Upsert-only, additive |
| `DemoCompanySeeder` | local, staging, production (ring-fenced pool only) | Yes, in production | Yes, in production | Creates one flagged `is_demo` tenant |
| `DevelopmentSeeder` | local, staging | N/A — hard-blocked in production | No | Bulk factory inserts |
| Testing fixtures (`SeedsTestCompany`, Pest factories) | `testing` only (`APP_ENV=testing`) | N/A — hard-blocked outside testing | No | Transaction-scoped, auto-rolled-back |

## Environment Guard Trait

Every non-reference seeder in QAYD extends this guard rather than re-implementing the same
`app()->environment()` check by hand, so the rule lives in exactly one place:

```php
<?php
// database/seeders/Concerns/GuardsEnvironment.php

namespace Database\Seeders\Concerns;

trait GuardsEnvironment
{
    protected function assertAllowedIn(array $environments, bool $forceOk = false): void
    {
        $current = app()->environment();

        if (in_array($current, $environments, true)) {
            return;
        }

        if ($forceOk && $current === 'production' && ($this->command?->option('force') ?? false)) {
            return;
        }

        throw new \RuntimeException(sprintf(
            '%s is not permitted in the "%s" environment (allowed: %s).',
            static::class, $current, implode(', ', $environments)
        ));
    }
}
```

```php
class DevelopmentSeeder extends Seeder
{
    use \Database\Seeders\Concerns\GuardsEnvironment;

    public function run(): void
    {
        $this->assertAllowedIn(['local', 'development', 'staging']);
        // ...
    }
}
```

## Database-Role Defense in Depth

Application-layer guards are the first line of defense; QAYD does not rely on them as the *only*
line. The Postgres role the Laravel application connects with for routine traffic (`qayd_app`)
has the full DML rights it needs at runtime. A second, narrower role is used specifically for the
reference-data release step of the deploy pipeline and is granted no `DELETE`/`TRUNCATE` at all,
so that even a bug in `ReferenceDataSeeder` (which should never issue either) cannot silently wipe
a table in production:

```sql
CREATE ROLE qayd_seeder LOGIN PASSWORD :'seeder_password' NOSUPERUSER NOCREATEDB NOCREATEROLE;

GRANT SELECT, INSERT, UPDATE ON
    countries, currencies, languages, industries,
    permissions, roles, role_permissions,
    coa_templates, coa_template_lines
TO qayd_seeder;

REVOKE DELETE, TRUNCATE ON
    countries, currencies, languages, industries,
    permissions, roles, role_permissions,
    coa_templates, coa_template_lines
FROM qayd_seeder;

-- qayd_seeder has NO grants at all on business tables (journal_entries, invoices, etc.);
-- DemoCompanySeeder and DevelopmentSeeder always run under qayd_app, never under qayd_seeder.
```

The production deploy pipeline's reference-data seeding step connects as `qayd_seeder`, not
`qayd_app`:

```bash
# .github/workflows/deploy-production.yml (excerpt)
- name: Run reference data seeder
  env:
    DB_USERNAME: qayd_seeder
    DB_PASSWORD: ${{ secrets.SEEDER_DB_PASSWORD }}
  run: php artisan db:seed --class=ReferenceDataSeeder --force
```

## CI Lint for Dangerous SQL in Seeders

A pre-merge CI job statically scans every file under `database/seeders/` for raw
`DELETE FROM`/`TRUNCATE`/`DROP TABLE` outside an explicit, reviewed allow-list — catching an
accidental destructive statement before it reaches `main`, let alone production:

```bash
#!/usr/bin/env bash
# ci/lint-seeders.sh
set -euo pipefail

ALLOWLIST=("database/seeders/RoleSeeder.php") # the one seeder allowed a scoped DELETE (role_permissions pivot rebuild)

violations=0
for file in database/seeders/*.php; do
    if grep -qE '\b(TRUNCATE|DROP TABLE)\b' "$file"; then
        echo "FORBIDDEN: $file contains TRUNCATE/DROP TABLE."
        violations=$((violations + 1))
    fi
    if grep -qE '\bDELETE FROM\b' "$file" && [[ ! " ${ALLOWLIST[*]} " =~ " ${file} " ]]; then
        echo "REVIEW REQUIRED: $file contains DELETE FROM but is not on the seeder allow-list."
        violations=$((violations + 1))
    fi
done

exit $violations
```

## Approval Chain for Production Demo Provisioning

`DemoCompanySeeder --force` in production follows the same "sensitive operation" approval chain
the platform design context mandates for bank transfers and payroll release (never AI-only, never
single-operator): the on-call engineer opens a change ticket, a second engineer reviews and
approves it in the ticketing system, and only then is the `--force` deploy step unblocked in
CI — the pipeline job checks for an `approved` label on the linked ticket via the ticketing
system's API before executing, and refuses to run otherwise. Every such run additionally writes an
`audit_logs` row with `actor_type = 'system'`, `actor_id = null`, `action = 'seeder.run'`,
`reason = <ticket URL>`, so that "why does a new demo company exist in production" is always
answerable from the audit trail alone.

## Backups Before Any Non-Reference Seeder in Production

The deploy pipeline triggers an on-demand database snapshot immediately before any seeding step
other than `ReferenceDataSeeder` runs in production, and the pipeline blocks on that snapshot's
completion:

```bash
SNAPSHOT_ID="pre-seed-$(date +%Y%m%d%H%M%S)"

aws rds create-db-snapshot \
    --db-instance-identifier qayd-production \
    --db-snapshot-identifier "$SNAPSHOT_ID"

aws rds wait db-snapshot-available \
    --db-snapshot-identifier "$SNAPSHOT_ID"
```

## Alerting

A `SeederRan` domain event fires at the end of every seeder's `run()` (emitted by a base
`Seeder`-decorating class all QAYD seeders extend, not left to each seeder to remember to fire),
and a listener posts it to the platform's ops Slack channel whenever
`app()->environment('production')` is true, tagging the seeder class, row counts touched, the
invoking CI run URL, and the linked change-ticket ID when present — so a production seeder run is
never silent.

# Idempotency

## The Contract

Every seeder documented in this file satisfies one formal guarantee: **running it N times
produces the same database state as running it once**, for any N ≥ 1. Concretely: row counts in
the tables it owns are identical after run 1 and run 2; no unique-constraint violation is ever
thrown on a second run; and no seeder ever depends on a table being empty when it starts. This is
what makes `ReferenceDataSeeder` safe to wire into *every production deploy* (see
`# Production Safety`) rather than a one-time, manually-tracked script someone has to remember not
to run twice.

## Mechanics, Table by Table

| Table(s) | Technique | Conflict Target |
|---|---|---|
| `countries`, `currencies`, `languages`, `industries`, `permissions` | `DB::table()->upsert()` | Natural-key column (`iso2`, `code`, `key`) |
| `roles` | Manual `SELECT … WHERE slug AND company_id IS NULL` then insert-or-update | `(slug, company_id)` |
| `role_permissions` (pivot) | Delete-then-reinsert **inside the same request**, scoped to one `role_id` | Scoped replace — safe because it never touches another role's rows and runs inside the seeder's own invocation, never a separate transaction that could interleave with a concurrent grant change |
| `coa_templates`, `coa_template_lines` | `updateOrInsert()` keyed on `code` / `(template_id, code)` | `code` / `(template_id, code)` |
| `companies` (demo) | `updateOrCreate()` keyed on `code` | `code` |
| Everything under `DemoCompanySeeder` | Cascades from the company lookup — every child row is itself looked up by a natural key scoped to that company before insert | `(company_id, code)` throughout |

The one deliberate exception is `role_permissions`: a true upsert doesn't fit a pivot table well
(there is no "value to update" on a pivot row beyond its own existence), so QAYD accepts a
narrower guarantee — *idempotent per role*, achieved by scoping the delete to exactly the
`role_id` being reseeded, inside the same seeder invocation that immediately reinserts its correct
grant set. Two concurrent `RoleSeeder` runs are not safe (this is why the `SeederRan` alerting in
`# Production Safety` exists — so a human notices if that ever happens), but a `RoleSeeder` run
that is never concurrent with itself — the normal case in a serialized deploy pipeline — is fully
idempotent in the sense that matters: identical inputs (the `ROLES` matrix) always produce
identical `role_permissions` rows, regardless of what a previous, buggy version of the matrix left
behind.

## Base Class for Future Seeders

New reference-data seeders extend this base rather than re-deriving the upsert boilerplate, and it
doubles as a performance optimization: if the source fixture's checksum has not changed since the
last run, the seeder skips its DB round-trip entirely instead of upserting rows that would resolve
to no-op updates anyway.

```sql
CREATE TABLE seed_checksums (
    seeder_class    VARCHAR(150)  NOT NULL PRIMARY KEY,
    checksum        CHAR(64)      NOT NULL,          -- sha256 of the seeder's source fixture/data
    row_count       INTEGER       NOT NULL,
    last_run_at     TIMESTAMPTZ   NOT NULL,
    last_run_env    VARCHAR(30)   NOT NULL
);
```

```php
<?php
// database/seeders/Concerns/IdempotentSeeder.php

namespace Database\Seeders\Concerns;

use Illuminate\Support\Facades\DB;

trait IdempotentSeeder
{
    /** Returns true (and records nothing) if this exact payload was already applied. */
    protected function shouldSkip(string $checksum): bool
    {
        $existing = DB::table('seed_checksums')->where('seeder_class', static::class)->first();
        return $existing !== null && $existing->checksum === $checksum;
    }

    protected function recordRun(string $checksum, int $rowCount): void
    {
        DB::table('seed_checksums')->updateOrInsert(
            ['seeder_class' => static::class],
            [
                'checksum'     => $checksum,
                'row_count'    => $rowCount,
                'last_run_at'  => now(),
                'last_run_env' => app()->environment(),
            ]
        );
    }
}
```

```php
class CountrySeeder extends Seeder
{
    use \Database\Seeders\Concerns\IdempotentSeeder;

    public function run(): void
    {
        $path = database_path('seeders/data/countries.json');
        $raw  = File::get($path);
        $checksum = hash('sha256', $raw);

        if ($this->shouldSkip($checksum)) {
            $this->command?->info('CountrySeeder: fixture unchanged since last run — skipping upsert.');
            return;
        }

        $rows = json_decode($raw, true);
        // ... upsert exactly as shown in # Countries ...

        $this->recordRun($checksum, count($rows));
    }
}
```

This checksum optimization is purely a performance short-circuit, never a correctness mechanism —
the underlying `upsert()` call is already safe to run unconditionally. Skipping it on an unchanged
checksum only matters at scale (a 250-row `countries` upsert costs nothing either way; a
hypothetical future reference table with 50,000 rows benefits from not re-writing all 50,000 rows
on every deploy when the fixture hasn't moved).

## Verifying Idempotency in CI

```php
<?php
// tests/Unit/Seeders/IdempotencyTest.php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

it('leaves row counts unchanged when ReferenceDataSeeder runs twice', function () {
    Artisan::call('db:seed', ['--class' => 'Database\\Seeders\\ReferenceDataSeeder']);

    $countsAfterFirstRun = [
        'countries'        => DB::table('countries')->count(),
        'currencies'       => DB::table('currencies')->count(),
        'permissions'      => DB::table('permissions')->count(),
        'roles'            => DB::table('roles')->count(),
        'role_permissions' => DB::table('role_permissions')->count(),
    ];

    Artisan::call('db:seed', ['--class' => 'Database\\Seeders\\ReferenceDataSeeder']);

    foreach ($countsAfterFirstRun as $table => $expectedCount) {
        expect(DB::table($table)->count())->toBe($expectedCount, "Row count for {$table} changed on second run.");
    }
});
```

This test runs in the platform's CI pipeline on every pull request that touches
`database/seeders/**`, and a failing idempotency test blocks merge — the same gate that protects
`# Production Safety`'s claim that `ReferenceDataSeeder` is safe on every deploy.

# Examples

## Full Orchestration Class

```php
<?php
// database/seeders/DatabaseSeeder.php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Layer 1 — safe everywhere, runs on every deploy.
        $this->call(ReferenceDataSeeder::class);

        // Layer 2 — demo companies: explicit opt-in only, never chained automatically in production.
        if (app()->environment('local', 'staging')) {
            $this->call(DemoCompanySeeder::class);
        }

        // Layer 3 — volume data for local development/staging exploration only.
        if (app()->environment('local', 'development', 'staging')) {
            $this->call(DevelopmentSeeder::class);
        }

        // Layer 4 — testing seeds are never called from DatabaseSeeder; Pest/PHPUnit
        // invoke SeedsTestCompany and factories directly, per-test, via RefreshDatabase.

        $this->command?->info('DatabaseSeeder complete for environment: '.app()->environment());
    }
}
```

## Per-Environment Invocation

| Environment | Command | What Runs |
|---|---|---|
| Local dev (first clone) | `php artisan migrate:fresh --seed` | All four layers via `DatabaseSeeder` |
| Local dev (routine) | `php artisan db:seed` | Same as above, idempotent re-run |
| CI (unit/feature tests) | Handled per-test by `TestCase::setUp()` + `RefreshDatabase` | `ReferenceDataSeeder` once per worker; fixtures per test |
| Staging (CD pipeline, post-migrate) | `php artisan db:seed --force` | All four non-testing layers |
| Production (CD pipeline, post-migrate) | `php artisan db:seed --class=ReferenceDataSeeder --force` (as `qayd_seeder` role) | Reference data only |
| Production (demo refresh, approved ticket only) | `php artisan db:seed --class=DemoCompanySeeder --force` (as `qayd_app` role) | One ring-fenced demo tenant |

## Worked Example 1 — First-Time Local Bootstrap

```bash
$ git clone git@github.com:qayd/backend.git && cd backend
$ composer install
$ cp .env.example .env && php artisan key:generate
$ php artisan migrate:fresh --seed

   INFO  Preparing database.

  Dropping all tables ................................................ 412ms DONE
  Running migrations ................................................ 8,214ms DONE

Reference data seeded/updated: countries, currencies, languages, industries, account types, permissions, roles, COA templates.
Seeded 250 countries.
Seeded 14 currencies.
Seeded 3 languages.
Seeded 20 industries.
Seeded 174 permissions across 12 areas.
Seeded 17 system roles.
Seeded Kuwait COA templates: kw_general_trading, kw_retail.
Demo company "QAYD Demo Trading Co. W.L.L." (DEMO-KW-001) seeded end-to-end.
Development volume data seeded: 150 customers, 60 vendors, 300 products, 500 invoices, 400 standalone journal entries.
DatabaseSeeder complete for environment: local

$ php artisan serve
```

## Worked Example 2 — CI Pipeline (GitHub Actions)

```yaml
# .github/workflows/test.yml (excerpt)
jobs:
  backend-tests:
    runs-on: ubuntu-latest
    services:
      postgres:
        image: postgres:15
        env:
          POSTGRES_DB: qayd_testing
          POSTGRES_PASSWORD: secret
        ports: ['5432:5432']
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with: { php-version: '8.4' }
      - run: composer install --prefer-dist --no-progress
      - run: cp .env.testing.example .env.testing
      - run: php artisan migrate --env=testing --force
      - run: bash ci/lint-seeders.sh
      - run: ./vendor/bin/pest --parallel
```

No explicit `db:seed` step exists in this pipeline — reference data is seeded lazily, once per
parallel worker, by `TestCase::setUp()` (see `# Testing Seeds`), and `ci/lint-seeders.sh` (see
`# Production Safety`) gates the job before a single test runs.

## Worked Example 3 — Production Release

```bash
$ php artisan migrate --force
   INFO  Running migrations.
  2026_07_16_000004_add_demo_columns_to_companies_table ............. 44ms DONE

$ DB_USERNAME=qayd_seeder DB_PASSWORD=*** php artisan db:seed --class=ReferenceDataSeeder --force
Reference data seeded/updated: countries, currencies, languages, industries, account types, permissions, roles, COA templates.
Seeded 250 countries.
Seeded 14 currencies.
Seeded 3 languages.
Seeded 20 industries.
Seeded 174 permissions across 12 areas.
Seeded 17 system roles.
Seeded Kuwait COA templates: kw_general_trading, kw_retail.

[Slack #ops-alerts] SeederRan: ReferenceDataSeeder completed in production.
  environment=production  duration=1.8s  rows_touched=478  ci_run=https://github.com/qayd/backend/actions/runs/48213099
```

## Worked Example 4 — Nightly Demo Reset

```bash
$ php artisan demo:reset
Soft-deleted stale demo company #40021 (DEMO-KW-001).
Fresh demo company provisioned.

[Slack #ops-alerts] SeederRan: DemoCompanySeeder completed in production.
  environment=production  duration=3.1s  rows_touched=612  ci_run=scheduled:demo-reset  ticket=N/A (recurring, pre-approved runbook QAYD-OPS-014)
```

## Worked Example 5 — Idempotency Verification Before Merge

```bash
$ ./vendor/bin/pest tests/Unit/Seeders/IdempotencyTest.php

   PASS  Tests\Unit\Seeders\IdempotencyTest
  ✓ leaves row counts unchanged when ReferenceDataSeeder runs twice   0.42s

  Tests:    1 passed (5 assertions)
  Duration: 0.61s
```

# End of Document

This document is the authoritative specification for QAYD's database seeding subsystem: the
four-layer model (Reference Data, Demo Companies, Development Seeds, Testing Seeds), the complete
DDL for every reference table it owns (`countries`, `currencies`, `languages`, `industries`,
`permissions`, `roles`, `role_permissions`, `coa_templates`, `coa_template_lines`), fully worked
Laravel 12 seeder classes for each, the Kuwait-specific chart-of-accounts templates
(`kw_general_trading`, `kw_retail`), the end-to-end demo-company provisioning pipeline and its
nightly reset lifecycle, the environment guardrails and database-role-level defense in depth that
keep destructive or non-idempotent seeding out of production, and the idempotency contract every
seeder in the codebase must satisfy. Any new module doc that needs a reference table not listed
here (a new lookup with a natural key, an industry-specific COA template, a new demo transaction
type) extends this document rather than duplicating a parallel seeding mechanism — see
`# Seed Philosophy` for the four-layer rules any such addition must respect.
