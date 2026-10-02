-- Password-protected pages (2.14, Core\PageLock): a page with a password shows a password form until the visitor enters
-- it. Only a password_hash() is kept; NULL = an ordinary public page.
ALTER TABLE ka_stranky ADD COLUMN heslo_hash VARCHAR(255) NULL AFTER noindex;
