<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Folders inside folders (docs/specs/media-library.md): media_folders.parent_id. NULL = top level. When a parent row goes
 * away the foreign key lifts its children to the top level; Core\MediaFolders::delete moves them one level up first.
 */
final class AddMediaFoldersParent extends AbstractMigration
{
    public function change(): void
    {
        $prefix = (string) $this->getAdapter()->getOption('table_prefix'); // foreign key names are unique per database

        $this->table('media_folders')
            ->addColumn('parent_id', 'integer', ['signed' => false, 'null' => true, 'after' => 'name', 'comment' => 'parent folder; NULL = top level'])
            ->addIndex(['parent_id'], ['name' => 'ix_media_folders_parent_id'])
            ->addForeignKey('parent_id', 'media_folders', 'folder_id', ['constraint' => $prefix . 'fk_media_folders_parent_id', 'delete' => 'SET_NULL'])
            ->update();
    }
}
