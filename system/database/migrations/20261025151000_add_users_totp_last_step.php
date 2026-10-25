<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * The 30-second step of the last code from the authenticator app an account signed in with (Core\Auth::claimCodeStep): a code
 * of that step or an earlier one is refused, so a code seen once cannot sign in again while it is still within the drift.
 */
final class AddUsersTotpLastStep extends AbstractMigration
{
    public function change(): void
    {
        $this->table('users')
            ->addColumn('totp_last_step', 'biginteger', ['null' => true, 'after' => 'totp_backup_codes', 'comment' => 'Totp::step() of the last code used; NULL = none yet'])
            ->update();
    }
}
