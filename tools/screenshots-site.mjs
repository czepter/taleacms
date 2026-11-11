// Talea – the admin screenshots in docs/screenshots/site/ (feature pictures for the project website and the guide).
// Called by tools/screenshots.sh after tools/screenshots.mjs on the business install, which carries tools/screenshots.sql.
// Env: BASE, PASSWORD, OUT (docs/screenshots), CHROME, NODE_PATH (folder with playwright-core).
// Content it adds goes through MCP like a connected Claude would (so Change log → AI sessions has a session) and keeps
// bracketed placeholders (#54). A screen that fails is reported and skipped; the run fails at the end.
import { createRequire } from 'node:module';
import { createHash, randomBytes } from 'node:crypto';

const require = createRequire(`${process.env.NODE_PATH}/`);
const { chromium } = require('playwright-core');
const { BASE, PASSWORD, CHROME } = process.env;
const OUT = `${process.env.OUT}/site`;
const DESKTOP = { width: 1440, height: 900 };
const PHONE = { width: 390, height: 844 };
const failed = [];

const browser = await chromium.launch({ executablePath: CHROME, headless: true });
const context = await browser.newContext({ viewport: DESKTOP, deviceScaleFactor: 2, colorScheme: 'light', locale: 'en-GB' });
await context.addInitScript(() => { try { localStorage.setItem('tl-bd-tour', '1'); } catch (e) { /* ignore */ } });
// public pages are shot by a visitor (no "Edit here"), the administration by the signed-in owner
const guest = await (await browser.newContext({ viewport: DESKTOP, deviceScaleFactor: 2, colorScheme: 'light', locale: 'en-GB' })).newPage();
let page = await context.newPage();
const admin = page;
page.setDefaultTimeout(20000);
const canvas = () => page.frameLocator('.bd-canvas iframe').first();

async function settle() {
  await page.evaluate(async () => { await Promise.all([...document.fonts].map((f) => f.load().catch(() => null))); await document.fonts.ready; });
  await page.waitForTimeout(400);
}

async function shot(name, url, { viewport = DESKTOP, full = false, before = null, visitor = false } = {}) {
  page = visitor ? guest : admin;
  try {
    await page.setViewportSize(viewport);
    await page.emulateMedia({ colorScheme: 'light', reducedMotion: 'reduce' });
    if (url) await page.goto(BASE + url, { waitUntil: 'networkidle' });
    if (before) await before();
    await settle();
    await page.screenshot({ path: `${OUT}/${name}.png`, fullPage: full });
    console.log('  site/' + name);
  } catch (e) {
    failed.push(name);
    console.log(`  site/${name} FAILED: ${e.message.split('\n')[0]}`);
  }
}

// before the install (INSTALLER=1): only the whole installer page
if (process.env.INSTALLER) {
  await shot('installer-full', '/install.php', { full: true });
  await browser.close();
  process.exit(failed.length ? 1 : 0);
}

// sign in
await page.goto(`${BASE}/admin.php`);
await page.fill('input[name="username"]', 'admin');
await page.fill('input[name="password"]', PASSWORD);
await Promise.all([page.waitForNavigation(), page.press('input[name="password"]', 'Enter')]);

// a personal Claude token (full access, all tools), as the owner would create it in My account → AI connection
await page.goto(`${BASE}/admin/account?tab=claude`);
const tokenForm = page.locator('form:has(input[name="current_password"])');
await tokenForm.locator('input[name="name"]').fill('Claude');
await tokenForm.locator('input[name="access"][value="full"]').check();
await tokenForm.locator('input[name="tool_set"][value="all"]').check();
await Promise.all([page.waitForNavigation(), tokenForm.evaluate((form, password) => {
  form.querySelector('[name=current_password]').value = password;
  form.requestSubmit([...form.querySelectorAll('button[name=op][value=token_new]')].pop());
}, PASSWORD)]);
const token = (await page.content()).match(/talea_[0-9a-f]{40,}/)[0]; // shown once, after the form

