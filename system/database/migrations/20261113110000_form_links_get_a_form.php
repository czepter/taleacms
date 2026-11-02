<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/** A personal link opens one form (Core\FormLinks): the form it was made for. */
final class FormLinksGetAForm extends AbstractMigration
{
    public function change(): void
    {
        $this->table('form_links')
            ->addColumn('form_id', 'integer', ['signed' => false, 'null' => true, 'after' => 'enquiry_id'])
            ->addIndex(['form_id'], ['name' => 'ix_form_links_form_id'])
            ->update();
    }
}
