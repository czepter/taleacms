// Talea – browser test (run by tests/Browser/BrowserWalkTest.php): walks the admin, the builder, the news editor, the menu
// editor and the public site in Chrome and fails on any uncaught script error or console error.
// Env: BASE, PASSWORD (admin), CHROME (browser binary), NODE_PATH (folder with playwright-core), SHOTS (optional folder
// for screenshots of new screens, to look at them).
import { createRequire } from 'node:module';

const require = createRequire(`${process.env.NODE_PATH}/`);
const { chromium } = require('playwright-core');
const { BASE, PASSWORD, CHROME, SHOTS } = process.env;

const browser = await chromium.launch({ executablePath: CHROME, headless: true });
const context = await browser.newContext({ viewport: { width: 1440, height: 900 }, locale: 'en-GB' });
await context.addInitScript(() => { try { localStorage.setItem('tl-bd-tour', '1'); } catch (e) { /* ignore */ } });
const page = await context.newPage();
const errors = [];
let where = '';
const watch = (p) => {
  p.on('pageerror', (e) => errors.push(`${where}: ${e.message}`));
  p.on('console', (m) => { if (m.type() === 'error' && !/Failed to load resource/.test(m.text())) errors.push(`${where}: ${m.text()}`); });
};
watch(page);
page.on('frameattached', () => {}); // builder canvases are iframes of the same page: their errors arrive through the page
const canvas = () => page.frameLocator('.bd-canvas iframe').first();
let steps = 0;

async function step(name, fn) {
  where = name;
  const before = errors.length;
  try {
    await fn();
    await page.waitForTimeout(300);
  } catch (e) {
    errors.push(`${name}: ${e.message.split('\n').slice(0, 4).join(' ')}`);
  }
  steps++;
  console.log(`  ${errors.length === before ? 'ok   ' : 'FAIL '}  ${name}`);
}
const visit = (url) => page.goto(BASE + url, { waitUntil: 'networkidle' });

await step('sign in', async () => {
  await visit('/admin.php');
  await page.fill('input[name="username"]', 'admin');
  await page.fill('input[name="password"]', PASSWORD);
  await Promise.all([page.waitForNavigation(), page.press('input[name="password"]', 'Enter')]);
});

// the administration names a page by its public id: take the first one from the list
let PAGE = '';
await step('find a page', async () => {
  await visit('/admin.php?module=pages');
  PAGE = new URL(await page.locator('a[href*="action=edit&id="]').first().getAttribute('href'), BASE).searchParams.get('id') ?? '';
});

for (const url of ['/admin.php', '/admin.php?module=pages', '/admin.php?module=pages&action=new', `/admin.php?module=pages&action=edit&id=${PAGE}`,
  '/admin.php?module=news', '/admin.php?module=collections', '/admin.php?module=categories', '/admin.php?module=tags', '/admin.php?module=media',
  '/admin.php?module=appearance', '/admin.php?module=parts', '/admin.php?module=components', '/admin.php?module=popups', '/admin.php?module=users',
  '/admin.php?module=roles', '/admin.php?module=stats', '/admin.php?module=redirects', '/admin.php?module=changelog', '/admin.php?module=transfer',
  '/admin.php?module=extensions', '/admin.php?module=enquiries', '/admin.php?module=subscribers', '/admin.php?module=newsletters', '/admin.php?module=settings',
  '/admin.php?module=settings&tab=seo', '/admin.php?module=settings&tab=analytics', '/admin.php?module=settings&tab=backups', '/admin.php?module=status', '/admin.php?action=account',
  '/admin.php?module=bookings']) {
  await step(`open ${url}`, () => visit(url));
}

await step('appearance: change a colour and preview', async () => {
  await visit('/admin.php?module=appearance');
  const colour = page.locator('input[type="color"]:visible').first();
  if (await colour.count()) { await colour.fill('#335577'); await page.waitForTimeout(800); }
});

