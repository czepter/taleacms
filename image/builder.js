/* Talea – page builder. No libraries and no build step.
 *
 * The state is a tree of elements (the same shape PHP cleans and renders: Builder\Build). Every change goes into the history (undo/redo),
 * shortly afterwards it is saved as a draft (action stavba_uloz) and the canvas – the real site page in an iframe – is re-rendered.
 * There is deliberately no second rendering in JavaScript: what is on the canvas is exactly what the visitor will see.
 */
(function () {
	'use strict';

	const T = window.T || ((s) => s);
	const root = document.getElementById('builder');
	const D = JSON.parse(document.getElementById('builder-data').textContent);
	let csrf = (document.querySelector('input[name="_csrf"]') || {}).value || '';
	const TYPY = Object.fromEntries(D.schema.elements.map((p) => [p.type, p]));
	const STYLE = D.schema.style;
	const BP = { base: T('Desktop'), tablet: T('Tablet'), mobile: T('Mobile') };
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
		more: '<circle cx="5" cy="12" r="1.2"/><circle cx="12" cy="12" r="1.2"/><circle cx="19" cy="12" r="1.2"/>',
		eye: '<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>', close: '<path d="M6 6l12 12M18 6 6 18"/>',
		share: '<path d="M10 14a4.5 4.5 0 0 0 6.4 0l3.2-3.2a4.5 4.5 0 0 0-6.4-6.4L12 5.6"/><path d="M14 10a4.5 4.5 0 0 0-6.4 0l-3.2 3.2a4.5 4.5 0 0 0 6.4 6.4l1.2-1.2"/>',
		comment: '<path d="M4 5h16v11H9l-5 4z"/><path d="M8 9h8M8 12h5"/>',
		clipboard: '<rect x="6" y="5" width="12" height="16" rx="2"/><path d="M9 5a3 3 0 0 1 6 0"/><path d="M9 12h6M9 16h4"/>',
	};

	const state = {
		build: D.build && Array.isArray(D.build.children) ? D.build : { v: 1, children: [] },
		selected: null, bp: 'base', elementState: '', leftTab: 'add', rightTab: 'content', undo: [], redo: [], lastKey: null, lastTime: 0,
		changed: !!D.changed, saving: false, hidden: {}, timer: null, version: D.version || '', saved: '', retries: 0, signed_in: false, conflict: false, cleaned: null, errors: {}, collapsed: {}, editedClass: null, draggedNode: null, dragging: null, editingCanvas: false, placing: null, zoom: '', pasteTimer: null, multi: [], gesture: false, keepEditing: false, refreshAfterGesture: false,
	};

	/* The builder's small explicit interface for the compose scripts (builder-overlay.js, builder-handles.js, builder-keys.js): events and the API object at the end of the file. */
	const listeners = {};
	function on(name, fn) { (listeners[name] = listeners[name] || []).push(fn); }
	function emit(name, ...args) { (listeners[name] || []).forEach((fn) => fn(...args)); }

	/* ---------- small helpers ---------- */

	function el(tag, attributes, ...children) {
		const e = document.createElement(tag);
		for (const [k, v] of Object.entries(attributes || {})) {
			if (v === null || v === undefined || v === false) { continue; }
			if (k.startsWith('on')) { e.addEventListener(k.slice(2), v); } else if (k === 'html') { e.innerHTML = v; } else { e.setAttribute(k, v === true ? '' : v); }
		}
		for (const d of children.flat()) { if (d !== null && d !== undefined && d !== false) { e.append(d instanceof Node ? d : String(d)); } }
		return e;
	}
	const icon = (name) => el('span', { html: '<svg class="bd-icon" viewBox="0 0 24 24" aria-hidden="true">' + (ICONS[name] || ICONS.block) + '</svg>' }).firstChild;
	const clone = (o) => JSON.parse(JSON.stringify(o));
	const newId = () => Math.random().toString(16).slice(2, 9).padEnd(7, '0');
	const text = (html) => { const d = document.createElement('div'); d.innerHTML = html || ''; return d.textContent.trim(); };

	/**
	 * A request to the admin. Never ends with an exception: returns the response JSON, or {ok: false, error, …} –
	 * network (the connection failed), signed_in (a sign-in page came instead of JSON, or the form token expired).
	 */
	function query(address, data) {
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
	function refreshToken() {
		return fetch(D.urls.admin + '?action=token', { credentials: 'same-origin' }).then((r) => r.json()).then((j) => { if (j.csrf) { csrf = j.csrf; return true; } return false; }).catch(() => false);
	}

	function confirmAction(message, button) {
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

	function find(id, children = state.build.children, parent = null) {
		for (let i = 0; i < children.length; i++) {
			if (children[i].id === id) { return { p: children[i], siblings: children, i, parent }; }
			if (children[i].children) { const n = find(id, children[i].children, children[i]); if (n) { return n; } }
		}
		return null;
	}
	/** The element's position in the tree as the validator writes it in error keys: children[0].children[2]. */
	function elementPath(id, children = state.build.children, path = 'children') {
		for (let i = 0; i < children.length; i++) {
			const c = path + '[' + i + ']';
			if (children[i].id === id) { return c; }
			if (children[i].children) { const n = elementPath(id, children[i].children, c + '.children'); if (n) { return n; } }
		}
		return null;
	}
	/** The element by the position from an error key (the deepest element present on the path). */
	function elementByPath(key) {
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
	function contains(p, id) { return (p.children || []).some((d) => d.id === id || contains(d, id)); }
	// a copy gets new ids and no anchors – two identical anchors on a page would break #… links and popups
	function withNewIds(p) { const k = clone(p); (function walk(x) { x.id = newId(); delete x.anchor; (x.children || []).forEach(walk); })(k); return k; }

	function newElement(type) {
		const s = TYPY[type];
		const content = {};
		for (const [k, def] of Object.entries(s.properties || {})) { content[k] = clone(def.default ?? ''); }
		const style = s.default_style && Object.keys(s.default_style).length ? clone(s.default_style) : {};
		// a container can have default contents (Collection list: a card template with {{name}} and {{url}})
		return Object.assign({ id: newId(), type, tag: s.tags[0], content, style }, s.container ? { children: (s.default_children || []).map(withNewIds) } : {});
	}

	function labelText(p) {
		if (p.label) { return p.label; }
		const s = TYPY[p.type] || { name: p.type };
		const excerpt = p.type === 'heading' ? text(p.content.text) : p.type === 'button' ? p.content.text : p.type === 'text' ? text(p.content.html) : p.type === 'testimonial' ? p.content.author : '';
		return excerpt ? s.name + ': ' + excerpt.slice(0, 40) : s.name;
	}

	/* ---------- changes, history, saving ---------- */

	function applyChange(fn, key) {
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
		if (!key) { redraw(); } else { redrawTree(); redrawBar(); }
	}
	function back() { if (!state.undo.length) { return; } state.redo.push(JSON.stringify(state.build)); state.build = JSON.parse(state.undo.pop()); state.lastKey = null; state.changed = true; scheduleSave(); redraw(); }
	function forward() { if (!state.redo.length) { return; } state.undo.push(JSON.stringify(state.build)); state.build = JSON.parse(state.redo.pop()); state.lastKey = null; state.changed = true; scheduleSave(); redraw(); }

	function scheduleSave(after) {
		clearTimeout(state.timer);
		if (!state.retries) { setState(T('Unsaved…')); }
		state.timer = setTimeout(save, after || 600);
	}
	const rejectInvalid = () => JSON.stringify(state.build) !== state.saved;

	/*
	 * Saving goes through a queue: two never run at once and every call of save() returns a promise that resolves when the
	 * build state from the moment of the call is saved (true), or saving failed (false). Publish relies on this – it publishes
	 * only what the server really has. A failed save retries by itself; a conflict with someone else's change is handled by a dialog.
	 */
	let queue = Promise.resolve(true), pending = null;
	function save() {
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
			redrawBar();
			if (rejectInvalid()) { scheduleSave(300); } else { refreshPreview(); }
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
	function adoptSanitized() {
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
	function conflictDialog(j) {
		setState(j.error, true);
		const d = el('dialog', { class: 'bd-dialog' },
			el('div', {}, el('h2', {}, T('Concurrent edit')), el('p', {}, j.error),
				el('p', {}, T('Load the newer version (your changes since the last save will be lost – they stay in Undo), or overwrite it with yours.'))),
			el('footer', {},
				el('button', { type: 'button', class: 'bd-btn', onclick: () => {
					d.close();
					state.undo.push(JSON.stringify(state.build));
					state.build = j.build; state.saved = JSON.stringify(j.build); state.version = j.version; state.conflict = false; state.selected = null; state.changed = true;
					setState(T('Newer version loaded')); redraw(); refreshPreview();
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

	/* ---------- canvas: the real page in an iframe, swapped without flicker ---------- */

	let frame2, preview, previewPending = false, scale;
	const WIDTHS = { base: 1280, tablet: 820, mobile: 390 };

	/** The iframe has the device width (desktop 1280 px) and is scaled down to fit – so the site's real breakpoints apply on the canvas. */
	function previewSize(frame) {
		if (!frame || !frame2) { return; }
		const w = frame2.clientWidth;
		const h = frame2.clientHeight;
		// preview: a wide monitor (1920 px) only for desktop; zoom is fixed, otherwise the canvas fits the window
		const width = state.bp === 'base' ? (state.zoom === '1920' ? 1920 : Math.max(w, WIDTHS.base)) : WIDTHS[state.bp];
		const m = /^\d+$/.test(state.zoom) && state.zoom !== '1920' ? Number(state.zoom) / 100 : Math.min(1, w / width);
		frame2.classList.toggle('bd-frame-shift', m * width > w + 1);
		frame.style.width = width + 'px';
		frame.style.height = Math.round(h / m) + 'px';
		frame.style.left = Math.max(0, Math.round((w - width * m) / 2)) + 'px';
		frame.style.transform = m < 1 ? 'scale(' + m + ')' : '';
		if (scale) { scale.textContent = width + ' px' + (m < 1 ? ' · ' + Math.round(m * 100) + ' %' : ''); }
		frame.dataset.scale = String(m);
		emit('resize');
	}
	function refreshPreview() {
		if (state.editingCanvas) { return; }
		if (state.gesture) { state.refreshAfterGesture = true; return; } // the canvas is not swapped under a gesture in progress
		if (previewPending) { previewPending = 'again'; return; }
		previewPending = true;
		const fresh = el('iframe', { class: 'bd-loading', title: T('Page preview'), src: D.preview + '&t=' + Date.now() });
		previewSize(fresh);
		fresh.addEventListener('load', () => {
			// a gesture started while this page was loading: the canvas is not swapped under it (the handle it holds would disappear)
			if (state.gesture) { fresh.remove(); previewPending = false; state.refreshAfterGesture = true; return; }
			const offset = preview && preview.contentWindow ? preview.contentWindow.scrollY : 0;
			try { fresh.contentWindow.scrollTo(0, offset); } catch (e) { /* nothing */ }
			preparePreview(fresh);
			if (preview) { preview.remove(); }
			fresh.classList.remove('bd-loading');
			preview = fresh;
			markInPreview(false);
			const retry = previewPending === 'again';
			previewPending = false;
			if (retry) { refreshPreview(); }
		});
		frame2.append(fresh);
	}

	function preparePreview(frame) {
		const doc = frame.contentDocument;
		if (!doc) { return; }
		doc.head.append(Object.assign(doc.createElement('style'), { textContent:
			'[data-tl-id]{cursor:default} .tl-bd-hover{outline:1px dashed #ff4f2e!important;outline-offset:-1px} .tl-bd-selected{outline:2px solid #ff4f2e!important;outline-offset:-2px}'
			+ '[contenteditable]{outline:2px solid #f79009!important;outline-offset:2px;cursor:text} .tl-edit-here,.cookies-bar,.cookies-reopen{display:none!important}' }));
		doc.addEventListener('click', (e) => {
			if (e.target.closest('[contenteditable]') || e.target.closest('#tl-bd-overlay')) { return; }
			e.preventDefault();
			e.stopPropagation(); // page scripts do not run on the canvas (player, popups, sharing) – a click only selects
			if (state.placing) {
				// move by tapping (touch and mouse): the element lands before, after or inside the tapped element depending on where it was tapped
				state.dragging = state.placing;
				const place = canvasSpot(doc, e);
				state.dragging = null;
				showSpot(doc, null);
				if (place) { const what = state.placing; endPlacing(); dropAt(what, place); }
				return;
			}
			// a locked element (Structure → lock) cannot be selected on the canvas: the nearest unlocked ancestor gets the selection
			let t = e.target.closest('[data-tl-id]');
			while (t && t.hasAttribute('data-tl-lock')) { t = t.parentElement && t.parentElement.closest('[data-tl-id]'); }
			// Shift / Ctrl / Cmd-click adds the element to the selection or takes it out
			if (t && (e.shiftKey || e.ctrlKey || e.metaKey) && find(t.getAttribute('data-tl-id'))) { toggleSelected(t.getAttribute('data-tl-id')); return; }
			selection(t ? t.getAttribute('data-tl-id') : null);
		}, true);
		hiddenOnCanvas(doc);
		doc.addEventListener('pointermove', (e) => {
			if (!state.placing) { return; }
			state.dragging = state.placing;
			showSpot(doc, canvasSpot(doc, e));
			state.dragging = null;
		});
		doc.addEventListener('mouseover', (e) => {
			doc.querySelectorAll('.tl-bd-hover').forEach((x) => x.classList.remove('tl-bd-hover'));
			const t = e.target.closest('[data-tl-id]');
			if (t) { t.classList.add('tl-bd-hover'); }
		});
		doc.addEventListener('dblclick', (e) => { const t = e.target.closest('[data-tl-id]'); if (t && !t.hasAttribute('data-tl-lock')) { editOnCanvas(t); } });
		doc.addEventListener('keydown', keys);
		doc.addEventListener('paste', onPaste);
		emit('preview', doc, frame);
		doc.addEventListener('dragover', (e) => {
			if (!state.dragging) { return; }
			const place = canvasSpot(doc, e);
			showSpot(doc, place);
			if (place) { e.preventDefault(); e.dataTransfer.dropEffect = state.dragging.move ? 'move' : 'copy'; }
		});
		doc.addEventListener('dragleave', (e) => { if (!e.relatedTarget) { showSpot(doc, null); } });
		doc.addEventListener('drop', (e) => {
			const place = state.dragging && canvasSpot(doc, e);
			showSpot(doc, null);
			if (!place) { return; }
			e.preventDefault();
			dropAt(state.dragging, place);
			state.dragging = null;
		});
	}

	/** Elements hidden only in the editor (the eye in Structure) – they stay on the site; the state is not saved. */
	function hiddenOnCanvas(doc) {
		doc = doc || (preview && preview.contentDocument);
		if (!doc) { return; }
		let st = doc.getElementById('tl-bd-hidden');
		if (!st) { st = Object.assign(doc.createElement('style'), { id: 'tl-bd-hidden' }); doc.head.append(st); }
		st.textContent = Object.keys(state.hidden).filter((id) => state.hidden[id]).map((id) => '[data-tl-id="' + id + '"]{display:none!important}').join('');
	}

	/* ---------- dragging on the canvas: a new element, a ready-made section or moving the selected element ---------- */

	function startDrag(e, what) {
		hideSectionPreview();
		state.dragging = what;
		e.dataTransfer.effectAllowed = what.move ? 'move' : 'copy';
		e.dataTransfer.setData('text/plain', 'talea');
	}

	/** Move by tapping – a replacement for dragging on touch devices: select an element, tap "Move" and then the place. */
	function startPlacing(id) {
		if (!id) { return; }
		state.placing = { move: id, more: id === state.selected ? state.multi.slice() : [] };
		document.body.classList.add('bd-placing');
		emit('placing');
		setState(T('Tap the place on the page to move the element to (top or bottom part of an element = before or after, middle of a container = inside). Esc cancels.'));
	}

	function endPlacing() {
		state.placing = null;
		document.body.classList.remove('bd-placing');
		emit('placing');
		setState('');
	}

	function endDrag() {
		state.dragging = null;
		const doc = preview && preview.contentDocument;
		if (doc) { showSpot(doc, null); }
	}

	/**
	 * Where the element would land: {target: id, where: 'before' | 'after' | 'inside'}, or {root: true} on an empty page. Sections only between sections;
	 * inside a container when the pointer is in its middle part (or it is empty); never a move into itself.
	 */
	function canvasSpot(doc, e) {
		const type = state.dragging.newType || (state.dragging.section ? 'section' : (find(state.dragging.move) || { p: {} }).p.type);
		let node = e.target.closest ? e.target.closest('[data-tl-id]') : null;
		while (node && !find(node.getAttribute('data-tl-id'))) { node = node.parentElement && node.parentElement.closest('[data-tl-id]'); }
		if (!node) { return state.build.children.length ? null : { root: true }; }
		let n = find(node.getAttribute('data-tl-id'));
		if (type === 'section' || type === 'page_content') {
			while (n.parent) { n = find(n.parent.id); }
			node = doc.querySelector('[data-tl-id="' + n.p.id + '"]') || node;
		}
		const moving = state.dragging.move && find(state.dragging.move);
		if (moving && (moving.p.id === n.p.id || contains(moving.p, n.p.id))) { return null; }
		const r = node.getBoundingClientRect();
		const y = (e.clientY - r.top) / Math.max(1, r.height);
		const inside = type !== 'section' && type !== 'page_content' && TYPY[n.p.type] && TYPY[n.p.type].container && (!n.p.children.length || (y > 0.25 && y < 0.75));
		return { target: n.p.id, where: inside ? 'inside' : (y < 0.5 ? 'before' : 'after'), node };
	}

	/** A blue line (before / after) or a frame (inside) on the canvas. */
	function showSpot(doc, place) {
		let htmlTag = doc.getElementById('tl-bd-place');
		if (!place || !place.node) { if (htmlTag) { htmlTag.hidden = true; } return; }
		if (!htmlTag) {
			htmlTag = Object.assign(doc.createElement('div'), { id: 'tl-bd-place' });
			htmlTag.style.cssText = 'position:absolute;z-index:2147483646;pointer-events:none;border-radius:3px';
			doc.body.append(htmlTag);
		}
		const r = place.node.getBoundingClientRect();
		const x = r.left + doc.defaultView.scrollX;
		const y = r.top + doc.defaultView.scrollY;
		htmlTag.hidden = false;
		Object.assign(htmlTag.style, place.where === 'inside'
			? { left: x + 'px', top: y + 'px', width: r.width + 'px', height: r.height + 'px', background: 'rgb(255 79 46 / 0.08)', outline: '2px dashed #ff4f2e' }
			: { left: x + 'px', top: (place.where === 'before' ? y - 2 : y + r.height - 2) + 'px', width: r.width + 'px', height: '4px', background: '#ff4f2e', outline: 'none' });
	}

	function dropAt(what, place) {
		if (what.move) {
			placeElements([what.move, ...(what.more || [])], place, !!what.copy);
			redrawPanels();
			return;
		}
		const placeElement = (element) => {
			// a lone element between sections gets its own section (as when inserted by tapping)
			const target = place.root ? null : find(place.target);
			const sectionGap = place.root || (place.where !== 'inside' && !target.parent);
			const inserting = sectionGap && element.type !== 'section' && element.type !== 'page_content' ? Object.assign(newElement('section'), { children: [element] }) : element;
			applyChange(() => {
				if (place.root) { state.build.children.push(inserting); return; }
				const c = find(place.target);
				if (place.where === 'inside') { c.p.children.push(inserting); } else { c.siblings.splice(c.i + (place.where === 'after' ? 1 : 0), 0, inserting); }
			});
			selection(element.id);
			redrawPanels();
		};
		if (what.newType) { placeElement(newElement(what.newType)); return; }
		if (what.custom) { placeElement(withNewIds(what.custom)); return; }
		query(D.urls.section + '&key=' + encodeURIComponent(what.section), { ok: 1 }).then((j) => {
			if (!j.ok) { setState(j.error, true); return; }
			D.classes = j.classes;
			placeElement(j.element);
		});
	}

	function markInPreview(shift) {
		const doc = preview && preview.contentDocument;
		if (!doc) { return; }
		doc.querySelectorAll('.tl-bd-selected').forEach((x) => x.classList.remove('tl-bd-selected'));
		const t = state.selected && doc.querySelector('[data-tl-id="' + state.selected + '"]');
		state.multi.forEach((id) => { const x = doc.querySelector('[data-tl-id="' + id + '"]'); if (x) { x.classList.add('tl-bd-selected'); } });
		let outline = doc.getElementById('tl-bd-outline');
		if (t) {
			t.classList.add('tl-bd-selected');
			if (shift) { t.scrollIntoView({ block: 'nearest', behavior: 'smooth' }); }
			// a component without its own wrapper has display: contents – it has no box, the outline is drawn around its content
			if (doc.defaultView.getComputedStyle(t).display === 'contents') {
				const scope = doc.createRange();
				scope.selectNodeContents(t);
				const r = scope.getBoundingClientRect();
				if (!outline) {
					outline = Object.assign(doc.createElement('div'), { id: 'tl-bd-outline' });
					outline.style.cssText = 'position:absolute;z-index:2147483646;pointer-events:none;outline:2px solid #ff4f2e;outline-offset:2px';
					doc.body.append(outline);
				}
				Object.assign(outline.style, { left: r.left + doc.defaultView.scrollX + 'px', top: r.top + doc.defaultView.scrollY + 'px', width: r.width + 'px', height: r.height + 'px' });
				outline.hidden = false;
			} else if (outline) {
				outline.hidden = true;
			}
		} else if (outline) {
			outline.hidden = true;
		}
		emit('mark', shift);
	}

	/** Double-click on a heading, text, button or reference: typing right on the canvas. */
	function editOnCanvas(node) {
		const n = find(node.getAttribute('data-tl-id'));
		if (!n || !['heading', 'text', 'button', 'testimonial'].includes(n.p.type)) { return; }
		// in a collection the canvas shows the item's substituted value – editing would overwrite the {{placeholder}}; the text is changed in the Content panel
		if (elementCollection(n.p.id) && JSON.stringify(n.p.content).includes('{{')) { selection(n.p.id); setState(T('Edit text with collection {{tags}} in the Content panel.')); return; }
		const target = n.p.type === 'testimonial' ? node.querySelector('p') : node;
		if (!target) { return; }
		state.editingCanvas = true;
		target.contentEditable = n.p.type === 'button' ? 'plaintext-only' : 'true';
		target.focus();
		emit('edit', true);
		const done = () => {
			if (state.keepEditing) { return; } // a dialog of the compose toolbar (link) holds the editing – it ends with that dialog
			target.removeEventListener('blur', done);
			target.removeAttribute('contenteditable');
			state.editingCanvas = false;
			emit('edit', false);
			const field = { heading: 'text', text: 'html', button: 'text', testimonial: 'text' }[n.p.type];
			const value = n.p.type === 'button' ? target.textContent.trim() : target.innerHTML.trim();
			if (value !== n.p.content[field]) { applyChange(() => { n.p.content[field] = value; }); } else { refreshPreview(); }
		};
		target.addEventListener('blur', done);
		target.addEventListener('keydown', (e) => { if (e.key === 'Escape' || (e.key === 'Enter' && n.p.type !== 'text' && !e.shiftKey)) { e.preventDefault(); target.blur(); } });
	}

	/* ---------- selection and tree edits ---------- */

	function selection(id) {
		state.selected = id && find(id) ? id : null;
		state.multi = [];
		state.editedClass = null;
		state.lastKey = null;
		redrawPanels();
		markInPreview(true);
	}

	/** Everything that is selected (the main element and the others), in the order of the page. */
	function selectedIds() {
		const ids = new Set([state.selected, ...state.multi].filter(Boolean));
		const ordered = [];
		(function walk(children) { children.forEach((p) => { if (ids.has(p.id)) { ordered.push(p.id); } if (p.children) { walk(p.children); } }); })(state.build.children);
		return ordered;
	}
	/** Shift / Ctrl-click: adds the element to the selection, or takes it out (the main element passes to the next one). */
	function toggleSelected(id) {
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
		markInPreview(false);
	}
	/** A marquee or a program sets the whole selection at once (the first becomes the main element). */
	function selectMany(ids) {
		ids = ids.filter((id) => find(id));
		if (!ids.length) { selection(null); return; }
		state.selected = ids[0];
		state.multi = ids.slice(1);
		state.editedClass = null;
		state.lastKey = null;
		redrawPanels();
		markInPreview(false);
		const doc = preview && preview.contentDocument;
		if (doc) { setState(T('%d elements selected.').replace('%d', String(ids.length))); }
	}

	function insert(element) {
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
	function topSelected() {
		const ids = selectedIds();
		return ids.filter((id) => !ids.some((o) => o !== id && contains(find(o).p, id)));
	}
	function removeSelected() {
		const ids = topSelected();
		if (ids.length < 2) { remove(state.selected); return; }
		applyChange(() => { ids.forEach((id) => { const n = find(id); if (n) { n.siblings.splice(n.i, 1); } }); state.selected = null; state.multi = []; });
	}
	function duplicateSelected() {
		const ids = topSelected();
		if (ids.length < 2) { duplicate(state.selected); return; }
		const copies = [];
		applyChange(() => { ids.forEach((id) => { const n = find(id); const c = withNewIds(n.p); n.siblings.splice(n.i + 1, 0, c); copies.push(c.id); }); state.selected = copies[0]; state.multi = copies.slice(1); });
	}
	/** Wraps the selected elements (siblings in one container) in a new container, in the place of the first of them. */
	function group() {
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
	function ungroup() {
		const n = state.selected && find(state.selected);
		if (!n || !n.parent || !n.p.children || !n.p.children.length || n.p.type === 'section' || !TYPY[n.p.type].container) { setState(T('Select a container with elements in it to ungroup.'), true); return; }
		applyChange(() => { const kids = n.p.children; n.siblings.splice(n.i, 1, ...kids); state.selected = kids[0].id; state.multi = kids.slice(1).map((k) => k.id); });
		setState(T('Container removed, its elements stay.'));
	}
	/** Properties of the style for the current breakpoint (desktop, tablet, phone) – one history step. list = [[id, {property: value | ''}], …]. */
	function writeStyle(list, key) {
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
		if (key) { redrawRight(); }
	}
	function offset(id, direction) {
		const n = find(id);
		if (!n || n.i + direction < 0 || n.i + direction >= n.siblings.length) { return; }
		applyChange(() => { n.siblings.splice(n.i, 1); n.siblings.splice(n.i + direction, 0, n.p); });
	}
	function move(id, targetId, destination) {
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
	function placeElements(ids, place, copy) {
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
		markInPreview(false);
	}

	/** Keyboard move: the selection one place earlier (-1) or later (+1) among its siblings; at the end of a container it hops into the next container. */
	function nudge(direction) {
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

	/* ---------- clipboard (also between pages and between Talea sites) ---------- */

	// Within one site the copy lives in localStorage. For another Talea site the same element goes as text into the system
	// clipboard: an envelope {"talea":"elements",…} with its classes and components (Builder\ElementClipboard), which the
	// paste event of the other builder recognises and sends to its server.
	const CLIPBOARD_FORMAT = 'elements';
	function envelope(elements) {
		return query(D.urls.package, { elements: JSON.stringify(elements) })
			.then((j) => JSON.stringify(j.ok ? j.clipboard : { talea: CLIPBOARD_FORMAT, v: 1, site: location.origin, elements, classes: [], components: [] }));
	}
	/** Writes a promised text into the system clipboard; Safari accepts a promise only through ClipboardItem. */
	function writeClipboard(text) {
		if (!navigator.clipboard) { return Promise.reject(new Error('clipboard')); }
		if (window.ClipboardItem) {
			try { return navigator.clipboard.write([new ClipboardItem({ 'text/plain': text.then((t) => new Blob([t], { type: 'text/plain' })) })]); } catch (e) { /* ClipboardItem without promises */ }
		}
		return text.then((t) => navigator.clipboard.writeText(t));
	}
	function copy() {
		const n = state.selected && find(state.selected);
		if (!n) { return; }
		try { localStorage.setItem('tl-builder-clipboard', JSON.stringify(n.p)); } catch (e) { /* private mode */ }
		setState(T('Copied'));
		writeClipboard(envelope([n.p])).catch(() => setState(T('Copied within this site – for another Talea site use More actions → Copy for another Talea site.')));
	}
	/** The copy from this site (localStorage). */
	function pasteFromClipboard() {
		let p = null;
		try { p = JSON.parse(localStorage.getItem('tl-builder-clipboard') || 'null'); } catch (e) { p = null; }
		if (p && TYPY[p.type]) { insert(withNewIds(p)); return true; }
		return false;
	}
	/** Text from the system clipboard: an envelope from this or another Talea site; anything else is not for the editor. */
	function pasteText(text) {
		let data = null;
		try { data = JSON.parse(text); } catch (e) { return false; }
		if (!data || data.talea !== CLIPBOARD_FORMAT || !Array.isArray(data.elements) || !data.elements.length) { return false; }
		if (data.site === location.origin) { insertAll(data.elements.filter((p) => p && TYPY[p.type]).map(withNewIds)); return true; }
		setState(T('Inserting elements from another site…'));
		query(D.urls.paste, { clipboard: text }).then((j) => {
			if (!j.ok) { setState(j.error || T('The elements could not be inserted.'), true); return; }
			D.classes = j.classes;
			setComponents(j.components);
			insertAll(j.elements);
			const notes = j.notes || [];
			setState([T('Inserted from %s.').replace('%s', data.site)].concat(notes).join(' '), notes.length > 0);
		});
		return true;
	}
	/** The first element goes where a new element goes (after the selection or into the container), the others follow it. */
	function insertAll(elements) {
		if (!elements.length) { return; }
		insert(elements[0]);
		elements.slice(1).forEach((p) => {
			const previous = state.selected && find(state.selected);
			if (!previous) { insert(p); return; }
			applyChange(() => { previous.siblings.splice(previous.i + 1, 0, p); state.selected = p.id; });
		});
		redrawPanels();
	}
	/** Ctrl+V: the paste event brings the system clipboard; when none comes (nothing in it), the copy from this site is used. */
	function onPaste(e) {
		const v = e.target;
		if ((v && (v.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(v.tagName))) || document.querySelector('dialog[open]')) { return; }
		clearTimeout(state.pasteTimer);
		const text = e.clipboardData ? e.clipboardData.getData('text/plain') : '';
		if (pasteText(text)) { e.preventDefault(); return; }
		pasteFromClipboard();
	}
	function copyDialog(id) {
		const n = find(id);
		if (!n) { return; }
		const area = el('textarea', { rows: 8, readonly: true, 'aria-label': T('Elements as text') });
		area.value = T('Preparing…');
		const copyButton = el('button', { type: 'button', class: 'bd-btn bd-btn-main', disabled: true, onclick: () => {
			area.focus(); area.select();
			(navigator.clipboard ? navigator.clipboard.writeText(area.value) : Promise.reject()).then(() => { copyButton.textContent = T('Copied'); }, () => { document.execCommand('copy'); copyButton.textContent = T('Copied'); });
		} }, T('Copy'));
		envelope([n.p]).then((text) => { area.value = text; copyButton.disabled = false; area.focus(); area.select(); });
		const d = el('dialog', { class: 'bd-dialog' },
			el('div', {}, el('h2', {}, T('Copy for another Talea site')),
				el('p', {}, T('The text carries the element with its classes and components. In the builder of the other site press Ctrl+V, or choose More actions → Paste from another Talea site.')), area),
			el('footer', {}, el('button', { type: 'button', class: 'bd-btn', onclick: () => d.close() }, T('Close')), copyButton));
		d.addEventListener('close', () => d.remove());
		document.body.append(d);
		d.showModal();
	}
	function pasteDialog() {
		const area = el('textarea', { rows: 8, placeholder: '{"talea":"elements", …}', 'aria-label': T('Elements as text') });
		const note = el('p', { class: 'bd-share-error', hidden: true });
		const d = el('dialog', { class: 'bd-dialog' },
			el('div', {}, el('h2', {}, T('Paste from another Talea site')),
				el('p', {}, T('Paste the text the builder of the other site copied (Ctrl+C on an element, or More actions → Copy for another Talea site).')), area, note),
			el('footer', {}, el('button', { type: 'button', class: 'bd-btn', onclick: () => d.close() }, T('Close')),
				el('button', { type: 'button', class: 'bd-btn bd-btn-main', onclick: () => {
					if (pasteText(area.value.trim())) { d.close(); return; }
					note.hidden = false;
					note.textContent = T('The text is not a copy of Talea elements.');
				} }, T('Insert'))));
		d.addEventListener('close', () => d.remove());
		document.body.append(d);
		d.showModal();
		area.focus();
	}

	/* ---------- keys ---------- */

	function keys(e) {
		const v = e.target;
		const typing = v.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(v.tagName);
		const mod = e.ctrlKey || e.metaKey;
		if (mod && e.key.toLowerCase() === 's') { e.preventDefault(); save(); return; }
		if (typing || document.querySelector('dialog[open], .bd-more:popover-open')) { return; } // in an open dialog or menu the keys (Esc) belong to them
		// selected text is copied as text, not as an element
		const marked = String(window.getSelection() || '') || (preview && preview.contentWindow ? String(preview.contentWindow.getSelection() || '') : '');
		if (mod && e.key.toLowerCase() === 'c' && marked) { return; }
		if (mod && e.key.toLowerCase() === 'z') { e.preventDefault(); if (e.shiftKey) { forward(); } else { back(); } }
		else if (mod && e.key.toLowerCase() === 'y') { e.preventDefault(); forward(); }
		else if (mod && e.key.toLowerCase() === 'd' && state.selected) { e.preventDefault(); duplicateSelected(); }
		else if (mod && e.key.toLowerCase() === 'c' && state.selected) { copy(); }
		else if (mod && e.key.toLowerCase() === 'v') { clearTimeout(state.pasteTimer); state.pasteTimer = setTimeout(pasteFromClipboard, 250); } // the paste event (onPaste) comes first when the system clipboard has text
		else if ((e.key === 'Delete' || e.key === 'Backspace') && state.selected) { e.preventDefault(); removeSelected(); }
		else if (e.key === '?' && !mod) { e.preventDefault(); hint(); }
		else if (e.key === 'Escape' && state.placing) { const doc = preview && preview.contentDocument; if (doc) { showSpot(doc, null); } endPlacing(); }
		else if (e.key === 'Escape' && state.selected) { const n = find(state.selected); selection(n && n.parent ? n.parent.id : null); }
	}

	/* ---------- top bar ---------- */

	let tabList, stateText;
	function setState(message, error, link) {
		if (!stateText) { return; }
		stateText.replaceChildren(message, link ? el('a', { href: link.url, target: '_blank', rel: 'noopener' }, ' ' + link.text) : '');
		stateText.classList.toggle('error', !!error);
	}

	function createBar() {
		stateText = el('span', { class: 'bd-status', role: 'status' }, state.changed ? T('Draft in progress') : T("Published"));
		tabList = el('header', { class: 'bd-bar' });
		root.append(tabList);
		redrawBar();
	}
	let barLook = '';
	function redrawBar() {
		// the bar is rebuilt only when what it shows changes – re-rendering under the cursor would "swallow" a click in progress
		const look = [state.changed, state.bp, state.zoom, state.undo.length > 0, state.redo.length > 0, D.page.published].join();
		if (look === barLook && tabList.childElementCount) { return; }
		barLook = look;
		const bpTl = Object.entries({ base: 'desktop', tablet: 'tablet', mobile: 'mobile' }).map(([bp, ik]) =>
			el('button', { type: 'button', title: BP[bp], 'aria-label': BP[bp], 'aria-pressed': String(state.bp === bp), onclick: () => { state.bp = bp; previewSize(preview); redrawBar(); redrawPanels(); } }, icon(ik)));
		tabList.replaceChildren(...[
			el('a', { class: 'bd-btn', href: D.back.url, title: D.back.text }, icon('parent'), el('span', { class: 'bd-text' }, D.back.text)),
			el('div', { class: 'bd-name' }, el('h1', {}, D.page.title), el('small', {}, state.changed ? T('draft in progress – visitors see the published version') : T('no changes against the live site'))),
			el('div', { class: 'bd-group', role: 'group', 'aria-label': T("Device") }, bpTl),
			el('select', { class: 'bd-magnifier', 'aria-label': T('Preview size'), title: T('Preview size'), onchange: (e) => { state.zoom = e.target.value; previewSize(preview); } },
				[['', T('Fit')], ['1920', T('Wide monitor (1920 px)')], ['100', '100 %'], ['75', '75 %'], ['50', '50 %']].map(([k, n]) => el('option', { value: k, selected: state.zoom === k }, n))),
			el('div', { class: 'bd-group', role: 'group', 'aria-label': T('History') },
				el('button', { type: 'button', title: T('Undo (Ctrl+Z)'), disabled: !state.undo.length, onclick: back }, icon('undo')),
				el('button', { type: 'button', title: T('Redo (Ctrl+Shift+Z)'), disabled: !state.redo.length, onclick: forward }, icon('redo'))),
			stateText,
			el('button', { type: 'button', class: 'bd-btn', title: T('Published versions'), onclick: versionsDialog }, icon('version'), el('span', { class: 'bd-text' }, T('Versions'))),
			D.urls.share ? el('button', { type: 'button', class: 'bd-btn', title: T('Share a link to the draft preview'), onclick: shareDialog }, icon('share'), el('span', { class: 'bd-text' }, T('Share'))) : null,
			D.comments ? el('button', { type: 'button', class: 'bd-btn', title: T('Comments from people with a preview link'), onclick: commentsDialog }, icon('comment'),
				el('span', { class: 'bd-text' }, T('Comments') + (openComments().length ? ' (' + openComments().length + ')' : ''))) : null,
			el('a', { class: 'bd-btn', href: D.page.url, target: '_blank', rel: 'noopener', title: T('Open the published page') }, icon('eye')),
			el('button', { type: 'button', class: 'bd-btn', title: T('Help and keyboard shortcuts (?)'), 'aria-label': T('Help'), onclick: hint }, icon('placeholder')),
			D.page.published && state.changed ? el('button', { type: 'button', class: 'bd-btn', onclick: discard }, T('Discard changes')) : null,
			el('button', { type: 'button', class: 'bd-btn bd-btn-main', disabled: (!state.changed && D.page.published) || D.page.can_publish === false,
				title: D.page.can_publish === false ? T('Only an editor or administrator can publish. Your changes stay saved as a draft.') : null, onclick: publishAfterCheck }, T('Publish')),
		].filter(Boolean));
	}

	/** What the page lacks for a visitor or a search engine: buttons without a link, images without a file or description, the heading outline. */
	function check() {
		const findings = [];
		const headings = [];
		const tags = (x) => typeof x === 'string' && x.includes('{{');
		(function walk(children, inComponent) {
			children.forEach((p) => {
				const o = p.content || {};
				if (p.type === 'button' && (!o.link || o.link === '#')) { findings.push([p.id, T('The button “%s” leads nowhere – add a link.').replace('%s', o.text || '')]); }
				if (p.type === 'image' && !o.src) { findings.push([p.id, T('No image selected – it will not appear on the site.')]); }
				if (p.type === 'image' && o.src && !o.alt && !tags(o.src)) { findings.push([p.id, T('The image has no description for blind visitors (alt).')]); }
				const level = p.type === 'heading' && /^h([1-6])$/.exec(p.tag || 'h2'); // a heading with the p tag (big number, label) is not in the outline
				if (level) { headings.push([p.id, Number(level[1]), text(o.text)]); }
				if (p.children) { walk(p.children, inComponent); }
			});
		})(state.build.children);
		findings.push(...contrastCheck());
		if (D.page.headings) {
			const h1 = headings.filter((n) => n[1] === 1);
			if (!h1.length) { findings.push([headings[0] ? headings[0][0] : null, T('The page has no main heading (h1) – search engines and screen readers use it to tell what the page is about.')]); }
			if (h1.length > 1) { findings.push([h1[1][0], T('The page has more than one main heading (h1) – keep just one.')]); }
			headings.forEach((n, i) => { if (i > 0 && n[1] > headings[i - 1][1] + 1) { findings.push([n[0], T('The heading “%s” skips a level (h%d → h%d).').replace('%s', n[2].slice(0, 40)).replace('%d', headings[i - 1][1]).replace('%d', n[1])]); } });
		}
		return findings;
	}
	/**
	 * Text contrast on the canvas (WCAG AA: 4.5 : 1, large text 3 : 1): the text color against the first opaque surface below it.
	 * The browser converts colors (oklch and color-mix too) via a drawing canvas; an element on a background image is not evaluated.
	 */
	function contrastCheck() {
		const doc = preview && preview.contentDocument;
		if (!doc) { return []; }
		const c = document.createElement('canvas').getContext('2d', { willReadFrequently: true });
		const rgb = (color) => { c.clearRect(0, 0, 1, 1); c.fillStyle = '#000'; c.fillStyle = color; c.fillRect(0, 0, 1, 1); return Array.from(c.getImageData(0, 0, 1, 1).data); };
		const luminance = ([r, g, b]) => [r, g, b].map((x) => { x /= 255; return x <= 0.04045 ? x / 12.92 : ((x + 0.055) / 1.055) ** 2.4; }).reduce((s, x, i) => s + x * [0.2126, 0.7152, 0.0722][i], 0);
		const findings = [];
		const seen = new Set();
		Array.from(doc.querySelectorAll('[data-tl-id]')).slice(0, 400).forEach((node) => {
			const text = Array.from(node.childNodes).some((n) => n.nodeType === 3 && n.textContent.trim()) ? node : node.querySelector('h1,h2,h3,h4,p,li,a,span,strong');
			if (!text || !text.textContent.trim()) { return; }
			const id = node.getAttribute('data-tl-id');
			if (seen.has(id)) { return; }
			const style = doc.defaultView.getComputedStyle(text);
			let below = text;
			let background = null;
			while (below && below.nodeType === 1) {
				const ps = doc.defaultView.getComputedStyle(below);
				if (ps.backgroundImage !== 'none') { return; }
				const b = rgb(ps.backgroundColor);
				if (ps.backgroundColor !== 'transparent' && ps.backgroundColor !== 'rgba(0, 0, 0, 0)') { background = b; break; }
				below = below.parentElement;
			}
			background = background || rgb(doc.defaultView.getComputedStyle(doc.body).backgroundColor);
			const [l1, l2] = [luminance(rgb(style.color)), luminance(background)].sort((x, y) => y - x);
			const ratio = (l1 + 0.05) / (l2 + 0.05);
			const large = parseFloat(style.fontSize) >= 24 || (parseFloat(style.fontSize) >= 18.66 && Number(style.fontWeight) >= 700);
			if (ratio < (large ? 3 : 4.5)) {
				seen.add(id);
				findings.push([id, T('Text “%s” has low contrast against its background (%d : 1) – it is hard to read.').replace('%s', text.textContent.trim().slice(0, 30)).replace('%d', ratio.toFixed(1))]);
			}
		});
		return findings.slice(0, 6);
	}

	/** Help: keyboard shortcuts, the builder guide on taleacms.com and starting the editor tour. */
	function hint() {
		const mod = /Mac|iPhone|iPad/.test(navigator.platform) ? '⌘' : 'Ctrl';
		const shortcuts = [[mod + '+S', T('Save draft')], [mod + '+Z / ' + mod + '+Shift+Z', T('Undo / redo')], [mod + '+D', T('Duplicate the selected element')],
			[mod + '+C / ' + mod + '+V', T('Copy and paste an element (also between pages and Talea sites)')], ['Delete', T('Delete the selected element')], ['Esc', T('Select the parent element / cancel moving')],
			[T('double-click'), T('Edit text right on the canvas')], ['↑ ↓ ← →', T('Move within Structure')],
				[T('Shift- or Ctrl/Cmd-click'), T('Add an element to the selection or take it out')], [T('drag on empty space'), T('Select the elements inside a frame')],
				[T('Arrows on the canvas'), T('Select the previous or next element')], [mod + '+Shift+ ↑ ↓ ← →', T('Move the selected elements (at the end of a container: into the next one)')],
				['Alt+ ↑ ↓ ← →', T('Resize the selected element by one step')], ['Enter', T('Walk the handles of the selected element (Tab), arrows change a value')],
				[mod + '+G / ' + mod + '+Shift+G', T('Group / ungroup')], [T('Alt-drag'), T('Duplicate while moving')], ['?', T('This help')]];
		const d = el('dialog', { class: 'bd-dialog' },
			el('div', {}, el('h2', {}, T('Keyboard shortcuts')),
				el('dl', { class: 'bd-shortcuts' }, shortcuts.flatMap(([k, t]) => [el('dt', {}, el('kbd', {}, k)), el('dd', {}, t)]))),
			el('footer', {}, D.urls.guide ? el('a', { class: 'bd-btn', href: D.urls.guide, target: '_blank', rel: 'noopener' }, T('Builder guide')) : null,
				el('button', { type: 'button', class: 'bd-btn', onclick: () => { d.close(); tour(0); } }, T('Editor tour')),
				el('button', { type: 'button', class: 'bd-btn bd-btn-main', onclick: () => d.close() }, T('Close'))));
		d.addEventListener('close', () => d.remove());
		document.body.append(d);
		d.showModal();
	}

	/** Intro tour: four stops, each highlighting a part of the editor. The first time it starts by itself, then from the help. */
	function tour(step) {
		const stops = [
			[left, T('Elements and ready-made sections'), T('On the left you pick an element or a whole ready-made section. Click to insert it after the selected element, drag it anywhere on the page. The Structure tab shows the page as a tree.')],
			[frame2, T('The page as visitors will see it'), T('Click to select an element, double-click to edit text. At the top you switch the preview to tablet and mobile – the style then changes only for that width.')],
			[right, T('Content, style and advanced'), T('On the right you change the text, links, colours, spacing and behaviour of the selected element. Take colours and sizes from the list – they keep the website consistent.')],
			[tabList, T('Saving and publishing'), T('Changes save automatically as a draft. Visitors see them only after Publish – before that we warn you about missing links, descriptions and low contrast.')],
		];
		document.querySelectorAll('.bd-highlighted').forEach((x) => x.classList.remove('bd-highlighted'));
		try { localStorage.setItem('tl-bd-tour', '1'); } catch (e) { /* private mode */ }
		if (step >= stops.length) { return; }
		const [target, heading, text] = stops[step];
		target.classList.add('bd-highlighted');
		const d = el('dialog', { class: 'bd-dialog bd-tour' },
			el('div', {}, el('small', {}, (step + 1) + ' / ' + stops.length), el('h2', {}, heading), el('p', {}, text)),
			el('footer', {}, el('button', { type: 'button', class: 'bd-btn', onclick: () => { d.close(); tour(stops.length); } }, T('Skip')),
				el('button', { type: 'button', class: 'bd-btn bd-btn-main', onclick: () => { d.close(); tour(step + 1); } }, step + 1 < stops.length ? T('Next') : T('Done'))));
		d.addEventListener('close', () => d.remove());
		document.body.append(d);
		d.show();
	}

	/** Before publishing shows the check findings; publishing is possible anyway (warnings only). */
	function publishAfterCheck() {
		const findings = check();
		if (!findings.length) { publish(); return; }
		const d = el('dialog', { class: 'bd-dialog' },
			el('div', {}, el('h2', {}, T('Pre-publish check')), el('p', {}, T('We found a few things on the page worth fixing:')),
				el('ul', { class: 'bd-check' }, findings.slice(0, 12).map(([id, message]) => el('li', {}, id ? el('button', { type: 'button', class: 'bd-link', onclick: () => { d.close(); selection(id); } }, message) : message)))),
			el('footer', {}, el('button', { type: 'button', class: 'bd-btn', onclick: () => d.close() }, T('Back to editing')),
				el('button', { type: 'button', class: 'bd-btn bd-btn-main', onclick: () => { d.close(); publish(); } }, T('Publish anyway'))));
		d.addEventListener('close', () => d.remove());
		document.body.append(d);
		d.showModal();
	}
	function publish() {
		save().then((ok) => {
			if (!ok) { return null; } // saving has already shown the message; nothing older gets published
			setState(T('Publishing…'));
			return query(D.urls.publish, { ok: 1, version: state.version });
		}).then((j) => {
			if (!j) { return; }
			if (!j.ok) { if (j.conflict) { state.conflict = true; conflictDialog(j); } else { setState(j.error || T('Publishing failed.'), true); } return; }
			state.changed = rejectInvalid();
			D.page.published = true;
			setState(D.page.visible ? T('Published – changes are live') : T('Published (the page is still hidden – make it public in the page settings)'));
			redrawBar();
		});
	}
	function discard() {
		confirmAction(T('Discard all changes since the last publish? This cannot be undone.'), T('Discard')).then((yes) => {
			if (!yes) { return; }
			// a scheduled save would recreate the draft after discarding; a running one is left to finish
			stopSaving().then(() => query(D.urls.discard, { ok: 1 })).then((j) => {
				if (!j.ok) { setState(j.error, true); return; }
				state.build = j.build; state.saved = JSON.stringify(j.build); state.version = j.version; state.conflict = false;
				state.undo = []; state.redo = []; state.changed = false; state.selected = null;
				setState(T('Changes discarded')); redraw(); refreshPreview();
			});
		});
	}
	/** Cancels a scheduled save and waits until the one already running finishes (returns a promise). */
	function stopSaving() {
		clearTimeout(state.timer);
		state.timer = null;
		return queue;
	}
	function versionsDialog() {
		query(D.urls.versions).then((j) => {
			if (j.ok === false) { setState(j.error, true); return; }
			const list = el('ul');
			(j.revisions || []).forEach((r) => list.append(el('li', {}, el('span', {}, r.when, r.who ? ' · ' + r.who : ''),
				el('button', { type: 'button', class: 'bd-btn', onclick: () => { d.close(); stopSaving().then(() => query(D.urls.restore, { revision_id: r.revision_id })).then((o) => {
					if (!o.ok) { setState(o.error, true); return; }
					state.undo.push(JSON.stringify(state.build)); state.build = o.build; state.saved = JSON.stringify(o.build); state.version = o.version; state.conflict = false;
					state.changed = true; state.selected = null;
					setState(T('The older version is in the draft – publish it when ready')); redraw(); refreshPreview();
				}); } }, T('Load into draft')))));
			const d = el('dialog', { class: 'bd-dialog' }, el('div', {}, el('h2', {}, T('Published versions')), j.revisions && j.revisions.length ? list : el('p', { class: 'bd-empty' }, T('No older versions yet.'))),
				el('footer', {}, el('button', { type: 'button', class: 'bd-btn', onclick: () => d.close() }, T('Close'))));
			d.addEventListener('close', () => d.remove());
			document.body.append(d);
			d.showModal();
		});
	}

	/** Comments from shared previews (2.15): the unresolved ones first; a click on the element scrolls the canvas to it, one click resolves. */
	function openComments() { return (D.comments || []).filter((c) => !c.resolved); }
	function commentsDialog() {
		const list = el('ul', { class: 'bd-comments' });
		const d = el('dialog', { class: 'bd-dialog' }, el('div', {}, el('h2', {}, T('Comments on the draft')),
			el('p', { class: 'bd-share-note' }, T('Written by people who opened a preview link that allows comments. Feedback to act on in the draft – nothing publishes by itself.')), list),
			el('footer', {}, el('button', { type: 'button', class: 'bd-btn', onclick: () => d.close() }, T('Close'))));
		const render = () => {
			list.replaceChildren(...(D.comments || []).map((c) => el('li', { class: c.resolved ? 'bd-comment-resolved' : null },
				el('div', { class: 'bd-comment-head' }, el('strong', {}, c.name), ' · ', c.when, c.resolved ? ' · ' + T('resolved') : ''),
				c.quote ? el('blockquote', {}, c.quote) : null,
				el('p', {}, c.text),
				el('div', { class: 'bd-comment-actions' },
					c.element && find(c.element) ? el('button', { type: 'button', class: 'bd-link', onclick: () => { d.close(); selection(c.element); } }, T('Show the element')) : null,
					!c.resolved ? el('button', { type: 'button', class: 'bd-btn', onclick: (e) => { e.target.disabled = true; query(D.urls.comment_resolve, { id: c.id }).then((j) => {
						if (!j.ok) { e.target.disabled = false; setState(j.error, true); return; }
						D.comments = j.comments || []; render(); redrawBar();
					}); } }, T('Resolve')) : null))));
			if (!(D.comments || []).length) { list.replaceChildren(el('li', { class: 'bd-empty' }, T('No comments yet. Share a preview link with comments allowed.'))); }
		};
		render();
		d.addEventListener('close', () => d.remove());
		document.body.append(d);
		d.showModal();
	}

	/** A draft preview link for a colleague or client: anyone can open it without signing in, valid for 1–7 days. */
	function shareDialog() {
		const days = el('select', { 'aria-label': T('Link validity') },
			[['1', T('1 day')], ['3', T('3 days')], ['7', T('7 days')]].map(([k, n]) => el('option', { value: k, selected: k === '7' }, n)));
		const result = el('div', { class: 'bd-share', 'aria-live': 'polite' });
		// comments (2.15): the visitor with the link can click an element and write what they think; the flag is signed into the key
		const comments = D.comments ? el('input', { type: 'checkbox' }) : null;
		const create = el('button', { type: 'button', class: 'bd-btn bd-btn-main', onclick: () => {
			create.disabled = true;
			// the link shows what the server has – unsaved changes are saved first
			save().then((ok) => ok ? query(D.urls.share, { days: days.value, comments: comments && comments.checked ? '1' : '0' }) : { ok: false, error: T('The draft could not be saved.') }).then((j) => {
				create.disabled = false;
				if (!j.ok) { result.replaceChildren(el('p', { class: 'bd-share-error' }, j.error || T('The link could not be created.'))); return; }
				const field = el('input', { type: 'text', readonly: true, value: j.link, 'aria-label': T('Preview link'), onfocus: (e) => e.target.select() });
				const copy = el('button', { type: 'button', class: 'bd-btn', onclick: () => {
					field.select();
					(navigator.clipboard ? navigator.clipboard.writeText(j.link) : Promise.reject()).then(() => { copy.textContent = T('Copied'); }, () => { document.execCommand('copy'); copy.textContent = T('Copied'); });
				} }, T('Copy'));
				const isValid = new Date(j.valid_until * 1000).toLocaleString(document.documentElement.lang === 'en' ? 'en-GB' : document.documentElement.lang || undefined, { dateStyle: 'medium', timeStyle: 'short' });
				result.replaceChildren(el('div', { class: 'bd-share-row' }, field, copy), el('p', { class: 'bd-share-note' }, T('Valid until %s.').replace('%s', isValid)));
				field.focus();
			});
		} }, T('Create link'));
		const d = el('dialog', { class: 'bd-dialog' },
			el('div', {}, el('h2', {}, T('Share preview')),
				el('p', {}, T('Anyone with the link can see the draft without signing in – including changes you make later. Search engines do not index it.')),
				el('label', { class: 'bd-share-row' }, el('span', {}, T("Valid for")), days),
				comments ? el('label', { class: 'bd-share-row' }, comments, el('span', {}, T('Allow comments – whoever opens the link can click an element and write a note with their name'))) : null, result),
			el('footer', {}, el('button', { type: 'button', class: 'bd-btn', onclick: () => d.close() }, T('Close')), create));
		d.addEventListener('close', () => d.remove());
		document.body.append(d);
		d.showModal();
	}

	/* ---------- left panel: Add and Structure ---------- */

	let left, leftContent, right;
	function redrawLeft() {
		const tabItem = (key, name) => el('button', { type: 'button', role: 'tab', 'aria-selected': String(state.leftTab === key), onclick: () => { state.leftTab = key; redrawLeft(); } }, name);
		leftContent = el('div', { class: 'bd-panel' });
		left.replaceChildren(el('div', { class: 'bd-tabs', role: 'tablist' }, tabItem('add', T("Add")), tabItem('structure', T('Structure'))), leftContent);
		if (state.leftTab === 'add') { addPanel(); } else { redrawTree(); }
	}

	/** AI: a new section from a description – inserted after the selected section (or at the end) as a normal change, can be undone. */
	function aiSection() {
		const field = el('textarea', { rows: 5, placeholder: T('E.g.: Three cards with our services – kitchens, wardrobes, staircases. A short description and a contact link for each.') });
		const d = el('dialog', { class: 'bd-dialog' }, el('div', {}, el('h2', {}, T('Create a section with AI')),
			el('label', { class: 'bd-field' }, el('span', {}, T('What should the section contain?')), field),
			el('p', { class: 'bd-empty', style: 'text-align:left;padding:0' }, T('The assistant drafts texts and layout in your site\'s style. Check the result – fill in facts (numbers, prices, names) yourself.'))),
		el('footer', {}, el('button', { type: 'button', class: 'bd-btn', onclick: () => d.close() }, T('Cancel')),
			el('button', { type: 'button', class: 'bd-btn bd-btn-main', onclick: () => {
				const prompt = field.value.trim();
				if (!prompt) { field.focus(); return; }
				d.close();
				setState(T('The assistant is drafting a section…'));
				query(D.urls.ai_section, { prompt: prompt }).then((j) => {
					if (!j.ok) { setState(j.error || T('The assistant did not respond.'), true); return; }
					D.classes = j.classes;
					const v = state.selected && find(state.selected);
					let upper = v; while (upper && upper.parent) { upper = find(upper.parent.id); }
					applyChange(() => { state.build.children.splice(upper ? upper.i + 1 : state.build.children.length, 0, ...j.elements); });
					selection(j.elements[0].id);
					redrawPanels();
					setState(j.notes && j.notes.length ? T('Section inserted. Notes: ') + j.notes.join(' ') : T('Section inserted – check the texts.'));
				});
			} }, T('Create'))));
		d.addEventListener('close', () => d.remove());
		document.body.append(d);
		d.showModal();
		field.focus();
	}

	/** AI: rewriting an element's text (shorter, longer…) – the result is a normal change, Undo reverts it. */
	function aiRewrites(p) {
		const key = { heading: 'text', text: 'html', button: 'text', testimonial: 'text' }[p.type];
		if (!D.ai || !key || !(p.content[key] || '').trim() || String(p.content[key]).includes('{{')) { return null; }
		const instructions = [['shorter', T('shorter')], ['longer', T('longer')], ['formal', T('more formal')], ['friendly', T('friendlier')], ['fix', T('fix mistakes')]];
		return el('div', { class: 'bd-ai' }, el('span', {}, '✨ ' + T('Rewrite with AI:')), el('div', {}, instructions.map(([instruction, name]) => el('button', { type: 'button', onclick: (e) => {
			e.target.disabled = true;
			setState(T('The assistant is rewriting the text…'));
			query(D.urls.ai_text, { text: p.content[key], instruction: instruction, html: key === 'html' ? '1' : '0' }).then((j) => {
				e.target.disabled = false;
				if (!j.ok) { setState(j.error || T('The assistant did not respond.'), true); return; }
				applyChange(() => { p.content[key] = j.text; });
				redrawRight();
				setState(T('Text rewritten – Ctrl+Z undoes it.'));
			});
		} }, name))));
	}

	function addPanel() {
		if (D.ai) { leftContent.append(el('button', { type: 'button', class: 'bd-btn bd-ai-section', onclick: aiSection }, '✨ ' + T('Create a section with AI'))); }
		// one search for elements, my sections and ready-made sections
		const searchBox = el('input', { type: 'search', class: 'bd-search', placeholder: T('Search elements or sections…'), 'aria-label': T('Search elements or sections'), oninput: (e) => render(e.target.value) });
		const content = el('div', {});
		leftContent.append(searchBox, content);
		const groups = {};
		D.schema.elements.forEach((p) => { (groups[p.group] = groups[p.group] || []).push(p); });
		const render = (search) => {
			const q = search.trim().toLowerCase();
			const matches = (...texts) => !q || texts.join(' ').toLowerCase().includes(q);
			content.replaceChildren();
			for (const [name, elements] of Object.entries(groups)) {
				const selected = elements.filter((p) => matches(p.name, p.description, T(name)));
				if (!selected.length) { continue; }
				content.append(el('h3', {}, T(name)), el('div', { class: 'bd-elements' }, selected.map((p) =>
					el('button', { type: 'button', title: p.description, draggable: 'true', onclick: () => insert(newElement(p.type)),
						ondragstart: (e) => startDrag(e, { newType: p.type }), ondragend: endDrag }, icon(p.icon), p.name))));
			}
			const mine = (D.my_sections || []).filter((m) => matches(m.name));
			if (mine.length) {
				content.append(el('h3', {}, T('My sections')), el('div', { class: 'bd-library' }, mine.map((m) => el('span', { class: 'bd-my-section' },
					el('button', { type: 'button', draggable: 'true', onclick: () => insert(withNewIds(m.element)), ondragstart: (e) => startDrag(e, { custom: m.element }), ondragend: endDrag }, el('strong', {}, m.name)),
					D.urls.delete_section ? el('button', { type: 'button', class: 'bd-remove', title: T('Remove from my sections'), 'aria-label': T('Remove from my sections') + ': ' + m.name, onclick: () => confirmAction(T('Remove the section “%s” from my sections? It stays on pages where it is already used.').replace('%s', m.name), T("Remove")).then((yes) => {
						if (yes) { query(D.urls.delete_section, { section_id: m.id }).then((j) => { if (j.ok) { D.my_sections = j.sections; redrawLeft(); } else { setState(j.error, true); } }); }
					}) }, '×') : null))));
			}
			libraryFilter(q);
		};
		const library = el('div', {});
		let libraryFilter = () => {};
		// ready-made sections by category; search filters by name and description
		libraryFilter = (q) => {
			const blocks = Object.entries(D.library_categories || { content: '' }).map(([category, categoryName]) => {
				const section = D.library.filter((s) => (s.category || 'content') === category && (!q || (s.name + ' ' + s.description).toLowerCase().includes(q)));
				return section.length ? el('div', {}, el('h3', {}, categoryName), el('div', { class: 'bd-library' }, section.map(sectionButton))) : null;
			}).filter(Boolean);
			library.replaceChildren(...(blocks.length ? [el('h3', { class: 'bd-heading-library' }, T('Ready-made sections')), ...blocks] : []));
			content.append(library);
			if (!content.querySelector('button')) { content.append(el('p', { class: 'bd-empty' }, T('Nothing like that here.'))); }
		};
		render('');
	}

	/** A live preview of a ready-made section next to the panel (the server renders it in the site design, scaled down). */
	let previewSection = null;
	let sectionPreviewTimer = null;
	function showSectionPreview(button, key) {
		clearTimeout(sectionPreviewTimer);
		sectionPreviewTimer = setTimeout(() => {
			if (!previewSection) {
				previewSection = el('div', { class: 'bd-preview-section', 'aria-hidden': 'true' }, el('iframe', { tabindex: '-1', title: '' }));
				document.body.append(previewSection);
			}
			const r = button.getBoundingClientRect();
			const iframe = previewSection.firstChild;
			if (iframe.dataset.key !== key) { iframe.dataset.key = key; iframe.src = D.urls.section_preview + encodeURIComponent(key); }
			previewSection.style.left = Math.max(8, Math.min(r.right + 12, window.innerWidth - 392)) + 'px';
			previewSection.style.top = Math.max(8, Math.min(window.innerHeight - 280, r.top - 40)) + 'px';
			previewSection.hidden = false;
		}, 250);
	}
	function hideSectionPreview() {
		clearTimeout(sectionPreviewTimer);
		if (previewSection) { previewSection.hidden = true; }
	}

	function sectionButton(s) {
		return (
			el('button', { onmouseenter: (e) => showSectionPreview(e.currentTarget, s.key), onmouseleave: hideSectionPreview, onfocus: (e) => showSectionPreview(e.currentTarget, s.key), onblur: hideSectionPreview, type: 'button', draggable: 'true', ondragstart: (e) => startDrag(e, { section: s.key }), ondragend: endDrag, onclick: () => query(D.urls.section + '&key=' + encodeURIComponent(s.key), { ok: 1 }).then((j) => {
				if (!j.ok) { setState(j.error, true); return; }
				D.classes = j.classes;
				insert(j.element);
			}) }, el('strong', {}, s.name), el('small', {}, s.description)));
	}

	function redrawTree() {
		if (state.leftTab !== 'structure' || !leftContent) { return; }
		const node = (p) => {
			const s = TYPY[p.type] || { name: p.type, icon: 'block' };
			const hasChildren = p.children && p.children.length;
			const row = el('div', {
				class: 'bd-node' + (state.hidden[p.id] ? ' bd-hidden' : ''), draggable: p.locked ? null : 'true', role: 'treeitem', 'aria-selected': String(state.selected === p.id),
				'aria-expanded': hasChildren ? String(!state.collapsed[p.id]) : null, tabindex: state.selected === p.id || (!state.selected && state.build.children[0] === p) ? '0' : '-1',
				'data-id': p.id, onkeydown: (e) => treeKeys(e, p, hasChildren),
				onclick: () => {
					const u = state.placing && find(state.placing.move);
					if (!u) { selection(p.id); return; }
					// move by tapping in Structure: inside an empty container, otherwise after the tapped element
					if (u.p.id === p.id || contains(u.p, p.id)) { return; }
					const what = state.placing;
					endPlacing();
					dropAt(what, { target: p.id, where: TYPY[p.type] && TYPY[p.type].container && !hasChildren ? 'inside' : 'after' });
				},
				onmouseenter: () => { const t = preview && preview.contentDocument && preview.contentDocument.querySelector('[data-tl-id="' + p.id + '"]'); if (t) { t.classList.add('tl-bd-hover'); } },
				onmouseleave: () => { const t = preview && preview.contentDocument && preview.contentDocument.querySelector('[data-tl-id="' + p.id + '"]'); if (t) { t.classList.remove('tl-bd-hover'); } },
				ondragstart: (e) => { state.draggedNode = p.id; e.dataTransfer.effectAllowed = 'move'; e.dataTransfer.setData('text/plain', p.id); },
				ondragover: (e) => {
					if (!state.draggedNode || state.draggedNode === p.id) { return; }
					e.preventDefault();
					const r = row.getBoundingClientRect();
					const y = (e.clientY - r.top) / r.height;
					const destination = s.container && y > 0.25 && y < 0.75 ? 'inside' : (y < 0.5 ? 'before' : 'after');
					row.classList.remove('target-before', 'target-after', 'target-inside');
					row.classList.add('target-' + destination);
					row.dataset.where = destination;
				},
				ondragleave: () => row.classList.remove('target-before', 'target-after', 'target-inside'),
				ondrop: (e) => { e.preventDefault(); row.classList.remove('target-before', 'target-after', 'target-inside'); if (state.draggedNode) { move(state.draggedNode, p.id, row.dataset.where || 'after'); } state.draggedNode = null; },
				ondragend: () => { state.draggedNode = null; },
			},
			hasChildren ? el('button', { type: 'button', class: 'bd-collapse', 'aria-label': T('Collapse / expand'), onclick: (e) => { e.stopPropagation(); state.collapsed[p.id] = !state.collapsed[p.id]; redrawTree(); } }, state.collapsed[p.id] ? '▸' : '▾') : el('span', { style: 'width:16px;flex:none' }),
			icon(s.icon), el('span', {}, labelText(p)), el('small', {}, p.tag),
			el('span', { class: 'bd-node-actions' },
				el('button', { type: 'button', title: T('Hide in the editor only (stays on the site)'), 'aria-label': T('Hide in the editor only (stays on the site)'), 'aria-pressed': String(!!state.hidden[p.id]), tabindex: '-1',
					onclick: (e) => { e.stopPropagation(); state.hidden[p.id] = !state.hidden[p.id]; hiddenOnCanvas(); redrawTree(); } }, icon(state.hidden[p.id] ? 'hidden' : 'eye')),
				el('button', { type: 'button', title: T('Lock: cannot be selected or moved on the canvas'), 'aria-label': T('Lock: cannot be selected or moved on the canvas'), 'aria-pressed': String(!!p.locked), tabindex: '-1',
					onclick: (e) => { e.stopPropagation(); applyChange(() => { if (p.locked) { delete p.locked; } else { p.locked = true; } }); } }, icon(p.locked ? 'lock' : 'unlocked'))));
			return el('li', { role: 'none' }, row, hasChildren && !state.collapsed[p.id] ? el('ul', { role: 'group' }, p.children.map(node)) : null);
		};
		leftContent.replaceChildren(state.build.children.length
			? el('ul', { class: 'bd-tree', role: 'tree' }, state.build.children.map(node))
			: el('p', { class: 'bd-empty' }, T('The page is empty. Add a section from the Add panel.')));
	}

	/** The tree from the keyboard (ARIA tree pattern): up/down arrows between visible nodes, right/left expand, collapse or jump to the parent. */
	function treeKeys(e, p, hasChildren) {
		const nodes = Array.from(leftContent.querySelectorAll('.bd-node'));
		const i = nodes.indexOf(e.currentTarget);
		const focusTarget = (u) => { if (u) { nodes.forEach((x) => { x.tabIndex = -1; }); u.tabIndex = 0; u.focus(); } };
		if (e.key === 'ArrowDown') { e.preventDefault(); focusTarget(nodes[i + 1]); }
		else if (e.key === 'ArrowUp') { e.preventDefault(); focusTarget(nodes[i - 1]); }
		else if (e.key === 'Home') { e.preventDefault(); focusTarget(nodes[0]); }
		else if (e.key === 'End') { e.preventDefault(); focusTarget(nodes[nodes.length - 1]); }
		else if (e.key === 'ArrowRight' && hasChildren) {
			e.preventDefault();
			if (state.collapsed[p.id]) { state.collapsed[p.id] = false; redrawTree(); focusTarget(leftContent.querySelector('[data-id="' + p.id + '"]')); } else { focusTarget(nodes[i + 1]); }
		} else if (e.key === 'ArrowLeft') {
			e.preventDefault();
			const n = find(p.id);
			if (hasChildren && !state.collapsed[p.id]) { state.collapsed[p.id] = true; redrawTree(); focusTarget(leftContent.querySelector('[data-id="' + p.id + '"]')); } else if (n && n.parent) { focusTarget(leftContent.querySelector('[data-id="' + n.parent.id + '"]')); }
		} else if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); selection(p.id); }
	}

	/* ---------- right panel: properties of the selected element, or class editing ---------- */

	function redrawPanels() { redrawTree(); redrawRight(); }
	function redraw() { redrawBar(); redrawLeft(); redrawRight(); }

	function redrawRight() {
		if (state.editedClass !== null) { classesPanel(); return; }
		const n = state.selected && find(state.selected);
		if (!n) {
			right.replaceChildren(el('div', { class: 'bd-panel' }, el('p', { class: 'bd-empty' }, T('Select an element on the canvas or in the structure. Double-click text to edit it right on the page.')),
				D.urls.settings ? el('p', { class: 'bd-empty' }, el('a', { href: D.urls.settings }, D.settings_text || T('Page settings (title, address, SEO)'))) : null));
			return;
		}
		const p = n.p;
		const s = TYPY[p.type];
		const tabItem = (key, name) => el('button', { type: 'button', role: 'tab', 'aria-selected': String(state.rightTab === key), onclick: () => { state.rightTab = key; redrawRight(); } }, name);
		const panel = el('div', { class: 'bd-panel' });
		// errors from the server: at the selected element (at the specific field too), for the others only a count with a link
		const path = state.selectedPath = elementPath(p.id);
		const all = Object.entries(state.errors);
		const custom = all.filter(([k]) => k === path || k.startsWith(path + '.content') || k.startsWith(path + '.style'));
		const elsewhere = all.filter(([k]) => !custom.some(([v]) => v === k));
		if (custom.length || elsewhere.length) {
			panel.append(el('ul', { class: 'bd-errors' }, custom.slice(0, 6).map(([, t]) => el('li', {}, t)),
				elsewhere.length ? el('li', {}, T('Warnings on other elements: ') + elsewhere.length + ' ', el('button', { type: 'button', class: 'bd-link', onclick: () => { const x = elementByPath(elsewhere[0][0]); if (x) { selection(x.id); } } }, T("show"))) : null));
		}
		// less frequent actions are in the "More actions" menu (with a description), so the bar fits the panel even on a laptop
		const more = [
			p.locked ? null : [icon('move'), state.placing ? T('Cancel move by tapping') : T('Move by tapping the target (works on touch screens too)'), () => (state.placing ? endPlacing() : startPlacing(p.id))],
			D.urls.component && p.type !== 'component' ? [icon('component'), T('Save as component'), () => saveAsComponent(p.id)] : null,
			D.urls.save_section ? [icon('library'), T('Save to my sections (then insert it on any page)'), () => saveToMySections(p.id)] : null,
			[icon('clipboard'), T('Copy for another Talea site (as text)'), () => copyDialog(p.id)],
			[icon('clipboard'), T('Paste from another Talea site (as text)'), () => pasteDialog()],
		].filter(Boolean);
		const offer = more.length ? el('div', { id: 'bd-more', class: 'bd-more', popover: 'auto' },
			more.map(([ik, description, action]) => el('button', { type: 'button', onclick: () => { offer.hidePopover(); action(); } }, ik, el('span', {}, description)))) : null;
		const moreButton = offer ? el('button', { type: 'button', title: T('More actions'), 'aria-label': T('More actions'), popovertarget: 'bd-more' }, icon('more')) : null;
		if (offer) {
			// the menu below the button, aligned to its right edge (a popover is otherwise drawn in the middle of the window)
			offer.addEventListener('toggle', (e) => {
				if (e.newState !== 'open') { return; }
				const r = moreButton.getBoundingClientRect();
				offer.style.top = (r.bottom + 4) + 'px';
				offer.style.left = Math.max(8, r.right - offer.offsetWidth) + 'px';
			});
		}
		right.replaceChildren(
			el('div', { class: 'bd-head-element' }, icon(s.icon), el('strong', {}, s.name), el('div', { class: 'bd-actions' },
				el('button', { type: 'button', title: T('Up'), onclick: () => offset(p.id, -1) }, icon('up')),
				el('button', { type: 'button', title: T('Down'), onclick: () => offset(p.id, 1) }, icon('down')),
				n.parent ? el('button', { type: 'button', title: T('Select parent element (Esc)'), onclick: () => selection(n.parent.id) }, icon('parent')) : null,
				el('button', { type: 'button', title: T('Duplicate (Ctrl+D)'), onclick: duplicateSelected }, icon('copy')),
				moreButton, offer,
				el('button', { type: 'button', class: 'danger', title: T('Delete (Delete)'), onclick: removeSelected }, icon('delete')))),
			...(state.multi.length ? [el('p', { class: 'bd-multi', role: 'status' }, T('%d elements selected. The panel below edits the first one; Delete, Duplicate and Ctrl+G act on all of them.').replace('%d', String(state.multi.length + 1)))] : []),
			el('div', { class: 'bd-tabs', role: 'tablist' }, tabItem('content', T('Content')), tabItem('style', T("Style")), tabItem('advanced', T("Advanced"))),
			panel,
		);
		if (state.rightTab === 'content') { contentPanel(panel, p, s); } else if (state.rightTab === 'style') { stylePanel(panel, p, 'element:' + p.id); } else { advancedPanel(panel, p, s); }
	}

	/** The collection whose items the element receives: the nearest parent "Collection list", otherwise the collection of the detail template. */
	function elementCollection(id) {
		let n = find(id);
		while (n) {
			if (n.p.type === 'collection_list' && n.p.id !== id) { return (D.collections || []).find((k) => k.slug === n.p.content.collection) || null; }
			n = n.parent ? find(n.parent.id) : null;
		}
		return D.detail_collection || null;
	}

	/** Help for {{siblings}} placeholders – a click copies the placeholder. */
	function placeholderHint(collection) {
		const tags = (collection.builtin === false ? [] : [['name', T("Name")], ['url', T('Detail address')], ['date', T('Date')]]).concat(collection.fields.map((p) => [p.key, p.label]));
		if (!tags.length) { return null; }
		return el('div', { class: 'bd-html-tags' }, el('span', {}, (collection.builtin === false ? T('Component properties “%s” – insert into text, image or link:') : T('Fields of collection “%s” – insert into text, image or link:')).replace('%s', collection.name)),
			el('div', {}, tags.map(([key, name]) => el('button', { type: 'button', title: name, onclick: (e) => {
				const htmlTag = '{{' + key + '}}';
				if (navigator.clipboard) { navigator.clipboard.writeText(htmlTag); }
				e.target.textContent = T('copied');
				setTimeout(() => { e.target.textContent = htmlTag; }, 1200);
			} }, '{{' + key + '}}'))));
	}

	/**
	 * A dialog with one named field instead of window.prompt() (that cannot be styled or translated and browsers suppress it).
	 * Enter saves, Esc cancels; returns the entered text, or null.
	 */
	function askName(heading, fieldLabel, value, hint) {
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

	/** A copy of the selected element into the own library ("My sections", My sections) – unlike a component, it is edited independently after inserting. */
	function saveToMySections(id) {
		const n = find(id);
		if (!n) { return; }
		askName(T('Save to my sections'), T('Section name'), labelText(n.p),
			T('The section appears in the Add panel → My sections. Each insert is a separate copy; if it should be the same everywhere, save it as a component.')).then((name) => {
			if (!name) { return; }
			query(D.urls.save_section, { name: name, element: JSON.stringify(n.p) }).then((j) => {
				if (!j.ok) { setState(j.error || T('Saving failed.'), true); return; }
				D.my_sections = j.sections;
				setState(T('The section is in the Add panel → My sections.'));
				if (state.leftTab === 'add') { redrawLeft(); }
			});
		});
	}

	/** The selected element is saved as a component (administrator) and a use of the component remains in its place. */
	function saveAsComponent(id) {
		const n = find(id);
		if (!n) { return; }
		askName(T('Save as component'), T('Component name (e.g. Service card):').replace(/:$/, ''), labelText(n.p),
			T('A component is a shared template: editing it under Components changes it everywhere it is used. The element on this page is replaced by the component.')).then((name) => {
			if (name) { saveComponent(n, name); }
		});
	}

	/** The site's components changed (saved, pasted from another site): the choice in the Component element follows. */
	function setComponents(list) {
		if (!Array.isArray(list)) { return; }
		D.components = list;
		const options = { '': '—' };
		list.forEach((k) => { options[String(k.id)] = k.name; });
		if (TYPY.component) { TYPY.component.properties.component.options = options; }
	}

	function saveComponent(n, name) {
		query(D.urls.component, { name, element: JSON.stringify(n.p) }).then((j) => {
			if (!j.ok) { setState(j.error || T('Saving failed.'), true); return; }
			setComponents(j.components);
			const usage = { id: newId(), type: 'component', tag: 'div', content: { component: String(j.id), values: {} }, style: {} };
			applyChange(() => { n.siblings.splice(n.i, 1, usage); state.selected = usage.id; });
			redrawPanels();
			setState(T('Component saved – edits in Components apply everywhere it is used.'));
		});
	}

	/** Property values of a component use: fields according to the selected component, empty = default value. */
	function valueField(p) {
		const component = (D.components || []).find((k) => String(k.id) === String(p.content.component));
		if (!component) { return null; }
		const wrapper = el('div', { class: 'bd-field' }, el('span', {}, T('Properties')));
		if (!component.properties.length) {
			wrapper.append(el('p', { class: 'bd-empty' }, T('The component has no properties – it looks the same everywhere.')));
			return wrapper;
		}
		if (!p.content.values || Array.isArray(p.content.values)) { p.content.values = {}; }
		component.properties.forEach((v) => {
			const def = { type: v.type === 'lines' || v.type === 'html' ? 'lines' : (v.type === 'image' ? 'image' : 'text'), label: v.label + ' {{' + v.key + '}}' };
			const inputEl = field(def, p.content.values[v.key] || '', (h) => applyChange(() => { p.content.values[v.key] = h; }, 'values:' + p.id + ':' + v.key));
			const input = inputEl.querySelector('input, textarea');
			if (input && v.default) { input.placeholder = v.default; }
			wrapper.append(inputEl);
		});
		return wrapper;
	}

	function contentPanel(panel, p, s) {
		if (p.type === 'component') {
			panel.append(field(s.properties.component, p.content.component, (h) => { applyChange(() => { p.content.component = h; p.content.values = {}; }); redrawRight(); }));
			const values = valueField(p);
			if (values) { panel.append(values); }
			return;
		}
		const collection = p.type !== 'collection_list' ? elementCollection(p.id) : null;
		const hint = collection ? placeholderHint(collection) : null;
		if (hint) { panel.append(hint); }
		const ai = aiRewrites(p);
		if (ai) { panel.append(ai); }
		const properties = Object.entries(s.properties || {});
		if (!properties.length) { panel.append(el('p', { class: 'bd-empty' }, s.container ? T('A container has no content of its own – put elements into it and set its look in the Style tab.') : T('This element has no editable content.'))); return; }
		properties.forEach(([key, def]) => panel.append(field(def, p.content[key], (h) => { if (JSON.stringify(p.content[key]) !== JSON.stringify(h)) { applyChange(() => { p.content[key] = h; }, 'content:' + p.id + ':' + key); } },
			{ error: state.errors[state.selectedPath + '.content.' + key], element: p })));
	}

	/** A control for a content field according to the type from the schema. */
	function field(def, value, change, options) {
		const description = T(def.label || '');
		const error = options && options.error;
		const wrapper = el('label', { class: 'bd-field' + (error ? ' bd-field-error' : '') }, el('span', {}, description), error ? el('small', { class: 'bd-error-field', role: 'alert' }, error) : null);
		let inputEl;
		switch (def.type) {
			case 'boolean':
				return el('label', { class: 'bd-tick' }, el('input', { type: 'checkbox', checked: !!value, onchange: (e) => change(e.target.checked) }), description);
			case 'choice':
				inputEl = el('select', { onchange: (e) => change(e.target.value) }, Object.entries(def.options).map(([k, v]) => el('option', { value: k, selected: k === String(value) }, T(v))));
				break;
			case 'number':
				inputEl = el('input', { type: 'number', min: def.min ?? 0, max: def.max ?? 100, value: value, oninput: (e) => change(parseInt(e.target.value, 10) || 0) });
				break;
			case 'lines': case 'code':
				inputEl = el('textarea', { rows: def.type === 'code' ? 8 : 5, oninput: (e) => change(e.target.value) });
				inputEl.value = value || '';
				break;
			case 'html': {
				const ta = el('textarea', { 'data-editor': 'small', oninput: (e) => change(e.target.value) });
				ta.value = value || '';
				wrapper.append(ta);
				if (window.taleaCreateEditor) { setTimeout(() => window.taleaCreateEditor(ta), 0); }
				return wrapper;
			}
			case 'image': {
				const imagePreview = el('img', { class: 'bd-image-preview', alt: '', src: value || null, hidden: !value });
				inputEl = el('input', { type: 'text', value: value || '', placeholder: 'media/…', oninput: (e) => { change(e.target.value); imagePreview.src = e.target.value; imagePreview.hidden = !e.target.value; } });
				const btn = el('button', { type: 'button', class: 'bd-btn', onclick: () => window.taleaPickImage && window.taleaPickImage((o) => {
					inputEl.value = o.url; imagePreview.src = o.url; imagePreview.hidden = false; change(o.url);
					// the description for blind users from the Media library, when the element has none yet (can be overwritten)
					const p = options && options.element;
					if (p && 'alt' in p.content && !p.content.alt && o.name) { applyChange(() => { p.content.alt = o.name; }); redrawRight(); }
				}) }, T('Media'));
				wrapper.append(el('span', { class: 'bd-field-row' }, inputEl, btn), imagePreview);
				return wrapper;
			}
			case 'structured': // typed schema.org data: the control is in builder-structured.js
				if (window.taleaStructuredField) { return window.taleaStructuredField(def, value, change, { page: D.page }); }
				return wrapper;
			case 'items':
				return itemField(def, Array.isArray(value) ? value : [], change);
			default:
				if (def.media === 'video' || def.media === 'file') { // a file from Media (section background video, a form's gated file): not a link menu, but a file picker
					const video = def.media === 'video';
					inputEl = el('input', { type: 'text', value: value ?? '', placeholder: video ? 'media/…/video.mp4' : 'media/…/file.pdf', oninput: (e) => change(e.target.value) });
					wrapper.append(el('span', { class: 'bd-field-row' }, inputEl, el('button', { type: 'button', class: 'bd-btn', onclick: () => window.taleaPickImage && window.taleaPickImage((o) => {
						if (video && !/\.(mp4|webm)$/i.test(o.url || '')) { setState(T('Choose a video in MP4 or WebM format.'), true); return; }
						inputEl.value = o.url; change(o.url);
					}, false, true) }, T('Media'))));
					return wrapper;
				}
				inputEl = el('input', { type: 'text', value: value ?? '', placeholder: def.type === 'link' ? T('site page, https://…, #anchor, mailto:, tel:') : null,
					list: def.type === 'link' ? 'bd-dl-links' : null, onfocus: def.type === 'link' ? refreshLinks : null, oninput: (e) => change(e.target.value) });
		}
		wrapper.append(inputEl);
		return wrapper;
	}

	function itemField(def, previous, change) {
		const wrapper = el('div', { class: 'bd-field' }, el('span', {}, T(def.label)));
		// works on a copy: every change (adding, removing and moving too) then goes through zmena → history, save, canvas re-render
		const items = clone(Array.isArray(previous) ? previous : []);
		const save = () => change(clone(items));
		items.forEach((item, i) => {
			const box = el('div', { class: 'bd-item' });
			// a field with the condition „when“ ({siblings: hodnota}) shows only when another field of the item has the given value (options only for a select)
			const visible = (d) => !d.when || Object.entries(d.when).every(([pk, pv]) => item[pk] === pv);
			Object.entries(def.fields).forEach(([k, d]) => {
				if (!visible(d)) { return; }
				box.append(field(d, item[k], (h) => {
					item[k] = h;
					save();
					if (Object.values(def.fields).some((other) => other.when && k in other.when)) { redrawRight(); }
				}));
			});
			box.append(el('div', { class: 'bd-item-actions bd-actions' },
				el('button', { type: 'button', title: T('Up'), onclick: () => { if (i > 0) { items.splice(i - 1, 0, items.splice(i, 1)[0]); change(clone(items)); redrawRight(); } } }, icon('up')),
				el('button', { type: 'button', class: 'danger', title: T("Remove"), onclick: () => { items.splice(i, 1); change(clone(items)); redrawRight(); } }, icon('delete'))));
			wrapper.append(box);
		});
		wrapper.append(el('button', { type: 'button', class: 'bd-btn', onclick: () => {
			const newVersion = {};
			Object.entries(def.fields).forEach(([k, d]) => { newVersion[k] = d.default ?? ''; });
			items.push(newVersion);
			change(clone(items));
			redrawRight();
		} }, T('Add item')));
		return wrapper;
	}

	/* ---------- style (of an element and a class) ---------- */

	const HINTS = {
		space: ['2xs', 'xs', 's', 'm', 'l', 'xl', '2xl', '3xl', '0'], step: ['-1', '0', '1', '2', '3', '4', '5'], radius: ['0', 's', 'm', 'l', 'full'], shadow: ['s', 'm', 'l', 'none'],
		color: Object.keys(D.schema.tokens.colors).concat(['transparent']), length: ['auto', '100%', '50%', 'var(--tl-text-width)', 'var(--tl-width)', '20rem', '30rem', '60vh', 'fit-content'],
		columns: ['1', '2', '3', '4', 'auto:14rem', 'auto:16rem', 'auto:20rem', '2fr 1fr', '1fr 2fr'], number: ['-1', '0', '1', '2'],
		rows: ['1', '2', '3', 'auto 1fr auto'], border: Object.keys((D.schema.style.border || {}).options || {}).concat(['1px solid line', '2px dashed primary']),
		area: [],
	};
	const datalists = el('div', { hidden: true }, Object.entries(HINTS).map(([type, values]) => el('datalist', { id: 'bd-dl-' + type }, values.map((h) => el('option', { value: h })))),
		el('datalist', { id: 'bd-dl-links' }));
	/** The link field menu: site pages, news and element anchors on this page (up to date on every opening). */
	function refreshLinks() {
		const anchors = [];
		(function walk(children) { children.forEach((p) => { if (p.anchor) { anchors.push(['#' + p.anchor, labelText(p)]); } if (p.children) { walk(p.children); } }); })(state.build.children);
		datalists.querySelector('#bd-dl-links').replaceChildren(...(D.links || []).concat(anchors).map(([url, name]) => el('option', { value: url, label: name })));
	}

	/** An approximate token color (for the swatch in the panel) – derived shades are mixed the same way as on the site. */
	function tokenColor(h) {
		const b = D.colors;
		const mapping = { primary: b.primary, secondary: b.secondary, text: b.text, background: b.background, surface: b.surface, white: '#fff', black: '#000',
			'primary-soft': 'color-mix(in oklch, ' + b.primary + ' 12%, ' + b.background + ')', muted: 'color-mix(in oklch, ' + b.text + ' 64%, ' + b.background + ')',
			line: 'color-mix(in oklch, ' + b.text + ' 14%, ' + b.background + ')', 'on-primary': '#fff' };
		return mapping[h] || h;
	}

	/** The edited style state: a breakpoint, or hover/press – separately on tablet and mobile (hover_tablet…). */
	function currentState() { return state.elementState ? state.elementState + (state.bp === 'base' ? '' : '_' + state.bp) : state.bp; }
	const INHERITANCE = { base: [], tablet: ['base'], mobile: ['tablet', 'base'], hover: ['base'], hover_tablet: ['hover', 'tablet', 'base'],
		hover_mobile: ['hover_tablet', 'hover', 'mobile', 'tablet', 'base'], active: ['hover', 'base'], active_tablet: ['active', 'hover_tablet', 'hover', 'tablet', 'base'],
		active_mobile: ['active_tablet', 'active', 'hover_mobile', 'hover_tablet', 'hover', 'mobile', 'tablet', 'base'] };
	function inherited(style, key) {
		for (const st of INHERITANCE[currentState()] || []) { if (style[st] && style[st][key] !== undefined) { return style[st][key]; } }
		return '';
	}

	/** Style panel: $cil is an element ({styl}) or a class record; a change goes through zmen (element) or ulozTridu (class). */
	function stylePanel(panel, target, changeKey, shouldSave) {
		target.style = target.style && !Array.isArray(target.style) ? target.style : {};
		const s = currentState();
		panel.append(el('div', { class: 'bd-status-style' },
			el('span', {}, T('Editing: '), el('strong', {}, [{ hover: T('hover and focus'), active: T('press') }[state.elementState], state.elementState && state.bp === 'base' ? '' : BP[state.bp]].filter(Boolean).join(' · '))),
			el('span', { class: 'bd-group', role: 'group', 'aria-label': T('Element state') }, [['', T("Regular")], ['hover', T("Hover")], ['active', T('Press')]].map(([k, n]) =>
				el('button', { type: 'button', class: 'bd-btn', 'aria-pressed': String(state.elementState === k), title: k === 'hover' ? T('Mouse hover – also applies to keyboard focus') : null, onclick: () => { state.elementState = k; redrawRight(); } }, n)))));
		// copying only the style (without content) between elements and pages – the browser keeps it
		const clipboard = () => { try { return JSON.parse(localStorage.getItem('tl-bd-style') || 'null'); } catch (e) { return null; } };
		panel.append(el('div', { class: 'bd-field-row bd-style-clipboard' },
			el('button', { type: 'button', class: 'bd-btn', onclick: () => { try { localStorage.setItem('tl-bd-style', JSON.stringify({ style: target.style, classes: target.classes || [] })); setState(T('Style copied.')); redrawRight(); } catch (e) { /* private mode */ } } }, T('Copy style')),
			el('button', { type: 'button', class: 'bd-btn', disabled: !clipboard() || shouldSave, onclick: () => {
				const v = clipboard();
				if (!v) { return; }
				applyChange(() => { target.style = JSON.parse(JSON.stringify(v.style || {})); if (v.classes && v.classes.length) { target.classes = v.classes.slice(); } else { delete target.classes; } });
				redrawRight();
			} }, T('Paste style'))));
		if (s !== 'base') { panel.append(el('p', { class: 'placeholder', style: 'margin:0 0 8px;font-size:12px;color:var(--text-weak)' }, T('Empty field = same value as on the larger screen (grey).'))); }
		// the first (open) group by element kind: Typography for text, Size for an image, otherwise Layout
		const first = { heading: 'typography', text: 'typography', button: 'typography', list: 'typography', testimonial: 'typography', breadcrumbs: 'typography',
			counter: 'typography', image: 'dimensions', video: 'dimensions', map: 'dimensions' }[target.type];
		const groups = first ? { [first]: [] } : {};
		Object.entries(STYLE).forEach(([key, def]) => { (groups[def.group] = groups[def.group] || []).push([key, def]); });
		const box = el('div', { class: 'bd-style' });
		Object.entries(groups).forEach(([group, properties], order) => {
			const isSet = properties.filter(([k]) => target.style[s] && target.style[s][k] !== undefined).length;
			const det = el('details', { open: isSet > 0 || order === 0 }, el('summary', {}, T(D.schema.style_groups[group]), isSet ? el('small', {}, isSet) : null));
			const content = el('div');
			const set = (key, value) => {
				const perform = () => {
					target.style[s] = target.style[s] || {};
					if (value === '') { delete target.style[s][key]; if (!Object.keys(target.style[s]).length) { delete target.style[s]; } } else { target.style[s][key] = value; }
				};
				if (shouldSave) { perform(); shouldSave(); } else { applyChange(perform, changeKey + ':' + s + ':' + key); }
			};
			if (group === 'layout' && ((target.style[s] || {}).display || inherited(target.style, 'display')) === 'grid') { content.append(gridEditor(target, s, set)); }
			properties.forEach(([key, def]) => content.append(styleControl(target, key, def, (value) => set(key, value))));
			det.append(content);
			box.append(det);
		});
		panel.append(box);
	}

	function styleControl(target, key, def, change) {
		const s = currentState();
		const value = (target.style[s] || {})[key] ?? '';
		const inheritedFrom = inherited(target.style, key);
		let inputEl;
		if (def.type === 'choice') {
			inputEl = el('select', { onchange: (e) => change(e.target.value) }, el('option', { value: '' }, inheritedFrom ? '↳ ' + T(def.options[inheritedFrom] || inheritedFrom) : '—'),
				Object.entries(def.options).map(([k, v]) => el('option', { value: k, selected: k === value }, T(v))));
		} else {
			const field = el('input', { type: 'text', value: value, placeholder: inheritedFrom, list: HINTS[def.type] ? 'bd-dl-' + def.type : null,
				onchange: (e) => change(e.target.value.trim()), oninput: (e) => { if (sample) { sample.style.background = tokenColor(e.target.value || inheritedFrom || 'transparent'); } } });
			// color: the swatch is also the color picker (a custom shade as #hex); the site tokens are offered by the list in the field
			const sample = def.type === 'color' ? el('label', { class: 'bd-swatch', title: T('Pick a custom colour'), style: 'background:' + tokenColor(value || inheritedFrom || 'transparent') },
				el('input', { type: 'color', 'aria-label': T('Pick a custom colour'), value: /^#[0-9a-f]{6}$/i.test(value) ? value : '#000000',
					oninput: (e) => { sample.style.background = e.target.value; }, onchange: (e) => { field.value = e.target.value; change(e.target.value); } })) : null;
			inputEl = el('span', { class: 'bd-field-row' }, sample, field,
				def.type === 'image' ? el('button', { type: 'button', class: 'bd-btn', title: T('Media'), onclick: () => window.taleaPickImage && window.taleaPickImage((o) => { field.value = o.url; change(o.url); }) }, '…') : null,
				def.type === 'shadow' || def.type === 'border' ? el('button', { type: 'button', class: 'bd-btn', title: T('Compose your own'), 'aria-expanded': 'false', onclick: (e) => {
					const opened = e.currentTarget.getAttribute('aria-expanded') === 'true';
					e.currentTarget.setAttribute('aria-expanded', String(!opened));
					const box = e.currentTarget.closest('.bd-property').querySelector('.bd-storage');
					if (box) { box.remove(); return; }
					e.currentTarget.closest('.bd-property').append((def.type === 'shadow' ? shadowStack : borderStack)(value || inheritedFrom, (h) => { field.value = h; change(h); }));
				} }, '✎') : null);
			if (def.type === 'border') { field.setAttribute('list', 'bd-dl-border'); }
		}
		const id = 'bd-v-' + key;
		(inputEl.matches('select') ? inputEl : inputEl.querySelector('input[type="text"]')).id = id;
		const error = target.id && state.errors[state.selectedPath + '.style.' + s + '.' + key];
		// a value set only for this screen size is marked, and one click returns to what the larger screen gives
		const overridden = value !== '' && s !== 'base';
		return el('div', { class: 'bd-property' + (value !== '' ? ' set' : '') + (overridden ? ' bd-overridden' : '') + (error ? ' bd-field-error' : '') }, el('label', { for: id, title: def.css }, T(def.label)), inputEl,
			overridden ? el('button', { type: 'button', class: 'bd-reset', title: T('Reset to inherited'), 'aria-label': T('Reset to inherited') + ': ' + T(def.label), onclick: () => { change(''); redrawRight(); } }, icon('undo')) : null,
			error ? el('small', { class: 'bd-error-field', role: 'alert' }, error) : null);
	}

	/** Shadow builder: offset, blur, spread, color and opacity → „0 8px 24px color-mix(…)“; color tokens work. */
	function shadowStack(value, change) {
		const m = /^(inset\s+)?(-?[\d.]+)(?:px)?\s+(-?[\d.]+)(?:px)?\s+(-?[\d.]+)?(?:px)?\s*(-?[\d.]+)?(?:px)?\s*(\S+)?/.exec(/^[slm]$|^none$/.test(value) ? '' : value) || [];
		const v = { inset: !!m[1], x: m[2] || '0', y: m[3] || '8', blur: m[4] || '24', spread: m[5] || '0', color: m[6] && m[6][0] === '#' ? m[6].slice(0, 7) : '#000000', strength: 15 };
		const collapse = () => change((v.inset ? 'inset ' : '') + v.x + 'px ' + v.y + 'px ' + v.blur + 'px ' + v.spread + 'px ' + v.color + Math.round(v.strength * 2.55).toString(16).padStart(2, '0'));
		const number = (key, labelText, min, max) => el('label', {}, el('span', {}, T(labelText)), el('input', { type: 'number', min, max, value: v[key], oninput: (e) => { v[key] = e.target.value || '0'; collapse(); } }));
		return el('div', { class: 'bd-storage' }, number('x', 'Horizontal', -60, 60), number('y', 'Vertical', -60, 60), number('blur', 'Blur', 0, 120), number('spread', 'Spread', -40, 40),
			el('label', {}, el('span', {}, T("Colour")), el('input', { type: 'color', value: v.color, oninput: (e) => { v.color = e.target.value; collapse(); } })),
			el('label', {}, el('span', {}, T('Strength')), el('input', { type: 'range', min: 3, max: 60, value: v.strength, oninput: (e) => { v.strength = +e.target.value; collapse(); } })),
			el('label', { class: 'bd-tick' }, el('input', { type: 'checkbox', checked: v.inset, onchange: (e) => { v.inset = e.target.checked; collapse(); } }), T('Inset')));
	}

	/** Border builder: width, line and color (a token or custom) → „2px dashed primarni“. */
	function borderStack(value, change) {
		const m = /^(\d+(?:\.\d)?)px\s+(solid|dashed|dotted|double)\s+(\S+)$/.exec(value) || [];
		const v = { width: m[1] || '1', line: m[2] || 'solid', color: m[3] || 'line' };
		const collapse = () => change(v.width + 'px ' + v.line + ' ' + v.color);
		return el('div', { class: 'bd-storage' },
			el('label', {}, el('span', {}, T('Width')), el('input', { type: 'number', min: 1, max: 20, value: v.width, oninput: (e) => { v.width = e.target.value || '1'; collapse(); } })),
			el('label', {}, el('span', {}, T("Line")), el('select', { onchange: (e) => { v.line = e.target.value; collapse(); } },
				[['solid', "solid"], ['dashed', "dashed"], ['dotted', "dotted"], ['double', "double"]].map(([k, n]) => el('option', { value: k, selected: k === v.line }, T(n))))),
			el('label', {}, el('span', {}, T("Colour")), el('input', { type: 'text', list: 'bd-dl-color', value: v.color, onchange: (e) => { v.color = e.target.value.trim() || 'line'; collapse(); } })));
	}

	/**
	 * Grid editor: a preview of columns and rows, quick presets and naming areas by clicking into cells
	 * (nested elements then get "Grid area"). Writes to the properties columns, rows and areas.
	 */
	function gridEditor(target, s, set) {
		const value = (k) => (target.style[s] || {})[k] || inherited(target.style, k);
		const columns = value('columns') || '1';
		const columnCount = /^\d+$/.test(columns) ? +columns : /^auto:/.test(columns) ? 3 : columns.trim().split(/\s+/).length;
		const areas = value('areas') ? value('areas').split('/').map((r) => r.trim().split(/\s+/)) : [];
		const rows = value('rows');
		const rowCount = Math.max(areas.length, /^\d+$/.test(rows) ? +rows : rows ? rows.trim().split(/\s+/).length : 0, 1);
		const widths = /^\d+$/.test(columns) || /^auto:/.test(columns) ? Array(columnCount).fill('1fr') : columns.trim().split(/\s+/);
		const grid = el('div', { class: 'bd-grid-preview', style: 'grid-template-columns:' + widths.map((w) => /fr$/.test(w) ? w : 'auto').join(' ') });
		for (let r = 0; r < rowCount; r++) {
			for (let c = 0; c < Math.min(columnCount, 12); c++) {
				const name = (areas[r] || [])[c] || '.';
				grid.append(el('input', { type: 'text', value: name === '.' ? '' : name, 'aria-label': T('Area') + ' ' + (r + 1) + '/' + (c + 1), placeholder: '·',
					onchange: (e) => {
						const table = Array.from({ length: rowCount }, (_, ri) => Array.from({ length: Math.min(columnCount, 12) }, (_, childIndex) => (areas[ri] || [])[childIndex] || '.'));
						table[r][c] = (e.target.value.trim().toLowerCase().replace(/[^a-z0-9-]/g, '') || '.').replace(/^(\d)/, 'o$1');
						set('areas', table.every((ra) => ra.every((x) => x === '.')) ? '' : table.map((ra) => ra.join(' ')).join(' / '));
					} }));
			}
		}
		const preset = (text, h) => el('button', { type: 'button', class: 'bd-btn', 'aria-pressed': String(columns === h), onclick: () => set('columns', h) }, text);
		return el('div', { class: 'bd-grid' },
			el('div', { class: 'bd-grid-presets' }, preset('1', '1'), preset('2', '2'), preset('3', '3'), preset('4', '4'), preset('2 : 1', '2fr 1fr'), preset('1 : 2', '1fr 2fr'), preset(T('fit to space'), 'auto:16rem')),
			el('div', { class: 'bd-grid-rows' }, el('span', {}, T('Rows')),
				el('button', { type: 'button', class: 'bd-btn', 'aria-label': T('Remove row'), onclick: () => set('rows', rowCount > 1 ? String(rowCount - 1) : '') }, '−'),
				el('strong', {}, String(rowCount)),
				el('button', { type: 'button', class: 'bd-btn', 'aria-label': T('Add row'), onclick: () => set('rows', String(Math.min(12, rowCount + 1))) }, '+')),
			grid,
			el('small', {}, T('Type area names into the cells (the same name across several cells = the element spans them). Then give the nested element its “Grid area”.')));
	}

	/* ---------- advanced: tag, classes, anchor ---------- */

	function advancedPanel(panel, p, s) {
		if (s.tags.length > 1) {
			panel.append(field({ type: 'choice', label: 'HTML tag', options: Object.fromEntries(s.tags.map((z) => [z, '<' + z + '>'])) }, p.tag, (h) => applyChange(() => { p.tag = h; })));
		}
		panel.append(
			field({ type: 'text', label: 'Name in Structure' }, p.label || '', (h) => applyChange(() => { if (h) { p.label = h; } else { delete p.label; } }, 'label:' + p.id)),
			field({ type: 'text', label: 'Anchor (id for a #… link)' }, p.anchor || '', (h) => applyChange(() => { if (h) { p.anchor = h; } else { delete p.anchor; } }, 'anchor:' + p.id)),
		);
		const classes = p.classes || [];
		const newClass = el('input', { type: 'text', list: 'bd-dl-classes', placeholder: T('e.g. card'), onkeydown: (e) => { if (e.key === 'Enter') { e.preventDefault(); addClass(); } } });
		const addClass = () => {
			const name = newClass.value.trim().toLowerCase();
			if (!/^[a-z][a-z0-9-]{0,40}(__[a-z0-9-]{1,30})?(--[a-z0-9-]{1,30})?$/.test(name) || classes.includes(name)) { return; }
			applyChange(() => { p.classes = classes.concat([name]); });
		};
		panel.append(el('div', { class: 'bd-field' }, el('span', {}, T('Classes')),
			el('div', { class: 'bd-classes' }, classes.map((t) => el('span', { class: 'bd-class' },
				el('a', { href: '#', title: T('Edit class'), onclick: (e) => { e.preventDefault(); state.editedClass = t; redrawRight(); } }, '.' + t),
				el('button', { type: 'button', title: T('Remove class from element'), onclick: () => applyChange(() => { p.classes = classes.filter((x) => x !== t); if (!p.classes.length) { delete p.classes; } }) }, '×')))),
			el('span', { class: 'bd-field-row' }, newClass, el('button', { type: 'button', class: 'bd-btn', onclick: addClass }, T("Add"))),
			el('datalist', { id: 'bd-dl-classes' }, Object.keys(D.classes).map((t) => el('option', { value: t }))),
			el('small', { style: 'color:var(--text-weak)' }, T('A class shares its look between elements on all pages. Click a class to edit it.'))));
		const cssField = el('textarea', { rows: 4, placeholder: 'transition: transform .2s;\nbackdrop-filter: blur(8px);', onchange: (e) => applyChange(() => { const h = e.target.value.trim(); if (h) { p.css = h; } else { delete p.css; } }) });
		cssField.value = p.css || '';
		const attributeField = el('textarea', { rows: 3, placeholder: 'data-track=cta\naria-label=' + T('Main call to action'), onchange: (e) => applyChange(() => {
			const attributes = {};
			e.target.value.split('\n').forEach((row) => { const i = row.indexOf('='); if (i > 0) { attributes[row.slice(0, i).trim()] = row.slice(i + 1).trim(); } });
			if (Object.keys(attributes).length) { p.attributes = attributes; } else { delete p.attributes; }
		}) });
		attributeField.value = Object.entries(p.attributes || {}).map(([k, v]) => k + '=' + v).join('\n');
		panel.append(el('h3', {}, T('Custom CSS and attributes')),
			el('label', { class: 'bd-field' + (state.errors[state.selectedPath + '.css'] ? ' bd-field-error' : '') }, el('span', {}, T('CSS for this element only (property: value;)')), cssField,
				state.errors[state.selectedPath + '.css'] ? el('small', { class: 'bd-error-field' }, state.errors[state.selectedPath + '.css']) : null),
			el('label', { class: 'bd-field' + (state.errors[state.selectedPath + '.attributes'] ? ' bd-field-error' : '') }, el('span', {}, T('Attributes (name=value, one per line; data-…, aria-…, title, lang, role, rel)')), attributeField,
				state.errors[state.selectedPath + '.attributes'] ? el('small', { class: 'bd-error-field' }, state.errors[state.selectedPath + '.attributes']) : null));
		const cond = p.conditions || {};
		const setCondition = (key, h) => applyChange(() => { p.conditions = Object.assign({}, p.conditions || {}); if (h) { p.conditions[key] = h; } else { delete p.conditions[key]; } if (!Object.keys(p.conditions).length) { delete p.conditions; } });
		panel.append(el('h3', {}, T('Display conditions')),
			field({ type: 'choice', label: "Show to", options: { '': 'everyone', no: 'visitors only (not signed in)', yes: 'only people signed in to the administration' } }, cond.signed_in || '', (h) => setCondition('signed_in', h)),
			el('div', { class: 'bd-field-row' },
				el('label', { class: 'bd-field' }, el('span', {}, T('Show from')), el('input', { type: 'date', value: cond.from || '', onchange: (e) => setCondition('from', e.target.value) })),
				el('label', { class: 'bd-field' }, el('span', {}, T('Show until (inclusive)')), el('input', { type: 'date', value: cond.to || '', onchange: (e) => setCondition('to', e.target.value) }))));
		// language versions: nothing checked = every version; shown on a single-language site only when a build already has the condition
		const languages = D.languages || [];
		const chosenLanguages = Array.isArray(cond.languages) ? cond.languages : null;
		if (languages.length > 1 || chosenLanguages) {
			panel.append(el('div', { class: 'bd-field' }, el('span', {}, T('Only in these language versions (none checked = all)')),
				languages.map((l) => el('label', { class: 'bd-tick' }, el('input', { type: 'checkbox', checked: !!chosenLanguages && chosenLanguages.includes(l.code), onchange: (e) => {
					const list = (chosenLanguages || []).filter((k) => k !== l.code);
					if (e.target.checked) { list.push(l.code); }
					setCondition('languages', list.length ? list : null);
				} }), l.name))));
		}
		// a query parameter of the page address: a campaign link (?utm_campaign=jaro) or a variant (?variant=b)
		const parameter = cond.url_parameter || {};
		const setParameter = (name, value) => setCondition('url_parameter', name ? Object.assign({ name: name }, value ? { value: value } : {}) : null);
		panel.append(el('div', { class: 'bd-field-row' },
				el('label', { class: 'bd-field' }, el('span', {}, T('Only with a URL parameter (name)')),
					el('input', { type: 'text', value: parameter.name || '', placeholder: 'utm_campaign', maxlength: 40, onchange: (e) => setParameter(e.target.value.trim(), parameter.value || '') })),
				el('label', { class: 'bd-field' }, el('span', {}, T('…with the value (empty = any)')),
					el('input', { type: 'text', value: parameter.value || '', placeholder: 'jaro', maxlength: 80, disabled: !parameter.name, onchange: (e) => setParameter(parameter.name || '', e.target.value.trim()) }))),
			el('div', {}, state.errors[state.selectedPath + '.conditions'] ? el('small', { class: 'bd-error-field' }, state.errors[state.selectedPath + '.conditions']) : null, // el() skips null – append() would write "null"
				el('small', { style: 'color:var(--text-weak)' }, T('On the canvas the element is always visible. On the website it appears only when the conditions are met – for example a promotional banner for a week.') + ' '
					+ T('A page with a date, sign-in or URL parameter condition is assembled for every visit (it is not cached).'))));
		panel.append(el('h3', {}, T('Visibility')),
			el('label', { class: 'bd-tick' }, el('input', { type: 'checkbox', checked: ((p.style || {}).mobile || {}).display === 'none', onchange: (e) => applyChange(() => {
				p.style = p.style || {};
				if (e.target.checked) { p.style.mobile = Object.assign(p.style.mobile || {}, { display: 'none' }); } else if (p.style.mobile) { delete p.style.mobile.display; }
			}) }), T('Hide on mobile')),
			el('label', { class: 'bd-tick' }, el('input', { type: 'checkbox', checked: ((p.style || {}).tablet || {}).display === 'none', onchange: (e) => applyChange(() => {
				p.style = p.style || {};
				if (e.target.checked) { p.style.tablet = Object.assign(p.style.tablet || {}, { display: 'none' }); } else if (p.style.tablet) { delete p.style.tablet.display; }
			}) }), T('Hide on tablet and mobile')));
	}

	/* ---------- editing a shared class ---------- */

	const classTimer = {};
	function classesPanel() {
		const name = state.editedClass;
		const record = D.classes[name] || (D.classes[name] = { style: {}, css: '' });
		if (Array.isArray(record.style)) { record.style = {}; }
		const saveClass = () => {
			setState(T('Unsaved…'));
			clearTimeout(classTimer[name]); // a timer for each class separately – switching to another class does not cancel saving the previous one
			classTimer[name] = setTimeout(() => query(D.urls.class, { name: name, style: JSON.stringify(record.style), css: record.css }).then((j) => {
				if (!j.ok) { setState(j.error, true); return; }
				D.classes = j.classes;
				setState(j.errors ? T('Class saved with a warning') : T('Class saved – it applies on all pages'), !!j.errors);
				refreshPreview();
			}), 500);
		};
		const panel = el('div', { class: 'bd-panel' });
		right.replaceChildren(el('div', { class: 'bd-head-element' }, el('strong', {}, T('Class') + ' .' + name),
			el('div', { class: 'bd-actions' }, el('button', { type: 'button', title: T('Back to element'), onclick: () => { state.editedClass = null; redrawRight(); } }, icon('close')))), panel);
		panel.append(el('p', { style: 'margin:0 0 10px;font-size:12px;color:var(--text-weak)' }, T('Class changes apply to all elements with this class across the site – immediately after saving, without publishing.')));
		if (!D.urls.delete_section) {
			// shared classes are edited only by an administrator (the server enforces it too)
			panel.append(el('p', { class: 'bd-empty', style: 'text-align:left;padding:0' }, T('Only an administrator edits a shared class – a change applies to the whole website at once. Set the look of a single element in its style.')));
			return;
		}
		stylePanel(panel, record, 'class:' + name, saveClass);
		const css = el('textarea', { rows: 5, placeholder: 'transition: transform .2s;', oninput: (e) => { record.css = e.target.value; saveClass(); } });
		css.value = record.css || '';
		const whereParts = el('p', { class: 'bd-empty', style: 'text-align:left;padding:0' }, T('Checking where the class is used…'));
		query(D.urls.class, { name: name, usage: '1' }).then((j) => {
			whereParts.textContent = j.ok ? (j.usage.length ? T('Used in: ') + j.usage.join(', ') : T('The class is not used in any published or draft build yet.')) : '';
		});
		const newName = el('input', { type: 'text', value: name, 'aria-label': T('New class name') });
		panel.append(el('h3', {}, T('Custom CSS')), el('label', { class: 'bd-field' }, el('span', {}, T('Extra declarations (property: value;)')), css),
			el('h3', {}, T('Where it is used')), whereParts);
		if (D.urls.delete_section) { // renaming and deleting is allowed to an administrator (the change affects the whole site)
			panel.append(el('h3', {}, T('Rename')), el('span', { class: 'bd-field-row' }, newName, el('button', { type: 'button', class: 'bd-btn', onclick: () => {
				const fresh = newName.value.trim().toLowerCase();
				if (!fresh || fresh === name) { return; }
				// first save unsaved changes, then rename in all builds and reload the editor
				save().then((ok) => (ok ? query(D.urls.class, { name: name, new_name: fresh }) : null)).then((j) => {
					if (!j) { return; }
					if (!j.ok) { setState(j.error, true); return; }
					window.location.reload();
				});
			} }, T('Rename'))));
		}
		if (!D.urls.delete_section) { return; }
		panel.append(el('button', { type: 'button', class: 'bd-btn', onclick: () => confirmAction(T('Delete class .') + name + T('? Elements keep it in the structure, but it will lose its look.'), T("Delete")).then((yes) => {
				if (!yes) { return; }
				query(D.urls.class, { name: name, delete: '1' }).then((j) => { if (!j.ok) { setState(j.error, true); return; } D.classes = j.classes; state.editedClass = null; redrawRight(); refreshPreview(); });
			}) }, T('Delete class')));
	}

	/* ---------- start ---------- */

	/** What the compose scripts (builder-overlay.js, builder-handles.js, builder-keys.js) may use – nothing else of the editor is reachable from them. */
	window.taleaBuilder = {
		D, T, TYPY, state, el, icon, on, find, contains, newElement, withNewIds, applyChange, writeStyle, selection, selectMany, toggleSelected, selectedIds, topSelected,
		remove: removeSelected, duplicate: duplicateSelected, group, ungroup, nudge, placeElements, dropAt, canvasSpot, showSpot, startPlacing, endPlacing, editOnCanvas,
		redrawPanels, redrawRight, setState, askName, labelText, query, refreshPreview, markInPreview, save,
		previewDoc: () => (preview && preview.contentDocument) || null,
		scale: () => (preview && parseFloat(preview.dataset.scale)) || 1,
		/** After a gesture: the canvas may be swapped again (a refresh that came during it runs now). */
		endGesture: () => { state.gesture = false; if (state.refreshAfterGesture) { state.refreshAfterGesture = false; refreshPreview(); } },
	};

	createBar();
	left = el('aside', { class: 'bd-left', 'aria-label': T('Elements and structure') });
	right = el('aside', { class: 'bd-right', 'aria-label': T('Properties') });
	frame2 = el('div', { class: 'bd-frame', 'data-bp': 'base' });
	scale = el('span', { class: 'bd-scale', 'aria-hidden': 'true' });
	root.append(left, el('main', { class: 'bd-canvas' }, frame2, scale), right, datalists);
	new ResizeObserver(() => previewSize(preview)).observe(frame2);
	root.hidden = false;
	redraw();
	refreshPreview();
	document.addEventListener('keydown', keys);
	document.addEventListener('paste', onPaste);
	// no tour on a phone: there the builder shows only "needs a bigger screen" instead of itself (builder.css, same width);
	// it is not marked as seen, so it starts on the first opening on a desktop or tablet
	const narrow = window.matchMedia('(max-width: 719px)').matches;
	try { if (!narrow && !localStorage.getItem('tl-bd-tour')) { setTimeout(() => tour(0), 800); } } catch (e) { /* private mode */ }
	state.saved = JSON.stringify(state.build);
	root.addEventListener('focusout', () => setTimeout(adoptSanitized, 0));
	window.addEventListener('focus', () => { if (state.retries && rejectInvalid()) { (state.signed_in ? refreshToken() : Promise.resolve()).then(save); } });
	// unsaved changes: the browser asks whether to really leave the page; if so, they are sent once more in the background (keepalive)
	window.addEventListener('beforeunload', (e) => { if (rejectInvalid() || state.saving) { save(); e.preventDefault(); e.returnValue = ''; } });
	window.addEventListener('pagehide', () => {
		if (!rejectInvalid() || state.conflict) { return; }
		const f = new FormData();
		f.append('_csrf', csrf); f.append('build', JSON.stringify(state.build)); f.append('version', state.version);
		try { fetch(D.urls.save, { method: 'POST', body: f, credentials: 'same-origin', keepalive: true }); } catch (e) { /* over the keepalive limit – the warning has already been shown */ }
	});
})();
