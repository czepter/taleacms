-- Testimonial requests (2.12, Core\Testimonials): a personal link sent after an enquiry; what the customer writes arrives
-- as a hidden draft reference, with the consent they gave and when. Only a hash of the link's token is stored.
CREATE TABLE IF NOT EXISTS ka_testimonial_requests (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    idp         INT UNSIGNED NULL,                  -- the enquiry it was asked for (NULL when the enquiry is gone)
    token_hash  CHAR(64) NOT NULL,
    email       VARCHAR(190) NOT NULL DEFAULT '',
    created_at  DATETIME NOT NULL,
    expires_at  DATETIME NOT NULL,
    used_at     DATETIME NULL,
    item_id     INT UNSIGNED NULL,                  -- the draft reference it created
    consent     TEXT NULL,                          -- the consent texts the customer ticked, as shown to them
    PRIMARY KEY (id),
    UNIQUE KEY ux_testimonial_token (token_hash),
    KEY ix_testimonial_enquiry (idp)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;
