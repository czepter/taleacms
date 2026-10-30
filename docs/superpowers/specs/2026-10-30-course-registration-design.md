# Course registration: seats, confirmation, waiting list

Date: 2026-10-30 · Status: draft for review

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
- The visitor gets an e-mail for every change of their registration.
- Generic: nothing dance-specific (no lead/follow, no levels logic). Yoga, coaching, workshops use it the same way.

## Non-goals (out of scope, decided)

- Moving between sessions or courses (the "swap Tuesday for Thursday" case), per-session seat counts, attendance.
- Contracts, payment, a shop.
- Participants cancelling themselves online (no manage link, no member account).
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
registration that fits the free seats, so a single person may pass a pair.

Seats taken = the seats held by registrations in `confirmed` or `waiting` that are not in the trash and not marked spam. **This also
fixes a present bug:** `Calendar::registered()` counts enquiries marked spam (`category = 'spam'`) as seats.

Enquiries with no registration state (existing events, other forms) keep behaving as today. Repeating events keep
their per-occurrence counting and get neither confirmation nor waiting list (see "Left to the builder" for the
existing data).

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
capacity is raised. Then, while the course has not ended:

- **instant** course: the oldest `waitlisted` registration becomes `confirmed`; e-mail "you have a seat".
- **confirm-first** course: the oldest `waitlisted` registration becomes `waiting`; e-mail "a seat is free, we will
  confirm"; the holder is notified as for a new request.

Repeat until no seat is free or the list is empty. The holder may also promote any waitlisted person by hand, out of
order, as long as a seat is free.

### E-mails to the visitor

Through `Core\Mail::send()`, in the language of the page they registered on, using the site's existing mail look:
registered (instant), request received (confirm first), on the waiting list, confirmed, declined (optional message
from the holder), moved up, cancelled (optional message). Wording follows the "no invented content" rule: course
name, dates and place come from the item.

The holder is notified of new registrations as forms already do (form recipients); a waiting request says so in the
subject.

### Admin

- On the course item in Collections: a **Registrations** panel – seats taken / capacity, then the list grouped by
  state (waiting, confirmed, waiting list in order, declined/cancelled collapsed), each row with name, e-mail, date
  and the actions that apply: Confirm, Decline, Cancel, Move up. Decline and Cancel ask with `data-confirm` and allow
  an optional message to the visitor.
- In Enquiries, a registration shows its state as a label next to the existing status; the actions live on the
  course, not in Enquiries.
- Every action writes `Cache::clear()` (seats left is on public pages) as admin POSTs already do.

### MCP (parity rule)

- The existing item result's `registration` block gains `mode`, `waiting_list`, `taken`, `waiting`, `waitlisted`.
- `list_registrations(item)` – registrations of a course with state and public id.
- `update_registration(registration, action: confirm|decline|cancel|promote, message?)` – the same as the admin
  buttons, same e-mails. Listed in `tools/contracts/mcp-tools.json` and the `$parity` map.

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

**Price** is shown, never charged: the existing `price` field (number, feeds the structured data) stays. Contract and
payment remain on site.

Events gets the registration mode, waiting list and seats-per-registration fields too, so a one-off workshop can use
them.

Placeholders for templates: `{{course_status}}`, `{{places_left}}` (existing), `{{waiting_list}}` (empty, or that
the course is full and the waiting list is open).

## Constraints (project rules that bite here)

- Schema change = a Phinx migration; portable SQL (MySQL, PostgreSQL, SQLite); booleans `= TRUE/FALSE`.
- UI text through `t()`, translations for every language (`tools/add-translations.py`).
- Admin CSP: no inline script; behaviour via `data-` attributes in `image/admin.js`.
- Public ids only in admin URLs and MCP.
- No invented content in the preset (placeholders in brackets).
- The page cache: seats left must not go stale for anonymous visitors.

## Calls made while writing this (review these)

Settled by the author of this document, not by the owner:

- **Link to the course:** an item reference on the enquiry, backfilled from `page`, replaces the `LIKE` match on the
  path. Language versions of a course share its seats, as today.
- **Existing registrations:** a migration marks every enquiry sent from a calendar item page `confirmed`, so current
  events keep their numbers.
- **No e-mail field:** the preset's form has a required e-mail field. A course form without one still works; the
  Registrations panel warns that the visitor gets no e-mails. The visitor address is `enquiries.email`.
- **Auto-reply:** on a course page the registration e-mails replace the form's own auto-reply (no double mail).
- **Spam, trash, restore:** every path that marks spam or trashes a holding registration frees its seat and runs
  promotion (one shared routine). Restoring or un-spamming takes a seat if one is free, otherwise the registration
  goes to the end of the waiting list (or `cancelled` when the waiting list is off). Never overbooks.
- **Settings changed while registrations exist:** confirm → instant leaves `waiting` rows for the holder to confirm;
  waiting list off keeps existing `waitlisted` rows (holder can promote or cancel) but takes no new ones; capacity
  lowered below seats taken bumps nobody, promotion waits until a seat is free.
- **Thank-you texts:** new result codes in `Front\Forms` and the Form element (registered, request, waitlisted)
  next to `full` and `closed`; the texts are translated defaults.
- **Ended courses:** the list uses the existing "upcoming" period (not ended yet), which already includes running
  courses. Cache expiry around a course's start or end follows whatever Events does today.

## Left to the builder

- Where the registration state is stored (a column on `enquiries` is the obvious choice) and its name.
- How existing registrations (enquiries from event pages before this change) are treated: count them as `confirmed`
  or leave them stateless-but-counted. Either is fine as long as current events keep their numbers.
- The locking used for the last seat (`Db::lock()` exists).
- Exact admin layout of the Registrations panel, within the existing admin list styles.
- Exact e-mail texts.
- Tests: unit tests for the state transitions and seat count, a site test for register → full → waitlist → cancel →
  auto-promotion, both modes.
