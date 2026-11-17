# Form builder: a form is defined once and placed on pages

Status: phase 1 built (October 2026). Designed on 29 October 2026 (design canvas "Dedicated form builder", reviewed for UX the
same day). It reverses one decision of `form-calculator.md`: its anti-goal "a second form builder next to the Form element" no
longer holds. There is still one form system; the Form element moved into the form's own builder and a page only places it.

## Why

A form used to live inside one page's build. The same enquiry form on Contact, Weddings and a pop-up was three forms with three
sets of fields, recipients and thank-you texts; a change had to be made three times and the answers were split three ways. The
owner also wanted more after sending than a fixed confirmation line: a thank-you e-mail of their own, with the visitor's name and
the chosen package in it.

## Model

- **A form is a record** (table `forms`, public id): a name and a build, published (`build`) and draft (`build_draft`), with
  versions like a component (`build_revisions`, key `form:<id>`). Its build holds exactly one Form element
  (`Builder\Elements\Form`, type `form`): the fields, the logic, the e-mails and everything after sending. All of the Form
  element's code (rendering, `FormLogic`, `FormResult`, `NextSteps`, `Front\Forms`) works on that element as before.
- **The form builder** is the page builder opened on the form (`admin.php?module=forms&action=builder&id=…`, Enquiries →
  Forms): the canvas shows the form alone (`/_form/<public id>`), the inspector edits its fields, the logic overlay and
  "Try it" work as before. Saving keeps only the first Form element of the build (`Builder\FormRecords::definitionBuild`).
- **The page places a form** with the element "Form" (`Builder\Elements\FormEmbed`, type `form_embed`): which form (public id),
  a thank-you page instead of the message, the button text on this page, and a width per field. Nothing else: fields, their
  order, e-mails and spam protection belong to the form.
- **Resolving**: `FormRecords::resolve()` turns a placement into a Form element: the form's content (published; the draft on the
  builder's canvas and in a draft preview) plus the placement's overrides, under the placement's id. The page posts that id
  with its source, so enquiries, the step counter and the result link keep `source` + `element` as before;
  `Front\Forms::findElement()` resolves a placement wherever it looks for a form.
- **Enquiries** also carry `form_id`: the answers of one form, wherever it is placed, are counted together.

## A form written inside a build

A Form element inside a page, site part, pop-up, component or item template is still how a form is first *written*: a starter
section, a blueprint, Claude through MCP, an import. It renders and sends as before. **Publishing turns it into a record**
(`FormRecords::adopt`, called by every `Publisher` method): the form is created from it (published), the element is replaced by a
placement with the same id in the published build and in the draft, and the enquiries it already received get the form's id.
A form the same build adopted before (column `origin`, "page:12|abc12") is updated instead of created twice. Opening
Enquiries → Forms adopts what other ways of writing left behind (an import writes published builds directly).

## Widths

The placement's `widths` map field key → 6 (full), 4 (two thirds), 3 (half), 2 (one third) of a six-column grid. Only a form
with widths gets the grid (`tl-form-layout`, a `tl-span-*` class on each field); a phone stacks the fields. A field without an
entry is full width. The order is the form's: it drives the steps, the conditions, the e-mails and the order of answers in
Enquiries, so a page cannot change it.

## Field names

A field is posted, stored and delivered under a **technical name** that does not move. `Form::fieldName($field, $i)` is the one rule:
the field's `name` if it has one, else its stable `key` (`k` + 4 characters, assigned by `Form::sanitizeElement`), and only for a
field with neither (never in a stored build) `p<i>`. The position is never a name, so inserting, reordering or deleting fields
renames no other field. The element ids (`f-<element>-<i>`), `data-field` of a condition and the `field=<index>` of the error
redirect stay index-based: they live inside one request. The form prints the posted names in field order as `data-fields`
(JSON); `image/forms.js` uses that list for the remembered answers and for the live logic and estimate.

