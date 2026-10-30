<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Several owners: the e-mail address of the first administrator an operator named when creating the owner. The invitation is sent from
 * the Owners area once the owner has a host name (Core\Owners::invite). Install table, written once.
 */
final class AddOwnersInvitation extends AbstractMigration
{
    public function change(): void
    {
        $this->table('owners')
            ->addColumn('admin_email', 'string', ['limit' => 190, 'null' => false, 'default' => '', 'comment' => 'first administrator of the owner, to be invited'])
            ->update();
    }
}
