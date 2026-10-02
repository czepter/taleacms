-- Which ready-made collection a collection was created from (2.11): events, jobs, documents, branches and notices find
-- their fields by it (Builder\Presets). Empty = a collection of its own.
ALTER TABLE ka_kolekce ADD COLUMN preset VARCHAR(30) NOT NULL DEFAULT '' AFTER hidden_redirect;
