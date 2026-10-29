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

## The thank-you e-mail

The Form element's confirmation e-mail gets its own subject, text, sender name and reply-to address. Text and subject take
`{Field label}` and `{Field label|text when empty}`, plus `{site name}` and `{next steps}`. **Only answers that cannot carry
someone else's words are inserted**: a choice from the form's options, a date, a number, a time range, and a short text field
(a name) when it is at most 60 characters with no address, link or `@` in it. A longer text, an e-mail, a phone number, an
attachment or a field the visitor wrote freely is never put into an e-mail to the visitor: the form must not become a relay that
sends anyone's text to any address. An empty text keeps the old confirmation (thank-you message, result, next steps).
The notification to the owner can have its own subject with the same placeholders (every answer allowed, one line).

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
