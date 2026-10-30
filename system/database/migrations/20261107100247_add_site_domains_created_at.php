<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Multisite, domains (docs/specs/multisite.md): when a host was added, so a host that is still not verified after 14 days is removed and
 * does not keep a name from the other owners. Owner table; the hosts that exist are verified and keep NULL.
 */
final class AddSiteDomainsCreatedAt extends AbstractMigration
{
    public function change(): void
    {
        $this->table('site_domains')
            ->addColumn('created_at', 'datetime', ['null' => true, 'comment' => 'when the host was added; NULL for the hosts from before this column'])
            ->update();
    }
}