await step('appearance: save to the draft look, preview bar, publish', async () => {
  await visit('/admin.php?module=appearance');
  await page.getByRole('tab', { name: 'Colours' }).click(); // the save button is hidden on the tabs outside the form (Style presets, Import and export)
  // the colour field may sit on a tab that is not open – set the value directly
  await page.evaluate(() => { document.querySelectorAll('[name="ds[colors][primary]"]').forEach((i) => { i.value = '#335577'; }); });
  await Promise.all([page.waitForNavigation(), page.locator('.appearance-save input[type="submit"]').click()]);
  await page.locator('#appearance-draft').waitFor();
  if (SHOTS) { await page.screenshot({ path: `${SHOTS}/look-draft-bar.png`, fullPage: false }); }
  await Promise.all([page.waitForNavigation(), page.locator('#appearance-draft button.btn').click()]);
  if (await page.locator('#appearance-draft').count()) { throw new Error('the draft look is still there after publishing'); }
});

const SAMPLE = 'Quick brown fox ÄÖÜäöüß ěščřžýáíéúůťďňĚŠČŘŽ ąćęłńóśźżĄĆĘŁŃÓŚŹŻ € –'; // English, German, Czech, Polish; check-english: allow

await step('fonts: every library family has the glyphs of English, German, Czech and Polish (no fallback)', async () => {
  await visit('/admin.php?module=appearance');
  const names = await page.$$eval('#ds-font-heading option[value^="lib:"]', (o) => o.map((x) => x.textContent.trim()));
  if (names.length < 25) { throw new Error('the font library has only ' + names.length + ' families'); }
  const lacking = await page.evaluate(async ([names, sample]) => {
    const ctx = document.createElement('canvas').getContext('2d');
    const width = (family, ch, fallback) => { ctx.font = `400 48px "${family}", ${fallback}`; return ctx.measureText(ch).width; };
    const result = [];
    for (const name of names) {
      await document.fonts.load(`400 48px "${name}"`, sample);
      // a glyph the font lacks comes from the fallback – and two different fallbacks measure it differently
      const missing = [...new Set(sample.replace(/ /g, ''))].filter((ch) => width(name, ch, 'monospace') !== width(name, ch, 'serif'));
      if (missing.length) { result.push(name + ': ' + missing.join('')); }
    }
    return result;
  }, [names, SAMPLE]);
  if (lacking.length) { throw new Error('glyphs fall back to another font in ' + lacking.join(' | ')); }
});

