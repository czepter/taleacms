<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * The admin sidebar: a collection can stand in it (admin_menu, on for every existing and new collection), and the count of
 * bookings waiting for a confirmation next to Bookings is read through an index on every admin page.
 */
final class AddAdminMenuCollections extends AbstractMigration
{
    public function change(): void
    {
        $this->table('collections')
            ->addColumn('admin_menu', 'boolean', ['null' => false, 'default' => 1, 'after' => 'detail', 'comment' => 'listed in the admin sidebar under Collections (at most 8, by name)'])
            ->update();

        $this->table('bookings')
            ->addIndex(['status', 'ends_at'], ['name' => 'ix_bookings_status_ends_at'])
            ->update();
    }
}
