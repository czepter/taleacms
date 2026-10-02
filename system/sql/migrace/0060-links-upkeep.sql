-- Links that look after themselves (2.14): redirects the site creates by itself for addresses visitors could not find
-- (Core\RedirectMatcher) and the broken link check extended from news items to published page builds and collection items
-- (Core\Links).
-- auto_score: NULL = made by hand or by a slug change; 0–100 = created by the daily job with this confidence (undo = delete).
ALTER TABLE ka_presmerovani ADD COLUMN auto_score TINYINT UNSIGNED NULL AFTER typ;
-- a broken link may now sit in a news item, a page build (with the element id) or a collection item: idc is the id of
-- the record of that kind, so it can no longer be a foreign key to the news table
ALTER TABLE ka_odkazy_vadne DROP FOREIGN KEY fk_odkazy_clanek;
ALTER TABLE ka_odkazy_vadne ADD COLUMN kind VARCHAR(10) NOT NULL DEFAULT 'news' AFTER ido, ADD COLUMN element VARCHAR(40) NOT NULL DEFAULT '' AFTER url;
ALTER TABLE ka_stranky ADD COLUMN links_checked DATETIME NULL AFTER zmeneno;
ALTER TABLE ka_kolekce_polozky ADD COLUMN links_checked DATETIME NULL AFTER zmeneno;
