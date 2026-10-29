# Form Calculator Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Extend the Form element into a lead quiz: AND/OR rules with amounts, factors and result variants; image/icon tiles,
slider and time range; a "result after contact" mode computed only on the server; drop-off counters per step; MCP `try_form`.

**Architecture:** One pure PHP class `Builder\FormLogic` owns rules, visibility, amounts, range and variants. `Front\Forms`, the
result screen, the builder's Try it tab and MCP `try_form` call it. `image/web.js` evaluates only visibility and, in live mode,
the running total. Rules live in the form's content (property `logic`) and go through `Build::sanitize()` via a new per-element
hook, so draft/publish, versions, language versions and MCP come with the build.

**Tech Stack:** PHP 8.5 (no framework), Phinx, PHPUnit/ParaTest, vanilla JS, server-rendered HTML.

**Spec:** `docs/specs/form-calculator.md`

## Global Constraints

- English everywhere in code, identifiers, comments, tests, docs. UI text through `t('English')` (PHP) and `T('English')` (admin JS); add translations to every dictionary with `tools/add-translations.py` (German formal + `-du`; "AI", not "KI").
- Admin CSP `script-src 'self'`: no inline scripts, no `on*=`; behaviour via `data-` attributes. No `window.confirm()`: `data-confirm`.
- Site CSS in `@layer template` / shared pieces in `image/web.css` inside `:where()`; tokens `--tl-*` with own defaults; logical properties (RTL); no `!important`.
- "What nothing emits has no style and no script": every new selector and `[data-…]` hook must be produced by markup (`tests/Unit/ScriptsTest.php`).
- Portable SQL (MySQL 8, PostgreSQL 18, SQLite 3.40): `Db::upsert()`, no `ON DUPLICATE KEY`, no `IF()`; booleans `= TRUE`.
- Schema change only as a Phinx migration in `system/database/migrations/` (table DSL, no `COLLATE`).
- Public ids (UUID) outside the database layer: MCP arguments/results, URLs, beacons.
- Placeholders in brackets in every default and starter (`[Question]`, `[amount]`), never realistic prices or people (#54).
- No amounts in the page in `after_contact` mode: no `data-price`, `data-price-per`, `data-estimate`, no `| 1200` text, no amount actions or variants in `data-logic`.
- New `data-` attribute names printed by the renderer are added to the reserved list in `Build::ATTRIBUTE_PATTERN`.
- PHP, Composer, PHPUnit and `php tools/…` run only in the dev container: `docker compose -f docker-compose-dev.yaml run --rm web <command>` (on the Colima Mac through `colima ssh -- sh -c "cd '<worktree>' && …"`). Below, `RUN` stands for that prefix.
- Before every commit: `RUN php tools/check-english.php` exits 0, `RUN php tools/sql-dialect-scan.php` reports 0 lines.
- Branch `feat/form-calculator`; commit messages end with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.

## Review Focus

1. **Prices leaking in `after_contact`**: the rendered form has no amount anywhere (attributes, option text, JSON). Test: Task 4 `testAfterContactPageCarriesNoAmounts`.
2. **Renaming a field or an option**: renaming a field label keeps its rules working (key unchanged); renaming an option that a rule names is reported by the check and the rule does nothing, no error. Tests: Task 1 `testKeysSurviveLabelChange`, Task 2 `testRuleOnMissingOptionIsReportedAndInert`.
3. **No JavaScript**: an `after_contact` form submitted without the script, with a required question hidden by a rule left empty, is accepted and shows the result. Test: Task 5 `testHiddenRequiredQuestionDoesNotBlock` (site test posts without the hidden answer).
4. **Time range past midnight**: 22:00–02:00 is 4 hours; equal times are 0; a malformed value is rejected as a field error. Tests: Task 2 `testTimeRangeHours`, Task 5 `testMalformedTimeRangeIsAFieldError`.
5. **Result link tampered, expired, enquiry deleted or anonymised**: shows "This result is no longer available." with the form's button, status 200, never contact data. Test: Task 5 `testResultLinkFailsClosed`.

---

### Task 1: Per-element sanitize hook, stable field keys, 40 fields

**Files:**
- Modify: `system/src/Builder/Element.php` (new hook)
- Modify: `system/src/Builder/Build.php:121` (call the hook after `sanitizeContent`)
- Modify: `system/src/Builder/Elements/Form.php:51-66` (`max` 20 → 40, item property `key`)
- Modify: `image/builder.js:1819` (skip item properties with `hidden: true`)
- Create: `tests/Unit/Builder/FormKeysTest.php`

**Interfaces:**
- Produces: `Element::sanitizeElement(array $content, string $place, array &$errors): array` (default returns `$content`); `Form::sanitizeElement(...)` override; every Form field item has `key` matching `/^k[a-z0-9]{4}$/`, unique within the form.

- [ ] **Step 1: Write the failing tests** in `tests/Unit/Builder/FormKeysTest.php`

```php
public function testMissingKeysAreAssignedAndUnique(): void
{
    $build = Build::sanitize(['v' => 1, 'children' => [['type' => 'form', 'content' => ['fields' => [
        ['label' => 'A', 'type' => 'text'], ['label' => 'B', 'type' => 'text', 'key' => 'kabcd'], ['label' => 'C', 'type' => 'text', 'key' => 'kabcd'],
    ]]]]]);
    $keys = array_column($build['children'][0]['content']['fields'], 'key');
    $this->assertCount(3, array_unique($keys));
    $this->assertSame('kabcd', $keys[1]);
    foreach ($keys as $k) { $this->assertMatchesRegularExpression('/^k[a-z0-9]{4}$/', $k); }
}

public function testKeysSurviveLabelChange(): void
{
    $first = Build::sanitize(['v' => 1, 'children' => [['type' => 'form', 'content' => ['fields' => [['label' => 'Guests', 'type' => 'number']]]]]]);
    $first['children'][0]['content']['fields'][0]['label'] = 'Number of guests';
    $second = Build::sanitize($first);
    $this->assertSame($first['children'][0]['content']['fields'][0]['key'], $second['children'][0]['content']['fields'][0]['key']);
}

public function testFortyFieldsAreKept(): void
{
    $fields = array_map(fn (int $i): array => ['label' => 'F' . $i, 'type' => 'text'], range(1, 45));
    $build = Build::sanitize(['v' => 1, 'children' => [['type' => 'form', 'content' => ['fields' => $fields]]]]);
    $this->assertCount(40, $build['children'][0]['content']['fields']);
}
```

- [ ] **Step 2: Run** `RUN vendor/bin/phpunit tests/Unit/Builder/FormKeysTest.php` — expected FAIL (no `key`, 20 fields).
- [ ] **Step 3: Implement.** In `Element`, add the no-op `sanitizeElement`. In `Build::sanitizeChildren` right after building `$clean['content']`, call `$clean['content'] = $className::sanitizeElement($clean['content'], $place . '.content', $errors);`. In `Form::properties()` set `fields.max` to 40 and add item property `'key' => ['type' => 'text', 'label' => 'Key', 'default' => '', 'max' => 5, 'hidden' => true]`. `Form::sanitizeElement` keeps a valid unused key, else assigns `'k' . 4 random chars of [a-z0-9]` not yet used in this form. In `builder.js` item rendering, skip defs with `d.hidden`.
- [ ] **Step 4: Run** the test file — PASS; then `RUN vendor/bin/phpunit --testsuite unit` — PASS (existing Form/Build tests may need `key` ignored in exact comparisons; adjust only those assertions).
- [ ] **Step 5: Commit** `git commit -m "Form fields get stable keys; up to 40 fields"`

---

### Task 2: `FormLogic` – rules, conditions, visibility

**Files:**
- Create: `system/src/Builder/FormLogic.php`
- Modify: `system/src/Builder/Build.php:279-315` (property type `form_logic` passes an array through raw)
- Modify: `system/src/Builder/Elements/Form.php` (property `logic`, `Form::sanitizeElement` calls `FormLogic::sanitize`)
- Create: `tests/Unit/Builder/FormLogicTest.php`, `tests/Fixtures/form-logic.json`

**Interfaces:**
- Consumes: field `key` (Task 1).
- Produces (all `public static`, namespace `Talea\Builder`):
  - `const OPERATORS = ['is','is_not','contains','not_contains','gt','lt','between','weekday','month','empty','not_empty']`
  - `const ACTIONS = ['add','quantity','multiply','skip','show_when','result']`; `const MAX_RULES = 50, MAX_CONDITIONS = 10, MAX_ACTIONS = 5, MAX_RESULTS = 10`
  - `sanitize(mixed $logic, array $fields, string $place, array &$errors): array{rules: list<array>, results: list<array>}`
  - `rules(array $content): list<array>` — stored rules without `broken` ones, preceded by the converted legacy `show_when_field/value` pairs
  - `value(array $field, string|array $answer): ?float` — number/slider → float (comma decimal ok), `timerange` "HH:MM-HH:MM" → hours (past midnight wraps), else null
  - `holds(array $condition, array $field, string|array $answer): bool`
  - `visible(array $fields, array $rules, array $answers): array<int, bool>` — `$answers` keyed by field index as in `Front\Forms` today
- Rule shape (stored): `{label, broken?: true, match: all|any, when: [{field: key, op, value, to?}], then: [{do: add, amount} | {do: quantity, field: key, amount} | {do: multiply, by} | {do: skip, field: key} | {do: show_when, field: key} | {do: result, variant}]}`; result shape `{key: /^v[a-z0-9]{1,8}$/, heading, text, show_amount: bool, button_text, button_link, image}`.

- [ ] **Step 1: Write the failing tests**

```php
public function testTimeRangeHours(): void
{
    $f = ['type' => 'timerange'];
    $this->assertSame([4.0, 0.0, 8.5, null], [FormLogic::value($f, '22:00-02:00'), FormLogic::value($f, '10:00-10:00'), FormLogic::value($f, '09:30-18:00'), FormLogic::value($f, '25:00-01:00')]);
    $this->assertSame(1.5, FormLogic::value(['type' => 'number'], '1,5'));
}

public function testOperators(): void
{
    $radio = ['type' => 'radio']; $boxes = ['type' => 'checkboxes']; $num = ['type' => 'number']; $date = ['type' => 'date'];
    $this->assertTrue(FormLogic::holds(['op' => 'is', 'value' => 'Abroad'], $radio, 'Abroad'));
    $this->assertTrue(FormLogic::holds(['op' => 'is_not', 'value' => 'Abroad'], $radio, ''));
    $this->assertTrue(FormLogic::holds(['op' => 'contains', 'value' => 'Album'], $boxes, ['Album', 'Photo booth']));
    $this->assertTrue(FormLogic::holds(['op' => 'between', 'value' => '50', 'to' => '120'], $num, '120'));
    $this->assertTrue(FormLogic::holds(['op' => 'weekday', 'value' => '6'], $date, '2026-10-31')); // Saturday = 6 (ISO)
    $this->assertTrue(FormLogic::holds(['op' => 'month', 'value' => '10'], $date, '2026-10-31'));
    $this->assertTrue(FormLogic::holds(['op' => 'not_empty'], $boxes, ['x']));
    $this->assertFalse(FormLogic::holds(['op' => 'gt', 'value' => '5'], $num, 'abc'));
}

public function testVisibilityCombination(): void
{
    $fields = [['key' => 'ka000', 'type' => 'radio'], ['key' => 'kb000', 'type' => 'text'], ['key' => 'kc000', 'type' => 'text']];
    $show = ['match' => 'any', 'when' => [['field' => 'ka000', 'op' => 'is', 'value' => 'Yes']], 'then' => [['do' => 'show_when', 'field' => 'kb000']]];
    $skip = ['match' => 'all', 'when' => [['field' => 'ka000', 'op' => 'is', 'value' => 'Yes']], 'then' => [['do' => 'skip', 'field' => 'kb000']]];
    $chain = ['match' => 'all', 'when' => [['field' => 'kb000', 'op' => 'empty']], 'then' => [['do' => 'skip', 'field' => 'kc000']]];
    $this->assertSame([true, true, true], array_values(FormLogic::visible($fields, [$show, $chain], [0 => 'Yes', 1 => 'x', 2 => ''])));
    $this->assertFalse(FormLogic::visible($fields, [$show, $skip], [0 => 'Yes'])[1], 'skip wins over show');
    $this->assertFalse(FormLogic::visible($fields, [$show, $chain], [0 => 'No', 1 => 'typed'])[2], 'a hidden answer counts as empty');
}

public function testLegacyConditionsBecomeRules(): void
{
    $content = ['fields' => [['key' => 'ka000', 'label' => 'Type', 'type' => 'radio'], ['key' => 'kb000', 'label' => 'Colour', 'type' => 'text', 'show_when_field' => 'type', 'show_when_value' => '*']], 'logic' => ['rules' => [], 'results' => []]];
    $rule = FormLogic::rules($content)[0];
    $this->assertSame(['field' => 'ka000', 'op' => 'not_empty'], array_intersect_key($rule['when'][0], ['field' => 1, 'op' => 1]));
    $this->assertSame(['do' => 'show_when', 'field' => 'kb000'], $rule['then'][0]);
}

public function testRuleOnMissingOptionIsReportedAndInert(): void
{
    $errors = [];
    $fields = [['key' => 'ka000', 'type' => 'radio', 'choices' => "North\nSouth"], ['key' => 'kb000', 'type' => 'text']];
    $logic = FormLogic::sanitize(['rules' => [['match' => 'all', 'when' => [['field' => 'ka000', 'op' => 'is', 'value' => 'East']], 'then' => [['do' => 'skip', 'field' => 'kb000']]]]], $fields, 'x', $errors);
    $this->assertNotEmpty($errors);
    $this->assertTrue($logic['rules'][0]['broken']);
    $this->assertTrue(FormLogic::visible($fields, FormLogic::rules(['fields' => $fields, 'logic' => $logic]), [0 => 'East'])[1]);
}

public function testRulesMayOnlyLookBack(): void
{
    $errors = [];
    $fields = [['key' => 'ka000', 'type' => 'text'], ['key' => 'kb000', 'type' => 'text']];
    $logic = FormLogic::sanitize(['rules' => [['match' => 'all', 'when' => [['field' => 'kb000', 'op' => 'empty']], 'then' => [['do' => 'skip', 'field' => 'ka000']]]]], $fields, 'x', $errors);
    $this->assertSame([], $logic['rules']);
    $this->assertNotEmpty($errors);
}

public function testFixtureVisibility(): void // shared with the browser walk (Task 6)
{
    foreach (json_decode((string) file_get_contents(TALEA_ROOT . '/tests/Fixtures/form-logic.json'), true) as $case) {
        $visible = FormLogic::visible($case['content']['fields'], FormLogic::rules($case['content']), $case['answers']);
        $this->assertSame($case['visible'], array_values($visible), $case['name']);
    }
}
```

`tests/Fixtures/form-logic.json`: a list of cases `{name, content: {calculation, fields, logic}, answers: {"<index>": value}, visible: [bool…], total, shown}`. Start with three: the wedding quiz (location radio with prices, guests slider, time range, extras checkboxes, "Abroad" multiply ×1.3, "Abroad" → variant), the solar check (north + heavy shade → variant), and one live form with add/quantity rules. `total` and `shown` are filled in Task 3.

- [ ] **Step 2: Run** `RUN vendor/bin/phpunit tests/Unit/Builder/FormLogicTest.php` — FAIL (class missing).
- [ ] **Step 3: Implement** `FormLogic` per the interface. Notes the tests do not pin:
  - `sanitize` drops unknown operators/actions, conditions on unknown keys, `value` texts over 200 chars, rules over the limits; a condition whose choice value is not an option of that field is reported and its rule kept with `broken: true` (the overlay shows it for fixing; `rules()` leaves broken rules out, so it does nothing on the site), and it drops a rule whose `skip`/`show_when` target is not after every field its conditions name (report). `multiply.by` 0–100, amounts ±10,000,000, labels ≤ 120 chars, result texts ≤ 2000 chars, `button_link` through the same link check as other `link` properties, `image` the `image` pattern.
  - `visible` walks fields in order: a field is hidden when any `skip` rule for it holds; if it has `show_when` rules, at least one must hold. A condition sees a hidden field's answer as `''`/`[]`. A condition naming the field itself or a later field is false.
  - Legacy conversion: `show_when_value` `*` → `not_empty`; on `checkboxes` → `contains`; else `is`; label match is case-insensitive and trimmed as in today's `Form::visible`.
  - `Build::sanitizeContent`: `'form_logic' => is_array($value) ? $value : ['rules' => [], 'results' => []]`; `Form::properties()` adds `'logic' => ['type' => 'form_logic', 'label' => 'Logic', 'default' => ['rules' => [], 'results' => []]]`; `Form::sanitizeElement` replaces it with `FormLogic::sanitize(...)`.
- [ ] **Step 4: Run** the test file — PASS; `RUN vendor/bin/phpunit --testsuite unit` — PASS.
- [ ] **Step 5: Commit** `git commit -m "FormLogic: rules, conditions and visibility for forms"`

---

### Task 3: `FormLogic` – amounts, range, variants; retire `Form::visible/estimate`

**Files:**
- Modify: `system/src/Builder/FormLogic.php`
- Modify: `system/src/Builder/Elements/Form.php` (remove `visible()`, `estimate()`; `money()` gains a prefix; estimate item properties)
- Modify: every caller (`grep -rn "Form::visible\|Form::estimate\|FormElement::visible\|FormElement::estimate" system tests`)
- Modify: `tests/Unit/Builder/FormLogicTest.php`, `tests/Unit/Front/FormsTest.php:31-52`, `tests/Fixtures/form-logic.json`

**Interfaces:**
- Consumes: Task 2.
- Produces:
  - `amounts(array $fields, array $rules, array $answers, array $visible, float $base, bool $afterContact): array{lines: list<array{0: string, 1: float}>, total: float, variant: string}`
  - `range(float $total, array $estimate): array{low: float, high: float, kind: 'plain'|'about'|'from'|'range'}`
  - `format(array $range, array $estimate): string`
  - `evaluate(array $content, array $answers): array{visible: array<int,bool>, lines: list<array{0:string,1:float}>, total: float, range: array, shown: string, variant: string, after_contact: bool}`
  - `Form::money(float $amount, string $suffix, string $prefix = ''): string`
  - Estimate item properties: `unit_prefix`, `unit_suffix` (text ≤ 10), `range_below`, `range_above` (number 0–90, default 0), `round_to` (number 0–100000, default 0), `minimum` (text ≤ 20, default ''). Old `currency` is read as `unit_suffix` when `unit_suffix` is empty. Form property `calculation` choice `live` | `after_contact`, default `live`.

- [ ] **Step 1: Write the failing tests**

```php
public function testAmountsInOrder(): void
{
    $fields = [['key' => 'ka000', 'type' => 'radio', 'label' => 'Location', 'choices' => "Barn\nCastle | 200\nAbroad"], ['key' => 'kb000', 'type' => 'timerange', 'label' => 'Time']];
    $rules = [
        ['label' => 'Hours', 'match' => 'all', 'when' => [], 'then' => [['do' => 'quantity', 'field' => 'kb000', 'amount' => 180]]],
        ['label' => 'Abroad', 'match' => 'all', 'when' => [['field' => 'ka000', 'op' => 'is', 'value' => 'Abroad']], 'then' => [['do' => 'multiply', 'by' => 1.3], ['do' => 'result', 'variant' => 'v1']]],
    ];
    $answers = [0 => 'Abroad', 1 => '14:00-22:00'];
    $out = FormLogic::amounts($fields, $rules, $answers, FormLogic::visible($fields, $rules, $answers), 1000.0, true);
    $this->assertSame(round((1000 + 8 * 180) * 1.3, 2), $out['total']);
    $this->assertSame('v1', $out['variant']);
    $this->assertSame(['Base price', 'Hours', 'Abroad'], array_column($out['lines'], 0));
    $live = FormLogic::amounts($fields, $rules, $answers, FormLogic::visible($fields, $rules, $answers), 1000.0, false);
    $this->assertSame(['', 1000.0 + 8 * 180], [$live['variant'], $live['total']], 'live: no multiply, no variant');
}

public function testRange(): void
{
    $e = ['range_below' => 10, 'range_above' => 15, 'round_to' => 50, 'minimum' => '1500', 'unit_suffix' => '€'];
    $this->assertSame(['low' => 2700.0, 'high' => 3500.0, 'kind' => 'range'], FormLogic::range(3010.0, $e)); // 2709 down to 2700, 3461.5 up to 3500
    $this->assertSame(['low' => 1500.0, 'high' => 1500.0, 'kind' => 'from'], FormLogic::range(1000.0, $e));
    $this->assertSame(['low' => 3400.0, 'high' => 3400.0, 'kind' => 'about'], FormLogic::range(3412.0, ['range_below' => 0, 'range_above' => 0, 'round_to' => 50]));
    $this->assertSame('plain', FormLogic::range(3412.0, [])['kind'], 'no settings = today’s exact amount');
}

public function testFixtureTotals(): void
{
    foreach (json_decode((string) file_get_contents(TALEA_ROOT . '/tests/Fixtures/form-logic.json'), true) as $case) {
        $r = FormLogic::evaluate($case['content'], $case['answers']);
        $this->assertSame([$case['total'], $case['shown']], [$r['total'], str_replace("\u{a0}", ' ', $r['shown'])], $case['name']);
    }
}
```

Range rules: low = `floor(total × (1 − below/100) / round_to) × round_to`, high = `ceil(total × (1 + above/100) / round_to) × round_to` (no rounding when `round_to` is 0); if `minimum` > high → `kind` `from`, low = high = minimum; if low < minimum → low = minimum; below = above = 0 → `about` when `round_to` > 0 (low = high = total rounded to the nearest step), else `plain` (low = high = total). `format`: `plain` → `money(total)`, `about` → `t('about %s')`, `from` → `t('from %s')`, `range` → `money(low) . ' – ' . money(high)`.

Lines: base (label `t('Base price')`, only when ≠ 0), each visible chosen option with a price (label = option label), each visible number × `unit_price` (label = field label), then the rules top to bottom (label = rule label or `t('Adjustment')`); `multiply` adds the line `total × (by − 1)`. `result`: first matching wins, `after_contact` only.

- [ ] **Step 2: Run** `RUN vendor/bin/phpunit tests/Unit/Builder/FormLogicTest.php` — FAIL.
- [ ] **Step 3: Implement** the interface. `Form::sanitizeElement` reports `calculation` `after_contact` without an estimate field and stores `live` instead. Move `FormsTest::testMultiStepConditionsAndEstimate` onto `FormLogic::visible/amounts` (same expected numbers); delete `Form::visible` and `Form::estimate`; fill `total`/`shown` in the fixture.
- [ ] **Step 4: Run** unit suite — PASS.
- [ ] **Step 5: Commit** `git commit -m "FormLogic: amounts, factors, range and result variants"`

---

### Task 4: Form rendering – tiles, slider, time range, `data-logic`, no amounts after contact

**Files:**
- Modify: `system/src/Builder/Elements/Form.php` (properties, `render`, `fields`, `estimateField`, `baseCss`)
- Modify: `system/src/Builder/Build.php:31` (reserve `logic`, `advance`, `field`)
- Modify: `image/web.css` (tiles, slider value, time range, progress bar)
- Create: `tests/Unit/Builder/FormRenderTest.php`

**Interfaces:**
- Consumes: Tasks 1–3.
- Produces:
  - Field type `timerange` (in `FIELD_TYPES`: `'timerange' => 'time range (from – to, counts as hours)'`): two `<input type="time" name="p{i}[]">` in a fieldset.
  - Item properties: `display` choice (`list`, `image_tiles`, `icon_tiles` when type radio/checkboxes; `slider` when number — one choice list, the inspector shows only what fits via `when`), `option_media` (lines, ≤ 4000; per option one `media/…` path or one `Builder\Icons` key), `min`, `max`, `step` (text ≤ 12, when number), `advance` (boolean, when radio).
  - Every field targeted by a `skip`/`show_when` rule is wrapped in `<div class="tl-condition" data-field="{i}">`; the `<form>` carries `data-logic` = JSON `{fields: [{key, type}], rules: [...], estimate?: {base, below, above, round, minimum, prefix, suffix}}`. In `after_contact`, `rules` keep only `skip`/`show_when` actions and `estimate` is absent. The old `data-when` / `data-when-value` output is removed.
  - `after_contact` estimate field renders `<p class="tl-field tl-estimate-later">` with `t('You will see your estimate after sending.')`; no `data-price`, `data-price-per`, `data-estimate` anywhere.

- [ ] **Step 1: Write the failing tests** (render through `Build::html` with a test `Context`, as `ElementsTest` does)

```php
public function testAfterContactPageCarriesNoAmounts(): void
{
    $html = $this->renderForm(['calculation' => 'after_contact', 'fields' => [
        ['label' => 'Where', 'type' => 'radio', 'display' => 'image_tiles', 'choices' => "Barn | 100\nCastle | 200", 'option_media' => "media/a.webp\nmedia/b.webp"],
        ['label' => 'Guests', 'type' => 'number', 'display' => 'slider', 'min' => '10', 'max' => '250', 'unit_price' => '12'],
        ['label' => 'Estimate', 'type' => 'estimate', 'base_price' => '900'],
    ], 'logic' => ['rules' => [['match' => 'all', 'when' => [], 'then' => [['do' => 'add', 'amount' => 777]]]], 'results' => []]]);
    foreach (['data-price', 'data-price-per', 'data-estimate', '| 100', '777', '900', '"add"'] as $leak) {
        $this->assertStringNotContainsString($leak, $html, $leak);
    }
    $this->assertStringContainsString('type="range" min="10" max="250"', $html);
    $this->assertStringContainsString('<img', $html);
}

public function testTilesAreRealRadioButtons(): void
{
    $html = $this->renderForm(['fields' => [['label' => 'Roof', 'type' => 'radio', 'display' => 'icon_tiles', 'choices' => "Gable\nFlat", 'option_media' => "home\nsquare"]]]);
    $this->assertSame(2, substr_count($html, 'type="radio"'));
    $this->assertStringContainsString('tl-tiles', $html);
}

public function testTimeRangeHasTwoTimeInputs(): void
{
    $html = $this->renderForm(['fields' => [['label' => 'When', 'type' => 'timerange', 'required' => true]]]);
    $this->assertSame(2, substr_count($html, 'type="time" name="p0[]"'));
}

public function testLiveFormCarriesLogicForTheScript(): void
{
    $html = $this->renderForm(['fields' => [['label' => 'A', 'type' => 'radio', 'choices' => "Yes\nNo"], ['label' => 'B', 'type' => 'text']],
        'logic' => ['rules' => [['match' => 'all', 'when' => [['field' => 'kaaaa', 'op' => 'is', 'value' => 'Yes']], 'then' => [['do' => 'skip', 'field' => 'kbbbb']]]], 'results' => []]], keys: ['kaaaa', 'kbbbb']);
    $this->assertStringContainsString('data-field="1"', $html);
    $this->assertStringContainsString('data-logic=', $html);
    $this->assertStringNotContainsString('data-when', $html);
}
```

`renderForm(array $content, array $keys = [])` is a private helper in the test: puts the keys onto the fields, runs `Build::sanitize`, then `Build::html` with an editor-less `Context`.

- [ ] **Step 2: Run** `RUN vendor/bin/phpunit tests/Unit/Builder/FormRenderTest.php` — FAIL.
- [ ] **Step 3: Implement.** Tiles: the same `<label><input …> …</label>` markup inside `<fieldset class="tl-tiles tl-tiles--image|icon">`; the image is `<img src alt="" loading="lazy">` (the label text names it), the icon via `Builder\Icons`. Slider: `<input type="range" min max step>` plus `<output>` that the script fills. CSS for `.tl-tiles`, `.tl-timerange`, `.tl-form-progress` in `image/web.css` under `:where()`. Remove the `data-when` branch from `web.js` only in Task 6 (it still works until then; this task stops printing it).
- [ ] **Step 4: Run** unit suite and `RUN vendor/bin/phpunit tests/Unit/ScriptsTest.php` — PASS.
- [ ] **Step 5: Commit** `git commit -m "Form: answer tiles, slider, time range; no amounts in result-after-contact pages"`

---

### Task 5: Submitting – server result, result link, e-mail block

**Files:**
- Modify: `system/src/Front/Forms.php:91-245`
- Create: `system/src/Front/FormResult.php`
- Modify: `system/src/Builder/Elements/Form.php:164-175` (`render` shows the result screen)
- Create: `tests/Site/CollectionsB/QuizFormTest.php`
- Modify: `tests/Site/CollectionsB/CalculatorFormTest.php` (expected data now ends with the Breakdown pair)

**Interfaces:**
- Consumes: `FormLogic::evaluate`, `Core\Preview::key/verify`.
- Produces:
  - `FormResult::url(App $app, string $back, array $element, string $enquiryPublicId, string $variant): string` — `$back?form=<el>&result=ok&enquiry=<uuid>&variant=<key>&key=<Preview key for "form-result:<uuid>:<variant>", 1440 min>#<anchor>`
  - `FormResult::html(App $app, array $p, Context $k): ?string` — null when the request carries no result parameters; the "no longer available" screen when verification, enquiry lookup (same `source`+`element`, `deleted_at IS NULL`, `anonymised_at IS NULL`) or the variant fails.
  - `FormResult::mailBlock(array $content, array $answers, array $evaluation): string`
  - Enquiry `data`: the estimate field's pair carries `shown` (or the variant heading, plus `shown` when `show_amount`); a pair `[t('Breakdown'), "label: ±amount\n…"]` follows when there are lines. Both modes.

- [ ] **Step 1: Write the failing site tests** in `QuizFormTest` (pattern of `CalculatorFormTest`: build via MCP `save_build` publish, `sleep(4)`, post `/form`):
  - `testAfterContactShowsTheServerResult`: post answers → redirect contains `result=ok&enquiry=` and `key=`; GET the redirect → contains the range text (e.g. `2,700 – 3,500`) and every breakdown label; contains no e-mail address.
  - `testVariantWins`: answers matching the "Abroad" rule → page shows the variant heading, not the range (`show_amount` false).
  - `testHiddenRequiredQuestionDoesNotBlock`: a required question skipped by a rule is left out of the post → `result=ok`.
  - `testMalformedTimeRangeIsAFieldError`: `p1[] = ['25:00', '02:00']` → `result=field&field=1`.
  - `testResultLinkFailsClosed`: (a) change one character of `key` → "This result is no longer available." and the form's button; (b) valid link, then `UPDATE tl_enquiries SET deleted_at = NOW()` → same; (c) `anonymised_at` set → same. All status 200.
  - `testThankYouPageIgnoredAfterContact`: form with `thank_you_page` `/contact` → redirect stays on the form page.
  - `testConfirmationMailHasResultBlockWithoutFreeText`: `confirmation` true, a textarea answer `SECRET-TEXT`, name `Jane` → the queued mail (`tl_mail_queue` or how `Mail::send` stores it — read `Core\Mail`) contains the range and a chosen option label, contains neither `SECRET-TEXT` nor `Jane`.
- [ ] **Step 2: Run** `RUN vendor/bin/phpunit --testsuite site --filter QuizFormTest` — FAIL.
- [ ] **Step 3: Implement.** In `Forms::process`: build `$answers` as today plus `timerange` (`postList` of two `HH:MM`, joined with `-`; malformed → field error); `$eval = FormLogic::evaluate($element['content'], $answers)`; use `$eval['visible']` where `Form::visible` was; the estimate pair as above; after insert, in `after_contact` return `Response::redirect(FormResult::url(...), 303)` and never `thankYouPage`; append `FormResult::mailBlock` to the confirmation text in `after_contact`. In `Form::render`, before the `$result === 'ok'` thank-you branch, `if (($screen = FormResult::html($k->app, $p, $k)) !== null) return $screen;` — the screen keeps `data-sent` (conversion) and shows heading, amount, breakdown list, the variant image/text/button or, for the range, the thank-you text and next steps.
- [ ] **Step 4: Run** `--filter 'QuizFormTest|CalculatorFormTest'` — PASS; then the same on PostgreSQL (`TALEA_TEST_DB_DRIVER=pgsql`).
- [ ] **Step 5: Commit** `git commit -m "Forms: result after contact with a signed 24-hour result link"`

---

### Task 6: `web.js` – visibility from rules, live range, step flow

**Files:**
- Modify: `image/web.js:499-604`
- Modify: `system/src/Front/Seo.php` script texts list (new `T()` strings)
- Modify: `tests/Browser/BrowserWalkTest.php`

**Interfaces:**
- Consumes: `data-logic`, `data-field`, `data-advance` (Task 4), `tests/Fixtures/form-logic.json`.
- Produces: `window.taleaFormLogic = {visible(logic, answers) → bool[], total(form, logic, visible) → number}` (exposed for the browser test only through the same object the code uses, no test-only branch).

- [ ] **Step 1: Write the failing browser test** `testFormLogicMatchesTheServer`: for each fixture case with `calculation` `live`, publish it via MCP, open the page in Chrome, set the answers, read which `[data-field]` boxes are hidden and the estimate `<output>`; compare with `visible` and with `total` (parsed number). Also: clicking a tile with `advance` moves to the next step; `history.back()` returns one step; reload keeps the answers.
- [ ] **Step 2: Run** `RUN composer test:browser` (CI image, see `reference` notes in `tests/Site/README.md`) — FAIL.
- [ ] **Step 3: Implement.** Replace `refreshForm`'s `data-when` loop with an evaluator over `data-logic` that follows the same rules as `FormLogic::visible` (order, skip wins, hidden answer empty, later field false); disabled inputs inside hidden boxes as today. Live total: base + `data-price` / `data-price-per` as today + rule `add`/`quantity`, then the range per `estimate` and `Intl.NumberFormat`. Step flow: skip steps whose fields are all hidden; `<progress>` before the steps with the aria-live text kept; `advance`: move on when a radio in `[data-advance]` receives a `click` with `event.detail > 0` (pointer, not keyboard); `history.pushState({tlStep})` on Next, `popstate` shows that step; store answers in the existing `tl-form-<element>` `sessionStorage` entry on every `input` and restore on load (not only after an error).
- [ ] **Step 4: Run** browser test — PASS; `RUN vendor/bin/phpunit tests/Unit/ScriptsTest.php` — PASS.
- [ ] **Step 5: Commit** `git commit -m "Forms in the browser: rule visibility, live range, progress, back and resume"`

---

### Task 7: Drop-off counters

**Files:**
- Create: `system/database/migrations/20261029100000_create_form_steps.php`
- Create: `system/src/Front/FormSteps.php`
- Modify: `system/src/Front/Kernel.php:278` (route `POST /form-step` next to `/popup`)
- Modify: `image/web.js` (beacon)
- Modify: `system/src/Admin/Modules/Forms.php:50-79`, `system/views/admin/forms/detail.php`
- Modify: `system/src/Mcp/Handlers/SettingsTools.php:305` (`get_stats` gets `forms`), `system/src/Mcp/Tools.php:222` (description)
- Create: `tests/Site/FormsLook/FormStepsTest.php`

**Interfaces:**
- Produces:
  - Table `form_steps`: `source` string(40), `element` string(16), `step` smallint unsigned, `day` date, `reached` integer unsigned default 0; primary key (`source`, `element`, `step`, `day`).
  - `FormSteps::record(Db $db, string $source, string $element, int $step): void` — `Db::upsert` incrementing `reached` (`{old.reached} + 1`, see `Dialect::upsert`); 1 in 50 calls deletes rows older than 400 days (as `Antispam::write`).
  - `FormSteps::funnel(Db $db, string $source, string $element, int $days): list<array{step: int, reached: int}>`
  - Beacon: `navigator.sendBeacon(<form action with "form" → "form-step">, FormData{source, element, step})`; step 1 on the first `input` in the form, step n when the visitor first enters step n; once per step per page view.
  - Kernel: internal source via `Forms::internalSource` must be non-empty, `element` `/^[a-z0-9]{3,16}$/`, `step` 1–40, `Antispam::count(ip, 'form_step', 0, 60) < 120`; always 204.

- [ ] **Step 1: Write the failing site test** `FormStepsTest`: publish a 3-step form; POST `/form-step` steps 1,1,2 → `funnel` rows `[[1,2],[2,1]]`; a junk source → no row; the Forms detail page shows the step titles and `<meter`; MCP `get_stats` returns `forms[0].steps`; the page HTML contains no visitor id/cookie set by the beacon route (response has no `Set-Cookie`).
- [ ] **Step 2: Run** `--filter FormStepsTest` — FAIL.
- [ ] **Step 3: Implement** the migration (`RUN bin/migrate` locally), `FormSteps`, the route, the beacon, the admin section (period select 7/30/365 days, one `<meter>` per step with the step title and percentage of step 1, the number of enquiries, one sentence naming the biggest drop between two consecutive steps), the `get_stats` section `{form, page, steps: [{step, title, reached}], sent}`. Update `tests/Unit/Mcp/ParityTest.php` if a new admin action appears (the detail view stays `$readOnly`).
- [ ] **Step 4: Run** the test on MySQL, PostgreSQL and SQLite (`composer test:sqlite` filter) — PASS.
- [ ] **Step 5: Commit** `git commit -m "Forms: drop-off counters per step without cookies"`

---

### Task 8: MCP `try_form` and the builder's try action

**Files:**
- Modify: `system/src/Mcp/Tools.php` (definition next to `get_build`), `system/src/Mcp/Catalog.php:38` (`'try_form' => ['read', '']`), `system/src/Mcp/Handlers/BuilderTools.php` (`toolTryForm`)
- Modify: `system/src/Admin/BuilderActions.php` (`actionBuildFormTry`)
- Modify: `tests/Unit/Mcp/ParityTest.php:23` (`'build_form_try' => 'try_form'`), `tools/contracts/mcp-tools.json` (`RUN php tools/contracts.php --update`)
- Modify: `system/src/Builder/Elements/Form.php` `DESCRIPTION`/property labels so `builder_schema` explains rules, operators, actions and results (the schema is how Claude learns the shape)
- Create: `tests/Site/McpBuilder/TryFormTest.php`

**Interfaces:**
- Consumes: `FormLogic::evaluate`, `loadBuildTarget`, `findElement` in `BuilderTools`.
- Produces: `try_form` input = the `get_build` target arguments + `element` (string, required) + `answers` (object: field key → string or list of strings) + `published` (boolean, default false = draft). Output `{visible: [keys], lines: [{label, amount}], total, shown, variant, unknown_keys: [...]}`. `actionBuildFormTry` takes the same (target from the builder, `element`, `answers` JSON) and returns the same JSON; it reads the current draft.

- [ ] **Step 1: Write the failing site test** `TryFormTest`: save a draft form (not published) with the wedding fixture's content; `try_form` with the fixture's answers mapped to keys → `total` and `shown` equal the fixture; `published: true` on a page without a published form → error text; an unknown key → listed in `unknown_keys`; the admin action with the same input returns the same JSON.
- [ ] **Step 2: Run** `--filter TryFormTest` and `RUN vendor/bin/phpunit tests/Unit/Mcp` — FAIL.
- [ ] **Step 3: Implement** the tool, the action, the schema descriptions, the contract update, the parity entry.
- [ ] **Step 4: Run** both — PASS; `RUN php tools/contracts.php` exits 0.
- [ ] **Step 5: Commit** `git commit -m "MCP try_form: run a form's logic with given answers"`

---

### Task 9: Builder – inspector additions and the logic overlay

**Files:**
- Create: `image/builder-form-logic.js` (loaded after `builder.js` like `builder-structured.js`; exposes `window.taleaFormLogicField(def, value, change, ctx)`)
- Modify: `system/views/admin/pages/builder.php` (script tag, as for `builder-structured.js`)
- Modify: `image/builder.js` (`case 'form_logic'`; top-level `when` on element properties so `thank_you_page` hides when `calculation` is `after_contact`; per-option media picker rows for `option_media`)
- Modify: `image/builder.css`, `image/languages/admin-*.js` (via `tools/add-translations.py`)
- Modify: `system/src/Builder/Elements/Form.php` (`thank_you_page` gets `'when' => ['calculation' => 'live']` and a help line)
- Modify: `tests/Browser/BrowserWalkTest.php`

**Interfaces:**
- Consumes: `actionBuildFormTry` (Task 8), the form's `fields` (keys, labels, types, options) from the element being edited.
- Produces: the inspector shows a button `T('Edit logic…')` with the summary `T('%d rules, %d results')`; the overlay is a `<dialog>` with tabs **Rules · Results · Try it**; the left column lists the questions (pick only). Saving writes `content.logic` through the editor's normal `applyChange` (one undo step) and clears converted `show_when_field/value` pairs.

- [ ] **Step 1: Write the failing browser test** `testLogicOverlay`: open the builder on a page with a form that has a legacy condition; open the overlay → the legacy condition appears as a rule; add a rule "Location is Abroad → multiply 1.3" by picking from the lists; Results tab: add a variant; Try it: choose answers → the total shown equals `try_form` for the same answers; close, autosave, reload → `get_build` has the rule and no `show_when_field`; switching calculation to after_contact hides the thank-you page field; no console errors.
- [ ] **Step 2: Run** `RUN composer test:browser` — FAIL.
- [ ] **Step 3: Implement.** Rules tab: list in run order with drag to reorder, each rule as a sentence (`T('When all/any of')` …); editing picks a question, then an operator from those valid for its type, then a value (option list for choices, number inputs, weekday/month selects). Results tab: the fixed fields with the Media picker for the image and the link menu for the button. Try it: one input per visible question, calls `build_form_try` on change. All texts through `T()`, no inline handlers.
- [ ] **Step 4: Run** browser test — PASS; `RUN vendor/bin/phpunit tests/Unit/ScriptsTest.php` — PASS.
- [ ] **Step 5: Commit** `git commit -m "Builder: logic overlay for forms with rules, results and a try-out"`

---

### Task 10: Starter section, texts, docs, checks

**Files:**
- Modify: `system/src/Builder/Library.php` (section `quick-check` in category `action`)
- Modify: `system/languages/*.php`, `system/languages/admin-*.php` (via `tools/add-translations.py`)
- Modify: `docs/manual.md` (Forms chapter: calculator, rules, result after contact, statistics), `docs/ROADMAP.md` (entry under the next release)
- Modify: `docs/specs/form-calculator.md` (status line: built; the two deviations noted below)
- Modify: `tests/Unit/Builder/LibraryTest.php`, `tests/Unit/Builder/SampleContentTest.php` if they enumerate sections

**Interfaces:**
- Produces: library section "Quick check with estimate": a Section with a heading `[Your quick check]`, a short text, and a Form in `after_contact` with 3 steps (`[Question 1]` image tiles `[Answer] | [amount]`…, `[Question 2]` slider, `[Question 3]` icon tiles), name/e-mail/consent, an estimate field with `range_below` 10, `range_above` 15, `round_to` 50, `unit_suffix` `[unit]`, and one result variant `[Heading]`.

- [ ] **Step 1: Write the failing test**: `LibraryTest::testQuickCheckSectionSanitizesAndIsFlaggedAsSample` — the section passes `Build::sanitize` with no errors and `Builder\SampleContent` finds its placeholders.
- [ ] **Step 2: Run** — FAIL.
- [ ] **Step 3: Implement** the section, translations, docs.
- [ ] **Step 4: Run** `RUN composer test:unit`, `RUN php tools/check-english.php`, `RUN php tools/sql-dialect-scan.php`, `RUN php tools/contracts.php`, then the site suite with 2 processes on MySQL and PostgreSQL — all PASS / 0.
- [ ] **Step 5: Commit** `git commit -m "Quick check starter section, translations and docs for the form calculator"`

---

## Deviations from the spec (decided while planning)

- **Range defaults are 0 / 0 / no rounding on the estimate field**, so existing live forms keep showing exactly today's amount (spec §2 "behave exactly as today" outranks §6's −10 %/+15 % default). The starter section and the overlay's "new estimate" carry 10 / 15 / 50.
- **The result pair keeps the estimate field's own label** instead of a fixed "Result" label; the Breakdown pair is added as specified.
- **The result screen arrives by the normal redirect** to the same page and anchor, also with JavaScript (no fetch-based submit). It is the same page, and it keeps one code path.
- **Old counters are pruned like `ip_checks`** (1 in 50 writes), not by the cron.

Task 10 writes these into the spec.
