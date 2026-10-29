<?php

declare(strict_types=1);

namespace Talea\Core;

use Phinx\Db\Adapter\TablePrefixAdapter;
use Phinx\Db\Table\Table;

/**
 * Phinx's table prefix wrapper with the two things SQLite needs from it. Registered by Migrator::phinxConfig() for SQLite only.
 */
final class SqlitePrefixAdapter extends TablePrefixAdapter
{
    /** An auto-numbered key must be INTEGER in SQLite (BIGINT AUTOINCREMENT is refused); SQLite's INTEGER holds 64 bits anyway. */
    public function createTable(Table $table, array $columns = [], array $indexes = []): void
    {
        foreach ($columns as $column) {
            if ($column->getIdentity() && $column->getType() === 'biginteger') {
                $column->setType('integer');
            }
        }
        parent::createTable($table, $columns, $indexes);
    }

    /** The table names of a finished step reach SQLite's foreign key check without the prefix ("no such table: user_permissions"). */
    public function postExecuteActions(array $tableNames, array $preOptions): void
    {
        parent::postExecuteActions(array_map($this->getAdapterTableName(...), $tableNames), $preOptions);
    }
}
