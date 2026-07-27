<?php

declare(strict_types=1);

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Casts a PHP `list<string>` to/from a PostgreSQL `text[]` column (used by `audit_logs.changed_fields`).
 *
 * Eloquent has no native text[] support: on write it emits a `{"a","b"}` array literal (each element
 * double-quoted and escaped, so field names with commas/quotes survive); on read it parses that
 * literal back into a list. A bound array literal is coerced by Postgres into the target array type,
 * so no explicit cast is needed in the INSERT.
 *
 * @implements CastsAttributes<list<string>, list<string>>
 */
final class PostgresTextArray implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     * @return list<string>|null
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?array
    {
        if ($value === null) {
            return null;
        }

        if (is_array($value)) {
            return $this->stringify($value);
        }

        if (! is_string($value)) {
            return null;
        }

        $inner = trim($value, '{}');
        if ($inner === '') {
            return [];
        }

        return $this->stringify(str_getcsv($inner, ',', '"', '\\'));
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<string>|null  $value
     * @return array<string, string|null>
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        if ($value === null) {
            return [$key => null];
        }

        $escaped = array_map(
            static fn (string $item): string => '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $item).'"',
            $value,
        );

        return [$key => '{'.implode(',', $escaped).'}'];
    }

    /**
     * Coerce a mixed array (raw driver output or a caller-supplied list) into a clean `list<string>`.
     *
     * @param  array<array-key, mixed>  $items
     * @return list<string>
     */
    private function stringify(array $items): array
    {
        return array_values(array_map(
            static fn (mixed $item): string => is_scalar($item) ? (string) $item : '',
            $items,
        ));
    }
}
