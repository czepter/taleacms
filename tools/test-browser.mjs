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
    errors.push(`${name}: ${e.message.split('\n')[0]}`);
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
