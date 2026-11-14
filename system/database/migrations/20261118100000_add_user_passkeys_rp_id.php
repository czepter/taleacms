<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;
use Talea\Core\MigrationSupport;
use Talea\Core\Passkey;

/**
 * A passkey belongs to the host it was registered for (Passkey::rpId): another host cannot use it. user_passkeys is an owner table,
 * every site of the owner has its own host, so each key records which one. The column is written on registration and rewritten by
 * every successful use (a successful assertion proves the host, so a wrong or empty value heals itself). Existing keys get the host
 * of the first site's address – exactly right for a single site.
 */
final class AddUserPasskeysRpId extends AbstractMigration
{
    public function up(): void
    {
        $this->table('user_passkeys')
            ->addColumn('rp_id', 'string', ['limit' => 253, 'null' => false, 'default' => '', 'after' => 'alg', 'comment' => 'host the passkey was registered for (Passkey::rpId); rewritten by every successful use'])
            ->update();
        if (MigrationSupport::ownerTableInSiteRun($this->getAdapter(), 'user_passkeys') || !$this->hasTable('settings')) {
            return;
        }
        $pdo = $this->getAdapter()->getConnection();
        $prefix = (string) $this->getAdapter()->getOption('table_prefix');
        $url = $pdo->query("SELECT value FROM {$prefix}settings WHERE name = 'site_url'")->fetchColumn();
        $host = Passkey::rpId(is_string($url) ? $url : '');
        if ($host !== '') {
            $pdo->prepare("UPDATE {$prefix}user_passkeys SET rp_id = ? WHERE rp_id = ''")->execute([$host]);
        }
    }

    public function down(): void
    {
        $this->table('user_passkeys')->removeColumn('rp_id')->update();
    }
}
