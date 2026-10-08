-- The whistleblowing channel no longer refuses reports beyond its hourly cap (3.3.3): a script must not shut genuine
-- reporters out. A report that arrives when 20 or more came in the last hour is accepted and marked as received
-- during a flood, so the readers can tell the cases among the mass apart (Core\Whistleblowing::isFlood).
ALTER TABLE ka_whistleblowing_cases ADD COLUMN flood TINYINT(1) NOT NULL DEFAULT 0 AFTER closed_at;
