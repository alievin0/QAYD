# Database Seeding & Reference Data — QAYD Database Layer
Version: 1.0
Status: Design Specification
Module: Database
Submodule: Database Seeding & Reference Data
---

# Purpose

QAYD ships as a multi-tenant PostgreSQL 15+ database driven by a Laravel 12 backend. Two distinct
classes of data must exist before any company can transact in the system: **system reference data**
(country lists, currency lists, tax regimes, permission/role catalogs — data that is identical across
every tenant and owned by QAYD itself) and **per-company defaults** (a starter Chart of Accounts, tax
codes, units of measure, numbering sequences — data that is copied or generated into a *specific*
`company_id` the moment a company signs up). This document defines how both classes are seeded,
re-seeded, and provisioned:

- The taxonomy of seed data QAYD ships (system reference vs. per-company defaults vs. demo/sandbox data).
- The Laravel seeder class structure, naming, and registration used across the codebase.
- The idempotency contract every seeder MUST satisfy — `php artisan db:seed` is safe to run against a
  database that already has data, in any environment, any number of times.
- Environment-specific seed behavior: production only ever receives reference data (never demo data);
  local/staging/CI receive reference data plus synthetic demo companies for engineering and QA.
- The canonical Gulf/Kuwait default Chart of Accounts template, its seed SQL/JSON, and how it is cloned
  into a new company's `accounts` table.
- Roles & permissions seeding — the default RBAC catalog defined in the platform design context,
  loaded once as system data and attached to companies at provisioning time.
- The full "New-Company Provisioning" seed pipeline: the ordered set of inserts that happen inside the
  transaction that creates a `companies` row, so a brand-new tenant is immediately usable.
- Dependency ordering across the ~40 canonical tables, so seeders never violate a foreign key.
- Seed data versioning — how QAYD evolves its default CoA/tax/role catalog over time without breaking
  companies that already cloned an older version.
- Testing with Laravel model factories, independent of and complementary to seeders.
- Edge cases: partial provisioning failures, re-running seeders against a live production reference
  table, locale-specific reference data (name_en/name_ar), and multi-currency defaults.

This document does not define the Chart of Accounts *module* itself (see the Accounting Engine
module doc) — it defines how that module's starting data is *populated*, both once (system reference)
and repeatedly (once per new company).

# Categories Of Seed Data

QAYD seed data falls into three non-overlapping categories. Every seeder class MUST declare which
category it belongs to via its namespace (`Database\Seeders\Reference\*`, `Database\Seeders\Company\*`,
`Database\Seeders\Demo\*`) because each category has a different environment policy (see
`# Environment-Specific Seeds`).

## 1. System Reference Data (global, `company_id` is NULL or the table has no `company_id` column)

Data that is true for the whole platform, identical for every tenant, and never edited by a company
user. These tables have NO `company_id` column — they are platform-owned lookup tables.

| Table | Contents | Approx. row count |
|---|---|---|
| `countries` | ISO 3166-1 alpha-2/alpha-3 codes, name_en, name_ar, phone_code, region | 195 |
| `currencies` | ISO 4217 codes, name_en, name_ar, symbol, decimal_places | 180 |
| `tax_systems` | Named tax regimes (`kw_zero_rated`, `sa_vat_15`, `ae_vat_5`, `oecd_generic`) | 12 |
| `permissions` | The full `<area>.<action>` permission catalog (see platform RBAC design) | ~140 |
| `system_roles` | The default role templates (Owner, CFO, Accountant, Auditor, …) | 17 |
| `account_types` | Asset/Liability/Equity/Revenue/Expense classification + normal balance | 5 |
| `units_of_measure_catalog` | Global UoM library (kg, piece, box, hour, liter, m², …) with conversion base | ~60 |
| `industries` | NAICS-derived industry classification used for onboarding & benchmarking | ~120 |

`account_types` is a genuinely fixed, closed enum (5 rows, never grows) and is additionally guarded by
a DB `CHECK` constraint on `accounts.account_type` — the seeder exists mainly so foreign keys and
human-readable joins work, not because the set is expected to change.

## 2. Per-Company Defaults (cloned or generated at provisioning time, `company_id` populated)

Data that starts as a copy of a system template but from the moment of creation belongs to, and is
editable by, one company. This is the data produced by `# New-Company Provisioning Seed`.

| Source template | Cloned into (per-company table) | Editable after clone? |
|---|---|---|
| `coa_templates` / `coa_template_lines` | `accounts` | Yes — company can add/rename/deactivate accounts |
| `tax_systems` (selected one) | `tax_codes`, `tax_rates` | Yes — rates can change on new tax_rate version |
| `units_of_measure_catalog` (subset) | `units_of_measure` | Yes — company can add custom UoM |
| `role_templates` / `permission_templates` | `roles`, `role_permissions` | Yes — company can create custom roles |
| `number_sequence_templates` | `number_sequences` | Yes — company can change prefix/padding/reset policy |
| `fiscal_year_templates` | `fiscal_years`, `fiscal_periods` | Yes — company can pick a non-calendar fiscal year |

## 3. Demo / Sandbox Data (non-production only)

Fully synthetic companies, transactions, and users used for local development, staging demos, and
automated QA. Demo data is generated by seeders in `Database\Seeders\Demo\*` and is **never** run
against production (enforced in code — see `# Environment-Specific Seeds`). A demo run produces one
or more complete tenants (e.g. "Al-Waha Trading Co.") with realistic customers, vendors, products,
90 days of posted journal entries, invoices in every status, and at least one bank reconciliation, so
that every screen in the frontend has non-empty data to render against.

# Laravel Seeders Structure

All seeders live under `database/seeders/` and follow a fixed namespace-per-category layout:

