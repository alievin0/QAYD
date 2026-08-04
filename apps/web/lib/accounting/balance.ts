/**
 * Client-side balance derivation for the journal editor (S2-11).
 *
 * **This is advisory and nothing more.** `JournalEntryPostingService` is the single source of truth for
 * whether an entry balances: it re-derives the totals from the persisted lines with zero tolerance, in
 * both currencies, inside the posting transaction. What this module does is tell the person typing that
 * their entry does not add up *before* they ask the server — a UX affordance, not a rule.
 *
 * The story's DoD asks for exactly that ("a client-side `deriveBalance` that flags imbalance the same
 * way the backend will"), which makes this the one place in the codebase where duplicating a server rule
 * is deliberate rather than drift. Two things keep it honest:
 *
 *   - it only ever DISABLES a button; it never decides that something is postable, and
 *   - a backend `balance_mismatch` is displayed exactly as returned, never pre-empted or reworded.
 *
 * Money is handled as scaled integers via `bigint`, never as `number`. A JS float cannot represent
 * `0.1 + 0.2` exactly, and an editor that silently disagreed with the ledger by a thousandth of a fils
 * would be worse than one that did no arithmetic at all.
 */

/** The scale of every money column in the ledger: `NUMERIC(19,4)`. */
const SCALE = 4;

export interface DraftLine {
  accountId: number | null;
  debit: string;
  credit: string;
  description: string;
}

export interface DerivedBalance {
  /** Total debits, as a `0.0000`-formatted string. */
  totalDebit: string;
  /** Total credits, as a `0.0000`-formatted string. */
  totalCredit: string;
  /** `totalDebit − totalCredit`; negative when credits exceed debits. */
  difference: string;
  /** True only when the difference is exactly zero AND at least one line carries an amount. */
  isBalanced: boolean;
}

/**
 * Parse a money literal into minor units (scale 4). Anything unparseable counts as zero rather than
 * throwing: the field is mid-typing much of the time, and a half-written number is not an error yet.
 */
export function toMinor(value: string): bigint {
  const trimmed = value.trim();
  if (trimmed === "" || !/^\d*(\.\d*)?$/.test(trimmed)) return 0n;

  const [whole, fraction = ""] = trimmed.split(".");
  const padded = (fraction + "0".repeat(SCALE)).slice(0, SCALE);

  return (
    BigInt(whole === "" ? "0" : whole) * 10n ** BigInt(SCALE) +
    BigInt(padded === "" ? "0" : padded)
  );
}

/** Render minor units back as a fixed-scale decimal string, e.g. `-40.0000`. */
export function fromMinor(value: bigint): string {
  const negative = value < 0n;
  const absolute = negative ? -value : value;
  const divisor = 10n ** BigInt(SCALE);
  const whole = absolute / divisor;
  const fraction = (absolute % divisor).toString().padStart(SCALE, "0");

  return `${negative ? "-" : ""}${whole.toString()}.${fraction}`;
}

/**
 * Sum the grid's debits and credits and report the difference.
 *
 * An entry with no amounts at all is NOT balanced, even though its difference is zero: zero equals zero
 * is arithmetically true and accounting-wise meaningless, and enabling Post on an empty grid would send
 * the server something it will only reject.
 */
export function deriveBalance(lines: DraftLine[]): DerivedBalance {
  let debit = 0n;
  let credit = 0n;

  for (const line of lines) {
    debit += toMinor(line.debit);
    credit += toMinor(line.credit);
  }

  const difference = debit - credit;

  return {
    totalDebit: fromMinor(debit),
    totalCredit: fromMinor(credit),
    difference: fromMinor(difference),
    isBalanced: difference === 0n && (debit > 0n || credit > 0n),
  };
}

/**
 * A line is ready to send when it names an account and carries an amount on exactly one side. The
 * server enforces the same one-sidedness with a CHECK constraint; this only decides whether a row is
 * worth sending, so a half-filled row the user has not finished does not become a validation error.
 */
export function isCompleteLine(line: DraftLine): boolean {
  if (line.accountId === null) return false;

  const debit = toMinor(line.debit);
  const credit = toMinor(line.credit);

  return debit > 0n !== credit > 0n;
}
