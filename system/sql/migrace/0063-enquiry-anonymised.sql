-- Anonymise instead of delete (2.14, Core\Privacy): when the retention period ends, or on the administrator's action, the
-- row of an enquiry may stay for statistics (date, form, page, topic, kind) with everything about the person blanked.
-- This is when it happened; NULL = the enquiry still holds the person's data.
ALTER TABLE ka_poptavky ADD COLUMN anonymizovano DATETIME NULL AFTER prirazeno;
