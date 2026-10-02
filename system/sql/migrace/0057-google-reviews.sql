-- Customer reviews from the Google Business Profile (2.13, Core\GoogleBusiness): the latest reviews as Google shows them
-- publicly (display name, stars, text, the owner's reply), fetched once a day; a review gone from Google goes from here,
-- disconnecting Google empties the table. The profile's average rating and review count are in the settings
-- google_rating and google_reviews.
CREATE TABLE IF NOT EXISTS ka_google_reviews (
    review_id   VARCHAR(190) NOT NULL,              -- Google's reviewId
    author      VARCHAR(190) NOT NULL DEFAULT '',   -- the reviewer's public display name
    stars       TINYINT UNSIGNED NOT NULL DEFAULT 0,
    comment     TEXT NULL,
    reviewed_at DATETIME NOT NULL,
    reply       TEXT NULL,                          -- the owner's reply
    replied_at  DATETIME NULL,
    fetched_at  DATETIME NOT NULL,                  -- the last fetch that returned it
    PRIMARY KEY (review_id),
    KEY ix_google_reviews_time (stars, reviewed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;
