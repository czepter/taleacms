# Launch bar for 1.0

Tracking issue: #35. The bar is the list in `docs/DECISIONS.md` ("Launch bar for 1.0"); this file is its test plan and the place
where results are recorded. Results are linked from the release notes. Only measured evidence goes into the result sections; a
check that has not been run says "not yet run".

| # | Check | How | Status |
|---|---|---|---|
| 1 | A non-technical person goes from install to a published five-page site in 30 minutes, without CSS | Usability test, 3 people (below) | **not yet run** (human work) |
| 2 | Four compose tasks work by mouse, keyboard and touch | `composer test:browser` (Playwright, `tools/test-browser.mjs`) | passed 2026-10-10 |
| 3 | Lighthouse 99-100 on every look | `LOOKS=all tools/test-lighthouse.sh` | passed 2026-10-10 (with the caveats below) |
| 4 | Bookings, forms, newsletter and A/B tests work out of the box | PHPUnit site tests (mapping below) | passed 2026-10-10 |

## 1. Usability test protocol (not yet run)

Status: **not yet run.** This is human work; nothing in this section has been measured.

**Participants.** Three people who have never used a CMS and have not seen Talea before (no colleagues, no one who saw a demo).
Recruit one more than needed; replace a participant who turns out to know the product.

**Setup (identical for everyone).**

1. A clean machine or account with Docker. Fresh install from the release image, following only `README.md` / `docs/DEPLOYMENT.md`
   (no help from the moderator): `docker compose up`, open the installer, finish it.
2. Screen recording with audio (the participant thinks aloud) and a visible timer. The moderator starts the timer when the
   participant opens the README, and stops it when the five pages are published and visible on the public address.
3. Task text, read aloud once and left on paper: "Build a five-page site for your business (Home, About, Services, Contact and one
   more page of your choice) and publish it." Participants may use their own logo, text and photos if they brought them;
   otherwise placeholder text is fine.
4. The moderator does not help. A question is answered with "what would you do if I were not here?". If a participant is blocked for
   5 minutes the moderator gives the smallest hint and records it as a blocked moment.

**Record for every participant.** Time to publish (install and build separately), every blocked moment (what, how long, what
resolved it), every question asked, every place where the participant used CSS or gave up on something, and a one-line verdict.

**Pass.** All three publish in 30 minutes or less without writing CSS. Otherwise fix the blocking points and repeat the test with
three new people (never reuse a participant).

### Results table (fill in; leave empty until run)

| Participant | Date | Install (min) | Build and publish (min) | Total (min) | Blocked moments | Questions asked | CSS written? | Recording | Pass? |
|---|---|---|---|---|---|---|---|---|---|
| P1 | | | | | | | | | |
| P2 | | | | | | | | | |
| P3 | | | | | | | | | |

Round: 1 (repeat rounds are added as "Round 2" with three new rows).

### Findings to issues

Every blocked moment, question and complaint becomes a GitHub issue, one per finding (merge duplicates, but note how many
participants hit it):

- Title in the user's words ("Could not find where to add a page"), not the fix.
- Label by area: `builder`, `admin`, `installer`, `docs`, `design-system`, `looks`, `media`, `forms`, `bookings`, `newsletter`.
- Body: participant(s), timestamp in the recording, what they tried, what they expected, severity (blocked / slowed / confused).
- Findings that blocked a participant for more than a minute are fixed before 1.0; the rest are labelled `after-hard-fork` (1.1).
- Link the issues in the "Findings" list below and re-run the affected steps in the repeat round.

Findings: none yet (test not run).

## 2. Compose tasks: mouse, keyboard, touch

Covered by `tests/Browser/BrowserWalkTest.php`, which installs a clean English site and drives `tools/test-browser.mjs` in Chrome
(Playwright). Steps for the four tasks, from the script:

