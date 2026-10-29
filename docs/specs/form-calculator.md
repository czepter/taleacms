# Form calculator: a quick check that ends in an estimate

Status: built (October 2026). Designed in conversation on 28–29 October 2026; where the build differs from the text below, the
section "Decided during implementation" at the end says so and wins.

## Why

Small businesses want a short quiz on their site that asks a few questions and answers with a first price. The visitor gets
a number they can plan with; the owner gets a lead that already says what the person wants. Two cases drove the design:

- **A small solar installer**: five questions (roof shape, orientation, consumption, storage, wallbox) and a rough first
  quote. A north-facing, shaded roof does not get a price; it gets a different answer ("a balcony system may suit you").
- **A wedding photographer**: guests, location, start and end time, a few extras, and a budget indication. A wedding
  abroad does not get a range; it gets "we make you a personal offer".

The pattern is the established lead quiz: one question per screen, big tappable answers, a progress bar, contact details
before the result, the result as a range with a short breakdown and one clear next step. Nothing here is new to visitors;
it should feel like every good quick check they have used.

## What exists already (release 2.12)

The Form element (`Builder\Elements\Form`, `Front\Forms`) already has most of the skeleton, so this feature **extends the
Form element** and adds no second form system:

- field type `step` (multi-step form with a text counter "Step x / n" in an `aria-live` span, `image/web.js`; there is
  no progress bar yet),
- field type `estimate` with a base price and a currency; option prices written as `Label | 1200`; a number field with a
  price per unit; `Form::estimate()` adds them up; the visitor sees a running total (`data-price`, `data-estimate`),
- a single condition per field (`show_when_field` / `show_when_value`, matched by label),
- the server recomputes visibility and the estimate from the published build on `POST /form`; the enquiry, the e-mails,
  the webhook, triage and CRM delivery all follow from there.

What is missing: AND/OR conditions and comparisons, amounts that depend on conditions, multiplying, different results,
answer tiles with images or icons, a slider, a time range, a result that only the server knows, a way to try the logic
out, and numbers on where visitors drop off.

## Goals

1. An owner builds a five- to ten-question quick check with a computed result in the page builder, without code.
2. Rules maintained in the admin decide the result: if/then, all-of (AND) or any-of (OR), amounts, surcharges, factors
   and different result screens.
3. Six visual answer types: image tiles, icon tiles, slider, time range, result as a range, result with a breakdown.
4. The owner can hide the price until the visitor has left contact details; then the price never reaches the browser
   before submitting.
5. The owner sees where visitors drop out.
6. Everything the editor can do is possible through MCP, plus a tool that tries the logic with given answers.

## Non-goals (version 1)

- Score or personality quizzes ("which type are you"): there are no points, only amounts and result variants.
- A newsletter tick box on the quiz form. A form never subscribes anyone today; coupling a lead magnet to the
  newsletter raises its own consent questions (double opt-in, separate purposes). A later, separate step.
- Result screens built from builder elements. A result variant has fixed fields (below).
- Parentheses in conditions or nested rule groups. One rule is all-AND or all-OR; complex logic is several rules.
- Several result numbers per form (for example price and kWp). One number per form.
- A maximum price. A "personal offer" result variant covers the case.

## Anti-goals (a failure even if it works)

- A second form builder next to the Form element, or a "Quizzes" section in the admin menu.
- The calculation written twice: the amounts are computed by PHP only. (The visibility conditions are the one exception,
  see "Where the logic runs".)
