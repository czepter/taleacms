<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Multisite, the Sites area (docs/specs/multisite.md): a new site is a row first and gets its tables from cron – ready_at stays NULL
 * until Sites::provision() has run (source_site_id: the site its account settings, and with `clone` its content, come from).
 * handoff_tokens: the one-time links that sign an administrator in on another site of the owner (60 seconds, sha256 only).
 * Owner tables (Db::OWNER_TABLES): written once, under the owner prefix.
 */
final class AddSiteProvisioning extends AbstractMigration
{
    public function change(): void
    {
        $prefix = (string) $this->getAdapter()->getOption('table_prefix');

        $this->table('sites')
            ->addColumn('source_site_id', 'integer', ['signed' => false, 'null' => true, 'comment' => 'the site a new site was made from (account settings, content with clone)'])
            ->addColumn('clone', 'boolean', ['null' => false, 'default' => false, 'comment' => 'copy the content of source_site_id when the site is set up'])
            ->addColumn('ready_at', 'datetime', ['null' => true, 'comment' => 'tables and settings are in place; NULL = cron has not set the site up yet'])
            ->update();

        $this->table('handoff_tokens', ['id' => false, 'primary_key' => ['handoff_id']])
            ->addColumn('handoff_id', 'integer', ['signed' => false, 'identity' => true, 'null' => false])
            ->addColumn('token_hash', 'char', ['limit' => 64, 'null' => false, 'comment' => 'sha256 of the token in the link'])
            ->addColumn('user_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('site_id', 'integer', ['signed' => false, 'null' => false, 'comment' => 'the only site the token is valid on'])
            ->addColumn('expires_at', 'datetime', ['null' => false])
            ->addColumn('used_at', 'datetime', ['null' => true])
            ->addIndex(['token_hash'], ['name' => 'uq_handoff_tokens_token_hash', 'unique' => true])
            ->addIndex(['user_id'], ['name' => 'ix_handoff_tokens_user_id'])
            ->addIndex(['site_id'], ['name' => 'ix_handoff_tokens_site_id'])
            ->addForeignKey('user_id', 'users', 'user_id', ['constraint' => $prefix . 'fk_handoff_tokens_user_id', 'delete' => 'CASCADE'])
            ->addForeignKey('site_id', 'sites', 'site_id', ['constraint' => $prefix . 'fk_handoff_tokens_site_id', 'delete' => 'CASCADE'])
            ->create();

        // the sites that exist now have their tables (the migrator made them): ready. A further site's run touches no owner table.
        if ($this->isMigratingUp() && $prefix === (string) $this->getAdapter()->getOption('owner_prefix')) {
            $this->getUpdateBuilder()->update($prefix . 'sites')->set('ready_at', date('Y-m-d H:i:s'))->execute();
        }
    }
}
