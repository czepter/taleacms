/* Talea – page builder, the core: state, data, helpers, the tree, changes, history and saving, selection and tree edits, keys.
 * No libraries and no build step: the editor is a set of native ES modules in image/builder/ (main.js is the entry).
 *
 * The state is a tree of elements (the same shape PHP cleans and renders: Builder\Build). Every change goes into the history (undo/redo),
 * shortly afterwards it is saved as a draft (action stavba_uloz) and the canvas – the real site page in an iframe – is re-rendered.
 * There is deliberately no second rendering in JavaScript: what is on the canvas is exactly what the visitor will see.
 *
 * This module imports nothing of the editor; the other modules import it. What it calls in them (redrawing the panels, the canvas,
 * the clipboard, the help) goes through `ui`, which main.js fills with their functions – a registration instead of a circle of imports.
 */

export const T = window.T || ((s) => s);
export const root = document.getElementById('builder');
export const D = JSON.parse(document.getElementById('builder-data').textContent);
/* Text fields of a right-to-left page (Arabic) follow what is typed in them (dir="auto"); the builder itself stays left to right. */
export const contentDir = D.direction === 'rtl' ? 'auto' : null;
/** The form token; refreshToken() replaces it after a new sign-in (importers read the live binding). */
export let csrf = (document.querySelector('input[name="_csrf"]') || {}).value || '';
export const TYPY = Object.fromEntries(D.schema.elements.map((p) => [p.type, p]));
export const STYLE = D.schema.style;
export const BP = { base: T('Desktop'), tablet: T('Tablet'), mobile: T('Mobile') };
const ICONS = {
	section: '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M3 9h18M3 15h18"/>',
	container: '<rect x="4" y="4" width="16" height="16" rx="3"/><path d="M8 9h8M8 13h5"/>',
	grid: '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>',
	heading: '<path d="M6 4v16M18 4v16M6 12h12"/>',
	text: '<path d="M4 6h16M4 10h16M4 14h16M4 18h10"/>',
	image: '<rect x="3" y="5" width="18" height="14" rx="2"/><circle cx="9" cy="10" r="2"/><path d="m21 17-5-5-9 7"/>',
	button: '<rect x="3" y="8" width="18" height="8" rx="4"/><path d="M9 12h6"/>',
	list: '<path d="M9 6h11M9 12h11M9 18h11"/><circle cx="4.5" cy="6" r="1"/><circle cx="4.5" cy="12" r="1"/><circle cx="4.5" cy="18" r="1"/>',
	testimonial: '<path d="M7 7h4v4c0 3-2 5-4 6M15 7h4v4c0 3-2 5-4 6"/>',
	faq: '<circle cx="12" cy="12" r="9"/><path d="M9.5 9.5a2.5 2.5 0 1 1 3.5 2.3c-.6.3-1 .9-1 1.6v.6M12 17h.01"/>',
	video: '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m10 9 5 3-5 3z"/>',
	divider: '<path d="M3 12h18"/>',
	collection: '<rect x="3" y="4" width="8" height="7" rx="1.5"/><rect x="13" y="4" width="8" height="7" rx="1.5"/><rect x="3" y="13" width="8" height="7" rx="1.5"/><rect x="13" y="13" width="8" height="7" rx="1.5"/><path d="M5.5 8h3M15.5 8h3M5.5 17h3M15.5 17h3"/>',
	logo: '<circle cx="12" cy="12" r="8"/><path d="M9 15V9l3 3 3-3v6"/>',
	menu: '<path d="M4 7h16M4 12h16M4 17h16"/>',
	component: '<path d="M12 3 4 7.5v9L12 21l8-4.5v-9z"/><path d="M4 7.5 12 12l8-4.5M12 12v9"/>',
	form: '<rect x="4" y="3" width="16" height="18" rx="2"/><path d="M8 8h8M8 12h8"/><rect x="8" y="15" width="5" height="3" rx="1"/>',
	company_details: '<rect x="3" y="5" width="18" height="14" rx="2"/><circle cx="9" cy="11" r="2"/><path d="M14 10h4M14 14h4M6 16c.8-1.5 1.8-2 3-2s2.2.5 3 2"/>',
	article: '<rect x="4" y="3" width="16" height="18" rx="2"/><path d="M8 7h8M8 11h8M8 15h5"/>',
	code: '<path d="m8 8-4 4 4 4M16 8l4 4-4 4M13 6l-2 12"/>',
	block: '<rect x="4" y="4" width="16" height="16" rx="2"/>',
	icon: '<circle cx="12" cy="12" r="9"/><path d="m8 12.5 3 3 5-6"/>',
	gallery: '<rect x="3" y="3" width="8" height="8" rx="1"/><rect x="13" y="3" width="8" height="8" rx="1"/><rect x="3" y="13" width="8" height="8" rx="1"/><rect x="13" y="13" width="8" height="8" rx="1"/><path d="m13 19 3-3 5 4"/>',
	tabs: '<path d="M3 20V8h6V5h6v3h6v12z"/><path d="M9 8h6"/>',
	carousel: '<rect x="6" y="5" width="12" height="14" rx="2"/><path d="M3 8v8M21 8v8"/>',
	pricing_table: '<rect x="3" y="6" width="5" height="12" rx="1"/><rect x="9.5" y="3" width="5" height="18" rx="1"/><rect x="16" y="6" width="5" height="12" rx="1"/>',
	'before-after': '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M12 3v18M8 12h-2M18 12h-2"/>',
	hotspots: '<rect x="3" y="5" width="18" height="14" rx="2"/><circle cx="9" cy="10" r="2.5"/><circle cx="15.5" cy="14.5" r="2.5"/>',
	timeline: '<path d="M12 3v18"/><circle cx="12" cy="7" r="2"/><circle cx="12" cy="17" r="2"/><path d="M14 7h6M4 17h6"/>',
	map: '<path d="M3 6.5 9 4l6 2.5L21 4v13.5L15 20l-6-2.5L3 20z"/><path d="M9 4v13.5M15 6.5V20"/>',
	breadcrumbs: '<path d="M3 12h4M10 12h4M17 12h4"/><path d="m6 9 2 3-2 3M13 9l2 3-2 3"/>',
	counter: '<path d="M4 17V7l3 3M11 7h4l-4 10h4M18 7v10"/>',
	progress_bars: '<rect x="3" y="6" width="18" height="4" rx="2"/><rect x="3" y="14" width="18" height="4" rx="2"/><path d="M5 8h10M5 16h6"/>',
	star: '<path d="m12 3.5 2.6 5.4 5.9.8-4.3 4.1 1 5.8L12 16.8l-5.2 2.8 1-5.8-4.3-4.1 5.9-.8z"/>',
	countdown: '<circle cx="12" cy="13" r="8"/><path d="M12 9v4l2.5 2M9 2.5h6"/>',
	social_links: '<circle cx="6" cy="12" r="2.5"/><circle cx="18" cy="6" r="2.5"/><circle cx="18" cy="18" r="2.5"/><path d="m8.2 10.8 7.6-3.6M8.2 13.2l7.6 3.6"/>',
	search: '<circle cx="11" cy="11" r="6.5"/><path d="m20 20-4.4-4.4"/>',
	member: '<circle cx="12" cy="8" r="3.5"/><path d="M5 20a7 7 0 0 1 14 0"/>',
	'back-to-top': '<circle cx="12" cy="12" r="9"/><path d="M12 16V8M8.5 11.5 12 8l3.5 3.5"/>',
	basket: '<path d="M3 5h2l2.2 10.2a1.5 1.5 0 0 0 1.5 1.2h8.6a1.5 1.5 0 0 0 1.5-1.1L21 8H6.2"/><circle cx="9.5" cy="20" r="1.2"/><circle cx="17" cy="20" r="1.2"/>',
	globe: '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"/>',
	newsletter_signup: '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/><path d="M16 15h3"/>',
	placeholder: '<circle cx="12" cy="12" r="9"/><path d="M9.5 9.5a2.5 2.5 0 1 1 3.5 2.3c-.6.3-1 .9-1 1.6v.6M12 17h.01"/>',
	move: '<path d="M12 3v18M3 12h18M12 3l-3 3M12 3l3 3M12 21l-3-3M12 21l3-3M3 12l3-3M3 12l3 3M21 12l-3-3M21 12l-3 3"/>',
	up: '<path d="m6 15 6-6 6 6"/>', down: '<path d="m6 9 6 6 6-6"/>', parent: '<path d="M9 14 4 9l5-5M4 9h11a5 5 0 0 1 0 10h-2"/>',
	library: '<path d="M4 5h4v14H4zM10 5h4v14h-4z"/><path d="m16 6 3.5-1 3 13.5-3.5 1z"/>',
	copy: '<rect x="8" y="8" width="12" height="12" rx="2"/><path d="M16 8V6a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h2"/>',
	delete: '<path d="M4 7h16M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3"/>',
	undo: '<path d="M9 14 4 9l5-5M4 9h11a5 5 0 0 1 0 10h-3"/>', redo: '<path d="m15 14 5-5-5-5M20 9H9a5 5 0 0 0 0 10h3"/>',
	desktop: '<rect x="3" y="4" width="18" height="12" rx="2"/><path d="M8 20h8M12 16v4"/>', tablet: '<rect x="5" y="3" width="14" height="18" rx="2"/><path d="M11 18h2"/>',
	mobile: '<rect x="7" y="3" width="10" height="18" rx="2"/><path d="M11 18h2"/>', version: '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
	lock: '<rect x="5" y="11" width="14" height="9" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/>', unlocked: '<rect x="5" y="11" width="14" height="9" rx="2"/><path d="M8 11V8a4 4 0 0 1 7.5-2"/>',
	hidden: '<path d="M3 3l18 18M10.6 5.1A10 10 0 0 1 12 5c6.5 0 10 7 10 7a17 17 0 0 1-3.2 4M6.6 6.6C3.7 8.4 2 12 2 12s3.5 7 10 7a9.6 9.6 0 0 0 4.4-1"/>',
	plus: '<path d="M12 5v14M5 12h14"/>',
	structure: '<path d="M4 6h16M8 12h12M8 18h12"/>',
	more: '<circle cx="5" cy="12" r="1.2"/><circle cx="12" cy="12" r="1.2"/><circle cx="19" cy="12" r="1.2"/>',
	eye: '<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>', close: '<path d="M6 6l12 12M18 6 6 18"/>',
	share: '<path d="M10 14a4.5 4.5 0 0 0 6.4 0l3.2-3.2a4.5 4.5 0 0 0-6.4-6.4L12 5.6"/><path d="M14 10a4.5 4.5 0 0 0-6.4 0l-3.2 3.2a4.5 4.5 0 0 0 6.4 6.4l1.2-1.2"/>',
	comment: '<path d="M4 5h16v11H9l-5 4z"/><path d="M8 9h8M8 12h5"/>',
	edit: '<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/>',
	clipboard: '<rect x="6" y="5" width="12" height="16" rx="2"/><path d="M9 5a3 3 0 0 1 6 0"/><path d="M9 12h6M9 16h4"/>',
};