| Task | Mouse | Keyboard | Touch |
|---|---|---|---|
| Space between two sections | "space between two sections, one undo step per gesture" | "Enter walks the handles, arrows change the spacing" | "the four tasks by pointer events of a finger" |
| Row of three columns becomes 2 : 1 : 1 | "a row of three columns becomes 2 : 1 : 1 with the dividers" | "the divider handles set 2 : 1 : 1" | same touch step |
| Image half width | "an image is resized to half width" | "Alt+arrows resize an image to half width" | same touch step |
| Button into the right column | "a button moves to the right column with the toolbar grip" | "Ctrl+Shift+arrow moves the button into the next column" | same touch step |

Also covered there: phone edits leave desktop alone, a failed save never corrupts the tree, group/ungroup, marquee, file drop.
The walk fails on any script error in the admin, builder or public site.

## 3. Lighthouse

`tools/test-lighthouse.sh` installs a sample site (starter site `business`, five pages from the sitemap: `/`, `/about-us`,
`/services`, `/contact`, `/news`), applies and publishes every look in `system/looks` over MCP (`apply_look`, `publish_look`) and
runs Lighthouse 12 (mobile preset, headless Chrome) on each page. `LOOKS=all MIN_PERF=99 MIN_OTHER=99` makes the bar 99-100 in all
four categories.

Needs: PHP, MySQL (no `mysql` client; PDO creates the database), Node, Google Chrome (`CHROME=...`), `npm` network access for
`lighthouse@12`. Example with the dev stack's test database:

```bash
DB_PORT=3307 LOOKS=all MIN_PERF=99 MIN_OTHER=99 tools/test-lighthouse.sh
```

## 4. Feature checks and the tests that cover them

