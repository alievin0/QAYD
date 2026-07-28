<?php

declare(strict_types=1);

namespace App\Support;

use LogicException;

/**
 * Type-safe column access on a raw `selectOne()` result. PDO returns an untyped `stdClass` (`mixed` to
 * the analyser), so every raw-SQL read has to narrow its columns before use; this is the one place that
 * narrowing lives, instead of being re-spelled in each caller. A column the query itself selected must
 * be present — its absence is a programming error in the SQL, not a runtime condition, so it throws.
 */
final class SqlRow
{
    public static function string(mixed $row, string $column): string
    {
        $value = self::value($row, $column);

        return is_scalar($value) ? (string) $value : '';
    }

    public static function int(mixed $row, string $column): int
    {
        $value = self::value($row, $column);

        return is_numeric($value) ? (int) $value : 0;
    }

    private static function value(mixed $row, string $column): mixed
    {
        if (! is_object($row) || ! property_exists($row, $column)) {
            throw new LogicException("Column '{$column}' is missing from the query result.");
        }

        return $row->{$column};
    }
}
