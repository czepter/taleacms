// Kaleta – browser test (called by tools/test-browser.sh): walks the admin, the builder, the news editor, the menu
// editor and the public site in Chrome and fails on any uncaught script error or console error.
// Env: BASE, PASSWORD (admin), CHROME (browser binary), NODE_PATH (folder with playwright-core), SHOTS (optional folder
// for screenshots of new screens, to look at them).
import { createRequire } from 'node:module';

const require = createRequire(`${process.env.NODE_PATH}/`);
const { chromium } = require('playwright-core');
const { BASE, PASSWORD, CHROME, SHOTS } = process.env;

const browser = await chromium.launch({ executablePath: CHROME, headless: true });
const context = await browser.newContext({ viewport: { width: 1440, height: 900 }, locale: 'en-GB' });
await context.addInitScript(() => { try { localStorage.setItem('ka-st-prohlidka', '1'); } catch (e) { /* ignore */ } });
const page = await context.newPage();
const errors = [];
let where = '';
const watch = (p) => {
  p.on('pageerror', (e) => errors.push(`${where}: ${e.message}`));
  p.on('console', (m) => { if (m.type() === 'error' && !/Failed to load resource/.test(m.text())) errors.push(`${where}: ${m.text()}`); });
};
watch(page);
page.on('frameattached', () => {}); // builder canvases are iframes of the same page: their errors arrive through the page
const canvas = () => page.frameLocator('.st-platno iframe').first();
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
  console.log(`  ${errors.length === before ? 'ok   ' : 'CHYBA'}  ${name}`);
}
const visit = (url) => page.goto(BASE + url, { waitUntil: 'networkidle' });

await step('sign in', async () => {
  await visit('/admin.php');
  await page.fill('input[name="user"]', 'admin');
  await page.fill('input[name="password"]', PASSWORD);
  await Promise.all([page.waitForNavigation(), page.press('input[name="password"]', 'Enter')]);
});

for (const url of ['/admin.php', '/admin.php?module=pages', '/admin.php?module=pages&action=new', '/admin.php?module=pages&action=edit&id=1',
  '/admin.php?module=news', '/admin.php?module=collections', '/admin.php?module=categories', '/admin.php?module=tags', '/admin.php?module=media',
  '/admin.php?module=appearance', '/admin.php?module=parts', '/admin.php?module=components', '/admin.php?module=popups', '/admin.php?module=users',
  '/admin.php?module=roles', '/admin.php?module=stats', '/admin.php?module=redirects', '/admin.php?module=changelog', '/admin.php?module=transfer',
  '/admin.php?module=extensions', '/admin.php?module=enquiries', '/admin.php?module=subscribers', '/admin.php?module=newsletters', '/admin.php?module=settings',
  '/admin.php?module=settings&tab=seo', '/admin.php?module=settings&tab=analytics', '/admin.php?module=settings&tab=backups', '/admin.php?module=status', '/admin.php?action=account',
  '/admin.php?module=bookings', '/admin.php?module=whistleblowing']) {
  await step(`open ${url}`, () => visit(url));
}

await step('appearance: change a colour and preview', async () => {
  await visit('/admin.php?module=appearance');
  const colour = page.locator('input[type="color"]:visible').first();
  if (await colour.count()) { await colour.fill('#335577'); await page.waitForTimeout(800); }
});

await step('appearance: save to the draft look, preview bar, publish', async () => {
  await visit('/admin.php?module=appearance');
  // the colour field may sit on a tab that is not open – set the value directly
  await page.evaluate(() => { document.querySelectorAll('[name="ds[barvy][primarni]"]').forEach((i) => { i.value = '#335577'; }); });
  await Promise.all([page.waitForNavigation(), page.locator('.vzhled-ulozit input[type="submit"]').click()]);
  await page.locator('#vzhled-koncept').waitFor();
  if (SHOTS) { await page.screenshot({ path: `${SHOTS}/look-draft-bar.png`, fullPage: false }); }
  await Promise.all([page.waitForNavigation(), page.locator('#vzhled-koncept button.tl').click()]);
  if (await page.locator('#vzhled-koncept').count()) { throw new Error('the draft look is still there after publishing'); }
});

