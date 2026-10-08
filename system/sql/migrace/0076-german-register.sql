-- German administration in two registers (issue #20): the user's form of address, '' = formal (Sie), 'informal' = du.
-- The site texts for visitors have their own setting (german_register); the dictionaries only get an overlay file.
ALTER TABLE ka_uzivatele ADD COLUMN register VARCHAR(10) NOT NULL DEFAULT '' AFTER jazyk;
