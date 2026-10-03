-- Online booking of appointments (3.0, Core\Booking): services, the people who provide them with their weekly hours and
-- days off, and the bookings themselves. A booking holds personal data: it follows the enquiry retention, Core\PersonalData
-- finds and erases it, and the site export carries the set-up only – never the bookings.
CREATE TABLE ka_booking_services (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name         VARCHAR(150) NOT NULL,
    duration_min SMALLINT UNSIGNED NOT NULL DEFAULT 30,
    buffer_min   SMALLINT UNSIGNED NOT NULL DEFAULT 0,          -- time kept free after the appointment (cleaning, notes)
    price_text   VARCHAR(60) NOT NULL DEFAULT '',               -- shown to the visitor as written; no payments
    description  VARCHAR(500) NOT NULL DEFAULT '',
    active       TINYINT(1) NOT NULL DEFAULT 1,
    sort_order   INT NOT NULL DEFAULT 0,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

CREATE TABLE ka_booking_staff (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name       VARCHAR(150) NOT NULL,
    email      VARCHAR(190) NOT NULL DEFAULT '',                -- gets the notifications; empty = the site e-mail
    active     TINYINT(1) NOT NULL DEFAULT 1,
    user_id    INT UNSIGNED NULL,                               -- ka_uzivatele.idu when the person has an account
    sort_order INT NOT NULL DEFAULT 0,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

CREATE TABLE ka_booking_staff_services (
    staff_id   INT UNSIGNED NOT NULL,
    service_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (staff_id, service_id),
    CONSTRAINT fk_booking_staff_services_staff FOREIGN KEY (staff_id) REFERENCES ka_booking_staff (id) ON DELETE CASCADE,
    CONSTRAINT fk_booking_staff_services_service FOREIGN KEY (service_id) REFERENCES ka_booking_services (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

-- Weekly availability of a person: several ranges a day; a person without any row works the site's opening hours.
CREATE TABLE ka_booking_hours (
    id        INT UNSIGNED NOT NULL AUTO_INCREMENT,
    staff_id  INT UNSIGNED NOT NULL,
    weekday   TINYINT UNSIGNED NOT NULL,                        -- 1 = Monday … 7 = Sunday
    time_from CHAR(5) NOT NULL,                                 -- HH:MM
    time_to   CHAR(5) NOT NULL,
    PRIMARY KEY (id),
    KEY ix_booking_hours_staff (staff_id, weekday),
    CONSTRAINT fk_booking_hours_staff FOREIGN KEY (staff_id) REFERENCES ka_booking_staff (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

-- Days off and other exceptions: holidays, sick days; staff_id NULL = everyone.
CREATE TABLE ka_booking_off (
    id       INT UNSIGNED NOT NULL AUTO_INCREMENT,
    staff_id INT UNSIGNED NULL,
    off_from DATETIME NOT NULL,
    off_to   DATETIME NOT NULL,
    note     VARCHAR(150) NOT NULL DEFAULT '',
    PRIMARY KEY (id),
    KEY ix_booking_off_to (off_to),
    CONSTRAINT fk_booking_off_staff FOREIGN KEY (staff_id) REFERENCES ka_booking_staff (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

-- The bookings. service_id and staff_id have no foreign key on purpose: a past booking keeps its history after the
-- service or the person is removed (the admin refuses to remove anyone with upcoming bookings).
CREATE TABLE ka_bookings (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    service_id    INT UNSIGNED NOT NULL,
    staff_id      INT UNSIGNED NOT NULL,
    starts_at     DATETIME NOT NULL,                            -- site time zone
    ends_at       DATETIME NOT NULL,                            -- start + the service duration (the buffer is not part of it)
    name          VARCHAR(150) NOT NULL DEFAULT '',
    email         VARCHAR(190) NOT NULL DEFAULT '',
    phone         VARCHAR(40) NOT NULL DEFAULT '',
    note          VARCHAR(1000) NOT NULL DEFAULT '',
    status        VARCHAR(10) NOT NULL DEFAULT 'confirmed',     -- confirmed | cancelled | no_show | done
    token_hash    CHAR(64) NOT NULL,                            -- sha256 of the customer's token (the cancel link, the .ics link)
    created_at    DATETIME NOT NULL,
    reminded_at   DATETIME NULL,
    cancelled_at  DATETIME NULL,
    cancelled_by  VARCHAR(10) NOT NULL DEFAULT '',              -- customer | admin | claude
    source        VARCHAR(255) NOT NULL DEFAULT '',             -- the page the booking was made on; 'admin' when entered by hand
    language      VARCHAR(2) NOT NULL DEFAULT '',               -- the site language version the customer used ('' = default)
    anonymised_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY ux_bookings_token (token_hash),
    KEY ix_bookings_staff_start (staff_id, starts_at),
    KEY ix_bookings_start (starts_at),
    KEY ix_bookings_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;
