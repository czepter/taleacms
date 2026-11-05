<?php

declare(strict_types=1);

use Phinx\Db\Adapter\MysqlAdapter;
use Phinx\Migration\AbstractMigration;
use Talea\Core\MigrationSupport;

/**
 * Your own page templates (Builder\PageTemplates): a page saved as a template ("Save as template" in Pages, MCP save_page_template)
 * starts new pages from New page. The build is a copy made when it was saved; the page itself is not linked. Site table.
 */
final class CreatePageTemplates extends AbstractMigration
{
    public function change(): void
    {
        $this->table('page_templates', ['id' => false, 'primary_key' => ['page_template_id']])
            ->addColumn('page_template_id', 'integer', ['signed' => false, 'identity' => true, 'null' => false])
            ->addColumn('public_id', ...MigrationSupport::publicId($this->getAdapter()))
            ->addColumn('name', 'string', ['limit' => 100, 'null' => false])
            ->addColumn('build', 'text', ['limit' => MysqlAdapter::TEXT_MEDIUM, 'null' => false, 'comment' => 'JSON of the build, checked by Build::sanitize when it was saved'])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addColumn('updated_at', 'datetime', ['null' => true])
            ->addIndex(['public_id'], ['name' => 'uq_page_templates_public_id', 'unique' => true])
            ->create();
    }
}
