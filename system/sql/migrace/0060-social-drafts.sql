-- Social post drafts (2.13, Core\SocialDrafts): when a news item is published, a draft per chosen network with a tracked
-- link and an image. A person edits, copies and posts it – the site never posts anywhere.
CREATE TABLE IF NOT EXISTS ka_social_drafts (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    idc        INT UNSIGNED NOT NULL,
    network    VARCHAR(20)  NOT NULL,                      -- facebook | linkedin | x | instagram
    text       TEXT         NOT NULL,
    link       VARCHAR(500) NOT NULL DEFAULT '',           -- the news URL with utm_source, utm_medium, utm_campaign
    image      VARCHAR(500) NOT NULL DEFAULT '',           -- the news image or the picture the site draws (/og/…)
    created_at DATETIME     NOT NULL,
    copied_at  DATETIME     NULL,                          -- when the person marked it as posted
    PRIMARY KEY (id),
    UNIQUE KEY uq_social_drafts (idc, network),
    CONSTRAINT fk_social_drafts_clanek FOREIGN KEY (idc) REFERENCES ka_novinky (idc) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;
