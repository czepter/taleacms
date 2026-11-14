<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/** The daily trash job (Core\Scheduler 'trash') deletes collection items by deleted_at; news, pages and enquiries have this index already. */
final class IndexCollectionItemsTrash extends AbstractMigration
{
    public function change(): void
    {
        $this->table('collection_items')->addIndex(['deleted_at'], ['name' => 'ix_collection_items_deleted_at'])->update();
    }
}
