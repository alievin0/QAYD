<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Opaque pagination cursors (docs/api/API_ARCHITECTURE.md "# Cursor-style pagination").
 *
 * A cursor is a base64url-encoded JSON pointer to the last row a caller received. The encoding is
 * deliberately NOT part of the API contract — the standard states a client must treat the cursor as
 * opaque and never construct or parse one — which is what lets a resource key its cursor on whatever
 * columns its sort order actually uses. `ledger_entries` needs that: its rows are read in
 * `(entry_date, id)` order, and a cursor keyed on `id` alone would silently skip rows the moment a
 * backdated entry is posted.
 *
 * Decoding is total and never throws: a malformed, truncated, or hand-forged cursor decodes to `null`,
 * and the caller treats that as "no cursor" (first page) rather than a 500. A cursor is a navigation
 * hint, not an authorization token — every query it feeds is still RLS-scoped and permission-gated, so
 * a forged one can only ever move a reader around inside their own company's rows.
 */
final class Cursor
{
    /**
     * @param  array<string, scalar>  $pointer
     */
    public static function encode(array $pointer): string
    {
        $json = json_encode($pointer);

        if ($json === false) {
            return '';
        }

        return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }

    /**
     * @return array<string, scalar>|null null when the cursor is absent or unreadable
     */
    public static function decode(?string $cursor): ?array
    {
        if ($cursor === null || $cursor === '') {
            return null;
        }

        $binary = base64_decode(strtr($cursor, '-_', '+/'), true);

        if ($binary === false) {
            return null;
        }

        $decoded = json_decode($binary, true);

        if (! is_array($decoded)) {
            return null;
        }

        $pointer = [];
        foreach ($decoded as $key => $value) {
            if (is_string($key) && is_scalar($value)) {
                $pointer[$key] = $value;
            }
        }

        return $pointer === [] ? null : $pointer;
    }
}
