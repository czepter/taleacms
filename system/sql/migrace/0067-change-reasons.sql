-- Why a change was made (2.15): Claude passes a reason with a write tool – the request it answers, what the user asked
-- for – and the change log keeps it next to the change. Empty for changes made in the admin.
ALTER TABLE ka_protokol ADD COLUMN duvod VARCHAR(255) NOT NULL DEFAULT '' AFTER popis;
