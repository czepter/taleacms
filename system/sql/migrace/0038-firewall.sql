-- Firewall (2.8, Core\Firewall): addresses blocked for a while after probing for other systems or a manual block, and
-- the requests the firewall refused (kept 30 days – a security log).
CREATE TABLE IF NOT EXISTS ka_firewall_blocks (
    ip         VARCHAR(45)  NOT NULL,
    until      DATETIME     NOT NULL,
    reason     VARCHAR(40)  NOT NULL DEFAULT '',
    created_at DATETIME     NOT NULL,
    PRIMARY KEY (ip),
    KEY ix_firewall_blocks_until (until)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

CREATE TABLE IF NOT EXISTS ka_firewall_log (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    created_at DATETIME     NOT NULL,
    ip         VARCHAR(45)  NOT NULL,
    reason     VARCHAR(40)  NOT NULL,                      -- list | country | rate | probe | temporary
    path       VARCHAR(255) NOT NULL DEFAULT '',
    PRIMARY KEY (id),
    KEY ix_firewall_log_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;
