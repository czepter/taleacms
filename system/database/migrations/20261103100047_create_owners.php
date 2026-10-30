<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;
use Talea\Core\MigrationSupport;
use Talea\Core\Uuid;

/**
 * Several owners (docs/specs/multisite.md, "Several owners"): the install table {owners}, one row per owner. An install table
 * (Db::INSTALL_TABLES) is made in the install run only – PrefixAdapter skips it in the run of a further owner or site – and always
 * lives under the install prefix. The row of owner 1 has the install prefix, so a single-owner install is "one owner".
 */
final class CreateOwners extends AbstractMigration
{
    public function change(): void
    {
        $this->table('owners', ['id' => false, 'primary_key' => ['owner_id']])
            ->addColumn('owner_id', 'integer', ['signed' => false, 'identity' => true, 'null' => false])
            ->addColumn('public_id', ...MigrationSupport::publicId($this->getAdapter()))
            ->addColumn('name', 'string', ['limit' => 120, 'null' => false, 'default' => ''])
            ->addColumn('kind', 'string', ['limit' => 12, 'null' => false, 'default' => 'organisation', 'comment' => 'person | organisation (Owners::KINDS)'])
            ->addColumn('prefix', 'string', ['limit' => 30, 'null' => false, 'comment' => 'owner prefix (Owners::prefixFor); owner 1 = the install prefix'])
            ->addColumn('status', 'string', ['limit' => 10, 'null' => false, 'default' => 'live', 'comment' => 'live | paused | archived (Owners::STATUSES)'])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addColumn('ready_at', 'datetime', ['null' => true, 'comment' => 'set by cron once the owner\'s tables exist (Owners::provisionPending); NULL = being set up'])
            ->addIndex(['public_id'], ['name' => 'uq_owners_public_id', 'unique' => true])
            ->addIndex(['prefix'], ['name' => 'uq_owners_prefix', 'unique' => true])
            ->create();

        if ($this->isMigratingUp()) {
            $now = date('Y-m-d H:i:s');
            $this->table('owners')->insert(['public_id' => Uuid::v4(), 'name' => '', 'kind' => 'organisation', 'prefix' => (string) $this->getAdapter()->getOption('table_prefix'), 'status' => 'live', 'created_at' => $now, 'ready_at' => $now])->saveData();
        }
    }
}
