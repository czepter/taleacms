<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/** An item that arrived through a form (Core\CollectionSubmission) remembers which one, so a person reviewing it can tell it from their own. */
final class CollectionItemsRememberTheirForm extends AbstractMigration
{
    public function change(): void
    {
        $this->table('collection_items')
            ->addColumn('submitted_via', 'integer', ['signed' => false, 'null' => true, 'comment' => 'tl_forms.form_id of the form a visitor sent it through; NULL = written by a person'])
            ->update();
    }
}
