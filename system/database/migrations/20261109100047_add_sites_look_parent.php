<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Look families (docs/specs/multisite.md, "Look families"): a site may take its look from one parent site of the same owner. The parent's
 * published design system, classes, header and footer are copied into it whenever the parent publishes. Owner table, written once.
 */
final class AddSitesLookParent extends AbstractMigration
{
    public function change(): void
    {
        $this->table('sites')
            ->addColumn('look_parent_id', 'integer', ['signed' => false, 'null' => true, 'comment' => 'the site whose published look is copied into this one; NULL = not in a look family'])
            ->update();
    }
}