```
database/seeders/
├── DatabaseSeeder.php                 # entrypoint, dispatches by environment
├── Reference/
│   ├── CountrySeeder.php
│   ├── CurrencySeeder.php
│   ├── TaxSystemSeeder.php
│   ├── AccountTypeSeeder.php
│   ├── PermissionSeeder.php
│   ├── SystemRoleSeeder.php
│   ├── UnitOfMeasureCatalogSeeder.php
│   ├── IndustrySeeder.php
│   └── CoaTemplateSeeder.php          # coa_templates + coa_template_lines (Kuwait, Saudi, generic)
├── Company/
│   ├── CompanyProvisioningSeeder.php  # orchestrator, called from CompanyService::provision()
│   ├── DefaultChartOfAccountsSeeder.php
│   ├── DefaultTaxCodesSeeder.php
│   ├── DefaultUnitsOfMeasureSeeder.php
│   ├── DefaultRolesSeeder.php
│   ├── DefaultNumberSequencesSeeder.php
│   └── DefaultFiscalYearSeeder.php
└── Demo/
    ├── DemoCompanySeeder.php
    ├── DemoCustomerVendorSeeder.php
    ├── DemoProductCatalogSeeder.php
    ├── DemoTransactionSeeder.php
    └── DemoUserSeeder.php
```

`DatabaseSeeder.php` is intentionally thin. It never seeds demo data by default — that requires an
explicit flag — and it always seeds reference data:

```php
<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Database\Seeders\Reference\CountrySeeder;
use Database\Seeders\Reference\CurrencySeeder;
use Database\Seeders\Reference\TaxSystemSeeder;
use Database\Seeders\Reference\AccountTypeSeeder;
use Database\Seeders\Reference\PermissionSeeder;
use Database\Seeders\Reference\SystemRoleSeeder;
use Database\Seeders\Reference\UnitOfMeasureCatalogSeeder;
use Database\Seeders\Reference\IndustrySeeder;
use Database\Seeders\Reference\CoaTemplateSeeder;
use Database\Seeders\Demo\DemoCompanySeeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Always safe, always idempotent, runs in every environment including production.
        $this->call([
            CountrySeeder::class,
            CurrencySeeder::class,
            AccountTypeSeeder::class,
            TaxSystemSeeder::class,
            PermissionSeeder::class,
            SystemRoleSeeder::class,
            UnitOfMeasureCatalogSeeder::class,
            IndustrySeeder::class,
            CoaTemplateSeeder::class,
        ]);

        // Demo data is opt-in and blocked outside local/staging/testing (see guard inside the class).
        if ($this->command?->option('demo') || app()->environment(['local', 'staging', 'testing'])) {
            $this->call(DemoCompanySeeder::class);
        }
    }
}
```

Each individual seeder is small, single-purpose, and named after the table (or template group) it
owns. A representative reference seeder:

```php
<?php

namespace Database\Seeders\Reference;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CurrencySeeder extends Seeder
{
    public function run(): void
    {
        $rows = collect(json_decode(file_get_contents(
            database_path('seeders/data/currencies.json')
        ), true));

        $now = now();

        $rows->chunk(200)->each(function ($chunk) use ($now) {
            $payload = $chunk->map(fn ($c) => [
                'code'           => $c['code'],           // ISO 4217, natural key
                'name_en'        => $c['name_en'],
                'name_ar'        => $c['name_ar'],
                'symbol'         => $c['symbol'],
                'decimal_places' => $c['decimal_places'],
                'is_active'      => true,
                'created_at'     => $now,
                'updated_at'     => $now,
            ])->all();

            DB::table('currencies')->upsert(
                $payload,
                uniqueBy: ['code'],
                update: ['name_en', 'name_ar', 'symbol', 'decimal_places', 'updated_at']
            );
        });
    }
}
```

Bulk reference tables (countries, currencies, UoM catalog, industries) load from versioned JSON fixture
files under `database/seeders/data/*.json` rather than inline PHP arrays — this keeps the seeder classes
short, lets non-engineers (localization team) edit `name_ar` translations via PR without touching PHP,
and lets `# Versioning Seed Data` diff exact fixture changes between releases.

# Idempotent Seeding

Every seeder in `Reference/` and `Company/` MUST be safe to execute an unbounded number of times against
a database that already contains the rows it produces, with no duplicate rows, no unique-constraint
violations, and no destructive overwrite of a value a user has since edited. This is a hard platform
rule because seeders run on every deploy (via `php artisan migrate --force && php artisan db:seed
--force` in the release pipeline), not only once at initial setup.

**The mechanism is `INSERT ... ON CONFLICT (natural_key) DO UPDATE`, exposed in Laravel as
`DB::table(...)->upsert($rows, $uniqueBy, $update)`.** Every seedable table therefore has an explicit
natural key with a `UNIQUE` constraint, distinct from its surrogate `id`:

```sql
-- Reference tables: natural key is a stable business code, never the surrogate id.
ALTER TABLE countries            ADD CONSTRAINT countries_iso2_uk UNIQUE (iso2);
ALTER TABLE currencies           ADD CONSTRAINT currencies_code_uk UNIQUE (code);
ALTER TABLE tax_systems          ADD CONSTRAINT tax_systems_code_uk UNIQUE (code);
ALTER TABLE permissions          ADD CONSTRAINT permissions_key_uk UNIQUE (key);
ALTER TABLE account_types        ADD CONSTRAINT account_types_code_uk UNIQUE (code);
ALTER TABLE units_of_measure_catalog ADD CONSTRAINT uom_catalog_code_uk UNIQUE (code);
ALTER TABLE coa_templates        ADD CONSTRAINT coa_templates_code_uk UNIQUE (code);
ALTER TABLE coa_template_lines   ADD CONSTRAINT coa_template_lines_uk UNIQUE (coa_template_id, account_code);
```

Rules that keep upserts safe and non-destructive:

1. **Never include `created_at` in the `update` list.** Only `updated_at` and the mutable business
   columns are refreshed on conflict; the original creation timestamp is preserved.
2. **Never upsert a column a company user can edit.** Per-company clones (`accounts`, `tax_codes`,
   `roles`, …) are seeded once at provisioning and are NEVER touched again by a reference-data
   re-run — the seeder that clones a template into a company only runs inside
   `CompanyProvisioningSeeder`, which is invoked exactly once per company (see
   `# New-Company Provisioning Seed`). Re-running `CoaTemplateSeeder` updates the *template*
   (`coa_templates`/`coa_template_lines`), never a company's already-cloned `accounts` rows.
3. **Soft-deleted or deactivated reference rows are restored, not recreated.** If `is_active=false`
   was set manually on a reference row (e.g. a currency de-listed by finance ops) and the fixture file
   still contains it, the upsert must not silently flip `is_active` back to `true`. Seeders therefore
   exclude `is_active`/`status` from the `update` column list for any table where a human can toggle
   that flag post-seed; only an explicit `--force-status` flag on the Artisan command does that.
4. **Wrap multi-statement seeders in a transaction** so a partial failure (e.g. one bad row in a 200-row
   chunk) cannot leave the reference table half-updated:

```php
DB::transaction(function () use ($rows) {
    foreach ($rows->chunk(200) as $chunk) {
        DB::table('countries')->upsert($chunk->all(), ['iso2'], [
            'name_en', 'name_ar', 'phone_code', 'region', 'updated_at',
        ]);
    }
});
```

5. **Idempotency is unit-tested.** Every reference seeder has a Pest test that runs `->run()` twice and
   asserts the row count is unchanged and no exception is thrown on the second call:

```php
it('is idempotent', function () {
    (new CurrencySeeder())->run();
    $countAfterFirst = DB::table('currencies')->count();

    (new CurrencySeeder())->run();
    $countAfterSecond = DB::table('currencies')->count();

    expect($countAfterSecond)->toBe($countAfterFirst);
});
```

# Environment-Specific Seeds

QAYD enforces a strict boundary between reference data (safe everywhere, including production) and
demo/sandbox data (forbidden in production). This is enforced in three layers, not just convention:

**Layer 1 — Artisan command guard.** `Database\Seeders\Demo\DemoCompanySeeder` refuses to run outside
allowed environments, independent of how it was invoked:

```php
<?php

namespace Database\Seeders\Demo;

use Illuminate\Database\Seeder;
use RuntimeException;

class DemoCompanySeeder extends Seeder
{
    private const ALLOWED_ENVIRONMENTS = ['local', 'staging', 'testing'];

    public function run(): void
    {
        if (! in_array(app()->environment(), self::ALLOWED_ENVIRONMENTS, true)) {
            throw new RuntimeException(
                'DemoCompanySeeder cannot run in environment ['.app()->environment().']. '
                .'Demo/sandbox data is prohibited in production and any environment reachable by real customers.'
            );
        }

        $this->call([
            DemoUserSeeder::class,
            DemoProductCatalogSeeder::class,
            DemoCustomerVendorSeeder::class,
            DemoTransactionSeeder::class,
        ]);
    }
}
```

**Layer 2 — CI/CD pipeline gate.** The deploy pipeline's production stage runs:

```
php artisan migrate --force
php artisan db:seed --class=Database\\Seeders\\ReferenceOnlySeeder --force
```

`ReferenceOnlySeeder` exists specifically so production deploys can never accidentally invoke
`DatabaseSeeder` (which conditionally calls demo seeders) — the production job hard-codes the reference
entrypoint by class name, sidestepping the `--demo` flag entirely.

**Layer 3 — config flag.** `config/qayd.php` exposes `seeding.allow_demo_data`, computed from
`APP_ENV` and a second explicit `ALLOW_DEMO_SEED` env var that must be `true` in *both* to activate,
so a misconfigured `APP_ENV=staging` on an environment that is actually customer-facing cannot alone
turn on demo seeding:

```php
// config/qayd.php
'seeding' => [
    'allow_demo_data' => in_array(env('APP_ENV'), ['local', 'staging', 'testing'], true)
        && env('ALLOW_DEMO_SEED', false) === true,
],
```

| Environment | Reference seeds | Company provisioning seeds | Demo seeds |
|---|---|---|---|
| `production` | Yes, every deploy | Yes, on every real signup | Never |
| `staging` | Yes, every deploy | Yes, on every test signup | Yes, opt-in flag |
| `local` | Yes, every `db:seed` | Yes, on every local signup | Yes, default on |
| `testing` (CI/Pest) | Yes, via `RefreshDatabase` + seeders | Yes, per-test factory | Yes, minimal fixture only |

# Default Chart Of Accounts Seeding (Gulf/Kuwait Template)

A default Chart of Accounts is never hand-typed per company. It is defined ONCE as a system-owned
**template** (`coa_templates` + `coa_template_lines`), then cloned row-for-row into a new company's
`accounts` table at provisioning time. This lets QAYD ship multiple templates (Kuwait retail, Kuwait
services, Saudi VAT-registered, generic IFRS-SME) and lets a company pick one during onboarding.

