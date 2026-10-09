-- Kaleta - database structure
--
-- Table and column names are Czech. The "ka_" prefix is replaced with the prefix from config.php during installation.
-- Older column names remain: idc = news item id, tema/idt = category, ido = media item, idu = user.
-- InnoDB with foreign keys, utf8mb4, passwords via password_hash().
--
-- This file is always the complete current schema for a new installation. Every change is also written
-- as a migration to system/sql/migrace/NNNN-popis.sql, so existing sites update by themselves.

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------------
-- Admin users
-- ---------------------------------------------------------------------------
CREATE TABLE ka_users (
    user_id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    username           VARCHAR(40)  NOT NULL,                 -- sign-in name
    password       VARCHAR(255) NOT NULL,                 -- password_hash()
    name          VARCHAR(100) NOT NULL DEFAULT '',
    email          VARCHAR(190) NOT NULL DEFAULT '',
    url            VARCHAR(255) NOT NULL DEFAULT '',
    admin          TINYINT UNSIGNED NOT NULL DEFAULT 0,   -- role: 0 author, 1 editor, 2 administrator
    role           INT UNSIGNED NULL,                     -- custom role (ka_role); NULL = only the level from admin
    blocked       BOOL NOT NULL DEFAULT 0,
    auto_blocked_at DATETIME NULL,                  -- when the automatic suspension blocked the account (Core\SecurityHygiene); NULL = not by it
    failed_logins     SMALLINT UNSIGNED NOT NULL DEFAULT 0,  -- failed sign-ins in a row
    locked_until     DATETIME NULL,                         -- temporary lock after 10 failed sign-ins
    reset_token_hash   CHAR(64)     NOT NULL DEFAULT '',      -- sha256 of the one-time token for a password reset by e-mail; empty = nothing pending
    reset_sent_at     DATETIME NULL,                         -- when the password reset link was sent (valid for an hour)
    totp_secret VARCHAR(64)  NOT NULL DEFAULT '',      -- two-factor sign-in (TOTP); empty = off
    totp_backup_codes   TEXT NULL,                             -- JSON: hashes of one-time backup codes
    last_login_at DATETIME NULL,                         -- last completed sign-in to the administration
    confirmed_at      DATETIME NULL,                         -- created or last confirmed by an administrator (saved in Users, reactivated) – the unused-account check counts from it
    language          CHAR(2) NOT NULL DEFAULT '',            -- admin language; '' = Czech
    register       VARCHAR(10) NOT NULL DEFAULT '',        -- form of address in the German administration: '' = formal (Sie), 'informal' = du
    position         VARCHAR(100) NOT NULL DEFAULT '',      -- position in the company (bio of the news author)
    photo           VARCHAR(255) NOT NULL DEFAULT '',
    bio            TEXT NULL,                             -- a few sentences about the author
    PRIMARY KEY (user_id),
    UNIQUE KEY uq_users_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

-- Custom roles: a named set of admin sections and a level (0 = writes own news items, 1 = publishes and manages everyone's content).
CREATE TABLE ka_role (
    role_id     INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name   VARCHAR(60)  NOT NULL,
    description   VARCHAR(200) NOT NULL DEFAULT '',
    level  TINYINT UNSIGNED NOT NULL DEFAULT 0,
    modules  VARCHAR(1000) NOT NULL DEFAULT '',            -- comma-separated section identifiers
    PRIMARY KEY (role_id),
    UNIQUE KEY uq_role_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

-- User access to an admin module
CREATE TABLE ka_user_permissions (
    user_id   INT UNSIGNED NOT NULL,
    module VARCHAR(30)  NOT NULL,
    PRIMARY KEY (user_id, module),
    CONSTRAINT fk_user_permissions_user_id FOREIGN KEY (user_id) REFERENCES ka_users (user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;


-- ---------------------------------------------------------------------------
-- Configuration
-- ---------------------------------------------------------------------------
CREATE TABLE ka_settings (
    name VARCHAR(60) NOT NULL,
    value  TEXT NOT NULL,
    PRIMARY KEY (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;



-- ---------------------------------------------------------------------------
-- Categories and news
-- ---------------------------------------------------------------------------
CREATE TABLE ka_categories (
    category_id       INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name     VARCHAR(100) NOT NULL,
    slug  VARCHAR(120) NOT NULL,
    description     TEXT NOT NULL,
    weight   SMALLINT UNSIGNED NOT NULL DEFAULT 100,     -- order, higher = higher up
    language     CHAR(2) NOT NULL DEFAULT '',                -- language version; '' = the site's default language
    translation_of INT UNSIGNED NULL,                          -- counterpart in the default language (hreflang, language switcher)
    PRIMARY KEY (category_id),
    UNIQUE KEY uq_categories_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;




CREATE TABLE ka_news (
    news_id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    slug       VARCHAR(160) NOT NULL,
    title        VARCHAR(255) NOT NULL,
    intro           MEDIUMTEXT NOT NULL,                   -- intro
    text           MEDIUMTEXT NOT NULL,
    image        VARCHAR(255) NOT NULL DEFAULT '',      -- featured image
    image_caption  VARCHAR(300) NOT NULL DEFAULT '',      -- caption of the featured image (empty = the caption from the media library)
    image_author  VARCHAR(120) NOT NULL DEFAULT '',      -- author of the featured image (empty = the author from the media library)
    category_id           INT UNSIGNED NOT NULL,                 -- category
    author_id          INT UNSIGNED NULL,
    published_at          DATETIME NOT NULL,                     -- publish date (also a future one)
    visible        BOOL NOT NULL DEFAULT 0,               -- published news item (otherwise a draft)
    keywords        VARCHAR(500) NOT NULL DEFAULT '',      -- keywords
    seo_title    VARCHAR(255) NOT NULL DEFAULT '',      -- custom <title>, empty = the title
    seo_description      VARCHAR(320) NOT NULL DEFAULT '',      -- custom meta description, empty = from the intro
    noindex        BOOL NOT NULL DEFAULT 0,
    faq            TEXT NULL,                             -- questions and answers: question, the answer below it, an empty line
    visit          INT UNSIGNED NOT NULL DEFAULT 0,       -- view count
    edited_at        DATETIME NULL,
    updated_at  DATETIME NULL,                         -- when the published news item was substantially updated
    announced_at       DATETIME NULL,                         -- when the system announced the publishing (webhook, IndexNow); NULL = not yet
    valid_until    DATE NULL,                             -- true until: the day after, the news item hides itself (2.10, Core\Validity)
    review_by      DATE NULL,                             -- review by: on this day the site audit asks for a check (2.10)
    language          CHAR(2) NOT NULL DEFAULT '',           -- taken from the category on save
    translation_of      INT UNSIGNED NULL,                     -- idc of the news item this one is a translation of
    search_text        MEDIUMTEXT NULL,                       -- text without diacritics for search (Core\Search)
    links_checked_at     DATETIME NULL,                         -- when the links were last checked
    deleted_at        DATETIME NULL,                         -- in the trash since (deleted permanently after 30 days); NULL = not in the trash
    PRIMARY KEY (news_id),
    UNIQUE KEY uq_news_slug (slug),
    KEY ix_news_language_visible_published_at (language, visible, published_at),
    KEY ix_news_announced_at_visible_published_at (announced_at, visible, published_at),
    KEY ix_news_published_at (published_at),
    KEY ix_news_deleted_at (deleted_at),
    KEY ix_news_category_id_visible_published_at (category_id, visible, published_at),
    KEY ix_news_author_id (author_id),
    FULLTEXT KEY ft_news_title_intro_text_keywords (title, intro, text, keywords),
    FULLTEXT KEY ft_news_search_text (search_text),
    CONSTRAINT fk_news_category_id  FOREIGN KEY (category_id)  REFERENCES ka_categories (category_id),
    CONSTRAINT fk_news_author_id FOREIGN KEY (author_id) REFERENCES ka_users (user_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;


-- ---------------------------------------------------------------------------
-- Image gallery
-- ---------------------------------------------------------------------------
CREATE TABLE ka_media_folders (
    folder_id   INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    PRIMARY KEY (folder_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

CREATE TABLE ka_media (
    media_id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    owner_id    INT UNSIGNED NULL,
    folder_id       INT UNSIGNED NULL,                         -- folder
    name       VARCHAR(150) NOT NULL DEFAULT '',          -- also serves as the alternative text (alt)
    description       VARCHAR(500) NOT NULL DEFAULT '',          -- caption below the image
    author       VARCHAR(120) NOT NULL DEFAULT '',          -- photo author (shown with the article's featured photo)
    image_path  VARCHAR(255) NOT NULL,                     -- path from the web root: media/2026/09/foto.jpg
    image_width   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    image_height  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    image_size     INT UNSIGNED NOT NULL DEFAULT 0,           -- file size in bytes
    thumb_path VARCHAR(255) NOT NULL DEFAULT '',
    thumb_width  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    thumb_height SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    color       CHAR(7) NOT NULL DEFAULT '',               -- dominant color (#rrggbb) as a placeholder before loading; '' = not computed, '-' = cannot be determined
    focal_point     VARCHAR(12) NOT NULL DEFAULT '',           -- crop center (object-position), e.g. „50% 30%“; '' = center
    created_at       DATETIME NOT NULL,
    PRIMARY KEY (media_id),
    KEY ix_media_created_at (created_at),
    KEY ix_media_image_path (image_path),
    KEY ix_media_folder_id (folder_id),
    CONSTRAINT fk_media_folder_id FOREIGN KEY (folder_id) REFERENCES ka_media_folders (folder_id) ON DELETE SET NULL,
    CONSTRAINT fk_media_owner_id FOREIGN KEY (owner_id) REFERENCES ka_users (user_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;



-- Protection against repeating an action from the same IP (sign-in, search, forms)
CREATE TABLE ka_ip_checks (
    check_id       INT UNSIGNED NOT NULL AUTO_INCREMENT,
    ip VARCHAR(45) NOT NULL,
    type       VARCHAR(20) NOT NULL,
    target       INT UNSIGNED NOT NULL DEFAULT 0,
    checked_at       DATETIME NOT NULL,
    PRIMARY KEY (check_id),
    KEY ix_ip_checks_type_target_ip_checked_at (type, target, ip, checked_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

-- Which news items an image is used in (recomputed on save)
CREATE TABLE ka_media_usage (
    media_id INT UNSIGNED NOT NULL,
    news_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (media_id, news_id),
    KEY ix_media_usage_news_id (news_id),
    CONSTRAINT fk_media_usage_media_id FOREIGN KEY (media_id) REFERENCES ka_media (media_id) ON DELETE CASCADE,
    CONSTRAINT fk_media_usage_news_id FOREIGN KEY (news_id) REFERENCES ka_news (news_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

-- ---------------------------------------------------------------------------
-- News tags, news item version history and site pages.
CREATE TABLE ka_tags (
    tag_id      INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name    VARCHAR(80) NOT NULL,
    slug VARCHAR(100) NOT NULL,
    description    TEXT NULL,                                   -- intro of the topic page (HTML from the editors)
    image  VARCHAR(255) NOT NULL DEFAULT '',
    PRIMARY KEY (tag_id),
    UNIQUE KEY uq_tags_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;
CREATE TABLE ka_news_tags (
    news_id INT UNSIGNED NOT NULL,
    tag_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (news_id, tag_id),
    KEY ix_news_tags_tag_id (tag_id),
    CONSTRAINT fk_news_tags_news_id FOREIGN KEY (news_id) REFERENCES ka_news (news_id) ON DELETE CASCADE,
    CONSTRAINT fk_news_tags_tag_id FOREIGN KEY (tag_id) REFERENCES ka_tags (tag_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;
CREATE TABLE ka_news_revisions (
    revision_id     INT UNSIGNED NOT NULL AUTO_INCREMENT,
    news_id     INT UNSIGNED NOT NULL,
    created_at   DATETIME NOT NULL,
    user_id     INT UNSIGNED NULL,
    title VARCHAR(255) NOT NULL,
    intro    MEDIUMTEXT NOT NULL,
    text    MEDIUMTEXT NOT NULL,
    PRIMARY KEY (revision_id),
    KEY ix_news_revisions_news_id_created_at (news_id, created_at),
    CONSTRAINT fk_news_revisions_news_id FOREIGN KEY (news_id) REFERENCES ka_news (news_id) ON DELETE CASCADE,
    CONSTRAINT fk_news_revisions_user_id FOREIGN KEY (user_id) REFERENCES ka_users (user_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;
CREATE TABLE ka_pages (
    page_id      INT UNSIGNED NOT NULL AUTO_INCREMENT,
    slug VARCHAR(120) NOT NULL,
    title  VARCHAR(200) NOT NULL,
    description    VARCHAR(300) NOT NULL DEFAULT '',           -- meta description
    seo_title VARCHAR(200) NOT NULL DEFAULT '',        -- custom <title>, empty = the title
    image  VARCHAR(255) NOT NULL DEFAULT '',           -- image for sharing (og:image), empty = the default from Settings
    noindex  BOOL NOT NULL DEFAULT 0,
    password_hash VARCHAR(255) NULL,                      -- password-protected page (2.14, Core\PageLock): password_hash(); NULL = public
    text     MEDIUMTEXT NOT NULL,
    visible BOOL NOT NULL DEFAULT 1,
    publish_at DATETIME NULL,                          -- a hidden page publishes itself at this moment
    valid_until DATE NULL,                               -- true until: the day after, the page hides itself (2.10, Core\Validity)
    review_by DATE NULL,                                 -- review by: on this day the site audit asks for a check (2.10)
    head_code TEXT NULL,                              -- code for <head> of this page only (administrators, 2.3)
    in_menu   BOOL NOT NULL DEFAULT 1,                     -- link in the site footer / navigation
    sort_order   SMALLINT UNSIGNED NOT NULL DEFAULT 100,
    updated_at  DATETIME NULL,
    links_checked DATETIME NULL,                         -- when the links of the published page were last checked (2.14, Core\Links)
    language          CHAR(2) NOT NULL DEFAULT '',            -- language version; '' = the site's default language
    translation_of      INT UNSIGNED NULL,                      -- counterpart in the default language (hreflang, language switcher)
    parent_id      INT UNSIGNED NULL,                      -- parent page: the URL is /nadrazena/stranka
    build         MEDIUMTEXT NULL,                        -- published build (JSON tree of builder elements); NULL = text page
    build_draft MEDIUMTEXT NULL,                        -- work-in-progress build from the editor; NULL = no unsaved changes
    deleted_at        DATETIME NULL,                          -- in the trash since (deleted permanently after 30 days); NULL = not in the trash
    PRIMARY KEY (page_id),
    UNIQUE KEY uq_pages_slug (slug),
    KEY ix_pages_deleted_at (deleted_at),
    KEY ix_pages_publish_at (publish_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

-- Published build versions (the last 20 per page)
-- Site parts from the builder: header and footer on all pages, the envelope of the news item detail, the listing and the 404 page.
-- Without a row (or without a published build) the part from the layout applies. Language '' = the site's default language.
CREATE TABLE ka_site_parts (
    type            VARCHAR(20) NOT NULL,
    language          CHAR(2) NOT NULL DEFAULT '',
    variant       VARCHAR(40) NOT NULL DEFAULT '',   -- '' = default; otherwise the variant for the pages in the stranky list (JSON of numbers)
    name          VARCHAR(100) NOT NULL DEFAULT '',
    pages        TEXT NULL,
    build         MEDIUMTEXT NULL,
    build_draft MEDIUMTEXT NULL,
    updated_at        DATETIME NULL,
    PRIMARY KEY (type, language, variant)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

-- Published build versions: of pages (ids) and of site parts (cast = "typ:jazyk").
CREATE TABLE ka_build_revisions (
    revision_id    INT UNSIGNED NOT NULL AUTO_INCREMENT,
    page_id    INT UNSIGNED NULL,
    part   VARCHAR(80) NULL,
    created_at  DATETIME NOT NULL,
    user_id    INT UNSIGNED NULL,
    build MEDIUMTEXT NOT NULL,
    PRIMARY KEY (revision_id),
    KEY ix_build_revisions_page_id_revision_id (page_id, revision_id),
    KEY ix_build_revisions_part_revision_id (part, revision_id),
    CONSTRAINT fk_build_revisions_page_id FOREIGN KEY (page_id) REFERENCES ka_pages (page_id) ON DELETE CASCADE,
    CONSTRAINT fk_build_revisions_user_id FOREIGN KEY (user_id) REFERENCES ka_users (user_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

-- Shared builder classes: style by breakpoints and states (JSON like an element's style) + optional custom CSS
CREATE TABLE ka_classes (
    name  VARCHAR(60) NOT NULL,                          -- class name in HTML (lowercase letters, digits, hyphens, __)
    style   TEXT NOT NULL,                                 -- {"zaklad": {...}, "tablet": {...}, "mobil": {...}, "hover": {...}}
    css    TEXT NULL,                                     -- custom declarations (only safe ones, see Builder\Style::customCss)
    updated_at DATETIME NULL,
    PRIMARY KEY (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

-- ---------------------------------------------------------------------------
-- Redirects and consent records
CREATE TABLE ka_redirects (
    redirect_id       INT UNSIGNED NOT NULL AUTO_INCREMENT,
    from_path  VARCHAR(255) NOT NULL,                     -- path on the site without the leading slash: clanek/stara-adresa
    to_path VARCHAR(255) NOT NULL,                     -- path on the site, or a full URL https://...
    type       SMALLINT UNSIGNED NOT NULL DEFAULT 301,    -- 301 permanent, 302 temporary
    auto_score TINYINT UNSIGNED NULL,                    -- NULL = by hand or a slug change; 0–100 = created by the daily job with this confidence (2.14, Core\RedirectMatcher)
    hits     INT UNSIGNED NOT NULL DEFAULT 0,           -- how many times the redirect was used
    created_at DATETIME NOT NULL,
    PRIMARY KEY (redirect_id),
    UNIQUE KEY uq_redirects_from_path (from_path)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;
CREATE TABLE ka_consents (
    consent_id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    visitor_token CHAR(32) NOT NULL,                       -- a random identifier stored in the visitor's cookie
    created_at         DATETIME NOT NULL,
    categories   VARCHAR(60) NOT NULL,                    -- "analytika,marketing" or "nic"
    PRIMARY KEY (consent_id),
    KEY ix_consents_created_at (created_at),
    KEY ix_consents_visitor_token (visitor_token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

-- ---------------------------------------------------------------------------
-- Stats without cookies
CREATE TABLE ka_stats_days (
    day       DATE NOT NULL,
    visits  INT UNSIGNED NOT NULL DEFAULT 0,            -- unique visitors of the day
    views INT UNSIGNED NOT NULL DEFAULT 0,            -- page views
    PRIMARY KEY (day)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;
-- Visitor hash = hash(IP + browser + daily salt). The next day it can no longer be linked to the previous one; older rows are deleted.
-- Contact clicks (2.12, Core\Conversions) leave a mark here too – the same hash with the page and the link type mixed in – so a click counts once a day.
CREATE TABLE ka_stats_visitors (
    day   DATE NOT NULL,
    visitor_hash CHAR(32) NOT NULL,
    PRIMARY KEY (day, visitor_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;
CREATE TABLE ka_stats_news (
    day   DATE NOT NULL,
    news_id   INT UNSIGNED NOT NULL,
    views INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (day, news_id),
    KEY ix_stats_news_news_id (news_id),
    CONSTRAINT fk_stats_news_news_id FOREIGN KEY (news_id) REFERENCES ka_news (news_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;
CREATE TABLE ka_stats_pages (
    day   DATE NOT NULL,
    path VARCHAR(255) NOT NULL,
    views INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (day, path)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

-- Campaigns (utm_source / utm_medium / utm_campaign of the page a visit started on) and devices of the visits (2.3)
CREATE TABLE ka_stats_campaigns (
    day      DATE NOT NULL,
    campaign   VARCHAR(255) NOT NULL,
    visits INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (day, campaign)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;
CREATE TABLE ka_stats_devices (
    day      DATE NOT NULL,
    device VARCHAR(10) NOT NULL,                        -- phone | tablet | computer
    visits INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (day, device)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

-- Contact clicks (2.12, Core\Conversions): clicks on phone numbers, e-mail addresses and WhatsApp links counted as leads
-- per page path and day – without cookies, nothing about the visitor; rows older than 400 days are deleted
CREATE TABLE ka_stats_conversions (
    day   DATE NOT NULL,
    path VARCHAR(255) NOT NULL,
    type   VARCHAR(10) NOT NULL,                           -- tel | mailto | whatsapp
    count INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (day, path, type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

-- Real-user speed (2.8): Core Web Vitals per page path and day as a histogram – one row per metric and bucket
-- (Core\WebVitals::BUCKETS); nothing about the visitor, rows older than 400 days are deleted
CREATE TABLE ka_web_vitals (
    day     DATE NOT NULL,
    path    VARCHAR(255) NOT NULL,
    metric  VARCHAR(3) NOT NULL,                              -- lcp | cls | inp
    bucket  TINYINT UNSIGNED NOT NULL,                        -- index into Core\WebVitals::BUCKETS[metric], the last one is open
    samples INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (day, path, metric, bucket)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

CREATE TABLE ka_stats_sources (
    day   DATE NOT NULL,
    source VARCHAR(100) NOT NULL,                          -- the domain the visitor came from
    count INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (day, source)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;


-- ---------------------------------------------------------------------------
-- Change log in the admin
CREATE TABLE ka_change_log (
    log_id   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    created_at   DATETIME NOT NULL,
    user_id   INT UNSIGNED NULL,
    user_name VARCHAR(100) NOT NULL DEFAULT '',               -- the name at the moment of the action (the account may be removed later)
    via   VARCHAR(100) NOT NULL DEFAULT '',               -- the Claude connection a change came through (empty = the admin)
    module VARCHAR(30) NOT NULL,
    action  VARCHAR(40) NOT NULL,
    description VARCHAR(255) NOT NULL DEFAULT '',
    reason VARCHAR(255) NOT NULL DEFAULT '',               -- why (2.15): the reason Claude gave with a write tool
    PRIMARY KEY (log_id),
    KEY ix_change_log_created_at (created_at),
    KEY ix_change_log_user_id (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

-- ---------------------------------------------------------------------------
-- Access tokens for connecting to Claude (MCP). Only the token hash is stored.
CREATE TABLE ka_api_tokens (
    token_id       INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id       INT UNSIGNED NOT NULL,
    name     VARCHAR(100) NOT NULL,
    client_id    CHAR(32) NULL,                              -- OAuth client_id; NULL = a personal token from "Můj účet" (My account)
    kind      VARCHAR(10) NOT NULL DEFAULT 'token',       -- token | pristup | obnova
    access    VARCHAR(10) NOT NULL DEFAULT 'full',        -- full | drafts | read – what the connection may do (2.2)
    expires_at  DATETIME NULL,
    token_hash     CHAR(64) NOT NULL,                          -- sha256 of the token
    created_at  DATETIME NOT NULL,
    used_at    DATETIME NULL,
    PRIMARY KEY (token_id),
    UNIQUE KEY uq_api_tokens_token_hash (token_hash),
    KEY ix_api_tokens_client_id (client_id),
    CONSTRAINT fk_api_tokens_user_id FOREIGN KEY (user_id) REFERENCES ka_users (user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

-- Passkeys (WebAuthn) as the second sign-in step. Only the device's public key is stored.
CREATE TABLE ka_user_passkeys (
    passkey_id        INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id        INT UNSIGNED NOT NULL,
    name      VARCHAR(80)  NOT NULL DEFAULT '',          -- the device name given by the user („MacBook“, „telefon“)
    credential_hash   CHAR(64)     NOT NULL,                     -- sha256 of the key identifier (the identifier can be up to 1023 bytes)
    credential_id   TEXT         NOT NULL,                     -- key identifier, base64url
    public_key    TEXT         NOT NULL,                     -- the device's public key (PEM)
    alg        SMALLINT     NOT NULL,                     -- signature algorithm per COSE: -7 ES256, -257 RS256
    sign_count  INT UNSIGNED NOT NULL DEFAULT 0,           -- signature counter; if the device keeps one, it must increase
    created_at  DATETIME     NOT NULL,
    used_at    DATETIME     NULL,
    PRIMARY KEY (passkey_id),
    UNIQUE KEY uq_user_passkeys_credential_hash (credential_hash),
    KEY ix_user_passkeys_user_id (user_id),
    CONSTRAINT fk_user_passkeys_user_id FOREIGN KEY (user_id) REFERENCES ka_users (user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;






-- ---------------------------------------------------------------------------
-- E-mail queue and log (Core\Mail)
-- ---------------------------------------------------------------------------
CREATE TABLE ka_mail (
    mail_id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    recipient        VARCHAR(190) NOT NULL,
    subject     VARCHAR(255) NOT NULL,
    body        MEDIUMTEXT NULL,                          -- JSON {text, html, hlavicky}; deleted after sending
    created_at   DATETIME NOT NULL,
    sent_at    DATETIME NULL,
    attempts      TINYINT UNSIGNED NOT NULL DEFAULT 0,
    next_attempt_at DATETIME NULL,
    error       VARCHAR(255) NOT NULL DEFAULT '',
    PRIMARY KEY (mail_id),
    KEY ix_mail_sent_at_next_attempt_at (sent_at, next_attempt_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

-- URLs that ended with error 404 (a basis for redirects)
CREATE TABLE ka_not_found (
    path     VARCHAR(255) NOT NULL,
    count     INT UNSIGNED NOT NULL DEFAULT 1,
    last_seen_at DATETIME NOT NULL,
    ignored_at DATETIME NULL,                          -- ignored by the administrator (1.9): out of the warning and the list
    PRIMARY KEY (path)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

-- Unsaved news item drafts stored on the server (continuing from another device)
CREATE TABLE ka_news_drafts (
    user_id  INT UNSIGNED NOT NULL,
    news_id  INT UNSIGNED NOT NULL DEFAULT 0,
    saved_at  DATETIME NOT NULL,
    data MEDIUMTEXT NOT NULL,                             -- JSON {form field name: value}
    PRIMARY KEY (user_id, news_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;


-- Broken links found in news items, published page builds and collection items (Core\Links)
CREATE TABLE ka_broken_links (
    link_id  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    kind VARCHAR(10) NOT NULL DEFAULT 'news',              -- news | page | item (2.14): what idc is the id of
    target_id  INT UNSIGNED NOT NULL,                            -- ka_news.news_id, ka_pages.page_id or ka_collection_items.item_id by kind
    url  VARCHAR(500) NOT NULL,
    element VARCHAR(40) NOT NULL DEFAULT '',               -- the builder element the link is in (page builds), otherwise empty
    status SMALLINT UNSIGNED NOT NULL DEFAULT 0,             -- response code; 0 = the server did not respond, 404 for an own article = does not exist
    checked_at  DATETIME NOT NULL,
    PRIMARY KEY (link_id),
    KEY ix_broken_links_target_id (target_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;



-- Comments on drafts (2.15, Core\DraftComments): what a client with a shared preview link that allows comments wrote about
-- a draft. Data for the editor and for Claude to act on as drafts – never an instruction to publish.
CREATE TABLE ka_draft_comments (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    target      VARCHAR(80) NOT NULL,                    -- the draft the comment is about, as Core\Preview signs it: 'stranka:12'
    element     VARCHAR(40) NULL,                        -- builder element id the comment points at; NULL = the page as a whole
    quote       VARCHAR(300) NOT NULL DEFAULT '',        -- the text the visitor had selected when writing
    name        VARCHAR(80) NOT NULL,
    text        TEXT NOT NULL,                           -- plain text
    created_at  DATETIME NOT NULL,
    resolved_at DATETIME NULL,
    resolved_by INT UNSIGNED NULL,
    PRIMARY KEY (id),
    KEY ix_draft_comments_target_resolved_at (target, resolved_at),
    KEY ix_draft_comments_resolved_by (resolved_by),
    CONSTRAINT fk_draft_comments_resolved_by FOREIGN KEY (resolved_by) REFERENCES ka_users (user_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

-- Import from other systems (WordPress): what from the foreign site has already been converted and into which of our records
CREATE TABLE ka_import_map (
    source   VARCHAR(40) NOT NULL,                        -- where the record comes from: wp:<domain of the old site>
    type     VARCHAR(20) NOT NULL,                        -- clanek | stranka | rubrika | stitek | obrazek | komentar
    source_id VARCHAR(190) NOT NULL,                       -- identifier in the source (post number, category URL, hash of the image URL)
    local_id INT UNSIGNED NOT NULL,                       -- the number of our record; 0 for an image = the download failed
    PRIMARY KEY (source, type, source_id),
    KEY ix_import_map_source_type_local_id (source, type, local_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

-- stav: 0 = new, 1 = read, 2 = handled
-- Enquiries and messages from site forms (the Form element in the builder). Data = JSON [[popisek, hodnota], …].
CREATE TABLE ka_enquiries (
    enquiry_id      INT UNSIGNED NOT NULL AUTO_INCREMENT,
    created_at    DATETIME NOT NULL,
    form VARCHAR(120) NOT NULL DEFAULT '',
    source    VARCHAR(40) NOT NULL DEFAULT '',
    element    VARCHAR(16) NOT NULL DEFAULT '',
    page  VARCHAR(255) NOT NULL DEFAULT '',
    topic     VARCHAR(255) NOT NULL DEFAULT '',          -- what it was about (2.12, Front\EnquiryTopic): "<collection> – <item>", the page title or the pop-up name
    landing_page    VARCHAR(255) NOT NULL DEFAULT '',          -- the first page of the visit (2.3; only with consent to marketing)
    referrer    VARCHAR(100) NOT NULL DEFAULT '',          -- the site that sent the visitor (2.3; likewise)
    campaign   VARCHAR(255) NOT NULL DEFAULT '',          -- utm_* parameters of the page with the form (or of the visit, 2.3)
    email    VARCHAR(190) NOT NULL DEFAULT '',
    data     MEDIUMTEXT NOT NULL,
    status     TINYINT UNSIGNED NOT NULL DEFAULT 0,
    category VARCHAR(12) NOT NULL DEFAULT '',          -- triage (2.12, Core\Triage): sales | support | job | supplier | spam | other; '' = not sorted yet
    priority TINYINT UNSIGNED NOT NULL DEFAULT 0,       -- 0 = not set, 1 low, 2 normal, 3 high
    suggested_reply TEXT NULL,                           -- a drafted reply (never sent by itself)
    triaged_by VARCHAR(40) NOT NULL DEFAULT '',         -- claude | assistant | rule | the user's name
    triaged_at DATETIME NULL,
    note TEXT NULL,                               -- internal note (the visitor does not see it)
    assigned_to INT UNSIGNED NULL,                      -- which user handles the enquiry
    anonymised_at DATETIME NULL,                      -- the person's data was blanked at this time (2.14, Core\Privacy); NULL = still held
    PRIMARY KEY (enquiry_id),
    KEY ix_enquiries_status_enquiry_id (status, enquiry_id),
    KEY ix_enquiries_category_enquiry_id (category, enquiry_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

-- Collections: custom content types (references, team, products, branches…). pole = JSON [{klic, popisek, typ}], typ: text | radky | html | obrazek | odkaz | cislo | datum | termin | soubor | poloha | polozka.
-- detail = items have their own page /<seo_link>/<item seo> with an item template from the builder (stavba, stavba_koncept).
CREATE TABLE ka_collections (
    collection_id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name          VARCHAR(100) NOT NULL,
    slug       VARCHAR(110) NOT NULL,
    fields           TEXT NOT NULL,
    detail         TINYINT(1) NOT NULL DEFAULT 0,
    hidden_redirect VARCHAR(255) NOT NULL DEFAULT '',    -- where the page of a hidden or deleted item redirects (2.10); empty = 404
    preset         VARCHAR(30) NOT NULL DEFAULT '',     -- the ready-made collection it was created from (2.11, Builder\Presets); empty = its own
    schema_org     TEXT NULL,                           -- structured data of item pages: {"typ": "Service|Person|Product|Event|FAQPage", "pole": {property: field key}} (1.9)
    build         MEDIUMTEXT NULL,
    build_draft MEDIUMTEXT NULL,
    updated_at        DATETIME NULL,
    PRIMARY KEY (collection_id),
    UNIQUE KEY uq_collections_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

CREATE TABLE ka_collection_items (
    item_id      INT UNSIGNED NOT NULL AUTO_INCREMENT,
    collection_id      INT UNSIGNED NOT NULL,
    name    VARCHAR(200) NOT NULL,
    slug VARCHAR(160) NOT NULL,
    data     MEDIUMTEXT NOT NULL,
    seo_title VARCHAR(200) NOT NULL DEFAULT '',       -- custom <title>, empty = the name (1.9)
    description    VARCHAR(300) NOT NULL DEFAULT '',          -- meta description, empty = from the first text field
    image  VARCHAR(255) NOT NULL DEFAULT '',          -- image for sharing (og:image), empty = the first image field
    noindex  TINYINT(1) NOT NULL DEFAULT 0,
    sort_order   INT NOT NULL DEFAULT 100,
    visible TINYINT(1) NOT NULL DEFAULT 1,
    publish_at DATETIME NULL,                         -- a hidden item publishes itself at this moment
    valid_until DATE NULL,                              -- true until: the day after, the item hides itself (2.10, Core\Validity)
    review_by DATE NULL,                                -- review by: on this day the site audit asks for a check (2.10)
    language    CHAR(2) NOT NULL DEFAULT '',
    created_at    DATETIME NOT NULL,
    updated_at  DATETIME NULL,
    links_checked DATETIME NULL,                        -- when the links in the item's fields were last checked (2.14, Core\Links)
    deleted_at  DATETIME NULL,                     -- in the trash since (deleted permanently after 30 days); NULL = not in the trash
    PRIMARY KEY (item_id),
    UNIQUE KEY uq_collection_items_collection_id_language_slug (collection_id, language, slug),
    KEY ix_collection_items_collection_id_visible_s_8f145d (collection_id, visible, sort_order),
    KEY ix_collection_items_publish_at (publish_at),
    CONSTRAINT fk_collection_items_collection_id FOREIGN KEY (collection_id) REFERENCES ka_collections (collection_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

-- The collection's item template in other site languages (the default language is in ka_collections); without a row the default language's template applies.
CREATE TABLE ka_collection_templates (
    collection_id            INT UNSIGNED NOT NULL,
    language          CHAR(2) NOT NULL,
    build         MEDIUMTEXT NULL,
    build_draft MEDIUMTEXT NULL,
    updated_at        DATETIME NULL,
    PRIMARY KEY (collection_id, language),
    CONSTRAINT fk_collection_templates_collection_id FOREIGN KEY (collection_id) REFERENCES ka_collections (collection_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

-- Document library (2.11, Core\Documents): the previous files of a document, kept for good when its file changes.
CREATE TABLE ka_document_versions (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    item_id         INT UNSIGNED NOT NULL,                  -- the document (ka_collection_items)
    file        VARCHAR(500) NOT NULL,                  -- the replaced file: a path in Media or an https address
    version     VARCHAR(100) NOT NULL DEFAULT '',       -- the version number the document stated at the time
    replaced_at DATETIME     NOT NULL,
    replaced_by VARCHAR(100) NOT NULL DEFAULT '',       -- who replaced it (user name, "(Claude)" over MCP)
    PRIMARY KEY (id),
    KEY ix_document_versions_item_id_id (item_id, id),
    CONSTRAINT fk_document_versions_item_id FOREIGN KEY (item_id) REFERENCES ka_collection_items (item_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

-- Downloads of a document's stable address (/<collection>/<document>/latest) per day – counts only, no personal data.
CREATE TABLE ka_document_downloads (
    item_id   INT UNSIGNED NOT NULL,
    day   DATE         NOT NULL,
    count INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (item_id, day),
    CONSTRAINT fk_document_downloads_item_id FOREIGN KEY (item_id) REFERENCES ka_collection_items (item_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

-- Site menus ("Vzhled → Menu", Appearance → Menu): main and footer, for each language version. Without a row the main menu is composed of pages „v menu“ (in menu).
CREATE TABLE ka_menus (
    location VARCHAR(20) NOT NULL,                    -- hlavni | paticka
    language    CHAR(2) NOT NULL DEFAULT '',             -- '' = the site's default language
    items  MEDIUMTEXT NOT NULL,                     -- JSON [{typ: stranka|odkaz|novinky|skupina, ids, url, text, nove_okno, deti: […]}]
    updated_at  DATETIME NULL,
    PRIMARY KEY (location, language)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

CREATE TABLE ka_page_revisions (
    revision_id     INT UNSIGNED NOT NULL AUTO_INCREMENT,
    page_id     INT UNSIGNED NOT NULL,
    created_at   DATETIME NOT NULL,
    user_id     INT UNSIGNED NULL,
    title VARCHAR(200) NOT NULL,
    text    MEDIUMTEXT NOT NULL,
    PRIMARY KEY (revision_id),
    KEY ix_page_revisions_page_id_revision_id (page_id, revision_id),
    CONSTRAINT fk_page_revisions_page_id FOREIGN KEY (page_id) REFERENCES ka_pages (page_id) ON DELETE CASCADE,
    CONSTRAINT fk_page_revisions_user_id FOREIGN KEY (user_id) REFERENCES ka_users (user_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

-- Sections the site saved from the builder into its own library (panel "Přidat → Moje sekce", Add → My sections).
CREATE TABLE ka_sections (
    section_id     INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name   VARCHAR(100) NOT NULL,
    element   MEDIUMTEXT NOT NULL,                      -- JSON of one element (usually a section) including its contents
    kit_key VARCHAR(80) NULL,                         -- the key it came with from a fleet design kit (2.16, Fleet\Kit): the next kit updates it
    updated_at DATETIME NULL,
    PRIMARY KEY (section_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

-- Components: reusable builder blocks. vlastnosti = JSON [{klic, popisek, typ, vychozi}] – in the component as {{klic}},
-- every use (element „komponenta“) gives them its own values. A change to the component shows everywhere it is used.
-- Popups as site parts: their own build in the builder, trigger, display rules (JSON), frequency and counters without cookies.
CREATE TABLE ka_popups (
    popup_id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name          VARCHAR(100) NOT NULL,
    slug         VARCHAR(60)  NOT NULL,
    type            VARCHAR(20)  NOT NULL DEFAULT 'okno',
    trigger_type       VARCHAR(20)  NOT NULL DEFAULT 'cas',
    value        SMALLINT UNSIGNED NOT NULL DEFAULT 5,
    rules       TEXT NOT NULL,
    frequency        VARCHAR(20)  NOT NULL DEFAULT 'relace',
    days            SMALLINT UNSIGNED NOT NULL DEFAULT 7,
    active        TINYINT(1) NOT NULL DEFAULT 0,
    valid_until    DATE NULL,                             -- true until: the day after, the pop-up switches itself off (2.10, Core\Validity)
    review_by      DATE NULL,                             -- review by: on this day the site audit asks for a check (2.10)
    sort_order         SMALLINT NOT NULL DEFAULT 100,
    build         MEDIUMTEXT NULL,
    build_draft MEDIUMTEXT NULL,
    impressions      INT UNSIGNED NOT NULL DEFAULT 0,
    closes        INT UNSIGNED NOT NULL DEFAULT 0,
    conversions       INT UNSIGNED NOT NULL DEFAULT 0,
    updated_at        DATETIME NULL,
    PRIMARY KEY (popup_id),
    UNIQUE KEY uq_popups_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

CREATE TABLE ka_components (
    component_id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name          VARCHAR(100) NOT NULL,
    properties     TEXT NOT NULL,
    build         MEDIUMTEXT NULL,
    build_draft MEDIUMTEXT NULL,
    kit_key        VARCHAR(80) NULL,                  -- the key it came with from a fleet design kit (2.16, Fleet\Kit): the next kit updates its draft
    updated_at        DATETIME NULL,
    PRIMARY KEY (component_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

-- Newsletter extension: subscribers signed up via the "Odběr novinek" (News subscription) element (double opt-in).
CREATE TABLE ka_subscribers (
    subscriber_id       INT UNSIGNED NOT NULL AUTO_INCREMENT,
    email     VARCHAR(190) NOT NULL,
    status      TINYINT UNSIGNED NOT NULL DEFAULT 0,       -- 0 awaiting confirmation, 1 confirmed
    token     CHAR(32)     NOT NULL,                     -- confirming and unsubscribing via a link
    source     VARCHAR(255) NOT NULL DEFAULT '',          -- the page they subscribed from
    campaign    VARCHAR(255) NOT NULL DEFAULT '',          -- utm_* of the page or of the visit (2.3)
    landing_page     VARCHAR(255) NOT NULL DEFAULT '',          -- the first page of the visit (2.3; only with consent to marketing)
    created_at     DATETIME     NOT NULL,
    confirmed_at DATETIME     NULL,
    sync       VARCHAR(10)  NOT NULL DEFAULT '',          -- mailing service: '' nothing, ceka, ok, chyba
    sync_error VARCHAR(255) NOT NULL DEFAULT '',
    PRIMARY KEY (subscriber_id),
    UNIQUE KEY uq_subscribers_email (email),
    UNIQUE KEY uq_subscribers_token (token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

-- Queue of adding and removing subscribers in the mailing service (Core\Newsletter), sent by the background cleanup.
CREATE TABLE ka_subscription_queue (
    queue_id       INT UNSIGNED NOT NULL AUTO_INCREMENT,
    email     VARCHAR(190) NOT NULL,
    action      VARCHAR(10)  NOT NULL,                      -- pridat | odebrat
    attempts    TINYINT UNSIGNED NOT NULL DEFAULT 0,
    next_attempt_at     DATETIME NULL,                             -- next attempt; NULL = given up (visible in Subscribers)
    error     VARCHAR(255) NOT NULL DEFAULT '',
    created_at DATETIME NOT NULL,
    PRIMARY KEY (queue_id),
    KEY ix_subscription_queue_next_attempt_at (next_attempt_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

-- OAuth 2.1 for the Claude connector (MCP): registered clients and one-time authorization codes (tokens are in ka_api_tokens).
CREATE TABLE ka_oauth_clients (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    client_id    CHAR(32)     NOT NULL,
    secret_hash    CHAR(64)     NOT NULL DEFAULT '',   -- sha256 client_secret; empty = public client (PKCE only)
    name        VARCHAR(100) NOT NULL DEFAULT '',
    redirect_uris TEXT         NOT NULL,              -- JSON list of allowed redirect_uri
    created_at     DATETIME     NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_oauth_clients_client_id (client_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

CREATE TABLE ka_oauth_codes (
    code_hash        CHAR(64)     NOT NULL,              -- sha256 of the code
    client_id    CHAR(32)     NOT NULL,
    user_id          INT UNSIGNED NOT NULL,
    redirect_uri VARCHAR(500) NOT NULL,
    code_challenge        VARCHAR(128) NOT NULL,              -- code_challenge (PKCE, S256)
    access       VARCHAR(10)  NOT NULL DEFAULT 'full', -- the access chosen on the consent screen
    expires_at     DATETIME     NOT NULL,
    PRIMARY KEY (code_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

-- Newsletters (1.5): one e-mail template styled by the design system (Core\Mailing). The rendered e-mail is kept from
-- the start of sending, so every subscriber gets the same content. The queue holds recipients only while sending;
-- a day after a newsletter finishes its rows are deleted and only the counts and dates remain.
CREATE TABLE ka_newsletters (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    subject      VARCHAR(200) NOT NULL,
    preheader    VARCHAR(200) NOT NULL DEFAULT '',          -- preview text shown next to the subject in the inbox
    intro        TEXT         NOT NULL,
    news_mode    VARCHAR(10)  NOT NULL DEFAULT 'latest',    -- latest | chosen | none
    news_count   TINYINT UNSIGNED NOT NULL DEFAULT 3,        -- how many of the latest news items
    news_ids     VARCHAR(500) NOT NULL DEFAULT '',          -- chosen news items (idc, comma separated)
    button_label VARCHAR(80)  NOT NULL DEFAULT '',
    button_url   VARCHAR(500) NOT NULL DEFAULT '',
    language     CHAR(2)      NOT NULL DEFAULT '',          -- language of the fixed texts and the news items (empty = the site language)
    status       VARCHAR(10)  NOT NULL DEFAULT 'draft',     -- draft | scheduled | sending | sent
    scheduled_at DATETIME     NULL,
    html         MEDIUMTEXT   NULL,                         -- the rendered e-mail, kept from the start of sending
    text         MEDIUMTEXT   NULL,
    recipients   INT UNSIGNED NOT NULL DEFAULT 0,
    sent_count   INT UNSIGNED NOT NULL DEFAULT 0,
    failed_count INT UNSIGNED NOT NULL DEFAULT 0,
    author       INT UNSIGNED NULL,
    created      DATETIME     NOT NULL,
    changed      DATETIME     NULL,
    started_at   DATETIME     NULL,
    finished_at  DATETIME     NULL,
    PRIMARY KEY (id),
    KEY ix_newsletters_status_scheduled_at (status, scheduled_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

CREATE TABLE ka_newsletter_queue (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    newsletter_id INT UNSIGNED NOT NULL,
    subscriber_id INT UNSIGNED NOT NULL,
    attempts      TINYINT UNSIGNED NOT NULL DEFAULT 0,
    next_attempt  DATETIME     NULL,                        -- NULL = done (sent or given up)
    sent_at       DATETIME     NULL,
    error         VARCHAR(255) NOT NULL DEFAULT '',
    PRIMARY KEY (id),
    UNIQUE KEY uq_newsletter_queue_newsletter_id_subscriber_id (newsletter_id, subscriber_id),
    KEY ix_newsletter_queue_next_attempt (next_attempt),
    KEY ix_newsletter_queue_sent_at (sent_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

-- Look versions (1.7, Core\Look): the published look (design system, shared classes, menus) kept before a draft look
-- was published – the last 20, each can come back into the draft.
CREATE TABLE ka_look_versions (
    id      INT UNSIGNED NOT NULL AUTO_INCREMENT,
    data    MEDIUMTEXT   NOT NULL,                  -- {design_system, classes: {name: {styl, css}}, menus: {"location|language": items}}
    summary VARCHAR(500) NOT NULL DEFAULT '',       -- what the publishing changed, in words
    author  INT UNSIGNED NULL,
    created DATETIME     NOT NULL,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

-- Webhook deliveries (1.8): log and retry queue of webhook calls (Core\Webhook).
CREATE TABLE ka_webhook_deliveries (
    id           INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    event        VARCHAR(40)       NOT NULL,
    url          VARCHAR(500)      NOT NULL,
    body         MEDIUMTEXT        NULL,             -- JSON sent; NULL after a successful delivery
    attempts     TINYINT UNSIGNED  NOT NULL DEFAULT 0,
    status       SMALLINT UNSIGNED NOT NULL DEFAULT 0, -- HTTP status of the last attempt (0 = no response)
    error        VARCHAR(255)      NOT NULL DEFAULT '',
    created      DATETIME          NOT NULL,
    next_attempt DATETIME          NULL,             -- NULL = nothing more to do (delivered or given up)
    delivered    DATETIME          NULL,
    PRIMARY KEY (id),
    KEY ix_webhook_deliveries_next_attempt (next_attempt)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

-- What happened on the site (2.8, Core\Events): one table of events that feeds alert e-mails, reports and Claude
-- (list_events). Never personal data – an enquiry event names the form and the page, not the sender.
CREATE TABLE ka_events (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    created_at DATETIME     NOT NULL,
    type       VARCHAR(40)  NOT NULL,                       -- e.g. backup.failed, enquiry.received, update.applied
    severity   VARCHAR(10)  NOT NULL DEFAULT 'info',          -- info | warning | error
    message    VARCHAR(255) NOT NULL DEFAULT '',
    data       TEXT         NULL,                           -- JSON with ids and counts, never personal data
    PRIMARY KEY (id),
    KEY ix_events_created_at (created_at),
    KEY ix_events_type_id (type, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

-- Background jobs (2.8, Core\Scheduler): when each job last ran, whether it worked, and how many times in a row it failed.
CREATE TABLE ka_jobs (
    name        VARCHAR(40)  NOT NULL,
    last_run    DATETIME     NULL,
    last_ok     DATETIME     NULL,
    last_error  VARCHAR(255) NOT NULL DEFAULT '',
    failures    INT UNSIGNED NOT NULL DEFAULT 0,
    runs        INT UNSIGNED NOT NULL DEFAULT 0,
    duration_ms INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

-- Firewall (2.8, Core\Firewall): addresses blocked for a while after probing for other systems or a manual block, and
-- the requests the firewall refused (kept 30 days – a security log).
CREATE TABLE ka_firewall_blocks (
    ip         VARCHAR(45)  NOT NULL,
    until      DATETIME     NOT NULL,
    reason     VARCHAR(40)  NOT NULL DEFAULT '',
    created_at DATETIME     NOT NULL,
    PRIMARY KEY (ip),
    KEY ix_firewall_blocks_until (until)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

CREATE TABLE ka_firewall_log (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    created_at DATETIME     NOT NULL,
    ip         VARCHAR(45)  NOT NULL,
    reason     VARCHAR(40)  NOT NULL,                      -- list | country | rate | probe | temporary
    path       VARCHAR(255) NOT NULL DEFAULT '',
    PRIMARY KEY (id),
    KEY ix_firewall_log_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

-- Fleet console (2.9, Kaleta\Fleet): a Kaleta install with the extension "fleet" keeps the sites paired with it – each
-- site's public key (it signs its heartbeat), the latest heartbeat, the uptime the console checks itself, the update ring,
-- and the site's token for relaying Claude's calls. One-time pairing codes are stored as hashes.
CREATE TABLE ka_fleet_sites (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name            VARCHAR(150) NOT NULL DEFAULT '',
    url             VARCHAR(255) NOT NULL,
    public_key      VARCHAR(64)  NOT NULL,                  -- base64 Ed25519 key of the site
    relay_token     VARCHAR(80)  NULL,                      -- the site's Claude token for the console; never shown or exported
    relay_access    VARCHAR(10)  NOT NULL DEFAULT '',       -- '' (no relay) | read | drafts | full
    relay_expires   DATETIME     NULL,
    ring            VARCHAR(10)  NOT NULL DEFAULT 'normal', -- canary | normal
    manage_updates  TINYINT(1)   NOT NULL DEFAULT 0,        -- the site lets the console decide when updates install
    update_allowed  VARCHAR(30)  NOT NULL DEFAULT '',       -- the version the console allowed the site to install
    paired_at       DATETIME     NOT NULL,
    last_seen       DATETIME     NULL,                      -- the last heartbeat
    last_ts         INT UNSIGNED NOT NULL DEFAULT 0,        -- its time stamp: an older or repeated heartbeat is refused
    heartbeat       MEDIUMTEXT   NULL,                      -- the last heartbeat (JSON)
    version         VARCHAR(30)  NOT NULL DEFAULT '',
    version_since   DATETIME     NULL,
    status          VARCHAR(10)  NOT NULL DEFAULT '',       -- the site's own health summary: ok | warning | error
    up              TINYINT(1)   NULL,                      -- the console's own check: 1 up, 0 down, NULL not checked yet
    up_status       SMALLINT     NOT NULL DEFAULT 0,        -- the HTTP status of the last check (0 = no answer)
    up_checked      DATETIME     NULL,
    up_changed      DATETIME     NULL,
    up_failures     TINYINT UNSIGNED NOT NULL DEFAULT 0,
    silent_reported TINYINT(1)   NOT NULL DEFAULT 0,        -- "stopped reporting" already recorded as an event
    PRIMARY KEY (id),
    UNIQUE KEY uq_fleet_sites_public_key (public_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

CREATE TABLE ka_fleet_pairing (
    code_hash  CHAR(64)     NOT NULL,                       -- sha256 of the one-time code
    created_at DATETIME     NOT NULL,
    expires_at DATETIME     NOT NULL,
    used_at    DATETIME     NULL,
    site_id    INT UNSIGNED NULL,
    PRIMARY KEY (code_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

-- Shared design kit of a fleet (2.16, Fleet\Kit): the console snapshots its design system, chosen classes, components and
-- saved sections into numbered versions. Member sites that opted in fetch the newest one (announced in the heartbeat reply,
-- handed over signed by the console) and apply it as drafts only. manifest = JSON {design_system, classes, components, sections}.
CREATE TABLE ka_fleet_kits (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    version    INT UNSIGNED NOT NULL,
    created_at DATETIME     NOT NULL,
    created_by INT UNSIGNED NULL,
    manifest   MEDIUMTEXT   NOT NULL,
    sha256     CHAR(64)     NOT NULL,                       -- of the manifest bytes: a site checks the kit it fetched against the announced hash
    summary    VARCHAR(255) NOT NULL DEFAULT '',            -- what it carries, in words
    PRIMARY KEY (id),
    UNIQUE KEY uq_fleet_kits_version (version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

-- Business facts (2.10, Core\Facts): typed facts the site states in many places – {{fact.key}} in pages, site parts and
-- news, schema.org, llms.txt and MCP. A language version may have its own value of a text fact. Every change of a value
-- is kept, so the old value can be found in sentences that still say it.
CREATE TABLE ka_facts (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    fact_key    VARCHAR(40)  NOT NULL,                      -- a-z, digits, _; used as {{fact.<key>}}
    language    VARCHAR(2)   NOT NULL DEFAULT '',           -- '' = the default language
    label       VARCHAR(150) NOT NULL DEFAULT '',
    type        VARCHAR(10)  NOT NULL DEFAULT 'text',       -- text | number | money | date | year | phone | email | url
    value       VARCHAR(500) NOT NULL DEFAULT '',
    schema_prop VARCHAR(40)  NOT NULL DEFAULT '',           -- a schema.org property of the organisation, e.g. foundingDate
    source      VARCHAR(255) NOT NULL DEFAULT '',           -- where the fact comes from (a note or a link)
    updated_at  DATETIME     NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_facts_fact_key_language (fact_key, language)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

CREATE TABLE ka_fact_history (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    fact_key   VARCHAR(40)  NOT NULL,
    language   VARCHAR(2)   NOT NULL DEFAULT '',
    old_value  VARCHAR(500) NOT NULL DEFAULT '',
    new_value  VARCHAR(500) NOT NULL DEFAULT '',
    changed_at DATETIME     NOT NULL,
    PRIMARY KEY (id),
    KEY ix_fact_history_fact_key_id (fact_key, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

-- Exceptions to the opening hours (2.10, Core\Hours): holidays, a closed day, shorter hours. They change "open now",
-- the hours of the day and the structured data, and show a notice bar on the site a few days ahead until they end.
CREATE TABLE ka_hours_exceptions (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    date_from   DATE         NOT NULL,
    date_to     DATE         NOT NULL,
    closed      TINYINT(1)   NOT NULL DEFAULT 1,
    hours       VARCHAR(100) NOT NULL DEFAULT '',           -- when open: 9:00-12:00, more ranges with a comma
    note        VARCHAR(150) NOT NULL DEFAULT '',           -- e.g. Christmas, inventory
    notice_days TINYINT UNSIGNED NOT NULL DEFAULT 7,        -- the notice bar this many days ahead (0 = no bar)
    proposed    TINYINT(1)   NOT NULL DEFAULT 0,            -- 1 = proposed by a drafts-only Claude connection; ignored until a person applies it (3.2)
    created_at  DATETIME     NOT NULL,
    PRIMARY KEY (id),
    KEY ix_hours_exceptions_date_to (date_to)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

-- Industry blueprints applied on the site (2.11, Core\Blueprint): manifest = the JSON with presets, facts, questions,
-- audit checks and instructions for Claude.
CREATE TABLE ka_blueprints (
    bkey       VARCHAR(40) NOT NULL,
    name      VARCHAR(100) NOT NULL DEFAULT '',
    manifest   MEDIUMTEXT NOT NULL,
    applied_at DATETIME NOT NULL,
    PRIMARY KEY (bkey)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

-- Official notice board (2.11, Core\Notices): the append-only audit trail of notices – who created or changed a notice and
-- what changed (field keys with the old and new value), and the day the board posted and took down each one. Rows are
-- never edited or deleted, and a notice is never deleted either, so the table stands on its own (no foreign key).
CREATE TABLE ka_notice_log (
    id      INT UNSIGNED NOT NULL AUTO_INCREMENT,
    item_id     INT UNSIGNED NOT NULL,                       -- the notice (ka_collection_items.item_id)
    action  VARCHAR(12)  NOT NULL,                       -- created | changed | posted | taken_down
    `at`    DATETIME     NOT NULL,
    `by`    VARCHAR(100) NOT NULL DEFAULT '',            -- user name, "Claude" or "system"
    fields  MEDIUMTEXT   NOT NULL,                       -- JSON: key => [old, new], the job writes {action: date}
    PRIMARY KEY (id),
    KEY ix_notice_log_item_id_id (item_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

-- Testimonial requests (2.12, Core\Testimonials): a personal link after an enquiry; the answer becomes a hidden draft
-- reference with the consent the customer gave. Only a hash of the token is stored.
CREATE TABLE ka_testimonial_requests (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    enquiry_id         INT UNSIGNED NULL,
    token_hash  CHAR(64) NOT NULL,
    email       VARCHAR(190) NOT NULL DEFAULT '',
    created_at  DATETIME NOT NULL,
    expires_at  DATETIME NOT NULL,
    used_at     DATETIME NULL,
    item_id     INT UNSIGNED NULL,
    consent     TEXT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_testimonial_requests_token_hash (token_hash),
    KEY ix_testimonial_requests_enquiry_id (enquiry_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

-- Outbound connectors (2.13, Core\Connectors): the outside services a site is connected to (Google, a CRM), their
-- credentials encrypted with the site's key, the queue of deliveries with retries, and a log of every call without its
-- content (enquiries carry personal data).
CREATE TABLE ka_connectors (
    service       VARCHAR(20) NOT NULL,
    account       VARCHAR(190) NOT NULL DEFAULT '',   -- what the connection is (an e-mail, a company name) for the admin
    client_id     VARCHAR(255) NOT NULL DEFAULT '',   -- the site's own OAuth app (Google), entered by an administrator
    secret        TEXT NULL,                          -- encrypted: the OAuth client secret or an API token
    access_token  TEXT NULL,                          -- encrypted
    refresh_token TEXT NULL,                          -- encrypted
    expires_at    DATETIME NULL,
    scopes        VARCHAR(500) NOT NULL DEFAULT '',
    config        TEXT NULL,                          -- JSON: what to sync where (a sheet, a location, a pipeline)
    connected_at  DATETIME NULL,
    connected_by  VARCHAR(100) NOT NULL DEFAULT '',
    last_error    VARCHAR(255) NOT NULL DEFAULT '',
    PRIMARY KEY (service)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

CREATE TABLE ka_connector_queue (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    action       VARCHAR(40) NOT NULL,                -- e.g. sheets.append, crm.lead, gbp.hours
    payload      MEDIUMTEXT NULL,                     -- JSON; emptied once delivered
    attempts     TINYINT UNSIGNED NOT NULL DEFAULT 0,
    next_attempt DATETIME NULL,                       -- NULL = done or given up
    last_error   VARCHAR(255) NOT NULL DEFAULT '',
    created_at   DATETIME NOT NULL,
    delivered_at DATETIME NULL,
    PRIMARY KEY (id),
    KEY ix_connector_queue_next_attempt (next_attempt)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

CREATE TABLE ka_connector_log (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    created_at  DATETIME NOT NULL,
    service     VARCHAR(20) NOT NULL,
    action      VARCHAR(60) NOT NULL DEFAULT '',
    status      SMALLINT UNSIGNED NOT NULL DEFAULT 0, -- the HTTP status; 0 = no answer
    ok          TINYINT(1) NOT NULL DEFAULT 0,
    ms          INT UNSIGNED NOT NULL DEFAULT 0,
    error       VARCHAR(255) NOT NULL DEFAULT '',
    PRIMARY KEY (id),
    KEY ix_connector_log_service_id (service, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

-- Whistleblowing channel (2.14, Core\Whistleblowing): reports under the EU Whistleblower Directive. The text, the contact,
-- the attachment list and every message are encrypted with the site's key; only a hash of the reporter's access code is
-- stored; no IP address anywhere. Not exported with the site, not reachable over MCP.
CREATE TABLE ka_whistleblowing_cases (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    number          VARCHAR(12) NOT NULL,              -- the case number the reporter knows: "2026-0007"
    created_at      DATETIME NOT NULL,
    status          VARCHAR(12) NOT NULL DEFAULT 'received', -- received | acknowledged | in_progress | closed
    acknowledged_at DATETIME NULL,                     -- acknowledgement of receipt (due within 7 days)
    feedback_due    DATETIME NOT NULL,                 -- created_at + 3 months
    closed_at       DATETIME NULL,                     -- closed cases are deleted after the retention period
    flood           TINYINT(1) NOT NULL DEFAULT 0,     -- received when 20 or more came in the hour before (3.3.3)
    text            MEDIUMTEXT NOT NULL,               -- encrypted
    contact         TEXT NULL,                         -- encrypted: name and contact, NULL = anonymous
    attachments     TEXT NULL,                         -- encrypted JSON: [{name, path, size}], files in storage/oznameni/
    code_hash       CHAR(64) NOT NULL,                 -- sha256 of the case number and the access code
    PRIMARY KEY (id),
    UNIQUE KEY uq_whistleblowing_cases_number (number),
    KEY ix_whistleblowing_cases_status_closed_at (status, closed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

CREATE TABLE ka_whistleblowing_messages (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    case_id    INT UNSIGNED NOT NULL,
    sender     VARCHAR(10) NOT NULL,                   -- reporter | handler
    text       TEXT NOT NULL,                          -- encrypted
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY ix_whistleblowing_messages_case_id_id (case_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

-- Search data (2.13, Core\SearchData): what Google Search Console and Bing Webmaster Tools know about the site, stored
-- once a day by the job search_data as a snapshot of the last 28 days – the top queries and pages with clicks,
-- impressions, CTR and the average position, and for Google the sitemaps (kind sitemap: key = the sitemap address,
-- impressions = pages submitted, clicks = pages indexed). Kept 16 months.
CREATE TABLE ka_search_stats (
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
    KEY ix_search_stats_engine_kind_day (engine, kind, day)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

-- Social post drafts (2.13, Core\SocialDrafts): when a news item is published, a draft per chosen network with a tracked
-- link and an image. A person edits, copies and posts it – the site never posts anywhere.
CREATE TABLE ka_social_drafts (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    news_id        INT UNSIGNED NOT NULL,
    network    VARCHAR(20)  NOT NULL,                      -- facebook | linkedin | x | instagram
    text       TEXT         NOT NULL,
    link       VARCHAR(500) NOT NULL DEFAULT '',           -- the news URL with utm_source, utm_medium, utm_campaign
    image      VARCHAR(500) NOT NULL DEFAULT '',           -- the news image or the picture the site draws (/og/…)
    created_at DATETIME     NOT NULL,
    copied_at  DATETIME     NULL,                          -- when the person marked it as posted
    PRIMARY KEY (id),
    UNIQUE KEY uq_social_drafts_news_id_network (news_id, network),
    CONSTRAINT fk_social_drafts_news_id FOREIGN KEY (news_id) REFERENCES ka_news (news_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

-- Customer reviews from the Google Business Profile (2.13, Core\GoogleBusiness): the latest reviews as Google shows them
-- publicly (display name, stars, text, the owner's reply), fetched once a day; a review gone from Google goes from here,
-- disconnecting Google empties the table. The profile's average rating and review count are in the settings
-- google_rating and google_reviews.
CREATE TABLE ka_google_reviews (
    review_id   VARCHAR(190) NOT NULL,              -- Google's reviewId
    author      VARCHAR(190) NOT NULL DEFAULT '',   -- the reviewer's public display name
    stars       TINYINT UNSIGNED NOT NULL DEFAULT 0,
    comment     TEXT NULL,
    reviewed_at DATETIME NOT NULL,
    reply       TEXT NULL,                          -- the owner's reply
    replied_at  DATETIME NULL,
    fetched_at  DATETIME NOT NULL,                  -- the last fetch that returned it
    PRIMARY KEY (review_id),
    KEY ix_google_reviews_stars_reviewed_at (stars, reviewed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

-- Agent notebook (2.15, Core\Notebook): notes for whoever works on the site next – Claude in a new conversation or a
-- colleague: decisions ("we never use the word cheap"), style rules, photo credits, the history of the redesign, what
-- the client is sensitive about. Pinned notes come first; the author is the user's name or the Claude connection name.
CREATE TABLE ka_notebook (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    topic      VARCHAR(60)  NOT NULL DEFAULT 'other',      -- decisions | style | credits | history | todo | other
    title      VARCHAR(150) NOT NULL,
    text       TEXT         NOT NULL,                      -- plain text
    pinned     TINYINT(1)   NOT NULL DEFAULT 0,
    author     VARCHAR(100) NOT NULL DEFAULT '',           -- the user's name, or the name of the Claude connection
    created_at DATETIME     NOT NULL,
    updated_at DATETIME     NOT NULL,
    PRIMARY KEY (id),
    KEY ix_notebook_topic_pinned_updated_at (topic, pinned, updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

-- Requests to Claude (2.15, Core\Requests): staff write what they need changed on the site in the administration
-- ("change the opening hours on Monday", "add this PDF to the price list"); Claude reads them over MCP, works as drafts
-- and answers with notes; a person publishes. Attachments are ordinary Media uploads (ka_media.media_id), so Claude can use
-- them. Not in the site export: a request is work for the team, not content of the site.
CREATE TABLE ka_requests (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    created_at  DATETIME NOT NULL,
    updated_at  DATETIME NOT NULL,
    author_id   INT UNSIGNED NOT NULL,                   -- ka_users.user_id of the staff member who wrote it
    title       VARCHAR(190) NOT NULL,
    text        TEXT NOT NULL,
    about       VARCHAR(500) NOT NULL DEFAULT '',        -- what it is about: page:<ids> | news:<idc> | item:<idp> | a URL | ''
    attachments VARCHAR(255) NOT NULL DEFAULT '[]',      -- JSON list of ka_media.media_id (up to 5)
    status      VARCHAR(12) NOT NULL DEFAULT 'new',      -- new | in_progress | done | declined
    done_at     DATETIME NULL,                           -- when it was marked done or declined
    PRIMARY KEY (id),
    KEY ix_requests_status_id (status, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

-- The conversation of a request: Claude's notes (with links to the drafts it made) and the person's replies.
CREATE TABLE ka_request_messages (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    request_id  INT UNSIGNED NOT NULL,
    sender      VARCHAR(10) NOT NULL,                    -- claude | person
    sender_name VARCHAR(190) NOT NULL DEFAULT '',        -- the person's name, or the name of the Claude connection
    text        TEXT NOT NULL,
    links       TEXT NULL,                               -- JSON list of {"label": …, "url": …} – the drafts Claude made
    created_at  DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY ix_request_messages_request_id_id (request_id, id),
    CONSTRAINT fk_request_messages_request_id FOREIGN KEY (request_id) REFERENCES ka_requests (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

-- Undo a whole Claude session (2.17, Core\AgentJournal): the changes one Claude connection makes in a row form a session;
-- every content row it changes is kept before and after, by its key, so the session can be taken back in one step.
-- Kept 30 days.
CREATE TABLE ka_agent_sessions (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    connection VARCHAR(100) NOT NULL,                   -- the name of the Claude connection
    started_at DATETIME NOT NULL,
    last_at    DATETIME NOT NULL,
    calls      INT UNSIGNED NOT NULL DEFAULT 0,          -- tool calls that change the site
    undone_at  DATETIME NULL,
    undone_by  VARCHAR(100) NOT NULL DEFAULT '',
    PRIMARY KEY (id),
    KEY ix_agent_sessions_connection_last_at (connection, last_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

CREATE TABLE ka_agent_journal (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    session_id INT UNSIGNED NOT NULL,
    call_no    INT UNSIGNED NOT NULL DEFAULT 0,
    tool       VARCHAR(60) NOT NULL DEFAULT '',
    tbl        VARCHAR(40) NOT NULL,
    row_key    VARCHAR(500) NOT NULL DEFAULT '',        -- JSON of the primary key; empty for an untracked write
    before_row MEDIUMTEXT NULL,                         -- JSON of the row before; NULL = the row did not exist
    after_row  MEDIUMTEXT NULL,                         -- JSON of the row after; NULL = deleted
    untracked  VARCHAR(120) NULL,                       -- a write that could not be followed row by row, and why
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY ix_agent_journal_session_id_id (session_id, id),
    CONSTRAINT fk_agent_journal_session_id FOREIGN KEY (session_id) REFERENCES ka_agent_sessions (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

-- Scheduled Claude runs (2.17, Core\AgentSchedules): the site keeps the schedules (a weekly review, a monthly report, a
-- daily triage of enquiries, the open requests, or the administrator's own instructions), tells a Claude routine what is
-- due (get_due_agent_runs), records each run (report_agent_run) and notices runs nobody picked up. The site cannot run
-- Claude by itself: the run happens in Claude, over a drafts-only connection. Not in the site export: the schedule belongs
-- to the team working on the site, not to its content.
CREATE TABLE ka_agent_schedules (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name        VARCHAR(150) NOT NULL,
    task        VARCHAR(30) NOT NULL,                    -- review | report | triage | requests | custom
    text        TEXT NOT NULL,                           -- the instructions of a custom task; optional extra for the others
    cadence     VARCHAR(10) NOT NULL,                    -- daily | weekly | monthly
    day         TINYINT UNSIGNED NOT NULL DEFAULT 1,     -- weekly: ISO weekday 1–7; monthly: day of month 1–28
    time        CHAR(5) NOT NULL DEFAULT '07:00',        -- HH:MM in the site's time zone
    active      TINYINT(1) NOT NULL DEFAULT 1,
    next_due    DATETIME NULL,                           -- the next moment a run is handed out (site time)
    last_run_at DATETIME NULL,
    created_at  DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY ix_agent_schedules_active_next_due (active, next_due)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

-- One run of a schedule: handed out (running) until the routine reports it (ok | partial | failed), or missed when nobody
-- picked it up within 6 hours. The summary and the links to the drafts are what the routine reported.
CREATE TABLE ka_agent_runs (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    schedule_id INT UNSIGNED NOT NULL,
    due_at      DATETIME NOT NULL,
    started_at  DATETIME NULL,                           -- when get_due_agent_runs handed it out
    finished_at DATETIME NULL,
    status      VARCHAR(10) NOT NULL DEFAULT 'running',  -- running | ok | partial | failed | missed
    summary     TEXT NULL,
    links       TEXT NULL,                               -- JSON list of {"label": …, "url": …}
    connection  VARCHAR(100) NOT NULL DEFAULT '',        -- the name of the Claude connection that did the run
    PRIMARY KEY (id),
    KEY ix_agent_runs_schedule_id_id (schedule_id, id),
    CONSTRAINT fk_agent_runs_schedule_id FOREIGN KEY (schedule_id) REFERENCES ka_agent_schedules (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

-- Online booking of appointments (3.0, Core\Booking): services, the people who provide them with their weekly hours and
-- days off, and the bookings themselves. A booking holds personal data: it follows the enquiry retention, Core\PersonalData
-- finds and erases it, and the site export carries the set-up only – never the bookings.
CREATE TABLE ka_booking_services (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name         VARCHAR(150) NOT NULL,
    duration_min SMALLINT UNSIGNED NOT NULL DEFAULT 30,
    buffer_min   SMALLINT UNSIGNED NOT NULL DEFAULT 0,          -- time kept free after the appointment (cleaning, notes)
    price_text   VARCHAR(60) NOT NULL DEFAULT '',               -- shown to the visitor as written; no payments
    description  VARCHAR(500) NOT NULL DEFAULT '',
    active       TINYINT(1) NOT NULL DEFAULT 1,
    requires_confirmation TINYINT(1) NOT NULL DEFAULT 0,        -- 3.3: a booking is pending until the provider accepts it
    sort_order   INT NOT NULL DEFAULT 0,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

CREATE TABLE ka_booking_staff (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name       VARCHAR(150) NOT NULL,
    email      VARCHAR(190) NOT NULL DEFAULT '',                -- gets the notifications; empty = the site e-mail
    active     TINYINT(1) NOT NULL DEFAULT 1,
    user_id    INT UNSIGNED NULL,                               -- ka_users.user_id when the person has an account
    sort_order INT NOT NULL DEFAULT 0,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

CREATE TABLE ka_booking_staff_services (
    staff_id   INT UNSIGNED NOT NULL,
    service_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (staff_id, service_id),
    KEY ix_booking_staff_services_service_id (service_id),
    CONSTRAINT fk_booking_staff_services_staff_id FOREIGN KEY (staff_id) REFERENCES ka_booking_staff (id) ON DELETE CASCADE,
    CONSTRAINT fk_booking_staff_services_service_id FOREIGN KEY (service_id) REFERENCES ka_booking_services (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

-- Weekly availability of a person: several ranges a day; a person without any row works the site's opening hours.
CREATE TABLE ka_booking_hours (
    id        INT UNSIGNED NOT NULL AUTO_INCREMENT,
    staff_id  INT UNSIGNED NOT NULL,
    weekday   TINYINT UNSIGNED NOT NULL,                        -- 1 = Monday … 7 = Sunday
    time_from CHAR(5) NOT NULL,                                 -- HH:MM
    time_to   CHAR(5) NOT NULL,
    PRIMARY KEY (id),
    KEY ix_booking_hours_staff_id_weekday (staff_id, weekday),
    CONSTRAINT fk_booking_hours_staff_id FOREIGN KEY (staff_id) REFERENCES ka_booking_staff (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

-- Days off and other exceptions: holidays, sick days; staff_id NULL = everyone.
CREATE TABLE ka_booking_off (
    id       INT UNSIGNED NOT NULL AUTO_INCREMENT,
    staff_id INT UNSIGNED NULL,
    off_from DATETIME NOT NULL,
    off_to   DATETIME NOT NULL,
    note     VARCHAR(150) NOT NULL DEFAULT '',
    PRIMARY KEY (id),
    KEY ix_booking_off_off_to (off_to),
    KEY ix_booking_off_staff_id (staff_id),
    CONSTRAINT fk_booking_off_staff_id FOREIGN KEY (staff_id) REFERENCES ka_booking_staff (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

-- The bookings. service_id and staff_id have no foreign key on purpose: a past booking keeps its history after the
-- service or the person is removed (the admin refuses to remove anyone with upcoming bookings).
CREATE TABLE ka_bookings (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    service_id    INT UNSIGNED NOT NULL,
    staff_id      INT UNSIGNED NOT NULL,
    starts_at     DATETIME NOT NULL,                            -- site time zone
    ends_at       DATETIME NOT NULL,                            -- start + the service duration (the buffer is not part of it)
    name          VARCHAR(150) NOT NULL DEFAULT '',
    email         VARCHAR(190) NOT NULL DEFAULT '',
    phone         VARCHAR(40) NOT NULL DEFAULT '',
    note          VARCHAR(1000) NOT NULL DEFAULT '',
    status        VARCHAR(10) NOT NULL DEFAULT 'confirmed',     -- pending | confirmed | declined | cancelled | no_show | done
    token_hash    CHAR(64) NOT NULL,                            -- sha256 of the customer's token (the cancel link, the .ics link)
    created_at    DATETIME NOT NULL,
    reminded_at   DATETIME NULL,
    hold_until    DATETIME NULL,                                -- pending: the time is held until then
    hold_reminded_at DATETIME NULL,                             -- pending: the provider was reminded that the hold ran out
    cancelled_at  DATETIME NULL,
    cancelled_by  VARCHAR(10) NOT NULL DEFAULT '',              -- customer | admin | claude
    source        VARCHAR(255) NOT NULL DEFAULT '',             -- the page the booking was made on; 'admin' when entered by hand
    language      VARCHAR(2) NOT NULL DEFAULT '',               -- the site language version the customer used ('' = default)
    anonymised_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_bookings_token_hash (token_hash),
    KEY ix_bookings_staff_id_starts_at (staff_id, starts_at),
    KEY ix_bookings_starts_at (starts_at),
    KEY ix_bookings_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

-- Other times the provider proposed for a pending booking (3.3); the customer picks one with the link in the e-mail.
CREATE TABLE ka_booking_proposals (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    booking_id INT UNSIGNED NOT NULL,
    starts_at  DATETIME NOT NULL,
    ends_at    DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY ix_booking_proposals_booking_id (booking_id),
    CONSTRAINT fk_booking_proposals_booking_id FOREIGN KEY (booking_id) REFERENCES ka_bookings (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;
