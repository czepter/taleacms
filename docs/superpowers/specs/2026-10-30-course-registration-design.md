# Course registration: seats, confirmation, waiting list

Date: 2026-10-30 · Status: reviewed, awaiting approval

## Intent

A dance school (equally a yoga studio or a coach) runs **courses**: a fixed series of sessions (say ten Tuesdays at
19:00) that people join as a whole. A visitor wants to see which courses – running ones or ones starting in November –
still have a seat, and take one. The contract and payment happen on site; the website only reserves the seat.

Talea already does most of the "join" part: an Events collection item without repetition, with a capacity and a
registration form, counts registrations and closes the form when full. What is missing is a lifecycle for a
registration (confirm, decline, cancel), a waiting list, and a preset that reads as a course.

## Goals

- A visitor sees every course that has not ended, with "starts 4 Nov" or "running since 7 Oct", the schedule and the
  seats left, and registers from the course page.
- Per course, the holder chooses **instant** (registering takes the seat) or **confirm first** (the registration is a
  request that holds a seat until the holder confirms or declines it).
- A full course offers a **waiting list** instead of closing. A freed seat moves the first person up.
- The holder cancels, confirms, declines and promotes registrations in the admin and over MCP.
- The holder can cancel a whole course and offer up to three other courses instead.
- The visitor gets an e-mail for every change of their registration.
- Every text a visitor or customer reads, in an e-mail or on the site, can be changed by the owner, starting from a
  template. This covers the existing appointment bookings too.
- Generic: nothing dance-specific (no lead/follow, no levels logic). Yoga, coaching, workshops use it the same way.

## Non-goals (out of scope, decided)

- Moving between sessions or courses (the "swap Tuesday for Thursday" case), per-session seat counts, attendance.
- Contracts, payment, a shop.
- Participants cancelling themselves online (no manage link, no member account). The one personal link a
  participant ever gets is the take-over link of a cancelled course (see below).
- Automatic decline of requests nobody answered.
- Confirmation and waiting list for repeating (drop-in) events.
- A CSV export of participants.
- Per-collection or per-service overrides of the texts (one site-wide set; add overrides only when a site needs them).
- Changing the notifications to the business (new booking, new request, reminder to the holder).
- Member login is not involved.

## Anti-goals

- **It must not become a CRM.** No customer records beyond the registration (which is an enquiry), no history per
  person, no notes system of its own, no participant profiles.
- No new table of people. A registration is an enquiry.

## Decided behaviour

### Course

A course is a collection item of a calendar collection (one that maps `start`, `end`, `capacity`,
`registration_until` through `Core\Calendar`) **without repetition**: `start` = the first session, `end` = the last
session.

- Registration is open until `registration_until`; empty = until the end of the last session (current behaviour of
  `Calendar::registrationState`). Joining a running course is therefore allowed by default.
- New item field **Registration**: `instant` | `confirm` (default `instant`).
- New item field **Seats per registration**: see below (default 1).
- New item field **Waiting list**: on | off (default on). *(Builder's call recorded here: the owner asked for a waiting
  list; making it switchable per course is my call, default on.)*

### Registration states

A registration is an enquiry sent from the course page (as today: `source = collection:<id>`, `page` = the item path).
It gets one new state, separate from the enquiry's processing `status` (new / read / resolved):

| State | Holds a seat | How it is reached |
|---|---|---|
| `confirmed` | yes | instant course; or the holder confirms; or auto-promotion on an instant course |
| `waiting` | yes | confirm-first course; or auto-promotion on a confirm-first course |
| `waitlisted` | no | the course was full when they registered |
| `declined` | no | the holder declines a `waiting` registration |
| `cancelled` | no | the holder cancels a `confirmed` or `waiting` registration, or removes someone from the waiting list |

