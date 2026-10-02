-- Requests to Claude (2.15, Core\Requests): staff write what they need changed on the site in the administration
-- ("change the opening hours on Monday", "add this PDF to the price list"); Claude reads them over MCP, works as drafts
-- and answers with notes; a person publishes. Attachments are ordinary Media uploads (ka_media.ido), so Claude can use
-- them. Not in the site export: a request is work for the team, not content of the site.
CREATE TABLE IF NOT EXISTS ka_requests (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    created_at  DATETIME NOT NULL,
    updated_at  DATETIME NOT NULL,
    author_id   INT UNSIGNED NOT NULL,                   -- ka_uzivatele.idu of the staff member who wrote it
    title       VARCHAR(190) NOT NULL,
    text        TEXT NOT NULL,
    about       VARCHAR(500) NOT NULL DEFAULT '',        -- what it is about: page:<ids> | news:<idc> | item:<idp> | a URL | ''
    attachments VARCHAR(255) NOT NULL DEFAULT '[]',      -- JSON list of ka_media.ido (up to 5)
    status      VARCHAR(12) NOT NULL DEFAULT 'new',      -- new | in_progress | done | declined
    done_at     DATETIME NULL,                           -- when it was marked done or declined
    PRIMARY KEY (id),
    KEY ix_requests_status (status, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

-- The conversation of a request: Claude's notes (with links to the drafts it made) and the person's replies.
CREATE TABLE IF NOT EXISTS ka_request_messages (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    request_id  INT UNSIGNED NOT NULL,
    sender      VARCHAR(10) NOT NULL,                    -- claude | person
    sender_name VARCHAR(190) NOT NULL DEFAULT '',        -- the person's name, or the name of the Claude connection
    text        TEXT NOT NULL,
    links       TEXT NULL,                               -- JSON list of {"label": …, "url": …} – the drafts Claude made
    created_at  DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY ix_request_messages (request_id, id),
    CONSTRAINT fk_request_messages FOREIGN KEY (request_id) REFERENCES ka_requests (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;
