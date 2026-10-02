-- Search data (2.13, Core\SearchData): what Google Search Console and Bing Webmaster Tools know about the site, stored
-- once a day by the job search_data as a snapshot of the last 28 days – the top queries and pages with clicks,
-- impressions, CTR and the average position, and for Google the sitemaps (kind sitemap: key = the sitemap address,
-- impressions = pages submitted, clicks = pages indexed). Kept 16 months.
CREATE TABLE IF NOT EXISTS ka_search_stats (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    day         DATE NOT NULL,                          -- the day of the snapshot (it covers the 28 days before it)
    engine      VARCHAR(10) NOT NULL,                   -- google | bing
    kind        VARCHAR(10) NOT NULL,                   -- query | page | sitemap
    `key`       VARCHAR(255) NOT NULL,                  -- the query, the page address or the sitemap address
    clicks      INT UNSIGNED NOT NULL DEFAULT 0,
    impressions INT UNSIGNED NOT NULL DEFAULT 0,
    ctr         DECIMAL(6,2) NOT NULL DEFAULT 0,        -- per cent
    position    DECIMAL(6,1) NOT NULL DEFAULT 0,        -- the average position in the results, 1 = first
    PRIMARY KEY (id),
    KEY ix_search_stats_day (engine, kind, day)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;
