<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * The fleet console keeps, per site, the end of its TLS certificate (seen by the uptime check), the end of its domain
 * registration (typed by an administrator), the states a person accepted on the Today screen and the expiry reminders
 * already sent (Fleet\Console, Fleet\Today, Fleet\Expiry).
 */
final class FleetSitesExpiryAndAcceptances extends AbstractMigration
{
    public function up(): void
    {
        $this->table('fleet_sites')
            ->addColumn('cert_expires', 'datetime', ['null' => true, 'comment' => 'end of the TLS certificate the uptime check saw (Fleet\\Http::probe); kept when a check fails'])
            ->addColumn('domain_expires', 'date', ['null' => true, 'comment' => 'end of the domain registration, typed by an administrator'])
            ->addColumn('accepted', 'text', ['null' => true, 'comment' => 'JSON: Today item key => {state, note, until, by, at} – states accepted on the console (Fleet\\Today)'])
            ->addColumn('reminders', 'text', ['null' => true, 'comment' => 'JSON list of expiry reminders sent: kind|Y-m-d|days (Fleet\\Expiry)'])
            ->update();
    }

    public function down(): void
    {
        $this->table('fleet_sites')->removeColumn('cert_expires')->removeColumn('domain_expires')->removeColumn('accepted')->removeColumn('reminders')->update();
    }
}