```sql
CREATE TABLE coa_templates (
    id            BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    code          VARCHAR(40)  NOT NULL,                 -- natural key, e.g. 'kw_general_trading'
    name_en       VARCHAR(160) NOT NULL,
    name_ar       VARCHAR(160) NOT NULL,
    country_code  VARCHAR(2)   NOT NULL REFERENCES countries(iso2),
    description   TEXT,
    is_default    BOOLEAN      NOT NULL DEFAULT false,   -- offered pre-selected in onboarding for the country
    is_active     BOOLEAN      NOT NULL DEFAULT true,
    created_at    TIMESTAMPTZ  NOT NULL DEFAULT now(),
    updated_at    TIMESTAMPTZ  NOT NULL DEFAULT now(),
    CONSTRAINT coa_templates_code_uk UNIQUE (code)
);

CREATE TABLE coa_template_lines (
    id               BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    coa_template_id  BIGINT       NOT NULL REFERENCES coa_templates(id) ON DELETE CASCADE,
    account_code     VARCHAR(20)  NOT NULL,               -- e.g. '1000', '1010', '4000'
    parent_code      VARCHAR(20)  NULL,                   -- references account_code within same template
    name_en          VARCHAR(160) NOT NULL,
    name_ar          VARCHAR(160) NOT NULL,
    account_type     VARCHAR(20)  NOT NULL
                      CHECK (account_type IN ('asset','liability','equity','revenue','expense')),
    account_subtype  VARCHAR(40)  NULL,                   -- e.g. 'current_asset','cogs','operating_expense'
    normal_balance   VARCHAR(6)   NOT NULL CHECK (normal_balance IN ('debit','credit')),
    is_control       BOOLEAN      NOT NULL DEFAULT false, -- true for AR/AP/Inventory/Bank control accounts
    is_postable      BOOLEAN      NOT NULL DEFAULT true,  -- false for header/group nodes (not directly posted to)
    sort_order       INT          NOT NULL DEFAULT 0,
    created_at       TIMESTAMPTZ  NOT NULL DEFAULT now(),
    updated_at       TIMESTAMPTZ  NOT NULL DEFAULT now(),
    CONSTRAINT coa_template_lines_uk UNIQUE (coa_template_id, account_code)
);

CREATE INDEX idx_coa_template_lines_template ON coa_template_lines (coa_template_id);
```

Excerpt of the Kuwait general-trading template (`kw_general_trading`), stored as the fixture that
seeds `coa_template_lines`:

| account_code | parent_code | name_en | name_ar | account_type | normal_balance | is_control | is_postable |
|---|---|---|---|---|---|---|---|
| 1000 | — | Assets | الأصول | asset | debit | false | false |
| 1100 | 1000 | Current Assets | الأصول المتداولة | asset | debit | false | false |
| 1110 | 1100 | Cash on Hand | النقد في الصندوق | asset | debit | false | true |
| 1120 | 1100 | Bank Accounts | الحسابات البنكية | asset | debit | true | true |
| 1130 | 1100 | Accounts Receivable | حسابات القبض | asset | debit | true | true |
| 1140 | 1100 | Inventory | المخزون | asset | debit | true | true |
| 1150 | 1100 | Prepaid Expenses | مصروفات مدفوعة مقدماً | asset | debit | false | true |
| 1200 | 1000 | Non-Current Assets | الأصول غير المتداولة | asset | debit | false | false |
| 1210 | 1200 | Property & Equipment | الممتلكات والمعدات | asset | debit | false | true |
| 2000 | — | Liabilities | الخصوم | liability | credit | false | false |
| 2100 | 2000 | Current Liabilities | الخصوم المتداولة | liability | credit | false | false |
| 2110 | 2100 | Accounts Payable | حسابات الدفع | liability | credit | true | true |
| 2120 | 2100 | Tax Payable | ضريبة مستحقة الدفع | liability | credit | false | true |
| 2130 | 2100 | Accrued Expenses | مصروفات مستحقة | liability | credit | false | true |
| 3000 | — | Equity | حقوق الملكية | equity | credit | false | false |
| 3100 | 3000 | Owner's Capital | رأس مال الملكية | equity | credit | false | true |
| 3200 | 3000 | Retained Earnings | الأرباح المحتجزة | equity | credit | false | true |
| 4000 | — | Revenue | الإيرادات | revenue | credit | false | false |
| 4100 | 4000 | Sales Revenue | إيرادات المبيعات | revenue | credit | false | true |
| 4200 | 4000 | Service Revenue | إيرادات الخدمات | revenue | credit | false | true |
| 5000 | — | Cost of Goods Sold | تكلفة البضاعة المباعة | expense | debit | false | false |
| 5100 | 5000 | Purchases | المشتريات | expense | debit | false | true |
| 6000 | — | Operating Expenses | المصروفات التشغيلية | expense | debit | false | false |
| 6100 | 6000 | Salaries & Wages | الرواتب والأجور | expense | debit | false | true |
| 6200 | 6000 | Rent Expense | مصروف الإيجار | expense | debit | false | true |
| 6300 | 6000 | Utilities | المرافق | expense | debit | false | true |

`CoaTemplateSeeder` loads this from `database/seeders/data/coa_templates/kw_general_trading.json` and
upserts both the template header and its lines:

```php
<?php

namespace Database\Seeders\Reference;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CoaTemplateSeeder extends Seeder
{
    private const TEMPLATE_FILES = [
        'kw_general_trading' => 'kw_general_trading.json',
        'kw_services'        => 'kw_services.json',
        'sa_vat_general'     => 'sa_vat_general.json',
        'generic_ifrs_sme'   => 'generic_ifrs_sme.json',
    ];

    public function run(): void
    {
        foreach (self::TEMPLATE_FILES as $code => $file) {
            $data = json_decode(file_get_contents(
                database_path("seeders/data/coa_templates/{$file}")
            ), true);

            DB::transaction(function () use ($code, $data) {
                $templateId = DB::table('coa_templates')->upsertGetId([
                    'code'         => $code,
                    'name_en'      => $data['name_en'],
                    'name_ar'      => $data['name_ar'],
                    'country_code' => $data['country_code'],
                    'description'  => $data['description'],
                    'is_default'   => $data['is_default'] ?? false,
                    'updated_at'   => now(),
                ], ['code']);

                $lines = collect($data['lines'])->map(fn ($l) => [
                    'coa_template_id' => $templateId,
                    'account_code'    => $l['account_code'],
                    'parent_code'     => $l['parent_code'],
                    'name_en'         => $l['name_en'],
                    'name_ar'         => $l['name_ar'],
                    'account_type'    => $l['account_type'],
                    'account_subtype' => $l['account_subtype'] ?? null,
                    'normal_balance'  => $l['normal_balance'],
                    'is_control'      => $l['is_control'] ?? false,
                    'is_postable'     => $l['is_postable'] ?? true,
                    'sort_order'      => $l['sort_order'],
                    'updated_at'      => now(),
                ])->all();

                DB::table('coa_template_lines')->upsert(
                    $lines,
                    uniqueBy: ['coa_template_id', 'account_code'],
                    update: ['parent_code', 'name_en', 'name_ar', 'account_type',
                             'account_subtype', 'normal_balance', 'is_control',
                             'is_postable', 'sort_order', 'updated_at']
                );
            });
        }
    }
}
```