async function mcp(name, args = {}) {
  const response = await fetch(`${BASE}/mcp`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json, text/event-stream', Authorization: `Bearer ${token}`, 'MCP-Protocol-Version': '2025-06-18' },
    body: JSON.stringify({ jsonrpc: '2.0', id: 1, method: 'tools/call', params: { name, arguments: args } }),
  });
  const json = await response.json();
  const text = (json.result?.content || []).map((c) => c.text).join('\n');
  if (json.error || json.result?.isError) throw new Error(`${name}: ${json.error?.message || text}`);
  try { return JSON.parse(text); } catch { return text; }
}

// content the screens need, written as a connected Claude would: three items of a collection, a booking page and a multi-step form
const machines = (await mcp('list_collections')).find((c) => /machines/i.test(c.name));
for (let i = 1; i <= 3; i++) {
  await mcp('save_collection_item', { collection: machines.collection, name: `[Machine ${i}]`, visible: true,
    values: { model: '[Model]', year: '', availability: '[Availability]', parameters: '<p>[Main parameters of the machine]</p>' } });
}
const bookingPage = await mcp('create_page', { title: 'Book a site survey', slug: 'book-a-site-survey', visible: true });
const section = (children) => ({ type: 'section', children: [{ type: 'container', children }] });
await mcp('save_build', { id: bookingPage.id, build: { v: 1, children: [section([
  { type: 'heading', tag: 'h1', content: { text: 'Book a site survey' } },
  { type: 'text', content: { text: '<p>Choose a time that suits you. We confirm every request by e-mail.</p>' } },
  { type: 'booking', content: {} },
])] } });
await mcp('publish_build', { id: bookingPage.id });
const quotePage = await mcp('create_page', { title: 'Quick quote', slug: 'quick-quote', visible: true });
await mcp('save_build', { id: quotePage.id, build: { v: 1, children: [section([
  { type: 'heading', tag: 'h1', content: { text: 'Quick quote' } },
  { type: 'form', content: { name: 'Quick quote', fields: [
    { label: 'What do you need?', type: 'radio', required: true, choices: 'New installation\nMaintenance\nInspection\nSomething else' },
    { label: 'Rough size', type: 'select', choices: '', options: 'Small\nMedium\nLarge' },
    { label: 'About you', type: 'step' },
    { label: 'Your name', type: 'text', required: true },
    { label: 'Email', type: 'email', required: true },
    { label: 'Details', type: 'step' },
    { label: 'Anything else we should know?', type: 'textarea' },
    { label: 'I agree to the processing of my personal data for the purpose of handling this enquiry.', type: 'checkbox', required: true },
  ] } },
])] } });
await mcp('publish_build', { id: quotePage.id });

// the administration names pages and collections by their public ids: take them from its own links
const pageList = await mcp('list_pages');
const builder = (slug) => `/admin/pages/builder/${pageList.find((p) => (slug === '' ? p.home : new URL(p.url).pathname === `/${slug}`)).id}`;
const adminLink = async (url, pattern) => page.evaluate(async ([base, url, pattern]) => {
  const html = await (await fetch(base + url)).text();
  return (html.match(new RegExp(pattern)) || [''])[0];
}, [BASE, url, pattern]);
const machinesId = (await adminLink('/admin/collections', '/admin/collections/items/[0-9a-f-]{36}"[^>]*>\\s*Machines and equipment')).slice(25, 61);
const firstEnquiry = await adminLink('/admin/enquiries', '/admin/enquiries/[a-z]+/[0-9a-f-]{36}');

// the dashboard, on a computer and on a phone with the menu open
await shot('dashboard', '/admin');
await shot('dashboard-phone-menu', '/admin', { viewport: PHONE, before: async () => {
  await page.getByRole('button', { name: 'Menu', exact: true }).click();
  await page.waitForTimeout(500);
} });

// pages, news, menu, appearance, site parts
await shot('pages-list', '/admin/pages');
await shot('news-editor', '/admin/news/new');
await shot('menu-editor', '/admin/menu');
await shot('site-appearance-styles', '/admin/appearance');
await shot('site-parts-header', '/admin/parts/builder?type=header&language=', { before: async () => page.waitForTimeout(1500) });

