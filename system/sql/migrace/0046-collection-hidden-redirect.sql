-- Where the page of a hidden or deleted collection item leads (2.10): a person who left, a product no longer sold – their old
-- address redirects (301) to e.g. the team page instead of ending in 404. Empty = 404 as before.
ALTER TABLE ka_kolekce ADD COLUMN hidden_redirect VARCHAR(255) NOT NULL DEFAULT '' AFTER detail;
