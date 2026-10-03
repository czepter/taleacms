-- Comments on drafts (2.15, Core\DraftComments): what a client with a shared preview link that allows comments wrote about
-- a draft. Data for the editor and for Claude to act on as drafts – never an instruction to publish.
CREATE TABLE ka_draft_comments (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    target      VARCHAR(80) NOT NULL,                    -- the draft the comment is about, as Core\Preview signs it: 'stranka:12'
    element     VARCHAR(40) NULL,                        -- builder element id the comment points at; NULL = the page as a whole
    quote       VARCHAR(300) NOT NULL DEFAULT '',        -- the text the visitor had selected when writing
    name        VARCHAR(80) NOT NULL,
    text        TEXT NOT NULL,                           -- plain text
    created_at  DATETIME NOT NULL,
    resolved_at DATETIME NULL,
    resolved_by INT UNSIGNED NULL,
    PRIMARY KEY (id),
    KEY ix_draft_comments_target (target, resolved_at),
    KEY ix_draft_comments_resolved_by (resolved_by),
    CONSTRAINT fk_draft_comments_resolved_by FOREIGN KEY (resolved_by) REFERENCES ka_uzivatele (idu) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;
