<?php

declare(strict_types=1);

namespace Talea\Core;

use Phinx\Db\Action\AddIndex;
use Phinx\Db\Action\DropIndex;
use Phinx\Db\Adapter\TablePrefixAdapter;
use Phinx\Db\Table\Index;
use Phinx\Db\Table\Table;

/**
 * Phinx's table prefix wrapper, registered by Migrator::phinxConfig() for every engine. Two jobs:
 *  - Multisite (docs/specs/multisite.md): in the run of a further site (option owner_prefix differs from table_prefix) the owner
 *    tables (Db::OWNER_TABLES) keep the owner prefix – a foreign key to users points at tl_users – and every write to them is
 *    skipped, because the owner run already made it. The install tables (Db::INSTALL_TABLES) are made in the install run only
 *    (table_prefix = install_prefix) and skipped in every other. Index names get the table prefix unless it is the install's
 *    (MigrationSupport::indexName()). A migration file is written once, for one site, as before.
 *  - SQLite: an auto-numbered biginteger key becomes INTEGER, and the foreign key check gets the real table names.
 */
final class PrefixAdapter extends TablePrefixAdapter
{
    public function getAdapterTableName(string $tableName): string
    {
        if (in_array($tableName, Db::INSTALL_TABLES, true) && MigrationSupport::installPrefix($this) !== $this->getOption('table_prefix')) {
            return MigrationSupport::installPrefix($this) . $tableName; // the install's table, made in the install run
        }

        return $this->ownerTable($tableName) ? $this->getOption('owner_prefix') . $tableName : parent::getAdapterTableName($tableName);
    }

    public function createTable(Table $table, array $columns = [], array $indexes = []): void
    {
        if ($this->ownerTable($table->getName())) {
            return;
        }
        foreach ($indexes as $index) {
            $this->siteIndex($index);
        }
        if ($this->getOption('adapter') === 'sqlite') {
            // an auto-numbered key must be INTEGER in SQLite (BIGINT AUTOINCREMENT is refused); SQLite's INTEGER holds 64 bits anyway
            foreach ($columns as $column) {
                if ($column->getIdentity() && $column->getType() === 'biginteger') {
                    $column->setType('integer');
                }
            }
        }
        parent::createTable($table, $columns, $indexes);
    }

    public function executeActions(Table $table, array $actions): void
    {
        if ($this->ownerTable($table->getName())) {
            return;
        }
        foreach ($actions as $action) {
            if ($action instanceof AddIndex || $action instanceof DropIndex) {
                $this->siteIndex($action->getIndex());
            }
        }
        parent::executeActions($table, $actions);
    }

    public function hasIndexByName(string $tableName, string $indexName): bool
    {
        return parent::hasIndexByName($tableName, MigrationSupport::indexName($this, $indexName));
    }

    public function dropIndexByName(string $tableName, string $indexName): void
    {
        parent::dropIndexByName($tableName, MigrationSupport::indexName($this, $indexName));
    }

    public function insert(Table $table, array $row): void
    {
        if (!$this->ownerTable($table->getName())) {
            parent::insert($table, $row);
        }
    }

    public function bulkinsert(Table $table, array $rows): void
    {
        if (!$this->ownerTable($table->getName())) {
            parent::bulkinsert($table, $rows);
        }
    }

    public function truncateTable(string $tableName): void
    {
        if (!$this->ownerTable($tableName)) {
            parent::truncateTable($tableName);
        }
    }

    /** SQLite: the table names of a finished step reach its foreign key check without the prefix ("no such table: user_permissions"). */
    public function postExecuteActions(array $tableNames, array $preOptions): void
    {
        parent::postExecuteActions($this->getOption('adapter') === 'sqlite' ? array_map($this->getAdapterTableName(...), $tableNames) : $tableNames, $preOptions);
    }

    private function siteIndex(Index $index): void
    {
        if ($index->getName() !== null) {
            $index->setName(MigrationSupport::indexName($this, $index->getName()));
        }
    }

    /** An owner table while a further site is migrated, an install table while any other run goes: the earlier run already wrote it. */
    private function ownerTable(string $table): bool
    {
        return MigrationSupport::ownerTableInSiteRun($this, $table);
    }
}
