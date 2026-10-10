/* Talea – the page builder's direct manipulation on the canvas, part 1: the overlay layer (compose phase A, see docs/specs/visual-compose.md).
 *
 * Everything the gestures draw – handles, bands, guides, the floating toolbar, the marquee – lives in ONE element inside the canvas iframe
 * (#tl-bd-overlay). It is created by this script, is never part of the saved page and never exists on the public site: the canvas is the
 * editor's preview only. Gestures use pointer events with capture (mouse, pen and touch behave the same); during a gesture the node gets
 * temporary inline values, on release ONE applyChange writes the real style for the current screen size. No libraries, no build step.
 * The interface to the editor is window.taleaBuilder (builder.js); builder-handles.js and builder-keys.js build on window.taleaCompose. */
(function () {
	'use strict';

	const B = window.taleaBuilder;
	if (!B) { return; }
	const { T, state } = B;
	const C = window.taleaCompose = { providers: [], build: null };

	const STYLE = `
#tl-bd-overlay{position:absolute;left:0;top:0;width:0;height:0;z-index:2147483645;pointer-events:none;font:12px/1.25 system-ui,sans-serif;color:#fff;--s:1}
#tl-bd-overlay *{box-sizing:border-box}
#tl-bd-overlay .bdo-band{position:absolute;pointer-events:none}
#tl-bd-overlay .bdo-pad{background:rgb(255 79 46/.16)}
#tl-bd-overlay .bdo-mar{background:repeating-linear-gradient(135deg,rgb(255 150 40/.28) 0 calc(3px*var(--s)),rgb(255 150 40/.08) calc(3px*var(--s)) calc(6px*var(--s)))}
#tl-bd-overlay .bdo-h{position:absolute;pointer-events:auto;touch-action:none;cursor:pointer;transform:translate(-50%,-50%);outline:0;user-select:none;-webkit-user-select:none}
#tl-bd-overlay .bdo-h::after{content:'';position:absolute;inset:calc(-4px*var(--s))}
#tl-bd-overlay .bdo-h:focus-visible{outline:calc(3px*var(--s)) solid #0b57d0;outline-offset:calc(2px*var(--s))}
#tl-bd-overlay .bdo-sp{width:calc(20px*var(--s));height:calc(9px*var(--s));border-radius:calc(5px*var(--s));background:#ff4f2e;border:calc(1.5px*var(--s)) solid #fff;box-shadow:0 0 0 calc(1px*var(--s)) rgb(0 0 0/.35)}
#tl-bd-overlay .bdo-sp[data-axis="x"]{width:calc(9px*var(--s));height:calc(20px*var(--s))}
#tl-bd-overlay .bdo-sp.bdo-m{background:#fff;border-color:#ff4f2e;box-shadow:0 0 0 calc(1px*var(--s)) rgb(0 0 0/.25)}
#tl-bd-overlay .bdo-gap{width:calc(20px*var(--s));height:calc(9px*var(--s));border-radius:calc(5px*var(--s));background:#6b4eff;border:calc(1.5px*var(--s)) solid #fff;box-shadow:0 0 0 calc(1px*var(--s)) rgb(0 0 0/.35)}
#tl-bd-overlay .bdo-gap[data-axis="x"]{width:calc(9px*var(--s));height:calc(20px*var(--s))}
#tl-bd-overlay .bdo-rz{width:calc(11px*var(--s));height:calc(11px*var(--s));background:#fff;border:calc(2px*var(--s)) solid #ff4f2e;border-radius:calc(2px*var(--s))}
#tl-bd-overlay .bdo-dv{width:calc(8px*var(--s));background:#6b4eff;border:calc(1.5px*var(--s)) solid #fff;border-radius:calc(4px*var(--s));cursor:col-resize;opacity:.9}
#tl-bd-overlay .bdo-guide{position:absolute;pointer-events:none;background:#e0007a}
#tl-bd-overlay .bdo-label{position:absolute;pointer-events:none;padding:calc(3px*var(--s)) calc(7px*var(--s));border-radius:calc(4px*var(--s));background:#1d1d1f;color:#fff;font-size:calc(12px*var(--s));white-space:nowrap;box-shadow:0 0 0 calc(1px*var(--s)) rgb(255 255 255/.5)}
#tl-bd-overlay .bdo-marquee{position:absolute;pointer-events:none;border:calc(1px*var(--s)) dashed #ff4f2e;background:rgb(255 79 46/.08)}
#tl-bd-overlay .bdo-ghost{position:absolute;pointer-events:none;border:calc(2px*var(--s)) dashed #ff4f2e;background:rgb(255 79 46/.10);border-radius:calc(3px*var(--s))}
#tl-bd-overlay .bdo-tb{position:absolute;pointer-events:auto;display:flex;width:max-content;flex-wrap:wrap;gap:calc(2px*var(--s));padding:calc(3px*var(--s));max-width:calc(100vw - 12px*var(--s));border-radius:calc(8px*var(--s));background:#1d1d1f;box-shadow:0 calc(4px*var(--s)) calc(14px*var(--s)) rgb(0 0 0/.35),0 0 0 calc(1px*var(--s)) rgb(255 255 255/.35);touch-action:manipulation}
#tl-bd-overlay .bdo-b{display:inline-grid;place-items:center;min-width:calc(30px*var(--s));height:calc(30px*var(--s));padding:0 calc(6px*var(--s));border:0;border-radius:calc(6px*var(--s));background:transparent;color:#fff;font:600 calc(13px*var(--s))/1 system-ui,sans-serif;cursor:pointer;touch-action:none}
#tl-bd-overlay .bdo-b:hover{background:rgb(255 255 255/.18)}
#tl-bd-overlay .bdo-b:focus-visible{outline:calc(2px*var(--s)) solid #8ab4f8;outline-offset:calc(-2px*var(--s))}
#tl-bd-overlay .bdo-b[aria-pressed="true"]{background:#ff4f2e}
#tl-bd-overlay .bdo-b svg{width:calc(18px*var(--s));height:calc(18px*var(--s));fill:none;stroke:currentColor;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round}
#tl-bd-overlay .bdo-sep{width:calc(1px*var(--s));margin:calc(4px*var(--s)) calc(2px*var(--s));background:rgb(255 255 255/.3)}
#tl-bd-overlay .bdo-b[data-bdo="move"]{cursor:grab}
@media (pointer:coarse){#tl-bd-overlay .bdo-h::after{inset:calc(-13px*var(--s))}#tl-bd-overlay .bdo-b{min-width:calc(38px*var(--s));height:calc(38px*var(--s))}}
`;

	/* ---------- helpers ---------- */

	const mk = (doc, tag, attributes, ...children) => {
		const e = doc.createElement(tag);
		for (const [k, v] of Object.entries(attributes || {})) { if (v !== null && v !== undefined && v !== false) { e.setAttribute(k, v === true ? '' : v); } }
		children.flat().forEach((c) => { if (c !== null && c !== undefined && c !== false) { e.append(c); } });
		return e;
	};
	C.mk = mk;
	/** The element's box in page coordinates (the overlay is positioned in them; the iframe's own scaling does not matter inside it). */
	C.page = (node) => {
		const r = node.getBoundingClientRect();
		const w = node.ownerDocument.defaultView;
		return { left: r.left + w.scrollX, top: r.top + w.scrollY, right: r.right + w.scrollX, bottom: r.bottom + w.scrollY, width: r.width, height: r.height };
	};
	C.nodeOf = (doc, id) => doc.querySelector('[data-tl-id="' + id + '"]');
	const round = (n, d = 2) => Math.round(n * 10 ** d) / 10 ** d;
	C.round = round;
	/** A px value written for a free (modifier) gesture. */
	C.px = (n) => Math.max(0, Math.round(n)) + 'px';

	/** A polite announcement for screen readers (the live region is in the editor, not in the canvas). */
	let live = null;
	C.announce = (text) => {
		if (!live) { live = B.el('div', { id: 'bd-live', class: 'bd-sr', role: 'status', 'aria-live': 'polite', 'aria-atomic': 'true' }); document.body.append(live); }
		live.textContent = text;
	};

	/** Design tokens as the canvas resolves them: a probe element with the token, measured. Nothing of the token maths lives here. */
	function probe(doc, property, value) {
		const p = mk(doc, 'div', { style: 'position:absolute;visibility:hidden;pointer-events:none;left:0;top:0;width:0;height:0;overflow:hidden' });
		doc.body.append(p);
		p.style[property] = value;
		if (property === 'fontSize') { p.textContent = 'x'; }
		const result = property === 'fontSize' ? parseFloat(doc.defaultView.getComputedStyle(p).fontSize) : p.getBoundingClientRect().width;
		p.remove();
		return result;
	}
	/** The spacing scale of this canvas, ascending: [{key: '0' | 'xs' …, px}]. */
	C.spaces = (doc) => {
		if (!doc.__bdoSpaces) {
			doc.__bdoSpaces = [{ key: '0', px: 0 }, ...B.D.schema.tokens.spaces.map((key) => ({ key, px: probe(doc, 'width', 'var(--tl-space-' + key + ')') }))].filter((t) => t.key === '0' || t.px > 0).sort((a, b) => a.px - b.px);
		}
		return doc.__bdoSpaces;
	};
	/** The font size steps of this canvas: [{key: '-1' … '5', px}] ascending. */
	C.steps = (doc) => {
		if (!doc.__bdoSteps) {
			doc.__bdoSteps = B.D.schema.tokens.steps.map((key) => ({ key, px: probe(doc, 'fontSize', 'var(--tl-step-' + key + ')') })).filter((t) => t.px > 0).sort((a, b) => a.px - b.px);
		}
		return doc.__bdoSteps;
	};
	/** How much the overlay's sizes grow so that they look the same on a zoomed-out canvas (the canvas is the page scaled down to fit). */
	C.factor = () => Math.min(1.8, 1 / B.scale());
	C.rootPx = (doc) => parseFloat(doc.defaultView.getComputedStyle(doc.documentElement).fontSize) || 16;
	/** The nearest token to a length in px (or a free px value with the modifier): {key, px, css}. */
	C.snapSpace = (doc, px, free) => {
		px = Math.max(0, px);
		if (free) { const v = Math.round(px); return { key: v + 'px', px: v, css: v + 'px' }; }
		const tokens = C.spaces(doc);
		const t = tokens.reduce((best, x) => (Math.abs(x.px - px) < Math.abs(best.px - px) ? x : best), tokens[0]);
		return { key: t.key, px: t.px, css: t.key === '0' ? '0' : 'var(--tl-space-' + t.key + ')' };
	};
	C.spaceName = (v) => (/px$/.test(v.key) ? v.key : (v.key === '0' ? '0' : v.key + ' · ' + round(v.px, 0) + ' px'));

	/* ---------- the layer ---------- */

	C.items = []; // positioned parts: {el, place(geometry)}
	C.layer = null;
	C.ensure = (doc) => {
		if (!doc.getElementById('tl-bd-overlay-style')) { doc.head.append(Object.assign(doc.createElement('style'), { id: 'tl-bd-overlay-style', textContent: STYLE })); }
		let root = doc.getElementById('tl-bd-overlay');
		if (!root) { root = mk(doc, 'div', { id: 'tl-bd-overlay' }); doc.documentElement.append(root); }
		return root;
	};
	/** Adds a positioned part: place(g) sets its left/top/size from the live geometry g (see measure). */
	C.add = (el, place) => { C.layer.append(el); C.items.push({ el, place }); place && place(C.geometry); return el; };
	C.placeAll = () => { if (!C.node) { return; } C.geometry = C.measure(C.node); C.items.forEach((i) => i.place && i.place(C.geometry)); };

	/** The live box of the main selected element: page rect, paddings, margins, computed values. */
	C.measure = (node) => {
		const cs = node.ownerDocument.defaultView.getComputedStyle(node);
		const n = (v) => parseFloat(v) || 0;
		const r = C.page(node);
		return { r, display: cs.display, cs,
			pad: { top: n(cs.paddingTop), right: n(cs.paddingRight), bottom: n(cs.paddingBottom), left: n(cs.paddingLeft) },
			mar: { top: n(cs.marginTop), right: n(cs.marginRight), bottom: n(cs.marginBottom), left: n(cs.marginLeft) } };
	};

	/** A label next to the pointer while dragging (the token name and value). */
	C.say = (text, x, y) => {
		if (!C.layer) { return; }
		let l = C.layer.querySelector('.bdo-label');
		if (!l) { l = mk(C.layer.ownerDocument, 'div', { class: 'bdo-label' }); C.layer.append(l); }
		l.textContent = text;
		const m = C.factor();
		l.style.left = (x + 16 * m) + 'px';
		l.style.top = (y + 16 * m) + 'px';
		l.hidden = false;
		C.announce(text);
	};
	C.hideLabel = () => { const l = C.layer && C.layer.querySelector('.bdo-label'); if (l) { l.hidden = true; } };

	/* ---------- gestures: pointer events with capture, one requestAnimationFrame loop, Esc cancels ---------- */

	/**
	 * Binds a pointer gesture to a handle. start(event) returns the gesture context (or null to refuse); move(ctx, dx, dy, event) runs at most once per
	 * frame (dx, dy in the canvas's own pixels); end(ctx, moved) writes the result; cancel(ctx) puts back what move changed.
	 */
	C.drag = (handle, { start, move, end, cancel }) => {
		handle.addEventListener('pointerdown', (e) => {
			if ((e.pointerType === 'mouse' && e.button !== 0) || state.gesture) { return; }
			const doc = handle.ownerDocument;
			const win = doc.defaultView;
			const ctx = start(e);
			if (!ctx) { return; }
			e.preventDefault();
			e.stopPropagation();
			state.gesture = true;
			try { handle.setPointerCapture(e.pointerId); } catch (x) { /* the pointer is already gone */ }
			const x0 = e.clientX;
			const y0 = e.clientY;
			let last = e;
			let frame = 0;
			let moved = false;
			const run = () => {
				frame = 0;
				const dx = last.clientX - x0;
				const dy = last.clientY - y0;
				if (Math.abs(dx) + Math.abs(dy) > 3) { moved = true; }
				if (moved) { move(ctx, dx, dy, last); C.placeAll(); }
			};
			const stop = () => {
				if (frame) { win.cancelAnimationFrame(frame); frame = 0; }
				handle.removeEventListener('pointermove', onMove);
				handle.removeEventListener('pointerup', onUp);
				handle.removeEventListener('pointercancel', onCancel);
				doc.removeEventListener('keydown', onKey, true);
				document.removeEventListener('keydown', onKey, true);
				C.clearGuides();
				C.hideLabel();
				B.endGesture();
			};
			const onMove = (ev) => { last = ev; if (!frame) { frame = win.requestAnimationFrame(run); } };
			const onUp = (ev) => {
				last = ev;
				if (frame) { win.cancelAnimationFrame(frame); }
				run();
				try { handle.releasePointerCapture(ev.pointerId); } catch (x) { /* released */ }
				stop();
				// the click that follows the gesture is not a click on the page (the handle may be gone by then): it would select or place something
				const block = (c) => { c.stopImmediatePropagation(); c.preventDefault(); };
				win.addEventListener('click', block, { capture: true, once: true });
				setTimeout(() => win.removeEventListener('click', block, true), 150);
				end(ctx, moved);
				C.afterGesture();
			};
			const onCancel = () => { stop(); cancel(ctx); C.afterGesture(); };
			const onKey = (ev) => { if (ev.key === 'Escape') { ev.preventDefault(); ev.stopPropagation(); stop(); cancel(ctx); C.afterGesture(); C.announce(T('Cancelled.')); } };
			handle.addEventListener('pointermove', onMove);
			handle.addEventListener('pointerup', onUp);
			handle.addEventListener('pointercancel', onCancel);
			doc.addEventListener('keydown', onKey, true);
			document.addEventListener('keydown', onKey, true);
		});
	};
	/** After a gesture the handles are drawn again from the real geometry (the canvas itself is swapped once the draft is saved). */
	C.afterGesture = () => setTimeout(C.render, 80);

	/* ---------- alignment guides (also while resizing and moving) ---------- */

	/** The lines of the other elements a moving box can align with: left / centre / right and top / middle / bottom. */
	C.prepareGuides = (doc, skip) => {
		const x = new Set();
		const y = new Set();
		Array.from(doc.querySelectorAll('[data-tl-id]')).slice(0, 400).forEach((n) => {
			if (skip.some((s) => s === n || s.contains(n) || n.contains(s))) { return; }
			const r = C.page(n);
			if (r.width < 2 || r.height < 2) { return; }
			[r.left, (r.left + r.right) / 2, r.right].forEach((v) => x.add(Math.round(v)));
			[r.top, (r.top + r.bottom) / 2, r.bottom].forEach((v) => y.add(Math.round(v)));
		});
		return { x: [...x], y: [...y] };
	};
	C.clearGuides = () => { if (C.layer) { C.layer.querySelectorAll('.bdo-guide').forEach((g) => g.remove()); } };
	/** Draws a line where an edge or the centre of the box (page coordinates) is within 2 px of a line of another element. */
	C.showGuides = (candidates, box) => {
		C.clearGuides();
		if (!C.layer || !candidates) { return; }
		const doc = C.layer.ownerDocument;
		const m = C.factor();
		const draw = (css) => C.layer.append(mk(doc, 'div', { class: 'bdo-guide', style: css }));
		const height = doc.documentElement.scrollHeight;
		const width = doc.documentElement.scrollWidth;
		[box.left, (box.left + box.right) / 2, box.right].forEach((v) => { if (candidates.x.some((c) => Math.abs(c - v) <= 2)) { draw('left:' + round(v) + 'px;top:0;width:' + m + 'px;height:' + height + 'px'); } });
		[box.top, (box.top + box.bottom) / 2, box.bottom].forEach((v) => { if (candidates.y.some((c) => Math.abs(c - v) <= 2)) { draw('top:' + round(v) + 'px;left:0;height:' + m + 'px;width:' + width + 'px'); } });
	};

	/* ---------- rendering ---------- */

	/** The elements that can carry handles: found in the tree, on the canvas, with a box, not locked. */
	function usable(doc, id) {
		const n = B.find(id);
		const node = n && C.nodeOf(doc, id);
		if (!node || node.closest('[data-tl-lock]') || n.p.locked) { return null; }
		const r = node.getBoundingClientRect();
		return r.width > 0 && r.height > 0 && doc.defaultView.getComputedStyle(node).display !== 'contents' ? { n, node } : null;
	}

	C.render = () => {
		const doc = B.previewDoc();
		if (!doc || !doc.body || state.gesture) { return; }
		const root = C.ensure(doc);
		// a keyboard step re-draws the handles: the focus stays on the same one
		const active = doc.activeElement;
		const keep = active && active.closest && active.closest('#tl-bd-overlay') ? active.getAttribute('data-bdo') : null;
		root.replaceChildren();
		C.layer = root;
		C.items = [];
		C.node = null;
		C.geometry = null;
		// on a zoomed-out canvas the handles grow with the zoom, but never beyond 1.8 times: a huge toolbar would cover the page
		root.style.setProperty('--s', String(round(C.factor(), 3)));
		if (state.placing) { return; } // tapping the place to move to: nothing of the overlay may be in the way
		const picks = B.selectedIds().map((id) => usable(doc, id)).filter(Boolean);
		if (!picks.length) { return; }
		const main = picks.find((x) => x.n.p.id === state.selected) || picks[0];
		C.node = main.node;
		C.geometry = C.measure(main.node);
		C.toolbar(doc, picks, main);
		if (picks.length === 1 && !state.editingCanvas) { C.providers.forEach((fn) => fn({ doc, win: doc.defaultView, id: main.n.p.id, p: main.n.p, n: main.n, node: main.node })); }
		C.placeAll();
		const again = keep && root.querySelector('[data-bdo="' + keep + '"]');
		if (again) { again.focus({ preventScroll: true }); }
	};
	let pending = 0;
	C.later = () => { clearTimeout(pending); pending = setTimeout(C.render, 30); };

	/* ---------- the floating toolbar ---------- */

	const ICONS = {
		alignstart: '<path d="M4 6h16M4 12h10M4 18h13"/>', aligncenter: '<path d="M4 6h16M7 12h10M5.5 18h13"/>', alignend: '<path d="M4 6h16M10 12h10M7 18h13"/>',
		group: '<rect x="3" y="3" width="18" height="18" rx="3" stroke-dasharray="3 2"/><rect x="7" y="7" width="4" height="4"/><rect x="13" y="13" width="4" height="4"/>',
		ungroup: '<rect x="3" y="3" width="8" height="8" rx="1"/><rect x="13" y="13" width="8" height="8" rx="1"/>',
		replace: '<path d="M4 7h13l-3-3M20 17H7l3 3"/>',
	};
	function icon(doc, name) {
		if (ICONS[name]) { const s = mk(doc, 'span'); s.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true">' + ICONS[name] + '</svg>'; return s.firstChild; }
		return doc.importNode(B.icon(name), true);
	}
	/** An element's text alignment: text-like elements by text-align, others by auto margins (a button in a text line aligns its parent's line). */
	C.align = (where) => {
		const doc = B.previewDoc();
		const win = doc.defaultView;
		const textLike = ['heading', 'text', 'list', 'testimonial', 'counter', 'breadcrumbs'];
		const list = [];
		let line = false;
		B.topSelected().forEach((id) => {
			const found = B.find(id);
			const node = C.nodeOf(doc, id);
			if (!found || !node) { return; }
			if (textLike.includes(found.p.type)) { list.push([id, { text_align: where }]); return; }
			const parentStyle = node.parentElement ? win.getComputedStyle(node.parentElement) : null;
			const inLayout = parentStyle && /flex|grid/.test(parentStyle.display);
			if (!inLayout && /^inline/.test(win.getComputedStyle(node).display) && found.parent) { list.push([found.parent.id, { text_align: where }]); line = true; return; }
			// the "reset" value for a smaller screen has to override the larger one explicitly, on desktop it is just removed
			const none = state.bp === 'base' ? '' : '0';
			list.push([id, { start: { margin_left: none, margin_right: 'auto', center: state.bp === 'base' ? '' : undefined }, center: { center: 'auto', margin_left: none, margin_right: none }, end: { margin_left: 'auto', margin_right: none, center: state.bp === 'base' ? '' : undefined } }[where]]);
		});
		if (!list.length) { return; }
		B.writeStyle(list);
		B.setState(line ? T('A button sits in a line of text: the line of its container was aligned.') : T('Aligned.'));
	};

	/** Bold / italic: right away on the text being edited, otherwise the whole text is opened for editing first. */
	function format(doc, command, node) {
		if (!doc.querySelector('[contenteditable]')) {
			B.editOnCanvas(node);
			if (!state.editingCanvas) { return; }
			doc.execCommand('selectAll');
		}
		doc.execCommand(command);
	}
	/** A link on the selected words. The dialog of the editor takes the focus, so the editing is held until it closes and the selection is put back. */
	async function link(doc) {
		const editing = doc.querySelector('[contenteditable]');
		const selection = doc.getSelection();
		const range = editing && selection && selection.rangeCount ? selection.getRangeAt(0).cloneRange() : null;
		if (!editing || !range || range.collapsed) { B.setState(T('Double-click the text and select the words to link first.'), true); return; }
		const anchor = range.commonAncestorContainer.parentElement && range.commonAncestorContainer.parentElement.closest('a');
		state.keepEditing = true;
		const url = await B.askName(T('Link'), T('Address: https://…, /page, #anchor, mailto: or tel:'), anchor ? anchor.getAttribute('href') : '', null);
		state.keepEditing = false;
		editing.focus();
		selection.removeAllRanges();
		selection.addRange(range);
		if (url && /^(https?:\/\/|\/|#|mailto:|tel:)/i.test(url)) { doc.execCommand('createLink', false, url); } else if (url) { B.setState(T('Use an address that starts with https://, /, #, mailto: or tel:.'), true); }
	}
	/** One step up or down the font size scale (the nearest step to the current size, then the neighbour). */
	function stepFont(doc, node, id, direction) {
		const steps = C.steps(doc);
		const px = parseFloat(doc.defaultView.getComputedStyle(node).fontSize);
		let index = steps.reduce((best, s, i) => (Math.abs(s.px - px) < Math.abs(steps[best].px - px) ? i : best), 0);
		index = Math.max(0, Math.min(steps.length - 1, index + direction));
		B.writeStyle([[id, { font_size: steps[index].key }]]);
		C.announce(T('Font size step %s.').replace('%s', steps[index].key));
	}

	C.toolbar = (doc, picks, main) => {
		const many = picks.length > 1;
		const p = main.n.p;
		const id = p.id;
		const tb = mk(doc, 'div', { class: 'bdo-tb', role: 'toolbar', 'aria-label': T('Element toolbar') });
		// the toolbar must not take the focus (or the selection) from text being edited
		tb.addEventListener('mousedown', (e) => { if (!e.target.closest('[data-bdo="move"]')) { e.preventDefault(); } });
		const add = (name, label, run, content, pressed) => {
			const b = mk(doc, 'button', { type: 'button', class: 'bdo-b', 'data-bdo': name, title: label, 'aria-label': label, 'aria-pressed': pressed ? 'true' : null });
			b.append(content);
			if (run) { b.addEventListener('click', (e) => { e.preventDefault(); e.stopPropagation(); run(e); }); }
			tb.append(b);
			return b;
		};
		const separator = () => tb.append(mk(doc, 'span', { class: 'bdo-sep', role: 'separator' }));
		const text = (t, style) => { const s = mk(doc, 'span'); s.textContent = t; if (style) { s.style.cssText = style; } return s; };

		// move: a pointer gesture (drag), or a tap that starts "tap the place" for the people who cannot drag
		const grip = add('move', T('Drag to move, or tap and then tap the place. Alt-drag duplicates.'), null, icon(doc, 'move'));
		C.moveGesture(grip);
		if (!many && main.n.parent) { add('parent', T('Select parent element (Esc)'), () => B.selection(main.n.parent.id), icon(doc, 'parent')); }
		separator();
		if (!many) {
			if (['heading', 'text'].includes(p.type)) {
				add('bold', T('Bold'), () => format(doc, 'bold', main.node), text('B', 'font-weight:800'));
				add('italic', T('Italic'), () => format(doc, 'italic', main.node), text('I', 'font-style:italic;font-family:Georgia,serif'));
				add('link', T('Link on the selected words'), () => link(doc), icon(doc, 'share'));
			}
			if (['heading', 'text', 'button', 'list', 'testimonial'].includes(p.type)) {
				add('smaller', T('Smaller text (one step)'), () => stepFont(doc, main.node, id, -1), text('A−'));
				add('larger', T('Larger text (one step)'), () => stepFont(doc, main.node, id, 1), text('A+'));
			}
			if (p.type === 'button') {
				add('link', T('Link of the button'), () => B.askName(T('Link of the button'), T('Address: https://…, /page, #anchor, mailto: or tel:'), p.content.link === '#' ? '' : p.content.link, null).then((v) => { if (v !== null) { B.applyChange(() => { p.content.link = v; }); } }), icon(doc, 'share'));
				const variants = Object.keys(B.TYPY.button.properties.variant.options);
				add('variant', T('Next appearance of the button'), () => { const next = variants[(variants.indexOf(p.content.variant) + 1) % variants.length]; B.applyChange(() => { p.content.variant = next; }); C.announce(next); }, icon(doc, 'button'));
			}
			if (p.type === 'image') {
				add('replace', T('Replace the image'), () => window.taleaPickImage && window.taleaPickImage((o) => { B.applyChange(() => { p.content.src = o.url; if (!p.content.alt && o.name) { p.content.alt = o.name; } }); }), icon(doc, 'replace'));
				add('alt', T('Description for blind visitors (alt)'), () => B.askName(T('Image description'), T('What does the image show?'), p.content.alt || '', null).then((v) => { if (v !== null) { B.applyChange(() => { p.content.alt = v; }); } }), text('alt', 'font-size:calc(11px*var(--s))'));
			}
		}
		separator();
		add('align-start', T('Align left'), () => C.align('start'), icon(doc, 'alignstart'));
		add('align-center', T('Align centre'), () => C.align('center'), icon(doc, 'aligncenter'));
		add('align-end', T('Align right'), () => C.align('end'), icon(doc, 'alignend'));
		separator();
		add('duplicate', T('Duplicate (Ctrl+D)'), () => B.duplicate(), icon(doc, 'copy'));
		if (many || (main.n.parent && p.type !== 'section')) { add('group', T('Group into a container (Ctrl+G)'), () => B.group(), icon(doc, 'group')); }
		if (!many && B.TYPY[p.type].container && p.type !== 'section' && p.children && p.children.length && main.n.parent) { add('ungroup', T('Ungroup: keep the elements, remove the container (Ctrl+Shift+G)'), () => B.ungroup(), icon(doc, 'ungroup')); }
		add('delete', T('Delete (Delete)'), () => B.remove(), icon(doc, 'delete'));

		const union = picks.map((x) => C.page(x.node)).reduce((a, r) => ({ left: Math.min(a.left, r.left), top: Math.min(a.top, r.top), right: Math.max(a.right, r.right), bottom: Math.max(a.bottom, r.bottom) }));
		C.layer.append(tb);
		const place = () => {
			const win = doc.defaultView;
			const m = C.factor();
			const box = tb.getBoundingClientRect();
			const w = box.width * 1; // already in the canvas's own pixels
			const h = box.height;
			const left = Math.max(4 * m, Math.min(union.left, doc.documentElement.clientWidth + win.scrollX - w - 4 * m));
			let top = union.top - h - 6 * m;
			if (top < win.scrollY + 4 * m) { top = Math.min(union.bottom - h, Math.max(top, win.scrollY + 4 * m)); } // the top of the element is scrolled away: the toolbar stays in view
			if (top < 4 * m) { top = union.bottom + 6 * m; }
			tb.style.left = left + 'px';
			tb.style.top = Math.max(0, top) + 'px';
		};
		C.items.push({ el: tb, place: () => { const u = C.page(main.node); union.left = many ? union.left : u.left; union.top = many ? union.top : u.top; union.right = many ? union.right : u.right; union.bottom = many ? union.bottom : u.bottom; place(); } });
		place();
		if (!C.scrollBound || C.scrollBound !== doc) {
			C.scrollBound = doc;
			let frame = 0;
			doc.addEventListener('scroll', () => { if (!frame) { frame = doc.defaultView.requestAnimationFrame(() => { frame = 0; if (!state.gesture) { C.placeAll(); } }); } }, { passive: true });
		}
	};

	/* ---------- moving with a pointer: the toolbar's grip ---------- */

	C.moveGesture = (grip) => {
		C.drag(grip, {
			start: (e) => {
				const doc = grip.ownerDocument;
				const ids = B.topSelected();
				if (!ids.length) { return null; }
				const nodes = ids.map((id) => C.nodeOf(doc, id)).filter(Boolean);
				const rects = nodes.map(C.page);
				const box = { left: Math.min(...rects.map((r) => r.left)), top: Math.min(...rects.map((r) => r.top)), right: Math.max(...rects.map((r) => r.right)), bottom: Math.max(...rects.map((r) => r.bottom)) };
				const ghost = mk(doc, 'div', { class: 'bdo-ghost', style: 'left:' + box.left + 'px;top:' + box.top + 'px;width:' + (box.right - box.left) + 'px;height:' + (box.bottom - box.top) + 'px;display:none' });
				C.layer.append(ghost);
				state.dragging = { move: ids[0], more: ids.slice(1), copy: e.altKey };
				return { doc, ids, ghost, box, copy: e.altKey, place: null, guides: C.prepareGuides(doc, nodes), scroller: 0, y: 0 };
			},
			move: (ctx, dx, dy, e) => {
				const doc = ctx.doc;
				const win = doc.defaultView;
				ctx.ghost.style.display = '';
				ctx.ghost.style.transform = 'translate(' + dx + 'px,' + dy + 'px)';
				C.showGuides(ctx.guides, { left: ctx.box.left + dx, right: ctx.box.right + dx, top: ctx.box.top + dy, bottom: ctx.box.bottom + dy });
				const hit = doc.elementsFromPoint(e.clientX, e.clientY).find((n) => !n.closest('#tl-bd-overlay'));
				ctx.place = hit ? B.canvasSpot(doc, { target: hit, clientX: e.clientX, clientY: e.clientY }) : null;
				B.showSpot(doc, ctx.place);
				ctx.y = e.clientY;
				// the page scrolls while the pointer rests near the top or bottom edge
				const edge = e.clientY < 48 ? -1 : (e.clientY > win.innerHeight - 48 ? 1 : 0);
				if (edge && !ctx.scroller) { ctx.scroller = win.setInterval(() => win.scrollBy(0, ctx.edge * 14), 16); }
				if (!edge && ctx.scroller) { win.clearInterval(ctx.scroller); ctx.scroller = 0; }
				ctx.edge = edge;
			},
			end: (ctx, moved) => {
				const win = ctx.doc.defaultView;
				if (ctx.scroller) { win.clearInterval(ctx.scroller); }
				ctx.ghost.remove();
				B.showSpot(ctx.doc, null);
				state.dragging = null;
				if (!moved) { B.startPlacing(ctx.ids[0]); return; }
				if (ctx.place) { B.dropAt({ move: ctx.ids[0], more: ctx.ids.slice(1), copy: ctx.copy }, ctx.place); }
			},
			cancel: (ctx) => {
				if (ctx.scroller) { ctx.doc.defaultView.clearInterval(ctx.scroller); }
				ctx.ghost.remove();
				B.showSpot(ctx.doc, null);
				state.dragging = null;
			},
		});
	};

	/* ---------- a marquee on empty space selects several elements ---------- */

	function marquee(doc) {
		doc.addEventListener('pointerdown', (e) => {
			if (e.pointerType === 'touch' || (e.pointerType === 'mouse' && e.button !== 0) || state.gesture || state.placing || state.editingCanvas || e.target.closest('#tl-bd-overlay')) { return; }
			const hit = e.target.closest ? e.target.closest('[data-tl-id]') : null;
			// empty space: outside any element, on an element's own free area, or on a layout wrapper of a top-level section (it fills the whole width)
			const wrapper = /^(DIV|SECTION|MAIN|ARTICLE|ASIDE|HEADER|FOOTER|BODY|HTML)$/.test(e.target.tagName);
			if (hit && hit !== e.target && !(wrapper && !hit.parentElement.closest('[data-tl-id]'))) { return; }
			const win = doc.defaultView;
			const x0 = e.clientX + win.scrollX;
			const y0 = e.clientY + win.scrollY;
			let box = null;
			const root = C.ensure(doc);
			const onMove = (ev) => {
				const x = ev.clientX + win.scrollX;
				const y = ev.clientY + win.scrollY;
				if (!box && Math.abs(x - x0) + Math.abs(y - y0) < 6) { return; }
				if (!box) { box = mk(doc, 'div', { class: 'bdo-marquee' }); root.append(box); state.gesture = true; }
				ev.preventDefault();
				Object.assign(box.style, { left: Math.min(x, x0) + 'px', top: Math.min(y, y0) + 'px', width: Math.abs(x - x0) + 'px', height: Math.abs(y - y0) + 'px' });
			};
			const finish = (ev, apply) => {
				doc.removeEventListener('pointermove', onMove);
				doc.removeEventListener('pointerup', onUp);
				doc.removeEventListener('pointercancel', onAbort);
				doc.removeEventListener('keydown', onKey, true);
				if (!box) { return; }
				const x = ev.clientX + win.scrollX;
				const y = ev.clientY + win.scrollY;
				box.remove();
				B.endGesture();
				if (!apply) { return; }
				// the click that follows the drag must not replace the new selection
				const block = (c) => { c.stopImmediatePropagation(); c.preventDefault(); };
				win.addEventListener('click', block, { capture: true, once: true });
				setTimeout(() => win.removeEventListener('click', block, true), 50);
				const sel = { left: Math.min(x, x0), top: Math.min(y, y0), right: Math.max(x, x0), bottom: Math.max(y, y0) };
				const inside = Array.from(doc.querySelectorAll('[data-tl-id]')).filter((n) => {
					const r = C.page(n);
					return r.width > 0 && r.height > 0 && r.left >= sel.left && r.right <= sel.right && r.top >= sel.top && r.bottom <= sel.bottom && B.find(n.getAttribute('data-tl-id')) && !n.closest('[data-tl-lock]');
				});
				const top = inside.filter((n) => !inside.some((o) => o !== n && o.contains(n)));
				B.selectMany(top.map((n) => n.getAttribute('data-tl-id')));
			};
			const onUp = (ev) => finish(ev, true);
			const onAbort = (ev) => finish(ev, false);
			const onKey = (ev) => { if (ev.key === 'Escape') { ev.stopPropagation(); finish({ clientX: 0, clientY: 0 }, false); } };
			doc.addEventListener('pointermove', onMove);
			doc.addEventListener('pointerup', onUp);
			doc.addEventListener('pointercancel', onAbort);
			doc.addEventListener('keydown', onKey, true);
		});
	}

	/* ---------- files dropped from the computer: uploaded to Media and inserted at the drop spot ---------- */

	const hasFiles = (e) => e.dataTransfer && Array.from(e.dataTransfer.types || []).includes('Files');
	function fileDrop(doc) {
		doc.addEventListener('dragover', (e) => {
			if (!hasFiles(e)) { return; }
			e.preventDefault();
			e.dataTransfer.dropEffect = 'copy';
			state.dragging = { newType: 'image' };
			const place = B.canvasSpot(doc, e);
			state.dragging = null;
			B.showSpot(doc, place);
		});
		doc.addEventListener('drop', async (e) => {
			if (!hasFiles(e)) { return; }
			e.preventDefault();
			state.dragging = { newType: 'image' };
			const place = B.canvasSpot(doc, e);
			state.dragging = null;
			B.showSpot(doc, null);
			const files = Array.from(e.dataTransfer.files);
			const usableFiles = files.filter((f) => /^image\//.test(f.type) || /^video\/(mp4|webm)$/.test(f.type));
			if (!place) { B.setState(T('Drop the files onto an element or between elements of the page.'), true); return; }
			if (!usableFiles.length) { B.setState(T('Only images and MP4 or WebM videos can be dropped on the page.'), true); return; }
			B.setState(T('Uploading…'));
			const uploaded = [];
			for (const file of usableFiles) {
				const j = await B.query(B.D.urls.admin + '?module=media&action=upload&format=json', { 'files[]': file, folder_id: '0' });
				if (j.images && j.images.length) { uploaded.push(...j.images); } else { B.setState((j.errors && j.errors[0]) || j.error || T('Upload failed. Check your connection and try again.'), true); }
			}
			if (!uploaded.length) { return; }
			const elements = uploaded.map((o) => {
				const video = /\.(mp4|webm)$/i.test(o.url);
				const element = B.newElement(video ? 'video' : 'image');
				if (video) { element.content.url = o.url; } else { element.content.src = o.url; element.content.alt = o.name || ''; }
				return element;
			});
			// "after" puts each one right behind the spot, so they go in reverse to keep the order of the files
			(place.where === 'after' ? elements.slice().reverse() : elements).forEach((element) => B.dropAt({ custom: element }, place));
			B.setState(uploaded.length === 1 ? T('File uploaded and inserted.') : T('%d files uploaded and inserted.').replace('%d', String(uploaded.length)));
		});
	}

	/* ---------- wiring ---------- */

	B.on('preview', (doc) => { C.ensure(doc); marquee(doc); fileDrop(doc); new doc.defaultView.ResizeObserver(() => { if (!state.gesture) { C.later(); } }).observe(doc.documentElement); });
	B.on('mark', () => C.render());
	B.on('resize', () => C.later());
	B.on('edit', () => C.later());
	B.on('placing', () => C.later());
})();
