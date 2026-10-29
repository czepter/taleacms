/* Talea – page builder, the canvas: the real page of the site in an iframe (swapped without flicker, sized to the device), what lands
 * where when something is dropped or moved onto it, double-click editing on it, and the clipboard (also between Talea sites). */
import { T, D, TYPY, state, dom, emit, el, query, find, contains, withNewIds, newElement, applyChange, keys, selection, toggleSelected, insert, placeElements,
	redrawPanels, setState, elementCollection, setComponents, openComponent } from './core.js';

/* ---------- canvas: the real page in an iframe, swapped without flicker ---------- */

let previewPending = false;
const WIDTHS = { base: 1280, tablet: 820, mobile: 390 };

/** The iframe has the device width (desktop 1280 px) and is scaled down to fit – so the site's real breakpoints apply on the canvas. */
export function previewSize(frame) {
	if (!frame || !dom.frame2) { return; }
	const w = dom.frame2.clientWidth;
	const h = dom.frame2.clientHeight;
	// preview: a wide monitor (1920 px) only for desktop; zoom is fixed, otherwise the canvas fits the window
	const width = state.bp === 'base' ? (state.zoom === '1920' ? 1920 : Math.max(w, WIDTHS.base)) : WIDTHS[state.bp];
	const m = /^\d+$/.test(state.zoom) && state.zoom !== '1920' ? Number(state.zoom) / 100 : Math.min(1, w / width);
	dom.frame2.classList.toggle('bd-frame-shift', m * width > w + 1);
	frame.style.width = width + 'px';
	frame.style.height = Math.round(h / m) + 'px';
	frame.style.left = Math.max(0, Math.round((w - width * m) / 2)) + 'px';
	frame.style.transform = m < 1 ? 'scale(' + m + ')' : '';
	if (dom.scale) { dom.scale.textContent = width + ' px' + (m < 1 ? ' · ' + Math.round(m * 100) + ' %' : ''); }
	frame.dataset.scale = String(m);
	emit('resize');
}
export function refreshPreview() {
	if (state.editingCanvas) { return; }
	if (state.gesture) { state.refreshAfterGesture = true; return; } // the canvas is not swapped under a gesture in progress
	if (previewPending) { previewPending = 'again'; return; }
	previewPending = true;
	const fresh = el('iframe', { class: 'bd-loading', title: T('Page preview'), src: D.preview + (D.preview.includes('?') ? '&' : '?') + 't=' + Date.now() });
	previewSize(fresh);
	fresh.addEventListener('load', () => {
		// a gesture started while this page was loading: the canvas is not swapped under it (the handle it holds would disappear)
		if (state.gesture) { fresh.remove(); previewPending = false; state.refreshAfterGesture = true; return; }
		const offset = dom.preview && dom.preview.contentWindow ? dom.preview.contentWindow.scrollY : 0;
		try { fresh.contentWindow.scrollTo(0, offset); } catch (e) { /* nothing */ }
		preparePreview(fresh);
		if (dom.preview) { dom.preview.remove(); }
		fresh.classList.remove('bd-loading');
		dom.preview = fresh;
		previewSize(fresh); // the device or the zoom may have changed while it loaded: it was sized for the old one
		markInPreview(false);
		const retry = previewPending === 'again';
		previewPending = false;
		if (retry) { refreshPreview(); }
	});
	dom.frame2.append(fresh);
}

