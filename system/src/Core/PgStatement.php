<?php

declare(strict_types=1);

namespace Talea\Core;

use PDO;
use PDOStatement;

/**
 * PostgreSQL hands boolean columns to PHP as bool, MySQL (TINYINT(1)) as int. The code was written against ints (`=== 1`, `(int)`, `$row['visible'] + 1`),
 * so every fetched bool becomes 1 or 0 here, for every way of fetching; nothing else is changed. Set through PDO::ATTR_STATEMENT_CLASS by Db on PostgreSQL only.
 */
final class PgStatement extends PDOStatement
{
    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        $row = parent::fetch($mode, $cursorOrientation, $cursorOffset);

        return $row === false ? false : self::ints($row); // false = no more rows
    }

    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        return array_map(self::ints(...), parent::fetchAll($mode, ...$args));
    }

    public function fetchColumn(int $column = 0): mixed
    {
        $row = parent::fetch(PDO::FETCH_NUM); // fetchColumn() cannot tell "no row" from a false value; fetch() can

        return $row === false ? false : self::ints($row[$column] ?? null);
    }

    private static function ints(mixed $value): mixed
    {
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }
        if (is_array($value)) {
            foreach ($value as $k => $v) {
                if (is_bool($v)) {
                    $value[$k] = $v ? 1 : 0;
                }
            }
        }

        return $value;
    }
}