- Prices leaking into the page in "result after contact" mode (HTML, data attributes, JSON, the step beacon).
- The confirmation e-mail turning into a relay for text the visitor typed.
- Realistic invented prices, reviews or people in defaults and starter sections (#54): placeholders in brackets.
- A cookie, a visitor id or anything personal in the drop-off statistics.

## Decisions

Decisions taken by the owner in the conversation are marked **(decided)**. Calls I made while writing are marked
**(my call)**; the builder may change those without asking.

### 1. Where it lives (decided)

- The Form element is extended. Questions stay in the existing inspector editor of the form's fields; simple amounts stay
  where they are now, at the options and number fields.
- A **"Edit logic…"** button in the form's inspector opens a large overlay in the builder for complex logic. The overlay
  has three tabs: **Rules · Results · Try it**. Its left side lists the form's questions for picking only; questions are
  not edited there.
- Leads stay in **Enquiries**. The drop-off statistics are shown in the existing **Forms** admin area.
- Draft and publish, versions, language versions, pop-ups and MCP come with the build, unchanged.

### 2. Two calculation modes (decided)

A per-form switch, form property `calculation` **(my call for the name)**:

- **Live** (`live`, the default and today's behaviour): the running total shows while the visitor answers. Prices are in
  the page, as today.
- **Result after contact** (`after_contact`): no amounts in the page. The visitor answers, leaves contact details,
  submits, and the server computes and shows the result.

Only `after_contact` supports the *multiply* action and result variants. In `live` mode the amount actions are *add* and
*quantity × amount*, evaluated in the browser on the same conditions as the visibility (prices are public there anyway).

Existing forms are `live` and behave exactly as today.

### 3. Field keys and limits (decided)

- Every field gets a stable hidden `key`, assigned by `Build::sanitize()` when missing, unique within the form, kept when
  the label changes. Rules and new conditions reference keys, never labels.
- Old label-based conditions (`show_when_field` / `show_when_value`) keep working. When the overlay opens a form that has
  them, it turns them into rules; saving stores the rules and clears the old pair **(my call)**.
- The field limit goes from 20 to **40**.
- Answer options have no keys; a rule names an option by its label. A rule that names an option or field that no longer
  exists is reported (builder check, MCP `check`) and does nothing on the site **(my call)**.

### 4. Answer types (decided: all six)

| Visual | How it is built **(my call unless noted)** |
|---|---|
| Image tiles | `radio` or `checkboxes` with `display: image_tiles`; one image per option from Media, the option label is the alt text **(decided)** |
| Icon tiles | `radio` or `checkboxes` with `display: icon_tiles`; one icon per option from the Icon element's set (`Builder\Icons`) **(decided)** |
| Slider | `number` with `display: slider`, new `min`, `max`, `step`; the current value is shown next to it |
| Time range | new field type `timerange`: a start and an end time; its numeric value is the duration in hours (an end before the start runs past midnight) |
| Result as a range | the `estimate` field in either mode, formatted by section 6 |
| Result with breakdown | the result screen in `after_contact` mode lists one line per amount that applied |

- Tiles are real radio buttons and checkboxes, styled differently, so they work without JavaScript and with the keyboard
  **(decided)**. Style and script go in `image/web.css` / `image/web.js` (`:where()`), only on pages that use them.
- The per-option image or icon is stored in a new item property `option_media` (lines, same order as the options) and
  edited in the inspector as one row per option with a picker **(my call; the builder may choose a different shape if it
  keeps MCP writable and passes `Build::sanitize`)**.
- Date fields can be tested by weekday and month in rules (section 5).

### 5. Rules (decided)

A rule is: *when* [all | any] of these conditions hold, *then* do these actions. Rules run top to bottom.

Conditions:

| Field kind | Operators |
|---|---|
| single choice, list | is, is not |
| several choices | contains, does not contain |
| number, slider, time range (hours) | greater than, less than, between |
| date | weekday is, month is |
| any field | is empty, is not empty |

Actions:

| Action | Effect |
|---|---|
| Add amount | adds a fixed amount (may be negative) |
| Quantity × amount | the value of a number, slider or time-range field times an amount |
| Multiply | multiplies the sum so far (`after_contact` only) |
| Skip question | hides a question |
| Show question only when | shows a question only if the rule holds |
| Show result variant | ends in that variant instead of the range (`after_contact` only); the first matching variant rule wins **(my call)** |

- How visibility rules combine **(my call)**: a question is hidden when any *skip* rule for it holds. If it has *show
  only when* rules, at least one of them must hold. Skip wins over show. A step whose questions are all hidden is
  skipped as a whole.
- A hidden question's answer counts as empty in every condition. Its amounts do not count **(my call)**. An old
  `show_when_value` of `*` (any answer) becomes "show only when … is not empty".
- A rule that hides or shows a question may only look at questions **before** that question, so no circle can arise.
  The validator drops what breaks this and reports it **(my call)**.
- Each amount rule has a short label that appears as its line in the breakdown ("Surcharge abroad × 1.3").
- Limits **(my call)**: 50 rules per form, 10 conditions and 5 actions per rule, 10 result variants.
- The option prices (`Label | 1200`) and the number field's price per unit stay; they count as add / quantity × amount
  lines before the rules run.

### 6. The number (decided)

- The estimate field's "currency" becomes a **unit** with a prefix or suffix. There is no site currency setting and none
  is added: the unit is set per form, empty by default; the starter section carries a placeholder **(decided)**. One
  result number per form.
- The shown result is a range: the total **minus x %** and **plus y %** (default −10 % / +15 %), **rounded to** a step
  (default 50), with an optional **minimum** ("from 1,500 €" when the total is lower).
- With 0 % / 0 % the result is one number ("about 3,400 €").
- These settings sit at the estimate field, next to the base price, in both modes **(my call)**. In `after_contact` mode
  the estimate field shows a short note in place of the running total ("You will see your estimate after sending").
- In `live` mode the running total is shown as that range too, computed in the browser. It is a few lines more next
  to add and quantity × amount, covered by the shared test file **(my call)**.
- Numbers are formatted by the page language (`Form::money()` in PHP; `Intl.NumberFormat` only for the live total).

### 7. Result variants (decided)

A variant has fixed fields: heading, text, **show the amount** yes/no, one button (text + link; the link may point to a
page with a booking element), and an optional image from Media. No nested builds. Variants are edited in the overlay's
**Results** tab. Without a matching variant rule the result is the range with its breakdown.

### 8. The visitor's flow (decided)

- One question per step (the existing `step` field), **Next** always visible. A progress bar is new work: it replaces
  the "Step x / n" counter visually and keeps the `aria-live` text. It counts only the steps on the visitor's path.
- Tapping a single-choice answer moves on by itself (per question, switch "Move on after choosing"); arrow keys only
  change the selection, they do not move on.
- The browser's back button goes one step back (history entry per step).
- Progress is kept in `sessionStorage` so a reload does not lose the answers; no cookie.
- Contact details come before the result in `after_contact` mode.
- Without JavaScript all questions are on one form, as today; the server ignores answers to questions the rules hide and
  does not require them.

### 9. Showing the result after submitting (decided)

- `POST /form` stores the enquiry and redirects (303) as today, adding a **signed result link** valid for **24 hours**.
  The page reads the stored snapshot of that enquiry and shows the range or the variant with the breakdown. It never shows
  contact details or anything the visitor typed. With JavaScript the same result appears without leaving the page.
- The token names the enquiry by its public id and the variant key, signed with HMAC and an expiry time **(my call for the
  contents)**. An expired link, a deleted or anonymised enquiry, or a changed variant shows a short "this result is no
  longer available" with the form's button.
- In `after_contact` mode the result is always shown at the form. The form's `thank_you_page` does not apply: the
  inspector hides the field in this mode with a short note, and the server ignores a stored value. Conversions are
  recorded as today **(decided)**.
- The antispam limits and checks stay exactly as they are (`Core\Antispam`, 5 per 10 minutes per IP).

### 10. E-mail (decided)

- If the form sends the visitor a confirmation, in `after_contact` mode it may include a **result block**: the range or
  variant heading, the labels of the predefined options the visitor chose, and the breakdown lines. Never free text the
  visitor typed, not even the name.
- The owner's notification gets everything, as today.

### 11. What an enquiry stores (decided)

No new column. The answers stay `[label, value]` pairs as today. Two pairs are added: **Result** (the range or the
variant heading) and **Breakdown** (one line per amount). This applies in both modes. In `live` mode the server
recomputes the numbers, as today **(my call)**. The Enquiries list, CSV export, webhook and CRM delivery work unchanged.

### 12. Drop-off statistics (decided)

- When a visitor reaches a step, the page sends a small beacon (`POST /form-step`, like the pop-up counters in
  `Front\Kernel`): form source, element id, step number. No cookie, no visitor id, no answers, no amounts. Limited per IP
  with `Antispam::count()` / `write()`.
- Daily counters per form and step in a new table **(my call: `form_steps` with source, element, step, day, reached;
  unique on the first four; `Db::upsert()`; rows older than 400 days deleted by the cron)**.
- What the counters mean **(my call)**. They are fixed once data is collected:
  - **Started** fires on the visitor's first answer in the form, not when the form renders, so pop-up impressions and
    page views do not count.
  - **Step n** is the position of the `step` field in the form's source, counted from 1 for the part before the first
    `step` field. Steps that the rules skip send nothing.
  - A form without `step` fields sends only "started".
  - **Sent** comes from the enquiries, which store `source` and `element`.
- The **Forms** admin area shows, per form and period, the share of starters that reached each step, with the step
  titles, and the number of enquiries. The weakest step is pointed out in one sentence.
- Readable over MCP **(my call: an addition to `get_stats`)**.

### 13. MCP (decided)

- Rules and results are part of the build: written with the existing `save_build` / `edit_build`, validated by
  `Build::sanitize()`, described in `builder_schema`.
- New read tool **`try_form`**: page (or pop-up/part), form element id, answers by field key, draft or published (default
  draft) → visible questions, each breakdown line, the total, the shown range and the result variant. It is the same
  computation as the **Try it** tab. Contract in `tools/contracts/mcp-tools.json`; the tool is classified by
  `Catalog::access()` as read-only.
- `tests/Unit/Mcp/ParityTest.php` gets the new admin actions (overlay save goes through the existing build actions; the
  statistics view is read-only).

## Where the logic runs

- **Amounts, totals, ranges, variants:** PHP only, in one pure class **(my call: `Builder\FormLogic`)** with no database
  access: `evaluate(fields, logic, answers) → visible, lines, total, range, variant`. `Front\Forms`, the result page, the
  overlay's Try it tab (a builder action) and `try_form` all call it.
- **Visibility (skip / show only when) and, in `live` mode, add and quantity × amount:** also in `image/web.js`, because
  the browser must hide questions while the visitor answers. This duplicates the condition evaluation only. One shared
  file of test cases (answers in, visible fields and live total out) is run by the PHP unit tests and by the browser
  suite so the two cannot drift **(my call)**.
- In `after_contact` mode the page carries the conditions needed for visibility (field keys, operators, compared values)
  but no amounts, no amount actions and no variants.

## Data shape (my call, a sketch)

New form properties next to `fields`:

```json
{
  "calculation": "after_contact",
  "logic": {
    "rules": [
      {
        "label": "Surcharge abroad",
        "match": "all",
        "when": [{ "field": "k7f2", "op": "is", "value": "Abroad" }],
        "then": [{ "do": "multiply", "by": 1.3 }]
      },
      {
        "label": "Hours",
        "match": "all",
        "when": [],
        "then": [{ "do": "quantity", "field": "k9a1", "amount": 180 }]
      },
      {
        "match": "any",
        "when": [{ "field": "k2c4", "op": "is", "value": "North" }, { "field": "k3d8", "op": "is", "value": "Heavy shade" }],
        "then": [{ "do": "result", "variant": "v1" }]
      }
    ],
    "results": [
      { "key": "v1", "heading": "[Heading]", "text": "[Text]", "show_amount": false,
        "button_text": "[Button text]", "button_link": "", "image": "" }
    ]
  }
}
```

New field item properties: `key`, `display` (`list` | `image_tiles` | `icon_tiles` for choices; `field` | `slider` for
numbers), `option_media`, `min`, `max`, `step`, `advance` (move on after choosing). The estimate field gets `unit_prefix`,
`unit_suffix`, `range_below`, `range_above`, `round_to`, `minimum`; its old `currency` is read as the unit when present.

`Build::sanitize()` validates all of it (types, limits, known keys, references, the "earlier questions only" rule) and
drops what is broken, reporting it like other broken content. Today `Build::sanitizeContent()` cleans each property on
its own and there is no element-level hook. Assigning unique keys and checking references across fields needs a new
per-element hook in `Build::sanitize()` (new work, for example a static `Element::sanitizeElement()` that `Form`
overrides). New `data-` attributes the renderer prints go into the
reserved list in `Build::ATTRIBUTE_PATTERN`.

## The admin, in short

- **Inspector (unchanged place):** the field list with the new display choice, per-option picture/icon, slider bounds,
  the time-range type, "move on after choosing"; at the estimate field the unit and range settings; at the form the
  calculation mode and the **Edit logic…** button with a one-line summary ("5 rules, 2 results").
- **Overlay, Rules tab:** the rule list in run order (drag to reorder), each rule as a readable sentence; editing a rule
  picks questions from the left list, then operator and value from what that question offers.
- **Overlay, Results tab:** the variants with their fixed fields; a preview of the default range screen.
- **Overlay, Try it tab:** answer the questions, see visible questions, each line, the total, the range and the variant,
  computed by the server from the current draft.
- Admin JS follows the CSP: no inline script, behaviour through `data-` attributes, texts through `T()`.

## Language and text

- All new UI text through `t()` / `T()` with translations in every admin and site dictionary (German formal plus the
  informal overlay; "AI", not "KI").
- Rule labels, variant texts and option labels are content: a language version of the page has its own build and so its
  own logic. Visitor-facing default texts (the "you will see your estimate after sending" note, "no longer available")
  go through `t()` and, if owners should change them, `Settings::TRANSLATED_DEFAULTS`.
- A library section **(my call)** "Quick check with estimate" with bracketed placeholders (`[Question 1]`, `[Answer 1]` …)
  and no amounts at all: the owner adds them (`[Answer 1] | 1200`).

## Mockups

https://claude.ai/artifact/4BMNxmUX4zZrQ9iUchM3fx shows the visitor's screens (question tiles, slider, time range,
extras, contact, result with breakdown, the "different result" variant on a phone). The admin boards there predate
decision 1: they show a separate "Quizzes" section, which will not exist; their content moves to the builder inspector,
the logic overlay and the Forms area.

## Left to the builder

- The overlay's exact layout and interaction, within the three tabs.
- How the result appears without a page change when JavaScript is on.
- The tile styling, within the design-system tokens and the `template` layer rules.
- Everything marked **(my call)**.
- Test layout: unit tests for `FormLogic` and the sanitizer, site tests for both modes end to end (submit, result link,
  e-mail block, expired link, no amounts in `after_contact` HTML), the step beacon, and a browser test for the overlay.

## Decided during implementation

These calls were made while building and change what an owner or builder notices. They outrank the text above.

- **Range defaults are 0 / 0 / no rounding.** A new or existing estimate field has `range_below` and `range_above` 0 and
  `round_to` 0, so a live form that already exists shows exactly the amount it showed before (section 2 outranks the
  -10 % / +15 % default of section 6). The starter section "Quick check with estimate" and the overlay's new estimate carry
  10 / 15 / 50.
- **The result pair keeps the estimate field's own label** instead of a fixed "Result" label; the Breakdown pair is added
  as specified.
- **The result arrives by the normal redirect** to the same page and anchor, also with JavaScript. There is no
  fetch-based submit: one code path, and the page the visitor sees is the same page.
- **A rule with no conditions always holds**, for visibility and amounts alike, so an unconditional amount ("hours x 180")
  can be stored and an unconditional skip hides its question.
- **A broken rule is kept and does nothing.** A condition that names an unknown field or operator marks its rule broken:
  the overlay still shows it so it can be fixed, the site and `try_form` leave it out. The same applies to a rule that
  names an option which was renamed or removed, and to a condition on a file upload (the browser sees a file name, the
  server no answer). Broken rules are listed by the builder check and the MCP `check` field (`Builder\Check`).
- **`get_stats` keeps `forms` as before** and returns the funnels in a separate key `form_steps` (form, page, steps, sent).
- **The dead-link result screen carries no `data-sent`**, so a tampered or expired link is never counted as a conversion.
  Old answers of that tab can stay in `sessionStorage`.
- **In `after_contact` mode the estimate pair is stored even when a rule hides the estimate field**, so the visitor does not
  land on "no longer available" right after sending. An owner who hides the estimate on purpose still gets the number in
  the enquiry.
- **Old step counters are pruned like `ip_checks`** (one write in 50), not by the cron.
- **Hidden fields are never kept in `sessionStorage`**: their value is the page's own (a collection item, the basket), so
  answers restored on another item page with the same form element would otherwise send the first item.
- **Only the first estimate field is the result**: a second one is rendered but never stored, so an enquiry has one
  estimate and one breakdown.
- **A slider's number is held within its `min` and `max`** on the server (`FormLogic::value`), as the browser holds it.
- **`/form-step` is off with the Enquiries feature** like `/form`, and checks the per-IP limit before it reads the build.
