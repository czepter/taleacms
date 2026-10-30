<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Several owners, operators (docs/specs/multisite.md, "Several owners"): a user row of a client owner that stands for an operator
 * (an administrator of owner 1) who was sent over by the Owners area. `operator_user_id` is the user number in owner 1's {users}; a
 * row with it cannot sign in with a password, cannot be edited or deleted by the client and goes when the operator does. Owner table.
 */
final class AddUsersOperator extends AbstractMigration
{
    public function change(): void
    {
        $this->table('users')
            ->addColumn('operator_user_id', 'integer', ['signed' => false, 'null' => true, 'comment' => 'owner 1\'s user number of the operator this row stands for (Core\Owners::operatorRow)'])
            ->update();
    }
}
