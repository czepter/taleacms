<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Booking hours per service: one person can keep different weekly hours for different services (speed dates on weekday
 * evenings, family shoots at weekends). service_id NULL = the person's general hours; rows with a service replace the general
 * ones for that service only. Existing rows stay general.
 */
final class AddBookingHoursService extends AbstractMigration
{
    public function change(): void
    {
        $prefix = (string) $this->getAdapter()->getOption('table_prefix'); // foreign key names are unique per database

        $this->table('booking_hours')
            ->addColumn('service_id', 'integer', ['signed' => false, 'null' => true, 'after' => 'staff_id', 'comment' => 'NULL = the general hours; else only for this service'])
            ->addIndex(['service_id'], ['name' => 'ix_booking_hours_service_id'])
            ->addForeignKey('service_id', 'booking_services', 'id', ['constraint' => $prefix . 'fk_booking_hours_service_id', 'delete' => 'CASCADE'])
            ->update();
    }
}
