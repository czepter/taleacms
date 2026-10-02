-- What happened on the site (2.8, Core\Events): one table of events that feeds alert e-mails, reports and Claude
-- (list_events). Never personal data – an enquiry event names the form and the page, not the sender.
CREATE TABLE IF NOT EXISTS ka_events (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    created_at DATETIME     NOT NULL,
    type       VARCHAR(40)  NOT NULL,                       -- e.g. backup.failed, enquiry.received, update.applied
    severity   VARCHAR(10)  NOT NULL DEFAULT 'info',          -- info | warning | error
    message    VARCHAR(255) NOT NULL DEFAULT '',
    data       TEXT         NULL,                           -- JSON with ids and counts, never personal data
    PRIMARY KEY (id),
    KEY ix_events_created (created_at),
    KEY ix_events_type (type, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

-- Background jobs (2.8, Core\Scheduler): when each job last ran, whether it worked, and how many times in a row it failed.
CREATE TABLE IF NOT EXISTS ka_jobs (
    name        VARCHAR(40)  NOT NULL,
    last_run    DATETIME     NULL,
    last_ok     DATETIME     NULL,
    last_error  VARCHAR(255) NOT NULL DEFAULT '',
    failures    INT UNSIGNED NOT NULL DEFAULT 0,
    runs        INT UNSIGNED NOT NULL DEFAULT 0,
    duration_ms INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;
