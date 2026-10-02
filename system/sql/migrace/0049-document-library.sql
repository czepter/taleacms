-- Document library (2.11, Core\Documents): when the file of a document changes, the previous file and its version number
-- are kept for good (the item history keeps only the last few saves), and downloads of the stable address
-- /<collection>/<document>/latest are counted per document and day – no personal data, bots are not counted.
CREATE TABLE IF NOT EXISTS ka_document_versions (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    idp         INT UNSIGNED NOT NULL,                  -- the document (ka_kolekce_polozky)
    file        VARCHAR(500) NOT NULL,                  -- the replaced file: a path in Media or an https address
    version     VARCHAR(100) NOT NULL DEFAULT '',       -- the version number the document stated at the time
    replaced_at DATETIME     NOT NULL,
    replaced_by VARCHAR(100) NOT NULL DEFAULT '',       -- who replaced it (user name, "(Claude)" over MCP)
    PRIMARY KEY (id),
    KEY ix_document_versions_item (idp, id),
    CONSTRAINT fk_document_versions_item FOREIGN KEY (idp) REFERENCES ka_kolekce_polozky (idp) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

CREATE TABLE IF NOT EXISTS ka_document_downloads (
    idp   INT UNSIGNED NOT NULL,
    day   DATE         NOT NULL,
    count INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (idp, day),
    CONSTRAINT fk_document_downloads_item FOREIGN KEY (idp) REFERENCES ka_kolekce_polozky (idp) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;
