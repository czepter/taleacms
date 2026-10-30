<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Several owners, cross-owner clone: the owner prefix the source site of a copy belongs to ('' = this owner). Cron reads it when it
 * sets the new site up (Core\OwnerClone). Owner table.
 */
final class AddSitesSourceOwner extends AbstractMigration
{
    public function change(): void
    {
        $this->table('sites')
            ->addColumn('source_owner', 'string', ['limit' => 30, 'null' => false, 'default' => '', 'comment' => 'owner prefix of the site a clone was copied from; empty = the same owner'])
            ->update();
    }
}
