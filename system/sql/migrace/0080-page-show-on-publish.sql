-- A page with nothing to show yet – a new page from a template, or one opened in the builder before it has text – is not
-- made visible on save; the wish to show it is kept here and applied by its first published build (3.5, Builder\Publisher).
-- Visitors never get an empty page, and it does not join the navigation before it has content. Existing pages: 0 = as before.
ALTER TABLE ka_stranky ADD COLUMN show_on_publish BOOL NOT NULL DEFAULT 0 AFTER zobrazit;
