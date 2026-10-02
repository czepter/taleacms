-- Agent notebook (2.15, Core\Notebook): notes for whoever works on the site next – Claude in a new conversation or a
-- colleague: decisions ("we never use the word cheap"), style rules, photo credits, the history of the redesign, what
-- the client is sensitive about. Pinned notes come first; the author is the user's name or the Claude connection name.
CREATE TABLE IF NOT EXISTS ka_notebook (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    topic      VARCHAR(60)  NOT NULL DEFAULT 'other',      -- decisions | style | credits | history | todo | other
    title      VARCHAR(150) NOT NULL,
    text       TEXT         NOT NULL,                      -- plain text
    pinned     TINYINT(1)   NOT NULL DEFAULT 0,
    author     VARCHAR(100) NOT NULL DEFAULT '',           -- the user's name, or the name of the Claude connection
    created_at DATETIME     NOT NULL,
    updated_at DATETIME     NOT NULL,
    PRIMARY KEY (id),
    KEY ix_notebook_topic (topic, pinned, updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;