**Seats per registration (owner: depends on configuration):** a per-course item field **Seats per registration**
(maximum, default 1), independent of instant/confirm. At 1 every registration holds one seat. Above 1 the form asks
for the number of participants (1 up to the maximum, never more than the capacity); the registration holds that many
seats and is confirmed, declined, cancelled and promoted as a whole. *(Author's calls:)* a registration for more seats
than are free goes to the waiting list as a whole (no partial seats); promotion moves up the oldest waitlisted
registration that fits the free seats, so a single person may pass a pair. The pair keeps its place at the top and
moves up as soon as two seats are free. The participants field shows whenever the maximum is above 1, in both modes.

**One registration per address and course:** a registration whose e-mail already has a `confirmed`, `waiting` or
`waitlisted` registration for the same course is refused with a message that they are already registered (or on
the waiting list). A `declined` or `cancelled` one does not block a new registration.

Seats taken = the seats held by registrations in `confirmed` or `waiting` that are not in the trash and not marked spam. **This also
fixes a present bug:** `Calendar::registered()` counts enquiries marked spam (`category = 'spam'`) as seats.

Enquiries with no registration state (existing events, other forms) keep behaving as today. Repeating events keep
their per-occurrence counting and get neither confirmation nor waiting list (existing data: see "Calls made while
writing this").

### Visitor flow

1. Course page shows schedule, start/end, "starts …" / "running since …", seats left.
2. Seats free → normal registration form → thank-you text says either "you are registered" (instant) or "we have
   your request and will confirm it" (confirm first).
3. Course full, waiting list on → the form stays, with a note "This course is full – join the waiting list" and a
   button "Join the waiting list"; thank-you text says they are on the waiting list.
4. Course full, waiting list off, or registration closed → the form is replaced by the current "full" / "closed"
   message.

The seat check at submit time decides the state (the page may be stale or cached): a registration that arrives after
the last seat went becomes `waitlisted` (or is refused with the "full" message when the waiting list is off). Two
submissions for the last seat must not both get it.

### Freed seat

A seat frees when a holding registration is declined, cancelled, moved to the trash or marked spam, or when the
capacity is raised. Then, while registration is still open (before `registration_until`, or before the end when it
is empty):

- **instant** course: the oldest `waitlisted` registration becomes `confirmed`; e-mail "you have a seat", which
  asks them to tell the school if they no longer want it.
- **confirm-first** course: the oldest `waitlisted` registration becomes `waiting`; e-mail "a seat is free, we will
  confirm"; the holder is notified as for a new request.

Repeat until no seat is free or nobody on the list fits. After `registration_until` nothing moves up by itself; the
holder may still promote by hand. The holder may promote any waitlisted person by hand, out of order, at any time, as
long as enough seats are free.

### Unanswered requests

A `waiting` registration nobody confirmed or declined for 3 days sends the holder one reminder (same recipients as
the request). Nothing is declined automatically; the seat stays held.

### E-mails to the visitor

Through `Core\Mail::send()`, plain text as all Talea mails, in the language of the page they registered on:
registered (instant), request received (confirm first), on the waiting list, confirmed, declined (optional message
from the holder), moved up, cancelled (optional message), course cancelled (with the take-over links). Each one is a
customisable message (below). Course name, dates and place come from the item.

The holder is notified of new registrations as forms already do (form recipients); a waiting request says so in the
subject. The form's recipients also get the reminder for unanswered requests.

### Cancelling a course

One action on the Registrations screen, **Cancel the course**, for when the course will not take place:

1. The holder picks up to 3 other courses of the same collection to offer (only courses still open for
   registration are listed; none is fine) and may add a message.
2. On confirm: every `confirmed`, `waiting` and `waitlisted` registration becomes `cancelled`; the course item is
   hidden; its address redirects to the first offered course, or to the collection list when none was offered
   (through the collection's existing handling of hidden items); one e-mail goes to each of those registrations.
3. The e-mail says the course is cancelled, carries the holder's message and, per offered course, a personal button
   "Take a seat in [course]". The link is signed and single use, tied to the registration and the offered course.
4. Clicking it shows a page with a button (the GET never registers). Pressing it creates a new registration in the
   offered course with the same name, e-mail and seat count, under that course's rules: a seat if one is free
   (`confirmed` or `waiting` by its mode), otherwise its waiting list, otherwise the "full" message. No priority over
   people already on that course's waiting list; they are ordered by when they press the button. The usual
   registration e-mail follows. Once used for one course, the other buttons in that e-mail stop working.
5. A link stops working when the offered course closes for registration or was itself cancelled, and after 30 days.
   The page says why and links to the course list.

The action cannot be undone; it asks with `data-confirm`. Over MCP: `cancel_course(item, offer?: [item…], message?)`.

### Admin

- **Permissions:** whoever may edit the collection may handle its registrations and cancel a course.
- The collection's item list gets a **Seats** column for calendar collections ("13 of 16", "Full · 3 waiting",
  a mark when requests wait) that links to the course's **Registrations** screen. The item edit screen links there
  too. It is a screen of its own, not a tab: the item edit screen stays as it is.
- The Registrations screen: seats taken / capacity, then the list grouped by state (waiting, confirmed, waiting list
  in order, declined/cancelled collapsed), each row with name, e-mail, seats, date and the actions that apply:
  Confirm, Decline, Cancel, Move up, Remove from the waiting list. Decline and Cancel ask with `data-confirm` and
  allow an optional message to the visitor. Header actions: **Print list** and **Cancel the course**.
- **Print list:** a print view of the confirmed participants only (name, e-mail, phone if the form has it, seats, a
  blank column for ticking attendance by hand), course name, schedule and place on top. Print CSS, no export file.
- In Enquiries, a registration shows its state as a label next to the existing status; the actions live on the
  course, not in Enquiries.
- Every action writes `Cache::clear()` (seats left is on public pages) as admin POSTs already do.

### MCP (parity rule)

- The existing item result's `registration` block gains `mode`, `waiting_list`, `taken`, `waiting`, `waitlisted`.
- `list_registrations(item)` – registrations of a course with state and public id.
- `update_registration(registration, action: confirm|decline|cancel|promote, message?)` – the same as the admin
  buttons, same e-mails.
- `cancel_course(item, offer?, message?)` – as the admin action.
- All listed in `tools/contracts/mcp-tools.json` and the `$parity` map (the print view is "admin: print").

### Courses preset

The preset **"Courses and training" already exists** (`system/presets/courses.php`: start, end, place, price,
capacity, description, image; structured data Event with the price; registration form; list of upcoming courses).
This work **extends it**, no new preset: add `schedule` (text, e.g. "Tuesdays 19:00–20:30"), `sessions` (number),
`level` (text, used as list filter), `registration_until`, `registration` (instant/confirm), `waiting_list`,
`seats_per_registration`; map the new ones in its `calendar` block; put price, schedule and seats left on the card.
The list keeps its "upcoming" period, which already includes running courses.

Fields like level, style or teacher are ordinary collection fields: the preset brings sensible defaults and the
owner adds, renames or removes fields per collection as with any collection (`Collections::FIELD_TYPES`). Nothing in
the registration logic depends on them. Only the fields mapped in the `calendar` block (start, end, capacity,
registration until, registration mode, waiting list, seats per registration) drive behaviour.

**Protected fields (owner: yes).** In Collection settings → Item fields, every field the `calendar` block maps
carries a tag "Used by registration" and a lock in place of removal: its label may be renamed and its help, width
and order changed, but it cannot be removed, nor its type or key changed, while the mapping exists. The form says so
in one line under the list. Fields without a mapping (level, style, teacher …) behave as today. The save refuses a
request that removes a mapped field (MCP `update_collection` too), with a message naming the field.

**Price** is shown, never charged: the existing `price` field (number, feeds the structured data) stays. Contract and
payment remain on site.

Events gets the registration mode, waiting list and seats-per-registration fields too, so a one-off workshop can use
them.

Placeholders for templates: `{{course_status}}`, `{{places_left}}` (existing), `{{waiting_list}}` (empty, or that
the course is full and the waiting list is open).

## Customisable messages

Every text a visitor or customer reads is a **message** with a template the owner may change: course registration
and appointment booking alike.

- **Which:** the visitor e-mails (subject and body) of courses (above) and of bookings (booked, request received,
  declined, other times proposed, cancelled, reminder), and the texts on the site: the thank-you texts (registered,
  request, waiting list), "full – join the waiting list", "registration closed", "already registered", the take-over
  page, and the booking pages (booked, request sent, cancel page).
- **Where:** one site-wide set in Settings → **Messages**, grouped Bookings / Courses. Every field shows the current
  text, pre-filled with the template; "Back to the template" empties it, "Send me a test" mails it to the signed-in
  user with sample values. The Courses group shows only when a calendar collection exists, Bookings only when the
  bookings extension is on.
- **Format:** plain text; e-mails have a subject and a body. Placeholders use the form e-mail syntax of
  `Front\FormMail` (`{name}`, `{name|there}`), each message with its own list shown under the field: `{name}`,
  `{course}`, `{dates}`, `{schedule}`, `{place}`, `{seats}`, `{message}`, `{site name}`, `{appointment}`,
  `{service}`, `{times}` and the links `{cancel link}`, `{calendar link}`, `{take-over links}`. An unknown
  placeholder is refused on save. A message whose link placeholder was removed gets the link added at the end
  when sent; the field's help says so. Nothing a visitor typed goes into a mail to a visitor except a name
  (`FormMail::isName`); `{message}` is the holder's own text.
- **Languages:** one text per language version; empty = the translated template of that language (as
  `maintenance_text` today). A mail goes out in the language the person booked or registered in.
- **Existing sites:** the templates reproduce today's booking texts, so nothing changes until someone edits one.
- **Storage and MCP:** stored as settings (a key per message and part, `_<language>` for language versions), so
  `site_info` and `update_settings` cover them (parity). The save checks the placeholders, also over MCP.

## Constraints (project rules that bite here)

- Schema change = a Phinx migration; portable SQL (MySQL, PostgreSQL, SQLite); booleans `= TRUE/FALSE`.
- UI text through `t()`, translations for every language (`tools/add-translations.py`).
- Admin CSP: no inline script; behaviour via `data-` attributes in `image/admin.js`.
- Public ids only in admin URLs and MCP.
- No invented content in the preset (placeholders in brackets).
- The page cache: seats left must not go stale for anonymous visitors.

## Decided in review (2026-10-30)

Registrations on their own screen (Q1); a reminder after 3 days, no automatic decline (Q2); a pair keeps its place
until two seats are free (Q3); nothing moves up by itself after "Registration until" (Q4); the participants field
whenever the maximum is above 1 (Q5); one registration per address and course (Q6); repeating events excluded (Q7);
automatic promotion on instant courses, the e-mail asks to say if the seat is no longer wanted (Q8); permissions
follow the collection (Q9); the form's recipients get the holder's mails (Q10); a print view, no CSV (Q11);
cancelling a course with up to three offered courses and a one-click take-over, no priority, the cancelled course
hidden and redirected (Q12–Q16); every visitor-facing text of courses and bookings customisable from a template,
site-wide, per language, links re-added when removed, stored as settings (Q17–Q22).

## Calls made while writing this (review these)

Settled by the author of this document, not by the owner:

- **Link to the course:** an item reference on the enquiry, backfilled from `page`, replaces the `LIKE` match on the
  path. Language versions of a course share its seats, as today.
- **Existing registrations:** a migration marks every enquiry sent from a calendar item page `confirmed`, so current
  events keep their numbers.
- **No e-mail field:** the preset's form has a required e-mail field. A course form without one still works; the
  Registrations screen warns that the visitor gets no e-mails. The visitor address is `enquiries.email`.
- **Auto-reply:** on a course page the registration e-mails replace the form's own auto-reply (no double mail).
- **Spam, trash, restore:** every path that marks spam or trashes a holding registration frees its seat and runs
  promotion (one shared routine). Restoring or un-spamming takes a seat if one is free, otherwise the registration
  goes to the end of the waiting list (or `cancelled` when the waiting list is off). Never overbooks.
- **Settings changed while registrations exist:** confirm → instant leaves `waiting` rows for the holder to confirm;
  waiting list off keeps existing `waitlisted` rows (holder can promote or cancel) but takes no new ones; capacity
  lowered below seats taken bumps nobody, promotion waits until a seat is free.
- **Thank-you texts:** new result codes in `Front\Forms` and the Form element (registered, request, waitlisted,
  already registered) next to `full` and `closed`; the texts are customisable messages.
- **Ended courses:** the list uses the existing "upcoming" period (not ended yet), which already includes running
  courses. Cache expiry around a course's start or end follows whatever Events does today.

## Left to the builder

- Where the registration state is stored (a column on `enquiries` is the obvious choice) and its name.
- The locking used for the last seat (`Db::lock()` exists).
- Exact admin layout of the Registrations screen, within the existing admin list styles.
- Exact template texts for the new course messages (the booking templates are today's texts).
- Tests: unit tests for the state transitions and seat count, a site test for register → full → waitlist → cancel →
  auto-promotion, both modes, and cancel course → take-over link.
- Where the take-over token lives (a hash on the cancelled registration, as member links store sha256) and how the
  reminder runs (the existing job runner).
