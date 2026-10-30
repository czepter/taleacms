<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;
use Talea\Core\MigrationSupport;
use Talea\Core\Uuid;

/**
 * Multisite (docs/specs/multisite.md): the owner's sites, each with its table prefix, and the host names they answer to. Owner tables
 * (Db::OWNER_TABLES): created once under the owner prefix. Every install gets the row of site 1 – its prefix is the owner prefix –
 * so a single-site install is "one owner, one site". The domains are filled when multisite is switched on.
 */
final class CreateSites extends AbstractMigration
{
    public function change(): void
    {
        $prefix = (string) $this->getAdapter()->getOption('table_prefix'); // foreign key names are unique per database

        $this->table('sites', ['id' => false, 'primary_key' => ['site_id']])
            ->addColumn('site_id', 'integer', ['signed' => false, 'identity' => true, 'null' => false])
            ->addColumn('public_id', ...MigrationSupport::publicId($this->getAdapter()))
            ->addColumn('name', 'string', ['limit' => 120, 'null' => false, 'default' => '', 'comment' => 'shown in the Sites area; empty = the site title'])
            ->addColumn('slug', 'string', ['limit' => 60, 'null' => false])
            ->addColumn('prefix', 'string', ['limit' => 30, 'null' => false, 'comment' => 'table prefix (Sites::prefixFor); site 1 = the owner prefix'])
            ->addColumn('status', 'string', ['limit' => 10, 'null' => false, 'default' => 'live', 'comment' => 'live | paused | archived | trashed (Sites::STATUSES)'])
            ->addColumn('redirect_to_site_id', 'integer', ['signed' => false, 'null' => true, 'comment' => 'archived: answer with a 301 to this site'])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addColumn('trashed_at', 'datetime', ['null' => true])
            ->addIndex(['public_id'], ['name' => 'uq_sites_public_id', 'unique' => true])
            ->addIndex(['slug'], ['name' => 'uq_sites_slug', 'unique' => true])
            ->addIndex(['prefix'], ['name' => 'uq_sites_prefix', 'unique' => true])
            ->create();

        $this->table('site_domains', ['id' => false, 'primary_key' => ['domain_id']])
            ->addColumn('domain_id', 'integer', ['signed' => false, 'identity' => true, 'null' => false])
            ->addColumn('site_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('host', 'string', ['limit' => 253, 'null' => false, 'comment' => 'lower-case, no port'])
            ->addColumn('is_primary', 'boolean', ['null' => false, 'default' => false])
            ->addColumn('redirect_to_primary', 'boolean', ['null' => false, 'default' => false])
            ->addIndex(['host'], ['name' => 'uq_site_domains_host', 'unique' => true])
            ->addIndex(['site_id'], ['name' => 'ix_site_domains_site_id'])
            ->addForeignKey('site_id', 'sites', 'site_id', ['constraint' => $prefix . 'fk_site_domains_site_id', 'delete' => 'CASCADE'])
            ->create();

        if ($this->isMigratingUp()) {
            $this->table('sites')->insert(['public_id' => Uuid::v4(), 'slug' => 'main', 'prefix' => $prefix, 'status' => 'live', 'created_at' => date('Y-m-d H:i:s')])->saveData();
        }
    }
}
