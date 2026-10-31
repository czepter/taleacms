# Course Registration Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Courses whose seats are taken instantly or after confirmation, with a waiting list, admin and MCP handling,
cancelling a whole course with a one-click take-over into another course, and every visitor-facing text of courses
and bookings customisable from a template.

**Architecture:** A registration stays an enquiry: new columns on `enquiries` (item, seat state, seats, language, offer
token) and one class `Core\Registrations` that owns seat counting, placing, transitions, promotion and course
cancellation; every path that changes a registration (form submit, admin, MCP, spam, trash, restore) calls it.
`Core\Messages` owns the message catalogue (templates built from existing `t()` strings), per-language overrides
stored as settings, placeholder checking and filling; courses and bookings both send through it.

**Tech Stack:** PHP 8.5 (no framework), Phinx, PHPUnit/ParaTest, MySQL 8 / PostgreSQL 18 / SQLite, server-rendered HTML.

**Spec:** `docs/superpowers/specs/2026-10-30-course-registration-design.md`
**Design:** https://claude.ai/artifact/V6YKfKLCxNzdZLD23zB6mJ (boards named in each task)

## Global Constraints

- English everywhere in code, identifiers, comments, tests, docs. UI text through `t('English')`; add translations to every dictionary with `tools/add-translations.py` (German formal + `-du`; "AI", not "KI"). Site and e-mail texts in the site dictionaries, admin texts in `admin-*.php`.
- Admin CSP `script-src 'self'`: no inline scripts, no `on*=`; behaviour via `data-` attributes in `image/admin.js`. No `window.confirm()`: `data-confirm`.
- Portable SQL (MySQL 8, MariaDB 11.4, PostgreSQL 18, SQLite 3.40): no `IF()`, `IFNULL`, `ON DUPLICATE KEY`, `GROUP_CONCAT`, `JSON_EXTRACT`; booleans `= TRUE`; `NOW() - INTERVAL 3 DAY` is fine.
- Schema change only as a Phinx migration in `system/database/migrations/` (table DSL, no `COLLATE`/`CHARSET`); index names `ix_<table>_<cols>`.
- Public ids (UUID) in admin URLs, MCP arguments/results and token pages; integers stay in the database layer (`Mcp\PublicIds` maps).
- No invented content: preset and template defaults use bracketed placeholders (`[price]`, `[Teacher’s name]`).
- Nothing a visitor typed goes into a mail to a visitor except a name (`FormMail::isName`); `{message}` is the holder's own text.
- Every new admin `action*` method gets a `$parity` entry in `tests/Unit/Mcp/ParityTest.php`; every new MCP tool gets `Catalog::TOOLS`, `PublicIds` and `tools/contracts/mcp-tools.json` entries (`RUN php tools/contracts.php --update`).
- New front routes go into `tools/contracts/routes.json` (`RUN php tools/contracts.php --update`).
- It must not become a CRM: no people table, no history per person, no notes system.
- PHP, Composer, PHPUnit and `php tools/…` run only in the dev container: `docker compose -f docker-compose-dev.yaml run --rm web <command>` (on the Colima Mac through `colima ssh -- sh -c "cd '<worktree>' && …"`). Below, `RUN` stands for that prefix. Site tests: `--processes 2`, CI image `talea-ci-local` (see `tests/Site/README.md`).
- Before every commit: `RUN php tools/sql-dialect-scan.php` reports 0 lines; `php tools/check-english.php` exits 0 (run in `talea-ci-local`, it needs git).
- Branch `feat/course-registration`; no push until the whole plan is done; commit messages end with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.

## Decisions this plan adds to the spec

Facts found in the code that the spec got wrong or left open; each is the plan's call.

1. **The calendar mapping is the preset's**, not stored per collection (`Presets::of($collection)['calendar']`,
   `Calendar::ROLES`). New roles `registration`, `waiting_list`, `seats_per_registration` are added to `Calendar::ROLES`
   and mapped in the `courses` and `events` presets. A custom collection without a preset has no registration features
   (as today).
2. **Redirect of a cancelled course** goes through the site redirects (`Admin\Modules\Redirects::add`), one per item,
   not `collections.hidden_redirect` (which is one value per collection).
3. **Enquiries have no language**: a new column `enquiries.language` is filled at submit from `Language::siteColumn()`;
   mails use it (empty = default language).
4. **Placeholders on the page**: `{{course_status}}` does not exist; it is added (“Starts 4 November” / “Running since
   7 October”) next to the existing `event_status`, plus `{{waiting_list}}`.
5. **Waiting list order** is `state_changed_at, enquiry_id` of `waitlisted` rows, so a restored registration goes to the
   end and a pair keeps its place.
