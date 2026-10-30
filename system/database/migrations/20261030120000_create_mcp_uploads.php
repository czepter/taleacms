<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Upload links of Claude connections (Mcp\UploadLinks, MCP request_upload): the sha256 of a random token that takes one file by
 * PUT within 15 minutes, with the name, description and folder it gets. A link belongs to the connection that asked for it and
 * goes with it. Never addressed by row from outside, so no public id.
 */
final class CreateMcpUploads extends AbstractMigration
{
    public function change(): void
    {
        $prefix = (string) $this->getAdapter()->getOption('table_prefix');

        $this->table('mcp_uploads', ['id' => false, 'primary_key' => ['upload_id']])
            ->addColumn('upload_id', 'integer', ['signed' => false, 'identity' => true, 'null' => false])
            ->addColumn('token_hash', 'char', ['limit' => 64, 'null' => false, 'comment' => 'sha256 of the token in the link'])
            ->addColumn('api_token_id', 'integer', ['signed' => false, 'null' => false, 'comment' => 'the connection that asked for it'])
            ->addColumn('filename', 'string', ['limit' => 255, 'null' => false])
            ->addColumn('alt', 'string', ['limit' => 150, 'default' => '', 'null' => false])
            ->addColumn('folder_id', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('expires_at', 'datetime', ['null' => false])
            ->addColumn('used_at', 'datetime', ['null' => true])
            ->addIndex(['token_hash'], ['name' => 'uq_mcp_uploads_token_hash', 'unique' => true])
            ->addIndex(['api_token_id'], ['name' => 'ix_mcp_uploads_api_token_id'])
            ->addIndex(['folder_id'], ['name' => 'ix_mcp_uploads_folder_id'])
            ->addForeignKey('api_token_id', 'api_tokens', 'token_id', ['constraint' => $prefix . 'fk_mcp_uploads_api_token_id', 'delete' => 'CASCADE'])
            ->addForeignKey('folder_id', 'media_folders', 'folder_id', ['constraint' => $prefix . 'fk_mcp_uploads_folder_id', 'delete' => 'SET_NULL'])
            ->create();
    }
}
