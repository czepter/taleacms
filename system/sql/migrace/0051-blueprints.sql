-- Industry blueprints applied on the site (2.11, Core\Blueprint): the manifest with its questions, audit checks and
-- instructions for Claude. The collections and facts it created are ordinary content and stay when it is removed.
CREATE TABLE IF NOT EXISTS ka_blueprints (
    bkey       VARCHAR(40) NOT NULL,
    nazev      VARCHAR(100) NOT NULL DEFAULT '',
    manifest   MEDIUMTEXT NOT NULL,
    applied_at DATETIME NOT NULL,
    PRIMARY KEY (bkey)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;
