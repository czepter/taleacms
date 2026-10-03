-- Shared design kit of a fleet (2.16, Fleet\Kit): the console snapshots its design system, chosen classes, components and
-- saved sections into numbered kit versions; member sites that opted in fetch the newest one (signed by the console) and
-- apply it as drafts only. A component or section that came with a kit keeps the kit's key, so the next version updates
-- it instead of adding a copy.
CREATE TABLE ka_fleet_kits (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    version    INT UNSIGNED NOT NULL,
    created_at DATETIME     NOT NULL,
    created_by INT UNSIGNED NULL,
    manifest   MEDIUMTEXT   NOT NULL,
    sha256     CHAR(64)     NOT NULL,
    summary    VARCHAR(255) NOT NULL DEFAULT '',
    PRIMARY KEY (id),
    UNIQUE KEY uq_fleet_kits_version (version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;
ALTER TABLE ka_komponenty ADD COLUMN kit_key VARCHAR(80) NULL AFTER stavba_koncept;
ALTER TABLE ka_sekce ADD COLUMN kit_key VARCHAR(80) NULL AFTER prvek;