await step('fonts: choose library fonts, publish, the public page loads only those files from its own host', async () => {
  await visit('/admin.php?module=appearance');
  await page.getByRole('tab', { name: 'Fonts and sizes' }).click();
  await page.locator('#ds-font-heading').selectOption('lib:playfair-display');
  await page.locator('#ds-font-body').selectOption('lib:source-sans-3');
  const sample = await page.locator('[data-font-sample="ds[font_heading]"]').evaluate((e) => getComputedStyle(e).fontFamily);
  if (!sample.startsWith('"Playfair Display"')) { throw new Error('the sample under the picker does not show the chosen font: ' + sample); }
  await Promise.all([page.waitForNavigation(), page.locator('.appearance-save input[type="submit"]').click()]);
  await page.locator('#appearance-draft').waitFor();
  await Promise.all([page.waitForNavigation(), page.locator('#appearance-draft button.btn').click()]);

  const requests = [];
  const listen = (r) => requests.push(r.url());
  page.on('request', listen);
  await visit('/');
  page.off('request', listen);
  const outside = requests.filter((u) => !u.startsWith('data:') && !u.startsWith('blob:') && new URL(u).host !== new URL(BASE).host);
  if (outside.length) { throw new Error('requests to another host: ' + outside.join(', ')); }
  const fontFiles = requests.filter((u) => /\.woff2?($|\?)/.test(u)).map((u) => new URL(u).pathname.replace(/^.*\/image\/fonts\//, ''));
  const expected = ['playfair-display/playfair-display.woff2', 'source-sans-3/source-sans-3.woff2'];
  if (fontFiles.filter((f) => !expected.includes(f)).length) { throw new Error('unexpected font files: ' + fontFiles.join(', ')); }
  for (const f of expected) { if (!fontFiles.includes(f)) { throw new Error('font file not loaded: ' + f + ' (got ' + fontFiles.join(', ') + ')'); } }
  await page.evaluate(() => document.fonts.ready);
  const used = await page.evaluate(() => ({
    loaded: [...document.fonts].filter((f) => f.status === 'loaded').map((f) => f.family.replace(/"/g, '')),
    heading: getComputedStyle(document.querySelector('h1, h2') || document.body).fontFamily,
    body: getComputedStyle(document.querySelector('p') || document.body).fontFamily,
  }));
  if (!used.loaded.includes('Playfair Display') || !used.loaded.includes('Source Sans 3')) { throw new Error('document.fonts: ' + used.loaded.join(', ')); }
  if (!used.heading.startsWith('"Playfair Display"') || !used.body.startsWith('"Source Sans 3"')) { throw new Error('computed families: ' + used.heading + ' / ' + used.body); }
});

await step('builder: select, style, mobile, edit text', async () => {
  await visit(`/admin.php?module=pages&action=builder&id=${PAGE}`);
  await page.waitForTimeout(1500);
  await canvas().locator('h1').first().click();
  await page.waitForTimeout(500);
  await page.getByRole('tab', { name: 'Style' }).first().click().catch(() => page.getByText('Style', { exact: true }).first().click());
  await page.waitForTimeout(400);
  await page.getByRole('button', { name: 'Mobile' }).first().click();
  await page.waitForTimeout(1000);
  await page.getByRole('button', { name: 'Desktop' }).first().click().catch(() => {});
  await page.waitForTimeout(600);
  await canvas().locator('h1').first().click();
  await page.getByRole('tab', { name: 'Content' }).first().click().catch(() => page.getByText('Content', { exact: true }).first().click());
  const field = page.locator('.bd-panel textarea, .bd-panel input[type="text"]').first();
  if (await field.count()) { await field.fill('Browser test heading'); await page.waitForTimeout(2500); } // autosave of the draft
});

await step('builder: select the parent element', async () => {
  // broken from 1.4.0 to 3.2.0: find() returned the parent under another key than its callers read (3.2.1)
  await canvas().locator('h1').first().click();
  await page.waitForTimeout(400);
  const parent = page.locator('button[title^="Select parent element"]').first();
  if (!(await parent.count())) { throw new Error('a nested element offers no "Select parent element" button'); }
  await parent.click();
  await page.waitForTimeout(300);
});

await step('builder: element tree and search', async () => {
  const search = page.locator('input.bd-search').first();
  if (await search.count()) { await search.fill('text'); await page.waitForTimeout(400); await search.fill(''); }
  const tree = page.locator('.bd-tree [role="treeitem"], .bd-tree li').nth(1);
  if (await tree.count()) { await tree.click(); await page.keyboard.press('ArrowDown'); await page.keyboard.press('ArrowUp'); }
});

await step('builder: dialogs, classes, library and clipboard', async () => {
  await visit(`/admin.php?module=pages&action=builder&id=${PAGE}`);
  await page.waitForTimeout(1500);
  for (const title of ['Help and keyboard shortcuts (?)', 'Published versions', 'Share a link to the draft preview']) {
    const button = page.locator(`[title="${title}"]`).first();
    if (!(await button.count())) { throw new Error(`no "${title}" button`); }
    await button.click();
    await page.waitForTimeout(500);
    if (title.startsWith('Share')) { await page.getByRole('button', { name: 'Create link' }).click(); await page.waitForTimeout(1200); }
    await page.locator('dialog[open] .bd-btn', { hasText: 'Close' }).first().click();
    await page.waitForTimeout(200);
  }
  await page.locator('.bd-library button').first().click(); // a ready-made section is inserted
  await page.waitForTimeout(1200);
  await canvas().locator('h1').first().click();
  await page.waitForTimeout(400);
  await page.getByRole('tab', { name: 'Advanced' }).first().click();
  const className = page.locator('input[list="bd-dl-classes"]').first();
  await className.fill('demo-card');
  await className.press('Enter');
  await page.waitForTimeout(500);
  await page.locator('.bd-class a').first().click(); // the panel of the shared class
  await page.waitForTimeout(800);
  await page.locator('[title="Back to element"]').click();
  await page.locator('button[aria-label="More actions"]').first().click();
  await page.locator('.bd-more button', { hasText: 'Copy for another Talea site' }).first().click();
  await page.waitForTimeout(800);
  await page.locator('dialog[open] .bd-btn', { hasText: 'Close' }).first().click();
  await page.keyboard.press('Control+z');
  await page.waitForTimeout(500);
});

await step('builder: structured data (Product), preview, checklist, publish, JSON-LD on the page', async () => {
  await visit(`/admin.php?module=pages&action=builder&id=${PAGE}`);
  await page.waitForTimeout(1500);
  const pageUrl = await page.evaluate(() => JSON.parse(document.getElementById('builder-data').textContent).page.url);
  await page.locator('.bd-library button, .bd-panel button', { hasText: 'Structured data' }).first().click(); // the element is inserted and selected
  await page.waitForTimeout(1200);
  const control = page.locator('.bd-sd');
  await control.waitFor({ timeout: 5000 });
  await control.locator('input[type="search"]').fill('prod');
  await control.locator('.bd-sd-picker select').selectOption('Product');
  await control.getByLabel('Name *').first().fill('Oak chair');
  if ((await control.locator('.bd-sd-check').innerText()).includes('Required')) { throw new Error('name is filled but the checklist still lists a required property'); }
  await control.getByRole('button', { name: 'Add Offers' }).click();
  await control.locator('.bd-sd-nested').getByLabel('Price').first().fill('149.9');
  await control.locator('.bd-sd-nested').getByLabel('Price currency').first().fill('EUR');
  await page.waitForTimeout(300);
  const preview = await control.locator('.bd-sd-preview').innerText();
  const ld = JSON.parse(preview);
  if (ld['@type'] !== 'Product' || ld.name !== 'Oak chair' || String(ld.offers[0].price) !== '149.9' || ld.offers[0]['@type'] !== 'Offer') { throw new Error('the preview does not mirror the form: ' + preview.slice(0, 200)); }
  if (!(await control.locator('.bd-sd-check').innerText()).includes('Recommended')) { throw new Error('the checklist does not hint at the recommended properties'); }
  await control.getByRole('button', { name: 'Fill from page' }).click();
  await page.waitForTimeout(2500); // autosave of the draft
  await page.getByRole('button', { name: 'Publish' }).first().click();
  const confirm = page.locator('dialog[open] button', { hasText: /Publish/ }).first();
  if (await confirm.count()) { await confirm.click(); }
  await page.waitForTimeout(2000);
  await visit(pageUrl.replace(BASE, ''));
  const blocks = await page.locator('script[type="application/ld+json"]').allTextContents();
  const graph = blocks.flatMap((b) => JSON.parse(b)['@graph'] ?? []);
  if (!graph.some((n) => n['@type'] === 'Product' && n.name === 'Oak chair')) { throw new Error('the Product node is not in the JSON-LD of the public page'); }
});

await step('builder: site header', async () => {
  await visit('/admin.php?module=parts&action=builder&type=header&language=');
  await page.waitForTimeout(1500);
  await canvas().locator('nav, header').first().click().catch(() => {});
});

await step('news editor: type and format', async () => {
  await visit('/admin.php?module=news&action=new');
  await page.fill('input[name="title"]', 'Browser test');
  const editor = page.locator('[contenteditable="true"]').first();
  if (await editor.count()) {
    await editor.click();
    await page.keyboard.type('Some text for the browser test.');
    await page.keyboard.press('Control+A');
    const bold = page.locator('button[data-command="bold"], button[title*="Bold"]').first();
    if (await bold.count()) { await bold.click(); }
  }
});

await step('newsletter: draft and preview', async () => {
  await visit('/admin.php?module=newsletters&action=new');
  await page.fill('input[name="subject"]', 'Spring news from our workshop');
  await page.fill('input[name="preheader"]', 'Two new projects and a spring offer');
  await page.fill('textarea[name="intro"]', 'Hello,\n\nhere is what we have been working on this spring. The full offer is at https://example.com/offer');
  await page.fill('input[name="button_label"]', 'See all news');
  await page.fill('input[name="button_url"]', '/news');
  await Promise.all([page.waitForNavigation(), page.locator('form.form input[type="submit"]').first().click()]);
  const preview = page.locator('iframe.mailing-preview');
  await preview.waitFor();
  await page.frameLocator('iframe.mailing-preview').locator('h1').waitFor({ timeout: 5000 });
  if (SHOTS) {
    await page.screenshot({ path: `${SHOTS}/newsletter-admin.png`, fullPage: true });
    const src = await preview.getAttribute('src');
    for (const [name, width] of [['newsletter-email', 700], ['newsletter-email-phone', 390]]) {
      await page.setViewportSize({ width, height: 900 });
      await page.goto(new URL(src, BASE).href, { waitUntil: 'networkidle' });
      await page.screenshot({ path: `${SHOTS}/${name}.png`, fullPage: true });
    }
    await page.setViewportSize({ width: 1440, height: 900 });
  }
});

await step('site parts: every header and footer template renders', async () => {
  for (const [part, templates] of [['header', ['classic', 'centered', 'with-bar', 'minimal']], ['footer', ['columns', 'compact', 'imprint', 'cta']]]) {
    for (const template of templates) {
      await visit(`/admin.php?module=parts&action=templates&type=${part}`);
      await Promise.all([page.waitForNavigation(), page.locator(`input[name="template"][value="${template}"] ~ button`).click()]);
      await visit(`/?part=${part}&build=draft`);
      if (SHOTS) {
        const box = page.locator(part === 'header' ? 'header' : 'footer').last();
        await box.screenshot({ path: `${SHOTS}/part-${part}-${template}.png` });
      }
    }
    await visit('/admin.php?module=parts');
  }
});

await step('menu editor', async () => {
  await visit('/admin.php?module=menu');
  const add = page.getByRole('button', { name: /Add/ }).first();
  if (await add.count()) { await add.click().catch(() => {}); }
});

await step('public site: home, phone menu, cookies', async () => {
  await visit('/');
  const accept = page.getByRole('button', { name: /Accept|Allow/ }).first();
  if (await accept.count()) { await accept.click().catch(() => {}); }
  await page.setViewportSize({ width: 390, height: 844 });
  await visit('/');
  const toggle = page.locator('.tl-nav-switch, button[popovertarget]').first();
  if (await toggle.count()) { await toggle.click().catch(() => {}); await page.waitForTimeout(300); }
  await page.setViewportSize({ width: 1440, height: 900 });
});

for (const url of ['/services', '/contact', '/news', '/search?q=test']) {
  await step(`site ${url}`, () => visit(url));
}

await step('galleries: justified rows, slideshow, viewer by keyboard and swipe, Esc, focus returns', async () => {
  await visit('/gallery-test');
  const accept = page.getByRole('button', { name: /Accept|Allow/ }).first();
  if (await accept.count()) { await accept.click().catch(() => {}); }
  // accessibility: every photo has alt text and every gallery a name
  const bad = await page.evaluate(() => [...document.querySelectorAll('.tl-gallery img')].filter((i) => !i.getAttribute('alt')).length
    + [...document.querySelectorAll('.tl-gallery')].filter((g) => !g.getAttribute('aria-label')).length);
  if (bad) { throw new Error(`${bad} gallery photos without alt or galleries without a name`); }
  // justified rows: every row ends at the same right edge (the script evened them out) and no photo is cropped
  const rows = await page.evaluate(() => {
    const g = document.querySelector('.tl-gallery--justified');
    const box = g.getBoundingClientRect();
    const lines = new Map();
    g.querySelectorAll('img').forEach((i) => { const r = i.getBoundingClientRect(); lines.set(Math.round(r.top), Math.max(lines.get(Math.round(r.top)) ?? 0, r.right)); });
    return { width: box.right, rights: [...lines.values()] };
  });
  if (rows.rights.length > 1 && Math.abs(rows.rights[0] - rows.width) > 2) { throw new Error('the first justified row does not fill the width: ' + JSON.stringify(rows)); }
  // slideshow: the arrows are shown by the script and move the strip
  const strip = page.locator('.tl-gallery-strip').first();
  await page.locator('.tl-gallery--slideshow[data-enabled] .tl-gallery-arrows [data-step="1"]').click();
  await page.waitForTimeout(700);
  if ((await strip.evaluate((s) => s.scrollLeft)) < 10) { throw new Error('the slideshow arrow did not scroll the strip'); }
  // viewer: open with the keyboard from a gallery photo, arrows, swipe, Esc, focus back
  const photo = page.locator('.tl-gallery--fullscreen img').nth(1);
  await photo.focus();
  await page.keyboard.press('Enter');
  const dialog = page.locator('dialog.tl-lightbox[open]');
  await dialog.waitFor({ timeout: 3000 });
  const caption = () => dialog.locator('p').innerText();
  if (!(await caption()).startsWith('2 / 4')) { throw new Error('the viewer did not open on the second photo: ' + await caption()); }
  await page.keyboard.press('ArrowRight');
  if (!(await caption()).startsWith('3 / 4')) { throw new Error('ArrowRight did not move on: ' + await caption()); }
  const box = await dialog.locator('img').boundingBox();
  await page.mouse.move(box.x + box.width / 2, box.y + box.height / 2);
  await page.mouse.down();
  await page.mouse.move(box.x + box.width / 2 + 120, box.y + box.height / 2, { steps: 6 });
  await page.mouse.up();
  if (!(await caption()).startsWith('2 / 4')) { throw new Error('a swipe to the right did not go back: ' + await caption()); }
  if (!(await dialog.count())) { throw new Error('a swipe closed the viewer'); }
  await page.keyboard.press('Escape');
  await page.waitForTimeout(300);
  if (await page.locator('dialog.tl-lightbox[open]').count()) { throw new Error('Esc did not close the viewer'); }
  const back = await page.evaluate(() => { const a = document.activeElement; return a && a.tagName === 'IMG' && !!a.closest('.tl-gallery--fullscreen') && a.getAttribute('alt'); });
  if (back !== 'Photo gt-b') { throw new Error('the focus did not return to the photo that opened the viewer: ' + back); }
});

await step('booking: pick a day, the month stays drawn and the free times load', async () => {
  // until 3.2.1 the month and the times shared one request counter: after a day click the month stayed on "Loading…"
  await visit('/booking-test');
  if (await page.locator('.tl-booking [data-no-script]:visible').count()) { throw new Error('the fallback field shows although the script runs'); }
  const free = page.locator('.tl-booking-days button:not(:disabled)').first();
  await free.waitFor({ timeout: 5000 });
  await free.click();
  await page.locator('.tl-booking-times button').first().waitFor({ timeout: 5000 });
  await page.waitForTimeout(500);
  if (!(await page.locator('.tl-booking-days button[aria-pressed="true"]').count())) { throw new Error('after picking a day the month is not drawn (or the day is not marked)'); }
  await page.locator('.tl-booking-times button').first().click();
  if (!(await page.locator('[data-selected]:visible').count())) { throw new Error('picking a time does not show the chosen time'); }
});

await browser.close();
if (errors.length) {
  console.log(`\nSCRIPT ERRORS: ${errors.length}\n  ` + [...new Set(errors)].join('\n  '));
  process.exit(1);
}
console.log(`\nBROWSER TEST OK (${steps} steps)`);
