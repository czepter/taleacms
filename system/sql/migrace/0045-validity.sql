-- True until and review by (2.10, Core\Validity): content that is only true for a while hides itself the day after
-- valid_until (a page and a collection item get zobrazit = 0, a news item visible = 0, a pop-up aktivni = 0 – written to
-- the change log and recorded as the event content.expired), and content with review_by asks for a check on that day
-- (the site audit, kind review, and the event content.review). Empty = always true / no review.
ALTER TABLE ka_stranky ADD COLUMN valid_until DATE NULL AFTER zverejnit_od;
ALTER TABLE ka_stranky ADD COLUMN review_by DATE NULL AFTER valid_until;
ALTER TABLE ka_novinky ADD COLUMN valid_until DATE NULL AFTER oznameno;
ALTER TABLE ka_novinky ADD COLUMN review_by DATE NULL AFTER valid_until;
ALTER TABLE ka_kolekce_polozky ADD COLUMN valid_until DATE NULL AFTER zverejnit_od;
ALTER TABLE ka_kolekce_polozky ADD COLUMN review_by DATE NULL AFTER valid_until;
ALTER TABLE ka_popupy ADD COLUMN valid_until DATE NULL AFTER aktivni;
ALTER TABLE ka_popupy ADD COLUMN review_by DATE NULL AFTER valid_until;
