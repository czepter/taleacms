-- Bookings that need the provider's confirmation (3.3): a service may require it, the booking is then pending and holds
-- the time until hold_until; the provider accepts it, declines it or proposes other times (ka_booking_proposals).
ALTER TABLE ka_booking_services ADD COLUMN requires_confirmation TINYINT(1) NOT NULL DEFAULT 0 AFTER active;
ALTER TABLE ka_bookings ADD COLUMN hold_until DATETIME NULL AFTER reminded_at;
ALTER TABLE ka_bookings ADD COLUMN hold_reminded_at DATETIME NULL AFTER hold_until;

CREATE TABLE ka_booking_proposals (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    booking_id INT UNSIGNED NOT NULL,
    starts_at  DATETIME NOT NULL,
    ends_at    DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY ix_booking_proposals_booking (booking_id),
    CONSTRAINT fk_booking_proposals_booking FOREIGN KEY (booking_id) REFERENCES ka_bookings (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;
