// Talea – form logic in Chrome (run by tests/Browser/BrowserWalkTest.php::testFormLogicMatchesTheServer): the script must hide the same
// questions and compute the same total and estimate as the server, and the quiz flow (move on after a tile, Back, resume, step beacons)
// must work. Env: BASE, CHROME, NODE_PATH, CASES (JSON file: {cases: [...], quiz: {slug}, basket: {slug}}).
import { createRequire } from 'node:module';
import { readFileSync } from 'node:fs';

const require = createRequire(`${process.env.NODE_PATH}/`);
const { chromium } = require('playwright-core');
const { BASE, CHROME, CASES } = process.env;
const { cases, quiz, basket } = JSON.parse(readFileSync(CASES, 'utf8'));

const browser = await chromium.launch({ executablePath: CHROME, headless: true });
const context = await browser.newContext({ viewport: { width: 1000, height: 900 }, locale: 'en-GB' });
await context.addInitScript(() => {
  window.__beacons = [];
  const send = navigator.sendBeacon.bind(navigator);
  navigator.sendBeacon = (url, data) => { window.__beacons.push({ url, step: data instanceof FormData ? data.get('step') : null, source: data instanceof FormData ? data.get('source') : null }); return send(url, data); };
});
const page = await context.newPage();
const failures = [];
page.on('pageerror', (e) => failures.push(`script error: ${e.message}`));
const check = (ok, message) => { if (!ok) { failures.push(message); } };

// sets the answers the way a visitor would, field by field (by index), through the real events
async function answer(fields, answers) {
  for (const [index, value] of Object.entries(answers)) {
    const field = fields[Number(index)];
    await page.evaluate(([i, f, v]) => {
      const fire = (el) => { el.dispatchEvent(new Event('input', { bubbles: true })); el.dispatchEvent(new Event('change', { bubbles: true })); };
      const named = [...document.querySelectorAll(`[name="p${i}"], [name="p${i}[]"]`)];
      if (f.type === 'radio') { const el = named.find((n) => n.value === v); if (el) { el.checked = true; fire(el); } }
      else if (f.type === 'checkboxes') { named.forEach((n) => { n.checked = v.includes(n.value); fire(n); }); }
      else if (f.type === 'timerange') { const [a, b] = v.split('-'); named[0].value = a; named[1].value = b; fire(named[1]); }
      else if (named[0] && v !== '') { named[0].value = v; fire(named[0]); }
    }, [index, field, value]);
  }
}

for (const c of cases) {
  await page.goto(`${BASE}/${c.slug}`, { waitUntil: 'networkidle' });
  await answer(c.fields, c.answers);
  const seen = await page.evaluate(() => [...document.querySelectorAll('.tl-condition[data-field]')].map((b) => [Number(b.dataset.field), b.hidden]));
  const hidden = Object.fromEntries(seen);
  c.visible.forEach((shown, i) => check((hidden[i] === undefined ? false : hidden[i]) === !shown, `${c.name}: question ${i} should be ${shown ? 'shown' : 'hidden'}`));
  if (c.total !== null) {
    const total = await page.evaluate(() => { const f = document.querySelector('form[data-logic]'); const logic = JSON.parse(f.dataset.logic); return window.taleaFormLogic.total(f, logic, window.taleaFormLogic.visible(logic, window.taleaFormLogic.answers(f, logic))); });
    check(Math.abs(total - c.total) < 0.001, `${c.name}: total ${total}, server ${c.total}`);
  }
  if (c.shown !== null) {
    const text = (await page.locator('[data-estimate] output').first().textContent()).replace(/ /g, ' ');
    check(text === c.shown, `${c.name}: estimate "${text}", server "${c.shown}"`);
  }
  console.log(`  ${failures.length ? 'FAIL' : 'ok  '}  ${c.name}`);
}

