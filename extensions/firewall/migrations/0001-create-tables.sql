-- the addresses blocked for a while (a probe, or a block that still runs) and the log of refused requests
CREATE TABLE IF NOT EXISTS {ext_firewall_blocks} (
    ip VARCHAR(45) NOT NULL PRIMARY KEY,
    blocked_until TIMESTAMP NOT NULL,
    reason VARCHAR(40) NOT NULL DEFAULT '',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX ext_firewall_blocks_until ON {ext_firewall_blocks} (blocked_until);
CREATE TABLE IF NOT EXISTS {ext_firewall_log} (
    id {pk},
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ip VARCHAR(45) NOT NULL,
    reason VARCHAR(40) NOT NULL,
    path VARCHAR(255) NOT NULL DEFAULT ''
);
CREATE INDEX ext_firewall_log_created_at ON {ext_firewall_log} (created_at);