Cloning into a company (`DefaultChartOfAccountsSeeder`, called only from
`CompanyProvisioningSeeder`) resolves `parent_code` to the newly generated `parent_id` in two passes
— insert all lines first with `parent_id = NULL`, then a single `UPDATE ... FROM` fixes up parents by
matching `account_code`:

```php
public function run(int $companyId, string $templateCode): void
{
    $templateId = DB::table('coa_templates')->where('code', $templateCode)->value('id');

    $lines = DB::table('coa_template_lines')
        ->where('coa_template_id', $templateId)
        ->orderBy('sort_order')
        ->get();

    DB::transaction(function () use ($lines, $companyId) {
        $codeToId = [];

        foreach ($lines as $line) {
            $id = DB::table('accounts')->insertGetId([
                'company_id'      => $companyId,
                'code'            => $line->account_code,
                'parent_id'       => null,               // fixed up below
                'name_en'         => $line->name_en,
                'name_ar'         => $line->name_ar,
                'account_type'    => $line->account_type,
                'account_subtype' => $line->account_subtype,
                'normal_balance'  => $line->normal_balance,
                'is_control'      => $line->is_control,
                'is_postable'     => $line->is_postable,
                'status'          => 'active',
                'created_at'      => now(),
                'updated_at'      => now(),
            ]);
            $codeToId[$line->account_code] = $id;
        }

        foreach ($lines as $line) {
            if ($line->parent_code !== null) {
                DB::table('accounts')
                    ->where('id', $codeToId[$line->account_code])
                    ->update(['parent_id' => $codeToId[$line->parent_code]]);
            }
        }
    });
}
```

# Roles & Permissions Seeding

`permissions` (system reference) and `system_roles` / `role_templates` (system reference) are seeded
once, platform-wide. Every company then gets its OWN rows in `roles` and `role_permissions`, cloned
from the templates at provisioning time — this lets a company later customize a role (e.g. restrict
"Accountant" further) without mutating the platform default that other companies still use.

```sql
CREATE TABLE permissions (
    id          BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    key         VARCHAR(100) NOT NULL,          -- '<area>.<action>', e.g. 'accounting.journal.post'
    area        VARCHAR(40)  NOT NULL,
    label_en    VARCHAR(160) NOT NULL,
    label_ar    VARCHAR(160) NOT NULL,
    is_sensitive BOOLEAN     NOT NULL DEFAULT false,  -- requires approval chain, never AI-only
    created_at  TIMESTAMPTZ  NOT NULL DEFAULT now(),
    updated_at  TIMESTAMPTZ  NOT NULL DEFAULT now(),
    CONSTRAINT permissions_key_uk UNIQUE (key)
);

CREATE TABLE role_templates (
    id          BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    code        VARCHAR(60)  NOT NULL,          -- 'owner','cfo','senior_accountant','auditor', ...
    name_en     VARCHAR(120) NOT NULL,
    name_ar     VARCHAR(120) NOT NULL,
    is_system   BOOLEAN      NOT NULL DEFAULT true,
    created_at  TIMESTAMPTZ  NOT NULL DEFAULT now(),
    updated_at  TIMESTAMPTZ  NOT NULL DEFAULT now(),
    CONSTRAINT role_templates_code_uk UNIQUE (code)
);

CREATE TABLE role_template_permissions (
    role_template_id BIGINT NOT NULL REFERENCES role_templates(id) ON DELETE CASCADE,
    permission_id    BIGINT NOT NULL REFERENCES permissions(id) ON DELETE CASCADE,
    PRIMARY KEY (role_template_id, permission_id)
);
```

`PermissionSeeder` seeds the ~140-row permission catalog from a single fixture grouped by area
(`accounting`, `bank`, `payroll`, `inventory`, `tax`, `reports`, `sales`, `purchasing`, `settings`,
`ai`), each row flagged `is_sensitive = true` for the operations the platform design mandates a human
approval chain for (`bank.transfer.approve`, `payroll.release`, `tax.submit`, `accounting.journal.void`,
`settings.permissions.update`):

```php
<?php

namespace Database\Seeders\Reference;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = json_decode(file_get_contents(
            database_path('seeders/data/permissions.json')
        ), true);

        $rows = collect($permissions)->map(fn ($p) => [
            'key'          => $p['key'],
            'area'         => $p['area'],
            'label_en'     => $p['label_en'],
            'label_ar'     => $p['label_ar'],
            'is_sensitive' => $p['is_sensitive'] ?? false,
            'updated_at'   => now(),
        ])->all();

        DB::table('permissions')->upsert(
            $rows,
            uniqueBy: ['key'],
            update: ['area', 'label_en', 'label_ar', 'is_sensitive', 'updated_at']
        );
    }
}
```

Default role-to-permission mapping (excerpt — full matrix ships as
`database/seeders/data/role_template_permissions.json`):

