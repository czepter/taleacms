<?php

declare(strict_types=1);

namespace Talea\Core;

use Phinx\Db\Adapter\AdapterInterface;
use Phinx\Db\Table;
use Phinx\Migration\AbstractMigration;
use Phinx\Util\Literal;
use Talea\Core\Dialect\Postgres;

/**
 * What a migration file needs where MySQL and PostgreSQL differ. The migrations stay in Phinx's DSL; the SQL this has to write
 * is here (not in the migration files) and only runs on PostgreSQL, so the MySQL schema is exactly what the DSL says.
 */
final class MigrationSupport
{
    public static function isPostgres(AdapterInterface $adapter): bool
    {
        return $adapter->getOption('adapter') === 'pgsql'; // getAdapterType() of the prefix wrapper is not the engine
    }

    /**
     * Type and options of a public_id column (UUID v4): CHAR(36) with a generated default on MySQL, a native uuid on PostgreSQL.
     * Core\Uuid fills the column on insert; the default covers raw inserts. Use: ->addColumn('public_id', ...MigrationSupport::publicId($this->getAdapter()))
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    public static function publicId(AdapterInterface $adapter): array
    {
        $comment = 'UUID v4: the identifier that leaves the server (Core\\Uuid fills it, the default covers raw inserts); the integer key stays internal';
        if (self::isPostgres($adapter)) {
            return ['uuid', ['null' => false, 'default' => Literal::from('gen_random_uuid()'), 'comment' => $comment]];
        }

        return ['char', ['limit' => 36, 'null' => false, 'default' => Literal::from('(LOWER(CONCAT(HEX(RANDOM_BYTES(4)), \'-\', HEX(RANDOM_BYTES(2)), \'-4\', SUBSTR(HEX(RANDOM_BYTES(2)), 2), \'-\', SUBSTR(\'89ab\', 1 + FLOOR(RAND() * 4), 1), SUBSTR(HEX(RANDOM_BYTES(2)), 2), \'-\', HEX(RANDOM_BYTES(6)))))'), 'comment' => $comment]];
    }

    /**
     * Creates the table with its full-text indexes: a FULLTEXT index on MySQL, a GIN index over a tsvector on PostgreSQL
     * (created right after the table, the query uses the same expression: Dialect::fulltextMatch()).
     *
     * @param array<string, list<string>> $fulltext index name => columns
     */
    public static function create(AbstractMigration $migration, Table $table, string $name, array $fulltext): void
    {
        $adapter = $migration->getAdapter();
        if (!self::isPostgres($adapter)) {
            foreach ($fulltext as $index => $columns) {
                $table->addIndex($columns, ['name' => $index, 'type' => 'fulltext']);
            }
            $table->create();

            return;
        }
        $table->create();
        $real = (string) $adapter->getOption('table_prefix') . $name;
        foreach ($fulltext as $index => $columns) {
            $migration->execute((new Postgres())->fulltextIndex($real, $index, $columns));
        }
    }

    /** PostgreSQL only: the ICU collation that ignores case and accents (see docs/specs/postgres.md). Nothing on MySQL, whose default collation does it. */
    public static function createInsensitiveCollation(AbstractMigration $migration): void
    {
        if (self::isPostgres($migration->getAdapter())) {
            $migration->execute('CREATE COLLATION IF NOT EXISTS ' . Postgres::COLLATION . " (provider = icu, locale = 'und-u-ks-level1', deterministic = false)");
        }
    }

    /** PostgreSQL only: columns that are compared the way MySQL compares everything (any case, any accents): sign-in names, e-mails, slugs. @param array<string, list<string>> $columns table => columns */
    public static function ignoreCase(AbstractMigration $migration, array $columns): void
    {
        $adapter = $migration->getAdapter();
        if (!self::isPostgres($adapter)) {
            return;
        }
        $dialect = new Postgres();
        foreach ($columns as $table => $names) {
            foreach ($names as $column) {
                $real = $dialect->quote((string) $adapter->getOption('table_prefix') . $table);
                $type = (string) $migration->fetchRow("SELECT format_type(a.atttypid, a.atttypmod) AS type FROM pg_attribute a WHERE a.attrelid = '{$real}'::regclass AND a.attname = '{$column}'")['type'];
                $migration->execute("ALTER TABLE {$real} ALTER COLUMN " . $dialect->quote($column) . " TYPE {$type} COLLATE " . $dialect->quote(Postgres::COLLATION));
            }
        }
    }
}
