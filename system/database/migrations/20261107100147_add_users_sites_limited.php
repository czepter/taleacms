<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Multisite, roles per site (docs/specs/multisite.md): an explicit flag instead of "no rows in user_sites = every site". A limited person
 * works only on the sites listed in user_sites; limited with no rows works on none. People who have rows today are limited. Owner table.
 */
final class AddUsersSitesLimited extends AbstractMigration
{
    public function change(): void
    {
        $prefix = (string) $this->getAdapter()->getOption('table_prefix');

        $this->table('users')
            ->addColumn('sites_limited', 'boolean', ['null' => false, 'default' => false, 'comment' => 'works only on the sites in user_sites (none listed = no site)'])
            ->update();

        if ($this->isMigratingUp() && $prefix === (string) $this->getAdapter()->getOption('owner_prefix')) {
            $ids = array_values(array_unique(array_map('intval', array_column($this->getSelectBuilder()->select(['user_id'])->from($prefix . 'user_sites')->execute()->fetchAll('assoc') ?: [], 'user_id'))));
            if ($ids !== []) {
                $this->getUpdateBuilder()->update($prefix . 'users')->set('sites_limited', true)->whereInList('user_id', $ids)->execute();
            }
        }
    }
}