export const state = {
	build: D.build && Array.isArray(D.build.children) ? D.build : { v: 1, children: [] },
	selected: null, bp: 'base', elementState: '', leftTab: 'add', leftOpen: false, rightTab: 'content', undo: [], redo: [], lastKey: null, lastTime: 0,
	changed: !!D.changed, saving: false, hidden: {}, timer: null, version: D.version || '', saved: '', retries: 0, signed_in: false, conflict: false, cleaned: null, errors: {}, collapsed: {}, openItems: {}, editedClass: null, draggedNode: null, dragging: null, editingCanvas: false, placing: null, zoom: '', pasteTimer: null, multi: [], gesture: false, keepEditing: false, refreshAfterGesture: false,
};

/** The screen sizes a style value can come from, the current one first: a smaller size takes what a wider one sets. */
export const CHAIN = { base: ['base'], tablet: ['tablet', 'base'], mobile: ['mobile', 'tablet', 'base'] };
/** A style value that one screen size sets itself (undefined where it does not). */
export const ownValue = (p, bp, key) => (p.style && !Array.isArray(p.style) && p.style[bp] && p.style[bp][key]) || undefined;
/** The value a wider screen size passes on to the current one ('' when none does; the current size itself is not asked). */
export function inheritedValue(p, key) {
	for (const bp of (CHAIN[state.bp] || ['base']).slice(1)) { const v = ownValue(p, bp, key); if (v) { return v; } }
	return '';
}
/** What the current screen size shows of a style value: its own, or the one a wider size passes on. */
export const effectiveValue = (p, key) => ownValue(p, state.bp, key) || inheritedValue(p, key);

