-- Whistleblowing channel (2.14, Core\Whistleblowing): reports under the EU Whistleblower Directive. The text, the contact,
-- the attachment list and every message are encrypted with the site's key; only a hash of the reporter's access code is
-- stored; no IP address anywhere. Not exported with the site, not reachable over MCP.
CREATE TABLE IF NOT EXISTS ka_whistleblowing_cases (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    number          VARCHAR(12) NOT NULL,              -- the case number the reporter knows: "2026-0007"
    created_at      DATETIME NOT NULL,
    status          VARCHAR(12) NOT NULL DEFAULT 'received', -- received | acknowledged | in_progress | closed
    acknowledged_at DATETIME NULL,                     -- acknowledgement of receipt (due within 7 days)
    feedback_due    DATETIME NOT NULL,                 -- created_at + 3 months
    closed_at       DATETIME NULL,                     -- closed cases are deleted after the retention period
    text            MEDIUMTEXT NOT NULL,               -- encrypted
    contact         TEXT NULL,                         -- encrypted: name and contact, NULL = anonymous
    attachments     TEXT NULL,                         -- encrypted JSON: [{name, path, size}], files in storage/oznameni/
    code_hash       CHAR(64) NOT NULL,                 -- sha256 of the case number and the access code
    PRIMARY KEY (id),
    UNIQUE KEY ux_whistleblowing_number (number),
    KEY ix_whistleblowing_status (status, closed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

CREATE TABLE IF NOT EXISTS ka_whistleblowing_messages (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    case_id    INT UNSIGNED NOT NULL,
    sender     VARCHAR(10) NOT NULL,                   -- reporter | handler
    text       TEXT NOT NULL,                          -- encrypted
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY ix_whistleblowing_messages_case (case_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;
