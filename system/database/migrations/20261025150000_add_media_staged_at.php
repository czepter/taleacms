<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Files waiting for approval (#150): what a Claude connection limited to drafts uploads waits outside the web root
 * (storage/staging/, Core\MediaStaging) until it is published with the content that uses it or approved in Media; after 30
 * days it is deleted. NULL = public in media/ (every existing file).
 */
final class AddMediaStagedAt extends AbstractMigration
{
    public function change(): void
    {
        $this->table('media')
            ->addColumn('staged_at', 'datetime', ['null' => true, 'after' => 'created_at', 'comment' => 'waiting for approval since; NULL = public'])
            ->addIndex(['staged_at'], ['name' => 'ix_media_staged_at'])
            ->update();
    }
}