/** What core calls in the modules that import it; main.js fills it (panels.js: redrawBar, redrawLeft, redrawTree, hint; inspector.js: redrawRight; canvas.js: refreshPreview, markInPreview, showSpot, endPlacing, copy, pasteFromClipboard). */
export const ui = {};
/** The parts of the editor that several modules reach: the canvas iframe (preview, swapped by canvas.js), the elements main.js and panels.js create. */
export const dom = { preview: null, frame2: null, scale: null, left: null, right: null, rail: null, stateText: null };

/* Events between the modules of the editor (the canvas tells the overlay, handles, keys and compose modules what happened). */
const listeners = {};
export function on(name, fn) { (listeners[name] = listeners[name] || []).push(fn); }
export function emit(name, ...args) { (listeners[name] || []).forEach((fn) => fn(...args)); }

/* ---------- small helpers ---------- */

export function el(tag, attributes, ...children) {
	const e = document.createElement(tag);
	for (const [k, v] of Object.entries(attributes || {})) {
		if (v === null || v === undefined || v === false) { continue; }
		if (k.startsWith('on')) { e.addEventListener(k.slice(2), v); } else if (k === 'html') { e.innerHTML = v; } else { e.setAttribute(k, v === true ? '' : v); }
	}
	for (const d of children.flat()) { if (d !== null && d !== undefined && d !== false) { e.append(d instanceof Node ? d : String(d)); } }
	return e;
}
export const icon = (name) => el('span', { html: '<svg class="bd-icon" viewBox="0 0 24 24" aria-hidden="true">' + (ICONS[name] || ICONS.block) + '</svg>' }).firstChild;
export const clone = (o) => JSON.parse(JSON.stringify(o));
export const newId = () => Math.random().toString(16).slice(2, 9).padEnd(7, '0');
// the text of an element's HTML for labels: parsed in the inert document behind <template>, where nothing loads or runs
const inert = document.createElement('template').content.ownerDocument;
export const text = (html) => { const d = inert.createElement('div'); d.innerHTML = html || ''; return d.textContent.trim(); };

/**
 * A request to the admin. Never ends with an exception: returns the response JSON, or {ok: false, error, …} –
 * network (the connection failed), signed_in (a sign-in page came instead of JSON, or the form token expired).
 */
