<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;
use Talea\Core\MigrationSupport;

/**
 * The seed of the authenticator app is stored sealed (Core\Vault, prefix "sealed:") while TALEA_SECRET_KEY is set, and a sealed seed is
 * longer than the 32 characters of a plain one. down() fails while a sealed seed exists (it would not fit).
 */
final class WidenUsersTotpSecret extends AbstractMigration
{
    public function up(): void
    {
        $this->resize(255);
    }

    public function down(): void
    {
        $this->resize(64);
    }

    private function resize(int $limit): void
    {
        // users is an owner table: a further site's run leaves it to the owner's own run (PrefixAdapter skips the DSL write; the guard names the rule)
        if (MigrationSupport::ownerTableInSiteRun($this->getAdapter(), 'users')) {
            return;
        }
        $this->table('users')->changeColumn('totp_secret', 'string', ['limit' => $limit, 'null' => false, 'default' => '', 'comment' => 'two-factor sign-in (TOTP), sealed (Core\\Vault, prefix sealed:) while TALEA_SECRET_KEY is set; empty = off'])->update();
    }
}
