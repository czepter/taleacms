<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * The sha1 of the bytes that were uploaded (docs/specs/media-library.md, Upload): an image is re-encoded when it is saved, so the
 * stored file never matches the upload, but the same photo uploaded twice does. Admin uploads skip a file whose hash is already
 * here. Existing rows stay NULL (no backfill).
 */
final class AddMediaSourceSha1 extends AbstractMigration
{
    public function change(): void
    {
        $this->table('media')
            ->addColumn('source_sha1', 'string', ['limit' => 40, 'null' => true, 'after' => 'created_at', 'comment' => 'sha1 of the uploaded bytes; NULL = unknown'])
            ->addIndex(['source_sha1'], ['name' => 'ix_media_source_sha1'])
            ->update();
    }
}
