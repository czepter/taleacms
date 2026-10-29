<?php

declare(strict_types=1);

use Phinx\Db\Adapter\MysqlAdapter;
use Phinx\Migration\AbstractMigration;
use Talea\Core\MigrationSupport;

/**
 * Forms as records (docs/specs/form-builder.md, Builder\FormRecords): a form's build holds its one Form element, pages place it
 * with a Form placement element. Enquiries name the form they came from, wherever it was placed.
 */
final class CreateForms extends AbstractMigration
{
    public function change(): void
    {
        $this->table('forms', ['id' => false, 'primary_key' => ['form_id']])
            ->addColumn('form_id', 'integer', ['signed' => false, 'identity' => true, 'null' => false])
            ->addColumn('public_id', ...MigrationSupport::publicId($this->getAdapter()))
            ->addColumn('name', 'string', ['limit' => 120, 'null' => false])
            ->addColumn('build', 'text', ['limit' => MysqlAdapter::TEXT_MEDIUM, 'null' => true, 'comment' => 'published: one Form element'])
            ->addColumn('build_draft', 'text', ['limit' => MysqlAdapter::TEXT_MEDIUM, 'null' => true])
            ->addColumn('origin', 'string', ['limit' => 80, 'null' => false, 'default' => '', 'comment' => 'the build and element it was adopted from ("page:12|abc12"); \'\' = made as a form'])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addColumn('updated_at', 'datetime', ['null' => true])
            ->addIndex(['public_id'], ['name' => 'uq_forms_public_id', 'unique' => true])
            ->addIndex(['origin'], ['name' => 'ix_forms_origin'])
            ->create();

        $this->table('enquiries')
            ->addColumn('form_id', 'integer', ['signed' => false, 'null' => true, 'comment' => 'the form record it was sent with (Builder\\FormRecords); NULL = a form written inside a build'])
            ->addIndex(['form_id'], ['name' => 'ix_enquiries_form_id'])
            ->update();
    }
}