export function query(address, data) {
	const f = new FormData();
	f.append('_csrf', csrf);
	for (const [k, v] of Object.entries(data || {})) { f.append(k, v); }
	return fetch(address, { method: data ? 'POST' : 'GET', body: data ? f : undefined, credentials: 'same-origin' })
		.then((r) => r.text().then((body) => {
			try { const j = JSON.parse(body); if (j && typeof j === 'object') { j.status = r.status; return j; } } catch (e) { /* not JSON */ }
			if (r.status < 500 && /<form/i.test(body)) {
				return { ok: false, signed_in: true, status: r.status, error: T('Your session has expired. Sign in again in a new tab – your unsaved changes stay here and will save automatically.') };
			}
			return { ok: false, status: r.status, error: T('The server returned an unexpected response.') + ' (' + r.status + ')' };
		}))
		.catch(() => ({ ok: false, network: true, error: T('Could not connect to the server.') }));
}
/** After a new sign-in (another tab) the session has a new form token – the editor fetches it. */
export function refreshToken() {
	return fetch(D.urls.admin + '/token', { credentials: 'same-origin' }).then((r) => r.json()).then((j) => { if (j.csrf) { csrf = j.csrf; return true; } return false; }).catch(() => false);
}

export function confirmAction(message, button) {
	return new Promise((done) => {
		const d = el('dialog', { class: 'bd-dialog' },
			el('div', {}, el('p', {}, message)),
			el('footer', {}, el('button', { type: 'button', class: 'bd-btn', onclick: () => { d.close(); done(false); } }, T('Cancel')),
				el('button', { type: 'button', class: 'bd-btn bd-btn-main', onclick: () => { d.close(); done(true); } }, button || T('Continue'))));
		d.addEventListener('close', () => d.remove());
		document.body.append(d);
		d.showModal();
	});
}

/* ---------- tree ---------- */

export function find(id, children = state.build.children, parent = null) {
	for (let i = 0; i < children.length; i++) {
		if (children[i].id === id) { return { p: children[i], siblings: children, i, parent }; }
		if (children[i].children) { const n = find(id, children[i].children, children[i]); if (n) { return n; } }
	}
	return null;
}
/** The element's position in the tree as the validator writes it in error keys: children[0].children[2]. */
export function elementPath(id, children = state.build.children, path = 'children') {
	for (let i = 0; i < children.length; i++) {
		const c = path + '[' + i + ']';
		if (children[i].id === id) { return c; }
		if (children[i].children) { const n = elementPath(id, children[i].children, c + '.children'); if (n) { return n; } }
	}
	return null;
}
/** The element by the position from an error key (the deepest element present on the path). */
export function elementByPath(key) {
	let children = state.build.children;
	let found = null;
	for (const m of key.matchAll(/children\[(\d+)\]/g)) {
		const p = children && children[Number(m[1])];
		if (!p) { break; }
		found = p;
		children = p.children;
	}
	return found;
}
export function contains(p, id) { return (p.children || []).some((d) => d.id === id || contains(d, id)); }
// a copy gets new ids and no anchors – two identical anchors on a page would break #… links and popups
export function withNewIds(p) { const k = clone(p); (function walk(x) { x.id = newId(); delete x.anchor; (x.children || []).forEach(walk); })(k); return k; }

export function newElement(type) {
	const s = TYPY[type];
	const content = {};
	for (const [k, def] of Object.entries(s.properties || {})) { content[k] = clone(def.default ?? ''); }
	const style = s.default_style && Object.keys(s.default_style).length ? clone(s.default_style) : {};
	// a container can have default contents (Collection list: a card template with {{name}} and {{url}})
	return Object.assign({ id: newId(), type, tag: s.tags[0], content, style }, s.container ? { children: (s.default_children || []).map(withNewIds) } : {});
}

export function labelText(p) {
	if (p.label) { return p.label; }
	const s = TYPY[p.type] || { name: p.type };
	const excerpt = p.type === 'heading' ? text(p.content.text) : p.type === 'button' ? p.content.text : p.type === 'text' ? text(p.content.html) : p.type === 'testimonial' ? p.content.author : '';
	return excerpt ? s.name + ': ' + excerpt.slice(0, 40) : s.name;
}

/* ---------- changes, history, saving ---------- */

export function applyChange(fn, key) {
	const now = Date.now();
	// typing into one field is merged in the history (otherwise Undo would go back letter by letter)
	if (!key || key !== state.lastKey || now - state.lastTime > 1500) {
		state.undo.push(JSON.stringify(state.build));
		if (state.undo.length > 150) { state.undo.shift(); }
		state.redo = [];
	}
	state.lastKey = key || null;
	state.lastTime = now;
	fn();
	state.changed = true;
	scheduleSave();
	if (!key) { redraw(); } else { ui.redrawTree(); ui.redrawBar(); }
}
export function back() { if (!state.undo.length) { return; } state.redo.push(JSON.stringify(state.build)); state.build = JSON.parse(state.undo.pop()); state.lastKey = null; state.changed = true; scheduleSave(); redraw(); }
export function forward() { if (!state.redo.length) { return; } state.undo.push(JSON.stringify(state.build)); state.build = JSON.parse(state.redo.pop()); state.lastKey = null; state.changed = true; scheduleSave(); redraw(); }

