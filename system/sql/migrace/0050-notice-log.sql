-- Official notice board (2.11, Core\Notices): the append-only audit trail of notices – who created or changed a notice and
-- what changed (field keys with the old and new value), and the day the board posted and took down each one. Rows are
-- never edited or deleted, and a notice is never deleted either, so the table stands on its own (no foreign key).
CREATE TABLE IF NOT EXISTS ka_notice_log (
    id      INT UNSIGNED NOT NULL AUTO_INCREMENT,
    idp     INT UNSIGNED NOT NULL,                       -- the notice (ka_kolekce_polozky.idp)
    action  VARCHAR(12)  NOT NULL,                       -- created | changed | posted | taken_down
    `at`    DATETIME     NOT NULL,
    `by`    VARCHAR(100) NOT NULL DEFAULT '',            -- user name, "Claude" or "system"
    fields  MEDIUMTEXT   NOT NULL,                       -- JSON: key => [old, new], the job writes {action: date}
    PRIMARY KEY (id),
    KEY ix_notice_log_item (idp, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;
