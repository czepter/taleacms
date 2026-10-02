-- Real-user speed (2.8): Core Web Vitals (LCP, CLS, INP) from visitors' browsers, aggregated per page path and day as a
-- histogram – one row per metric and bucket (Core\WebVitals::BUCKETS), nothing about the visitor. Cookie-free like the rest
-- of the statistics; rows older than 400 days are deleted.
CREATE TABLE IF NOT EXISTS ka_web_vitals (
    day     DATE NOT NULL,
    path    VARCHAR(255) NOT NULL,
    metric  VARCHAR(3) NOT NULL,                              -- lcp | cls | inp
    bucket  TINYINT UNSIGNED NOT NULL,                        -- index into Core\WebVitals::BUCKETS[metric], the last one is open
    samples INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (day, path, metric, bucket)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;