function scheduleSave(after) {
	clearTimeout(state.timer);
	if (!state.retries) { setState(T('Unsaved…')); }
	state.timer = setTimeout(save, after || 600);
}
export const rejectInvalid = () => JSON.stringify(state.build) !== state.saved;

/*
 * Saving goes through a queue: two never run at once and every call of save() returns a promise that resolves when the
 * build state from the moment of the call is saved (true), or saving failed (false). Publish relies on this – it publishes
 * only what the server really has. A failed save retries by itself; a conflict with someone else's change is handled by a dialog.
 */
let queue = Promise.resolve(true), pending = null;
export function save() {
	clearTimeout(state.timer);
	state.timer = null;
	if (pending) { return pending; } // a save that will take the current state is already queued
	pending = queue.then(() => { pending = null; return saveNow(); });
	queue = pending;
	return pending;
}
function saveNow() {
	if (state.conflict) { return Promise.resolve(false); }
	const sent = JSON.stringify(state.build);
	if (sent === state.saved) {
		if (!state.retries) { setState(T('Draft saved')); } // a change that changed nothing (e.g. leaving a field)
		return Promise.resolve(true);
	}
	state.saving = true;
	setState(T('Saving…'));
	return query(D.urls.save, { build: sent, version: state.version }).then((j) => {
		state.saving = false;
		if (!j.ok) { return saveError(j); }
		state.retries = 0;
		state.signed_in = false;
		state.saved = sent;
		state.version = j.version || state.version;
		state.errors = j.errors || {};
		// the server cleaned the tree (dropped invalid values) – it is taken over only when nothing changed meanwhile and the user is not typing
		state.cleaned = JSON.stringify(j.build) !== sent ? { sent, build: j.build } : null;
		adoptSanitized();
		state.changed = j.changed;
		const count = Object.keys(state.errors).length;
		if (!rejectInvalid()) { setState(count ? T('Saved, but with warnings: ') + count : T('Draft saved'), count > 0); }
		ui.redrawBar();
		if (rejectInvalid()) { scheduleSave(300); } else { ui.refreshPreview(); }
		return true;
	});
}
function saveError(j) {
	if (j.conflict) { state.conflict = true; conflictDialog(j); return false; }
	if (j.status === 400 || j.status === 403 || j.status === 404) { setState(j.error || T('Saving failed.'), true); return false; }
	// network, expired sign-in, server error: the changes stay in the editor and the save is retried
	state.retries++;
	state.signed_in = !!j.signed_in;
	const after = Math.min(30, 3 * state.retries);
	setState(j.error + ' ' + T('Changes are not saved yet, retrying in %s s.').replace('%s', after), true, j.signed_in ? { url: D.urls.admin, text: T("Sign in") } : null);
	clearTimeout(state.timer);
	state.timer = setTimeout(() => (state.signed_in ? refreshToken() : Promise.resolve()).then(save), after * 1000);
	return false;
}
export function adoptSanitized() {
	const v = state.cleaned;
	if (!v || state.editingCanvas) { return; }
	const a = document.activeElement;
	if (a && root.contains(a) && a.matches('input, textarea, select, [contenteditable]')) { return; } // only after leaving the field (focusout)
	state.cleaned = null;
	if (JSON.stringify(state.build) !== v.sent) { return; }
	state.build = v.build;
	state.saved = JSON.stringify(v.build);
	redrawPanels();
}
export function conflictDialog(j) {
	setState(j.error, true);
	const d = el('dialog', { class: 'bd-dialog' },
		el('div', {}, el('h2', {}, T('Concurrent edit')), el('p', {}, j.error),
			el('p', {}, T('Load the newer version (your changes since the last save will be lost – they stay in Undo), or overwrite it with yours.'))),
		el('footer', {},
			el('button', { type: 'button', class: 'bd-btn', onclick: () => {
				d.close();
				state.undo.push(JSON.stringify(state.build));
				state.build = j.build; state.saved = JSON.stringify(j.build); state.version = j.version; state.conflict = false; state.selected = null; state.changed = true;
				setState(T('Newer version loaded')); redraw(); ui.refreshPreview();
			} }, T('Load newer')),
			el('button', { type: 'button', class: 'bd-btn bd-btn-main', onclick: () => {
				d.close();
				state.conflict = false; state.version = j.version; // the next save is based on the version on the server, so it overwrites it
				save();
			} }, T('Overwrite with mine'))));
	d.addEventListener('cancel', (e) => e.preventDefault());
	d.addEventListener('close', () => d.remove());
	document.body.append(d);
	d.showModal();
}

/** Cancels a scheduled save and waits until the one already running finishes (returns a promise). */
export function stopSaving() {
	clearTimeout(state.timer);
	state.timer = null;
	return queue;
}

/* ---------- selection and tree edits ---------- */

export function selection(id) {
	state.selected = id && find(id) ? id : null;
	state.multi = [];
	state.editedClass = null;
	state.lastKey = null;
	redrawPanels();
	ui.markInPreview(true);
}