// the builder: Add panel, a heading on the canvas, its style on a phone, classes, a collection list, an item detail template
await shot('builder-add-panel', builder(''), { before: async () => {
  await page.waitForTimeout(1500);
  await page.getByRole('button', { name: 'Add elements and sections' }).click();
  await page.waitForTimeout(500);
} });
await shot('builder-hero-heading', builder(''), { before: async () => {
  await page.waitForTimeout(1500);
  await canvas().locator('h1').first().click();
  await page.waitForTimeout(600);
} });
await shot('builder-mobile-style', null, { before: async () => {
  await page.getByRole('button', { name: 'Mobile' }).first().click();
  await page.waitForTimeout(1200);
  await canvas().locator('h1').first().click();
  await page.getByRole('tab', { name: 'Style' }).first().click();
  await page.waitForTimeout(600);
} });
await shot('builder-classes', builder('services'), { before: async () => {
  await page.waitForTimeout(1500);
  await canvas().locator('h2').first().click();
  await page.getByRole('tab', { name: 'Advanced' }).first().click();
  await page.waitForTimeout(600);
} });
await shot('builder-collection-list', builder('machines-and-equipment'), { before: async () => {
  await page.waitForTimeout(1500);
  await page.getByRole('tab', { name: 'Structure' }).first().click().catch(() => null);
  // the collection list: the element whose children are the item cards
  await canvas().locator('[data-tl-id]:has(> [data-tl-id].card)').first().click({ position: { x: 4, y: 4 } });
  await page.waitForTimeout(600);
} });
await shot('builder-detail-template', `/admin/collections/builder/${machinesId}`, { before: async () => page.waitForTimeout(1500) });
await shot('collection-fields', `/admin/collections/edit/${machinesId}`);

// enquiries and forms
await shot('enquiries-main', '/admin/enquiries');
await shot('enquiries-inbox', '/admin/enquiries?status=open');
if (firstEnquiry) await shot('enquiry-triage', firstEnquiry);
await shot('form-steps', '/quick-quote', { visitor: true, before: async () => {
  await page.locator('.tl-form, form').first().scrollIntoViewIfNeeded();
} });

// bookings
await shot('bookings-list', '/admin/bookings?view=all');
await shot('bookings-services', '/admin/bookings/services');
await shot('booking-element', '/book-a-site-survey', { visitor: true });

// the business, its search engines, the audit
await shot('business-details', '/admin/business');
await shot('settings-seo', '/admin/settings?tab=seo');
await shot('site-audit', '/admin/audit');

// AI: requests, the AI box, scheduled runs, sessions with undo, the consent screen
await shot('ask-claude-requests', '/admin/requests');
await shot('ask-claude-dashboard', '/admin/requests/new');
await shot('scheduled-runs', '/admin/schedules');
await shot('claude-sessions-undo', '/admin/changelog/sessions');
const client = await (await fetch(`${BASE}/oauth/register`, {
  method: 'POST', headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify({ client_name: 'Claude', redirect_uris: ['https://claude.ai/api/mcp/auth_callback'], token_endpoint_auth_method: 'none' }),
})).json();
const challenge = createHash('sha256').update(randomBytes(32).toString('base64url')).digest('base64url');
await shot('claude-consent', `/oauth/authorize?${new URLSearchParams({ response_type: 'code', client_id: client.client_id,
  redirect_uri: 'https://claude.ai/api/mcp/auth_callback', code_challenge: challenge, code_challenge_method: 'S256', state: 'screens', scope: 'mcp' })}`);

// administration
await shot('features', '/admin/extensions');
await shot('features-main', '/admin/extensions', { viewport: { width: 1200, height: 900 } });
await shot('add-ons', '/admin/addons');
await shot('integrations', '/admin/connectors');
await admin.goto(`${BASE}/admin/roles/new?preset=office`);
await Promise.all([admin.waitForNavigation(), admin.locator('main form').first().evaluate((form) => form.requestSubmit())]);
await shot('roles', '/admin/roles');
await shot('settings-backups', '/admin/settings?tab=backups');
await shot('system-status', '/admin/status');
await shot('fleet-console', '/admin/settings?tab=console');
await shot('import-from-website', '/admin/transfer', { before: async () => {
  await page.locator('details:has-text("Import from a website") > summary').first().click().catch(() => null);
} });
await shot('import-from-wordpress', '/admin/transfer', { before: async () => {
  await page.locator('details:has-text("Import from WordPress") > summary').first().click().catch(() => null);
} });

await browser.close();
if (failed.length) {
  console.log(`failed: ${failed.join(', ')}`);
  process.exit(1);
}