6. **Booking own texts** (`booking_pending_thanks`, `booking_pending_mail`, `booking_declined_mail`, edited in the bookings
   list) move into Messages: a migration copies set values to the new keys and the old fields leave the bookings screen.
7. **Messages over MCP**: `update_settings` accepts `message_…` keys (validated by `Messages::check`) and its result lists
   the message keys that differ from the template; `site_info` is unchanged.
8. **Templates reproduce today's booking mails** by composing the existing `t()` sentences at runtime, so existing
   translations keep working and "nothing changes until someone edits".

## Review Focus

1. **Two people press "Register" for the last seat at the same moment**: exactly one gets it, the other is waitlisted (or gets "full" with the list off). Test: Task 4 `testLastSeatIsTakenOnce` (two `place()` calls under the lock, second sees the first).
2. **A pair heads the waiting list and one seat frees**: nobody passes the pair unless they fit, and the pair moves up when the second seat frees. Test: Task 3 `testPairKeepsItsPlace`.
3. **Spam restored or un-spammed while the course is full**: the registration goes to the end of the waiting list (or `cancelled` with the list off), never overbooks. Test: Task 5 `testRestoreIntoFullCourseWaitlists`.
4. **A German visitor registers on `/de/kurse/…`**: every later mail (confirmed, moved up, cancelled, course cancelled) is in German, including a customised German text. Test: Task 2 `testLanguageVersionFallsBackToTranslatedTemplate`, Task 9 `testCancellationMailUsesRegistrationLanguage`.
5. **The take-over link is prefetched, clicked twice, or the offered course filled meanwhile**: a GET never registers; the second POST shows "link no longer works"; a full course gives its waiting list. Test: Task 9 `testTakeOverLinkIsSingleUseAndGetNeverActs`.

---

### Task 1: Migration – registration columns on enquiries

**Files:**
- Create: `system/database/migrations/20261030110000_add_course_registrations.php`
- Test: `tests/Integration/CourseRegistrationMigrationTest.php`

**Interfaces:**
- Produces columns on `enquiries`: `item_id` int unsigned null (index `ix_enquiries_item_id_seat_state` on `item_id, seat_state`), `seat_state` string(12) null (`confirmed|waiting|waitlisted|declined|cancelled`; null = not a course registration), `seats` smallinteger unsigned default 1, `language` string(35) default `''`, `state_changed_at` datetime null, `reminded_at` datetime null, `offer_token_hash` string(64) null (index `ix_enquiries_offer_token_hash`), `offer_items` string(255) null (JSON list of item ids), `offer_until` datetime null.