/** Everything that is selected (the main element and the others), in the order of the page. */
export function selectedIds() {
	const ids = new Set([state.selected, ...state.multi].filter(Boolean));
	const ordered = [];
	(function walk(children) { children.forEach((p) => { if (ids.has(p.id)) { ordered.push(p.id); } if (p.children) { walk(p.children); } }); })(state.build.children);
	return ordered;
}
/** Shift / Ctrl-click: adds the element to the selection, or takes it out (the main element passes to the next one). */
export function toggleSelected(id) {
	if (!state.selected) { selection(id); return; }
	const ids = selectedIds();
	if (ids.includes(id)) {
		if (ids.length === 1) { selection(null); return; }
		const rest = ids.filter((x) => x !== id);
		state.selected = state.selected === id ? rest[0] : state.selected;
		state.multi = rest.filter((x) => x !== state.selected);
	} else {
		state.multi = [...state.multi, id];
	}
	state.editedClass = null;
	redrawPanels();
	ui.markInPreview(false);
}
/** A marquee or a program sets the whole selection at once (the first becomes the main element). */
export function selectMany(ids) {
	ids = ids.filter((id) => find(id));
	if (!ids.length) { selection(null); return; }
	state.selected = ids[0];
	state.multi = ids.slice(1);
	state.editedClass = null;
	state.lastKey = null;
	redrawPanels();
	ui.markInPreview(false);
	const doc = dom.preview && dom.preview.contentDocument;
	if (doc) { setState(T('%d elements selected.').replace('%d', String(ids.length))); }
}

export function insert(element) {
	const v = state.selected && find(state.selected);
	applyChange(() => {
		if (!v && element.type !== 'section' && element.type !== 'page_content') {
			// sections are at the top level: a lone element gets its own section (page content in an envelope has its own wrapper)
			const section = newElement('section');
			section.children.push(element);
			state.build.children.push(section);
		} else if (v && TYPY[v.p.type].container && element.type !== 'section') {
			v.p.children.push(element);
		} else if (v) {
			if (element.type === 'section' || element.type === 'page_content') {
				// a section belongs at the top level – after the section containing the selected element
				let upper = v; while (upper.parent) { upper = find(upper.parent.id); }
				upper.siblings.splice(upper.i + 1, 0, element);
			} else { v.siblings.splice(v.i + 1, 0, element); }
		} else { state.build.children.push(element); }
		state.selected = element.id;
	});
	state.rightTab = 'content';
	redrawPanels();
}

function remove(id) {
	const n = find(id);
	if (!n) { return; }
	applyChange(() => { n.siblings.splice(n.i, 1); state.selected = n.parent ? n.parent.id : (n.siblings[n.i] || n.siblings[n.i - 1] || {}).id || null; state.multi = []; });
}
function duplicate(id) {
	const n = find(id);
	if (!n) { return; }
	const copied = withNewIds(n.p);
	applyChange(() => { n.siblings.splice(n.i + 1, 0, copied); state.selected = copied.id; state.multi = []; });
}
/** The selected elements without those that sit inside another selected element (they travel with it). */
export function topSelected() {
	const ids = selectedIds();
	return ids.filter((id) => !ids.some((o) => o !== id && contains(find(o).p, id)));
}
export function removeSelected() {
	const ids = topSelected();
	if (ids.length < 2) { remove(state.selected); return; }
	applyChange(() => { ids.forEach((id) => { const n = find(id); if (n) { n.siblings.splice(n.i, 1); } }); state.selected = null; state.multi = []; });
}
export function duplicateSelected() {
	const ids = topSelected();
	if (ids.length < 2) { duplicate(state.selected); return; }
	const copies = [];
	applyChange(() => { ids.forEach((id) => { const n = find(id); const c = withNewIds(n.p); n.siblings.splice(n.i + 1, 0, c); copies.push(c.id); }); state.selected = copies[0]; state.multi = copies.slice(1); });
}
/** Wraps the selected elements (siblings in one container) in a new container, in the place of the first of them. */
export function group() {
	const ids = topSelected();
	const ns = ids.map((id) => find(id));
	if (!ns.length) { return; }
	if (ns.some((n) => !n.parent || n.parent.id !== ns[0].parent.id)) { setState(ns.some((n) => !n.parent) ? T('Sections cannot be grouped – put elements into a section instead.') : T('Only elements inside the same container can be grouped.'), true); return; }
	if (ns.some((n) => n.p.type === 'section')) { setState(T('Sections cannot be grouped – put elements into a section instead.'), true); return; }
	const wrapper = newElement('container');
	applyChange(() => {
		const set = new Set(ids);
		const siblings = ns[0].siblings;
		const index = siblings.findIndex((p) => set.has(p.id));
		wrapper.children = siblings.filter((p) => set.has(p.id));
		const rest = siblings.filter((p) => !set.has(p.id));
		rest.splice(index, 0, wrapper);
		siblings.splice(0, siblings.length, ...rest);
		state.selected = wrapper.id;
		state.multi = [];
	});
	setState(T('Grouped into a container.'));
}
/** A container's children take its place; the container disappears. */
export function ungroup() {
	const n = state.selected && find(state.selected);
	if (!n || !n.parent || !n.p.children || !n.p.children.length || n.p.type === 'section' || !TYPY[n.p.type].container) { setState(T('Select a container with elements in it to ungroup.'), true); return; }
	applyChange(() => { const kids = n.p.children; n.siblings.splice(n.i, 1, ...kids); state.selected = kids[0].id; state.multi = kids.slice(1).map((k) => k.id); });
	setState(T('Container removed, its elements stay.'));
}
/** Properties of the style for the current breakpoint (desktop, tablet, phone) – one history step. list = [[id, {property: value | ''}], …]. */
export function writeStyle(list, key) {
	applyChange(() => {
		list.forEach(([id, props]) => {
			const n = find(id);
			if (!n) { return; }
			const p = n.p;
			p.style = p.style && !Array.isArray(p.style) ? p.style : {};
			const st = state.bp;
			p.style[st] = p.style[st] || {};
			for (const [k, v] of Object.entries(props)) { if (v === '' || v === null || v === undefined) { delete p.style[st][k]; } else { p.style[st][k] = String(v); } }
			if (!Object.keys(p.style[st]).length) { delete p.style[st]; }
		});
	}, key);
	if (key) { ui.redrawRight(); }
}
export function offset(id, direction) {
	const n = find(id);
	if (!n || n.i + direction < 0 || n.i + direction >= n.siblings.length) { return; }
	applyChange(() => { n.siblings.splice(n.i, 1); n.siblings.splice(n.i + direction, 0, n.p); });
}
export function move(id, targetId, destination) {
	const n = find(id);
	const c = find(targetId);
	if (!n || !c || id === targetId || contains(n.p, targetId)) { return; }
	applyChange(() => {
		n.siblings.splice(n.i, 1);
		const target = find(targetId);
		if (destination === 'inside') { target.p.children.push(n.p); } else { target.siblings.splice(target.i + (destination === 'after' ? 1 : 0), 0, n.p); }
	});
}