await step('builder: select, style, mobile, edit text', async () => {
  await visit('/admin.php?module=pages&action=builder&id=1');
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
  const field = page.locator('.st-panel textarea, .st-panel input[type="text"]').first();
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

await step('builder: insert elements from the Add panel by click and by drag', async () => {
  // broken from 1.4.0 to 3.4.0: newElement() built the element under English keys the builder does not read (3.4.1)
  const count = () => canvas().locator('[data-ka-id]').count();
  await page.locator('.st-zalozky [role="tab"]').first().click();
  await page.waitForTimeout(300);
  for (const name of ['Heading', 'Image', 'Button']) {
    const before = await count();
    await page.locator('.st-prvky button', { hasText: new RegExp(`^${name}$`) }).first().click();
    await page.waitForTimeout(900);
    if ((await count()) <= before) { throw new Error(`clicking "${name}" in the Add panel inserted nothing`); }
    await page.locator('.st-zalozky [role="tab"]').first().click();
    await page.waitForTimeout(300);
  }
  const before = await count();
  await page.locator('.st-prvky button', { hasText: /^Text$/ }).first().dragTo(canvas().locator('h1').first());
  await page.waitForTimeout(900);
  if ((await count()) <= before) { throw new Error('dragging "Text" from the Add panel onto the canvas inserted nothing'); }
});

await step('builder: element tree and search', async () => {
  const search = page.locator('input.st-hledat').first();
  if (await search.count()) { await search.fill('text'); await page.waitForTimeout(400); await search.fill(''); }
  const tree = page.locator('.st-strom [role="treeitem"], .st-strom li').nth(1);
  if (await tree.count()) { await tree.click(); await page.keyboard.press('ArrowDown'); await page.keyboard.press('ArrowUp'); }
});

await step('builder: site header', async () => {
  await visit('/admin.php?module=parts&action=builder&typ=hlavicka&jazyk=');
  await page.waitForTimeout(1500);
  await canvas().locator('nav, header').first().click().catch(() => {});
});

await step('news editor: type and format', async () => {
  await visit('/admin.php?module=news&action=new');
  await page.fill('input[name="titulek"]', 'Browser test');
  const editor = page.locator('[contenteditable="true"]').first();
  if (await editor.count()) {
    await editor.click();
    await page.keyboard.type('Some text for the browser test.');
    await page.keyboard.press('Control+A');
    const bold = page.locator('button[data-prikaz="bold"], button[title*="Bold"]').first();
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
  await Promise.all([page.waitForNavigation(), page.locator('form.formular input[type="submit"]').first().click()]);
  const preview = page.locator('iframe.rozesilka-nahled');
  await preview.waitFor();
  await page.frameLocator('iframe.rozesilka-nahled').locator('h1').waitFor({ timeout: 5000 });
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
  for (const [part, templates] of [['hlavicka', ['klasicka', 'na-stred', 's-listou', 'minimalni']], ['paticka', ['sloupce', 'kompaktni', 'tiraz', 'vyzva']]]) {
    for (const template of templates) {
      await visit(`/admin.php?module=parts&action=templates&typ=${part}`);
      await Promise.all([page.waitForNavigation(), page.locator(`input[name="sablona"][value="${template}"] ~ button`).click()]);
      await visit(`/?cast=${part}&stavba=koncept`);
      if (SHOTS) {
        const box = page.locator(part === 'hlavicka' ? 'header' : 'footer').last();
        await box.screenshot({ path: `${SHOTS}/part-${part}-${template}.png` });
      }
    }
    await visit('/admin.php?module=parts');
  }
});

await step('menu editor', async () => {
  await visit('/admin.php?module=menu');
  const add = page.getByRole('button', { name: /Add|Přidat/ }).first();
  if (await add.count()) { await add.click().catch(() => {}); }
});

await step('public site: home, phone menu, cookies', async () => {
  await visit('/');
  const accept = page.getByRole('button', { name: /Accept|Allow|Přijmout/ }).first();
  if (await accept.count()) { await accept.click().catch(() => {}); }
  await page.setViewportSize({ width: 390, height: 844 });
  await visit('/');
  const toggle = page.locator('.ka-nav-prepinac, button[popovertarget]').first();
  if (await toggle.count()) { await toggle.click().catch(() => {}); await page.waitForTimeout(300); }
  await page.setViewportSize({ width: 1440, height: 900 });
});

for (const url of ['/services', '/contact', '/news', '/search?q=test']) {
  await step(`site ${url}`, () => visit(url));
}

// 3.5: axe-core (installed next to playwright-core, injected from the local file – no CDN) on the starter's home page, contact
// page and a news item, light mode, the cookie bar showing (test-browser.sh switches on lead attribution, a policy link and
// the accessibility toolbar), at 1440 and 390 px; any WCAG 2.0/2.1/2.2 A or AA violation fails. Dark mode is not checked yet.
const AXE = require.resolve('axe-core/axe.min.js');
const WCAG = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'];
let newsItem = '';
for (const [label, width, height] of [['desktop', 1440, 900], ['phone', 390, 844]]) {
  await step(`accessibility (axe, ${label}): home, contact and a news item with the cookie bar`, async () => {
    const ctx = await browser.newContext({ viewport: { width, height }, locale: 'en-GB', colorScheme: 'light', isMobile: width < 768, hasTouch: width < 768 });
    const p = await ctx.newPage();
    watch(p);
    try {
      if (!newsItem) {
        await p.goto(BASE + '/news', { waitUntil: 'networkidle' });
        newsItem = await p.locator('main a[href*="/news/"]').evaluateAll((links) => links.map((a) => new URL(a.href).pathname).find((path) => /^\/news\/[^/]+$/.test(path) && !path.includes('/category/') && !path.includes('/tag/')) || '');
        if (!newsItem) { throw new Error('no news item linked from /news'); }
      }
      const found = [];
      for (const url of ['/', '/contact', newsItem]) {
        await p.goto(BASE + url, { waitUntil: 'networkidle' });
        if (await p.locator('#cookies-lista:not([hidden])').count() !== 1) { throw new Error(`${url}: the cookie bar is not showing`); }
        await p.addScriptTag({ path: AXE });
        const result = await p.evaluate((tags) => window.axe.run(document, { runOnly: { type: 'tag', values: tags }, resultTypes: ['violations'] }), WCAG);
        for (const v of result.violations) { found.push(`${url} ${v.id} (${v.impact}, ${v.nodes.length}×): ${v.nodes[0].target.join(' ')}`); }
      }
      if (found.length) { throw new Error(`axe found ${found.length} WCAG violation(s): ${found.join(' | ')}`); }
    } finally {
      await ctx.close();
    }
  });
}

await step('cookie bar (3.5): compact on a phone, reached right after the skip link, never hides keyboard focus', async () => {
  // until 3.4 the bar took 340 px of a 390×844 phone, came last in the tab order and covered focused links (WCAG 2.2 SC 2.4.11)
  const ctx = await browser.newContext({ viewport: { width: 390, height: 844 }, locale: 'en-GB', colorScheme: 'light', isMobile: true, hasTouch: true });
  const p = await ctx.newPage();
  watch(p);
  try {
    await p.goto(BASE + '/', { waitUntil: 'networkidle' });
    const bar = await p.evaluate(() => Math.round(document.getElementById('cookies-lista').getBoundingClientRect().height));
    if (bar > 180) { throw new Error(`the cookie bar is ${bar} px tall on a phone (at most 180)`); }
    if (SHOTS) { await p.screenshot({ path: `${SHOTS}/cookie-bar-phone.png` }); }
    await p.keyboard.press('Tab');
    await p.keyboard.press('Tab');
    if (!(await p.evaluate(() => !!document.activeElement.closest('#cookies-lista')))) { throw new Error('the second Tab does not reach the cookie bar'); }
    const hidden = [];
    for (let i = 0; i < 200; i++) {
      await p.keyboard.press('Tab');
      const r = await p.evaluate(() => {
        const e = document.activeElement;
        if (!e || e === document.body) { return null; }
        if (e.hasAttribute('data-test-seen')) { return { again: true }; }
        e.setAttribute('data-test-seen', '');
        const b = e.getBoundingClientRect(), c = document.getElementById('cookies-lista').getBoundingClientRect();
        const covered = !e.closest('#cookies-lista') && b.width > 0 && b.top >= c.top && b.bottom <= c.bottom && b.left >= c.left && b.right <= c.right;
        return { covered, text: (e.textContent || e.getAttribute('aria-label') || e.tagName).trim().slice(0, 40) };
      });
      if (r && r.again) { break; }
      if (r && r.covered) { hidden.push(r.text); }
    }
    if (hidden.length) { throw new Error(`the cookie bar hides keyboard focus on: ${hidden.join(', ')}`); }
    // the accessibility toolbar sits above the bar; its panel opens above its button, not at the top of the window (UXP-03)
    const toolbar = await p.evaluate(() => [document.querySelector('.ka-pristupnost-tl').getBoundingClientRect().bottom, document.getElementById('cookies-lista').getBoundingClientRect().top]);
    if (toolbar[0] > toolbar[1]) { throw new Error('the accessibility toolbar is behind the cookie bar'); }
    await p.locator('.ka-pristupnost-tl').click();
    const panel = await p.evaluate(() => document.getElementById('ka-pristupnost-panel').getBoundingClientRect().top);
    if (panel < 844 / 3) { throw new Error(`the accessibility panel opens at the top of the window (top ${Math.round(panel)} px)`); }
    await p.locator('[data-pristupnost-volba="text"]').click();
    if (!/112[.,]5\s?%/.test(await p.locator('[data-pristupnost-volba="text"]').innerText())) { throw new Error('"Larger text" does not show the size it set'); }
    await p.keyboard.press('Escape');
    // once the visitor chooses, the bar's scroll padding goes with it
    await p.locator('#cookies-lista [data-cookies="nic"]').click();
    const after = await p.evaluate(() => [document.documentElement.style.getPropertyValue('--ka-cookies-vyska'), getComputedStyle(document.documentElement).scrollPaddingBottom]);
    if (after[0] !== '' || after[1] !== 'auto') { throw new Error(`the bar's scroll padding stays after the choice (${after.join(', ')})`); }
  } finally {
    await ctx.close();
  }
});

await step('booking: pick a day, the month stays drawn and the free times load', async () => {
  // until 3.2.1 the month and the times shared one request counter: after a day click the month stayed on "Loading…"
  await visit('/booking-test');
  if (await page.locator('.ka-rezervace [data-bez-skriptu]:visible').count()) { throw new Error('the fallback field shows although the script runs'); }
  const free = page.locator('.ka-rezervace-dny button:not(:disabled)').first();
  await free.waitFor({ timeout: 5000 });
  await free.click();
  await page.locator('.ka-rezervace-casy button').first().waitFor({ timeout: 5000 });
  await page.waitForTimeout(500);
  if (!(await page.locator('.ka-rezervace-dny button[aria-pressed="true"]').count())) { throw new Error('after picking a day the month is not drawn (or the day is not marked)'); }
  await page.locator('.ka-rezervace-casy button').first().click();
  if (!(await page.locator('[data-vybrano]:visible').count())) { throw new Error('picking a time does not show the chosen time'); }
});

await browser.close();
if (errors.length) {
  console.log(`\nSCRIPT ERRORS: ${errors.length}\n  ` + [...new Set(errors)].join('\n  '));
  process.exit(1);
}
console.log(`\nBROWSER TEST OK (${steps} steps)`);