| Permission key | Owner | CFO | Finance Manager | Senior Accountant | Accountant | Auditor | Read Only |
|---|---|---|---|---|---|---|---|
| `accounting.read` | Yes | Yes | Yes | Yes | Yes | Yes | Yes |
| `accounting.journal.create` | Yes | Yes | Yes | Yes | Yes | No | No |
| `accounting.journal.post` | Yes | Yes | Yes | Yes | No | No | No |
| `accounting.journal.void` | Yes | Yes | No | No | No | No | No |
| `bank.reconcile` | Yes | Yes | Yes | Yes | No | No | No |
| `bank.transfer` | Yes | Yes | Yes | No | No | No | No |
| `payroll.approve` | Yes | Yes | Yes | No | No | No | No |
| `tax.submit` | Yes | Yes | Yes | No | No | No | No |
| `reports.export` | Yes | Yes | Yes | Yes | Yes | Yes | No |
| `settings.permissions.update` | Yes | No | No | No | No | No | No |

`SystemRoleSeeder` seeds `role_templates` and `role_template_permissions` from that matrix in one pass,
using the same upsert-with-natural-key pattern as `PermissionSeeder`.

# New-Company Provisioning Seed

Company signup does not call individual seeders directly from a controller. It calls
`CompanyProvisioningSeeder::run($companyId, $options)`, a single orchestrator invoked from
`App\Services\CompanyService::provision()` inside the SAME database transaction that inserts the
`companies` row, so a failure at any step rolls back the entire signup — a company is never left in a
half-provisioned state.

```php
<?php

namespace Database\Seeders\Company;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CompanyProvisioningSeeder extends Seeder
{
    /**
     * @param  array{
     *   coa_template_code: string,
     *   country_code: string,
     *   base_currency_code: string,
     *   fiscal_year_start_month: int,
     *   owner_user_id: int
     * } $options
     */
    public function run(int $companyId, array $options): void
    {
        DB::transaction(function () use ($companyId, $options) {
            // 1. Default fiscal year + 12 monthly periods
            app(DefaultFiscalYearSeeder::class)->run(
                $companyId,
                $options['fiscal_year_start_month']
            );

            // 2. Chart of Accounts cloned from the chosen template
            app(DefaultChartOfAccountsSeeder::class)->run(
                $companyId,
                $options['coa_template_code']
            );

            // 3. Tax codes + rates for the company's country
            app(DefaultTaxCodesSeeder::class)->run(
                $companyId,
                $options['country_code']
            );

            // 4. Units of measure (base subset: piece, kg, box, hour, liter, m2)
            app(DefaultUnitsOfMeasureSeeder::class)->run($companyId);

            // 5. Roles + permissions cloned from role_templates
            app(DefaultRolesSeeder::class)->run($companyId);

            // 6. Number sequences (invoice, bill, journal entry, receipt, PO, ...)
            app(DefaultNumberSequencesSeeder::class)->run($companyId);

            // 7. Assign the signup user to the "Owner" role (must exist after step 5)
            $ownerRoleId = DB::table('roles')
                ->where('company_id', $companyId)
                ->where('code', 'owner')
                ->value('id');

            DB::table('company_users')->insert([
                'company_id' => $companyId,
                'user_id'    => $options['owner_user_id'],
                'role_id'    => $ownerRoleId,
                'status'     => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }
}
```

`DefaultNumberSequencesSeeder` seeds the per-document numbering that every transactional module reads
from (`sales_orders`, `invoices`, `bills`, `journal_entries`, `receipts`, `purchase_orders`, …):

```sql
CREATE TABLE number_sequences (
    id             BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id     BIGINT      NOT NULL REFERENCES companies(id),
    branch_id      BIGINT      NULL REFERENCES branches(id),
    document_type  VARCHAR(40) NOT NULL,      -- 'invoice','bill','journal_entry','receipt','purchase_order', ...
    prefix         VARCHAR(10) NOT NULL DEFAULT '',
    next_number    BIGINT      NOT NULL DEFAULT 1,
    padding        SMALLINT    NOT NULL DEFAULT 6,
    reset_policy   VARCHAR(10) NOT NULL DEFAULT 'never'
                   CHECK (reset_policy IN ('never','yearly','monthly')),
    created_at     TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at     TIMESTAMPTZ NOT NULL DEFAULT now(),
    CONSTRAINT number_sequences_uk UNIQUE (company_id, branch_id, document_type)
);
```

```php
private const DEFAULT_SEQUENCES = [
    ['document_type' => 'invoice',        'prefix' => 'INV-', 'padding' => 6, 'reset_policy' => 'yearly'],
    ['document_type' => 'bill',           'prefix' => 'BILL-', 'padding' => 6, 'reset_policy' => 'yearly'],
    ['document_type' => 'journal_entry',  'prefix' => 'JE-',   'padding' => 8, 'reset_policy' => 'never'],
    ['document_type' => 'receipt',        'prefix' => 'RCP-',  'padding' => 6, 'reset_policy' => 'yearly'],
    ['document_type' => 'purchase_order', 'prefix' => 'PO-',   'padding' => 6, 'reset_policy' => 'yearly'],
    ['document_type' => 'credit_note',    'prefix' => 'CN-',   'padding' => 6, 'reset_policy' => 'yearly'],
    ['document_type' => 'debit_note',     'prefix' => 'DN-',   'padding' => 6, 'reset_policy' => 'yearly'],
];

public function run(int $companyId): void
{
    $now = now();
    $rows = collect(self::DEFAULT_SEQUENCES)->map(fn ($s) => [
        'company_id'    => $companyId,
        'branch_id'     => null,
        'document_type' => $s['document_type'],
        'prefix'        => $s['prefix'],
        'next_number'   => 1,
        'padding'       => $s['padding'],
        'reset_policy'  => $s['reset_policy'],
        'created_at'    => $now,
        'updated_at'    => $now,
    ])->all();

    DB::table('number_sequences')->upsert(
        $rows,
        uniqueBy: ['company_id', 'branch_id', 'document_type'],
        update: [] // provisioning-only: never overwrite a company's live counter/prefix on re-run
    );
}
```