/**
 * Places elements by a drop spot ({target, where}) – a move, or copies with Alt. Elements between sections get a section of their own.
 * One history step for all of them.
 */
export function placeElements(ids, place, copy) {
	ids = ids.filter((id) => find(id));
	const target = !place.root && find(place.target);
	if (!target) { return; }
	if (!copy) { ids = ids.filter((id) => id !== place.target && !contains(find(id).p, place.target)); }
	ids = ids.filter((id) => !ids.some((o) => o !== id && contains(find(o).p, id)));
	if (!ids.length) { return; }
	const wrap = place.where !== 'inside' && !target.parent;
	const isSection = (p) => p.type === 'section' || p.type === 'page_content';
	const placed = [];
	applyChange(() => {
		const items = ids.map((id) => { const n = find(id); if (copy) { return withNewIds(n.p); } n.siblings.splice(n.i, 1); return n.p; });
		placed.push(...items.map((p) => p.id));
		let out = items;
		if (wrap && items.some((p) => !isSection(p))) { out = [...items.filter(isSection), Object.assign(newElement('section'), { children: items.filter((p) => !isSection(p)) })]; }
		const c = find(place.target);
		if (place.where === 'inside') { c.p.children.push(...out); } else { c.siblings.splice(c.i + (place.where === 'after' ? 1 : 0), 0, ...out); }
	});
	state.selected = placed[0];
	state.multi = placed.slice(1);
	redrawPanels();
	ui.markInPreview(false);
}

/** Keyboard move: the selection one place earlier (-1) or later (+1) among its siblings; at the end of a container it hops into the next container. */
export function nudge(direction) {
	const ids = topSelected();
	const ns = ids.map((id) => find(id));
	if (!ns.length || ns.some((n) => n.siblings !== ns[0].siblings || n.p.locked)) { return false; }
	const set = new Set(ids);
	const siblings = ns[0].siblings;
	const start = siblings.findIndex((p) => set.has(p.id));
	const rest = siblings.filter((p) => !set.has(p.id));
	const to = start + direction;
	if (to >= 0 && to <= rest.length) {
		const moved = siblings.filter((p) => set.has(p.id));
		applyChange(() => { rest.splice(to, 0, ...moved); siblings.splice(0, siblings.length, ...rest); });
		return true;
	}
	// the end of the container: the single element hops into the neighbouring container (a button into the next column)
	const parent = ns[0].parent && find(ns[0].parent.id);
	const neighbour = parent && parent.siblings[parent.i + direction];
	if (ns.length === 1 && neighbour && TYPY[neighbour.type] && TYPY[neighbour.type].container && ns[0].p.type !== 'section' && neighbour.type !== 'section' && !neighbour.locked) {
		applyChange(() => { ns[0].siblings.splice(ns[0].i, 1); if (direction > 0) { neighbour.children.unshift(ns[0].p); } else { neighbour.children.push(ns[0].p); } });
		return true;
	}
	setState(T('Nowhere further to move.'));
	return false;
}

/* ---------- keys ---------- */