**Validation** (`Form::sanitizeElement`, shown by the builder at the field and returned in `errors` by MCP `save_build`/`edit_build`).
The name is trimmed and lower-cased and must match `/^[a-z][a-z0-9_-]{0,39}$/` (no `.`, space, `[` or `]`: PHP rewrites them in
`$_POST` keys). It may not be one of `Form::RESERVED_NAMES` (what the form posts itself: `source`, `element`, `link`, `back`,
`save_draft`, `seats`, the antispam fields, the attribution fields, the captcha responses, `step`, `form`, `result`, `field`) or start
with `tl_` or `as_`. A name shaped like a key (`k` + 4 characters) is accepted only as the field's own key, and a name may not be
another field's name or key; the first field keeps a duplicated name. A bad name is cleared (the field then uses its key) and
reported at `<place>.fields.<i>.name`; the field itself is never dropped and the save never fails. A newly drawn key avoids every
name asked for in the same save. The property accepts up to 80 characters, so that a name longer than 40 is reported and not cut short.

**What carries the name.** Each stored answer in `enquiries.data` that comes from a field is `[label, value, (attachment path), 'name' => …]`
(a string key, so the list positions and `isset($d[2])` stay as they were; the breakdown and the "file sent by e-mail" rows have none).
The webhook `fields[]`, MCP `list_enquiries` `fields[]` carry `name` (`''` for older enquiries), and the CSV has a last column of
the answers as `{name: value}` JSON. Anonymising keeps the name. In the e-mails `{technical name}` and `{technical name|fallback}`
work like `{Field label}`; when a name and a label clash, the name wins (it is unique and stable). The visitor-safety rule applies to
the value whichever placeholder reaches it. Connectors, rules, widths, personal-link prefill and the collection mapping still address
fields by label or key.