function preparePreview(frame) {
	const doc = frame.contentDocument;
	if (!doc) { return; }
	doc.head.append(Object.assign(doc.createElement('style'), { textContent:
		'[data-tl-id]{cursor:default} .tl-bd-hover{outline:1px dashed #ff4f2e!important;outline-offset:-1px} .tl-bd-selected{outline:2px solid #ff4f2e!important;outline-offset:-2px}'
		+ '[contenteditable]{outline:2px solid #f79009!important;outline-offset:2px;cursor:text} .tl-edit-here,.cookies-bar,.cookies-reopen{display:none!important}' }));
	doc.addEventListener('click', (e) => {
		if (e.target.closest('[contenteditable]') || e.target.closest('#tl-bd-overlay, #tl-bd-part, #tl-bd-add')) { return; } // the overlay, the site part link and Add section (#116) handle their own clicks
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
	doc.addEventListener('dblclick', (e) => {
		const t = e.target.closest('[data-tl-id]');
		if (!t || t.hasAttribute('data-tl-lock')) { return; }
		const n = find(t.getAttribute('data-tl-id'));
		if (n && n.p.type === 'component') { openComponent(n.p, true); } else { editOnCanvas(t); } // a component is edited in its own builder, around it this page
	});
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
export function hiddenOnCanvas(doc) {
	doc = doc || (dom.preview && dom.preview.contentDocument);
	if (!doc) { return; }
	let st = doc.getElementById('tl-bd-hidden');
	if (!st) { st = Object.assign(doc.createElement('style'), { id: 'tl-bd-hidden' }); doc.head.append(st); }
	st.textContent = Object.keys(state.hidden).filter((id) => state.hidden[id]).map((id) => '[data-tl-id="' + id + '"]{display:none!important}').join('');
}

/** The document of the page on the canvas, or null while none is loaded. */
export const previewDoc = () => (dom.preview && dom.preview.contentDocument) || null;
/** How much the canvas is scaled down to fit (1 = not at all). */
export const previewScale = () => (dom.preview && parseFloat(dom.preview.dataset.scale)) || 1;
/** After a gesture: the canvas may be swapped again (a refresh that came during it runs now). */
export const endGesture = () => { state.gesture = false; if (state.refreshAfterGesture) { state.refreshAfterGesture = false; refreshPreview(); } };

/* ---------- dragging on the canvas: a new element, a ready-made section or moving the selected element ---------- */

/** Move by tapping – a replacement for dragging on touch devices: select an element, tap "Move" and then the place. */
export function startPlacing(id) {
	if (!id) { return; }
	state.placing = { move: id, more: id === state.selected ? state.multi.slice() : [] };
	document.body.classList.add('bd-placing');
	emit('placing');
	setState(T('Tap the place on the page to move the element to (top or bottom part of an element = before or after, middle of a container = inside). Esc cancels.'));
}

export function endPlacing() {
	state.placing = null;
	document.body.classList.remove('bd-placing');
	emit('placing');
	setState('');
}

/**
 * Where the element would land: {target: id, where: 'before' | 'after' | 'inside'}, or {root: true} on an empty page. Sections only between sections;
 * inside a container when the pointer is in its middle part (or it is empty); never a move into itself.
 */
export function canvasSpot(doc, e) {
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
export function showSpot(doc, place) {
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

export function dropAt(what, place) {
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
	query(D.urls.section + (D.urls.section.includes('?') ? '&' : '?') + 'key=' + encodeURIComponent(what.section), { ok: 1 }).then((j) => {
		if (!j.ok) { setState(j.error, true); return; }
		D.classes = j.classes;
		placeElement(j.element);
	});
}

export function markInPreview(shift) {
	const doc = dom.preview && dom.preview.contentDocument;
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
export function editOnCanvas(node) {
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

/* ---------- clipboard (also between pages and between Talea sites) ---------- */

// A copy is an envelope {"talea":"elements",…} with the classes and components of the elements (Builder\ElementClipboard): in
// the system clipboard as text (for another Talea site too) and in localStorage. Every paste goes through the server
// (build_paste): any web page can put text claiming this site into the clipboard, so the claim is never trusted here – the
// server checks the elements and keeps Custom HTML only in an envelope this site signed for an administrator.
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
export function copy() {
	const n = state.selected && find(state.selected);
	if (!n) { return; }
	const text = envelope([n.p]);
	text.then((t) => { try { localStorage.setItem('tl-builder-clipboard', t); } catch (e) { /* private mode */ } });
	setState(T('Copied'));
	writeClipboard(text).catch(() => setState(T('Copied within this site – for another Talea site use More actions → Copy for another Talea site.')));
}
/** The copy from this site (localStorage). */
export function pasteFromClipboard() {
	let text = '';
	try { text = localStorage.getItem('tl-builder-clipboard') || ''; } catch (e) { /* private mode */ }
	return pasteText(text);
}
/** Text from the system clipboard: an envelope from this or another Talea site; anything else is not for the editor. */
function pasteText(text) {
	let data = null;
	try { data = JSON.parse(text); } catch (e) { return false; }
	if (!data || data.talea !== CLIPBOARD_FORMAT || !Array.isArray(data.elements) || !data.elements.length) { return false; }
	const own = data.site === location.origin;
	setState(own ? T('Inserting elements…') : T('Inserting elements from another site…'));
	query(D.urls.paste, { clipboard: text }).then((j) => {
		if (!j.ok) { setState(j.error || T('The elements could not be inserted.'), true); return; }
		D.classes = j.classes;
		setComponents(j.components);
		insertAll(j.elements);
		const notes = j.notes || [];
		setState([own ? T('Inserted') : T('Inserted from %s.').replace('%s', String(data.site))].concat(notes).join(' '), notes.length > 0);
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
export function onPaste(e) {
	const v = e.target;
	if ((v && (v.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(v.tagName))) || document.querySelector('dialog[open]')) { return; }
	clearTimeout(state.pasteTimer);
	const text = e.clipboardData ? e.clipboardData.getData('text/plain') : '';
	if (pasteText(text)) { e.preventDefault(); return; }
	pasteFromClipboard();
}
export function copyDialog(id) {
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
export function pasteDialog() {
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