export function keys(e) {
	const v = e.target;
	const typing = v.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(v.tagName);
	const mod = e.ctrlKey || e.metaKey;
	if (mod && e.key.toLowerCase() === 's') { e.preventDefault(); save(); return; }
	if (typing || document.querySelector('dialog[open], .bd-more:popover-open')) { return; } // in an open dialog or menu the keys (Esc) belong to them
	// selected text is copied as text, not as an element
	const marked = String(window.getSelection() || '') || (dom.preview && dom.preview.contentWindow ? String(dom.preview.contentWindow.getSelection() || '') : '');
	if (mod && e.key.toLowerCase() === 'c' && marked) { return; }
	if (mod && e.key.toLowerCase() === 'z') { e.preventDefault(); if (e.shiftKey) { forward(); } else { back(); } }
	else if (mod && e.key.toLowerCase() === 'y') { e.preventDefault(); forward(); }
	else if (mod && e.key.toLowerCase() === 'd' && state.selected) { e.preventDefault(); duplicateSelected(); }
	else if (mod && e.key.toLowerCase() === 'c' && state.selected) { ui.copy(); }
	else if (mod && e.key.toLowerCase() === 'v') { clearTimeout(state.pasteTimer); state.pasteTimer = setTimeout(ui.pasteFromClipboard, 250); } // the paste event (onPaste) comes first when the system clipboard has text
	else if ((e.key === 'Delete' || e.key === 'Backspace') && state.selected) { e.preventDefault(); removeSelected(); }
	else if (e.key === '?' && !mod) { e.preventDefault(); ui.hint(); }
	else if (e.key === 'Escape' && state.placing) { const doc = dom.preview && dom.preview.contentDocument; if (doc) { ui.showSpot(doc, null); } ui.endPlacing(); }
	else if (e.key === 'Escape' && state.selected) { const n = find(state.selected); selection(n && n.parent ? n.parent.id : null); }
	else if (e.key === 'Escape' && state.leftOpen) { state.leftOpen = false; ui.redrawLeft(); }
}

/* ---------- shared by the panels: the status message, redrawing, names, components ---------- */

/** The message in the top bar (dom.stateText, created with the bar in panels.js). */
export function setState(message, error, link) {
	if (!dom.stateText) { return; }
	dom.stateText.replaceChildren(message, link ? el('a', { href: link.url, target: '_blank', rel: 'noopener' }, ' ' + link.text) : '');
	dom.stateText.classList.toggle('error', !!error);
}

export function redrawPanels() { ui.redrawTree(); ui.redrawRight(); }
export function redraw() { ui.redrawBar(); ui.redrawLeft(); ui.redrawRight(); }

/** The collection whose items the element receives: the nearest parent "Collection list", otherwise the collection of the detail template. */
export function elementCollection(id) {
	let n = find(id);
	while (n) {
		if (n.p.type === 'collection_list' && n.p.id !== id) { return (D.collections || []).find((k) => k.slug === n.p.content.collection) || null; }
		n = n.parent ? find(n.parent.id) : null;
	}
	return D.detail_collection || null;
}

/**
 * A dialog with one named field instead of window.prompt() (that cannot be styled or translated and browsers suppress it).
 * Enter saves, Esc cancels; returns the entered text, or null.
 */
export function askName(heading, fieldLabel, value, hint) {
	return new Promise((done) => {
		const headingId = 'bd-dialog-' + newId();
		const field = el('input', { type: 'text', maxlength: 100, value: value, required: true });
		let result = null;
		const d = el('dialog', { class: 'bd-dialog', 'aria-labelledby': headingId },
			el('form', { method: 'dialog', onsubmit: (e) => {
				if (!field.value.trim()) { e.preventDefault(); field.focus(); return; }
				result = field.value.trim();
			} },
			el('div', {}, el('h2', { id: headingId }, heading),
				el('label', { class: 'bd-field' }, el('span', {}, fieldLabel), field),
				hint ? el('p', { class: 'bd-empty', style: 'text-align:left;padding:0' }, hint) : null),
			el('footer', {}, el('button', { type: 'button', class: 'bd-btn', onclick: () => d.close() }, T('Cancel')),
				el('button', { type: 'submit', class: 'bd-btn bd-btn-main' }, T("Save")))));
		d.addEventListener('close', () => { d.remove(); done(result); });
		document.body.append(d);
		d.showModal();
		field.select();
	});
}

/** The site's components changed (saved, pasted from another site): the choice in the Component element follows. */
export function setComponents(list) {
	if (!Array.isArray(list)) { return; }
	D.components = list;
	const options = { '': '—' };
	list.forEach((k) => { options[String(k.id)] = k.name; });
	if (TYPY.component) { TYPY.component.properties.component.options = options; }
}

/**
 * A component use leads into the component's builder (administrator): "Edit here" keeps this page around it on the canvas (only a
 * page's builder has one), "Open" shows the component alone. Both lead back here; the draft of this page is saved first.
 */
export function openComponent(p, here) {
	const component = (D.components || []).find((k) => String(k.id) === String(p.content.component));
	if (!component || !D.urls.component) { return; }
	const back = window.location.pathname + window.location.search; // the component builder only takes an address of this administration
	const url = D.urls.admin + '/components/builder/' + component.public_id + '?' + (here && D.page.id ? 'page=' + D.page.id + '&use=' + p.id + '&' : '') + 'back=' + encodeURIComponent(back);
	save().then((ok) => { if (ok) { window.location.href = url; } });
}
