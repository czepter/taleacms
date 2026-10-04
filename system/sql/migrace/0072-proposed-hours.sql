-- A proposed exception to the opening hours (3.2): a Claude connection limited to drafts saves the exception as a proposal.
-- The site ignores it – the hours, the notice bar, the structured data, the Google Business Profile and the door sign –
-- until a person applies it in the administration (Core\Hours::apply).
ALTER TABLE ka_hours_exceptions ADD COLUMN proposed TINYINT(1) NOT NULL DEFAULT 0 AFTER notice_days;
