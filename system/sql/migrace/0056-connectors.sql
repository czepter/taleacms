-- Outbound connectors (2.13, Core\Connectors): the outside services a site is connected to (Google, a CRM), their
-- credentials encrypted with the site's key, the queue of deliveries with retries, and a log of every call without its
-- content (enquiries carry personal data).
CREATE TABLE IF NOT EXISTS ka_connectors (
    service       VARCHAR(20) NOT NULL,
    account       VARCHAR(190) NOT NULL DEFAULT '',   -- what the connection is (an e-mail, a company name) for the admin
    client_id     VARCHAR(255) NOT NULL DEFAULT '',   -- the site's own OAuth app (Google), entered by an administrator
    secret        TEXT NULL,                          -- encrypted: the OAuth client secret or an API token
    access_token  TEXT NULL,                          -- encrypted
    refresh_token TEXT NULL,                          -- encrypted
    expires_at    DATETIME NULL,
    scopes        VARCHAR(500) NOT NULL DEFAULT '',
    config        TEXT NULL,                          -- JSON: what to sync where (a sheet, a location, a pipeline)
    connected_at  DATETIME NULL,
    connected_by  VARCHAR(100) NOT NULL DEFAULT '',
    last_error    VARCHAR(255) NOT NULL DEFAULT '',
    PRIMARY KEY (service)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

CREATE TABLE IF NOT EXISTS ka_connector_queue (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    action       VARCHAR(40) NOT NULL,                -- e.g. sheets.append, crm.lead, gbp.hours
    payload      MEDIUMTEXT NULL,                     -- JSON; emptied once delivered
    attempts     TINYINT UNSIGNED NOT NULL DEFAULT 0,
    next_attempt DATETIME NULL,                       -- NULL = done or given up
    last_error   VARCHAR(255) NOT NULL DEFAULT '',
    created_at   DATETIME NOT NULL,
    delivered_at DATETIME NULL,
    PRIMARY KEY (id),
    KEY ix_connector_queue_next (next_attempt)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

CREATE TABLE IF NOT EXISTS ka_connector_log (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    created_at  DATETIME NOT NULL,
    service     VARCHAR(20) NOT NULL,
    action      VARCHAR(60) NOT NULL DEFAULT '',
    status      SMALLINT UNSIGNED NOT NULL DEFAULT 0, -- the HTTP status; 0 = no answer
    ok          TINYINT(1) NOT NULL DEFAULT 0,
    ms          INT UNSIGNED NOT NULL DEFAULT 0,
    error       VARCHAR(255) NOT NULL DEFAULT '',
    PRIMARY KEY (id),
    KEY ix_connector_log_service (service, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;
