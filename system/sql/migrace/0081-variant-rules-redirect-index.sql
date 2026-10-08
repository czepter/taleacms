-- Header and footer variants by content type (3.6, INV-9): besides the listed pages (stranky, which still win) a variant
-- may apply to news items, the news list, item pages of collections and pages under a parent – JSON
-- {"novinky":bool,"vypis":bool,"kolekce":[slug],"nadrazene":[page id]}, Builder\SiteParts::sanitizeRules. NULL = pages only, as before.
ALTER TABLE ka_casti ADD COLUMN pravidla TEXT NULL AFTER stranky;
-- Many redirects of a moved site (3.6, INV-10): Redirects::add rewrites every redirect that pointed to an old address – with
-- thousands of rows that lookup by target needs an index.
ALTER TABLE ka_presmerovani ADD KEY ix_presmerovani_na (na_adresu(191));
