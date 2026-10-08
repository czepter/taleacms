-- Booking hours per service: one person can keep different weekly hours for different services (speed dates on
-- weekday evenings, family shoots at weekends). service_id NULL = the person's general hours; hours with a service_id
-- replace the general ones for that service only. Existing rows stay general.
-- One statement on purpose: run again it fails as a whole on the existing column (1060, tolerated). Separate statements
-- would fail on MariaDB at the duplicate foreign key with 1005, which is not an "already applied" error.
ALTER TABLE ka_booking_hours
    ADD COLUMN service_id INT UNSIGNED NULL AFTER staff_id,
    ADD KEY ix_booking_hours_service (service_id),
    ADD CONSTRAINT fk_booking_hours_service FOREIGN KEY (service_id) REFERENCES ka_booking_services (id) ON DELETE CASCADE;
