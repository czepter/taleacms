<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * New site from a site template (Builder\SiteTemplates): the template chosen in Sites → New site waits here until an administrator
 * first opens the set-up site, which then gets the template's look and pages as drafts (Admin\Kernel). Owner table, written once.
 */
final class AddSitesTemplate extends AbstractMigration
{
    public function change(): void
    {
        $this->table('sites')
            ->addColumn('template', 'string', ['limit' => 40, 'null' => true, 'comment' => 'site template to apply as drafts when an administrator first opens the site; NULL = none'])
            ->update();
    }
}
