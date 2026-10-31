// Talea – browser test (run by tests/Browser/BrowserWalkTest.php): walks the admin, the builder, the news editor, the menu
// editor and the public site in Chrome and fails on any uncaught script error, console error or admin content that spills out of its box.
// Env: BASE, PASSWORD (admin), CHROME (browser binary), NODE_PATH (folder with playwright-core), SHOTS (optional folder
// for screenshots of new screens, to look at them).
import { createRequire } from 'node:module';
import { createHash } from 'node:crypto';

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

const ONLY = process.env.ONLY; // while developing: run only the steps whose name contains this text (the sign-in always runs)
async function step(name, fn) {
  if (ONLY && !/^(sign in|find a page)/.test(name) && !name.includes(ONLY)) { return; }
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
  PAGE = new URL(await page.locator('a[href*="/admin/pages/edit/"]').first().getAttribute('href'), BASE).pathname.split('/').pop() ?? '';
});

// content that spills out of its box (a badge wider than its tile, a word wider than its column): an element that lets its overflow
// show and whose content is wider than itself; scroll containers and clipped boxes are wider on purpose. Only the innermost is named.
async function nothingSpillsOut() {
  const spilled = await page.evaluate(() => {
    const wide = [...document.querySelectorAll('body *')].filter((e) => {
      if (e.closest('svg')) { return false; }
      const s = getComputedStyle(e);
      return s.overflowX === 'visible' && s.display !== 'inline' && e.clientWidth > 0 && e.scrollWidth > e.clientWidth + 1;
    });
    return wide.filter((e) => !wide.some((d) => d !== e && e.contains(d)))
      .map((e) => `<${e.tagName.toLowerCase()} class="${e.getAttribute('class') ?? ''}"> ${e.scrollWidth - e.clientWidth}px wider: "${e.textContent.trim().replace(/\s+/g, ' ').slice(0, 60)}"`);
  });
  if (spilled.length) { throw new Error('content spills out of its box: ' + spilled.join(' | ')); }
}

const ADMIN_PAGES = ['/admin.php', '/admin.php?module=pages', '/admin.php?module=pages&action=new', `/admin.php?module=pages&action=edit&id=${PAGE}`, `/admin.php?module=pages&action=website&id=${PAGE}&tab=publishing`, `/admin.php?module=pages&action=website&id=${PAGE}&tab=advanced`,
  '/admin.php?module=news', '/admin.php?module=collections', '/admin.php?module=categories', '/admin.php?module=tags', '/admin.php?module=media',
  '/admin.php?module=appearance', '/admin.php?module=parts', '/admin.php?module=components', '/admin.php?module=popups', '/admin.php?module=users',
  '/admin.php?module=roles', '/admin.php?module=stats', '/admin.php?module=redirects', '/admin.php?module=changelog', '/admin.php?module=transfer',
  '/admin.php?module=extensions', '/admin.php?module=enquiries', '/admin.php?module=forms', '/admin.php?module=forms&action=new', '/admin.php?module=subscribers', '/admin.php?module=newsletters', '/admin.php?module=settings',
  '/admin.php?module=settings&tab=seo', '/admin.php?module=settings&tab=analytics', '/admin.php?module=settings&tab=backups', '/admin.php?module=status', '/admin.php?action=account',
  '/admin.php?module=bookings'];
for (const url of ADMIN_PAGES) {
  await step(`open ${url}`, async () => { await visit(url); await nothingSpillsOut(); });
}

// a collection's fields are tiles on the item form's grid, each with its settings in the sidebar (collections/form.php): the typing
// shows on the tile, Earlier / Later moves it without a reload, and the item form lays the fields out by their width
await step('collection fields: add, width and description, reorder, the item form follows', async () => {
  const addField = async (label, width) => {
    await page.click('.field-tile-add');
    const panel = '#' + await page.locator('.field-tile-add').getAttribute('aria-controls');
    await page.fill(`${panel} [data-field-prop="label"]`, label);
    await page.click(`${panel} label:has(> input[data-field-prop="width"][value="${width}"])`);
  };
  const save = () => Promise.all([page.waitForNavigation(), page.click('.website-settings-foot .btn')]);
  const tile = (label) => page.locator('.field-grid [draggable="true"]', { hasText: label });
  const order = () => page.locator('.field-grid [draggable="true"] [data-tile="label"]').allTextContents();
  await visit('/admin.php?module=collections&action=new');
  await page.fill('#name', 'Walk stories');
  await addField('Place', 'third');
  await save();
  const id = page.url().match(/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/)[0];
  await visit(`/admin.php?module=collections&action=edit&id=${id}`);
  await addField('Lead', 'half');
  await save();
  await visit(`/admin.php?module=collections&action=edit&id=${id}`);
  await tile('Place').click();
  const panel = '#' + await tile('Place').getAttribute('aria-controls');
  if (!(await page.locator(panel).isVisible())) { throw new Error('the tile does not open its settings'); }
  await page.fill(`${panel} [data-field-prop="help"]`, 'Town or venue');
  if ((await tile('Place').locator('[data-tile="help"]').textContent()) !== 'Town or venue') { throw new Error('the tile does not show the description'); }
  if (!(await tile('Place').getAttribute('class')).includes('field-tile-third')) { throw new Error('the tile is not a third wide'); }
  await page.click(`${panel} [data-field-move="1"]`);
  const moved = await order();
  if (moved.indexOf('Lead') > moved.indexOf('Place')) { throw new Error(`Later did not move the tile: ${moved.join(', ')}`); }
  await nothingSpillsOut();
  if (SHOTS) { await page.screenshot({ path: `${SHOTS}/collection-fields.png`, fullPage: true }); }
  await save();
  await visit(`/admin.php?module=collections&action=item&id=${id}`);
  const fields = await page.locator('.item-main > .item-field').evaluateAll((els) => els.map((e) => e.className + ' ' + e.querySelector('label').textContent.trim()));
  const at = (label) => fields.findIndex((f) => f.endsWith(label));
  if (!(at('Lead') < at('Place') && fields[at('Place')].includes('item-field-third') && fields[at('Lead')].includes('item-field-half'))) { throw new Error(`the item form: ${fields.join(' | ')}`); }
  await nothingSpillsOut();
  if (SHOTS) { await page.screenshot({ path: `${SHOTS}/collection-item-fields.png`, fullPage: true }); }
});

// #155: the "Connect an application" screen stands in the focused frame (views/admin/bare.php), which loads only admin.css: its access
// choices are option cards (a hidden radio, a border, the title on its own line), "new, not verified" and "recommended" are badges (a tint)
await step('Claude connection consent: the access choices are cards, the labels are badges', async () => {
  const redirect = 'https://claude.ai/api/mcp/auth_callback';
  const client = await (await fetch(`${BASE}/oauth/register`, { method: 'POST', headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ client_name: 'Claude', redirect_uris: [redirect], token_endpoint_auth_method: 'none' }) })).json();
  const challenge = createHash('sha256').update('v'.repeat(50)).digest('base64url');
  await visit(`/oauth/authorize?response_type=code&client_id=${client.client_id}&redirect_uri=${encodeURIComponent(redirect)}&code_challenge=${challenge}&code_challenge_method=S256&state=s`);
  const look = await page.evaluate(() => {
    const card = document.querySelector('.oauth-consent .card-option');
    return card ? { radio: getComputedStyle(card.querySelector('input')).opacity, border: getComputedStyle(card).borderTopWidth, title: getComputedStyle(card.querySelector('strong')).display,
      badges: [...document.querySelectorAll('.oauth-consent .badge')].map((b) => `${b.textContent.trim()} ${getComputedStyle(b).backgroundColor !== 'rgba(0, 0, 0, 0)' ? 'tinted' : 'plain'}`) } : null;
  });
  if (!look) { throw new Error('no access choices on the consent screen'); }
  if (look.radio !== '0' || look.border !== '2px' || look.title !== 'block' || look.badges.join('|') !== 'new, not verified tinted|recommended tinted') {
    throw new Error(`the consent screen is unstyled: ${JSON.stringify(look)}`);
  }
  if (SHOTS) { await page.screenshot({ path: `${SHOTS}/oauth-consent.png`, fullPage: true }); }
});

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
  // focus mode (#115): Add and Structure open from the rail on the left of the canvas
  await page.locator('.bd-rail button[aria-label="Add elements and sections"]').click();
  const search = page.locator('input.bd-search').first();
  if (await search.count()) { await search.fill('text'); await page.waitForTimeout(400); await search.fill(''); }
  await page.locator('.bd-rail button[aria-label="Structure"]').click();
  if (!(await page.locator('.bd-tree').count())) { throw new Error('the Structure button of the rail does not open the element tree'); }
  const tree = page.locator('.bd-tree [role="treeitem"], .bd-tree li').nth(1);
  if (await tree.count()) { await tree.click(); await page.keyboard.press('ArrowDown'); await page.keyboard.press('ArrowUp'); }
});

