<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * "Stay signed in" on the admin sign-in: a long-lived cookie (tl_remember) whose sha256 is kept here. When the PHP session has
 * ended, Core\Auth::user() trades the cookie for a new session and a new token. The fingerprint of the password ends every token
 * of the account once the password changes.
 */
final class CreateUserRememberTokens extends AbstractMigration
{
    public function change(): void
    {
        $prefix = (string) $this->getAdapter()->getOption('table_prefix');

        $this->table('user_remember_tokens', ['id' => false, 'primary_key' => ['remember_id']])
            ->addColumn('remember_id', 'integer', ['signed' => false, 'identity' => true, 'null' => false])
            ->addColumn('user_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('token_hash', 'char', ['limit' => 64, 'null' => false, 'comment' => 'sha256 of the cookie'])
            ->addColumn('fingerprint', 'char', ['limit' => 24, 'null' => false, 'comment' => 'Auth::passwordHash() at sign-in'])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addColumn('expires_at', 'datetime', ['null' => false])
            ->addIndex(['token_hash'], ['name' => 'uq_user_remember_tokens_token_hash', 'unique' => true])
            ->addIndex(['user_id'], ['name' => 'ix_user_remember_tokens_user_id'])
            ->addForeignKey('user_id', 'users', 'user_id', ['constraint' => $prefix . 'fk_user_remember_tokens_user_id', 'delete' => 'CASCADE'])
            ->create();
    }
}
