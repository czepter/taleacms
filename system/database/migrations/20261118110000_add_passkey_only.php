<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Passkey-only sign-in (Core\Auth::passkeyOnly): an account can choose to sign in only with a passkey (or password plus passkey / backup
 * code), the site can ask it of every account that has one. discoverable says whether a key is kept on the device so that it signs in
 * alone (WebAuthn credProps): older keys are NULL (unknown) and offered "Register again" in My account.
 */
final class AddPasskeyOnly extends AbstractMigration
{
    public function up(): void
    {
        $this->table('users')
            ->addColumn('passkey_only', 'boolean', ['null' => false, 'default' => 0, 'comment' => 'the account signs in only with a passkey (or password + passkey / backup code) once it has a passkey for the site'])
            ->update();
        $this->table('user_passkeys')
            ->addColumn('discoverable', 'boolean', ['null' => true, 'comment' => 'kept on the device (signs in alone): 1 known, 0 not, NULL unknown (registered before)'])
            ->update();
    }

    public function down(): void
    {
        $this->table('user_passkeys')->removeColumn('discoverable')->update();
        $this->table('users')->removeColumn('passkey_only')->update();
    }
}