Note the empty `update: []` — this is deliberate. `CompanyProvisioningSeeder` runs exactly once per
company (guarded by `CompanyService::provision()`, which itself checks `companies.provisioned_at IS
NULL` before calling the seeder). If it were ever invoked again for the same company id, the upsert
must be a strict no-op rather than resetting `next_number` back to 1 and duplicating invoice numbers.

# Ordering & Dependencies

Seeders must run in dependency order because of foreign keys. The canonical order, enforced by
`DatabaseSeeder::run()`'s `$this->call([...])` array order for reference data and by
`CompanyProvisioningSeeder::run()`'s method-call order for per-company data:

```
Reference data (platform-wide, no company_id):
  1. countries
  2. currencies
  3. account_types                 (no dependency, but conceptually precedes coa_templates)
  4. tax_systems                   (depends on: countries)
  5. permissions                   (no dependency)
  6. system_roles / role_templates (depends on: permissions, via role_template_permissions)
  7. units_of_measure_catalog      (no dependency)
  8. industries                    (no dependency)
  9. coa_templates + lines         (depends on: countries, account_types)

Per-company provisioning (inside one transaction, after companies row exists):
  1. fiscal_years + fiscal_periods (depends on: companies)
  2. accounts                      (depends on: companies, coa_templates)
  3. tax_codes + tax_rates         (depends on: companies, tax_systems)
  4. units_of_measure              (depends on: companies, units_of_measure_catalog)
  5. roles + role_permissions      (depends on: companies, role_templates, permissions)
  6. number_sequences              (depends on: companies)
  7. company_users (owner link)    (depends on: companies, roles, users)
```

Cross-cutting rule: **no per-company seeder may run before its corresponding reference seeder has run
at least once on that database.** `CompanyProvisioningSeeder` defends this defensively rather than
assuming migration order — every clone step first checks its source template exists and throws a
descriptive exception otherwise, rather than inserting `NULL`s or silently skipping:

```php
$templateId = DB::table('coa_templates')->where('code', $templateCode)->value('id');

if ($templateId === null) {
    throw new \RuntimeException(
        "CoA template [{$templateCode}] not found. Run CoaTemplateSeeder before provisioning companies."
    );
}
```

Within the migrations themselves (not seeders), the same ordering constraint applies at the schema
level: `coa_templates`/`coa_template_lines`, `permissions`/`role_templates`, `tax_systems`,
`units_of_measure_catalog`, `countries`, and `currencies` migrations MUST be timestamped (and therefore
run) before the `accounts`, `roles`, `tax_codes`, and `units_of_measure` migrations that carry foreign
keys into them.

# Versioning Seed Data

QAYD's default catalogs (CoA templates, permission set, role-permission matrix, UoM catalog) evolve
across releases. Two rules keep this safe for companies that already provisioned against an older
version:

