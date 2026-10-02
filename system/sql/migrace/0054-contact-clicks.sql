-- Contact clicks (2.12, Core\Conversions): clicks on phone numbers, e-mail addresses and WhatsApp links counted as leads
-- per page path and day – the site's own statistics, without cookies; nothing about the visitor, rows older than 400 days
-- are deleted like the rest of the statistics
CREATE TABLE IF NOT EXISTS ka_stat_konverze (
    den   DATE NOT NULL,
    cesta VARCHAR(255) NOT NULL,
    typ   VARCHAR(10) NOT NULL,                           -- tel | mailto | whatsapp
    pocet INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (den, cesta, typ)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;
