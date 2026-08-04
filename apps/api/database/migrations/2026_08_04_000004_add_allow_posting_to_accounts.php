<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * S2-11 prerequisite — `accounts.allow_posting` (docs/accounting/CHART_OF_ACCOUNTS.md, closing TD-15).
 *
 * An account is either a POSTING account, which a journal line may reference, or a header/group account
 * that exists to organise the tree and roll its children up. Until now QAYD had no way to say which:
 * the posting engine checked only `status = 'active'`, and the chart-of-accounts screen deliberately
 * refused to infer postability from leaf-ness, because CHART_OF_ACCOUNTS.md is explicit that a leaf can
 * still be marked non-postable. So the journal editor had nothing truthful to filter its account picker
 * on. This column is that fact.
 *
 * **PostgreSQL is the source of truth for it, not the application.** One half of the rule is derivable
 * and therefore enforced here rather than remembered:
 *
 *   - `trg_accounts_parent_not_postable` — the moment an account gains a child it becomes a header, so
 *     its `allow_posting` is cleared automatically. Clearing rather than refusing is deliberate: adding
 *     a child under a leaf is a normal way to grow a chart, and refusing it would turn the natural
 *     action into an error the user has to work around.
 *   - `trg_accounts_no_postable_parent` — and it cannot be set back to true while children exist. That
 *     is the half that must refuse, because re-enabling it would let a line post to a node whose balance
 *     is supposed to be the sum of its children.
 *
 * The remaining half — a childless account a tenant wants to keep non-postable for organisational
 * reasons — is a human decision the column simply stores.
 *
 * Existing rows: `true` by default (every account created so far was created to be posted to), then
 * backfilled to `false` wherever children already exist, so the invariant holds from the first moment.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE accounts ADD COLUMN allow_posting BOOLEAN NOT NULL DEFAULT true');

        // Any account that is already a parent is a header by definition.
        DB::statement(<<<'SQL'
            UPDATE accounts SET allow_posting = false
            WHERE EXISTS (SELECT 1 FROM accounts child WHERE child.parent_id = accounts.id)
            SQL);

        DB::statement('CREATE INDEX idx_accounts_postable ON accounts (company_id, allow_posting)');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION accounts_clear_parent_posting() RETURNS trigger AS $$
            BEGIN
                IF NEW.parent_id IS NOT NULL THEN
                    UPDATE accounts SET allow_posting = false
                    WHERE id = NEW.parent_id AND allow_posting;
                END IF;

                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;

            CREATE OR REPLACE FUNCTION accounts_reject_postable_parent() RETURNS trigger AS $$
            BEGIN
                IF NEW.allow_posting AND EXISTS (
                    SELECT 1 FROM accounts child WHERE child.parent_id = NEW.id
                ) THEN
                    RAISE EXCEPTION
                        'account % has child accounts and cannot accept postings; its balance is the sum of its children',
                        NEW.id
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
            SQL);

        // AFTER, and only when parent_id is actually written: the auto-clear updates `allow_posting`
        // on a different row, which does not re-enter this trigger.
        DB::statement(<<<'SQL'
            CREATE TRIGGER trg_accounts_parent_not_postable
                AFTER INSERT OR UPDATE OF parent_id ON accounts
                FOR EACH ROW EXECUTE FUNCTION accounts_clear_parent_posting()
            SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER trg_accounts_no_postable_parent
                BEFORE UPDATE OF allow_posting ON accounts
                FOR EACH ROW EXECUTE FUNCTION accounts_reject_postable_parent()
            SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS trg_accounts_no_postable_parent ON accounts');
        DB::statement('DROP TRIGGER IF EXISTS trg_accounts_parent_not_postable ON accounts');
        DB::statement('DROP FUNCTION IF EXISTS accounts_reject_postable_parent()');
        DB::statement('DROP FUNCTION IF EXISTS accounts_clear_parent_posting()');
        DB::statement('DROP INDEX IF EXISTS idx_accounts_postable');
        DB::statement('ALTER TABLE accounts DROP COLUMN IF EXISTS allow_posting');
    }
};
