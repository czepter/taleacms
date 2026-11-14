<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * An imported page remembers the old address it came from and whether its layout still has to be rebuilt (Core\LayoutQueue): the "Layout not
 * rebuilt" tab of Pages, MCP list_layout_queue / layout_queue and the move status of the site audit.
 */
final class PagesRememberTheirImport extends AbstractMigration
{
    public function change(): void
    {
        $this->table('pages')
            ->addColumn('import_source', 'string', ['limit' => 500, 'null' => true, 'comment' => 'the old address an import brought the page from (Core\\WebImport, Core\\WpImport, Import\\Batch); NULL = made here'])
            ->addColumn('layout_pending', 'boolean', ['null' => false, 'default' => 0, 'comment' => 'imported, its layout not rebuilt yet: the Pages tab and list_layout_queue (Core\\LayoutQueue)'])
            ->update();
    }
}
