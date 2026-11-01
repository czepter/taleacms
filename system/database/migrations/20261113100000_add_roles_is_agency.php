<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Agency role (docs/specs/settings-ux.md 5.5): a user with such a role switches the "Created and maintained by" card on
 * by itself, on one website and on several.
 */
final class AddRolesIsAgency extends AbstractMigration
{
    public function change(): void
    {
        $this->table('role')
            ->addColumn('is_agency', 'boolean', ['default' => false, 'null' => false, 'after' => 'modules'])
            ->update();
    }
}
