-- Collection categories (3.7, Builder\CollectionCategories): a collection gets categories in two levels with their own
-- landing pages (/<collection>/<category>, /<collection>/<category>/<subcategory>), items belong to one or more of them,
-- and the category page is drawn by the collection's category template from the builder. New tables only – nothing an
-- existing site has changes, and a collection without categories behaves as before.
CREATE TABLE ka_collection_categories (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    idk        INT UNSIGNED NOT NULL,                   -- the collection (ka_kolekce)
    parent_id  INT UNSIGNED NULL,                       -- the top-level category of a subcategory; NULL = a top-level category
    image      VARCHAR(255) NOT NULL DEFAULT '',        -- a path in Media or an https address
    sort_order INT NOT NULL DEFAULT 100,
    visible    TINYINT(1) NOT NULL DEFAULT 1,           -- a hidden category has no page and is in no list (a draft from a drafts-only connection)
    updated_at DATETIME NULL,
    PRIMARY KEY (id),
    KEY ix_collection_categories (idk, parent_id, sort_order),
    KEY ix_collection_categories_parent (parent_id),
    CONSTRAINT fk_collection_categories_collection FOREIGN KEY (idk) REFERENCES ka_kolekce (idk) ON DELETE CASCADE,
    CONSTRAINT fk_collection_categories_parent FOREIGN KEY (parent_id) REFERENCES ka_collection_categories (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

CREATE TABLE ka_collection_category_texts (
    category_id     INT UNSIGNED NOT NULL,
    language        CHAR(2) NOT NULL DEFAULT '',        -- '' = the default language
    idk             INT UNSIGNED NOT NULL,              -- the collection once more: the address is unique within it and the language
    name            VARCHAR(200) NOT NULL,
    slug            VARCHAR(160) NOT NULL,
    description     MEDIUMTEXT NOT NULL,                -- formatted text (HTML through the allow-list, WpContent::safeHtml)
    seo_title       VARCHAR(200) NOT NULL DEFAULT '',   -- custom <title>, empty = the name
    seo_description VARCHAR(300) NOT NULL DEFAULT '',   -- meta description, empty = the beginning of the description
    PRIMARY KEY (category_id, language),
    UNIQUE KEY ux_collection_category_slug (idk, language, slug),
    CONSTRAINT fk_collection_category_texts FOREIGN KEY (category_id) REFERENCES ka_collection_categories (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

-- Which categories an item belongs to (one or more; an item row is one language version, so is its assignment).
CREATE TABLE ka_collection_item_categories (
    idp         INT UNSIGNED NOT NULL,
    category_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (idp, category_id),
    KEY ix_collection_item_categories (category_id),
    CONSTRAINT fk_collection_item_categories_item FOREIGN KEY (idp) REFERENCES ka_kolekce_polozky (idp) ON DELETE CASCADE,
    CONSTRAINT fk_collection_item_categories_category FOREIGN KEY (category_id) REFERENCES ka_collection_categories (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

-- The category template of a collection from the builder (3.7), per language ('' = the default language; a language
-- without its own row uses the default one, and without any row the built-in default template draws the page).
CREATE TABLE ka_collection_category_templates (
    idk            INT UNSIGNED NOT NULL,
    jazyk          CHAR(2) NOT NULL DEFAULT '',
    stavba         MEDIUMTEXT NULL,
    stavba_koncept MEDIUMTEXT NULL,
    zmeneno        DATETIME NULL,
    PRIMARY KEY (idk, jazyk),
    CONSTRAINT fk_collection_category_templates FOREIGN KEY (idk) REFERENCES ka_kolekce (idk) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;