await step('builder: Add section below a section of the page (#116)', async () => {
  await visit(`/admin.php?module=pages&action=builder&id=${PAGE}`);
  await page.waitForTimeout(1500);
  await canvas().locator('[data-tl-id]').first().hover();
  const add = canvas().locator('#tl-bd-add');
  await add.waitFor({ timeout: 5000 });
  await add.click();
  const ok = await page.evaluate(() => { const s = window.taleaBuilder.state; return s.leftOpen && s.leftTab === 'add' && s.build.children.some((c) => c.id === s.selected); });
  if (!ok) { throw new Error('Add section does not select the section and open Add'); }
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
  await page.locator('.bd-rail button[aria-label="Add elements and sections"]').click();
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

await step('builder dialogs: readable buttons when the admin theme differs from the system, light on dark and dark on light (#155)', async () => {
  // the dialogs live on <body>, outside .bd: their buttons fell back to the browser's own font and colours, and the browser's own
  // colours followed the system, not the theme chosen in the admin
  await visit(`/admin.php?module=pages&action=builder&id=${PAGE}`);
  await page.waitForTimeout(1500);
  try {
    for (const [system, admin] of [['dark', 'light'], ['light', 'dark']]) {
      await page.emulateMedia({ colorScheme: system });
      await page.evaluate((theme) => document.documentElement.setAttribute('data-theme', theme), admin);
      await page.locator('[title="Published versions"]').first().click();
      await page.locator('dialog.bd-dialog[open]').waitFor();
      const seen = await page.evaluate(() => {
        const font = getComputedStyle(document.body).fontFamily;
        return { scheme: getComputedStyle(document.documentElement).colorScheme, bad: [...document.querySelectorAll('dialog.bd-dialog[open] button')].map((b) => {
          const s = getComputedStyle(b);
          return s.color === s.backgroundColor || s.fontFamily !== font ? `${b.textContent.trim()}: ${s.color} on ${s.backgroundColor}, ${s.fontFamily}` : '';
        }).filter(Boolean) };
      });
      await page.keyboard.press('Escape');
      if (seen.scheme !== admin || seen.bad.length) { throw new Error(`system ${system}, admin ${admin}: color-scheme ${seen.scheme}; ${seen.bad.join('; ')}`); }
    }
  } finally {
    await page.evaluate(() => document.documentElement.removeAttribute('data-theme'));
    await page.emulateMedia({ colorScheme: 'light' });
  }
});

await step('builder: structured data (Product), preview, checklist, publish, JSON-LD on the page', async () => {
  await visit(`/admin.php?module=pages&action=builder&id=${PAGE}`);
  await page.waitForTimeout(1500);
  const pageUrl = await page.evaluate(() => JSON.parse(document.getElementById('builder-data').textContent).page.url);
  await page.locator('.bd-rail button[aria-label="Add elements and sections"]').click();
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


// ---------- compose: direct manipulation on the canvas (spacing, resize, column dividers, toolbar, move) ----------
// Each scenario ends as a valid SAVED build that survives a reload: the test reads the draft back from a fresh load of the builder.
const COMPOSE = JSON.parse(process.env.COMPOSE_PAGES || '{}');
const TOKENS = ['2xs', 'xs', 's', 'm', 'l', 'xl', '2xl', '3xl'];
const frameIn = (p) => p.frameLocator('.bd-canvas iframe').first();
const node = (p, id) => frameIn(p).locator(`[data-tl-id="${id}"]`).first();
const handle = (p, name) => frameIn(p).locator(`#tl-bd-overlay [data-bdo="${name}"]`).first();
const find = (children, id) => { for (const c of children ?? []) { if (c.id === id) { return c; } const f = find(c.children, id); if (f) { return f; } } return null; };
const parentOf = (children, id, parent = null) => { for (const c of children ?? []) { if (c.id === id) { return parent; } const f = parentOf(c.children, id, c); if (f !== undefined) { return f; } } return undefined; };
const expect = (condition, message) => { if (!condition) { throw new Error(message); } };

async function openBuilder(p, kind) {
  await p.goto(`${BASE}/admin.php?module=pages&action=builder&id=${COMPOSE[kind]}`, { waitUntil: 'networkidle' });
  await p.waitForFunction(() => window.taleaBuilder && window.taleaBuilder.previewDoc() && window.taleaBuilder.previewDoc().querySelector('[data-tl-id="cs1"], [data-tl-id="cf1"]'));
  await idle(p);
}
/** waitForFunction that fails with what it waited for, never swallowed. */
async function until(p, fn, arg, what, timeout = 15000) {
  await p.waitForFunction(fn, arg, { timeout }).catch((e) => { throw new Error(`timed out waiting until ${what}: ${e.message.split('\n')[0]}`); });
}
/**
 * The editor at rest: no autosave pending or running, no gesture, and no fresh canvas loading (a finished save reloads the canvas:
 * the new iframe carries .bd-loading until it is swapped in, and an overlay handle or a size taken before the swap is stale).
 */
const idle = (p) => until(p, () => { const s = window.taleaBuilder.state; return !s.timer && !s.saving && !s.retries && !s.gesture && !document.querySelector('.bd-frame iframe.bd-loading'); }, null, 'the autosave is done and the canvas is swapped')
  .catch(async (e) => { throw new Error(e.message + ' ' + JSON.stringify(await p.evaluate(() => { const s = window.taleaBuilder.state; return { timer: !!s.timer, saving: s.saving, retries: s.retries, gesture: s.gesture, loading: !!document.querySelector('.bd-frame iframe.bd-loading'), status: document.body.textContent.match(/(Saving failed|Could not|Unsaved|Draft saved|Saved, but)[^.]*/)?.[0] }; }).catch(() => ({})))); });
/** The draft as the server has it: wait until the autosave is done, then read it from a fresh load. */
async function savedBuild(p, kind) {
  await until(p, () => { const s = window.taleaBuilder.state; return !s.timer && !s.saving && !s.retries && s.saved === JSON.stringify(s.build); }, null, 'the draft is saved');
  await openBuilder(p, kind);
  return p.evaluate(() => JSON.parse(document.getElementById('builder-data').textContent).build);
}
/**
 * Switch the canvas to a device. The bar is rebuilt when the draft state changes (an autosave finishing), and a click that lands
 * while it is rebuilt is lost: wait for the save, click, and click again until the builder is on that breakpoint.
 */
async function device(p, name, bp) {
  await idle(p);
  for (let attempt = 0; attempt < 3 && await p.evaluate(() => window.taleaBuilder.state.bp) !== bp; attempt++) {
    await p.getByRole('button', { name }).first().click();
    await p.waitForFunction((x) => window.taleaBuilder.state.bp === x, bp, { timeout: 3000 }).catch(() => {}); // a lost click: the loop clicks again
  }
  expect(await p.evaluate(() => window.taleaBuilder.state.bp) === bp, `the canvas did not switch to ${name}`);
  // the canvas has the device's width, so the site's own breakpoints (tablet ≤1023 px, phone ≤767 px) apply in it
  const query = { base: '(min-width: 1024px)', tablet: '(min-width: 768px) and (max-width: 1023px)', mobile: '(max-width: 767px)' }[bp];
  await idle(p);
  await until(p, (q) => { const d = window.taleaBuilder.previewDoc(); return !!d && d.defaultView.matchMedia(q).matches; }, query, `the canvas is as wide as ${name} ${query}`);
}
async function pick(p, id, corner = false) {
  const b = await node(p, id).boundingBox();
  await p.mouse.click(corner ? b.x + 3 : b.x + b.width / 2, corner ? b.y + 3 : b.y + b.height / 2);
  await p.waitForFunction((x) => window.taleaBuilder.state.selected === x, id);
  await handle(p, 'margin-bottom').waitFor({ timeout: 5000 });
}
const scaleOf = (p) => p.evaluate(() => window.taleaBuilder.scale());
const undoDepth = (p) => p.evaluate(() => window.taleaBuilder.state.undo.length);
async function mouseDrag(p, locator, dx, dy) {
  await locator.scrollIntoViewIfNeeded();
  const b = await locator.boundingBox();
  if (!b) throw new Error(`the drag handle ${locator} has no box: it is not on the canvas (is the canvas being swapped?)`);
  const x = b.x + b.width / 2;
  const y = b.y + b.height / 2;
  await p.mouse.move(x, y);
  await p.mouse.down();
  await p.mouse.move(x + dx / 2, y + dy / 2, { steps: 4 });
  await p.mouse.move(x + dx, y + dy, { steps: 6 });
  await p.mouse.up();
  await p.waitForTimeout(300);
}
const grid = (p, id) => node(p, id).boundingBox();

await step('compose (mouse): space between two sections, one undo step per gesture', async () => {
  await openBuilder(page, 'mouse');
  await pick(page, 'cs1', true);
  const m = await scaleOf(page);
  const before = await undoDepth(page);
  await mouseDrag(page, handle(page, 'margin-bottom'), 0, 30 * m);
  expect((await undoDepth(page)) === before + 1, 'one gesture is not exactly one undo step');
  await page.keyboard.press('Control+z');
  await page.waitForTimeout(300);
  expect((await undoDepth(page)) === before && !find((await page.evaluate(() => window.taleaBuilder.state.build)).children, 'cs1').style.base.margin_bottom, 'undo did not revert the gesture');
  await pick(page, 'cs1', true);
  await mouseDrag(page, handle(page, 'margin-bottom'), 0, 30 * m);
  const build = await savedBuild(page, 'mouse');
  const value = (find(build.children, 'cs1').style ?? {}).base?.margin_bottom;
  expect(TOKENS.includes(value), `the section's margin is not a spacing token: ${value}`);
});

await step('compose (mouse): a phone edit leaves desktop alone, "reset to inherited" removes it', async () => {
  await openBuilder(page, 'mouse');
  const desktop = JSON.stringify(find((await page.evaluate(() => window.taleaBuilder.state.build)).children, 'cs1').style.base);
  await page.getByRole('button', { name: 'Mobile' }).first().click();
  await page.waitForTimeout(1200);
  await pick(page, 'cs1', true);
  await mouseDrag(page, handle(page, 'margin-bottom'), 0, 60 * (await scaleOf(page)));
  const style = find((await page.evaluate(() => window.taleaBuilder.state.build)).children, 'cs1').style;
  expect(style.mobile && style.mobile.margin_bottom, 'the phone edit did not write the mobile state');
  expect(JSON.stringify(style.base) === desktop, 'the phone edit changed the desktop style');
  await page.getByRole('tab', { name: 'Style' }).first().click();
  const reset = page.locator('.bd-property.bd-overridden .bd-reset').first();
  await reset.waitFor({ timeout: 5000 });
  await reset.click();
  await page.waitForTimeout(400);
  const after = find((await page.evaluate(() => window.taleaBuilder.state.build)).children, 'cs1').style;
  expect(!after.mobile, 'reset to inherited did not remove the override');
  await page.getByRole('button', { name: 'Desktop' }).first().click();
  await page.waitForTimeout(800);
});

await step('compose (mouse): a failed save mid-gesture never corrupts the tree', async () => {
  await openBuilder(page, 'mouse');
  await page.route(/build_save/, (r) => r.abort());
  await pick(page, 'cs4', true);
  await mouseDrag(page, handle(page, 'padding-top'), 0, 30 * (await scaleOf(page)));
  const state = await page.evaluate(() => JSON.parse(JSON.stringify(window.taleaBuilder.state.build)));
  expect(find(state.children, 'cs4').style.base.padding_y, 'the gesture did not reach the tree while the save failed');
  expect(state.children.length === 4 && find(state.children, 'cimg'), 'the tree is damaged');
  await page.unroute(/build_save/);
  const build = await savedBuild(page, 'mouse');
  expect(find(build.children, 'cs4').style.base.padding_y, 'the change was lost after the save recovered');
});

await step('compose (mouse): a row of three columns becomes 2 : 1 : 1 with the dividers', async () => {
  await openBuilder(page, 'mouse');
  await pick(page, 'txt1');
  await page.keyboard.press('Escape'); // the parent: the first column; its row shows the dividers
  await handle(page, 'divider-0').waitFor({ timeout: 5000 });
  const box = await grid(page, 'cg3');
  await mouseDrag(page, handle(page, 'divider-0'), (2 / 12) * box.width * 0.97, 0);
  await idle(page); // the autosave swaps the canvas: the second divider is drawn again on the new one
  await handle(page, 'divider-1').waitFor({ timeout: 5000 });
  await mouseDrag(page, handle(page, 'divider-1'), (1 / 12) * box.width * 0.97, 0);
  const build = await savedBuild(page, 'mouse');
  expect(find(build.children, 'cg3').style.base.columns === '2fr 1fr 1fr', `the columns are ${find(build.children, 'cg3').style.base.columns}`);
});

await step('compose (mouse): an image is resized to half width', async () => {
  await openBuilder(page, 'mouse');
  await pick(page, 'cimg');
  const b = await node(page, 'cimg').boundingBox();
  await mouseDrag(page, handle(page, 'resize-se'), -b.width / 2, 0);
  const build = await savedBuild(page, 'mouse');
  const width = find(build.children, 'cimg').style.base.width;
  expect(width === '50%' || width === '58.33%' || width === '41.67%', `the image width is ${width}`);
  expect(width === '50%', `the image did not snap to half the width: ${width}`);
});

await step('compose (mouse): the toolbar groups and ungroups, Shift-click selects several', async () => {
  await openBuilder(page, 'mouse');
  await pick(page, 'txt4');
  const second = await node(page, 'btn1').boundingBox();
  await page.keyboard.down('Shift');
  await page.mouse.click(second.x + second.width / 2, second.y + second.height / 2);
  await page.keyboard.up('Shift');
  await page.waitForFunction(() => window.taleaBuilder.selectedIds().length === 2, null, { timeout: 5000 }).catch(async () => {
    throw new Error('Shift-click did not add the button to the selection: ' + await page.evaluate(() => JSON.stringify([window.taleaBuilder.state.selected, window.taleaBuilder.state.multi])));
  });
  await frameIn(page).locator('#tl-bd-overlay [data-bdo="group"]').first().click();
  await page.waitForTimeout(400);
  let build = await savedBuild(page, 'mouse');
  const wrapper = parentOf(build.children, 'btn1');
  expect(wrapper && wrapper.type === 'container' && parentOf(build.children, 'txt4').id === wrapper.id && wrapper.id !== 'cola', 'the two elements were not grouped into a container');
  await page.evaluate((id) => window.taleaBuilder.selection(id), wrapper.id); // a click on the wrapper would hit its content
  await page.keyboard.press('Control+Shift+g');
  await page.waitForTimeout(400);
  build = await savedBuild(page, 'mouse');
  expect(parentOf(build.children, 'btn1').id === 'cola' && !find(build.children, wrapper.id), 'ungroup did not put the elements back');
});

await step('compose (mouse): a button moves to the right column with the toolbar grip', async () => {
  await openBuilder(page, 'mouse');
  await pick(page, 'btn1');
  const target = await node(page, 'txt5').boundingBox();
  const grip = await frameIn(page).locator('#tl-bd-overlay [data-bdo="move"]').first().boundingBox();
  await page.mouse.move(grip.x + grip.width / 2, grip.y + grip.height / 2);
  await page.mouse.down();
  await page.mouse.move(target.x + target.width / 2, target.y + target.height * 0.8, { steps: 12 });
  await page.waitForTimeout(150);
  await page.mouse.up();
  await page.waitForTimeout(500);
  const build = await savedBuild(page, 'mouse');
  const owner = parentOf(build.children, 'btn1');
  expect(owner && owner.id === 'colb', `the button sits in ${owner && owner.id}, not in the right column`);
});

await step('compose (mouse): the marquee selects several elements, an image file dropped on the canvas is uploaded and inserted', async () => {
  await openBuilder(page, 'mouse');
  const a = await node(page, 'cg3').boundingBox();
  await page.mouse.move(a.x + 2, a.y - 1);
  await page.mouse.down();
  await page.mouse.move(a.x + a.width - 2, a.y + a.height + 2, { steps: 8 });
  await page.mouse.up();
  await page.waitForTimeout(300);
  const count = await page.evaluate(() => window.taleaBuilder.selectedIds().length);
  expect(count >= 1, 'the marquee selected nothing');
  // a file dragged from the computer: a synthetic drop with a 1x1 PNG
  const before = await page.evaluate(() => JSON.stringify(window.taleaBuilder.state.build).split('"type":"image"').length);
  await frameIn(page).locator('body').evaluate(async (body) => {
    const doc = body.ownerDocument;
    const canvas = doc.createElement('canvas');
    canvas.width = canvas.height = 16;
    canvas.getContext('2d').fillRect(0, 0, 8, 8);
    const png = await new Promise((done) => canvas.toBlob(done, 'image/png'));
    const transfer = new DataTransfer();
    transfer.items.add(new File([png], 'dropped.png', { type: 'image/png' }));
    const r = doc.querySelector('[data-tl-id="cimg"]').getBoundingClientRect();
    const at = { clientX: r.left + r.width / 2, clientY: r.top + r.height * 0.8, shiftKey: true, dataTransfer: transfer, bubbles: true, cancelable: true }; // Shift: insert (a lone image dropped without it replaces the image it lands on)
    doc.querySelector('[data-tl-id="cimg"]').dispatchEvent(new DragEvent('dragover', at));
    doc.querySelector('[data-tl-id="cimg"]').dispatchEvent(new DragEvent('drop', at));
  });
  await page.waitForFunction((n) => JSON.stringify(window.taleaBuilder.state.build).split('"type":"image"').length > n, before, { timeout: 15000 });
});

// ---------- crop mode of an image (compose phase C1): focal point and shape, by mouse and by keyboard ----------
const crop = (p, name) => frameIn(p).locator(`#tl-bd-overlay [data-bdo="${name}"]`).first();
const styleOf = async (p, bp) => find((await p.evaluate(() => window.taleaBuilder.state.build)).children, 'cimg').style?.[bp] ?? {};
const focalOf = (v) => (v ?? '').split('% ').map((x) => parseInt(x, 10));
/** Opens crop mode with the toolbar button of the selected image. */
async function openCrop(p) {
  await crop(p, 'crop').click();
  await frameIn(p).locator('#tl-bd-overlay .bdc-photo').waitFor({ timeout: 5000 }).catch(async (e) => {
    const info = await p.evaluate(() => ({ gesture: window.taleaBuilder.state.gesture, overlay: window.taleaBuilder.previewDoc().getElementById('tl-bd-overlay')?.innerHTML.slice(0, 300) }));
    throw new Error('crop mode did not open: ' + JSON.stringify(info));
  });
}
/** Presses at a point of the whole photo (as fractions of its box), drags a little and lets go. */
async function dragPhoto(p, fx, fy) {
  const photo = frameIn(p).locator('#tl-bd-overlay .bdc-photo');
  const b = await photo.boundingBox();
  const x = b.x + b.width * fx;
  const y = b.y + b.height * fy;
  await p.mouse.move(b.x + b.width * 0.3, b.y + b.height * 0.3);
  await p.mouse.down();
  await p.mouse.move(x, y, { steps: 6 });
  if (SHOTS) { await p.screenshot({ path: `${SHOTS}/crop-dragging.png` }); }
  await p.mouse.up();
  await p.waitForTimeout(350); // the photo settles back
  if (SHOTS) { await p.screenshot({ path: `${SHOTS}/crop-settled.png` }); }
}

await step('crop (mouse): 16:9 and a focal point by dragging the photo, one undo step, phone 3:4 leaves desktop alone, reload', async () => {
  await openBuilder(page, 'mouse');
  await pick(page, 'cimg');
  const before = await undoDepth(page);
  await openCrop(page);
  expect(await frameIn(page).locator('#tl-bd-overlay .bdo-b[data-bdo="move"]').count() === 0, 'the handles and the toolbar of the element did not step aside');
  await crop(page, 'crop-ratio-16-9').click();
  expect(await crop(page, 'crop-ratio-16-9').getAttribute('aria-pressed') === 'true', 'the 16:9 button is not pressed');
  await dragPhoto(page, 0.62, 0.38);
  // while the crop is open nothing is written; the window is back on the element after the photo settled
  expect((await undoDepth(page)) === before, 'crop mode wrote before Done');
  const win = await frameIn(page).locator('#tl-bd-overlay .bdc-win').boundingBox();
  const shown = await node(page, 'cimg').boundingBox();
  expect(Math.abs(win.x - shown.x) < 2 && Math.abs(win.y - shown.y) < 2 && Math.abs(win.width - shown.width) < 2 && Math.abs(win.height - shown.height) < 2, `the window is not on the element's box: ${JSON.stringify([win, shown])}`);
  await crop(page, 'crop-done').click();
  await idle(page);
  expect((await undoDepth(page)) === before + 1, 'ratio and focal point together are not exactly one undo step');
  let base = await styleOf(page, 'base');
  const [fx, fy] = focalOf(base.focal_point);
  expect(base.aspect_ratio === '16/9' && base.object_fit === 'cover' && Math.abs(fx - 62) <= 2 && Math.abs(fy - 38) <= 2, `desktop crop is ${JSON.stringify(base)}`);
  await page.keyboard.press('Control+z');
  await page.waitForTimeout(300);
  base = await styleOf(page, 'base');
  expect(!base.aspect_ratio && !base.focal_point && !base.object_fit && (await undoDepth(page)) === before, 'one undo did not revert the whole crop');
  // again, then a phone edit
  await pick(page, 'cimg');
  await openCrop(page);
  await crop(page, 'crop-ratio-16-9').click();
  await dragPhoto(page, 0.62, 0.38);
  await crop(page, 'crop-done').click();
  await idle(page);
  const desktop = await styleOf(page, 'base');
  await device(page, 'Mobile', 'mobile');
  await pick(page, 'cimg');
  await openCrop(page);
  expect(await crop(page, 'crop-ratio-16-9').getAttribute('aria-pressed') === 'true', 'the phone does not show the inherited 16:9 as pressed');
  expect(!(await crop(page, 'crop-ratio-16-9').evaluate((b) => b.classList.contains('bdc-own'))), 'an inherited ratio is marked as overridden');
  await crop(page, 'crop-ratio-3-4').click();
  expect(await crop(page, 'crop-ratio-3-4').evaluate((b) => b.classList.contains('bdc-own')), 'the phone override has no dot');
  await crop(page, 'crop-done').click();
  await idle(page);
  const mobile = await styleOf(page, 'mobile');
  expect(mobile.aspect_ratio === '3/4' && !mobile.focal_point, `phone crop is ${JSON.stringify(mobile)}`);
  expect(JSON.stringify(await styleOf(page, 'base')) === JSON.stringify(desktop), 'the phone crop changed the desktop style');
  await pick(page, 'cimg');
  expect(await crop(page, 'crop').evaluate((b) => b.classList.contains('bdc-own')), 'the Crop button has no dot where the phone overrides the desktop shape');
  const saved = find((await savedBuild(page, 'mouse')).children, 'cimg');
  expect(saved.style.base.aspect_ratio === '16/9' && saved.style.mobile.aspect_ratio === '3/4' && saved.style.base.focal_point === desktop.focal_point, `the saved crop is ${JSON.stringify(saved.style)}`);
  await device(page, 'Desktop', 'base');
  await pick(page, 'cimg');
  expect(!(await crop(page, 'crop').evaluate((b) => b.classList.contains('bdc-own'))), 'the Crop button has a dot on desktop, where nothing is inherited');
});

await step('crop (mouse): Cancel and Esc write nothing, a double-click opens it, an unchanged Done is no history step', async () => {
  await openBuilder(page, 'mouse');
  await pick(page, 'cimg');
  const before = await undoDepth(page);
  const style = JSON.stringify(await styleOf(page, 'base'));
  await openCrop(page);
  await crop(page, 'crop-ratio-1').click();
  await dragPhoto(page, 0.9, 0.9);
  await crop(page, 'crop-cancel').click();
  await frameIn(page).locator('#tl-bd-overlay .bdc').waitFor({ state: 'detached', timeout: 5000 });
  await node(page, 'cimg').dblclick(); // a double-click on the image opens crop mode too
  await frameIn(page).locator('#tl-bd-overlay .bdc-photo').waitFor({ timeout: 5000 });
  await crop(page, 'crop-ratio-21-9').click();
  await page.keyboard.press('Escape');
  await openCrop(page);
  await crop(page, 'crop-done').click();
  await idle(page);
  expect((await undoDepth(page)) === before && JSON.stringify(await styleOf(page, 'base')) === style, 'Cancel, Esc or an unchanged Done wrote something');
  expect(await frameIn(page).locator('#tl-bd-overlay .bdc').count() === 0, 'crop mode did not close');
});

// ---------- background popover and replace by drop (compose phase C2, C3) ----------
const PNG = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', 'base64');
const bgStyle = async (p, id, bp = 'base') => find((await p.evaluate(() => window.taleaBuilder.state.build)).children, id).style?.[bp] ?? {};
const popover = (p) => frameIn(p).locator('#tl-bd-bg');
/** The popover is drawn again by every saved draft (the canvas is swapped): wait for the editor to be at rest, then for it. */
async function popoverAfterSave(p) {
  await idle(p);
  await popover(p).waitFor({ timeout: 5000 });
}
/** A file dragged from the computer onto an element of the canvas: a synthetic dragover (and drop) with a small PNG (or a video type). */
async function dragFile(p, id, { drop = true, shift = false, type = 'image/png', name = 'dropped.png' } = {}) {
  await frameIn(p).locator('body').evaluate(async (body, o) => {
    const doc = body.ownerDocument;
    const canvas = doc.createElement('canvas');
    canvas.width = canvas.height = 16;
    canvas.getContext('2d').fillRect(0, 0, 8, 8);
    const png = o.type === 'image/png' ? await new Promise((done) => canvas.toBlob(done, 'image/png')) : new Blob(['x'], { type: o.type });
    const transfer = new DataTransfer();
    transfer.items.add(new File([png], o.name, { type: o.type }));
    const node = doc.querySelector(`[data-tl-id="${o.id}"]`);
    const r = node.getBoundingClientRect();
    const at = { clientX: r.left + r.width / 2, clientY: r.top + r.height / 2, shiftKey: o.shift, dataTransfer: transfer, bubbles: true, cancelable: true };
    node.dispatchEvent(new DragEvent('dragover', at));
    if (o.drop) { node.dispatchEvent(new DragEvent('drop', at)); }
  }, { id, shift, type, name, drop });
}

await step('background (mouse): colour, uploaded and picked image, "darken at the bottom", focal point, each one undo step, reload', async () => {
  await openBuilder(page, 'mouse');
  await pick(page, 'cs1', true);
  let depth = await undoDepth(page);
  await handle(page, 'background').click();
  await popover(page).waitFor({ timeout: 5000 });
  expect((await popover(page).getAttribute('aria-label')) === 'Background · Desktop', `the popover is titled ${await popover(page).getAttribute('aria-label')}`);
  expect(await frameIn(page).locator('#tl-bd-bg [role="tab"]').count() === 3, 'a section has the tabs Colour, Image and Video');
  // the swatches show the colours of the canvas
  const swatch = await handle(page, 'bg-sw-primary').evaluate((b) => [getComputedStyle(b).backgroundColor, getComputedStyle(b.ownerDocument.documentElement).getPropertyValue('--tl-color-primary')]);
  expect(swatch[0] !== 'rgba(0, 0, 0, 0)' && swatch[1].trim() !== '', `the swatch is not the token colour: ${JSON.stringify(swatch)}`);
  await handle(page, 'bg-sw-primary').click();
  await popoverAfterSave(page);
  expect((await bgStyle(page, 'cs1')).background === 'primary' && (await undoDepth(page)) === ++depth, 'a swatch is not exactly one undo step');
  // the image: upload through the file chooser
  await handle(page, 'bg-tab-image').click();
  const [chooser] = await Promise.all([page.waitForEvent('filechooser', { timeout: 5000 }), handle(page, 'bg-upload').click()]);
  await chooser.setFiles({ name: 'bgup.png', mimeType: 'image/png', buffer: PNG });
  await until(page, () => !!window.taleaBuilder.find('cs1').p.style.base.background_image, null, 'the uploaded background image is set');
  await popoverAfterSave(page);
  expect((await undoDepth(page)) === ++depth && /bgup/.test((await bgStyle(page, 'cs1')).background_image), `upload: ${JSON.stringify(await bgStyle(page, 'cs1'))}`);
  // choose from Media replaces it
  await handle(page, 'bg-pick').click();
  await page.locator('dialog.gallery-window[open] .gallery-item', { hasText: 'Pick gt-b' }).dblclick({ timeout: 8000 }).catch(async (e) => { throw new Error(e.message.split('\n')[0] + ' ' + (await page.evaluate(() => { const d = document.querySelector('dialog.gallery-window'); return d ? d.open + ' ' + d.innerText.slice(0, 300) : typeof window.taleaPickImage + ' no dialog'; }))); });
  await until(page, () => /gt-b/.test(window.taleaBuilder.find('cs1').p.style.base.background_image || ''), null, 'the picked background image is set');
  await popoverAfterSave(page);
  expect((await undoDepth(page)) === ++depth, 'choosing from Media is not exactly one undo step');
  // the overlay: darken at the bottom is a layer on the photo
  await handle(page, 'bg-ov-bottom').click();
  await popoverAfterSave(page);
  expect((await bgStyle(page, 'cs1')).gradient === 'linear-gradient(180deg, transparent, rgb(0 0 0 / 0.55))' && (await undoDepth(page)) === ++depth, 'the overlay chip is not exactly one undo step');
  const painted = await node(page, 'cs1').evaluate((n) => getComputedStyle(n).backgroundImage);
  expect(/^linear-gradient\(rgba\(0, 0, 0, 0\), rgba\(0, 0, 0, 0\.55\)\)/.test(painted) && /gt-b/.test(painted), `the canvas does not paint the gradient over the photo: ${painted}`);
  // the focal point: crop mode on the background, no shape strip
  await handle(page, 'bg-focal').click();
  await frameIn(page).locator('#tl-bd-overlay .bdc-photo').waitFor({ timeout: 5000 });
  expect(await frameIn(page).locator('#tl-bd-overlay [data-ratio]').count() === 0, 'a background has no shape strip');
  const win = await frameIn(page).locator('#tl-bd-overlay .bdc-win').boundingBox();
  const shown = await node(page, 'cs1').boundingBox();
  expect(Math.abs(win.width - shown.width) < 2 && Math.abs(win.height - shown.height) < 2, 'the crop window is not the element box');
  // the photo is far taller than the section: the finger goes down and up inside the window, the point is where the pointer ended on the photo
  const photo = await frameIn(page).locator('#tl-bd-overlay .bdc-photo').boundingBox();
  const end = { x: win.x + win.width * 0.7, y: win.y + win.height * 0.6 };
  await page.mouse.move(win.x + win.width * 0.3, win.y + win.height * 0.4);
  await page.mouse.down();
  await page.mouse.move(end.x, end.y, { steps: 6 });
  await page.mouse.up();
  await page.waitForTimeout(350);
  await crop(page, 'crop-done').click();
  await popoverAfterSave(page);
  const [fx, fy] = focalOf((await bgStyle(page, 'cs1')).background_focal);
  const want = [(end.x - photo.x) / photo.width * 100, (end.y - photo.y) / photo.height * 100];
  expect(Math.abs(fx - want[0]) <= 4 && Math.abs(fy - want[1]) <= 4 && (await undoDepth(page)) === ++depth, `background focal point is ${JSON.stringify(await bgStyle(page, 'cs1'))}, expected about ${want.map(Math.round)}`);
  // Esc closes and gives the focus back to the button
  await page.keyboard.press('Escape');
  await popover(page).waitFor({ state: 'detached', timeout: 3000 });
  expect(await handle(page, 'background').evaluate((b) => b === b.ownerDocument.activeElement), 'the focus did not return to the Background button');
  // a phone edit leaves desktop alone and is titled by the screen size
  await device(page, 'Mobile', 'mobile');
  await pick(page, 'cs1', true);
  await handle(page, 'background').click();
  await popover(page).waitFor({ timeout: 5000 });
  expect((await popover(page).getAttribute('aria-label')) === 'Background · Mobile', 'the popover does not name the screen size');
  await handle(page, 'bg-tab-colour').click(); // the inherited photo opened the Image tab
  await handle(page, 'bg-sw-secondary').click();
  await popoverAfterSave(page);
  expect((await bgStyle(page, 'cs1', 'mobile')).background === 'secondary' && (await bgStyle(page, 'cs1')).background === 'primary', 'the phone colour changed the desktop one');
  // one undo step reverts the phone colour, redo brings it back
  await page.keyboard.press('Control+z');
  await page.waitForTimeout(300);
  expect(!(await bgStyle(page, 'cs1', 'mobile')).background && (await bgStyle(page, 'cs1')).background === 'primary', 'one undo did not revert exactly the phone colour');
  await page.keyboard.press('Control+Shift+z');
  await page.waitForTimeout(300);
  expect((await bgStyle(page, 'cs1', 'mobile')).background === 'secondary', 'redo did not bring the phone colour back');
  // "darken at the bottom" on a size that only inherits the photo keeps the photo there (a gradient is layered over an image of the same state only)
  await handle(page, 'bg-tab-image').click();
  await handle(page, 'bg-ov-bottom').click();
  await popoverAfterSave(page);
  const darkened = await bgStyle(page, 'cs1', 'mobile');
  expect(/gt-b/.test(darkened.background_image) && darkened.gradient && darkened.background_focal === (await bgStyle(page, 'cs1')).background_focal, `darken on the phone: ${JSON.stringify(darkened)}`);
  expect(/gt-b/.test(await node(page, 'cs1').evaluate((n) => getComputedStyle(n).backgroundImage)), 'the photo disappeared on the phone');
  // removing the image there takes the darkening with it, desktop keeps its own
  await handle(page, 'bg-remove').click();
  await popoverAfterSave(page);
  const removed = await bgStyle(page, 'cs1', 'mobile');
  expect(!removed.background_image && !removed.gradient && removed.background === 'secondary' && (await bgStyle(page, 'cs1')).gradient, `remove on the phone: ${JSON.stringify(removed)}`);
  await device(page, 'Desktop', 'base');
  const saved = find((await savedBuild(page, 'mouse')).children, 'cs1');
  expect(saved.style.base.background === 'primary' && /gt-b/.test(saved.style.base.background_image) && saved.style.base.gradient && saved.style.base.background_focal && saved.style.mobile.background === 'secondary', `the saved background is ${JSON.stringify(saved.style)}`);
});

await step('drop (mouse): an image file replaces an image (focal point gone, shape kept, one undo step), Shift inserts, a background and a video are replaced', async () => {
  await openBuilder(page, 'mouse');
  await page.evaluate(() => window.taleaBuilder.applyChange(() => {
    const p = window.taleaBuilder.find('cimg').p;
    p.style.base = { ...p.style.base, focal_point: '20% 30%', aspect_ratio: '16/9', object_fit: 'cover' };
    p.style.mobile = { focal_point: '10% 10%' };
    const s = window.taleaBuilder.find('cs1').p;
    s.style.base = { ...s.style.base, background_image: 'media/2026/05/gt-b.jpg', background_focal: '30% 30%' };
    s.content.background_video = 'media/2026/05/clip.mp4';
    s.style.tablet = { background_focal: '10% 10%' }; // takes the photo from desktop
    s.style.mobile = { background_image: 'media/2026/05/gt-b.jpg', background_focal: '5% 5%' }; // has a photo of its own
  }));
  await idle(page);
  const sources = async () => (await page.evaluate(() => JSON.stringify(window.taleaBuilder.state.build))).split('"type":"image"').length;
  const count = await sources();
  const oldSrc = find((await page.evaluate(() => window.taleaBuilder.state.build)).children, 'cimg').content.src;
  // the whole image is outlined while a file is over it
  await dragFile(page, 'cimg', { drop: false });
  const frame = frameIn(page).locator('#tl-bd-replace');
  expect((await frame.textContent()) === 'Replace image · Shift: insert' && await frame.isVisible(), 'the image is not outlined with the replace label');
  await dragFile(page, 'cimg', { drop: false, shift: true });
  expect(!(await frame.isVisible()), 'Shift should show the insertion line, not the replace frame');
  await dragFile(page, 'ch1', { drop: false });
  expect((await frame.textContent()) === 'Replace background · Shift: insert', 'a section with a background photo is not outlined');
  await dragFile(page, 'ch1', { drop: false, type: 'video/mp4', name: 'clip.mp4' });
  expect((await frame.textContent()) === 'Replace background · Shift: insert' && await frame.isVisible(), 'a video over a section with a background video is not outlined');
  // drop on the image
  let depth = await undoDepth(page);
  const said = page.waitForFunction(() => document.body.textContent.includes('Image replaced. Check that the description still fits.'), null, { timeout: 15000, polling: 50 });
  await dragFile(page, 'cimg');
  await until(page, (old) => window.taleaBuilder.find('cimg').p.content.src !== old, oldSrc, 'the dropped file replaced the image');
  await said;
  const p = find((await page.evaluate(() => window.taleaBuilder.state.build)).children, 'cimg');
  expect(!p.style.base.focal_point && !p.style.mobile?.focal_point, `the old focal point stayed: ${JSON.stringify(p.style)}`);
  expect(p.style.base.aspect_ratio === '16/9' && p.content.alt === 'A photo', `shape or description lost: ${JSON.stringify([p.style, p.content.alt])}`);
  expect((await undoDepth(page)) === depth + 1 && (await sources()) === count, 'the replacement is not exactly one undo step / inserted a new element');
  await idle(page);
  await page.keyboard.press('Control+z');
  await page.waitForTimeout(300);
  const back = find((await page.evaluate(() => window.taleaBuilder.state.build)).children, 'cimg');
  expect(back.content.src === oldSrc && back.style.base.focal_point === '20% 30%' && back.style.mobile.focal_point === '10% 10%', 'one undo did not restore the image and its points');
  // Shift inserts
  depth = await undoDepth(page);
  await dragFile(page, 'cimg', { shift: true });
  await until(page, (n) => JSON.stringify(window.taleaBuilder.state.build).split('"type":"image"').length > n, count, 'Shift-drop inserted an image');
  expect(find((await page.evaluate(() => window.taleaBuilder.state.build)).children, 'cimg').content.src === oldSrc, 'Shift-drop replaced the image');
  await page.keyboard.press('Control+z');
  await page.waitForTimeout(300);
  await idle(page);
  // drop on a background
  depth = await undoDepth(page);
  await dragFile(page, 'ch1');
  await until(page, () => window.taleaBuilder.find('cs1').p.style.base.background_image !== 'media/2026/05/gt-b.jpg', null, 'the dropped file replaced the background');
  const section = find((await page.evaluate(() => window.taleaBuilder.state.build)).children, 'cs1');
  expect(!section.style.base.background_focal && (await undoDepth(page)) === depth + 1 && find([section], 'ch1'), `background replace: ${JSON.stringify(section.style)}`);
  expect(!section.style.tablet && section.style.mobile.background_focal === '5% 5%', `the point of a size that takes the new photo stayed, or the one of a size with its own photo went: ${JSON.stringify(section.style)}`);
});

await step('replace (toolbar): a file picked from Media brings its description as the alt', async () => {
  await openBuilder(page, 'mouse');
  await pick(page, 'cimg');
  const oldSrc = find((await page.evaluate(() => window.taleaBuilder.state.build)).children, 'cimg').content.src;
  await handle(page, 'replace').click();
  await page.locator('dialog.gallery-window[open] .gallery-item', { hasText: 'Pick gt-b' }).dblclick({ timeout: 8000 });
  await until(page, (old) => window.taleaBuilder.find('cimg').p.content.src !== old, oldSrc, 'the picked file replaced the image');
  const alt = find((await page.evaluate(() => window.taleaBuilder.state.build)).children, 'cimg').content.alt;
  expect(alt === 'Pick gt-b', `the alt after Replace is ${JSON.stringify(alt)}`);
});

// keyboard only
await step('compose (keyboard): Enter walks the handles, arrows change the spacing', async () => {
  await openBuilder(page, 'keys');
  await pick(page, 'cs1', true);
  await page.keyboard.press('Enter');
  expect(await frameIn(page).locator('#tl-bd-overlay [data-bdo-h]:focus').count() === 1, 'Enter did not move the focus to a handle');
  await page.keyboard.press('Tab');
  await page.keyboard.press('Tab');
  expect(await frameIn(page).locator('#tl-bd-overlay [data-bdo="margin-bottom"]:focus').count() === 1, 'Tab does not walk the handles in order');
  await page.keyboard.press('ArrowUp');
  await page.waitForTimeout(150);
  await page.keyboard.press('ArrowUp');
  await page.waitForTimeout(150);
  const build = await savedBuild(page, 'keys');
  expect(find(build.children, 'cs1').style.base.margin_bottom === 'xs', `margin is ${find(build.children, 'cs1').style.base.margin_bottom}`);
});

await step('compose (keyboard): the divider handles set 2 : 1 : 1', async () => {
  await openBuilder(page, 'keys');
  await pick(page, 'txt1');
  await page.keyboard.press('Escape');
  await handle(page, 'divider-0').waitFor({ timeout: 5000 });
  await handle(page, 'divider-0').focus();
  await page.keyboard.press('ArrowRight');
  await page.waitForTimeout(150);
  await page.keyboard.press('ArrowRight');
  await page.waitForTimeout(250);
  await idle(page); // the autosave swaps the canvas: focus the second divider on the new one
  await handle(page, 'divider-1').waitFor({ timeout: 5000 });
  await handle(page, 'divider-1').focus();
  await page.keyboard.press('ArrowRight');
  await page.waitForTimeout(250);
  const build = await savedBuild(page, 'keys');
  expect(find(build.children, 'cg3').style.base.columns === '2fr 1fr 1fr', `the columns are ${find(build.children, 'cg3').style.base.columns}`);
});

await step('compose (keyboard): Alt+arrows resize an image to half width', async () => {
  await openBuilder(page, 'keys');
  await pick(page, 'cimg');
  for (let i = 0; i < 6; i++) { await page.keyboard.press('Alt+ArrowLeft'); await page.waitForTimeout(120); }
  const build = await savedBuild(page, 'keys');
  expect(find(build.children, 'cimg').style.base.width === '50%', `the image width is ${find(build.children, 'cimg').style.base.width}`);
});

await step('crop (keyboard): Enter opens, arrows and a digit, Enter is Done, Esc cancels', async () => {
  await openBuilder(page, 'keys');
  await pick(page, 'cimg');
  const before = await undoDepth(page);
  await page.keyboard.press('Enter');
  await frameIn(page).locator('#tl-bd-overlay .bdc-photo').waitFor({ timeout: 5000 });
  expect(await crop(page, 'crop-focal').evaluate((c) => c === c.ownerDocument.activeElement), 'the focus is not on the crosshair');
  await page.keyboard.press('ArrowRight');
  await page.keyboard.press('ArrowRight');
  await page.keyboard.press('Shift+ArrowDown');
  expect(await crop(page, 'crop-focal').getAttribute('aria-valuetext') === 'Focal point 60 %, 51 %', `the live value is ${await crop(page, 'crop-focal').getAttribute('aria-valuetext')}`);
  await page.keyboard.press('5'); // 16:9
  await page.keyboard.press('Enter');
  await idle(page);
  const base = await styleOf(page, 'base');
  expect(base.focal_point === '60% 51%' && base.aspect_ratio === '16/9' && base.object_fit === 'cover', `keyboard crop is ${JSON.stringify(base)}`);
  expect((await undoDepth(page)) === before + 1, 'the keyboard crop is not exactly one undo step');
  // Home centres, Esc restores the state before crop mode
  await pick(page, 'cimg');
  await page.keyboard.press('Enter');
  await frameIn(page).locator('#tl-bd-overlay .bdc-photo').waitFor({ timeout: 5000 });
  await page.keyboard.press('Home');
  expect(await crop(page, 'crop-focal').getAttribute('aria-valuetext') === 'Focal point 50 %, 50 %', 'Home did not centre the point');
  await page.keyboard.press('Escape');
  await page.waitForTimeout(300);
  expect(JSON.stringify(await styleOf(page, 'base')) === JSON.stringify(base) && (await undoDepth(page)) === before + 1, 'Esc wrote something');
  const saved = await savedBuild(page, 'keys');
  expect(find(saved.children, 'cimg').style.base.focal_point === '60% 51%', 'the keyboard crop did not survive a reload');
});

await step('background (keyboard): the popover by Tab and arrows, a swatch with Enter, Esc closes', async () => {
  await openBuilder(page, 'keys');
  await pick(page, 'cs1', true);
  const before = await undoDepth(page);
  const active = () => frameIn(page).locator('body').evaluate((b) => b.ownerDocument.activeElement.getAttribute('data-bdo'));
  await handle(page, 'background').focus();
  await page.keyboard.press('Enter');
  await popover(page).waitFor({ timeout: 5000 });
  await until(page, () => window.taleaBuilder.previewDoc().activeElement.getAttribute('data-bdo') === 'bg-tab-colour', null, 'the focus went into the popover, onto the selected tab');
  await page.keyboard.press('ArrowRight'); // the arrows move between the tabs
  await until(page, () => window.taleaBuilder.previewDoc().activeElement.getAttribute('data-bdo') === 'bg-tab-image', null, 'ArrowRight did not select the Image tab');
  expect(await handle(page, 'bg-tab-image').getAttribute('aria-selected') === 'true', 'the Image tab is not selected');
  await page.keyboard.press('ArrowLeft');
  await until(page, () => window.taleaBuilder.previewDoc().activeElement.getAttribute('data-bdo') === 'bg-tab-colour', null, 'ArrowLeft did not return to the Colour tab');
  await page.keyboard.press('Tab');
  expect((await active()) === 'bg-sw-none', `Tab from the tab reached ${await active()}`);
  await page.keyboard.press('Tab');
  expect((await active()) === 'bg-sw-primary', `the next Tab reached ${await active()}`);
  await page.keyboard.press('Enter');
  await idle(page);
  expect((await bgStyle(page, 'cs1')).background === 'primary' && (await undoDepth(page)) === before + 1, 'Enter on a swatch is not exactly one undo step');
  await popover(page).waitFor({ timeout: 5000 });
  await until(page, () => window.taleaBuilder.previewDoc().activeElement.getAttribute('data-bdo') === 'bg-sw-primary', null, 'the focus did not come back to the swatch after the canvas was swapped');
  await page.keyboard.press('Escape');
  await popover(page).waitFor({ state: 'detached', timeout: 3000 });
  expect((await active()) === 'background', `the focus after Esc is on ${await active()}`);
  expect(await page.evaluate(() => window.taleaBuilder.state.selected) === 'cs1', 'Esc moved the selection to the parent');
});

await step('compose (keyboard): Ctrl+Shift+arrow moves the button into the next column, arrows select siblings', async () => {
  await openBuilder(page, 'keys');
  await pick(page, 'txt4');
  await page.keyboard.press('ArrowDown');
  await page.waitForFunction(() => window.taleaBuilder.state.selected === 'btn1');
  await page.keyboard.press('Control+Shift+ArrowRight');
  await page.waitForTimeout(300);
  const build = await savedBuild(page, 'keys');
  expect(parentOf(build.children, 'btn1').id === 'colb', `the button is in ${parentOf(build.children, 'btn1').id}`);
});

// touch
await step('compose (touch): the four tasks by pointer events of a finger', async () => {
  const touch = await browser.newContext({ viewport: { width: 1180, height: 820 }, locale: 'en-GB', hasTouch: true });
  await touch.addInitScript(() => { try { localStorage.setItem('tl-bd-tour', '1'); } catch (e) { /* ignore */ } });
  const tp = await touch.newPage();
  watch(tp);
  await tp.goto(BASE + '/admin.php', { waitUntil: 'networkidle' });
  await tp.fill('input[name="username"]', 'admin');
  await tp.fill('input[name="password"]', PASSWORD);
  await Promise.all([tp.waitForNavigation(), tp.press('input[name="password"]', 'Enter')]);
  const cdp = await touch.newCDPSession(tp);
  const finger = async (from, to) => {
    await cdp.send('Input.dispatchTouchEvent', { type: 'touchStart', touchPoints: [{ x: from.x, y: from.y }] });
    for (let i = 1; i <= 10; i++) { await cdp.send('Input.dispatchTouchEvent', { type: 'touchMove', touchPoints: [{ x: from.x + (to.x - from.x) * i / 10, y: from.y + (to.y - from.y) * i / 10 }] }); }
    await cdp.send('Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [] });
    await idle(tp); // the autosave reloads the canvas: the next finger must not land on the old one
  };
  // the focus mode's canvas is wider (#115), so a target may lie below the fold: it is scrolled into view before the finger goes down
  const centre = async (locator) => { await locator.scrollIntoViewIfNeeded().catch(() => {}); const b = await locator.boundingBox(); if (!b) throw new Error(`${locator} has no box: it is not on the canvas`); return { x: b.x + b.width / 2, y: b.y + b.height / 2 }; };
  const tap = async (id, corner) => { await idle(tp); await node(tp, id).scrollIntoViewIfNeeded().catch(() => {}); const b = await node(tp, id).boundingBox(); await tp.touchscreen.tap(corner ? b.x + 3 : b.x + b.width / 2, corner ? b.y + 3 : b.y + b.height / 2); await tp.waitForFunction((x) => window.taleaBuilder.state.selected === x, id); await handle(tp, 'margin-bottom').waitFor(); };
  await openBuilder(tp, 'touch');

  await tap('cs1', true);
  const m = await scaleOf(tp);
  const from = await centre(handle(tp, 'margin-bottom'));
  await finger(from, { x: from.x, y: from.y + 30 * m });

  await tap('txt1');
  await tp.evaluate(() => window.taleaBuilder.selection('col1'));
  await handle(tp, 'divider-0').waitFor();
  await tp.waitForTimeout(1000);
  const grid3 = await grid(tp, 'cg3');
  let d = await centre(handle(tp, 'divider-0'));
  await finger(d, { x: d.x + (2 / 12) * grid3.width * 0.97, y: d.y });
  await handle(tp, 'divider-1').waitFor(); // finger() waited for the new canvas; its overlay draws the dividers again
  d = await centre(handle(tp, 'divider-1'));
  await finger(d, { x: d.x + (1 / 12) * grid3.width * 0.97, y: d.y });

  await tap('cimg');
  const image = await node(tp, 'cimg').boundingBox();
  const corner = await centre(handle(tp, 'resize-se'));
  await finger(corner, { x: corner.x - image.width / 2, y: corner.y });

  // moving by touch: the toolbar grip starts "tap the place", then the place is tapped
  await tp.evaluate(() => window.taleaBuilder.selection('btn1')); // the image's toolbar covers the button above it, so it is selected by the editor
  await handle(tp, 'margin-bottom').waitFor();
  await tp.waitForTimeout(1000); // the canvas scrolls smoothly to the selection, the toolbar follows
  const grip = await centre(frameIn(tp).locator('#tl-bd-overlay [data-bdo="move"]').first());
  await tp.touchscreen.tap(grip.x, grip.y);
  await tp.waitForFunction(() => !!window.taleaBuilder.state.placing);
  await tp.waitForTimeout(400);
  await node(tp, 'txt5').scrollIntoViewIfNeeded().catch(() => {});
  const right = await node(tp, 'txt5').boundingBox();
  await tp.touchscreen.tap(right.x + right.width / 2, right.y + right.height * 0.8);
  await tp.waitForTimeout(500);

  const build = await savedBuild(tp, 'touch');
  expect(TOKENS.includes((find(build.children, 'cs1').style ?? {}).base?.margin_bottom), 'touch: no spacing token written');
  expect(find(build.children, 'cg3').style.base.columns === '2fr 1fr 1fr', `touch: the columns are ${find(build.children, 'cg3').style.base.columns}`);
  expect(find(build.children, 'cimg').style.base.width === '50%', `touch: the image width is ${find(build.children, 'cimg').style.base.width}`);
  expect(parentOf(build.children, 'btn1').id === 'colb', 'touch: the button did not move to the right column');
  await touch.close();
});

await step('background and crop (touch): a swatch is tapped, the photo is dragged by a finger', async () => {
  const touch = await browser.newContext({ viewport: { width: 1180, height: 820 }, locale: 'en-GB', hasTouch: true });
  await touch.addInitScript(() => { try { localStorage.setItem('tl-bd-tour', '1'); } catch (e) { /* ignore */ } });
  const tp = await touch.newPage();
  watch(tp);
  await tp.goto(BASE + '/admin.php', { waitUntil: 'networkidle' });
  await tp.fill('input[name="username"]', 'admin');
  await tp.fill('input[name="password"]', PASSWORD);
  await Promise.all([tp.waitForNavigation(), tp.press('input[name="password"]', 'Enter')]);
  const cdp = await touch.newCDPSession(tp);
  const centre = async (locator) => { await locator.scrollIntoViewIfNeeded().catch(() => {}); const b = await locator.boundingBox(); if (!b) throw new Error(`${locator} has no box`); return { x: b.x + b.width / 2, y: b.y + b.height / 2 }; };
  const tapOn = async (locator) => { const c = await centre(locator); await tp.touchscreen.tap(c.x, c.y); };
  await openBuilder(tp, 'touch');
  await idle(tp);
  // the background: tap the section, the button, a swatch
  await node(tp, 'cs1').scrollIntoViewIfNeeded().catch(() => {});
  const box = await node(tp, 'cs1').boundingBox();
  await tp.touchscreen.tap(box.x + 3, box.y + 3);
  await tp.waitForFunction(() => window.taleaBuilder.state.selected === 'cs1');
  await handle(tp, 'background').waitFor({ timeout: 5000 });
  await tapOn(handle(tp, 'background'));
  await popover(tp).waitFor({ timeout: 5000 });
  await tapOn(handle(tp, 'bg-sw-secondary'));
  await idle(tp).catch((e) => { throw new Error('after the swatch: ' + e.message); });
  expect((await bgStyle(tp, 'cs1')).background === 'secondary', `touch: the colour is ${JSON.stringify(await bgStyle(tp, 'cs1'))}`);
  // the crop: tap the image, the Crop button, drag the photo with a finger, Done
  await tp.evaluate(() => window.taleaBuilder.selection('cimg'));
  await handle(tp, 'crop').waitFor({ timeout: 5000 });
  await tp.waitForTimeout(800);
  await handle(tp, 'crop').tap({ timeout: 8000 });
  const photo = frameIn(tp).locator('#tl-bd-overlay .bdc-photo');
  await photo.waitFor({ timeout: 5000 });
  const b = await photo.boundingBox();
  const from = { x: b.x + b.width * 0.3, y: b.y + b.height * 0.3 };
  const to = { x: b.x + b.width * 0.75, y: b.y + b.height * 0.6 };
  await cdp.send('Input.dispatchTouchEvent', { type: 'touchStart', touchPoints: [from] });
  for (let i = 1; i <= 10; i++) { await cdp.send('Input.dispatchTouchEvent', { type: 'touchMove', touchPoints: [{ x: from.x + (to.x - from.x) * i / 10, y: from.y + (to.y - from.y) * i / 10 }] }); }
  await cdp.send('Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [] });
  await tp.waitForTimeout(350);
  await tp.waitForTimeout(1500); // a tap right after a synthetic finger drag is taken for part of it (no click): a person lifts the finger first
  await crop(tp, 'crop-done').tap({ timeout: 8000 });
  await idle(tp).catch((e) => { throw new Error('after Done: ' + e.message); });
  const [fx, fy] = focalOf((await bgStyle(tp, 'cimg')).focal_point);
  expect(Math.abs(fx - 75) <= 4 && Math.abs(fy - 60) <= 4, `touch: the focal point is ${JSON.stringify(await bgStyle(tp, 'cimg'))}`);
  await touch.close();
});

// free placement: a Compose section (phase B)
await step('compose (free placement): convert, resize and move by grid lines, overlap with a layer, stack on a phone, tidy up, back to a stack', async () => {
  await openBuilder(page, 'free');
  const buildNow = () => page.evaluate(() => JSON.parse(JSON.stringify(window.taleaBuilder.state.build)));
  const cell = async (id) => { const s = find((await buildNow()).children, id).style?.base ?? {}; return { c0: +s.grid_column_start, c1: +s.grid_column_end, r0: +s.grid_row_start, r1: +s.grid_row_end, layer: s.layer ?? '' }; };
  // the canvas is swapped once the autosave is done: wait for it before the next gesture
  const select = async (id, wait) => { await idle(page); await page.evaluate((x) => window.taleaBuilder.selection(x), id); await handle(page, wait).waitFor({ timeout: 5000 }); await page.waitForTimeout(400); };
  const pitch = async () => page.evaluate(() => { const d = window.taleaBuilder.previewDoc(); const c = d.querySelector('.tl-compose'); const cs = d.defaultView.getComputedStyle(c); return (c.getBoundingClientRect().width + parseFloat(cs.columnGap)) / 12 * window.taleaBuilder.scale(); });
  const toggle = () => frameIn(page).locator('#tl-bd-overlay [data-bdo="compose"]').first();

  // Stack -> Compose keeps the positions: every child gets a cell
  await select('cf1', 'compose');
  await toggle().click();
  await idle(page);
  await until(page, () => window.taleaBuilder.previewDoc().querySelector('.tl-compose'), null, 'the canvas shows the compose section');
  let a = await cell('cfa');
  let b = await cell('cfb');
  expect(a.c0 === 1 && a.c1 === 13 && a.r0 >= 1 && a.r1 > a.r0 && b.r0 >= a.r1 - 1 && b.r1 > b.r0, `the conversion gave no sane cells: ${JSON.stringify([a, b])}`);

  // resize the text to six columns (the right edge handle), then move it three columns to the right (the grip)
  await select('cfa', 'resize-se');
  const p1 = await pitch();
  await mouseDrag(page, handle(page, 'resize-se'), -6 * p1, 0);
  a = await cell('cfa');
  expect(a.c0 === 1 && a.c1 === 7, `the text should span lines 1–7, it is ${a.c0}–${a.c1}`);
  await select('cfa', 'compose-move');
  await mouseDrag(page, handle(page, 'compose-move'), 3 * p1, 0);
  a = await cell('cfa');
  expect(a.c0 === 4 && a.c1 === 10, `the text should span lines 4–10, it is ${a.c0}–${a.c1}`);

  // the arrow keys step one cell
  await handle(page, 'compose-move').focus();
  await page.keyboard.press('ArrowRight');
  await idle(page);
  a = await cell('cfa');
  expect(a.c0 === 5 && a.c1 === 11, `ArrowRight should move the text one column, it is ${a.c0}–${a.c1}`);

  // overlap: put the image on top of the text with the layer scale (the grip drags it to the text's rows)
  const rowOf = async (id) => page.evaluate((x) => window.taleaBuilder.previewDoc().querySelector(`[data-tl-id="${x}"]`).getBoundingClientRect().top, id);
  await select('cfb', 'compose-move');
  const dy = ((await rowOf('cfa')) - (await rowOf('cfb'))) * (await scaleOf(page));
  await mouseDrag(page, handle(page, 'compose-move'), 2 * p1, dy);
  a = await cell('cfa');
  b = await cell('cfb');
  expect(b.r0 < a.r1 && a.r0 < b.r1 && b.c0 < a.c1 && a.c0 < b.c1, `the image does not overlap the text: ${JSON.stringify([a, b])}`);
  await frameIn(page).locator('#tl-bd-overlay [data-bdo="layer-up"]').first().click();
  await idle(page);
  expect((await cell('cfb')).layer === 'above', 'the layer button did not set "above"');

  // phone: the canvas stacks the section in source order, no overlap
  await device(page, 'Mobile', 'mobile');
  const boxes = await page.evaluate(() => { const d = window.taleaBuilder.previewDoc(); return ['cfa', 'cfb'].map((x) => { const r = d.querySelector(`[data-tl-id="${x}"]`).getBoundingClientRect(); return [r.top, r.bottom]; }); });
  expect(boxes[0][1] <= boxes[1][0] + 1, `at phone width the elements still overlap: ${JSON.stringify(boxes)}`);
  expect(await page.evaluate(() => window.taleaBuilder.previewDoc().defaultView.getComputedStyle(window.taleaBuilder.previewDoc().querySelector('.tl-compose')).display) === 'flex', 'the compose section does not stack on a phone');
  await device(page, 'Desktop', 'base');

  // tidy up: one clean column in reading order, no overlap
  await select('cf1', 'tidy');
  await frameIn(page).locator('#tl-bd-overlay [data-bdo="tidy"]').first().click();
  await idle(page);
  a = await cell('cfa');
  b = await cell('cfb');
  const [upper, lower] = a.r0 < b.r0 ? [a, b] : [b, a];
  const order = find((await buildNow()).children, 'cf1').children.map((c) => c.id);
  expect(a.c0 === 1 && a.c1 === 13 && b.c0 === 1 && b.c1 === 13 && upper.r1 <= lower.r0, `tidy up did not make a clean column: ${JSON.stringify([a, b])}`);
  expect(order[0] === (a.r0 < b.r0 ? 'cfa' : 'cfb'), `the source order does not follow the reading order: ${order}`);

  // back to a stack drops every placement; compose again places them anew
  await select('cf1', 'compose');
  await toggle().click();
  await idle(page);
  let tree = await buildNow();
  expect(find(tree.children, 'cf1').content.layout === 'stack' && !find(tree.children, 'cfa').style?.base?.grid_column_start && !find(tree.children, 'cfb').style?.base?.layer, 'switching back to a stack left placements behind');
  await select('cf1', 'compose');
  await toggle().click();
  tree = await savedBuild(page, 'free');
  expect(find(tree.children, 'cf1').content.layout === 'compose' && find(tree.children, 'cfa').style?.base?.grid_row_end && find(tree.children, 'cfb').style?.base?.grid_column_end, 'the saved draft lacks the compose layout or the placements');
});

// editing on its own copy of the compose page (#232): the Style panel, the Add panel, the canvas, the paste dialog and the keys
await step('builder (editing): a Style-panel value reaches the draft and the canvas', async () => {
  await openBuilder(page, 'editing');
  await pick(page, 'ch1');
  await page.getByRole('tab', { name: 'Style' }).first().click();
  const align = page.locator('#bd-v-text_align');
  await align.waitFor({ timeout: 5000 });
  await align.selectOption('center');
  expect(find((await page.evaluate(() => window.taleaBuilder.state.build)).children, 'ch1').style?.base?.text_align === 'center', 'the Text alignment select did not write the style of the heading');
  await idle(page);
  await until(page, () => { const d = window.taleaBuilder.previewDoc(); const h = d && d.querySelector('[data-tl-id="ch1"]'); return !!h && d.defaultView.getComputedStyle(h).textAlign === 'center'; }, null, 'the canvas shows the heading centred');
  const build = await savedBuild(page, 'editing');
  expect(find(build.children, 'ch1').style?.base?.text_align === 'center', 'the saved draft lacks the text alignment');
});

await step('builder (editing): an element dragged from the Add panel lands where it is dropped on the canvas', async () => {
  await openBuilder(page, 'editing');
  await page.locator('.bd-rail button[aria-label="Add elements and sections"]').click();
  await page.locator('.bd-elements button').first().waitFor({ timeout: 5000 });
  // Playwright cannot drive a real HTML5 drag from the admin page into the canvas iframe reliably, so the drag events are dispatched
  // by hand: they still run the editor's own handlers (startDrag on the Add panel button, canvasSpot and dropAt on the canvas)
  const failed = await page.evaluate(() => {
    const B = window.taleaBuilder;
    const name = B.D.schema.elements.find((p) => p.type === 'heading').name;
    const source = [...document.querySelectorAll('.bd-elements button')].find((b) => b.textContent.trim() === name);
    if (!source) { return `no "${name}" button in the Add panel`; }
    const transfer = new DataTransfer();
    source.dispatchEvent(new DragEvent('dragstart', { dataTransfer: transfer, bubbles: true, cancelable: true }));
    const target = B.previewDoc().querySelector('[data-tl-id="txt5"]');
    const r = target.getBoundingClientRect();
    const at = { clientX: r.left + r.width / 2, clientY: r.top + r.height * 0.8, dataTransfer: transfer, bubbles: true, cancelable: true };
    target.dispatchEvent(new DragEvent('dragover', at));
    target.dispatchEvent(new DragEvent('drop', at));
    source.dispatchEvent(new DragEvent('dragend', { dataTransfer: transfer, bubbles: true }));
    return '';
  });
  expect(!failed, failed);
  const id = await page.evaluate(() => window.taleaBuilder.state.selected);
  const build = await savedBuild(page, 'editing');
  const dropped = find(build.children, id);
  const column = find(build.children, 'colb');
  expect(dropped && dropped.type === 'heading', `the drop did not insert a heading: ${JSON.stringify(dropped)}`);
  expect(column.children.map((c) => c.id).join() === `txt5,${id}`, `the heading is not after the text it was dropped on: ${column.children.map((c) => c.id)}`);
});

await step('builder (editing): double-click edits a heading on the canvas', async () => {
  await openBuilder(page, 'editing');
  await node(page, 'ch1').dblclick();
  await until(page, () => window.taleaBuilder.state.editingCanvas, null, 'the double-click made the heading editable on the canvas');
  await page.keyboard.press('End'); // the double-click selected a word: typing would replace it
  await page.keyboard.type(' edited');
  await page.keyboard.press('Enter'); // ends the editing of a heading
  await until(page, () => !window.taleaBuilder.state.editingCanvas, null, 'Enter ended the editing');
  const build = await savedBuild(page, 'editing');
  const text = find(build.children, 'ch1').content.text;
  expect(/^Compose test(\s|&nbsp;)edited$/.test(text), `the heading text is ${text}`);
});

await step('builder (editing): the "Paste from another Talea site" dialog refuses other text, closes and inserts', async () => {
  await openBuilder(page, 'editing');
  const open = async () => {
    await page.locator('button[aria-label="More actions"]').first().click();
    await page.locator('.bd-more button', { hasText: 'Paste from another Talea site' }).first().click();
    await page.locator('dialog[open] h2', { hasText: 'Paste from another Talea site' }).waitFor({ timeout: 5000 });
  };
  await pick(page, 'txt1');
  await open();
  await page.locator('dialog[open] textarea').fill('not a copy');
  await page.locator('dialog[open] .bd-btn-main').click();
  expect(await page.locator('dialog[open] .bd-share-error').isVisible(), 'other text gives no error in the dialog');
  await page.locator('dialog[open] .bd-btn', { hasText: 'Close' }).click();
  expect(!(await page.locator('dialog[open]').count()), 'Close did not close the paste dialog');
  await open();
  await page.locator('dialog[open] textarea').fill(JSON.stringify({ talea: 'elements', v: 1, site: new URL(BASE).origin, elements: [{ type: 'text', content: { html: '<p>Pasted 232</p>' } }] }));
  await page.locator('dialog[open] .bd-btn-main').click();
  expect(!(await page.locator('dialog[open]').count()), 'Insert did not close the paste dialog');
  await until(page, () => JSON.stringify(window.taleaBuilder.state.build).includes('Pasted 232'), null, 'the pasted text is in the page');
  const build = await savedBuild(page, 'editing');
  const column = find(build.children, 'col1');
  expect(column.children.length === 2 && column.children[0].id === 'txt1' && JSON.stringify(column.children[1]).includes('Pasted 232'), `the pasted text is not after the selected one: ${JSON.stringify(column.children).slice(0, 300)}`);
});

await step('builder (editing): Ctrl+D duplicates, Delete removes, Ctrl+Z and Ctrl+Shift+Z undo and redo', async () => {
  await openBuilder(page, 'editing');
  const columnIds = () => page.evaluate(() => { const walk = (c) => { for (const p of c ?? []) { if (p.id === 'col2') { return p; } const f = walk(p.children); if (f) { return f; } } return null; }; return walk(window.taleaBuilder.state.build.children).children.map((p) => p.id); });
  await pick(page, 'txt2');
  await page.keyboard.press('Control+d');
  const copy = await page.evaluate(() => window.taleaBuilder.state.selected);
  expect(copy !== 'txt2' && (await columnIds()).join() === `txt2,${copy}`, `Ctrl+D did not put a copy after the text: ${await columnIds()}`);
  await idle(page);
  await page.keyboard.press('Delete');
  expect((await columnIds()).join() === 'txt2', `Delete did not remove the copy: ${await columnIds()}`);
  await idle(page);
  await page.keyboard.press('Control+z');
  expect((await columnIds()).join() === `txt2,${copy}`, `Ctrl+Z did not bring the copy back: ${await columnIds()}`);
  await idle(page);
  await page.keyboard.press('Control+Shift+z');
  expect((await columnIds()).join() === 'txt2', `Ctrl+Shift+Z did not remove it again: ${await columnIds()}`);
  const build = await savedBuild(page, 'editing');
  expect(find(build.children, 'col2').children.map((c) => c.id).join() === 'txt2', 'the saved draft does not match the last redo');
});

await step('forms: a new form opens in the form builder, a page places it and gives its fields a width', async () => {
  await visit('/admin.php?module=forms&action=new');
  await page.fill('input[name="name"]', 'Browser form');
  await Promise.all([page.waitForURL(/\/forms\/builder\//), page.click('form.form input[type="submit"]')]);
  await until(page, () => window.taleaBuilder && window.taleaBuilder.previewDoc() && window.taleaBuilder.previewDoc().querySelector('form.tl-form'), null, 'the form builder shows the form on its canvas');
  await page.locator('.bd-rail button[aria-label="Add elements and sections"]').click();
  expect(await page.getByText('A form holds only its fields.').count() === 1, 'the form builder offers no elements to add');
  const form = new URL(page.url()).pathname.split('/').pop();
  await visit(`/admin.php?module=pages&action=builder&id=${PAGE}`);
  await until(page, () => window.taleaBuilder && window.taleaBuilder.previewDoc() && window.taleaBuilder.previewDoc().querySelector('[data-tl-id]'), null, 'the page builder is open');
  const offered = await page.evaluate(() => window.taleaBuilder.D.schema.elements.filter((p) => p.offered !== false).map((p) => p.type));
  expect(offered.includes('form_embed') && !offered.includes('form'), `the Add panel offers the placement, not the fields: ${offered.join()}`);
  const id = await page.evaluate((chosen) => {
    const B = window.taleaBuilder;
    const n = B.newElement('form_embed');
    n.content.form = chosen;
    B.applyChange(() => { B.state.build.children.push(n); B.state.selected = n.id; });
    B.redrawPanels();
    return n.id;
  }, form);
  await idle(page);
  await until(page, (placed) => window.taleaBuilder.previewDoc().querySelector(`form.tl-form[data-tl-id="${placed}"]`), id, 'the canvas shows the placed form, its draft');
  await page.locator('.bd-form-widths select').first().selectOption('3');
  await idle(page);
  await until(page, (placed) => window.taleaBuilder.previewDoc().querySelector(`form.tl-form-layout[data-tl-id="${placed}"] .tl-span-3`), id, 'the field is half as wide on the canvas');
  await visit(`/admin.php?module=pages&action=builder&id=${PAGE}`);
  const saved = find(await page.evaluate(() => JSON.parse(document.getElementById('builder-data').textContent).build.children), id);
  expect(saved && saved.content.form === form && Object.values(saved.content.widths || {}).join() === '3', `the draft keeps the placement: ${JSON.stringify(saved)}`);
  await page.evaluate((placed) => { const B = window.taleaBuilder; B.applyChange(() => { B.state.build.children = B.state.build.children.filter((c) => c.id !== placed); }); }, id);
  await idle(page);
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

await step('news editor: pasted HTML is cleaned without running anything (#148)', async () => {
  // the paste cleanup used to parse the HTML in the admin page itself: an <img onerror> ran before it was removed
  const editor = page.locator('[contenteditable="true"]').first();
  await editor.click();
  const result = await editor.evaluate(async (surface) => {
    window.__pasteRan = 0;
    const blocked = [];
    const watch = (e) => blocked.push(`${e.violatedDirective} ${e.sourceFile}:${e.lineNumber} ${e.target && e.target.outerHTML ? e.target.outerHTML.slice(0, 160) : e.target}`);
    document.addEventListener('securitypolicyviolation', watch);
    const data = new DataTransfer();
    data.setData('text/html', '<p>Pasted <mark>word</mark></p><img src="/media/missing-148.png" onerror="window.__pasteRan = 1">'
      + '<a href="java&#9;script:window.__pasteRan=2">one</a> <a href=" JaVaScRiPt:window.__pasteRan=3">two</a> <a href="data:text/html,x" target="_top" rel="opener">three</a>'
      + '<iframe src="https://frames.example/x"></iframe><noscript><img src=x onerror="window.__pasteRan = 4"></noscript>');
    surface.dispatchEvent(new ClipboardEvent('paste', { clipboardData: data, bubbles: true, cancelable: true }));
    await new Promise((r) => setTimeout(r, 600));
    document.removeEventListener('securitypolicyviolation', watch);
    return { ran: window.__pasteRan, html: surface.innerHTML, blocked };
  });
  if (result.ran) { throw new Error(`the pasted HTML ran code (${result.ran})`); }
  if (result.blocked.length) { throw new Error(`the pasted HTML tried to run a handler in the admin page: ${result.blocked.join(' | ')}`); }
  if (/onerror|script:|data:|frames\.example|_top|rel="opener"/i.test(result.html)) { throw new Error(`the pasted HTML kept a handler, an unsafe address, a frame or a target: ${result.html}`); }
  if (!/<mark>word<\/mark>/.test(result.html)) { throw new Error(`the highlighted word was lost: ${result.html}`); }
});

await step('builder: a pasted envelope claiming this site goes through the server, its Custom HTML is left out (#148)', async () => {
  await visit(`/admin.php?module=pages&action=builder&id=${PAGE}`);
  await page.waitForTimeout(1500);
  const status = await page.evaluate(async (origin) => {
    window.__clipRan = 0;
    const text = JSON.stringify({ talea: 'elements', v: 1, site: origin, elements: [
      { type: 'text', content: { html: '<p>Clip 148<img src="/media/missing-148.png" alt="" onerror="window.__clipRan = 1"></p>' } },
      { type: 'custom_html', content: { code: '<p>Code 148</p>' } }] });
    const data = new DataTransfer();
    data.setData('text/plain', text);
    document.dispatchEvent(new ClipboardEvent('paste', { clipboardData: data, bubbles: true, cancelable: true }));
    const box = document.querySelector('.bd-status');
    for (let i = 0; i < 50 && !/^Inserted|could not|None of/.test(box.textContent); i++) { await new Promise((r) => setTimeout(r, 100)); }
    return box.textContent;
  }, new URL(BASE).origin);
  if (!/^Inserted/.test(status) || !/Custom HTML elements left out: 1/.test(status)) { throw new Error(`the paste did not go through the server: ${status}`); }
  const inserted = canvas().locator('.tl-text', { hasText: 'Clip 148' }).first();
  await inserted.waitFor({ timeout: 8000 });
  await page.waitForTimeout(500);
  if (await page.evaluate(() => window.__clipRan)) { throw new Error('the pasted envelope ran code in the admin'); }
  if (/onerror/i.test(await inserted.innerHTML())) { throw new Error('the server kept the handler'); }
  if (await canvas().getByText('Code 148').count()) { throw new Error('the unsigned Custom HTML was inserted'); }
  await page.keyboard.press('Control+z');
  await page.waitForTimeout(800);
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
  await page.locator('.tl-gallery--slideshow[data-enabled] .tl-slider-arrows [data-step="1"]').click();
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
  // late on the last day of a month the lead time leaves no free day in it: the next month has them
  await page.locator('.tl-booking-days').waitFor({ timeout: 5000 });
  if (!(await free.count())) { await page.locator('.tl-booking-month button').last().click(); }
  await free.waitFor({ timeout: 5000 });
  await free.click();
  await page.locator('.tl-booking-times button').first().waitFor({ timeout: 5000 });
  await page.waitForTimeout(500);
  if (!(await page.locator('.tl-booking-days button[aria-pressed="true"]').count())) { throw new Error('after picking a day the month is not drawn (or the day is not marked)'); }
  await page.locator('.tl-booking-times button').first().click();
  if (!(await page.locator('[data-selected]:visible').count())) { throw new Error('picking a time does not show the chosen time'); }
});

// German texts are among the longest: the administration in German once more, every screen without content that spills out (the last
// step, as the steps before find buttons by their English names)
await step('administration in German: nothing spills out of its box', async () => {
  await visit('/admin.php?action=account');
  await page.locator('#language').selectOption('de');
  await Promise.all([page.waitForNavigation(), page.locator('form:has(#language) input[type="submit"]').click()]);
  await visit('/admin.php');
  if (await page.getAttribute('html', 'lang') !== 'de') { throw new Error('the administration is not German: ' + await page.getAttribute('html', 'lang')); }
  const failed = [];
  for (const url of ADMIN_PAGES) {
    await visit(url);
    await nothingSpillsOut().catch((e) => failed.push(`${url}: ${e.message}`));
  }
  if (failed.length) { throw new Error(failed.join('\n  ')); }
});

await browser.close();
if (errors.length) {
  console.log(`\nSCRIPT ERRORS: ${errors.length}\n  ` + [...new Set(errors)].join('\n  '));
  process.exit(1);
}
console.log(`\nBROWSER TEST OK (${steps} steps)`);
