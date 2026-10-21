<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Sites update by pulling a new container image; the in-app updater and with it the fleet console's staged rollout (rings, the
 * site letting the console decide, the version allowed by hand) are gone. The console keeps showing which version each site runs.
 */
final class DropFleetUpdateRollout extends AbstractMigration
{
    public function up(): void
    {
        $this->table('fleet_sites')->removeColumn('ring')->removeColumn('manage_updates')->removeColumn('update_allowed')->update();
    }

    public function down(): void
    {
        $this->table('fleet_sites')
            ->addColumn('ring', 'string', ['limit' => 10, 'null' => false, 'default' => 'normal', 'after' => 'relay_expires'])
            ->addColumn('manage_updates', 'boolean', ['null' => false, 'default' => 0, 'after' => 'ring'])
            ->addColumn('update_allowed', 'string', ['limit' => 30, 'null' => false, 'default' => '', 'after' => 'manage_updates'])
            ->update();
    }
}
