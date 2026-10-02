-- Exceptions to the opening hours (2.10, Core\Hours): holidays, a closed day, shorter hours. They change "open now",
-- the hours of the day and the structured data, and show a notice bar on the site a few days ahead until they end.
CREATE TABLE IF NOT EXISTS ka_hours_exceptions (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    date_from   DATE         NOT NULL,
    date_to     DATE         NOT NULL,
    closed      TINYINT(1)   NOT NULL DEFAULT 1,
    hours       VARCHAR(100) NOT NULL DEFAULT '',           -- when open: 9:00-12:00, more ranges with a comma
    note        VARCHAR(150) NOT NULL DEFAULT '',           -- e.g. Christmas, inventory
    notice_days TINYINT UNSIGNED NOT NULL DEFAULT 7,        -- the notice bar this many days ahead (0 = no bar)
    created_at  DATETIME     NOT NULL,
    PRIMARY KEY (id),
    KEY ix_hours_exceptions_to (date_to)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;
