-- What an enquiry was about (2.12, Front\EnquiryTopic): "Services – Bathroom renovation" from the collection item page the
-- form was on, the title of an ordinary page or the name of a pop-up. Looked up on the server when the form is sent; empty
-- for forms in site parts and for enquiries from before.
ALTER TABLE ka_poptavky ADD COLUMN tema VARCHAR(255) NOT NULL DEFAULT '' AFTER stranka;