**Existing forms keep `p<i>`.** The migration `20261122100000_freeze_form_field_names.php` runs once and writes `name = "p<i>"`
(the index in the whole list, steps and estimates included, as `Form::fields()` counted them) into every published Form element of
`forms`, `pages` (also in the trash), `site_parts`, `popups`, `collections`, `collection_templates`, `components` (once per owner, not in
a further site's run) and `build_revisions`. A revision was published, so it keeps the names it rendered and restoring it brings them
back. A draft takes the name of the published field **with the same key** (a draft may already hold an unpublished insertion; freezing
it by its own positions would rename a published field); draft-only fields stay unnamed and use their key. After the migration there
is no flag and no legacy path: unnamed fields use their key. The migration is idempotent, and a form the owner renames
(`p1` to `email`) simply posts the new name from then on. Not frozen: saved sections, page templates, blueprints, looks, starters,
archives exported before this change and WordPress or web imports; they create new forms, which use their keys.

## The thank-you e-mail

The Form element's confirmation e-mail gets its own subject, text, sender name and reply-to address. Text and subject take
`{Field label}` (or the field's technical name, `{name}`) and `{Field label|text when empty}`, plus `{site name}` and `{next steps}`. **Only answers that cannot carry
someone else's words are inserted**: a choice from the form's options, a date, a number, a time range, and a short text field
(a name) when it is at most 60 characters with no address, link or `@` in it. A longer text, an e-mail, a phone number, an
attachment or a field the visitor wrote freely is never put into an e-mail to the visitor: the form must not become a relay that
sends anyone's text to any address. An empty text keeps the old confirmation (thank-you message, result, next steps).
The notification to the owner can have its own subject with the same placeholders (every answer allowed, one line).

## Personal links that fill the form in

A form whose access is `link` opens only from `/_f/<token>` (`Core\FormLinks`). A link may carry:

- **drafts** (`form_links.drafts`, at most 20, newest last, each `{at, by: site|person, values}`): the form shows the latest one filled
  in. The first is usually ours – `prefill`, field key => suggested answer (text, longer text, e-mail, phone, number, date, a choice,
  options to tick) – and a hint above the form says so. Never the consent (`checkbox`): neither a link nor a saved draft carries it; the
  person ticks it with the final send, and only that send records it (`form_links.consent`). The values belong to the link, never to the form, so one form serves
  many people. MCP names a field by its label or key (`create_form_link`; `update_form_link` adds our next suggestion as a new draft);
  the admin shows one input per field (Enquiries → Forms → a form → Personal links, and the link's own page to add a draft, extend or
  revoke it, with the whole history).
- **fixed**: collection field key => value the item the answer makes gets, never shown (an image, the service). A form's mapping may
  also give an image or file field a constant `media/…` path.
- **target item** (`item`): an existing item of the form's collection the person reviews ("Send for confirmation" on the item). The
  form is filled in from the item (fields the mapping takes as `{Label}` alone, and the item name). The answer becomes a hidden draft
  item; the link's `state` is `waiting` and the original item shows both side by side. Apply copies the mapped fields and the name into
  the item (its previous state is an item version) and trashes the draft; Discard trashes the draft. MCP: `review_form_answer`.
  Formatted text (`html`) goes both ways: the form shows its plain text (`CollectionSubmission::textFromHtml`), the answer becomes
  escaped paragraphs (`htmlFromText`), the comparison is by text, and Apply keeps the original formatting when the words did not change.
  The form is filled in from every mapping: a template of several answers (`{Name}, {Role}`) is read back into its parts
  (`FormLinks::readTemplate`), a date and time gives a date field its day, a time range comes back as `HH:MM-HH:MM`, a choice matches
  ignoring case. What still cannot be shown is returned as `not_prefilled`. **Apply never wipes what the person did not see**
  (`FormLinks::outcome`): an empty answer keeps the item's value for an upload field (no form shows the current file) and for a field the
  form could not fill in; a field the person saw filled in and cleared is cleared; the same day keeps a date and time's time. The
  comparison shows what Apply writes, with `kept` for the values that stay.
- **An answer that does not fit** its collection field (no number, an option the collection lacks) is never dropped:
  `CollectionSubmission::save` refuses with `field:<n>`, the form marks that field, and on a personal link the answers are kept as the
  person's draft, so the link shows them again to correct.

**Mapping a collection** (`CollectionSubmission::FILLABLE`, `UPLOADS`): text, lines, html, number, date, datetime, radio and link take
an answer, image and file an upload; a gallery, an item link and the like cannot be filled. `CollectionSubmission::mappingProblems`
names what would lose an answer (a field the form cannot fill, a field the collection lacks, a `{placeholder}` that is no form field,
form options a collection choice lacks):
in the pre-publish `check` of every MCP build write, in `try_form` (with the `item` the answers would make) and in the builder's
mapping panel.

**Saving a draft** (form option `link_save`): a "Save draft" button sends the answers without checks; they become one more draft
(by the person), the link stays open, the form then shows it and lists the history (our suggestion included); `?version=n` loads an
earlier one. Nothing reaches the site until the person sends the form.

**The page**: the plain link page uses the site's header and footer and the wrapper part `form_link`. A form may name a page of the site
(`link_page`, hidden or not) that places it with `form_embed`: the link opens that page, `{name}` and `{first name}` on it become the
person the link is for, and only the placement of this form takes the link. A used, expired or revoked link answers 410 with a kind
note and the company's public contact instead of an error page.

The collection of a form is named by its public id or by its address (slug); `list_collections` returns both.

## Phase 1 (built)

- `forms` table, `enquiries.form_id`; Enquiries → Forms lists the records with their placements and answers, creates
  ("New form", from a starter) and deletes them (refused while a page places the form).
- The form builder on the builder; publish, versions, discard as for components.
- The Form placement element with the form choice, "Edit form", thank-you page, button text and widths.
- The thank-you e-mail template; the notification subject.
- Adoption of written forms on publish and in the Forms list.
- MCP: `list_forms`, `create_form`, `delete_form`; the build tools take `form` as a target like `component`.

## Later (from the design canvas, not built yet)

- Creating a form in a dialog over the page builder, and the form builder over the page instead of a separate screen.
- Resizing fields by dragging their edge on the canvas; rows and free space shown in the inspector.
- The notice above the canvas when the placed form has unpublished changes, and "publish the form too?" when publishing the page.
- E-mail placeholders as chips linked to the field key (a renamed label breaks a `{Label}` today).
- Routing the notification by an answer, sorting and assigning the enquiry, offering the newsletter, a closing date or limit.
- Combining forms with identical fields after adoption; "Used in" and "Placed on" in the field inspector.