All are PHPUnit site tests (`tests/Site`, one installed site per class, over HTTP). No separate shell script exists any more
(`tools/test.sh` was ported in #38). No test had to be added: every item of the bar is covered.

| Bar item | Test | What it proves |
|---|---|---|
| Bookings | `ImportersBooking\BookingFlowTest` (visitor books a time, confirmation token and cancel, cancel deadline and reminders, MCP and admin views, personal data and retention), `BookingConfirmationTest`, `BookingSetupTest`, `BookingSwitchOffTest`; the booking element also in the browser walk | slot booking from a public page to the stored booking and the mails |
| Forms | `FormsLook\EnquiryFormsTest` (stored with campaign, webhook, too-fast and invalid submits, admin list and CSV), `FormsHygiene\FormContextTest` (builder `form` element posting to `/form`) | anti-spam, validation, storage, notification |
| Newsletter (fake SMTP) | `MediaMenuMail\NewslettersTest`, against `tools/fake-smtp.php` | draft, test mail, cron send, refused address, one-click unsubscribe, MCP tools, health page |
| A/B test through promotion | `Experiments\ExperimentsTest` (`testPromotingTheWinnerReplacesTheOriginalAndCanBeUndone`, `testAutomaticPromotionRunsOnlyWhenOptedIn`, `testAWholePageTestShowsTheOtherPagesContentAndPromotesIt`) | variants in the cached page, beacon counts, guardrails, promotion, change log, undo |

Run: `TALEA_TEST_DB_PORT=3307 TALEA_TEST_DB_PASSWORD= vendor/bin/paratest --testsuite site --filter 'BookingFlowTest|BookingConfirmationTest|EnquiryFormsTest|FormContextTest|NewslettersTest|ExperimentsTest'`.

## Results

### Run of 2026-10-10, commit `ab1cfa80` (branch `hard-fork-english`, working tree with the changes to `tools/test-lighthouse.sh` and this file)

Environment: macOS, PHP 8.5.11, Node 24, Google Chrome (local), MySQL 8.4 in Docker (`127.0.0.1:3307`), PHPUnit 13.4.1, Lighthouse 12
(mobile, headless). The sample site ran on the PHP built-in server: it compresses nothing and runs on one machine with the test.

| Check | Command | Result |
|---|---|---|
| Browser walk (bar item 2) | `vendor/bin/phpunit --testsuite browser` | OK, 1 test, 2 assertions, 3 min 01 s, not skipped |
| Feature checks (bar item 4) | `paratest --testsuite site --filter 'BookingFlowTest\|BookingConfirmationTest\|EnquiryFormsTest\|FormContextTest\|NewslettersTest\|ExperimentsTest'` | OK, 36 tests, 252 assertions, 14 s |
| Lighthouse, 14 looks x 5 pages = 70 measurements (bar item 3) | `LOOKS=all PAGES=5 MIN_PERF=99 MIN_OTHER=99 tools/test-lighthouse.sh` | "OUTPUT BUDGET OK": all 70 measurements >= 99 |
| Czech check | `php tools/check-english.php` | exit 0 |

Lighthouse detail. Accessibility, best practices and SEO are 100 in all 70 measurements. Performance is 100 on 56 of them and 99 on
the other 14: `/contact` of every look (LCP 1.7-1.8 s, CLS 0; the audits `first-contentful-paint` and `largest-contentful-paint` are
below 1 there; the cause was not investigated). Looks measured: atelier, boutique,
brasserie, classic-blog, harbor, journal, launch, meadow, monograph, noir, pulse, studio, sunny, terrace.

Caveats (measured numbers are real; what they do not show):

- The sample site is the starter site `business` with placeholder text and no photographs. Looks are therefore compared on type,
  colour and layout; the numbers say nothing about pages with large photos until the demo-images pack exists (1.1).
- The run was against the PHP built-in server without compression or HTTP caching headers from a front server; a real host should
  score the same or higher. The 99s on `/contact` are within the noise of one run on one machine; they were not repeated.
- One run, one machine. Not run on CI. The scores were not checked in dark mode.
- Lighthouse does not test contrast of every look in both colour modes; the design system's own `contrasts()` check does.

Fixed during this run: `tools/test-lighthouse.sh` was stale (needed a `mysql` client, created the database with a Czech collation,
did not link `vendor/` for Phinx, and could only test starter sites). It now creates the database through PDO with the collation
`utf8mb4_0900_ai_ci`, links `vendor/`, prints the installer's error text, and has `LOOKS` (all or a list) and `PAGES` options.

### Usability test

Not yet run. See section 1.

## Moved to 1.1

Everything not on the bar waits (`docs/DECISIONS.md`). The leftovers recorded in the closed issues and in the specs:

| Item | Source | Note |
|---|---|---|
| Demo-images pack in Media for the looks (CC0 or generated) and client-gallery demo images | #34 comment ("no demo-images pack in Media for looks"); `docs/DECISIONS.md` Looks | placeholders only for now; Lighthouse numbers above are without photos |
| Visual compose phase C: focal point, crop to ratio, section background from the canvas toolbar, replace image by drop | `docs/specs/visual-compose.md` (milestone M6, open question 5) | phase C is not built |
| Lighthouse beyond the bar: other starter sites with all looks, pages with photos, dark mode, a repeat on CI | #23/#29 comments ("Lighthouse not run" then); this run covers only `business` | the script supports `SITES` and `LOOKS`; wiring it to CI is not done |
| Browser checks for A/B flicker | #31/#32/#33 comments | the server-rendered both-versions approach is covered by `ExperimentsTest`, there is no layout-shift check in the browser |
| PostgreSQL run of the new tests and of add-on tables beyond the full suite | #31-#34 comments | `composer test:pg` runs the whole suite; no dedicated check of the add-on tables |
| E2E update test against a real image built by buildx | #17 comment ("ran green only against a hand-built image") | the in-app updater stays off; `tests/E2E/UpdateTest.php` |
| Screenshots of the new admin screens for the docs (`tools/screenshots.sh`) | #24, #29 comments | |
| Cyrillic and Greek font subsets | `docs/DECISIONS.md` Deferred | on request |
| Client galleries: private link or member login, favourites, download | `docs/DECISIONS.md` Deferred (add-on exists, see #34) | extensions beyond the first add-on |
| HF-15 name availability (#19), HF-00 umbrella (#4) | open issues | not part of the bar |
| Test-suite follow-ups: shared helper traits in `tests/Site/Support`, replace `sleep(4)` with back-dated signed timestamps | #38 comment | |

## Human-only items remaining before 1.0

1. The usability test (section 1): three participants, recording, results table, findings as issues, repeat round when needed.
2. Linking this file from the release notes once the results are in.
3. A decision whether the 99 on `/contact` is acceptable on a repeat run on a real host (the bar says 99-100).
