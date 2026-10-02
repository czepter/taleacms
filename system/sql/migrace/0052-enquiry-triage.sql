-- Enquiry triage (2.12, Core\Triage): what an enquiry is (sales, support, job, supplier, spam, other), how urgent, a
-- drafted reply, and who sorted it – Claude, the AI assistant, a rule or a person.
ALTER TABLE ka_poptavky ADD COLUMN kategorie VARCHAR(12) NOT NULL DEFAULT '' AFTER stav,
    ADD COLUMN priorita TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER kategorie,
    ADD COLUMN navrh_odpovedi TEXT NULL AFTER priorita,
    ADD COLUMN triaged_by VARCHAR(40) NOT NULL DEFAULT '' AFTER navrh_odpovedi,
    ADD COLUMN triaged_at DATETIME NULL AFTER triaged_by,
    ADD KEY ix_poptavky_kategorie (kategorie, idp);
