<?php

declare(strict_types=1);

namespace Kaleta\Core;

/** Helpers for SQL scripts shipped by add-ons (extension.sql): splitting into statements and filling in the table prefix. */
final class SqlScript
{
    /**
     * Splits an SQL script into statements and replaces the prefix "ka_" with the installation's prefix.
     *
     * @return list<string>
     */
    public static function statements(string $sql, string $prefix): array
    {
        // constraint names must be unique in the database - they get the prefix too
        $sql = preg_replace('/\b((?:CONSTRAINT|DROP FOREIGN KEY)\s+)fk_/', '$1' . $prefix . 'fk_', $sql) ?? $sql;
        $sql = preg_replace('/\bka_(?=[a-z])/', $prefix, $sql) ?? $sql;
        // a statement ends with a semicolon at the end of a line; only a comment may follow the semicolon
        $statements = preg_split('/;[ \t]*(--[^\n]*)?(\r?\n|$)/', $sql) ?: [];

        return array_values(array_filter(array_map(trim(...), $statements), function (string $statement): bool {
            return trim((string) preg_replace('/^\s*--.*$/m', '', $statement)) !== '';
        }));
    }
}
