<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Transfer of a site between owners (docs/specs/multisite.md, "Transfer between owners"). Owner table, written once.
 * On the source site `transfer_to` names the new site (its table prefix, unique in the install) while the move runs and `transfer_error` keeps
 * why the last try failed; on the new site `transfer_by` (the operator's name) marks it as a transfer, which cron sets up in transfer mode.
 */
final class AddSitesTransfer extends AbstractMigration
{
    public function change(): void
    {
        $this->table('sites')
            ->addColumn('transfer_to', 'string', ['limit' => 30, 'null' => true, 'comment' => 'table prefix of the site in the other owner this one is being moved to; NULL = no transfer under way'])
            ->addColumn('transfer_error', 'string', ['limit' => 500, 'null' => false, 'default' => '', 'comment' => 'why the last transfer of this site failed; empty = none'])
            ->addColumn('transfer_by', 'string', ['limit' => 100, 'null' => true, 'comment' => 'on the new site of a transfer: the operator who started it; NULL = not a transfer'])
            ->update();
    }
}
