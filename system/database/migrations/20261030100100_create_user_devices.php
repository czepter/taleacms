<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Known browsers of an account: a random cookie (tl_device) whose sha256 is kept here. While the account is locked after wrong
 * passwords, a browser with a row here (and fewer than 5 recorded failures) may still sign in. Rows end with a new password, when
 * two-factor sign-in or a passkey changes, and after 365 days. Never addressed from outside, so no public id.
 */
final class CreateUserDevices extends AbstractMigration
{
    public function change(): void
    {
        $prefix = (string) $this->getAdapter()->getOption('table_prefix');

        $this->table('user_devices', ['id' => false, 'primary_key' => ['device_id']])
            ->addColumn('device_id', 'integer', ['signed' => false, 'identity' => true, 'null' => false])
            ->addColumn('user_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('token_hash', 'char', ['limit' => 64, 'null' => false, 'comment' => 'sha256 of the cookie'])
            ->addColumn('failures', 'integer', ['default' => 0, 'null' => false, 'comment' => 'wrong passwords and codes made with the cookie'])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addIndex(['token_hash'], ['name' => 'uq_user_devices_token_hash', 'unique' => true])
            ->addIndex(['user_id'], ['name' => 'ix_user_devices_user_id'])
            ->addForeignKey('user_id', 'users', 'user_id', ['constraint' => $prefix . 'fk_user_devices_user_id', 'delete' => 'CASCADE'])
            ->create();
    }
}
