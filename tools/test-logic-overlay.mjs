// Talea – the builder's logic overlay in Chrome (run by tests/Browser/BrowserWalkTest.php::testLogicOverlay): the thank-you page field
// hides in "after contact" mode, a legacy condition shows as a rule, a rule and a result variant are added by picking from the lists,
// Try it shows the server's answer, and the saved draft keeps it all after a reload. Fails on any script or console error.
// Env: BASE, PASSWORD, CHROME, NODE_PATH, PAGE (public id of the page with the form frm1), OUT (JSON file the PHP test reads back), SHOTS (optional folder for screenshots).
import { createRequire } from 'node:module';
import { writeFileSync } from 'node:fs';

const require = createRequire(`${process.env.NODE_PATH}/`);
const { chromium } = require('playwright-core');
const { BASE, PASSWORD, CHROME, PAGE, OUT, SHOTS } = process.env;
const shot = (name) => (SHOTS ? page.screenshot({ path: `${SHOTS}/logic-${name}.png` }) : null); // optional: screenshots to look at

const browser = await chromium.launch({ executablePath: CHROME, headless: true });
const context = await browser.newContext({ viewport: { width: 1440, height: 900 }, locale: 'en-GB' });
await context.addInitScript(() => { try { localStorage.setItem('tl-bd-tour', '1'); } catch (e) { /* ignore */ } });
const page = await context.newPage();
const failures = [];
page.on('pageerror', (e) => failures.push(`script error: ${e.message}`));
page.on('console', (m) => { if (m.type() === 'error' && !/Failed to load resource/.test(m.text())) { failures.push(`console: ${m.text()}`); } });
const check = (ok, message) => { if (!ok) { failures.push(message); } };
const builder = `${BASE}/admin.php?module=pages&action=builder&id=${PAGE}`;
const openForm = async () => {
  await page.goto(builder, { waitUntil: 'networkidle' });
  await page.evaluate(() => window.taleaBuilder.selection('frm1'));
  await page.locator('.bd-logic-field').waitFor();
};
const answers = { kloc0: 'Abroad', kgue0: '10' };
let shown = '';

try {
  await page.goto(`${BASE}/admin.php`);
  await page.fill('input[name="username"]', 'admin');
  await page.fill('input[name="password"]', PASSWORD);
  await Promise.all([page.waitForNavigation(), page.press('input[name="password"]', 'Enter')]);

  // the thank-you page does not apply when the result shows at the form
  await openForm();
  const right = page.locator('.bd-right');
  check(await right.getByText('After sending, go to a page').count() === 1, 'the thank-you page field shows in live mode');
  await right.getByLabel('Show the estimate').selectOption('after_contact');
  check(await right.getByText('After sending, go to a page').count() === 0, 'the thank-you page field hides in after-contact mode');
  check(await right.locator('.bd-field-note').count() === 1, 'a note says why the thank-you page is not offered');
  check(/1 rules, 0 results/.test(await right.locator('.bd-logic-field').innerText()), 'the summary counts the old condition as a rule');

  // the legacy condition shows as the first rule
  await right.getByRole('button', { name: 'Edit logic…' }).click();
  const dialog = page.locator('dialog.bd-logic[open]');
  await dialog.waitFor();
  check(await dialog.locator('.bd-logic-questions li').count() === 4, 'the questions list leaves out the estimate');
  const first = dialog.locator('.bd-logic-rule').first();
  const sentence = await first.locator('.bd-logic-sentence').innerText();
  check(/Location is Home/.test(sentence) && /Extras/.test(sentence), `the legacy condition reads as a rule: "${sentence}"`);

  // a new rule: Location is Abroad → multiply by 1.3
  await dialog.getByRole('button', { name: 'Add rule' }).click();
  const rule = dialog.locator('.bd-logic-rule').last();
  await rule.getByRole('button', { name: 'Add condition' }).click();
  await rule.getByLabel('Question', { exact: true }).last().selectOption({ label: 'Location' });
  check(JSON.stringify(await rule.getByLabel('Condition', { exact: true }).last().locator('option').evaluateAll((o) => o.map((x) => x.value))) === '["is","is_not","empty","not_empty"]', 'a single choice offers is / is not / empty');
  await rule.getByLabel('Condition', { exact: true }).last().selectOption('is');
  await rule.getByLabel('Value', { exact: true }).last().selectOption('Abroad');
  await rule.getByRole('button', { name: 'Add action' }).click();
  await rule.getByLabel('Action', { exact: true }).last().selectOption('multiply');
  await rule.getByLabel('Factor', { exact: true }).last().fill('1.3');
  await rule.getByLabel('Label (a line in the breakdown)').fill('Surcharge abroad');
  await shot('rules');
  const added = await rule.locator('.bd-logic-sentence').innerText();
  check(/Location is Abroad/.test(added) && /1\.3/.test(added), `the new rule reads as a sentence: "${added}"`);

  // a result variant
  await dialog.getByRole('tab', { name: 'Results' }).click();
  await dialog.getByRole('button', { name: 'Add result' }).click();
  await dialog.locator('.bd-logic-result').last().getByLabel('Heading').fill('Personal offer');
  await shot('results');

  // Try it: the server's answer for the current draft
  await dialog.getByRole('tab', { name: 'Try it' }).click();
  const tryPane = dialog.locator('.bd-logic-try');
  await tryPane.getByLabel('Location').selectOption('Abroad');
  await tryPane.getByLabel('Guests').fill('10');
  await page.waitForFunction(() => /Surcharge abroad/.test(document.querySelector('dialog.bd-logic .bd-logic-lines')?.textContent || ''), null, { timeout: 10000 });
  await shot('try');
  shown = (await dialog.locator('.bd-logic-shown').innerText()).replace(/ /g, ' ').trim();
  check(shown !== '', 'Try it shows the result');
  check(await tryPane.getByLabel('Extras').isHidden(), 'Try it hides the question the rule leaves out');

  // closing keeps it as one change of the draft; after a reload the draft has it
  await dialog.getByRole('button', { name: 'Done' }).click();
  check(await page.evaluate(() => window.taleaBuilder.save()), 'the draft saves');
  await openForm();
  check(/2 rules, 1 results/.test(await page.locator('.bd-logic-field').innerText()), 'the summary after a reload');
  const form = await page.evaluate(() => window.taleaBuilder.find('frm1').p.content);
  check(form.logic.rules.length === 2 && !form.fields.some((f) => f.show_when_field), 'the reloaded draft has two rules and no old condition pair');
  await page.locator('.bd-right').getByRole('button', { name: 'Edit logic…' }).click();
  check(await page.locator('dialog.bd-logic[open] .bd-logic-rule').count() === 2, 'the overlay shows both rules after a reload, the legacy one only once');
  await page.keyboard.press('Escape');
} catch (e) {
  failures.push(e.message.split('\n').slice(0, 4).join(' '));
}

writeFileSync(OUT, JSON.stringify({ answers, shown }));
await browser.close();
console.log(failures.length ? 'FAIL  logic overlay' : 'ok    logic overlay');
if (failures.length) { console.error(failures.join('\n')); process.exit(1); }