// the quiz: a tile moves on, a skipped step is left out, Back goes one step back, a reload keeps the answers, the beacons count steps once
await page.goto(`${BASE}/${quiz.slug}`, { waitUntil: 'networkidle' });
const stepShown = () => page.evaluate(() => [...document.querySelectorAll('fieldset.tl-step')].map((s) => !s.hidden));
check(JSON.stringify(await stepShown()) === '[true,false,false]', 'quiz: starts at step 1');
check(await page.locator('progress.tl-form-progress').count() === 1, 'quiz: progress is shown');
await page.click('label:has-text("Pitched")');
check(JSON.stringify(await stepShown()) === '[false,true,false]', `quiz: a tile moves on to step 2, got ${JSON.stringify(await stepShown())}`);
await page.fill('input[name="p2"]', 'abc');
await page.goBack();
check(JSON.stringify(await stepShown()) === '[true,false,false]', 'quiz: the browser Back returns one step');
const focusIn = (n) => page.evaluate((i) => document.activeElement?.closest('fieldset.tl-step') === document.querySelectorAll('fieldset.tl-step')[i], n);
check(await focusIn(0), 'quiz: the browser Back puts focus into step 1');
await page.click('label:has-text("Flat")');
check(JSON.stringify(await stepShown()) === '[false,false,true]', `quiz: a skipped step is left out, got ${JSON.stringify(await stepShown())}`);
check(/Step 2 \/ 2/.test(await page.locator('.tl-steps-navigation span').textContent()), 'quiz: the step count leaves out the skipped step');
const beacons = await page.evaluate(() => window.__beacons.map((b) => b.step).sort());
check(JSON.stringify(beacons) === '["1","2","3"]', `quiz: beacons once per step, got ${JSON.stringify(beacons)}`);
check((await page.evaluate(() => window.__beacons[0].url)).endsWith('/form-step'), 'quiz: beacon goes to form-step');
await page.click('.tl-steps-navigation button:has-text("Back")');
check(JSON.stringify(await stepShown()) === '[true,false,false]', 'quiz: the Back button returns to step 1');
check(await focusIn(0), 'quiz: the Back button leaves focus in step 1');
await page.reload({ waitUntil: 'networkidle' });
check(await page.locator('input[name="p0"][value="Flat"]').isChecked(), 'quiz: a reload keeps the tile');
check(await page.locator('input[name="p2"]').inputValue() === 'abc', 'quiz: a reload keeps the text');
console.log(`  ${failures.length ? 'FAIL' : 'ok  '}  quiz flow`);

// a hidden field is the page's own (a collection item's value, the basket): answers kept for the same form element never restore it
await page.goto(`${BASE}/${basket.slug}`, { waitUntil: 'networkidle' });
await page.evaluate(() => {
  const id = document.querySelector('form[data-form]').dataset.form;
  sessionStorage.setItem(`tl-form-${id}`, JSON.stringify({ p0: '[{"c":"ghost","i":"ghost","n":"Ghost product","v":"","q":1}]', p1: 'kept' }));
});
await page.reload({ waitUntil: 'networkidle' });
check(await page.locator('input[name="p1"]').inputValue() === 'kept', 'basket: a reload keeps the text');
check(await page.locator('input[name="p0"]').inputValue() === '[]', `basket: the hidden field keeps its own value, got ${await page.locator('input[name="p0"]').inputValue()}`);
check(!(await page.locator('[data-basket-list]').textContent()).includes('Ghost'), 'basket: a stored hidden value does not bring a product back');
await page.fill('input[name="p1"]', 'typed');
const stored = await page.evaluate(() => JSON.parse(sessionStorage.getItem(`tl-form-${document.querySelector('form[data-form]').dataset.form}`)));
check(stored.p1 === 'typed' && !('p0' in stored), `basket: the hidden field is not stored, got ${JSON.stringify(stored)}`);
console.log(`  ${failures.length ? 'FAIL' : 'ok  '}  hidden field not stored or restored`);

await browser.close();
if (failures.length) { console.error(failures.join('\n')); process.exit(1); }
