-- Booking hours per service: one person can keep different weekly hours for different services (speed dates on
-- weekday evenings, family shoots at weekends). service_id NULL = the person's general hours; hours with a service_id
-- replace the general ones for that service only. Existing rows stay general.
ALTER TABLE ka_booking_hours ADD COLUMN service_id INT UNSIGNED NULL AFTER staff_id;
ALTER TABLE ka_booking_hours ADD KEY ix_booking_hours_service (service_id);
ALTER TABLE ka_booking_hours ADD CONSTRAINT fk_booking_hours_service FOREIGN KEY (service_id) REFERENCES ka_booking_services (id) ON DELETE CASCADE;
