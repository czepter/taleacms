/* Kaleta – page builder. No libraries and no build step.
 *
 * The state is a tree of elements (the same shape PHP cleans and renders: Builder\Build). Every change goes into the history (undo/redo),
 * shortly afterwards it is saved as a draft (action stavba_uloz) and the canvas – the real site page in an iframe – is re-rendered.
 * There is deliberately no second rendering in JavaScript: what is on the canvas is exactly what the visitor will see.
 */
(function () {
	'use strict';

	const T = window.T || ((s) => s);
	const root = document.getElementById('stavitel');
	const D = JSON.parse(document.getElementById('stavitel-data').textContent);
	let csrf = (document.querySelector('input[name="_csrf"]') || {}).value || '';
	const TYPY = Object.fromEntries(D.schema.prvky.map((p) => [p.type, p]));
	const STYLE = D.schema.style;
	const BP = { zaklad: T('Desktop'), tablet: T('Tablet'), mobil: T('Mobile') };
	const ICONS = {
		sekce: '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M3 9h18M3 15h18"/>',
		container: '<rect x="4" y="4" width="16" height="16" rx="3"/><path d="M8 9h8M8 13h5"/>',
		grid: '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>',
		heading: '<path d="M6 4v16M18 4v16M6 12h12"/>',
		text: '<path d="M4 6h16M4 10h16M4 14h16M4 18h10"/>',
		image: '<rect x="3" y="5" width="18" height="14" rx="2"/><circle cx="9" cy="10" r="2"/><path d="m21 17-5-5-9 7"/>',
		tlacitko: '<rect x="3" y="8" width="18" height="8" rx="4"/><path d="M9 12h6"/>',
		list: '<path d="M9 6h11M9 12h11M9 18h11"/><circle cx="4.5" cy="6" r="1"/><circle cx="4.5" cy="12" r="1"/><circle cx="4.5" cy="18" r="1"/>',
		testimonial: '<path d="M7 7h4v4c0 3-2 5-4 6M15 7h4v4c0 3-2 5-4 6"/>',
		faq: '<circle cx="12" cy="12" r="9"/><path d="M9.5 9.5a2.5 2.5 0 1 1 3.5 2.3c-.6.3-1 .9-1 1.6v.6M12 17h.01"/>',
		video: '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m10 9 5 3-5 3z"/>',
		divider: '<path d="M3 12h18"/>',
		kolekce: '<rect x="3" y="4" width="8" height="7" rx="1.5"/><rect x="13" y="4" width="8" height="7" rx="1.5"/><rect x="3" y="13" width="8" height="7" rx="1.5"/><rect x="13" y="13" width="8" height="7" rx="1.5"/><path d="M5.5 8h3M15.5 8h3M5.5 17h3M15.5 17h3"/>',
		logo: '<circle cx="12" cy="12" r="8"/><path d="M9 15V9l3 3 3-3v6"/>',
		menu: '<path d="M4 7h16M4 12h16M4 17h16"/>',
		component: '<path d="M12 3 4 7.5v9L12 21l8-4.5v-9z"/><path d="M4 7.5 12 12l8-4.5M12 12v9"/>',
		form: '<rect x="4" y="3" width="16" height="18" rx="2"/><path d="M8 8h8M8 12h8"/><rect x="8" y="15" width="5" height="3" rx="1"/>',
		company_details: '<rect x="3" y="5" width="18" height="14" rx="2"/><circle cx="9" cy="11" r="2"/><path d="M14 10h4M14 14h4M6 16c.8-1.5 1.8-2 3-2s2.2.5 3 2"/>',
		article: '<rect x="4" y="3" width="16" height="18" rx="2"/><path d="M8 7h8M8 11h8M8 15h5"/>',
		kod: '<path d="m8 8-4 4 4 4M16 8l4 4-4 4M13 6l-2 12"/>',
		blok: '<rect x="4" y="4" width="16" height="16" rx="2"/>',
		icon: '<circle cx="12" cy="12" r="9"/><path d="m8 12.5 3 3 5-6"/>',
		gallery: '<rect x="3" y="3" width="8" height="8" rx="1"/><rect x="13" y="3" width="8" height="8" rx="1"/><rect x="3" y="13" width="8" height="8" rx="1"/><rect x="13" y="13" width="8" height="8" rx="1"/><path d="m13 19 3-3 5 4"/>',
		tabs: '<path d="M3 20V8h6V5h6v3h6v12z"/><path d="M9 8h6"/>',
		carousel: '<rect x="6" y="5" width="12" height="14" rx="2"/><path d="M3 8v8M21 8v8"/>',
		pricing_table: '<rect x="3" y="6" width="5" height="12" rx="1"/><rect x="9.5" y="3" width="5" height="18" rx="1"/><rect x="16" y="6" width="5" height="12" rx="1"/>',
		'pred-po': '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M12 3v18M8 12h-2M18 12h-2"/>',
		hotspots: '<rect x="3" y="5" width="18" height="14" rx="2"/><circle cx="9" cy="10" r="2.5"/><circle cx="15.5" cy="14.5" r="2.5"/>',
		'casova-osa': '<path d="M12 3v18"/><circle cx="12" cy="7" r="2"/><circle cx="12" cy="17" r="2"/><path d="M14 7h6M4 17h6"/>',
		mapa: '<path d="M3 6.5 9 4l6 2.5L21 4v13.5L15 20l-6-2.5L3 20z"/><path d="M9 4v13.5M15 6.5V20"/>',
		breadcrumbs: '<path d="M3 12h4M10 12h4M17 12h4"/><path d="m6 9 2 3-2 3M13 9l2 3-2 3"/>',
		pocitadlo: '<path d="M4 17V7l3 3M11 7h4l-4 10h4M18 7v10"/>',
		progress_bars: '<rect x="3" y="6" width="18" height="4" rx="2"/><rect x="3" y="14" width="18" height="4" rx="2"/><path d="M5 8h10M5 16h6"/>',
		star: '<path d="m12 3.5 2.6 5.4 5.9.8-4.3 4.1 1 5.8L12 16.8l-5.2 2.8 1-5.8-4.3-4.1 5.9-.8z"/>',
		countdown: '<circle cx="12" cy="13" r="8"/><path d="M12 9v4l2.5 2M9 2.5h6"/>',
		social_links: '<circle cx="6" cy="12" r="2.5"/><circle cx="18" cy="6" r="2.5"/><circle cx="18" cy="18" r="2.5"/><path d="m8.2 10.8 7.6-3.6M8.2 13.2l7.6 3.6"/>',
		hledat: '<circle cx="11" cy="11" r="6.5"/><path d="m20 20-4.4-4.4"/>',
		'nahoru-prvek': '<circle cx="12" cy="12" r="9"/><path d="M12 16V8M8.5 11.5 12 8l3.5 3.5"/>',
		kosik: '<path d="M3 5h2l2.2 10.2a1.5 1.5 0 0 0 1.5 1.2h8.6a1.5 1.5 0 0 0 1.5-1.1L21 8H6.2"/><circle cx="9.5" cy="20" r="1.2"/><circle cx="17" cy="20" r="1.2"/>',
		globe: '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"/>',
		newsletter_signup: '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/><path d="M16 15h3"/>',
		placeholder: '<circle cx="12" cy="12" r="9"/><path d="M9.5 9.5a2.5 2.5 0 1 1 3.5 2.3c-.6.3-1 .9-1 1.6v.6M12 17h.01"/>',
		presun: '<path d="M12 3v18M3 12h18M12 3l-3 3M12 3l3 3M12 21l-3-3M12 21l3-3M3 12l3-3M3 12l3 3M21 12l-3-3M21 12l-3 3"/>',
		nahoru: '<path d="m6 15 6-6 6 6"/>', down: '<path d="m6 9 6 6 6-6"/>', rodic: '<path d="M9 14 4 9l5-5M4 9h11a5 5 0 0 1 0 10h-2"/>',
		knihovna: '<path d="M4 5h4v14H4zM10 5h4v14h-4z"/><path d="m16 6 3.5-1 3 13.5-3.5 1z"/>',
		kopie: '<rect x="8" y="8" width="12" height="12" rx="2"/><path d="M16 8V6a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h2"/>',
		smazat: '<path d="M4 7h16M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3"/>',
		zpet: '<path d="M9 14 4 9l5-5M4 9h11a5 5 0 0 1 0 10h-3"/>', vpred: '<path d="m15 14 5-5-5-5M20 9H9a5 5 0 0 0 0 10h3"/>',
		pocitac: '<rect x="3" y="4" width="18" height="12" rx="2"/><path d="M8 20h8M12 16v4"/>', tablet: '<rect x="5" y="3" width="14" height="18" rx="2"/><path d="M11 18h2"/>',
		mobil: '<rect x="7" y="3" width="10" height="18" rx="2"/><path d="M11 18h2"/>', verze: '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
		zamek: '<rect x="5" y="11" width="14" height="9" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/>', odemceno: '<rect x="5" y="11" width="14" height="9" rx="2"/><path d="M8 11V8a4 4 0 0 1 7.5-2"/>',
		skryto: '<path d="M3 3l18 18M10.6 5.1A10 10 0 0 1 12 5c6.5 0 10 7 10 7a17 17 0 0 1-3.2 4M6.6 6.6C3.7 8.4 2 12 2 12s3.5 7 10 7a9.6 9.6 0 0 0 4.4-1"/>',
		vice: '<circle cx="5" cy="12" r="1.2"/><circle cx="12" cy="12" r="1.2"/><circle cx="19" cy="12" r="1.2"/>',
		oko: '<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>', zavrit: '<path d="M6 6l12 12M18 6 6 18"/>',
		sdilet: '<path d="M10 14a4.5 4.5 0 0 0 6.4 0l3.2-3.2a4.5 4.5 0 0 0-6.4-6.4L12 5.6"/><path d="M14 10a4.5 4.5 0 0 0-6.4 0l-3.2 3.2a4.5 4.5 0 0 0 6.4 6.4l1.2-1.2"/>',
		komentar: '<path d="M4 5h16v11H9l-5 4z"/><path d="M8 9h8M8 12h5"/>',
		schranka: '<rect x="6" y="5" width="12" height="16" rx="2"/><path d="M9 5a3 3 0 0 1 6 0"/><path d="M9 12h6M9 16h4"/>',
	};

	const state = {
		stavba: D.build && Array.isArray(D.build.children) ? D.build : { v: 1, children: [] },
		vybrane: null, bp: 'zaklad', stavPrvku: '', levo: 'pridat', pravo: 'obsah', zpet: [], vpred: [], posledniKlic: null, posledniCas: 0,
		zmeny: !!D.zmeny, uklada: false, hidden: {}, casovac: null, verze: D.verze || '', ulozeno: '', pokusy: 0, signed_in: false, konflikt: false, vycistena: null, chyby: {}, sbalene: {}, trida: null, tazeny: null, tazeno: null, upravaNaPlatne: false, umistovani: null, lupa: '', embed: null,
	};

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
	const icon = (name) => el('span', { html: '<svg class="st-ikona" viewBox="0 0 24 24" aria-hidden="true">' + (ICONS[name] || ICONS.blok) + '</svg>' }).firstChild;
	const clone = (o) => JSON.parse(JSON.stringify(o));
	const newId = () => Math.random().toString(16).slice(2, 9).padEnd(7, '0');
	const text = (html) => { const d = document.createElement('div'); d.innerHTML = html || ''; return d.textContent.trim(); };

	/**
	 * A request to the admin. Never ends with an exception: returns the response JSON, or {ok: false, chyba, …} –
	 * sit (the connection failed), prihlaseni (a sign-in page came instead of JSON, or the form token expired).
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
			.catch(() => ({ ok: false, sit: true, error: T('Could not connect to the server.') }));
	}
	/** After a new sign-in (another tab) the session has a new form token – the editor fetches it. */
	function refreshToken() {
		return fetch(D.adresy.admin + '?action=token', { credentials: 'same-origin' }).then((r) => r.json()).then((j) => { if (j.csrf) { csrf = j.csrf; return true; } return false; }).catch(() => false);
	}

	function confirmAction(message, button) {
		return new Promise((done) => {
			const d = el('dialog', { class: 'st-dialog' },
				el('div', {}, el('p', {}, message)),
				el('footer', {}, el('button', { type: 'button', class: 'st-tl', onclick: () => { d.close(); done(false); } }, T('Cancel')),
					el('button', { type: 'button', class: 'st-tl st-tl-hlavni', onclick: () => { d.close(); done(true); } }, button || T('Continue'))));
			d.addEventListener('close', () => d.remove());
			document.body.append(d);
			d.showModal();
		});
	}

	/* ---------- tree ---------- */

	function find(id, children = state.stavba.children, parent = null) {
		for (let i = 0; i < children.length; i++) {
			if (children[i].id === id) { return { p: children[i], pole: children, i, rodic: parent }; }
			if (children[i].children) { const n = find(id, children[i].children, children[i]); if (n) { return n; } }
		}
		return null;
	}
	/** The element's position in the tree as the validator writes it in error keys: deti[0].deti[2]. */
	function elementPath(id, children = state.stavba.children, path = 'children') {
		for (let i = 0; i < children.length; i++) {
			const c = path + '[' + i + ']';
			if (children[i].id === id) { return c; }
			if (children[i].children) { const n = elementPath(id, children[i].children, c + '.children'); if (n) { return n; } }
		}
		return null;
	}
	/** The element by the position from an error key (the deepest element present on the path). */
	function elementByPath(key) {
		let children = state.stavba.children;
		let found = null;
		for (const m of key.matchAll(/deti\[(\d+)\]/g)) {
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
		for (const [k, def] of Object.entries(s.vlastnosti || {})) { content[k] = clone(def.vychozi ?? ''); }
		const style = s.vychozi_styl && Object.keys(s.vychozi_styl).length ? clone(s.vychozi_styl) : {};
		// a container can have default contents ("Výpis kolekce", Collection list: a card template with {{nazev}} and {{url}})
		return Object.assign({ id: newId(), type, tag: s.znacky[0], content, style }, s.container ? { children: (s.vychozi_deti || []).map(withNewIds) } : {});
	}

	function labelText(p) {
		if (p.popis) { return p.popis; }
		const s = TYPY[p.type] || { nazev: p.type };
		const excerpt = p.type === 'heading' ? text(p.obsah.text) : p.type === 'tlacitko' ? p.obsah.text : p.type === 'text' ? text(p.obsah.html) : p.type === 'testimonial' ? p.obsah.autor : '';
		return excerpt ? s.nazev + ': ' + excerpt.slice(0, 40) : s.nazev;
	}

	/* ---------- changes, history, saving ---------- */

	function applyChange(fn, key) {
		const now = Date.now();
		// typing into one field is merged in the history (otherwise Undo would go back letter by letter)
		if (!key || key !== state.posledniKlic || now - state.posledniCas > 1500) {
			state.zpet.push(JSON.stringify(state.stavba));
			if (state.zpet.length > 150) { state.zpet.shift(); }
			state.vpred = [];
		}
		state.posledniKlic = key || null;
		state.posledniCas = now;
		fn();
		state.zmeny = true;
		scheduleSave();
		if (!key) { redraw(); } else { redrawTree(); redrawBar(); }
	}
	function back() { if (!state.zpet.length) { return; } state.vpred.push(JSON.stringify(state.stavba)); state.stavba = JSON.parse(state.zpet.pop()); state.posledniKlic = null; state.zmeny = true; scheduleSave(); redraw(); }
	function forward() { if (!state.vpred.length) { return; } state.zpet.push(JSON.stringify(state.stavba)); state.stavba = JSON.parse(state.vpred.pop()); state.posledniKlic = null; state.zmeny = true; scheduleSave(); redraw(); }

	function scheduleSave(after) {
		clearTimeout(state.casovac);
		if (!state.pokusy) { setState(T('Unsaved…')); }
		state.casovac = setTimeout(save, after || 600);
	}
	const rejectInvalid = () => JSON.stringify(state.stavba) !== state.ulozeno;

	/*
	 * Saving goes through a queue: two never run at once and every call of save() returns a promise that resolves when the
	 * build state from the moment of the call is saved (true), or saving failed (false). Publish relies on this – it publishes
	 * only what the server really has. A failed save retries by itself; a conflict with someone else's change is handled by a dialog.
	 */
	let queue = Promise.resolve(true), pending = null;
	function save() {
		clearTimeout(state.casovac);
		state.casovac = null;
		if (pending) { return pending; } // a save that will take the current state is already queued
		pending = queue.then(() => { pending = null; return saveNow(); });
		queue = pending;
		return pending;
	}
	function saveNow() {
		if (state.konflikt) { return Promise.resolve(false); }
		const sent = JSON.stringify(state.stavba);
		if (sent === state.ulozeno) {
			if (!state.pokusy) { setState(T('Draft saved')); } // a change that changed nothing (e.g. leaving a field)
			return Promise.resolve(true);
		}
		state.uklada = true;
		setState(T('Saving…'));
		return query(D.adresy.uloz, { build: sent, verze: state.verze }).then((j) => {
			state.uklada = false;
			if (!j.ok) { return saveError(j); }
			state.pokusy = 0;
			state.signed_in = false;
			state.ulozeno = sent;
			state.verze = j.verze || state.verze;
			state.chyby = j.chyby || {};
			// the server cleaned the tree (dropped invalid values) – it is taken over only when nothing changed meanwhile and the user is not typing
			state.vycistena = JSON.stringify(j.build) !== sent ? { sent, stavba: j.build } : null;
			adoptSanitized();
			state.zmeny = j.zmeny;
			const count = Object.keys(state.chyby).length;
			if (!rejectInvalid()) { setState(count ? T('Saved, but with warnings: ') + count : T('Draft saved'), count > 0); }
			redrawBar();
			if (rejectInvalid()) { scheduleSave(300); } else { refreshPreview(); }
			return true;
		});
	}
	function saveError(j) {
		if (j.konflikt) { state.konflikt = true; conflictDialog(j); return false; }
		if (j.status === 400 || j.status === 403 || j.status === 404) { setState(j.error || T('Saving failed.'), true); return false; }
		// network, expired sign-in, server error: the changes stay in the editor and the save is retried
		state.pokusy++;
		state.signed_in = !!j.signed_in;
		const after = Math.min(30, 3 * state.pokusy);
		setState(j.error + ' ' + T('Changes are not saved yet, retrying in %s s.').replace('%s', after), true, j.signed_in ? { adresa: D.adresy.admin, text: T('Přihlásit se') } : null);
		clearTimeout(state.casovac);
		state.casovac = setTimeout(() => (state.signed_in ? refreshToken() : Promise.resolve()).then(save), after * 1000);
		return false;
	}
	function adoptSanitized() {
		const v = state.vycistena;
		if (!v || state.upravaNaPlatne) { return; }
		const a = document.activeElement;
		if (a && root.contains(a) && a.matches('input, textarea, select, [contenteditable]')) { return; } // only after leaving the field (focusout)
		state.vycistena = null;
		if (JSON.stringify(state.stavba) !== v.odeslano) { return; }
		state.stavba = v.stavba;
		state.ulozeno = JSON.stringify(v.stavba);
		redrawPanels();
	}
	function conflictDialog(j) {
		setState(j.error, true);
		const d = el('dialog', { class: 'st-dialog' },
			el('div', {}, el('h2', {}, T('Concurrent edit')), el('p', {}, j.error),
				el('p', {}, T('Load the newer version (your changes since the last save will be lost – they stay in Undo), or overwrite it with yours.'))),
			el('footer', {},
				el('button', { type: 'button', class: 'st-tl', onclick: () => {
					d.close();
					state.zpet.push(JSON.stringify(state.stavba));
					state.stavba = j.build; state.ulozeno = JSON.stringify(j.build); state.verze = j.verze; state.konflikt = false; state.vybrane = null; state.zmeny = true;
					setState(T('Newer version loaded')); redraw(); refreshPreview();
				} }, T('Load newer')),
				el('button', { type: 'button', class: 'st-tl st-tl-hlavni', onclick: () => {
					d.close();
					state.konflikt = false; state.verze = j.verze; // the next save is based on the version on the server, so it overwrites it
					save();
				} }, T('Overwrite with mine'))));
		d.addEventListener('cancel', (e) => e.preventDefault());
		d.addEventListener('close', () => d.remove());
		document.body.append(d);
		d.showModal();
	}

	/* ---------- canvas: the real page in an iframe, swapped without flicker ---------- */

	let frame2, preview, previewPending = false, scale;
	const WIDTHS = { zaklad: 1280, tablet: 820, mobil: 390 };

	/** The iframe has the device width (desktop 1280 px) and is scaled down to fit – so the site's real breakpoints apply on the canvas. */
	function previewSize(frame) {
		if (!frame || !frame2) { return; }
		const w = frame2.clientWidth;
		const h = frame2.clientHeight;
		// preview: a wide monitor (1920 px) only for desktop; zoom is fixed, otherwise the canvas fits the window
		const width = state.bp === 'zaklad' ? (state.lupa === '1920' ? 1920 : Math.max(w, WIDTHS.zaklad)) : WIDTHS[state.bp];
		const m = /^\d+$/.test(state.lupa) && state.lupa !== '1920' ? Number(state.lupa) / 100 : Math.min(1, w / width);
		frame2.classList.toggle('st-ramec-posun', m * width > w + 1);
		frame.style.width = width + 'px';
		frame.style.height = Math.round(h / m) + 'px';
		frame.style.left = Math.max(0, Math.round((w - width * m) / 2)) + 'px';
		frame.style.transform = m < 1 ? 'scale(' + m + ')' : '';
		if (scale) { scale.textContent = width + ' px' + (m < 1 ? ' · ' + Math.round(m * 100) + ' %' : ''); }
	}
	function refreshPreview() {
		if (state.upravaNaPlatne) { return; }
		if (previewPending) { previewPending = 'znovu'; return; }
		previewPending = true;
		const fresh = el('iframe', { class: 'st-nacita', title: T('Page preview'), src: D.nahled + '&t=' + Date.now() });
		previewSize(fresh);
		fresh.addEventListener('load', () => {
			const offset = preview && preview.contentWindow ? preview.contentWindow.scrollY : 0;
			try { fresh.contentWindow.scrollTo(0, offset); } catch (e) { /* nothing */ }
			preparePreview(fresh);
			if (preview) { preview.remove(); }
			fresh.classList.remove('st-nacita');
			preview = fresh;
			markInPreview(false);
			const retry = previewPending === 'znovu';
			previewPending = false;
			if (retry) { refreshPreview(); }
		});
		frame2.append(fresh);
	}

	function preparePreview(frame) {
		const doc = frame.contentDocument;
		if (!doc) { return; }
		doc.head.append(Object.assign(doc.createElement('style'), { textContent:
			'[data-ka-id]{cursor:default} .ka-st-hover{outline:1px dashed #ff4f2e!important;outline-offset:-1px} .ka-st-vybrany{outline:2px solid #ff4f2e!important;outline-offset:-2px}'
			+ '[contenteditable]{outline:2px solid #f79009!important;outline-offset:2px;cursor:text} .ka-upravit-zde,.cookies-lista,.cookies-znovu{display:none!important}' }));
		doc.addEventListener('click', (e) => {
			if (e.target.closest('[contenteditable]') || e.target.id === 'ka-st-uchyt') { return; }
			e.preventDefault();
			e.stopPropagation(); // page scripts do not run on the canvas (player, popups, sharing) – a click only selects
			if (state.umistovani) {
				// move by tapping (touch and mouse): the element lands before, after or inside the tapped element depending on where it was tapped
				state.tazeno = state.umistovani;
				const place = canvasSpot(doc, e);
				state.tazeno = null;
				showSpot(doc, null);
				if (place) { const what = state.umistovani; endPlacing(); dropAt(what, place); }
				return;
			}
			// a locked element ("Struktura → zámek", Structure → lock) cannot be selected on the canvas: the nearest unlocked ancestor gets the selection
			let t = e.target.closest('[data-ka-id]');
			while (t && t.hasAttribute('data-ka-zamek')) { t = t.parentElement && t.parentElement.closest('[data-ka-id]'); }
			selection(t ? t.getAttribute('data-ka-id') : null);
		}, true);
		hiddenOnCanvas(doc);
		doc.addEventListener('pointermove', (e) => {
			if (!state.umistovani) { return; }
			state.tazeno = state.umistovani;
			showSpot(doc, canvasSpot(doc, e));
			state.tazeno = null;
		});
		doc.addEventListener('mouseover', (e) => {
			doc.querySelectorAll('.ka-st-hover').forEach((x) => x.classList.remove('ka-st-hover'));
			const t = e.target.closest('[data-ka-id]');
			if (t) { t.classList.add('ka-st-hover'); }
		});
		doc.addEventListener('dblclick', (e) => { const t = e.target.closest('[data-ka-id]'); if (t && !t.hasAttribute('data-ka-zamek')) { editOnCanvas(t); } });
		doc.addEventListener('keydown', keys);
		doc.addEventListener('paste', onPaste);
		doc.addEventListener('dragover', (e) => {
			if (!state.tazeno) { return; }
			const place = canvasSpot(doc, e);
			showSpot(doc, place);
			if (place) { e.preventDefault(); e.dataTransfer.dropEffect = state.tazeno.presun ? 'move' : 'copy'; }
		});
		doc.addEventListener('dragleave', (e) => { if (!e.relatedTarget) { showSpot(doc, null); } });
		doc.addEventListener('drop', (e) => {
			const place = state.tazeno && canvasSpot(doc, e);
			showSpot(doc, null);
			if (!place) { return; }
			e.preventDefault();
			dropAt(state.tazeno, place);
			state.tazeno = null;
		});
	}

	/** Elements hidden only in the editor (the eye in Structure) – they stay on the site; the state is not saved. */
	function hiddenOnCanvas(doc) {
		doc = doc || (preview && preview.contentDocument);
		if (!doc) { return; }
		let st = doc.getElementById('ka-st-skryte');
		if (!st) { st = Object.assign(doc.createElement('style'), { id: 'ka-st-skryte' }); doc.head.append(st); }
		st.textContent = Object.keys(state.hidden).filter((id) => state.hidden[id]).map((id) => '[data-ka-id="' + id + '"]{display:none!important}').join('');
	}

	/* ---------- dragging on the canvas: a new element, a ready-made section or moving the selected element ---------- */

	function startDrag(e, what) {
		hideSectionPreview();
		state.tazeno = what;
		e.dataTransfer.effectAllowed = what.presun ? 'move' : 'copy';
		e.dataTransfer.setData('text/plain', 'kaleta');
	}

	/** Move by tapping – a replacement for dragging on touch devices: select an element, tap „Přesunout“ (Move) and then the place. */
	function startPlacing(id) {
		if (!id) { return; }
		state.umistovani = { presun: id };
		document.body.classList.add('st-umistovani');
		setState(T('Tap the place on the page to move the element to (top or bottom part of an element = before or after, middle of a container = inside). Esc cancels.'));
	}

	function endPlacing() {
		state.umistovani = null;
		document.body.classList.remove('st-umistovani');
		setState('');
	}

	function endDrag() {
		state.tazeno = null;
		const doc = preview && preview.contentDocument;
		if (doc) { showSpot(doc, null); }
	}

	/**
	 * Where the element would land: {cil: id, kam: 'pred' | 'za' | 'dovnitr'}, or {koren: true} on an empty page. Sections only between sections;
	 * inside a container when the pointer is in its middle part (or it is empty); never a move into itself.
	 */
	function canvasSpot(doc, e) {
		const type = state.tazeno.novy || (state.tazeno.sekce ? 'sekce' : (find(state.tazeno.presun) || { p: {} }).p.type);
		let node = e.target.closest ? e.target.closest('[data-ka-id]') : null;
		while (node && !find(node.getAttribute('data-ka-id'))) { node = node.parentElement && node.parentElement.closest('[data-ka-id]'); }
		if (!node) { return state.stavba.children.length ? null : { koren: true }; }
		let n = find(node.getAttribute('data-ka-id'));
		if (type === 'sekce' || type === 'obsah') {
			while (n.rodic) { n = find(n.rodic.id); }
			node = doc.querySelector('[data-ka-id="' + n.p.id + '"]') || node;
		}
		const moving = state.tazeno.presun && find(state.tazeno.presun);
		if (moving && (moving.p.id === n.p.id || contains(moving.p, n.p.id))) { return null; }
		const r = node.getBoundingClientRect();
		const y = (e.clientY - r.top) / Math.max(1, r.height);
		const inside = type !== 'sekce' && type !== 'obsah' && TYPY[n.p.type] && TYPY[n.p.type].container && (!n.p.children.length || (y > 0.25 && y < 0.75));
		return { target: n.p.id, kam: inside ? 'dovnitr' : (y < 0.5 ? 'pred' : 'za'), node };
	}

	/** A blue line (before / after) or a frame (inside) on the canvas. */
	function showSpot(doc, place) {
		let htmlTag = doc.getElementById('ka-st-misto');
		if (!place || !place.uzel) { if (htmlTag) { htmlTag.hidden = true; } return; }
		if (!htmlTag) {
			htmlTag = Object.assign(doc.createElement('div'), { id: 'ka-st-misto' });
			htmlTag.style.cssText = 'position:absolute;z-index:2147483646;pointer-events:none;border-radius:3px';
			doc.body.append(htmlTag);
		}
		const r = place.uzel.getBoundingClientRect();
		const x = r.left + doc.defaultView.scrollX;
		const y = r.top + doc.defaultView.scrollY;
		htmlTag.hidden = false;
		Object.assign(htmlTag.style, place.kam === 'dovnitr'
			? { left: x + 'px', top: y + 'px', width: r.width + 'px', height: r.height + 'px', background: 'rgb(255 79 46 / 0.08)', outline: '2px dashed #ff4f2e' }
			: { left: x + 'px', top: (place.kam === 'pred' ? y - 2 : y + r.height - 2) + 'px', width: r.width + 'px', height: '4px', background: '#ff4f2e', outline: 'none' });
	}

	function dropAt(what, place) {
		if (what.presun) {
			const n = find(what.presun);
			const target = !place.koren && find(place.target);
			if (!n || !target) { return; }
			if (place.kam !== 'dovnitr' && !target.rodic && n.p.type !== 'sekce' && n.p.type !== 'obsah') {
				// an element moved between sections gets its own section
				applyChange(() => {
					n.pole.splice(n.i, 1);
					const c = find(place.target);
					c.pole.splice(c.i + (place.kam === 'za' ? 1 : 0), 0, Object.assign(newElement('sekce'), { children: [n.p] }));
				});
			} else {
				move(what.presun, place.target, place.kam);
			}
			selection(what.presun);
			redrawPanels();
			return;
		}
		const embedUrl = (element) => {
			// a lone element between sections gets its own section (as when inserted by tapping)
			const target = place.koren ? null : find(place.target);
			const sectionGap = place.koren || (place.kam !== 'dovnitr' && !target.rodic);
			const inserting = sectionGap && element.type !== 'sekce' && element.type !== 'obsah' ? Object.assign(newElement('sekce'), { children: [element] }) : element;
			applyChange(() => {
				if (place.koren) { state.stavba.children.push(inserting); return; }
				const c = find(place.target);
				if (place.kam === 'dovnitr') { c.p.children.push(inserting); } else { c.pole.splice(c.i + (place.kam === 'za' ? 1 : 0), 0, inserting); }
			});
			selection(element.id);
			redrawPanels();
		};
		if (what.novy) { embedUrl(newElement(what.novy)); return; }
		if (what.vlastni) { embedUrl(withNewIds(what.vlastni)); return; }
		query(D.adresy.sekce + '&key=' + encodeURIComponent(what.sekce), { ok: 1 }).then((j) => {
			if (!j.ok) { setState(j.error, true); return; }
			D.classes = j.classes;
			embedUrl(j.prvek);
		});
	}

	function markInPreview(shift) {
		const doc = preview && preview.contentDocument;
		if (!doc) { return; }
		doc.querySelectorAll('.ka-st-vybrany').forEach((x) => x.classList.remove('ka-st-vybrany'));
		const t = state.vybrane && doc.querySelector('[data-ka-id="' + state.vybrane + '"]');
		let handle = doc.getElementById('ka-st-uchyt');
		if (t) {
			t.classList.add('ka-st-vybrany');
			if (shift) { t.scrollIntoView({ block: 'nearest', behavior: 'smooth' }); }
			// handle at the top left: dragging it moves the selected element elsewhere on the page
			if (!handle) {
				handle = Object.assign(doc.createElement('div'), { id: 'ka-st-uchyt', draggable: true, title: T('Drag or tap to move') });
				handle.textContent = '⠿';
				handle.style.cssText = 'position:absolute;z-index:2147483647;display:grid;place-items:center;width:22px;height:22px;border-radius:4px;background:#ff4f2e;color:#fff;font:14px/1 system-ui;cursor:grab;user-select:none';
				handle.addEventListener('dragstart', (e) => startDrag(e, { presun: state.vybrane }));
				handle.addEventListener('dragend', endDrag);
				handle.addEventListener('click', (e) => { e.stopPropagation(); e.preventDefault(); startPlacing(state.vybrane); }, true);
				doc.body.append(handle);
			}
			// a component without its own wrapper has display: contents – it has no box, the outline is drawn around its content
			handle.style.display = t.hasAttribute('data-ka-zamek') ? 'none' : ''; // a locked element is not dragged
			let r = t.getBoundingClientRect();
			let outline = doc.getElementById('ka-st-obrys');
			if (doc.defaultView.getComputedStyle(t).display === 'contents') {
				const scope = doc.createRange();
				scope.selectNodeContents(t);
				r = scope.getBoundingClientRect();
				if (!outline) {
					outline = Object.assign(doc.createElement('div'), { id: 'ka-st-obrys' });
					outline.style.cssText = 'position:absolute;z-index:2147483646;pointer-events:none;outline:2px solid #ff4f2e;outline-offset:2px';
					doc.body.append(outline);
				}
				Object.assign(outline.style, { left: r.left + doc.defaultView.scrollX + 'px', top: r.top + doc.defaultView.scrollY + 'px', width: r.width + 'px', height: r.height + 'px' });
				outline.hidden = false;
			} else if (outline) {
				outline.hidden = true;
			}
			handle.hidden = false;
			handle.style.left = Math.max(0, r.left + doc.defaultView.scrollX) + 'px';
			handle.style.top = Math.max(0, r.top + doc.defaultView.scrollY - 24) + 'px';
		} else if (handle) {
			handle.hidden = true;
			const outline = doc.getElementById('ka-st-obrys');
			if (outline) { outline.hidden = true; }
		}
	}

	/** Double-click on a heading, text, button or reference: typing right on the canvas. */
	function editOnCanvas(node) {
		const n = find(node.getAttribute('data-ka-id'));
		if (!n || !['heading', 'text', 'tlacitko', 'testimonial'].includes(n.p.type)) { return; }
		// in a collection the canvas shows the item's substituted value – editing would overwrite the {{placeholder}}; the text is changed in the Content panel
		if (elementCollection(n.p.id) && JSON.stringify(n.p.obsah).includes('{{')) { selection(n.p.id); setState(T('Edit text with collection {{tags}} in the Content panel.')); return; }
		const target = n.p.type === 'testimonial' ? node.querySelector('p') : node;
		if (!target) { return; }
		state.upravaNaPlatne = true;
		target.contentEditable = n.p.type === 'tlacitko' ? 'plaintext-only' : 'true';
		target.focus();
		const done = () => {
			target.removeEventListener('blur', done);
			target.removeAttribute('contenteditable');
			state.upravaNaPlatne = false;
			const field = { heading: 'text', text: 'html', tlacitko: 'text', testimonial: 'text' }[n.p.type];
			const value = n.p.type === 'tlacitko' ? target.textContent.trim() : target.innerHTML.trim();
			if (value !== n.p.obsah[field]) { applyChange(() => { n.p.obsah[field] = value; }); } else { refreshPreview(); }
		};
		target.addEventListener('blur', done);
		target.addEventListener('keydown', (e) => { if (e.key === 'Escape' || (e.key === 'Enter' && n.p.type !== 'text' && !e.shiftKey)) { e.preventDefault(); target.blur(); } });
	}

	/* ---------- selection and tree edits ---------- */

	function selection(id) {
		state.vybrane = id && find(id) ? id : null;
		state.trida = null;
		state.posledniKlic = null;
		redrawPanels();
		markInPreview(true);
	}

	function insert(element) {
		const v = state.vybrane && find(state.vybrane);
		applyChange(() => {
			if (!v && element.type !== 'sekce' && element.type !== 'obsah') {
				// sections are at the top level: a lone element gets its own section (page content in an envelope has its own wrapper)
				const section = newElement('sekce');
				section.children.push(element);
				state.stavba.children.push(section);
			} else if (v && TYPY[v.p.type].container && element.type !== 'sekce') {
				v.p.children.push(element);
			} else if (v) {
				if (element.type === 'sekce' || element.type === 'obsah') {
					// a section belongs at the top level – after the section containing the selected element
					let upper = v; while (upper.rodic) { upper = find(upper.rodic.id); }
					upper.pole.splice(upper.i + 1, 0, element);
				} else { v.pole.splice(v.i + 1, 0, element); }
			} else { state.stavba.children.push(element); }
			state.vybrane = element.id;
		});
		state.pravo = 'obsah';
		redrawPanels();
	}

	function remove(id) {
		const n = find(id);
		if (!n) { return; }
		applyChange(() => { n.pole.splice(n.i, 1); state.vybrane = n.rodic ? n.rodic.id : (n.pole[n.i] || n.pole[n.i - 1] || {}).id || null; });
	}
	function duplicate(id) {
		const n = find(id);
		if (!n) { return; }
		const copied = withNewIds(n.p);
		applyChange(() => { n.pole.splice(n.i + 1, 0, copied); state.vybrane = copied.id; });
	}
	function offset(id, direction) {
		const n = find(id);
		if (!n || n.i + direction < 0 || n.i + direction >= n.pole.length) { return; }
		applyChange(() => { n.pole.splice(n.i, 1); n.pole.splice(n.i + direction, 0, n.p); });
	}
	function move(id, targetId, destination) {
		const n = find(id);
		const c = find(targetId);
		if (!n || !c || id === targetId || contains(n.p, targetId)) { return; }
		applyChange(() => {
			n.pole.splice(n.i, 1);
			const target = find(targetId);
			if (destination === 'dovnitr') { target.p.children.push(n.p); } else { target.pole.splice(target.i + (destination === 'za' ? 1 : 0), 0, n.p); }
		});
	}

	/* ---------- clipboard (also between pages and between Kaleta sites) ---------- */

	// Within one site the copy lives in localStorage. For another Kaleta site the same element goes as text into the system
	// clipboard: an envelope {"kaleta":"elements",…} with its classes and components (Builder\ElementClipboard), which the
	// paste event of the other builder recognises and sends to its server.
	const CLIPBOARD_FORMAT = 'elements';
	function envelope(elements) {
		return query(D.adresy.balicek, { prvky: JSON.stringify(elements) })
			.then((j) => JSON.stringify(j.ok ? j.schranka : { kaleta: CLIPBOARD_FORMAT, v: 1, site: location.origin, elements, classes: [], components: [] }));
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
		const n = state.vybrane && find(state.vybrane);
		if (!n) { return; }
		try { localStorage.setItem('ka-stavitel-schranka', JSON.stringify(n.p)); } catch (e) { /* private mode */ }
		setState(T('Copied'));
		writeClipboard(envelope([n.p])).catch(() => setState(T('Copied within this site – for another Kaleta site use More actions → Copy for another Kaleta site.')));
	}
	/** The copy from this site (localStorage). */
	function pasteFromClipboard() {
		let p = null;
		try { p = JSON.parse(localStorage.getItem('ka-stavitel-schranka') || 'null'); } catch (e) { p = null; }
		if (p && TYPY[p.type]) { insert(withNewIds(p)); return true; }
		return false;
	}
	/** Text from the system clipboard: an envelope from this or another Kaleta site; anything else is not for the editor. */
	function pasteText(text) {
		let data = null;
		try { data = JSON.parse(text); } catch (e) { return false; }
		if (!data || data.kaleta !== CLIPBOARD_FORMAT || !Array.isArray(data.elements) || !data.elements.length) { return false; }
		if (data.site === location.origin) { insertAll(data.elements.filter((p) => p && TYPY[p.type]).map(withNewIds)); return true; }
		setState(T('Inserting elements from another site…'));
		query(D.adresy.embed, { schranka: text }).then((j) => {
			if (!j.ok) { setState(j.error || T('The elements could not be inserted.'), true); return; }
			D.classes = j.classes;
			setComponents(j.komponenty);
			insertAll(j.prvky);
			const notes = j.hlaseni || [];
			setState([T('Inserted from %s.').replace('%s', data.site)].concat(notes).join(' '), notes.length > 0);
		});
		return true;
	}
	/** The first element goes where a new element goes (after the selection or into the container), the others follow it. */
	function insertAll(elements) {
		if (!elements.length) { return; }
		insert(elements[0]);
		elements.slice(1).forEach((p) => {
			const previous = state.vybrane && find(state.vybrane);
			if (!previous) { insert(p); return; }
			applyChange(() => { previous.pole.splice(previous.i + 1, 0, p); state.vybrane = p.id; });
		});
		redrawPanels();
	}
	/** Ctrl+V: the paste event brings the system clipboard; when none comes (nothing in it), the copy from this site is used. */
	function onPaste(e) {
		const v = e.target;
		if ((v && (v.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(v.tagName))) || document.querySelector('dialog[open]')) { return; }
		clearTimeout(state.embed);
		const text = e.clipboardData ? e.clipboardData.getData('text/plain') : '';
		if (pasteText(text)) { e.preventDefault(); return; }
		pasteFromClipboard();
	}
	function copyDialog(id) {
		const n = find(id);
		if (!n) { return; }
		const area = el('textarea', { rows: 8, readonly: true, 'aria-label': T('Elements as text') });
		area.value = T('Preparing…');
		const copyButton = el('button', { type: 'button', class: 'st-tl st-tl-hlavni', disabled: true, onclick: () => {
			area.focus(); area.select();
			(navigator.clipboard ? navigator.clipboard.writeText(area.value) : Promise.reject()).then(() => { copyButton.textContent = T('Copied'); }, () => { document.execCommand('copy'); copyButton.textContent = T('Copied'); });
		} }, T('Copy'));
		envelope([n.p]).then((text) => { area.value = text; copyButton.disabled = false; area.focus(); area.select(); });
		const d = el('dialog', { class: 'st-dialog' },
			el('div', {}, el('h2', {}, T('Copy for another Kaleta site')),
				el('p', {}, T('The text carries the element with its classes and components. In the builder of the other site press Ctrl+V, or choose More actions → Paste from another Kaleta site.')), area),
			el('footer', {}, el('button', { type: 'button', class: 'st-tl', onclick: () => d.close() }, T('Close')), copyButton));
		d.addEventListener('close', () => d.remove());
		document.body.append(d);
		d.showModal();
	}
	function pasteDialog() {
		const area = el('textarea', { rows: 8, placeholder: '{"kaleta":"elements", …}', 'aria-label': T('Elements as text') });
		const note = el('p', { class: 'st-sdilet-chyba', hidden: true });
		const d = el('dialog', { class: 'st-dialog' },
			el('div', {}, el('h2', {}, T('Paste from another Kaleta site')),
				el('p', {}, T('Paste the text the builder of the other site copied (Ctrl+C on an element, or More actions → Copy for another Kaleta site).')), area, note),
			el('footer', {}, el('button', { type: 'button', class: 'st-tl', onclick: () => d.close() }, T('Close')),
				el('button', { type: 'button', class: 'st-tl st-tl-hlavni', onclick: () => {
					if (pasteText(area.value.trim())) { d.close(); return; }
					note.hidden = false;
					note.textContent = T('The text is not a copy of Kaleta elements.');
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
		if (typing || document.querySelector('dialog[open], .st-vice:popover-open')) { return; } // in an open dialog or menu the keys (Esc) belong to them
		// selected text is copied as text, not as an element
		const marked = String(window.getSelection() || '') || (preview && preview.contentWindow ? String(preview.contentWindow.getSelection() || '') : '');
		if (mod && e.key.toLowerCase() === 'c' && marked) { return; }
		if (mod && e.key.toLowerCase() === 'z') { e.preventDefault(); if (e.shiftKey) { forward(); } else { back(); } }
		else if (mod && e.key.toLowerCase() === 'y') { e.preventDefault(); forward(); }
		else if (mod && e.key.toLowerCase() === 'd' && state.vybrane) { e.preventDefault(); duplicate(state.vybrane); }
		else if (mod && e.key.toLowerCase() === 'c' && state.vybrane) { copy(); }
		else if (mod && e.key.toLowerCase() === 'v') { clearTimeout(state.embed); state.embed = setTimeout(pasteFromClipboard, 250); } // the paste event (onPaste) comes first when the system clipboard has text
		else if ((e.key === 'Delete' || e.key === 'Backspace') && state.vybrane) { e.preventDefault(); remove(state.vybrane); }
		else if (e.key === '?' && !mod) { e.preventDefault(); hint(); }
		else if (e.key === 'Escape' && state.umistovani) { const doc = preview && preview.contentDocument; if (doc) { showSpot(doc, null); } endPlacing(); }
		else if (e.key === 'Escape' && state.vybrane) { const n = find(state.vybrane); selection(n && n.rodic ? n.rodic.id : null); }
	}

	/* ---------- top bar ---------- */

	let tabList, stateText;
	function setState(message, error, link) {
		if (!stateText) { return; }
		stateText.replaceChildren(message, link ? el('a', { href: link.adresa, target: '_blank', rel: 'noopener' }, ' ' + link.text) : '');
		stateText.classList.toggle('chyba', !!error);
	}

	function createBar() {
		stateText = el('span', { class: 'st-stav', role: 'status' }, state.zmeny ? T('Draft in progress') : T('Publikováno'));
		tabList = el('header', { class: 'st-lista' });
		root.append(tabList);
		redrawBar();
	}
	let barLook = '';
	function redrawBar() {
		// the bar is rebuilt only when what it shows changes – re-rendering under the cursor would "swallow" a click in progress
		const look = [state.zmeny, state.bp, state.lupa, state.zpet.length > 0, state.vpred.length > 0, D.page.publikovana].join();
		if (look === barLook && tabList.childElementCount) { return; }
		barLook = look;
		const bpTl = Object.entries({ zaklad: 'pocitac', tablet: 'tablet', mobil: 'mobil' }).map(([bp, ik]) =>
			el('button', { type: 'button', title: BP[bp], 'aria-label': BP[bp], 'aria-pressed': String(state.bp === bp), onclick: () => { state.bp = bp; frame2.dataset.bp = bp; previewSize(preview); redrawBar(); redrawPanels(); } }, icon(ik)));
		tabList.replaceChildren(...[
			el('a', { class: 'st-tl', href: D.zpet.adresa, title: D.zpet.text }, icon('rodic'), el('span', { class: 'st-text' }, D.zpet.text)),
			el('div', { class: 'st-nazev' }, el('h1', {}, D.page.title), el('small', {}, state.zmeny ? T('draft in progress – visitors see the published version') : T('no changes against the live site'))),
			el('div', { class: 'st-skupina', role: 'group', 'aria-label': T('Zařízení') }, bpTl),
			el('select', { class: 'st-lupa', 'aria-label': T('Preview size'), title: T('Preview size'), onchange: (e) => { state.lupa = e.target.value; previewSize(preview); } },
				[['', T('Fit')], ['1920', T('Wide monitor (1920 px)')], ['100', '100 %'], ['75', '75 %'], ['50', '50 %']].map(([k, n]) => el('option', { value: k, selected: state.lupa === k }, n))),
			el('div', { class: 'st-skupina', role: 'group', 'aria-label': T('History') },
				el('button', { type: 'button', title: T('Undo (Ctrl+Z)'), disabled: !state.zpet.length, onclick: back }, icon('zpet')),
				el('button', { type: 'button', title: T('Redo (Ctrl+Shift+Z)'), disabled: !state.vpred.length, onclick: forward }, icon('vpred'))),
			stateText,
			el('button', { type: 'button', class: 'st-tl', title: T('Published versions'), onclick: versionsDialog }, icon('verze'), el('span', { class: 'st-text' }, T('Versions'))),
			D.adresy.sdilet ? el('button', { type: 'button', class: 'st-tl', title: T('Share a link to the draft preview'), onclick: shareDialog }, icon('sdilet'), el('span', { class: 'st-text' }, T('Share'))) : null,
			D.komentare ? el('button', { type: 'button', class: 'st-tl', title: T('Comments from people with a preview link'), onclick: commentsDialog }, icon('komentar'),
				el('span', { class: 'st-text' }, T('Comments') + (openComments().length ? ' (' + openComments().length + ')' : ''))) : null,
			el('a', { class: 'st-tl', href: D.page.adresa, target: '_blank', rel: 'noopener', title: T('Open the published page') }, icon('oko')),
			el('button', { type: 'button', class: 'st-tl', title: T('Help and keyboard shortcuts (?)'), 'aria-label': T('Help'), onclick: hint }, icon('placeholder')),
			D.page.publikovana && state.zmeny ? el('button', { type: 'button', class: 'st-tl', onclick: discard }, T('Discard changes')) : null,
			el('button', { type: 'button', class: 'st-tl st-tl-hlavni', disabled: (!state.zmeny && D.page.publikovana) || D.page.smiPublikovat === false,
				title: D.page.smiPublikovat === false ? T('Only an editor or administrator can publish. Your changes stay saved as a draft.') : null, onclick: publishAfterCheck }, T('Publish')),
		].filter(Boolean));
	}

	/** What the page lacks for a visitor or a search engine: buttons without a link, images without a file or description, the heading outline. */
	function check() {
		const findings = [];
		const headings = [];
		const tags = (x) => typeof x === 'string' && x.includes('{{');
		(function walk(children, inComponent) {
			children.forEach((p) => {
				const o = p.obsah || {};
				if (p.type === 'tlacitko' && (!o.link || o.link === '#')) { findings.push([p.id, T('The button “%s” leads nowhere – add a link.').replace('%s', o.text || '')]); }
				if (p.type === 'image' && !o.src) { findings.push([p.id, T('No image selected – it will not appear on the site.')]); }
				if (p.type === 'image' && o.src && !o.alt && !tags(o.src)) { findings.push([p.id, T('The image has no description for blind visitors (alt).')]); }
				const level = p.type === 'heading' && /^h([1-6])$/.exec(p.tag || 'h2'); // a heading with the p tag (big number, label) is not in the outline
				if (level) { headings.push([p.id, Number(level[1]), text(o.text)]); }
				if (p.children) { walk(p.children, inComponent); }
			});
		})(state.stavba.children);
		findings.push(...contrastCheck());
		if (D.page.nadpisy) {
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
		Array.from(doc.querySelectorAll('[data-ka-id]')).slice(0, 400).forEach((node) => {
			const text = Array.from(node.childNodes).some((n) => n.nodeType === 3 && n.textContent.trim()) ? node : node.querySelector('h1,h2,h3,h4,p,li,a,span,strong');
			if (!text || !text.textContent.trim()) { return; }
			const id = node.getAttribute('data-ka-id');
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

	/** Help: keyboard shortcuts, the builder guide on kaletacms.com and starting the editor tour. */
	function hint() {
		const mod = /Mac|iPhone|iPad/.test(navigator.platform) ? '⌘' : 'Ctrl';
		const shortcuts = [[mod + '+S', T('Save draft')], [mod + '+Z / ' + mod + '+Shift+Z', T('Undo / redo')], [mod + '+D', T('Duplicate the selected element')],
			[mod + '+C / ' + mod + '+V', T('Copy and paste an element (also between pages and Kaleta sites)')], ['Delete', T('Delete the selected element')], ['Esc', T('Select the parent element / cancel moving')],
			[T('double-click'), T('Edit text right on the canvas')], ['↑ ↓ ← →', T('Move within Structure')], ['?', T('This help')]];
		const d = el('dialog', { class: 'st-dialog' },
			el('div', {}, el('h2', {}, T('Keyboard shortcuts')),
				el('dl', { class: 'st-zkratky' }, shortcuts.flatMap(([k, t]) => [el('dt', {}, el('kbd', {}, k)), el('dd', {}, t)]))),
			el('footer', {}, D.adresy.navod ? el('a', { class: 'st-tl', href: D.adresy.navod, target: '_blank', rel: 'noopener' }, T('Builder guide')) : null,
				el('button', { type: 'button', class: 'st-tl', onclick: () => { d.close(); tour(0); } }, T('Editor tour')),
				el('button', { type: 'button', class: 'st-tl st-tl-hlavni', onclick: () => d.close() }, T('Close'))));
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
		document.querySelectorAll('.st-zvyraznene').forEach((x) => x.classList.remove('st-zvyraznene'));
		try { localStorage.setItem('ka-st-prohlidka', '1'); } catch (e) { /* private mode */ }
		if (step >= stops.length) { return; }
		const [target, heading, text] = stops[step];
		target.classList.add('st-zvyraznene');
		const d = el('dialog', { class: 'st-dialog st-prohlidka' },
			el('div', {}, el('small', {}, (step + 1) + ' / ' + stops.length), el('h2', {}, heading), el('p', {}, text)),
			el('footer', {}, el('button', { type: 'button', class: 'st-tl', onclick: () => { d.close(); tour(stops.length); } }, T('Skip')),
				el('button', { type: 'button', class: 'st-tl st-tl-hlavni', onclick: () => { d.close(); tour(step + 1); } }, step + 1 < stops.length ? T('Next') : T('Done'))));
		d.addEventListener('close', () => d.remove());
		document.body.append(d);
		d.show();
	}

	/** Before publishing shows the check findings; publishing is possible anyway (warnings only). */
	function publishAfterCheck() {
		const findings = check();
		if (!findings.length) { publish(); return; }
		const d = el('dialog', { class: 'st-dialog' },
			el('div', {}, el('h2', {}, T('Pre-publish check')), el('p', {}, T('We found a few things on the page worth fixing:')),
				el('ul', { class: 'st-kontrola' }, findings.slice(0, 12).map(([id, message]) => el('li', {}, id ? el('button', { type: 'button', class: 'st-odkaz', onclick: () => { d.close(); selection(id); } }, message) : message)))),
			el('footer', {}, el('button', { type: 'button', class: 'st-tl', onclick: () => d.close() }, T('Back to editing')),
				el('button', { type: 'button', class: 'st-tl st-tl-hlavni', onclick: () => { d.close(); publish(); } }, T('Publish anyway'))));
		d.addEventListener('close', () => d.remove());
		document.body.append(d);
		d.showModal();
	}
	function publish() {
		save().then((ok) => {
			if (!ok) { return null; } // saving has already shown the message; nothing older gets published
			setState(T('Publishing…'));
			return query(D.adresy.publikuj, { ok: 1, verze: state.verze });
		}).then((j) => {
			if (!j) { return; }
			if (!j.ok) { if (j.konflikt) { state.konflikt = true; conflictDialog(j); } else { setState(j.error || T('Publishing failed.'), true); } return; }
			state.zmeny = rejectInvalid();
			D.page.publikovana = true;
			setState(D.page.zobrazena ? T('Published – changes are live') : T('Published (the page is still hidden – make it public in the page settings)'));
			redrawBar();
		});
	}
	function discard() {
		confirmAction(T('Discard all changes since the last publish? This cannot be undone.'), T('Discard')).then((yes) => {
			if (!yes) { return; }
			// a scheduled save would recreate the draft after discarding; a running one is left to finish
			stopSaving().then(() => query(D.adresy.zahod, { ok: 1 })).then((j) => {
				if (!j.ok) { setState(j.error, true); return; }
				state.stavba = j.build; state.ulozeno = JSON.stringify(j.build); state.verze = j.verze; state.konflikt = false;
				state.zpet = []; state.vpred = []; state.zmeny = false; state.vybrane = null;
				setState(T('Changes discarded')); redraw(); refreshPreview();
			});
		});
	}
	/** Cancels a scheduled save and waits until the one already running finishes (returns a promise). */
	function stopSaving() {
		clearTimeout(state.casovac);
		state.casovac = null;
		return queue;
	}
	function versionsDialog() {
		query(D.adresy.revize).then((j) => {
			if (j.ok === false) { setState(j.error, true); return; }
			const list = el('ul');
			(j.revize || []).forEach((r) => list.append(el('li', {}, el('span', {}, r.kdy, r.kdo ? ' · ' + r.kdo : ''),
				el('button', { type: 'button', class: 'st-tl', onclick: () => { d.close(); stopSaving().then(() => query(D.adresy.obnov, { idr: r.idr })).then((o) => {
					if (!o.ok) { setState(o.error, true); return; }
					state.zpet.push(JSON.stringify(state.stavba)); state.stavba = o.build; state.ulozeno = JSON.stringify(o.build); state.verze = o.verze; state.konflikt = false;
					state.zmeny = true; state.vybrane = null;
					setState(T('The older version is in the draft – publish it when ready')); redraw(); refreshPreview();
				}); } }, T('Load into draft')))));
			const d = el('dialog', { class: 'st-dialog' }, el('div', {}, el('h2', {}, T('Published versions')), j.revize && j.revize.length ? list : el('p', { class: 'st-prazdno' }, T('No older versions yet.'))),
				el('footer', {}, el('button', { type: 'button', class: 'st-tl', onclick: () => d.close() }, T('Close'))));
			d.addEventListener('close', () => d.remove());
			document.body.append(d);
			d.showModal();
		});
	}

	/** Comments from shared previews (2.15): the unresolved ones first; a click on the element scrolls the canvas to it, one click resolves. */
	function openComments() { return (D.komentare || []).filter((c) => !c.vyrizeno); }
	function commentsDialog() {
		const list = el('ul', { class: 'st-komentare' });
		const d = el('dialog', { class: 'st-dialog' }, el('div', {}, el('h2', {}, T('Comments on the draft')),
			el('p', { class: 'st-sdilet-pozn' }, T('Written by people who opened a preview link that allows comments. Feedback to act on in the draft – nothing publishes by itself.')), list),
			el('footer', {}, el('button', { type: 'button', class: 'st-tl', onclick: () => d.close() }, T('Close'))));
		const render = () => {
			list.replaceChildren(...(D.komentare || []).map((c) => el('li', { class: c.vyrizeno ? 'st-komentar-vyrizeny' : null },
				el('div', { class: 'st-komentar-hlava' }, el('strong', {}, c.jmeno), ' · ', c.kdy, c.vyrizeno ? ' · ' + T('resolved') : ''),
				c.citace ? el('blockquote', {}, c.citace) : null,
				el('p', {}, c.text),
				el('div', { class: 'st-komentar-akce' },
					c.prvek && find(c.prvek) ? el('button', { type: 'button', class: 'st-odkaz', onclick: () => { d.close(); selection(c.prvek); } }, T('Show the element')) : null,
					!c.vyrizeno ? el('button', { type: 'button', class: 'st-tl', onclick: (e) => { e.target.disabled = true; query(D.adresy.komentarVyrizen, { id: c.id }).then((j) => {
						if (!j.ok) { e.target.disabled = false; setState(j.error, true); return; }
						D.komentare = j.komentare || []; render(); redrawBar();
					}); } }, T('Resolve')) : null))));
			if (!(D.komentare || []).length) { list.replaceChildren(el('li', { class: 'st-prazdno' }, T('No comments yet. Share a preview link with comments allowed.'))); }
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
		const result = el('div', { class: 'st-sdilet', 'aria-live': 'polite' });
		// comments (2.15): the visitor with the link can click an element and write what they think; the flag is signed into the key
		const comments = D.komentare ? el('input', { type: 'checkbox' }) : null;
		const create = el('button', { type: 'button', class: 'st-tl st-tl-hlavni', onclick: () => {
			create.disabled = true;
			// the link shows what the server has – unsaved changes are saved first
			save().then((ok) => ok ? query(D.adresy.sdilet, { days: days.value, komentare: comments && comments.checked ? '1' : '0' }) : { ok: false, error: T('The draft could not be saved.') }).then((j) => {
				create.disabled = false;
				if (!j.ok) { result.replaceChildren(el('p', { class: 'st-sdilet-chyba' }, j.error || T('The link could not be created.'))); return; }
				const field = el('input', { type: 'text', readonly: true, value: j.link, 'aria-label': T('Preview link'), onfocus: (e) => e.target.select() });
				const copy = el('button', { type: 'button', class: 'st-tl', onclick: () => {
					field.select();
					(navigator.clipboard ? navigator.clipboard.writeText(j.link) : Promise.reject()).then(() => { copy.textContent = T('Copied'); }, () => { document.execCommand('copy'); copy.textContent = T('Copied'); });
				} }, T('Copy'));
				const isValid = new Date(j.plati_do * 1000).toLocaleString(document.documentElement.lang === 'en' ? 'en-GB' : document.documentElement.lang || undefined, { dateStyle: 'medium', timeStyle: 'short' });
				result.replaceChildren(el('div', { class: 'st-sdilet-radek' }, field, copy), el('p', { class: 'st-sdilet-pozn' }, T('Valid until %s.').replace('%s', isValid)));
				field.focus();
			});
		} }, T('Create link'));
		const d = el('dialog', { class: 'st-dialog' },
			el('div', {}, el('h2', {}, T('Share preview')),
				el('p', {}, T('Anyone with the link can see the draft without signing in – including changes you make later. Search engines do not index it.')),
				el('label', { class: 'st-sdilet-radek' }, el('span', {}, T('Platnost')), days),
				comments ? el('label', { class: 'st-sdilet-radek' }, comments, el('span', {}, T('Allow comments – whoever opens the link can click an element and write a note with their name'))) : null, result),
			el('footer', {}, el('button', { type: 'button', class: 'st-tl', onclick: () => d.close() }, T('Close')), create));
		d.addEventListener('close', () => d.remove());
		document.body.append(d);
		d.showModal();
	}

	/* ---------- left panel: Add and Structure ---------- */

	let left, leftContent, right;
	function redrawLeft() {
		const tabItem = (key, name) => el('button', { type: 'button', role: 'tab', 'aria-selected': String(state.levo === key), onclick: () => { state.levo = key; redrawLeft(); } }, name);
		leftContent = el('div', { class: 'st-panel' });
		left.replaceChildren(el('div', { class: 'st-zalozky', role: 'tablist' }, tabItem('pridat', T('Přidat')), tabItem('struktura', T('Structure'))), leftContent);
		if (state.levo === 'pridat') { addPanel(); } else { redrawTree(); }
	}

	/** AI: a new section from a description – inserted after the selected section (or at the end) as a normal change, can be undone. */
	function aiSection() {
		const field = el('textarea', { rows: 5, placeholder: T('E.g.: Three cards with our services – kitchens, wardrobes, staircases. A short description and a contact link for each.') });
		const d = el('dialog', { class: 'st-dialog' }, el('div', {}, el('h2', {}, T('Create a section with AI')),
			el('label', { class: 'st-pole' }, el('span', {}, T('What should the section contain?')), field),
			el('p', { class: 'st-prazdno', style: 'text-align:left;padding:0' }, T('The assistant drafts texts and layout in your site\'s style. Check the result – fill in facts (numbers, prices, names) yourself.'))),
		el('footer', {}, el('button', { type: 'button', class: 'st-tl', onclick: () => d.close() }, T('Cancel')),
			el('button', { type: 'button', class: 'st-tl st-tl-hlavni', onclick: () => {
				const prompt = field.value.trim();
				if (!prompt) { field.focus(); return; }
				d.close();
				setState(T('The assistant is drafting a section…'));
				query(D.adresy.aiSekce, { zadani: prompt }).then((j) => {
					if (!j.ok) { setState(j.error || T('The assistant did not respond.'), true); return; }
					D.classes = j.classes;
					const v = state.vybrane && find(state.vybrane);
					let upper = v; while (upper && upper.rodic) { upper = find(upper.rodic.id); }
					applyChange(() => { state.stavba.children.splice(upper ? upper.i + 1 : state.stavba.children.length, 0, ...j.prvky); });
					selection(j.prvky[0].id);
					redrawPanels();
					setState(j.hlaseni && j.hlaseni.length ? T('Section inserted. Notes: ') + j.hlaseni.join(' ') : T('Section inserted – check the texts.'));
				});
			} }, T('Create'))));
		d.addEventListener('close', () => d.remove());
		document.body.append(d);
		d.showModal();
		field.focus();
	}

	/** AI: rewriting an element's text (shorter, longer…) – the result is a normal change, Undo reverts it. */
	function aiRewrites(p) {
		const key = { heading: 'text', text: 'html', tlacitko: 'text', testimonial: 'text' }[p.type];
		if (!D.ai || !key || !(p.obsah[key] || '').trim() || String(p.obsah[key]).includes('{{')) { return null; }
		const instructions = [['kratsi', T('shorter')], ['delsi', T('longer')], ['formalne', T('more formal')], ['pratelsky', T('friendlier')], ['oprava', T('fix mistakes')]];
		return el('div', { class: 'st-ai' }, el('span', {}, '✨ ' + T('Rewrite with AI:')), el('div', {}, instructions.map(([instruction, name]) => el('button', { type: 'button', onclick: (e) => {
			e.target.disabled = true;
			setState(T('The assistant is rewriting the text…'));
			query(D.adresy.aiText, { text: p.obsah[key], pokyn: instruction, html: key === 'html' ? '1' : '0' }).then((j) => {
				e.target.disabled = false;
				if (!j.ok) { setState(j.error || T('The assistant did not respond.'), true); return; }
				applyChange(() => { p.obsah[key] = j.text; });
				redrawRight();
				setState(T('Text rewritten – Ctrl+Z undoes it.'));
			});
		} }, name))));
	}

	function addPanel() {
		if (D.ai) { leftContent.append(el('button', { type: 'button', class: 'st-tl st-ai-sekce', onclick: aiSection }, '✨ ' + T('Create a section with AI'))); }
		// one search for elements, my sections and ready-made sections
		const searchBox = el('input', { type: 'search', class: 'st-hledat', placeholder: T('Search elements or sections…'), 'aria-label': T('Search elements or sections'), oninput: (e) => render(e.target.value) });
		const content = el('div', {});
		leftContent.append(searchBox, content);
		const groups = {};
		D.schema.prvky.forEach((p) => { (groups[p.skupina] = groups[p.skupina] || []).push(p); });
		const render = (search) => {
			const q = search.trim().toLowerCase();
			const matches = (...texts) => !q || texts.join(' ').toLowerCase().includes(q);
			content.replaceChildren();
			for (const [name, elements] of Object.entries(groups)) {
				const selected = elements.filter((p) => matches(p.nazev, p.popis, T(name)));
				if (!selected.length) { continue; }
				content.append(el('h3', {}, T(name)), el('div', { class: 'st-prvky' }, selected.map((p) =>
					el('button', { type: 'button', title: p.popis, draggable: 'true', onclick: () => insert(newElement(p.type)),
						ondragstart: (e) => startDrag(e, { novy: p.type }), ondragend: endDrag }, icon(p.icon), p.nazev))));
			}
			const mine = (D.mojeSekce || []).filter((m) => matches(m.nazev));
			if (mine.length) {
				content.append(el('h3', {}, T('My sections')), el('div', { class: 'st-knihovna' }, mine.map((m) => el('span', { class: 'st-moje-sekce' },
					el('button', { type: 'button', draggable: 'true', onclick: () => insert(withNewIds(m.prvek)), ondragstart: (e) => startDrag(e, { vlastni: m.prvek }), ondragend: endDrag }, el('strong', {}, m.nazev)),
					D.adresy.smazSekci ? el('button', { type: 'button', class: 'st-odebrat', title: T('Remove from my sections'), 'aria-label': T('Remove from my sections') + ': ' + m.nazev, onclick: () => confirmAction(T('Remove the section “%s” from my sections? It stays on pages where it is already used.').replace('%s', m.nazev), T('Odebrat')).then((yes) => {
						if (yes) { query(D.adresy.smazSekci, { idx: m.id }).then((j) => { if (j.ok) { D.mojeSekce = j.sekce; redrawLeft(); } else { setState(j.error, true); } }); }
					}) }, '×') : null))));
			}
			libraryFilter(q);
		};
		const library = el('div', {});
		let libraryFilter = () => {};
		// ready-made sections by category; search filters by name and description
		libraryFilter = (q) => {
			const blocks = Object.entries(D.kategorieKnihovny || { obsah: '' }).map(([category, categoryName]) => {
				const section = D.knihovna.filter((s) => (s.kategorie || 'obsah') === category && (!q || (s.nazev + ' ' + s.popis).toLowerCase().includes(q)));
				return section.length ? el('div', {}, el('h3', {}, categoryName), el('div', { class: 'st-knihovna' }, section.map(sectionButton))) : null;
			}).filter(Boolean);
			library.replaceChildren(...(blocks.length ? [el('h3', { class: 'st-nadpis-knihovny' }, T('Ready-made sections')), ...blocks] : []));
			content.append(library);
			if (!content.querySelector('button')) { content.append(el('p', { class: 'st-prazdno' }, T('Nothing like that here.'))); }
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
				previewSection = el('div', { class: 'st-nahled-sekce', 'aria-hidden': 'true' }, el('iframe', { tabindex: '-1', title: '' }));
				document.body.append(previewSection);
			}
			const r = button.getBoundingClientRect();
			const iframe = previewSection.firstChild;
			if (iframe.dataset.key !== key) { iframe.dataset.key = key; iframe.src = D.adresy.nahledSekce + encodeURIComponent(key); }
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
			el('button', { onmouseenter: (e) => showSectionPreview(e.currentTarget, s.key), onmouseleave: hideSectionPreview, onfocus: (e) => showSectionPreview(e.currentTarget, s.key), onblur: hideSectionPreview, type: 'button', draggable: 'true', ondragstart: (e) => startDrag(e, { sekce: s.key }), ondragend: endDrag, onclick: () => query(D.adresy.sekce + '&key=' + encodeURIComponent(s.key), { ok: 1 }).then((j) => {
				if (!j.ok) { setState(j.error, true); return; }
				D.classes = j.classes;
				insert(j.prvek);
			}) }, el('strong', {}, s.nazev), el('small', {}, s.popis)));
	}

	function redrawTree() {
		if (state.levo !== 'struktura' || !leftContent) { return; }
		const node = (p) => {
			const s = TYPY[p.type] || { nazev: p.type, icon: 'blok' };
			const hasChildren = p.children && p.children.length;
			const row = el('div', {
				class: 'st-uzel' + (state.hidden[p.id] ? ' st-skryty' : ''), draggable: p.zamek ? null : 'true', role: 'treeitem', 'aria-selected': String(state.vybrane === p.id),
				'aria-expanded': hasChildren ? String(!state.sbalene[p.id]) : null, tabindex: state.vybrane === p.id || (!state.vybrane && state.stavba.children[0] === p) ? '0' : '-1',
				'data-id': p.id, onkeydown: (e) => treeKeys(e, p, hasChildren),
				onclick: () => {
					const u = state.umistovani && find(state.umistovani.presun);
					if (!u) { selection(p.id); return; }
					// move by tapping in Structure: inside an empty container, otherwise after the tapped element
					if (u.p.id === p.id || contains(u.p, p.id)) { return; }
					const what = state.umistovani;
					endPlacing();
					dropAt(what, { target: p.id, kam: TYPY[p.type] && TYPY[p.type].container && !hasChildren ? 'dovnitr' : 'za' });
				},
				onmouseenter: () => { const t = preview && preview.contentDocument && preview.contentDocument.querySelector('[data-ka-id="' + p.id + '"]'); if (t) { t.classList.add('ka-st-hover'); } },
				onmouseleave: () => { const t = preview && preview.contentDocument && preview.contentDocument.querySelector('[data-ka-id="' + p.id + '"]'); if (t) { t.classList.remove('ka-st-hover'); } },
				ondragstart: (e) => { state.tazeny = p.id; e.dataTransfer.effectAllowed = 'move'; e.dataTransfer.setData('text/plain', p.id); },
				ondragover: (e) => {
					if (!state.tazeny || state.tazeny === p.id) { return; }
					e.preventDefault();
					const r = row.getBoundingClientRect();
					const y = (e.clientY - r.top) / r.height;
					const destination = s.container && y > 0.25 && y < 0.75 ? 'dovnitr' : (y < 0.5 ? 'pred' : 'za');
					row.classList.remove('cil-pred', 'cil-za', 'cil-dovnitr');
					row.classList.add('cil-' + destination);
					row.dataset.kam = destination;
				},
				ondragleave: () => row.classList.remove('cil-pred', 'cil-za', 'cil-dovnitr'),
				ondrop: (e) => { e.preventDefault(); row.classList.remove('cil-pred', 'cil-za', 'cil-dovnitr'); if (state.tazeny) { move(state.tazeny, p.id, row.dataset.kam || 'za'); } state.tazeny = null; },
				ondragend: () => { state.tazeny = null; },
			},
			hasChildren ? el('button', { type: 'button', class: 'st-sbalit', 'aria-label': T('Collapse / expand'), onclick: (e) => { e.stopPropagation(); state.sbalene[p.id] = !state.sbalene[p.id]; redrawTree(); } }, state.sbalene[p.id] ? '▸' : '▾') : el('span', { style: 'width:16px;flex:none' }),
			icon(s.icon), el('span', {}, labelText(p)), el('small', {}, p.tag),
			el('span', { class: 'st-uzel-akce' },
				el('button', { type: 'button', title: T('Hide in the editor only (stays on the site)'), 'aria-label': T('Hide in the editor only (stays on the site)'), 'aria-pressed': String(!!state.hidden[p.id]), tabindex: '-1',
					onclick: (e) => { e.stopPropagation(); state.hidden[p.id] = !state.hidden[p.id]; hiddenOnCanvas(); redrawTree(); } }, icon(state.hidden[p.id] ? 'skryto' : 'oko')),
				el('button', { type: 'button', title: T('Lock: cannot be selected or moved on the canvas'), 'aria-label': T('Lock: cannot be selected or moved on the canvas'), 'aria-pressed': String(!!p.zamek), tabindex: '-1',
					onclick: (e) => { e.stopPropagation(); applyChange(() => { if (p.zamek) { delete p.zamek; } else { p.zamek = true; } }); } }, icon(p.zamek ? 'zamek' : 'odemceno'))));
			return el('li', { role: 'none' }, row, hasChildren && !state.sbalene[p.id] ? el('ul', { role: 'group' }, p.children.map(node)) : null);
		};
		leftContent.replaceChildren(state.stavba.children.length
			? el('ul', { class: 'st-strom', role: 'tree' }, state.stavba.children.map(node))
			: el('p', { class: 'st-prazdno' }, T('The page is empty. Add a section from the Add panel.')));
	}

	/** The tree from the keyboard (ARIA tree pattern): up/down arrows between visible nodes, right/left expand, collapse or jump to the parent. */
	function treeKeys(e, p, hasChildren) {
		const nodes = Array.from(leftContent.querySelectorAll('.st-uzel'));
		const i = nodes.indexOf(e.currentTarget);
		const focusTarget = (u) => { if (u) { nodes.forEach((x) => { x.tabIndex = -1; }); u.tabIndex = 0; u.focus(); } };
		if (e.key === 'ArrowDown') { e.preventDefault(); focusTarget(nodes[i + 1]); }
		else if (e.key === 'ArrowUp') { e.preventDefault(); focusTarget(nodes[i - 1]); }
		else if (e.key === 'Home') { e.preventDefault(); focusTarget(nodes[0]); }
		else if (e.key === 'End') { e.preventDefault(); focusTarget(nodes[nodes.length - 1]); }
		else if (e.key === 'ArrowRight' && hasChildren) {
			e.preventDefault();
			if (state.sbalene[p.id]) { state.sbalene[p.id] = false; redrawTree(); focusTarget(leftContent.querySelector('[data-id="' + p.id + '"]')); } else { focusTarget(nodes[i + 1]); }
		} else if (e.key === 'ArrowLeft') {
			e.preventDefault();
			const n = find(p.id);
			if (hasChildren && !state.sbalene[p.id]) { state.sbalene[p.id] = true; redrawTree(); focusTarget(leftContent.querySelector('[data-id="' + p.id + '"]')); } else if (n && n.rodic) { focusTarget(leftContent.querySelector('[data-id="' + n.rodic.id + '"]')); }
		} else if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); selection(p.id); }
	}

	/* ---------- right panel: properties of the selected element, or class editing ---------- */

	function redrawPanels() { redrawTree(); redrawRight(); }
	function redraw() { redrawBar(); redrawLeft(); redrawRight(); }

	function redrawRight() {
		if (state.trida !== null) { classesPanel(); return; }
		const n = state.vybrane && find(state.vybrane);
		if (!n) {
			right.replaceChildren(el('div', { class: 'st-panel' }, el('p', { class: 'st-prazdno' }, T('Select an element on the canvas or in the structure. Double-click text to edit it right on the page.')),
				D.adresy.settings ? el('p', { class: 'st-prazdno' }, el('a', { href: D.adresy.settings }, D.textNastaveni || T('Page settings (title, address, SEO)'))) : null));
			return;
		}
		const p = n.p;
		const s = TYPY[p.type];
		const tabItem = (key, name) => el('button', { type: 'button', role: 'tab', 'aria-selected': String(state.pravo === key), onclick: () => { state.pravo = key; redrawRight(); } }, name);
		const panel = el('div', { class: 'st-panel' });
		// errors from the server: at the selected element (at the specific field too), for the others only a count with a link
		const path = state.cestaVybraneho = elementPath(p.id);
		const all = Object.entries(state.chyby);
		const custom = all.filter(([k]) => k === path || k.startsWith(path + '.obsah') || k.startsWith(path + '.style'));
		const elsewhere = all.filter(([k]) => !custom.some(([v]) => v === k));
		if (custom.length || elsewhere.length) {
			panel.append(el('ul', { class: 'st-chyby' }, custom.slice(0, 6).map(([, t]) => el('li', {}, t)),
				elsewhere.length ? el('li', {}, T('Warnings on other elements: ') + elsewhere.length + ' ', el('button', { type: 'button', class: 'st-odkaz', onclick: () => { const x = elementByPath(elsewhere[0][0]); if (x) { selection(x.id); } } }, T('ukázat'))) : null));
		}
		// less frequent actions are in the „Další akce“ (More actions) menu (with a description), so the bar fits the panel even on a laptop
		const more = [
			p.zamek ? null : [icon('presun'), state.umistovani ? T('Cancel move by tapping') : T('Move by tapping the target (works on touch screens too)'), () => (state.umistovani ? endPlacing() : startPlacing(p.id))],
			D.adresy.component && p.type !== 'component' ? [icon('component'), T('Save as component'), () => saveAsComponent(p.id)] : null,
			D.adresy.ulozSekci ? [icon('knihovna'), T('Save to my sections (then insert it on any page)'), () => saveToMySections(p.id)] : null,
			[icon('schranka'), T('Copy for another Kaleta site (as text)'), () => copyDialog(p.id)],
			[icon('schranka'), T('Paste from another Kaleta site (as text)'), () => pasteDialog()],
		].filter(Boolean);
		const offer = more.length ? el('div', { id: 'st-vice', class: 'st-vice', popover: 'auto' },
			more.map(([ik, description, action]) => el('button', { type: 'button', onclick: () => { offer.hidePopover(); action(); } }, ik, el('span', {}, description)))) : null;
		const moreButton = offer ? el('button', { type: 'button', title: T('More actions'), 'aria-label': T('More actions'), popovertarget: 'st-vice' }, icon('vice')) : null;
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
			el('div', { class: 'st-hlava-prvku' }, icon(s.icon), el('strong', {}, s.nazev), el('div', { class: 'st-akce' },
				el('button', { type: 'button', title: T('Up'), onclick: () => offset(p.id, -1) }, icon('nahoru')),
				el('button', { type: 'button', title: T('Down'), onclick: () => offset(p.id, 1) }, icon('down')),
				n.rodic ? el('button', { type: 'button', title: T('Select parent element (Esc)'), onclick: () => selection(n.rodic.id) }, icon('rodic')) : null,
				el('button', { type: 'button', title: T('Duplicate (Ctrl+D)'), onclick: () => duplicate(p.id) }, icon('kopie')),
				moreButton, offer,
				el('button', { type: 'button', class: 'nebezpecne', title: T('Delete (Delete)'), onclick: () => remove(p.id) }, icon('smazat')))),
			el('div', { class: 'st-zalozky', role: 'tablist' }, tabItem('obsah', T('Content')), tabItem('style', T('Styl')), tabItem('pokrocile', T('Pokročilé'))),
			panel,
		);
		if (state.pravo === 'obsah') { contentPanel(panel, p, s); } else if (state.pravo === 'style') { stylePanel(panel, p, 'prvek:' + p.id); } else { advancedPanel(panel, p, s); }
	}

	/** The collection whose items the element receives: the nearest parent "Výpis kolekce" (Collection list), otherwise the collection of the detail template. */
	function elementCollection(id) {
		let n = find(id);
		while (n) {
			if (n.p.type === 'kolekce' && n.p.id !== id) { return (D.kolekce || []).find((k) => k.seo_link === n.p.obsah.kolekce) || null; }
			n = n.rodic ? find(n.rodic.id) : null;
		}
		return D.kolekceDetailu || null;
	}

	/** Help for {{pole}} placeholders – a click copies the placeholder. */
	function placeholderHint(collection) {
		const tags = (collection.vestavene === false ? [] : [['nazev', T('Název')], ['url', T('Detail address')], ['datum', T('Date')]]).concat(collection.pole.map((p) => [p.key, p.popisek]));
		if (!tags.length) { return null; }
		return el('div', { class: 'st-znacky' }, el('span', {}, (collection.vestavene === false ? T('Component properties “%s” – insert into text, image or link:') : T('Fields of collection “%s” – insert into text, image or link:')).replace('%s', collection.nazev)),
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
			const headingId = 'st-dialog-' + newId();
			const field = el('input', { type: 'text', maxlength: 100, value: value, required: true });
			let result = null;
			const d = el('dialog', { class: 'st-dialog', 'aria-labelledby': headingId },
				el('form', { method: 'dialog', onsubmit: (e) => {
					if (!field.value.trim()) { e.preventDefault(); field.focus(); return; }
					result = field.value.trim();
				} },
				el('div', {}, el('h2', { id: headingId }, heading),
					el('label', { class: 'st-pole' }, el('span', {}, fieldLabel), field),
					hint ? el('p', { class: 'st-prazdno', style: 'text-align:left;padding:0' }, hint) : null),
				el('footer', {}, el('button', { type: 'button', class: 'st-tl', onclick: () => d.close() }, T('Cancel')),
					el('button', { type: 'submit', class: 'st-tl st-tl-hlavni' }, T('Uložit')))));
			d.addEventListener('close', () => { d.remove(); done(result); });
			document.body.append(d);
			d.showModal();
			field.select();
		});
	}

	/** A copy of the selected element into the own library ("Moje sekce", My sections) – unlike a component, it is edited independently after inserting. */
	function saveToMySections(id) {
		const n = find(id);
		if (!n) { return; }
		askName(T('Save to my sections'), T('Section name'), labelText(n.p),
			T('The section appears in the Add panel → My sections. Each insert is a separate copy; if it should be the same everywhere, save it as a component.')).then((name) => {
			if (!name) { return; }
			query(D.adresy.ulozSekci, { nazev: name, element: JSON.stringify(n.p) }).then((j) => {
				if (!j.ok) { setState(j.error || T('Saving failed.'), true); return; }
				D.mojeSekce = j.sekce;
				setState(T('The section is in the Add panel → My sections.'));
				if (state.levo === 'pridat') { redrawLeft(); }
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
		D.komponenty = list;
		const options = { '': '—' };
		list.forEach((k) => { options[String(k.id)] = k.nazev; });
		if (TYPY.component) { TYPY.component.vlastnosti.component.options = options; }
	}

	function saveComponent(n, name) {
		query(D.adresy.component, { name, element: JSON.stringify(n.p) }).then((j) => {
			if (!j.ok) { setState(j.error || T('Saving failed.'), true); return; }
			setComponents(j.komponenty);
			const usage = { id: newId(), type: 'component', tag: 'div', obsah: { component: String(j.id), values: {} }, style: {} };
			applyChange(() => { n.pole.splice(n.i, 1, usage); state.vybrane = usage.id; });
			redrawPanels();
			setState(T('Component saved – edits in Components apply everywhere it is used.'));
		});
	}

	/** Property values of a component use: fields according to the selected component, empty = default value. */
	function valueField(p) {
		const component = (D.komponenty || []).find((k) => String(k.id) === String(p.obsah.component));
		if (!component) { return null; }
		const wrapper = el('div', { class: 'st-pole' }, el('span', {}, T('Properties')));
		if (!component.vlastnosti.length) {
			wrapper.append(el('p', { class: 'st-prazdno' }, T('The component has no properties – it looks the same everywhere.')));
			return wrapper;
		}
		if (!p.obsah.values || Array.isArray(p.obsah.values)) { p.obsah.values = {}; }
		component.vlastnosti.forEach((v) => {
			const def = { type: v.type === 'radky' || v.type === 'html' ? 'radky' : (v.type === 'image' ? 'image' : 'text'), popisek: v.popisek + ' {{' + v.key + '}}' };
			const inputEl = field(def, p.obsah.values[v.key] || '', (h) => applyChange(() => { p.obsah.values[v.key] = h; }, 'hodnoty:' + p.id + ':' + v.key));
			const input = inputEl.querySelector('input, textarea');
			if (input && v.vychozi) { input.placeholder = v.vychozi; }
			wrapper.append(inputEl);
		});
		return wrapper;
	}

	function contentPanel(panel, p, s) {
		if (p.type === 'component') {
			panel.append(field(s.vlastnosti.component, p.obsah.component, (h) => { applyChange(() => { p.obsah.component = h; p.obsah.values = {}; }); redrawRight(); }));
			const values = valueField(p);
			if (values) { panel.append(values); }
			return;
		}
		const collection = p.type !== 'kolekce' ? elementCollection(p.id) : null;
		const hint = collection ? placeholderHint(collection) : null;
		if (hint) { panel.append(hint); }
		const ai = aiRewrites(p);
		if (ai) { panel.append(ai); }
		const properties = Object.entries(s.vlastnosti || {});
		if (!properties.length) { panel.append(el('p', { class: 'st-prazdno' }, s.container ? T('A container has no content of its own – put elements into it and set its look in the Style tab.') : T('This element has no editable content.'))); return; }
		properties.forEach(([key, def]) => panel.append(field(def, p.obsah[key], (h) => { if (JSON.stringify(p.obsah[key]) !== JSON.stringify(h)) { applyChange(() => { p.obsah[key] = h; }, 'obsah:' + p.id + ':' + key); } },
			{ error: state.chyby[state.cestaVybraneho + '.obsah.' + key], prvek: p })));
	}

	/** A control for a content field according to the type from the schema. */
	function field(def, value, change, options) {
		const description = T(def.popisek || '');
		const error = options && options.error;
		const wrapper = el('label', { class: 'st-pole' + (error ? ' st-pole-chyba' : '') }, el('span', {}, description), error ? el('small', { class: 'st-chyba-pole', role: 'alert' }, error) : null);
		let inputEl;
		switch (def.type) {
			case 'boolean':
				return el('label', { class: 'st-zaskrt' }, el('input', { type: 'checkbox', checked: !!value, onchange: (e) => change(e.target.checked) }), description);
			case 'vyber':
				inputEl = el('select', { onchange: (e) => change(e.target.value) }, Object.entries(def.options).map(([k, v]) => el('option', { value: k, selected: k === String(value) }, T(v))));
				break;
			case 'number':
				inputEl = el('input', { type: 'number', min: def.min ?? 0, max: def.max ?? 100, value: value, oninput: (e) => change(parseInt(e.target.value, 10) || 0) });
				break;
			case 'radky': case 'kod':
				inputEl = el('textarea', { rows: def.type === 'kod' ? 8 : 5, oninput: (e) => change(e.target.value) });
				inputEl.value = value || '';
				break;
			case 'html': {
				const ta = el('textarea', { 'data-editor': 'maly', oninput: (e) => change(e.target.value) });
				ta.value = value || '';
				wrapper.append(ta);
				if (window.kaletaVytvorEditor) { setTimeout(() => window.kaletaVytvorEditor(ta), 0); }
				return wrapper;
			}
			case 'image': {
				const imagePreview = el('img', { class: 'st-obrazek-nahled', alt: '', src: value || null, hidden: !value });
				inputEl = el('input', { type: 'text', value: value || '', placeholder: 'media/…', oninput: (e) => { change(e.target.value); imagePreview.src = e.target.value; imagePreview.hidden = !e.target.value; } });
				const tl = el('button', { type: 'button', class: 'st-tl', onclick: () => window.kaletaVyberObrazek && window.kaletaVyberObrazek((o) => {
					inputEl.value = o.url; imagePreview.src = o.url; imagePreview.hidden = false; change(o.url);
					// the description for blind users from the Media library, when the element has none yet (can be overwritten)
					const p = options && options.prvek;
					if (p && 'alt' in p.obsah && !p.obsah.alt && o.nazev) { applyChange(() => { p.obsah.alt = o.nazev; }); redrawRight(); }
				}) }, T('Media'));
				wrapper.append(el('span', { class: 'st-pole-radek' }, inputEl, tl), imagePreview);
				return wrapper;
			}
			case 'items':
				return itemField(def, Array.isArray(value) ? value : [], change);
			default:
				if (def.media === 'video' || def.media === 'file') { // a file from Media (section background video, a form's gated file): not a link menu, but a file picker
					const video = def.media === 'video';
					inputEl = el('input', { type: 'text', value: value ?? '', placeholder: video ? 'media/…/video.mp4' : 'media/…/file.pdf', oninput: (e) => change(e.target.value) });
					wrapper.append(el('span', { class: 'st-pole-radek' }, inputEl, el('button', { type: 'button', class: 'st-tl', onclick: () => window.kaletaVyberObrazek && window.kaletaVyberObrazek((o) => {
						if (video && !/\.(mp4|webm)$/i.test(o.url || '')) { setState(T('Choose a video in MP4 or WebM format.'), true); return; }
						inputEl.value = o.url; change(o.url);
					}, false, true) }, T('Media'))));
					return wrapper;
				}
				inputEl = el('input', { type: 'text', value: value ?? '', placeholder: def.type === 'link' ? T('site page, https://…, #anchor, mailto:, tel:') : null,
					list: def.type === 'link' ? 'st-dl-odkazy' : null, onfocus: def.type === 'link' ? refreshLinks : null, oninput: (e) => change(e.target.value) });
		}
		wrapper.append(inputEl);
		return wrapper;
	}

	function itemField(def, previous, change) {
		const wrapper = el('div', { class: 'st-pole' }, el('span', {}, T(def.popisek)));
		// works on a copy: every change (adding, removing and moving too) then goes through zmena → history, save, canvas re-render
		const items = clone(Array.isArray(previous) ? previous : []);
		const save = () => change(clone(items));
		items.forEach((item, i) => {
			const box = el('div', { class: 'st-polozka' });
			// a field with the condition „kdyz“ ({pole: hodnota}) shows only when another field of the item has the given value (options only for a select)
			const visible = (d) => !d.kdyz || Object.entries(d.kdyz).every(([pk, pv]) => item[pk] === pv);
			Object.entries(def.pole).forEach(([k, d]) => {
				if (!visible(d)) { return; }
				box.append(field(d, item[k], (h) => {
					item[k] = h;
					save();
					if (Object.values(def.pole).some((other) => other.kdyz && k in other.kdyz)) { redrawRight(); }
				}));
			});
			box.append(el('div', { class: 'st-polozka-akce st-akce' },
				el('button', { type: 'button', title: T('Up'), onclick: () => { if (i > 0) { items.splice(i - 1, 0, items.splice(i, 1)[0]); change(clone(items)); redrawRight(); } } }, icon('nahoru')),
				el('button', { type: 'button', class: 'nebezpecne', title: T('Odebrat'), onclick: () => { items.splice(i, 1); change(clone(items)); redrawRight(); } }, icon('smazat'))));
			wrapper.append(box);
		});
		wrapper.append(el('button', { type: 'button', class: 'st-tl', onclick: () => {
			const newVersion = {};
			Object.entries(def.pole).forEach(([k, d]) => { newVersion[k] = d.vychozi ?? ''; });
			items.push(newVersion);
			change(clone(items));
			redrawRight();
		} }, T('Add item')));
		return wrapper;
	}

	/* ---------- style (of an element and a class) ---------- */

	const HINTS = {
		mezera: ['2xs', 'xs', 's', 'm', 'l', 'xl', '2xl', '3xl', '0'], step: ['-1', '0', '1', '2', '3', '4', '5'], radius: ['0', 's', 'm', 'l', 'full'], shadow: ['s', 'm', 'l', 'none'],
		color: Object.keys(D.schema.tokens.barvy).concat(['transparent']), length: ['auto', '100%', '50%', 'var(--ka-sirka-textu)', 'var(--ka-sirka)', '20rem', '30rem', '60vh', 'fit-content'],
		columns: ['1', '2', '3', '4', 'auto:14rem', 'auto:16rem', 'auto:20rem', '2fr 1fr', '1fr 2fr'], number: ['-1', '0', '1', '2'],
		radky: ['1', '2', '3', 'auto 1fr auto'], border: Object.keys((D.schema.style.border || {}).options || {}).concat(['1px solid linka', '2px dashed primarni']),
		area: [],
	};
	const datalists = el('div', { hidden: true }, Object.entries(HINTS).map(([type, values]) => el('datalist', { id: 'st-dl-' + type }, values.map((h) => el('option', { value: h })))),
		el('datalist', { id: 'st-dl-odkazy' }));
	/** The link field menu: site pages, news and element anchors on this page (up to date on every opening). */
	function refreshLinks() {
		const anchors = [];
		(function walk(children) { children.forEach((p) => { if (p.anchor) { anchors.push(['#' + p.anchor, labelText(p)]); } if (p.children) { walk(p.children); } }); })(state.stavba.children);
		datalists.querySelector('#st-dl-odkazy').replaceChildren(...(D.odkazy || []).concat(anchors).map(([url, name]) => el('option', { value: url, label: name })));
	}

	/** An approximate token color (for the swatch in the panel) – derived shades are mixed the same way as on the site. */
	function tokenColor(h) {
		const b = D.barvy;
		const mapping = { primary: b.primary, secondary: b.secondary, text: b.text, background: b.background, surface: b.surface, white: '#fff', black: '#000',
			'primary-soft': 'color-mix(in oklch, ' + b.primary + ' 12%, ' + b.background + ')', muted: 'color-mix(in oklch, ' + b.text + ' 64%, ' + b.background + ')',
			line: 'color-mix(in oklch, ' + b.text + ' 14%, ' + b.background + ')', 'on-primary': '#fff' };
		return mapping[h] || h;
	}

	/** The edited style state: a breakpoint, or hover/press – separately on tablet and mobile (hover_tablet…). */
	function currentState() { return state.stavPrvku ? state.stavPrvku + (state.bp === 'zaklad' ? '' : '_' + state.bp) : state.bp; }
	const INHERITANCE = { zaklad: [], tablet: ['zaklad'], mobil: ['tablet', 'zaklad'], hover: ['zaklad'], hover_tablet: ['hover', 'tablet', 'zaklad'],
		hover_mobile: ['hover_tablet', 'hover', 'mobil', 'tablet', 'zaklad'], active: ['hover', 'zaklad'], active_tablet: ['active', 'hover_tablet', 'hover', 'tablet', 'zaklad'],
		active_mobile: ['active_tablet', 'active', 'hover_mobile', 'hover_tablet', 'hover', 'mobil', 'tablet', 'zaklad'] };
	function inherited(style, key) {
		for (const st of INHERITANCE[currentState()] || []) { if (style[st] && style[st][key] !== undefined) { return style[st][key]; } }
		return '';
	}

	/** Style panel: $cil is an element ({styl}) or a class record; a change goes through zmen (element) or ulozTridu (class). */
	function stylePanel(panel, target, changeKey, shouldSave) {
		target.style = target.style && !Array.isArray(target.style) ? target.style : {};
		const s = currentState();
		panel.append(el('div', { class: 'st-stav-stylu' },
			el('span', {}, T('Editing: '), el('strong', {}, [{ hover: T('hover and focus'), active: T('press') }[state.stavPrvku], state.stavPrvku && state.bp === 'zaklad' ? '' : BP[state.bp]].filter(Boolean).join(' · '))),
			el('span', { class: 'st-skupina', role: 'group', 'aria-label': T('Element state') }, [['', T('Běžný')], ['hover', T('Najetí')], ['active', T('Press')]].map(([k, n]) =>
				el('button', { type: 'button', class: 'st-tl', 'aria-pressed': String(state.stavPrvku === k), title: k === 'hover' ? T('Mouse hover – also applies to keyboard focus') : null, onclick: () => { state.stavPrvku = k; redrawRight(); } }, n)))));
		// copying only the style (without content) between elements and pages – the browser keeps it
		const clipboard = () => { try { return JSON.parse(localStorage.getItem('ka-st-styl') || 'null'); } catch (e) { return null; } };
		panel.append(el('div', { class: 'st-pole-radek st-styl-schranka' },
			el('button', { type: 'button', class: 'st-tl', onclick: () => { try { localStorage.setItem('ka-st-styl', JSON.stringify({ style: target.style, classes: target.classes || [] })); setState(T('Style copied.')); redrawRight(); } catch (e) { /* private mode */ } } }, T('Copy style')),
			el('button', { type: 'button', class: 'st-tl', disabled: !clipboard() || shouldSave, onclick: () => {
				const v = clipboard();
				if (!v) { return; }
				applyChange(() => { target.style = JSON.parse(JSON.stringify(v.style || {})); if (v.classes && v.classes.length) { target.classes = v.classes.slice(); } else { delete target.classes; } });
				redrawRight();
			} }, T('Paste style'))));
		if (s !== 'zaklad') { panel.append(el('p', { class: 'placeholder', style: 'margin:0 0 8px;font-size:12px;color:var(--text-slaby)' }, T('Empty field = same value as on the larger screen (grey).'))); }
		// the first (open) group by element kind: Typography for text, Size for an image, otherwise Layout
		const first = { heading: 'typografie', text: 'typografie', tlacitko: 'typografie', list: 'typografie', testimonial: 'typografie', breadcrumbs: 'typografie',
			pocitadlo: 'typografie', image: 'rozmery', video: 'rozmery', mapa: 'rozmery' }[target.type];
		const groups = first ? { [first]: [] } : {};
		Object.entries(STYLE).forEach(([key, def]) => { (groups[def.skupina] = groups[def.skupina] || []).push([key, def]); });
		const box = el('div', { class: 'st-styl' });
		Object.entries(groups).forEach(([group, properties], order) => {
			const isSet = properties.filter(([k]) => target.style[s] && target.style[s][k] !== undefined).length;
			const det = el('details', { open: isSet > 0 || order === 0 }, el('summary', {}, T(D.schema.skupiny_stylu[group]), isSet ? el('small', {}, isSet) : null));
			const content = el('div');
			const set = (key, value) => {
				const perform = () => {
					target.style[s] = target.style[s] || {};
					if (value === '') { delete target.style[s][key]; if (!Object.keys(target.style[s]).length) { delete target.style[s]; } } else { target.style[s][key] = value; }
				};
				if (shouldSave) { perform(); shouldSave(); } else { applyChange(perform, changeKey + ':' + s + ':' + key); }
			};
			if (group === 'rozlozeni' && ((target.style[s] || {}).zobrazeni || inherited(target.style, 'zobrazeni')) === 'grid') { content.append(gridEditor(target, s, set)); }
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
		if (def.type === 'vyber') {
			inputEl = el('select', { onchange: (e) => change(e.target.value) }, el('option', { value: '' }, inheritedFrom ? '↳ ' + T(def.options[inheritedFrom] || inheritedFrom) : '—'),
				Object.entries(def.options).map(([k, v]) => el('option', { value: k, selected: k === value }, T(v))));
		} else {
			const field = el('input', { type: 'text', value: value, placeholder: inheritedFrom, list: HINTS[def.type] ? 'st-dl-' + def.type : null,
				onchange: (e) => change(e.target.value.trim()), oninput: (e) => { if (sample) { sample.style.background = tokenColor(e.target.value || inheritedFrom || 'transparent'); } } });
			// color: the swatch is also the color picker (a custom shade as #hex); the site tokens are offered by the list in the field
			const sample = def.type === 'color' ? el('label', { class: 'st-vzorek', title: T('Pick a custom colour'), style: 'background:' + tokenColor(value || inheritedFrom || 'transparent') },
				el('input', { type: 'color', 'aria-label': T('Pick a custom colour'), value: /^#[0-9a-f]{6}$/i.test(value) ? value : '#000000',
					oninput: (e) => { sample.style.background = e.target.value; }, onchange: (e) => { field.value = e.target.value; change(e.target.value); } })) : null;
			inputEl = el('span', { class: 'st-pole-radek' }, sample, field,
				def.type === 'image' ? el('button', { type: 'button', class: 'st-tl', title: T('Media'), onclick: () => window.kaletaVyberObrazek && window.kaletaVyberObrazek((o) => { field.value = o.url; change(o.url); }) }, '…') : null,
				def.type === 'shadow' || def.type === 'border' ? el('button', { type: 'button', class: 'st-tl', title: T('Compose your own'), 'aria-expanded': 'false', onclick: (e) => {
					const opened = e.currentTarget.getAttribute('aria-expanded') === 'true';
					e.currentTarget.setAttribute('aria-expanded', String(!opened));
					const box = e.currentTarget.closest('.st-vlastnost').querySelector('.st-sklad');
					if (box) { box.remove(); return; }
					e.currentTarget.closest('.st-vlastnost').append((def.type === 'shadow' ? shadowStack : borderStack)(value || inheritedFrom, (h) => { field.value = h; change(h); }));
				} }, '✎') : null);
			if (def.type === 'border') { field.setAttribute('list', 'st-dl-ramecek'); }
		}
		const id = 'st-v-' + key;
		(inputEl.matches('select') ? inputEl : inputEl.querySelector('input[type="text"]')).id = id;
		const error = target.id && state.chyby[state.cestaVybraneho + '.style.' + s + '.' + key];
		return el('div', { class: 'st-vlastnost' + (value !== '' ? ' nastaveno' : '') + (error ? ' st-pole-chyba' : '') }, el('label', { for: id, title: def.css }, T(def.popisek)), inputEl,
			error ? el('small', { class: 'st-chyba-pole', role: 'alert' }, error) : null);
	}

	/** Shadow builder: offset, blur, spread, color and opacity → „0 8px 24px color-mix(…)“; color tokens work. */
	function shadowStack(value, change) {
		const m = /^(inset\s+)?(-?[\d.]+)(?:px)?\s+(-?[\d.]+)(?:px)?\s+(-?[\d.]+)?(?:px)?\s*(-?[\d.]+)?(?:px)?\s*(\S+)?/.exec(/^[slm]$|^none$/.test(value) ? '' : value) || [];
		const v = { inset: !!m[1], x: m[2] || '0', y: m[3] || '8', blur: m[4] || '24', spread: m[5] || '0', color: m[6] && m[6][0] === '#' ? m[6].slice(0, 7) : '#000000', sila: 15 };
		const collapse = () => change((v.inset ? 'inset ' : '') + v.x + 'px ' + v.y + 'px ' + v.blur + 'px ' + v.spread + 'px ' + v.color + Math.round(v.sila * 2.55).toString(16).padStart(2, '0'));
		const number = (key, labelText, min, max) => el('label', {}, el('span', {}, T(labelText)), el('input', { type: 'number', min, max, value: v[key], oninput: (e) => { v[key] = e.target.value || '0'; collapse(); } }));
		return el('div', { class: 'st-sklad' }, number('x', 'Horizontal', -60, 60), number('y', 'Vertical', -60, 60), number('blur', 'Blur', 0, 120), number('spread', 'Spread', -40, 40),
			el('label', {}, el('span', {}, T('Barva')), el('input', { type: 'color', value: v.color, oninput: (e) => { v.color = e.target.value; collapse(); } })),
			el('label', {}, el('span', {}, T('Strength')), el('input', { type: 'range', min: 3, max: 60, value: v.sila, oninput: (e) => { v.sila = +e.target.value; collapse(); } })),
			el('label', { class: 'st-zaskrt' }, el('input', { type: 'checkbox', checked: v.inset, onchange: (e) => { v.inset = e.target.checked; collapse(); } }), T('Inset')));
	}

	/** Border builder: width, line and color (a token or custom) → „2px dashed primarni“. */
	function borderStack(value, change) {
		const m = /^(\d+(?:\.\d)?)px\s+(solid|dashed|dotted|double)\s+(\S+)$/.exec(value) || [];
		const v = { width: m[1] || '1', cara: m[2] || 'solid', color: m[3] || 'line' };
		const collapse = () => change(v.width + 'px ' + v.cara + ' ' + v.color);
		return el('div', { class: 'st-sklad' },
			el('label', {}, el('span', {}, T('Width')), el('input', { type: 'number', min: 1, max: 20, value: v.width, oninput: (e) => { v.width = e.target.value || '1'; collapse(); } })),
			el('label', {}, el('span', {}, T('Čára')), el('select', { onchange: (e) => { v.cara = e.target.value; collapse(); } },
				[['solid', 'plná'], ['dashed', 'čárkovaná'], ['dotted', 'tečkovaná'], ['double', 'dvojitá']].map(([k, n]) => el('option', { value: k, selected: k === v.cara }, T(n))))),
			el('label', {}, el('span', {}, T('Barva')), el('input', { type: 'text', list: 'st-dl-barva', value: v.color, onchange: (e) => { v.color = e.target.value.trim() || 'line'; collapse(); } })));
	}

	/**
	 * Grid editor: a preview of columns and rows, quick presets and naming areas by clicking into cells
	 * (nested elements then get „Oblast v mřížce“ (Grid area)). Writes to the properties sloupce, radky and oblasti.
	 */
	function gridEditor(target, s, set) {
		const value = (k) => (target.style[s] || {})[k] || inherited(target.style, k);
		const columns = value('columns') || '1';
		const columnCount = /^\d+$/.test(columns) ? +columns : /^auto:/.test(columns) ? 3 : columns.trim().split(/\s+/).length;
		const areas = value('areas') ? value('areas').split('/').map((r) => r.trim().split(/\s+/)) : [];
		const rows = value('radky');
		const rowCount = Math.max(areas.length, /^\d+$/.test(rows) ? +rows : rows ? rows.trim().split(/\s+/).length : 0, 1);
		const widths = /^\d+$/.test(columns) || /^auto:/.test(columns) ? Array(columnCount).fill('1fr') : columns.trim().split(/\s+/);
		const grid = el('div', { class: 'st-mrizka-nahled', style: 'grid-template-columns:' + widths.map((w) => /fr$/.test(w) ? w : 'auto').join(' ') });
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
		const preset = (text, h) => el('button', { type: 'button', class: 'st-tl', 'aria-pressed': String(columns === h), onclick: () => set('columns', h) }, text);
		return el('div', { class: 'st-mrizka' },
			el('div', { class: 'st-mrizka-predvolby' }, preset('1', '1'), preset('2', '2'), preset('3', '3'), preset('4', '4'), preset('2 : 1', '2fr 1fr'), preset('1 : 2', '1fr 2fr'), preset(T('fit to space'), 'auto:16rem')),
			el('div', { class: 'st-mrizka-radky' }, el('span', {}, T('Rows')),
				el('button', { type: 'button', class: 'st-tl', 'aria-label': T('Remove row'), onclick: () => set('radky', rowCount > 1 ? String(rowCount - 1) : '') }, '−'),
				el('strong', {}, String(rowCount)),
				el('button', { type: 'button', class: 'st-tl', 'aria-label': T('Add row'), onclick: () => set('radky', String(Math.min(12, rowCount + 1))) }, '+')),
			grid,
			el('small', {}, T('Type area names into the cells (the same name across several cells = the element spans them). Then give the nested element its “Grid area”.')));
	}

	/* ---------- advanced: tag, classes, anchor ---------- */

	function advancedPanel(panel, p, s) {
		if (s.znacky.length > 1) {
			panel.append(field({ type: 'vyber', popisek: 'HTML tag', options: Object.fromEntries(s.znacky.map((z) => [z, '<' + z + '>'])) }, p.tag, (h) => applyChange(() => { p.tag = h; })));
		}
		panel.append(
			field({ type: 'text', popisek: 'Name in Structure' }, p.popis || '', (h) => applyChange(() => { if (h) { p.popis = h; } else { delete p.popis; } }, 'popis:' + p.id)),
			field({ type: 'text', popisek: 'Anchor (id for a #… link)' }, p.anchor || '', (h) => applyChange(() => { if (h) { p.anchor = h; } else { delete p.anchor; } }, 'kotva:' + p.id)),
		);
		const classes = p.classes || [];
		const newClass = el('input', { type: 'text', list: 'st-dl-tridy', placeholder: T('e.g. card'), onkeydown: (e) => { if (e.key === 'Enter') { e.preventDefault(); addClass(); } } });
		const addClass = () => {
			const name = newClass.value.trim().toLowerCase();
			if (!/^[a-z][a-z0-9-]{0,40}(__[a-z0-9-]{1,30})?(--[a-z0-9-]{1,30})?$/.test(name) || classes.includes(name)) { return; }
			applyChange(() => { p.classes = classes.concat([name]); });
		};
		panel.append(el('div', { class: 'st-pole' }, el('span', {}, T('Classes')),
			el('div', { class: 'st-tridy' }, classes.map((t) => el('span', { class: 'st-trida' },
				el('a', { href: '#', title: T('Edit class'), onclick: (e) => { e.preventDefault(); state.trida = t; redrawRight(); } }, '.' + t),
				el('button', { type: 'button', title: T('Remove class from element'), onclick: () => applyChange(() => { p.classes = classes.filter((x) => x !== t); if (!p.classes.length) { delete p.classes; } }) }, '×')))),
			el('span', { class: 'st-pole-radek' }, newClass, el('button', { type: 'button', class: 'st-tl', onclick: addClass }, T('Přidat'))),
			el('datalist', { id: 'st-dl-tridy' }, Object.keys(D.classes).map((t) => el('option', { value: t }))),
			el('small', { style: 'color:var(--text-slaby)' }, T('A class shares its look between elements on all pages. Click a class to edit it.'))));
		const cssField = el('textarea', { rows: 4, placeholder: 'transition: transform .2s;\nbackdrop-filter: blur(8px);', onchange: (e) => applyChange(() => { const h = e.target.value.trim(); if (h) { p.css = h; } else { delete p.css; } }) });
		cssField.value = p.css || '';
		const attributeField = el('textarea', { rows: 3, placeholder: 'data-sledovat=cta\naria-label=' + T('Main call to action'), onchange: (e) => applyChange(() => {
			const attributes = {};
			e.target.value.split('\n').forEach((row) => { const i = row.indexOf('='); if (i > 0) { attributes[row.slice(0, i).trim()] = row.slice(i + 1).trim(); } });
			if (Object.keys(attributes).length) { p.attributes = attributes; } else { delete p.attributes; }
		}) });
		attributeField.value = Object.entries(p.attributes || {}).map(([k, v]) => k + '=' + v).join('\n');
		panel.append(el('h3', {}, T('Custom CSS and attributes')),
			el('label', { class: 'st-pole' + (state.chyby[state.cestaVybraneho + '.css'] ? ' st-pole-chyba' : '') }, el('span', {}, T('CSS for this element only (property: value;)')), cssField,
				state.chyby[state.cestaVybraneho + '.css'] ? el('small', { class: 'st-chyba-pole' }, state.chyby[state.cestaVybraneho + '.css']) : null),
			el('label', { class: 'st-pole' + (state.chyby[state.cestaVybraneho + '.attributes'] ? ' st-pole-chyba' : '') }, el('span', {}, T('Attributes (name=value, one per line; data-…, aria-…, title, lang, role, rel)')), attributeField,
				state.chyby[state.cestaVybraneho + '.attributes'] ? el('small', { class: 'st-chyba-pole' }, state.chyby[state.cestaVybraneho + '.attributes']) : null));
		const cond = p.conditions || {};
		const setCondition = (key, h) => applyChange(() => { p.conditions = Object.assign({}, p.conditions || {}); if (h) { p.conditions[key] = h; } else { delete p.conditions[key]; } if (!Object.keys(p.conditions).length) { delete p.conditions; } });
		panel.append(el('h3', {}, T('Display conditions')),
			field({ type: 'vyber', popisek: 'Komu', options: { '': 'všem', ne: 'visitors only (not signed in)', ano: 'only people signed in to the administration' } }, cond.signed_in || '', (h) => setCondition('signed_in', h)),
			el('div', { class: 'st-pole-radek' },
				el('label', { class: 'st-pole' }, el('span', {}, T('Show from')), el('input', { type: 'date', value: cond.od || '', onchange: (e) => setCondition('od', e.target.value) })),
				el('label', { class: 'st-pole' }, el('span', {}, T('Show until (inclusive)')), el('input', { type: 'date', value: cond.do || '', onchange: (e) => setCondition('do', e.target.value) }))));
		// language versions: nothing checked = every version; shown on a single-language site only when a build already has the condition
		const languages = D.jazyky || [];
		const chosenLanguages = Array.isArray(cond.jazyky) ? cond.jazyky : null;
		if (languages.length > 1 || chosenLanguages) {
			panel.append(el('div', { class: 'st-pole' }, el('span', {}, T('Only in these language versions (none checked = all)')),
				languages.map((l) => el('label', { class: 'st-zaskrt' }, el('input', { type: 'checkbox', checked: !!chosenLanguages && chosenLanguages.includes(l.kod), onchange: (e) => {
					const list = (chosenLanguages || []).filter((k) => k !== l.kod);
					if (e.target.checked) { list.push(l.kod); }
					setCondition('jazyky', list.length ? list : null);
				} }), l.nazev))));
		}
		// a query parameter of the page address: a campaign link (?utm_campaign=jaro) or a variant (?variant=b)
		const parameter = cond.url_parameter || {};
		const setParameter = (name, value) => setCondition('url_parameter', name ? Object.assign({ nazev: name }, value ? { value: value } : {}) : null);
		panel.append(el('div', { class: 'st-pole-radek' },
				el('label', { class: 'st-pole' }, el('span', {}, T('Only with a URL parameter (name)')),
					el('input', { type: 'text', value: parameter.nazev || '', placeholder: 'utm_campaign', maxlength: 40, onchange: (e) => setParameter(e.target.value.trim(), parameter.value || '') })),
				el('label', { class: 'st-pole' }, el('span', {}, T('…with the value (empty = any)')),
					el('input', { type: 'text', value: parameter.value || '', placeholder: 'jaro', maxlength: 80, disabled: !parameter.nazev, onchange: (e) => setParameter(parameter.nazev || '', e.target.value.trim()) }))),
			el('div', {}, state.chyby[state.cestaVybraneho + '.conditions'] ? el('small', { class: 'st-chyba-pole' }, state.chyby[state.cestaVybraneho + '.conditions']) : null, // el() skips null – append() would write "null"
				el('small', { style: 'color:var(--text-slaby)' }, T('On the canvas the element is always visible. On the website it appears only when the conditions are met – for example a promotional banner for a week.') + ' '
					+ T('A page with a date, sign-in or URL parameter condition is assembled for every visit (it is not cached).'))));
		panel.append(el('h3', {}, T('Visibility')),
			el('label', { class: 'st-zaskrt' }, el('input', { type: 'checkbox', checked: ((p.style || {}).mobil || {}).zobrazeni === 'none', onchange: (e) => applyChange(() => {
				p.style = p.style || {};
				if (e.target.checked) { p.style.mobil = Object.assign(p.style.mobil || {}, { zobrazeni: 'none' }); } else if (p.style.mobil) { delete p.style.mobil.zobrazeni; }
			}) }), T('Hide on mobile')),
			el('label', { class: 'st-zaskrt' }, el('input', { type: 'checkbox', checked: ((p.style || {}).tablet || {}).zobrazeni === 'none', onchange: (e) => applyChange(() => {
				p.style = p.style || {};
				if (e.target.checked) { p.style.tablet = Object.assign(p.style.tablet || {}, { zobrazeni: 'none' }); } else if (p.style.tablet) { delete p.style.tablet.zobrazeni; }
			}) }), T('Hide on tablet and mobile')));
	}

	/* ---------- editing a shared class ---------- */

	const classTimer = {};
	function classesPanel() {
		const name = state.trida;
		const record = D.classes[name] || (D.classes[name] = { style: {}, css: '' });
		if (Array.isArray(record.style)) { record.style = {}; }
		const saveClass = () => {
			setState(T('Unsaved…'));
			clearTimeout(classTimer[name]); // a timer for each class separately – switching to another class does not cancel saving the previous one
			classTimer[name] = setTimeout(() => query(D.adresy.trida, { nazev: name, style: JSON.stringify(record.style), css: record.css }).then((j) => {
				if (!j.ok) { setState(j.error, true); return; }
				D.classes = j.classes;
				setState(j.chyby ? T('Class saved with a warning') : T('Class saved – it applies on all pages'), !!j.chyby);
				refreshPreview();
			}), 500);
		};
		const panel = el('div', { class: 'st-panel' });
		right.replaceChildren(el('div', { class: 'st-hlava-prvku' }, el('strong', {}, T('Class') + ' .' + name),
			el('div', { class: 'st-akce' }, el('button', { type: 'button', title: T('Back to element'), onclick: () => { state.trida = null; redrawRight(); } }, icon('zavrit')))), panel);
		panel.append(el('p', { style: 'margin:0 0 10px;font-size:12px;color:var(--text-slaby)' }, T('Class changes apply to all elements with this class across the site – immediately after saving, without publishing.')));
		if (!D.adresy.smazSekci) {
			// shared classes are edited only by an administrator (the server enforces it too)
			panel.append(el('p', { class: 'st-prazdno', style: 'text-align:left;padding:0' }, T('Only an administrator edits a shared class – a change applies to the whole website at once. Set the look of a single element in its style.')));
			return;
		}
		stylePanel(panel, record, 'trida:' + name, saveClass);
		const css = el('textarea', { rows: 5, placeholder: 'transition: transform .2s;', oninput: (e) => { record.css = e.target.value; saveClass(); } });
		css.value = record.css || '';
		const whereParts = el('p', { class: 'st-prazdno', style: 'text-align:left;padding:0' }, T('Checking where the class is used…'));
		query(D.adresy.trida, { nazev: name, pouziti: '1' }).then((j) => {
			whereParts.textContent = j.ok ? (j.pouziti.length ? T('Used in: ') + j.pouziti.join(', ') : T('The class is not used in any published or draft build yet.')) : '';
		});
		const newName = el('input', { type: 'text', value: name, 'aria-label': T('New class name') });
		panel.append(el('h3', {}, T('Custom CSS')), el('label', { class: 'st-pole' }, el('span', {}, T('Extra declarations (property: value;)')), css),
			el('h3', {}, T('Where it is used')), whereParts);
		if (D.adresy.smazSekci) { // renaming and deleting is allowed to an administrator (the change affects the whole site)
			panel.append(el('h3', {}, T('Rename')), el('span', { class: 'st-pole-radek' }, newName, el('button', { type: 'button', class: 'st-tl', onclick: () => {
				const fresh = newName.value.trim().toLowerCase();
				if (!fresh || fresh === name) { return; }
				// first save unsaved changes, then rename in all builds and reload the editor
				save().then((ok) => (ok ? query(D.adresy.trida, { nazev: name, novy_nazev: fresh }) : null)).then((j) => {
					if (!j) { return; }
					if (!j.ok) { setState(j.error, true); return; }
					window.location.reload();
				});
			} }, T('Rename'))));
		}
		if (!D.adresy.smazSekci) { return; }
		panel.append(el('button', { type: 'button', class: 'st-tl', onclick: () => confirmAction(T('Delete class .') + name + T('? Elements keep it in the structure, but it will lose its look.'), T('Smazat')).then((yes) => {
				if (!yes) { return; }
				query(D.adresy.trida, { nazev: name, smazat: '1' }).then((j) => { if (!j.ok) { setState(j.error, true); return; } D.classes = j.classes; state.trida = null; redrawRight(); refreshPreview(); });
			}) }, T('Delete class')));
	}

	/* ---------- start ---------- */

	createBar();
	left = el('aside', { class: 'st-levy', 'aria-label': T('Elements and structure') });
	right = el('aside', { class: 'st-pravy', 'aria-label': T('Properties') });
	frame2 = el('div', { class: 'st-ramec', 'data-bp': 'zaklad' });
	scale = el('span', { class: 'st-meritko', 'aria-hidden': 'true' });
	root.append(left, el('main', { class: 'st-platno' }, frame2, scale), right, datalists);
	new ResizeObserver(() => previewSize(preview)).observe(frame2);
	root.hidden = false;
	redraw();
	refreshPreview();
	document.addEventListener('keydown', keys);
	document.addEventListener('paste', onPaste);
	// no tour on a phone: there the builder shows only "needs a bigger screen" instead of itself (stavitel.css, same width);
	// it is not marked as seen, so it starts on the first opening on a desktop or tablet
	const narrow = window.matchMedia('(max-width: 719px)').matches;
	try { if (!narrow && !localStorage.getItem('ka-st-prohlidka')) { setTimeout(() => tour(0), 800); } } catch (e) { /* private mode */ }
	state.ulozeno = JSON.stringify(state.stavba);
	root.addEventListener('focusout', () => setTimeout(adoptSanitized, 0));
	window.addEventListener('focus', () => { if (state.pokusy && rejectInvalid()) { (state.signed_in ? refreshToken() : Promise.resolve()).then(save); } });
	// unsaved changes: the browser asks whether to really leave the page; if so, they are sent once more in the background (keepalive)
	window.addEventListener('beforeunload', (e) => { if (rejectInvalid() || state.uklada) { save(); e.preventDefault(); e.returnValue = ''; } });
	window.addEventListener('pagehide', () => {
		if (!rejectInvalid() || state.konflikt) { return; }
		const f = new FormData();
		f.append('_csrf', csrf); f.append('stavba', JSON.stringify(state.stavba)); f.append('verze', state.verze);
		try { fetch(D.adresy.uloz, { method: 'POST', body: f, credentials: 'same-origin', keepalive: true }); } catch (e) { /* over the keepalive limit – the warning has already been shown */ }
	});
})();
