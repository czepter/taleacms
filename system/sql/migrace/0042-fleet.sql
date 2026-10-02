-- Fleet console (2.9, Kaleta\Fleet): a Kaleta install with the extension "fleet" keeps the sites paired with it – each
-- site's public key (it signs its heartbeat), the latest heartbeat, the uptime the console checks itself, the update ring,
-- and the site's token for relaying Claude's calls. One-time pairing codes are stored as hashes.
CREATE TABLE IF NOT EXISTS ka_fleet_sites (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name            VARCHAR(150) NOT NULL DEFAULT '',
    url             VARCHAR(255) NOT NULL,
    public_key      VARCHAR(64)  NOT NULL,                  -- base64 Ed25519 key of the site
    relay_token     VARCHAR(80)  NULL,                      -- the site's Claude token for the console; never shown or exported
    relay_access    VARCHAR(10)  NOT NULL DEFAULT '',       -- '' (no relay) | read | drafts | full
    relay_expires   DATETIME     NULL,
    ring            VARCHAR(10)  NOT NULL DEFAULT 'normal', -- canary | normal
    manage_updates  TINYINT(1)   NOT NULL DEFAULT 0,        -- the site lets the console decide when updates install
    update_allowed  VARCHAR(30)  NOT NULL DEFAULT '',       -- the version the console allowed the site to install
    paired_at       DATETIME     NOT NULL,
    last_seen       DATETIME     NULL,                      -- the last heartbeat
    last_ts         INT UNSIGNED NOT NULL DEFAULT 0,        -- its time stamp: an older or repeated heartbeat is refused
    heartbeat       MEDIUMTEXT   NULL,                      -- the last heartbeat (JSON)
    version         VARCHAR(30)  NOT NULL DEFAULT '',
    version_since   DATETIME     NULL,
    status          VARCHAR(10)  NOT NULL DEFAULT '',       -- the site's own health summary: ok | warning | error
    up              TINYINT(1)   NULL,                      -- the console's own check: 1 up, 0 down, NULL not checked yet
    up_status       SMALLINT     NOT NULL DEFAULT 0,        -- the HTTP status of the last check (0 = no answer)
    up_checked      DATETIME     NULL,
    up_changed      DATETIME     NULL,
    up_failures     TINYINT UNSIGNED NOT NULL DEFAULT 0,
    silent_reported TINYINT(1)   NOT NULL DEFAULT 0,        -- "stopped reporting" already recorded as an event
    PRIMARY KEY (id),
    UNIQUE KEY uq_fleet_sites_key (public_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

CREATE TABLE IF NOT EXISTS ka_fleet_pairing (
    code_hash  CHAR(64)     NOT NULL,                       -- sha256 of the one-time code
    created_at DATETIME     NOT NULL,
    expires_at DATETIME     NOT NULL,
    used_at    DATETIME     NULL,
    site_id    INT UNSIGNED NULL,
    PRIMARY KEY (code_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;
