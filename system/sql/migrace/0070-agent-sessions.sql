-- Undo a whole Claude session (2.17, Core\AgentJournal): the changes one Claude connection makes in a row form a session;
-- every content row it changes is kept before and after, by its key, so the session can be taken back in one step.
-- Kept 30 days.
CREATE TABLE ka_agent_sessions (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    connection VARCHAR(100) NOT NULL,                   -- the name of the Claude connection
    started_at DATETIME NOT NULL,
    last_at    DATETIME NOT NULL,
    calls      INT UNSIGNED NOT NULL DEFAULT 0,          -- tool calls that change the site
    undone_at  DATETIME NULL,
    undone_by  VARCHAR(100) NOT NULL DEFAULT '',
    PRIMARY KEY (id),
    KEY ix_agent_sessions_connection (connection, last_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

CREATE TABLE ka_agent_journal (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    session_id INT UNSIGNED NOT NULL,
    call_no    INT UNSIGNED NOT NULL DEFAULT 0,
    tool       VARCHAR(60) NOT NULL DEFAULT '',
    tbl        VARCHAR(40) NOT NULL,
    row_key    VARCHAR(500) NOT NULL DEFAULT '',        -- JSON of the primary key; empty for an untracked write
    before_row MEDIUMTEXT NULL,                         -- JSON of the row before; NULL = the row did not exist
    after_row  MEDIUMTEXT NULL,                         -- JSON of the row after; NULL = deleted
    untracked  VARCHAR(120) NULL,                       -- a write that could not be followed row by row, and why
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY ix_agent_journal_session (session_id, id),
    CONSTRAINT fk_agent_journal_session FOREIGN KEY (session_id) REFERENCES ka_agent_sessions (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;
