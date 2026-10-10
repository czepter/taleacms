<?php

declare(strict_types=1);

use Phinx\Db\Adapter\MysqlAdapter;
use Phinx\Migration\AbstractMigration;
use Talea\Core\MigrationSupport;

/** Baseline of the database schema. */
final class CreateBookingsTables extends AbstractMigration
{
    public function change(): void
    {
        $prefix = (string) $this->getAdapter()->getOption('table_prefix'); // foreign key names are unique per database

        $this->table('booking_services', ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', 'integer', ['signed' => false, 'identity' => true, 'null' => false])
            ->addColumn('public_id', ...MigrationSupport::publicId($this->getAdapter()))
            ->addColumn('name', 'string', ['limit' => 150, 'null' => false])
            ->addColumn('duration_min', 'smallinteger', ['signed' => false, 'null' => false, 'default' => 30])
            ->addColumn('buffer_min', 'smallinteger', ['signed' => false, 'null' => false, 'default' => 0, 'comment' => 'time kept free after the appointment (cleaning, notes)'])
            ->addColumn('price_text', 'string', ['limit' => 60, 'null' => false, 'default' => '', 'comment' => 'shown to the visitor as written; no payments'])
            ->addColumn('description', 'string', ['limit' => 500, 'null' => false, 'default' => ''])
            ->addColumn('active', 'boolean', ['null' => false, 'default' => 1])
            ->addColumn('requires_confirmation', 'boolean', ['null' => false, 'default' => 0, 'comment' => '3.3: a booking is pending until the provider accepts it'])
            ->addColumn('sort_order', 'integer', ['signed' => true, 'null' => false, 'default' => 0])
            ->addIndex(['public_id'], ['name' => 'uq_booking_services_public_id', 'unique' => true])
            ->create();

        $this->table('booking_staff', ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', 'integer', ['signed' => false, 'identity' => true, 'null' => false])
            ->addColumn('public_id', ...MigrationSupport::publicId($this->getAdapter()))
            ->addColumn('name', 'string', ['limit' => 150, 'null' => false])
            ->addColumn('email', 'string', ['limit' => 190, 'null' => false, 'default' => '', 'comment' => 'gets the notifications; empty = the site e-mail'])
            ->addColumn('active', 'boolean', ['null' => false, 'default' => 1])
            ->addColumn('user_id', 'integer', ['signed' => false, 'null' => true, 'comment' => 'tl_users.user_id when the person has an account'])
            ->addColumn('sort_order', 'integer', ['signed' => true, 'null' => false, 'default' => 0])
            ->addIndex(['public_id'], ['name' => 'uq_booking_staff_public_id', 'unique' => true])
            ->create();

        $this->table('booking_staff_services', ['id' => false, 'primary_key' => ['staff_id', 'service_id']])
            ->addColumn('staff_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('service_id', 'integer', ['signed' => false, 'null' => false])
            ->addIndex(['service_id'], ['name' => 'ix_booking_staff_services_service_id'])
            ->addForeignKey('staff_id', 'booking_staff', 'id', ['constraint' => $prefix . 'fk_booking_staff_services_staff_id', 'delete' => 'CASCADE'])
            ->addForeignKey('service_id', 'booking_services', 'id', ['constraint' => $prefix . 'fk_booking_staff_services_service_id', 'delete' => 'CASCADE'])
            ->create();

        $this->table('booking_hours', ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', 'integer', ['signed' => false, 'identity' => true, 'null' => false])
            ->addColumn('staff_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('weekday', 'tinyinteger', ['signed' => false, 'null' => false, 'comment' => '1 = Monday … 7 = Sunday'])
            ->addColumn('time_from', 'char', ['limit' => 5, 'null' => false, 'comment' => 'HH:MM'])
            ->addColumn('time_to', 'char', ['limit' => 5, 'null' => false])
            ->addIndex(['staff_id', 'weekday'], ['name' => 'ix_booking_hours_staff_id_weekday'])
            ->addForeignKey('staff_id', 'booking_staff', 'id', ['constraint' => $prefix . 'fk_booking_hours_staff_id', 'delete' => 'CASCADE'])
            ->create();

        $this->table('booking_off', ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', 'integer', ['signed' => false, 'identity' => true, 'null' => false])
            ->addColumn('staff_id', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('off_from', 'datetime', ['null' => false])
            ->addColumn('off_to', 'datetime', ['null' => false])
            ->addColumn('note', 'string', ['limit' => 150, 'null' => false, 'default' => ''])
            ->addIndex(['off_to'], ['name' => 'ix_booking_off_off_to'])
            ->addIndex(['staff_id'], ['name' => 'ix_booking_off_staff_id'])
            ->addForeignKey('staff_id', 'booking_staff', 'id', ['constraint' => $prefix . 'fk_booking_off_staff_id', 'delete' => 'CASCADE'])
            ->create();

        $this->table('bookings', ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', 'integer', ['signed' => false, 'identity' => true, 'null' => false])
            ->addColumn('public_id', ...MigrationSupport::publicId($this->getAdapter()))
            ->addColumn('service_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('staff_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('starts_at', 'datetime', ['null' => false, 'comment' => 'site time zone'])
            ->addColumn('ends_at', 'datetime', ['null' => false, 'comment' => 'start + the service duration (the buffer is not part of it)'])
            ->addColumn('name', 'string', ['limit' => 150, 'null' => false, 'default' => ''])
            ->addColumn('email', 'string', ['limit' => 190, 'null' => false, 'default' => ''])
            ->addColumn('phone', 'string', ['limit' => 40, 'null' => false, 'default' => ''])
            ->addColumn('note', 'string', ['limit' => 1000, 'null' => false, 'default' => ''])
            ->addColumn('status', 'string', ['limit' => 10, 'null' => false, 'default' => 'confirmed', 'comment' => 'pending | confirmed | declined | cancelled | no_show | done'])
            ->addColumn('token_hash', 'char', ['limit' => 64, 'null' => false, 'comment' => 'sha256 of the customer\'s token (the cancel link, the .ics link)'])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addColumn('reminded_at', 'datetime', ['null' => true])
            ->addColumn('hold_until', 'datetime', ['null' => true, 'comment' => 'pending: the time is held until then'])
            ->addColumn('hold_reminded_at', 'datetime', ['null' => true, 'comment' => 'pending: the provider was reminded that the hold ran out'])
            ->addColumn('cancelled_at', 'datetime', ['null' => true])
            ->addColumn('cancelled_by', 'string', ['limit' => 10, 'null' => false, 'default' => '', 'comment' => 'customer | admin | claude'])
            ->addColumn('source', 'string', ['limit' => 255, 'null' => false, 'default' => '', 'comment' => 'the page the booking was made on; \'admin\' when entered by hand'])
            ->addColumn('language', 'string', ['limit' => 2, 'null' => false, 'default' => '', 'comment' => 'the site language version the customer used (\'\' = default)'])
            ->addColumn('anonymised_at', 'datetime', ['null' => true])
            ->addIndex(['public_id'], ['name' => 'uq_bookings_public_id', 'unique' => true])
            ->addIndex(['token_hash'], ['name' => 'uq_bookings_token_hash', 'unique' => true])
            ->addIndex(['staff_id', 'starts_at'], ['name' => 'ix_bookings_staff_id_starts_at'])
            ->addIndex(['starts_at'], ['name' => 'ix_bookings_starts_at'])
            ->addIndex(['email'], ['name' => 'ix_bookings_email'])
            ->create();

        $this->table('booking_proposals', ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', 'integer', ['signed' => false, 'identity' => true, 'null' => false])
            ->addColumn('booking_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('starts_at', 'datetime', ['null' => false])
            ->addColumn('ends_at', 'datetime', ['null' => false])
            ->addIndex(['booking_id'], ['name' => 'ix_booking_proposals_booking_id'])
            ->addForeignKey('booking_id', 'bookings', 'id', ['constraint' => $prefix . 'fk_booking_proposals_booking_id', 'delete' => 'CASCADE'])
            ->create();

    }
}
