<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Multisite, roles per site (docs/specs/multisite.md): which sites of the owner a user who is not an administrator may work on.
 * No row = every site. Owner table (Db::OWNER_TABLES): written once, under the owner prefix.
 */
final class CreateUserSites extends AbstractMigration
{
    public function change(): void
    {
        $prefix = (string) $this->getAdapter()->getOption('table_prefix');

        $this->table('user_sites', ['id' => false, 'primary_key' => ['user_id', 'site_id']])
            ->addColumn('user_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('site_id', 'integer', ['signed' => false, 'null' => false])
            ->addIndex(['site_id'], ['name' => 'ix_user_sites_site_id'])
            ->addForeignKey('user_id', 'users', 'user_id', ['constraint' => $prefix . 'fk_user_sites_user_id', 'delete' => 'CASCADE'])
            ->addForeignKey('site_id', 'sites', 'site_id', ['constraint' => $prefix . 'fk_user_sites_site_id', 'delete' => 'CASCADE'])
            ->create();
    }
}
