<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Drop-off counters of multi-step forms (Front\FormSteps): how many visitors reached each step on a day. A count per form, step
 * and day; no visitor id, no answers.
 */
final class CreateFormSteps extends AbstractMigration
{
    public function change(): void
    {
        $this->table('form_steps', ['id' => false, 'primary_key' => ['source', 'element', 'step', 'day']])
            ->addColumn('source', 'string', ['limit' => 40, 'null' => false, 'comment' => 'where the form sits, as in enquiries.source'])
            ->addColumn('element', 'string', ['limit' => 16, 'null' => false, 'comment' => 'id of the form element'])
            ->addColumn('step', 'smallinteger', ['signed' => false, 'null' => false, 'comment' => '1 = the first answer, n = the nth step reached'])
            ->addColumn('day', 'date', ['null' => false])
            ->addColumn('reached', 'integer', ['signed' => false, 'null' => false, 'default' => 0])
            ->create();
    }
}