1. **Templates are versioned by a `version` column, not by mutating in place across a breaking change.**
   A backward-compatible fixture edit (fixing a typo in `name_ar`, adding a new optional line) upserts
   in place using the natural key as shown above. A breaking change (renumbering accounts, changing an
   account's `normal_balance`) instead ships as a NEW template code, e.g. `kw_general_trading_v2`, so
   existing companies whose `accounts` were already cloned from `kw_general_trading` (v1) are
   completely unaffected — their rows are independent copies, not live references to the template.

```sql
ALTER TABLE coa_templates ADD COLUMN version SMALLINT NOT NULL DEFAULT 1;
ALTER TABLE coa_templates ADD COLUMN superseded_by_id BIGINT NULL REFERENCES coa_templates(id);
```

2. **`companies` records which template version it was provisioned from**, purely for support/audit
   traceability (e.g. "offer this company a one-time opt-in migration to v2 accounts"):

```sql
ALTER TABLE companies ADD COLUMN coa_template_code VARCHAR(40) NULL;
ALTER TABLE companies ADD COLUMN coa_template_version SMALLINT NULL;
```

3. **Fixture files are committed to git and reviewed like code.** Every change to
   `database/seeders/data/**/*.json` goes through the same PR review as a migration; the PR diff IS
   the changelog. A `seed_data_versions` table additionally records, per reference table, the git
   commit SHA and timestamp of the last seed applied, written by a shared trait every reference seeder
   uses:

```sql
CREATE TABLE seed_data_versions (
    seeder_class  VARCHAR(160) PRIMARY KEY,
    git_sha       VARCHAR(40)  NOT NULL,
    applied_at    TIMESTAMPTZ  NOT NULL DEFAULT now()
);
```

```php
trait RecordsSeedVersion
{
    protected function recordVersion(): void
    {
        DB::table('seed_data_versions')->upsert([
            'seeder_class' => static::class,
            'git_sha'      => trim(shell_exec('git rev-parse HEAD') ?? 'unknown'),
            'applied_at'   => now(),
        ], ['seeder_class'], ['git_sha', 'applied_at']);
    }
}
```

This table lets an engineer answer "what reference data version is production running?" with a single
query, and lets the release pipeline assert `seed_data_versions.applied_at` advanced after a deploy
that touched fixture files.

# Testing With Factories

Seeders and Laravel model factories serve different purposes and are NOT interchangeable:

- **Seeders** populate deterministic, named, business-meaningful data (a specific currency list, a
  specific CoA template) that the application logic depends on existing. They are part of the
  application, versioned like code, and run in every environment.
- **Factories** generate throwaway, randomized data for a single test's arrange step. They depend on
  reference data already being seeded (a `JournalEntryFactory` needs `account_types` and at least one
  `accounts` row to point a `journal_lines` row at) but never duplicate what a seeder already provides.

```php
<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

class AccountFactory extends Factory
{
    protected $model = Account::class;

    public function definition(): array
    {
        return [
            'company_id'     => Company::factory(),
            'code'           => fn () => (string) $this->faker->unique()->numberBetween(1000, 9999),
            'name_en'        => fn () => $this->faker->words(2, true),
            'name_ar'        => fn () => $this->faker->words(2, true),
            'account_type'   => $this->faker->randomElement(['asset','liability','equity','revenue','expense']),
            'normal_balance' => 'debit',
            'is_postable'    => true,
            'status'         => 'active',
        ];
    }

    public function asset(): static
    {
        return $this->state(['account_type' => 'asset', 'normal_balance' => 'debit']);
    }

    public function revenue(): static
    {
        return $this->state(['account_type' => 'revenue', 'normal_balance' => 'credit']);
    }
}
```

A Pest feature test composes both: seeded reference data via `RefreshDatabase` + a targeted call to
`Reference\AccountTypeSeeder`, and factory-generated per-test rows for everything else:

```php
uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(\Database\Seeders\Reference\AccountTypeSeeder::class);
    $this->seed(\Database\Seeders\Reference\CurrencySeeder::class);
});

it('balances a journal entry to the cent', function () {
    $company = Company::factory()->create();
    $cash    = Account::factory()->for($company)->asset()->create(['code' => '1110']);
    $revenue = Account::factory()->for($company)->revenue()->create(['code' => '4100']);

    $entry = JournalEntry::factory()->for($company)->create();
    JournalLine::factory()->for($entry)->create(['account_id' => $cash->id, 'debit' => 100.0000, 'credit' => 0]);
    JournalLine::factory()->for($entry)->create(['account_id' => $revenue->id, 'debit' => 0, 'credit' => 100.0000]);

    expect($entry->lines()->sum('debit'))->toEqual($entry->lines()->sum('credit'));
});
```

CI runs `RefreshDatabase` per test class (transactional rollback between tests), so reference seeders
called in `beforeEach` are cheap (a handful of upserts against an already-migrated schema) and never
leak state across test files.

# Edge Cases

- **Re-running `CompanyProvisioningSeeder` against an already-provisioned company.** Guarded at the
  application layer (`CompanyService::provision()` checks `provisioned_at IS NULL`), and defensively
  at the data layer: `DefaultChartOfAccountsSeeder`'s clone step is wrapped so that if `accounts` for
  that `company_id` already has rows, it aborts with a `CompanyAlreadyProvisionedException` rather than
  inserting a duplicate CoA.
- **Fixture file references a `parent_code` that does not exist in the same template.** The two-pass
  clone (insert-then-link) leaves `parent_id = NULL` for any code with no match in `$codeToId`, rather
  than throwing — but `CoaTemplateSeeder` itself validates every `parent_code` against the set of
  `account_code`s in the same JSON file at seed time and refuses to upsert a template with a dangling
  reference, so this can only surface as a template-authoring bug caught in CI, never in production.
- **A company deactivates a currency, tax code, or UoM it no longer uses.** Per-company tables use
  `status`/`is_active` for this; the row is never deleted (financial history may reference it via
  `journal_lines.currency_code` or `invoice_items.unit_of_measure_id`), and re-running the per-company
  seeder for that entity is a no-op (`update: []`) so a deactivated row is never silently reactivated.
- **Two concurrent signups race to provision the same `company_id`.** Cannot occur: `companies.id` is
  an identity column generated by the INSERT that starts provisioning, and the entire
  `CompanyProvisioningSeeder::run()` call happens inside that same transaction — no other transaction
  can see or act on the row until commit.
- **A reference fixture (e.g. `currencies.json`) is edited to REMOVE a currency already in use by a
  company's `journal_lines.currency_code`.** Reference seeders never `DELETE` — they only `INSERT`/
  `UPDATE` via upsert. A currency code disappearing from the fixture leaves the existing DB row
  untouched; removal from production requires an explicit, reviewed migration that sets `is_active =
  false`, never a seeder.
- **Kuwait vs. Saudi default CoA divergence for a company that changes its registered country
  post-signup.** Changing `companies.country_code` after provisioning does NOT re-clone the Chart of
  Accounts — accounts are immutable business history once posted against. The company must run the
  standard "add missing tax codes for new jurisdiction" flow (an explicit, user-triggered action in the
  Tax module), not an automatic reseed.
- **Demo seeder accidentally pointed at a database with `APP_ENV=production` due to a misconfigured
  `.env` on a new server.** `DemoCompanySeeder::run()`'s environment guard (see
  `# Environment-Specific Seeds`) throws before any write occurs; additionally, the production
  deploy pipeline never calls `DatabaseSeeder` at all — it calls `ReferenceOnlySeeder` by explicit
  class name, so the demo path is unreachable from the production release job regardless of `.env`
  drift.
- **Bulk JSON fixture file is malformed (trailing comma, wrong encoding) and ships in a release.**
  Every reference seeder's `json_decode()` call is followed by a `json_last_error()` check that aborts
  the whole seeding run with the offending file path in the exception message, and a Pest test per
  fixture file (`it('is valid json')`) runs in CI before the file can merge.
- **`next_number` collision under concurrent invoice creation within the same company.** Out of scope
  for the seeder itself (`number_sequences` rows are seeded once, not incremented by the seeder), but
  documented here because it is the direct consumer of this seed data: the Invoicing service increments
  `next_number` via `SELECT ... FOR UPDATE` inside the invoice-creation transaction, never via a
  read-then-write from application memory.

# End of Document