- [ ] **Step 1: Write the failing test** `testColumnsExistAndBackfillMarksCourseRegistrations`: insert a collection with preset `courses`, a non-repeating item `salsa`, an `events` item with `repeat = weekly`, and enquiries with `source = 'collection:<id>'` and pages `/courses/salsa`, `/de/courses/salsa?x=1`, `/events/yoga`; run the migration's backfill (`AddCourseRegistrations::backfill($pdo, 'tl_')`); assert the two `salsa` rows have `item_id` = salsa's id, `seat_state = 'confirmed'`, `seats = 1`, `language` `''` and `'de'`; the yoga row has `item_id` set and `seat_state` null.
- [ ] **Step 2: Run** `RUN vendor/bin/phpunit tests/Integration/CourseRegistrationMigrationTest.php` → FAIL (class not found).
- [ ] **Step 3: Implement** `change()` adding the columns above, and `up()`-time backfill as `public static function backfill(\PDO $pdo, string $prefix): void` (PHP loop: select enquiries with `source LIKE 'collection:%'`, parse `/(<lang>/)?<collection slug>/<item slug>` from `page`, look up the item by collection, slug and language (fallback: any language), set `item_id`, `language`; `seat_state = 'confirmed'` only when the collection's preset maps `start` and the item has no `repeat`). Plain prepared statements, portable.
- [ ] **Step 4: Run** the test on MySQL, then `TALEA_TEST_DB_DRIVER=pgsql` and `sqlite` → PASS.
- [ ] **Step 5: Commit** `Add registration columns to enquiries`.

### Task 2: `Core\Messages` – catalogue, overrides, placeholders

**Files:**
- Create: `system/src/Core/Messages.php`
- Test: `tests/Unit/MessagesTest.php`, `tests/Integration/MessagesSettingsTest.php`

**Interfaces:**
- Produces:
  - `Messages::CATALOGUE`: `id => ['group' => 'courses'|'bookings', 'kind' => 'mail'|'page', 'placeholders' => list<string>, 'links' => list<string>]`. Ids: courses mails `course_registered`, `course_request`, `course_waitlisted`, `course_confirmed`, `course_declined`, `course_moved_up`, `course_cancelled`, `course_course_cancelled`; course pages `course_thanks_registered`, `course_thanks_request`, `course_thanks_waitlisted`, `course_full`, `course_closed`, `course_already`, `course_take_over`; bookings mails `booking_confirmation`, `booking_pending`, `booking_declined`, `booking_proposal`, `booking_cancelled`, `booking_reminder`; booking pages `booking_thanks`, `booking_pending_thanks`, `booking_cancel_page`.
  - `Messages::template(string $id, string $part): string` (`part` = `subject`|`body`; built from `t()` in the current language; booking templates compose today's sentences, e.g. `booking_confirmation` body = `t('Thank you, your appointment is booked.') . "\n\n{appointment}\n\n" . t('Add it to your calendar: %s', '{calendar link}') . …`).
  - `Messages::key(string $id, string $part, string $language = ''): string` → `message_<id>_<part>` + `_<language>` when non-empty.
  - `Messages::text(Settings $s, string $id, string $part, string $language): string` → the stored override for that language, else (for the default language: stored base key) else `Language::runWith($language ?: default, fn () => self::template(...))`.
  - `Messages::fill(string $text, array $values, string $id): string` → replaces `{name}` / `{name|fallback}` via `FormMail` token syntax for the id's placeholders only, then appends each link in `links` whose placeholder is absent (`"\n\n" . $value`).
  - `Messages::check(string $id, string $value): ?string` → error text naming the first unknown placeholder, else null.
  - `Messages::send(App $app, string $id, string $language, string $to, array $values, array $headers = []): bool` → fills subject and body in `$language`, adds the site signature (`"\n\n—\n" . site_name . "\n" . site_url`), `Mail::send`.

- [ ] **Step 1: Write failing unit tests**: `testFillReplacesKnownPlaceholdersAndFallback` (`'Hi {name|there}'` with `name => ''` → `'Hi there'`), `testFillLeavesUnknownTextAlone`, `testMissingLinkIsAppended` (`booking_confirmation` body without `{cancel link}` gets the link at the end), `testCheckRefusesUnknownPlaceholder` (`'{price}'` → message contains `price`), `testBookingTemplateMatchesTodaysText` (English `booking_confirmation` body filled equals the string `Booking::sendConfirmation` builds today for a fixture booking – copy that expected string into the test before Task 13 changes Booking).
- [ ] **Step 2: Write failing integration test** `testLanguageVersionFallsBackToTranslatedTemplate`: set `message_course_confirmed_body` = `'EN own'`; `text($s,'course_confirmed','body','de')` equals the German translated template; set `message_course_confirmed_body_de` = `'DE own'` → returns `'DE own'`; `text(..., '')` returns `'EN own'`.
- [ ] **Step 3: Run** both → FAIL.
- [ ] **Step 4: Implement** `Core\Messages` as above. Course templates (English source, translated in Task 14) follow the texts on the design board "Admin · Settings, messages".
- [ ] **Step 5: Run** → PASS. **Commit** `Add customisable message templates`.

### Task 3: `Core\Registrations` – seat count, placing, promotion (pure + DB)

**Files:**
- Create: `system/src/Core/Registrations.php`
- Modify: `system/src/Core/Calendar.php` (`ROLES` += `registration`, `waiting_list`, `seats_per_registration`; spam fix in `registered()`)
- Modify: `system/presets/courses.php`, `system/presets/events.php` (the fields and `calendar` mappings listed in Task 12's Interfaces; Task 12 adds the page side)
- Test: `tests/Unit/RegistrationsTest.php`, `tests/Integration/RegistrationsTest.php`

**Interfaces:**
- Consumes: Task 1 columns.
- Produces:
  - `Registrations::STATES = ['confirmed','waiting','waitlisted','declined','cancelled']`, `HOLDING = ['confirmed','waiting']`.
  - `Registrations::course(array $collection, array $item): ?array` → `['mode' => 'instant'|'confirm', 'waiting_list' => bool, 'max_seats' => int>=1, 'capacity' => int (0 = no limit), 'deadline' => string, 'ends' => string]`, or null when the item repeats or the collection is not a calendar.
  - `Registrations::decide(array $course, int $taken, int $seats, string $now): string` (pure) → `confirmed|waiting|waitlisted|full|closed`.
  - `Registrations::taken(Db $db, int $itemId): int` → `SUM(seats)` of `HOLDING` rows, `deleted_at IS NULL`, `category <> 'spam'`.
  - `Registrations::nextToPromote(list<array{enquiry_id:int,seats:int}> $waitlist, int $free): ?int` (pure) → first id in order whose seats fit, else null.
  - `Registrations::promote(App $app, array $collection, array $item, string $now): int` → promoted count; stops after `deadline`; sends `course_moved_up` (instant → `confirmed`) or sets `waiting` and notifies the form's recipients.
  - `Registrations::lockName(int $itemId): string` → `'talea-seat-' . $itemId` (used with `Db::lock($name, 5)`).
- `Calendar::registered()` adds `AND category <> 'spam'` and, for a non-repeating item with `Registrations::course() !== null`, returns `Registrations::taken()`.

- [ ] **Step 1: Write failing unit tests** for `decide`: `testInstantOpenConfirms`, `testConfirmModeWaits`, `testFullWithListWaitlists`, `testFullWithoutListIsFull`, `testTooManySeatsForFreeSeatsWaitlistsWhole` (capacity 16, taken 15, seats 2 → `waitlisted`), `testNoCapacityNeverFull`, `testAfterDeadlineClosed`; for `nextToPromote`: `testPairKeepsItsPlace` (`[[1,2],[2,1]]`, free 1 → `2`; free 2 → `1`), `testNobodyFits` → null.
- [ ] **Step 2: Write failing integration tests**: `testTakenSkipsSpamTrashAndNonHolding`, `testRegisteredCountsSpamNoMore` (repeating event path), `testPromoteStopsAfterDeadline`, `testPromoteInstantConfirmsOldestFitting`.
- [ ] **Step 3: Run** → FAIL. **Step 4: Implement.** **Step 5: Run** unit + integration on MySQL/pgsql/sqlite → PASS.
- [ ] **Step 6: Commit** `Count course seats and move up the waiting list`.

### Task 4: Registering on the course page

**Files:**
- Modify: `system/src/Front/Forms.php` (`process()` around the calendar check at :80 and the insert at :214, auto-reply at :245)
- Modify: `system/src/Builder/Elements/Form.php` (`messages()` :292, registration branch :324, participants select)
- Modify: `system/src/Core/Calendar.php` (`values()`: `_registration` carries `[state, 'text', max_seats]`; new keys `course_status`, `waiting_list`)
- Test: `tests/Integration/RegistrationsTest.php`, `tests/Site/CollectionsA/CourseRegistrationTest.php`

**Interfaces:**
- Consumes: `Registrations::course/decide/taken/lockName`, `Messages::text/send`.
- Produces: `Registrations::place(App $app, array $collection, array $item, int $enquiryId, int $seats, string $email): string` → state written, or `already`; result codes on the page `registered|request|waitlisted|already|full|closed`. Posted field name for participants: `seats` (1…max, clamped to capacity).

Flow in `Forms::process()` for a course item: keep the early `stateForSubmission` check for `closed`; `full` only when the waiting list is off. After validation, under `Db::lock(lockName, 5)`: refuse with `already` when the e-mail has a `confirmed|waiting|waitlisted` row for the item; insert the enquiry with `item_id`, `seats`, `language = Language::siteColumn()`, then `place()`; unlock in `finally`. Skip the form's auto-reply for course items; send `course_registered|course_request|course_waitlisted` instead. Owner notification subject is prefixed with `t('Request:')` for `waiting`.

Form element: when `_registration[2] > 1`, render a select “How many people are coming?” (1…max) before the consent checkbox; when the state is `full` with the list on, show `Messages` text `course_full` above the form and button text `t('Join the waiting list')`; result codes map to `course_thanks_*` / `course_already` texts.

- [ ] **Step 1: Write failing integration test** `testLastSeatIsTakenOnce`: capacity 1, two `place()` calls for two enquiries → states `confirmed`, `waitlisted`.
- [ ] **Step 2: Write failing site test** `CourseRegistrationTest` (one site, methods build on each other): create a `courses` collection by MCP, item with capacity 2, mode instant, max seats 2; `testRegisterTwoSeatsFillsCourse` (post with `seats=2` → `result=registered`, mail `course_registered` queued); `testSameEmailIsRefused` (→ `result=already`); `testFullCourseOffersWaitingList` (page shows “Join the waiting list”, post → `result=waitlisted`); `testListOffShowsFull`.
- [ ] **Step 3: Run** → FAIL. **Step 4: Implement.** **Step 5: Run** → PASS.
- [ ] **Step 6: Commit** `Register for a course with seats and a waiting list`.

### Task 5: Transitions, spam, trash and restore

**Files:**
- Modify: `system/src/Core/Registrations.php`
- Modify: `system/src/Admin/Modules/Enquiries.php` (`actionBulk` :180, `actionTriage` :135, `actionDelete` :228, `actionRestore` :238)
- Modify: `system/src/Core/Triage.php` (`afterSubmit` :186 after a machine marks spam)
- Modify: `system/src/Mcp/Handlers/EnquiryAndPopupTools.php` (`toolUpdateEnquiry` :72), `system/src/Mcp/Handlers/PageTools.php` (restore :269)
- Test: `tests/Integration/RegistrationsTest.php`

**Interfaces:**
- Produces:
  - `Registrations::transition(App $app, int $enquiryId, string $action, string $message = ''): void` with `action` = `confirm` (waiting → confirmed, mail `course_confirmed`), `decline` (waiting → declined, `course_declined`), `cancel` (confirmed|waiting|waitlisted → cancelled, `course_cancelled`), `promote` (waitlisted → confirmed|waiting by mode when seats fit, `course_moved_up`); throws `\DomainException` with a translated reason otherwise. Runs `promote()` after a seat frees.
  - `Registrations::sync(App $app, list<int> $enquiryIds): void` → after spam/unspam/trash/restore: frees seats and promotes; a restored or un-spammed holding row that no longer fits becomes `waitlisted` with `state_changed_at = now` (or `cancelled` with the list off).

- [ ] **Step 1: Write failing tests**: `testConfirmDeclineCancelPromote` (states and one queued mail each, `{message}` in decline mail), `testPromoteRefusedWithoutSeat`, `testSpamFreesSeatAndPromotes`, `testRestoreIntoFullCourseWaitlists`, `testRestoreWithListOffCancels`, `testCapacityLoweredBumpsNobody`.
- [ ] **Step 2: Run** → FAIL. **Step 3: Implement**; call `sync()` from every caller listed above after its update. **Step 4: Run** → PASS.
- [ ] **Step 5: Commit** `Keep seats right when registrations change, are marked spam or trashed`.

### Task 6: Registrations screen and Seats column

Design boards: “Admin · Courses with seats”, “Admin · Registrations of a course”, “Admin · Course settings”.

**Files:**
- Modify: `system/src/Admin/Modules/Collections.php` (new `actionRegistrations`, `actionRegistration`; `actionItems` passes seats)
- Create: `system/views/admin/collections/registrations.php`
- Modify: `system/views/admin/collections/items.php` (Seats column when `Calendar::fields($k)` is not null), `system/views/admin/collections/item.php` (link “Registrations · n of m” in the head links)
- Modify: `system/views/admin/enquiries/list.php`, `detail.php` (state label next to status)
- Modify: `tests/Unit/Mcp/ParityTest.php`
- Test: `tests/Site/CollectionsA/CourseRegistrationTest.php`

**Interfaces:**
- Consumes: `Registrations::transition/taken/course`.
- Produces: `admin.php?module=collections&action=registrations&id=<collection>&item=<item>` (GET); POST `action=registration` with fields `registration` (enquiry public id), `do` (`confirm|decline|cancel|promote`), `message`. `Registrations::list(Db $db, int $itemId): array<string, list<array>>` grouped by state, waitlisted in order.

Screen: tiles (seats taken of capacity, seats held by requests, on the waiting list, mode · up to n seats each); notice when requests wait; sections Waiting for confirmation / Confirmed (first 10 + “Show all n confirmed registrations”) / Waiting list (# order, Move up disabled with the `noseat` hint when nothing fits, Remove) / Declined and cancelled in `<details>`; head links Edit course, Print list, Cancel the course; warning when the item's form has no e-mail field. Decline and Cancel buttons use `data-confirm` and open a message field (`<details>` with a textarea, no JS needed).

- [ ] **Step 1: Write failing site tests**: `testItemsListShowsSeats` (“2 of 2”, link to registrations), `testAdminConfirmsAndCancels` (adminPost confirm → state, mail), `testMoveUpNeedsSeat`.
- [ ] **Step 2: Run** → FAIL. **Step 3: Implement**; parity: `'registrations' => $readOnly, 'registration' => 'update_registration'`. **Step 4: Run** site tests + `RUN composer test:unit` → PASS.
- [ ] **Step 5: Commit** `Add the registrations screen of a course`.

### Task 7: Print list

Design board: “Admin · Print list of participants”.

**Files:**
- Modify: `system/src/Admin/Modules/Collections.php` (`actionRegistrationsPrint`)
- Create: `system/views/admin/collections/registrations_print.php`
- Modify: `tests/Unit/Mcp/ParityTest.php` (`'registrations_print' => 'admin: a print view of what list_registrations returns'`)
- Test: `tests/Site/CollectionsA/CourseRegistrationTest.php`

**Interfaces:**
- Produces: a standalone `Response` like `Settings::actionReportView` (same CSP header, `image/print.js`, no admin layout): course name, schedule, dates, place; table #, Name, E-mail, Phone (only when the form has a `tel` field), People, Present (empty); confirmed rows only.

- [ ] **Step 1: Write failing site test** `testPrintListShowsConfirmedOnly` (waitlisted name absent, header CSP present).
- [ ] **Step 2–4: Run → FAIL, implement, run → PASS. Step 5: Commit** `Print the participants of a course`.

### Task 8: Reminder for unanswered requests

**Files:**
- Modify: `system/src/Core/Scheduler.php` (`JOBS` + `jobs()`: `'registration_reminders' => [3600, 'any', 'Course registration: reminder of unanswered requests']`)
- Modify: `system/src/Core/Registrations.php`
- Test: `tests/Integration/RegistrationsTest.php`

**Interfaces:**
- Produces: `Registrations::remind(App $app): string` → for `waiting` rows with `state_changed_at < NOW() - INTERVAL 3 DAY` and `reminded_at IS NULL` (LIMIT 50): mark first (`UPDATE … SET reminded_at = NOW() WHERE enquiry_id = ? AND reminded_at IS NULL`, skip on rowCount 0), then mail the form's recipients (`Forms::findElement` + `Forms::recipients`) in the default language, fixed text (not a Message). Returns `'n reminders'`.

- [ ] **Step 1: Write failing test** `testReminderOnceAfterThreeDays` (row 4 days old → one mail, second run → none; 2 days old → none).
- [ ] **Step 2–4: Run → FAIL, implement, run → PASS. Step 5: Commit** `Remind the course holder of unanswered requests`.

### Task 9: Cancel a course and take over a seat

Design boards: “Admin · Cancel the course”, “E-mail · Course cancelled”, “Site · Take a seat from a cancelled course”.

**Files:**
- Modify: `system/src/Core/Registrations.php`
- Modify: `system/src/Admin/Modules/Collections.php` (`actionCancelCourse` GET form, POST)
- Create: `system/views/admin/collections/cancel_course.php`
- Modify: `system/src/Front/Kernel.php` (`routes()`), `system/src/Front/Endpoints.php` (`courseSeat`)
- Modify: `tools/contracts/routes.json`, `tests/Unit/Mcp/ParityTest.php` (`'cancel_course' => 'cancel_course'`)
- Test: `tests/Integration/RegistrationsTest.php`, `tests/Site/CollectionsA/CourseCancelTest.php`

**Interfaces:**
- Produces:
  - `Registrations::cancelCourse(App $app, array $collection, array $item, list<int> $offerItemIds, string $message): int` → count of mails; offers limited to 3 items of the same collection that are open for registration; per affected row (`confirmed|waiting|waitlisted`): `seat_state = cancelled`, a raw token `bin2hex(random_bytes(32))` stored as `offer_token_hash = sha256`, `offer_items` JSON, `offer_until = now + 30 days`; item `visible = FALSE`; `Redirects::add` from the item path to the first offer's path, else to the collection list path; mail `course_course_cancelled` with `{take-over links}` = per offer “<course> · <schedule> · from <date>\nTake a seat: <url>”. In one `Db::transaction`, mails after commit.
  - Route `ANY /_course/{token:[a-f0-9]{64}}/{n:[1-3]}` → `Endpoints::courseSeat(string $token, string $n): Response` (noindex, never cached).
  - `Registrations::takeOverPage(App $app, string $token, int $n, bool $post): array{0: string, 1: string, 2: int}` → `[heading, html, status]`. GET shows course, name, seats and a POST button; POST claims atomically (`UPDATE … SET offer_token_hash = NULL WHERE offer_token_hash = ? AND offer_until > NOW()`, rowCount 1) and then inserts a new enquiry copying name, e-mail, data, seats, language into the offered item and `place()`s it (its own mail follows). Link dead when expired, used, the offered course closed or hidden: text `course_take_over` expired variant with a link to the collection list.

- [ ] **Step 1: Write failing integration tests**: `testCancelCourseCancelsHidesRedirects`, `testCancellationMailUsesRegistrationLanguage` (a `de` row gets the German template), `testOffersLimitedToThreeOpenCourses`.
- [ ] **Step 2: Write failing site test** `testTakeOverLinkIsSingleUseAndGetNeverActs` (GET twice → no new enquiry; POST → `result` page “registered”; second POST, also for another `n` → link no longer works; offered course full → waitlisted).
- [ ] **Step 3: Run** → FAIL. **Step 4: Implement**; `RUN php tools/contracts.php --update`. **Step 5: Run** → PASS.
- [ ] **Step 6: Commit** `Cancel a course and offer a seat in another one`.

### Task 10: MCP – registrations

**Files:**
- Create: `system/src/Mcp/Handlers/RegistrationTools.php` (trait, added to the `use` list in `Mcp\Tools`)
- Modify: `system/src/Mcp/Tools.php` (`definitions()`), `system/src/Mcp/Catalog.php` (`TOOLS`, `SENDS`), `system/src/Mcp/PublicIds.php`, `system/src/Mcp/Handlers/CollectionTools.php:207` (registration block), `tools/contracts/mcp-tools.json`
- Test: `tests/Unit/Mcp/RegistrationToolsTest.php` or extend `tests/Site/CollectionsA/CourseRegistrationTest.php`

**Interfaces:**
- Produces tools:
  - `list_registrations(item)` → `read`, extension `enquiries`; rows `{id, state, seats, name, email, created_at, position?}` (ids public; a ChangeLog row like `list_enquiries`).
  - `update_registration(registration, action: confirm|decline|cancel|promote, message?)` → `write`, in `SENDS`; calls `Registrations::transition`.
  - `cancel_course(item, offer?: list<item id>, message?)` → `destructive`, in `SENDS`.
  - Item result `registration` gains `mode`, `waiting_list`, `max_seats`, `taken`, `waiting`, `waitlisted`.

- [ ] **Step 1: Write failing tests**: `testListRegistrationsReturnsPublicIds`, `testUpdateRegistrationConfirms`, `testCancelCourseOverMcp`, `testDraftsOnlyConnectionCannotUpdate`.
- [ ] **Step 2–4: Run → FAIL, implement, `RUN php tools/contracts.php --update`, run → PASS (`ParityTest`, `ContractsTest` included). Step 5: Commit** `Handle registrations over MCP`.

### Task 11: Protected fields

Design board: “Admin · Collection settings, item fields”.

**Files:**
- Modify: `system/src/Core/Calendar.php` (`protectedKeys(array $collection): array<string, string>` key => type of every mapped field)
- Modify: `system/src/Admin/Modules/Collections.php` (`actionSave` :99), `system/src/Mcp/Handlers/CollectionTools.php` (`toolUpdateCollection` :124)
- Modify: `system/views/admin/collections/form.php` (tag “Used by registration”, lock instead of remove, key and type read-only, the one help line under the list)
- Test: `tests/Unit/CalendarTest.php`, `tests/Site/CollectionsA/CourseRegistrationTest.php`

**Interfaces:**
- Produces: `Calendar::refuseProtected(array $collection, array $newFields): ?string` → error naming the first mapped field removed or whose type changed, else null. Both save paths refuse with it (admin: error back to the form; MCP: `\DomainException`).

- [ ] **Step 1: Write failing tests**: `testRemovingMappedFieldIsRefused`, `testChangingMappedTypeIsRefused`, `testRenamingMappedFieldIsAllowed`, `testUnmappedFieldCanBeRemoved`, site `testUpdateCollectionCannotDropCapacity`.
- [ ] **Step 2–4: Run → FAIL, implement, run → PASS. Step 5: Commit** `Protect the fields registration relies on`.

### Task 12: Presets and page placeholders

Design boards: “Site · Course list”, “Site · Course page and registration”, “Admin · Course settings”.

**Files:**
- Modify: `system/src/Core/Calendar.php` (`values()` adds `course_status`, `waiting_list`; the roles came in Task 3)
- Modify: `system/presets/courses.php`, `system/presets/events.php` (card, list filter, template; the fields and mappings came in Task 3)
- Modify: `system/src/Builder/Collections.php` (`PLACEHOLDER_TYPES` / placeholder list if it enumerates calendar keys)
- Test: `tests/Unit/CalendarTest.php`, `tests/Site/CollectionsA/CourseRegistrationTest.php`

**Interfaces:**
- Courses preset fields (order): start “First session” datetime, end “Last session” datetime, schedule “Schedule” text, sessions “Sessions” number, place text, price number, capacity number, registration_until “Registration until” datetime, registration “How a registration counts” radio (`instant`, `confirm`), seats_per_registration “Seats per registration” number, waiting_list “Waiting list” radio (`on`, `off`), description html, image image, level “Level” text. `calendar` maps start, end, place, capacity, registration_until, registration, waiting_list, seats_per_registration. `card` → `['schedule', 'start', 'places_left']`; list filter on `level`. Template adds `{{course_status}}`, schedule, price line `[price]`-free label, `{{waiting_list}}`. Events preset gets the same three registration fields and mappings.
- Empty values mean defaults: registration `instant`, waiting list `on`, seats 1.

- [ ] **Step 1: Write failing tests**: `testCourseStatusStartsAndRunning` (pure helper `Calendar::courseStatus(string $start, string $end, string $now): string`), site `testNewCoursesCollectionHasRegistrationFields`.
- [ ] **Step 2–4: Run → FAIL, implement, run → PASS. Step 5: Commit** `Extend the courses preset for registration`.

### Task 13: Settings → Messages and bookings through Messages

Design board: “Admin · Settings, messages”.

**Files:**
- Modify: `system/src/Admin/Modules/Settings.php` (`TABS` += `'messages' => 'Messages'`; `FIELDS['messages']` built from `Messages::CATALOGUE` × parts × languages; `fieldType()`/`verifyValue()` accept `message_*` keys with type `message:<id>` → `Messages::check`; new `actionMessageTest`)
- Create: `system/views/admin/settings/messages.php` (group tabs Courses | Bookings via `?group=`, language select, one `<details>` per message: subject, text, placeholder help, “Back to the template” = submit with the field emptied, “Send me a test”)
- Modify: `system/src/Mcp/Tools.php` (`MCP_SETTINGS` regex accepts `message_[a-z_]+`), `system/src/Mcp/Handlers/SettingsTools.php` (result lists set `message_*` keys)
- Modify: `system/src/Core/Booking.php` (`sendConfirmation`, `sendCancelled`, `sendReminder`, `sendPending`, `sendDeclined`, `sendProposal`, `pendingThanks`, `ownText` → `Messages`), `system/src/Front/Booking.php` (`cancelPage` texts), `system/src/Builder/Elements/Booking.php:115`
- Modify: `system/src/Admin/Modules/Bookings.php` (~:354 own-text save removed), `system/views/admin/bookings/list.php` (~:110 fields removed, link to Settings → Messages)
- Create: `system/database/migrations/20261030110100_move_booking_texts_to_messages.php` (copies non-empty `booking_pending_thanks|booking_pending_mail|booking_declined_mail` to `message_booking_pending_thanks_body`, `message_booking_pending_body`, `message_booking_declined_body`, deletes the old rows)
- Modify: `tests/Unit/Mcp/ParityTest.php` (`'message_test' => 'admin: sends a test to the signed-in person'`)
- Test: `tests/Site/ImportersBooking/BookingFlowTest.php` (still green unchanged), `tests/Site/Settings/MessagesTest.php`

**Interfaces:**
- Consumes: `Messages::*` (Task 2).
- Tab visibility: Courses group only when a collection with a calendar preset exists; Bookings group only with the bookings extension.

- [ ] **Step 1: Write failing site tests**: `testCustomisedThankYouAndMailAreUsed` (set `message_course_thanks_registered_body` and `message_course_registered_subject` via `update_settings`, register → page shows the own text, `tl_mail.subject` matches), `testEditedBookingConfirmationIsSent`, `testUnknownPlaceholderIsRefused` (admin and `update_settings`), `testBackToTemplateEmptiesOverride`, `testSendMeATest`, `testOldBookingTextsMoved`.
- [ ] **Step 2: Run** → FAIL; confirm `BookingFlowTest`, `BookingConfirmationTest` pass before the change.
- [ ] **Step 3: Implement.** **Step 4: Run** the new tests and the existing booking site tests → PASS.
- [ ] **Step 5: Commit** `Let the owner customise booking and course messages`.

### Task 14: Translations, docs, full run

**Files:**
- Modify: `system/languages/*.php`, `system/languages/admin-*.php` via `tools/add-translations.py`
- Modify: `docs/` user docs for collections and bookings (the page that describes events/registration and booking texts), `CLAUDE.md` (one bullet under "Content and services": registrations are enquiries with `seat_state`, `Core\Registrations` is the only writer; messages through `Core\Messages`)
- Modify: `system/src/Mcp/Prompts.php` / Claude's guide text if it describes event registration

- [ ] **Step 1:** `RUN php tools/missing-translations.php` lists every new string; add German (formal and `-du`), Czech, Spanish, French, Italian, Polish, Slovak, and the other site dictionaries.
- [ ] **Step 2:** `RUN php tools/sql-dialect-scan.php` → 0 lines; `check-english` → exit 0; `RUN php tools/contracts.php` → no diff.
- [ ] **Step 3:** `RUN composer test:unit`; integration on MySQL, `composer test:pg`, `composer test:sqlite`; site suite `--processes 2` → all PASS.
- [ ] **Step 4:** `tools/screenshots.sh` for the new admin screens (Registrations, Cancel the course, Settings → Messages).
- [ ] **Step 5: Commit** `Translate and document course registration`.
