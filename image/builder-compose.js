/* Talea – the page builder's free placement on the canvas: the Compose section (compose phase B, see docs/specs/visual-compose.md).
 *
 * A Section with the layout "compose" is a fixed grid of 12 columns and rows of one spacing token (Elements\Section::composeCss). Its direct children are
 * placed by grid LINES (style properties grid_column_start … grid_row_end, 1–13 and 1–40) and may overlap, ordered by a layer from a small scale.
 * This file draws the 12-column overlay, a move grip and eight resize handles on a selected child (all snapping to the grid lines, all focusable
 * sliders with arrow keys), the toolbar buttons "Compose / Stack", "Tidy up" and the layer buttons, and converts a section between the two layouts.
 * Everything ends as the normal style of the CURRENT screen size in one history step; the server (Build::sanitize) drops the properties anywhere
 * else. A section stacks on tablet and phone, so on those canvases the compose handles are absent unless the grid is shown there ("phone only"). */
(function () {
	'use strict';

	const B = window.taleaBuilder;
	const C = window.taleaCompose;
	if (!B || !C) { return; }
	const { T, state } = B;
	const mk = C.mk;

	const COLUMNS = 12;
	const LAST_COLUMN_LINE = 13;
	const LAST_ROW_LINE = 40;
	const PLACEMENT = ['grid_column_start', 'grid_column_end', 'grid_row_start', 'grid_row_end'];
	const LAYERS = ['below', 'base', 'above', 'top'];
	const CHAIN = { base: ['base'], tablet: ['tablet', 'base'], mobile: ['mobile', 'tablet', 'base'] };
	const num = (v) => parseFloat(v) || 0;
	const clamp = (v, low, high) => Math.max(low, Math.min(high, v));

	Object.assign(C.ICONS, {
		compose: '<rect x="3" y="3" width="8" height="8" rx="1"/><rect x="13" y="3" width="8" height="5" rx="1"/><rect x="3" y="13" width="5" height="8" rx="1"/><rect x="10" y="10" width="11" height="11" rx="1"/>',
		tidy: '<path d="M4 6h16M4 12h16M4 18h16"/><path d="M8 3v6M8 15v6"/>',
		forward: '<rect x="3" y="3" width="11" height="11" rx="1"/><rect x="10" y="10" width="11" height="11" rx="1" fill="currentColor"/>',
		backward: '<rect x="3" y="3" width="11" height="11" rx="1" fill="currentColor"/><rect x="10" y="10" width="11" height="11" rx="1"/>',
	});

	const STYLE = `
#tl-bd-overlay .bdo-cg{position:absolute;pointer-events:none;background:rgb(107 78 255/.09);border-inline:calc(1px*var(--s)) dashed rgb(107 78 255/.4)}
#tl-bd-overlay .bdo-rg{position:absolute;pointer-events:none;background:repeating-linear-gradient(to bottom,rgb(107 78 255/.35) 0 calc(1px*var(--s)),transparent calc(1px*var(--s)) 100%);opacity:.5}
#tl-bd-overlay .bdo-cell{position:absolute;pointer-events:none;border:calc(2px*var(--s)) solid #6b4eff;background:rgb(107 78 255/.14);border-radius:calc(2px*var(--s))}
#tl-bd-overlay .bdo-mv{width:calc(22px*var(--s));height:calc(22px*var(--s));border-radius:calc(6px*var(--s));background:#6b4eff;border:calc(1.5px*var(--s)) solid #fff;box-shadow:0 0 0 calc(1px*var(--s)) rgb(0 0 0/.35);cursor:grab;display:grid;place-items:center;transform:none}
#tl-bd-overlay .bdo-mv svg{width:calc(14px*var(--s));height:calc(14px*var(--s));fill:none;stroke:#fff;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
#tl-bd-overlay .bdo-cr{background:#6b4eff;border-color:#fff}
`;

	/* ---------- where things are: the grid of a compose container, in page coordinates ---------- */

	/** The compose container of a node (the node's parent when it is a placed child), or null. A stacked (flex) container has no grid to place on. */
	C.composeParent = (node) => {
		const parent = node && node.parentElement;
		return parent && parent.classList.contains('tl-compose') && node.hasAttribute('data-tl-id') && node.ownerDocument.defaultView.getComputedStyle(parent).display === 'grid' ? parent : null;
	};
	/** The grid of a compose section: the section itself (full width) or its inner wrapper. */
	function gridOf(sectionNode) {
		const container = sectionNode.classList.contains('tl-compose') ? sectionNode : sectionNode.querySelector(':scope > .tl-compose');
		return container && container.ownerDocument.defaultView.getComputedStyle(container).display === 'grid' ? container : null;
	}

	/** The lines of the grid: x(1…13), y(1…40) in page coordinates, the nearest line to a coordinate, the row unit. Read from the canvas, never recomputed. */
	function metrics(doc, container) {
		const cs = doc.defaultView.getComputedStyle(container);
		const r = C.page(container);
		const left = r.left + num(cs.borderLeftWidth) + num(cs.paddingLeft);
		const top = r.top + num(cs.borderTopWidth) + num(cs.paddingTop);
		const widths = cs.gridTemplateColumns.split(' ').map(parseFloat).filter((x) => !isNaN(x));
		if (widths.length !== COLUMNS) { return null; }
		const gap = num(cs.columnGap);
		const starts = [];
		let at = left;
		widths.forEach((w) => { starts.push(at); at += w + gap; });
		const xs = [starts[0]];
		for (let k = 2; k <= COLUMNS; k++) { xs.push(starts[k - 1] - gap / 2); }
		xs.push(starts[COLUMNS - 1] + widths[COLUMNS - 1]);
		// rows grow with their content (minmax(unit, auto)): the lines of the rows that exist, then the unit
		const unit = Math.max(8, num(cs.gridAutoRows.split(' ')[0]) || 24);
		const ys = [top];
		cs.gridTemplateRows.split(' ').map(parseFloat).filter((x) => !isNaN(x)).forEach((h) => ys.push(ys[ys.length - 1] + h));
		while (ys.length < LAST_ROW_LINE) { ys.push(ys[ys.length - 1] + unit); }
		const nearest = (list, v) => list.reduce((best, x, i) => (Math.abs(x - v) < Math.abs(list[best] - v) ? i : best), 0) + 1;
		return { xs, ys, unit, left, right: xs[COLUMNS], x: (line) => xs[line - 1], y: (line) => ys[line - 1], lineX: (v) => nearest(xs, v), lineY: (v) => nearest(ys, v), width: xs[COLUMNS] - xs[0] };
	}

	/** The value of a style property as the current screen size sees it (it inherits from the larger ones); 0 = not set. */
	function modelNumber(p, key) {
		for (const s of CHAIN[state.bp] || ['base']) {
			const v = p.style && !Array.isArray(p.style) && p.style[s] ? p.style[s][key] : undefined;
			if (v) { return parseInt(v, 10) || 0; }
		}
		return 0;
	}
	const modelLayer = (p) => { for (const s of CHAIN[state.bp] || ['base']) { const v = p.style && !Array.isArray(p.style) && p.style[s] ? p.style[s].layer : undefined; if (v) { return v; } } return 'base'; };

	/** The cell of an element: its stored lines, or (not placed yet) the lines its box is nearest to. {c0, c1, r0, r1}: end lines are exclusive. */
	function cellOf(p, node, m) {
		const [c0, c1, r0, r1] = PLACEMENT.map((k) => modelNumber(p, k));
		if (c0 && c1 > c0 && r0 && r1 > r0) { return { c0, c1, r0, r1 }; }
		const r = C.page(node);
		const a = clamp(m.lineX(r.left), 1, COLUMNS);
		const b = clamp(m.lineY(r.top), 1, LAST_ROW_LINE - 1);
		return { c0: a, c1: clamp(m.lineX(r.right), a + 1, LAST_COLUMN_LINE), r0: b, r1: clamp(m.lineY(r.bottom), b + 1, LAST_ROW_LINE) };
	}
	const props = (c) => ({ grid_column_start: String(c.c0), grid_column_end: String(c.c1), grid_row_start: String(c.r0), grid_row_end: String(c.r1) });
	const describe = (c) => T('Columns') + ' ' + c.c0 + '–' + (c.c1 - 1) + ' · ' + T('Rows') + ' ' + c.r0 + '–' + (c.r1 - 1);
	const sameCell = (a, b) => a.c0 === b.c0 && a.c1 === b.c1 && a.r0 === b.r0 && a.r1 === b.r1;

	/** Temporary inline placement on the node while a gesture runs; restore() puts the style attribute back. */
	function live(node) {
		const saved = node.getAttribute('style');
		return {
			set: (c) => { node.style.gridColumn = c.c0 + ' / ' + c.c1; node.style.gridRow = c.r0 + ' / ' + c.r1; },
			restore: () => { if (saved === null) { node.removeAttribute('style'); } else { node.setAttribute('style', saved); } },
		};
	}

	/* ---------- the overlay: 12 columns, the rows, the target cell ---------- */

	function gridOverlay(doc, container) {
		const first = metrics(doc, container);
		if (!first) { return null; }
		for (let i = 0; i < COLUMNS; i++) {
			const band = mk(doc, 'div', { class: 'bdo-cg' });
			C.add(band, () => {
				const m = metrics(doc, container);
				const r = C.page(container);
				if (!m) { band.style.display = 'none'; return; }
				band.style.display = '';
				// the band of a column is drawn between the lines on its sides
				Object.assign(band.style, { left: m.x(i + 1) + 'px', width: (m.x(i + 2) - m.x(i + 1)) + 'px', top: r.top + 'px', height: Math.max(r.height, 1) + 'px' });
			});
		}
		const rows = mk(doc, 'div', { class: 'bdo-rg' });
		C.add(rows, () => {
			const m = metrics(doc, container);
			const r = C.page(container);
			if (!m) { rows.style.display = 'none'; return; }
			rows.style.display = '';
			Object.assign(rows.style, { left: m.left + 'px', width: m.width + 'px', top: r.top + 'px', height: Math.max(r.height, 1) + 'px', backgroundSize: '100% ' + m.unit + 'px' });
		});
		return first;
	}

	/** The target cell while a gesture runs. */
	function targetCell(doc, m) {
		const cell = mk(doc, 'div', { class: 'bdo-cell' });
		C.layer.append(cell);
		return {
			show: (c) => Object.assign(cell.style, { display: '', left: m.x(c.c0) + 'px', width: (m.x(c.c1) - m.x(c.c0)) + 'px', top: m.y(c.r0) + 'px', height: (m.y(c.r1) - m.y(c.r0)) + 'px' }),
			remove: () => cell.remove(),
		};
	}

	/* ---------- handles on a placed child ---------- */

	const SIDES = { n: T('top'), s: T('bottom'), e: T('right'), w: T('left') };
	const ARROW = { ArrowRight: [1, 0], ArrowLeft: [-1, 0], ArrowDown: [0, 1], ArrowUp: [0, -1] };

	function write(id, cell, keyboard) {
		B.writeStyle([[id, props(cell)]], keyboard ? 'kb:' + id + ':cell' : undefined);
		if (keyboard) { C.later(); }
	}

	/**
	 * A handle with a pointer gesture and the arrow keys. plan(session, dx, dy) / step(session, [dx, dy]) → the new cell (or null).
	 * The gesture and the key write the same way: one history step, the live view on the node meanwhile.
	 */
	function bind(h, { begin, plan, step }) {
		C.drag(h, {
			start: () => begin(),
			move: (s, dx, dy, e) => {
				const c = plan(s, dx, dy);
				if (!c) { return; }
				s.last = c;
				s.live.set(c);
				s.target.show(c);
				C.say(describe(c), e.clientX + s.win.scrollX, e.clientY + s.win.scrollY);
			},
			end: (s, moved) => {
				s.target.remove();
				if (!moved || !s.last || sameCell(s.last, s.cell)) { s.live.restore(); return; }
				write(s.id, s.last, false);
			},
			cancel: (s) => { s.target.remove(); s.live.restore(); },
		});
		h.addEventListener('keydown', (e) => {
			if (e.key === 'Escape') { e.stopPropagation(); h.blur(); return; }
			if (!(e.key in ARROW)) { return; }
			e.preventDefault();
			e.stopPropagation(); // the arrows belong to the handle here, not to "select the next element"
			const s = begin();
			const c = s && step(s, ARROW[e.key]);
			s && s.target.remove();
			if (!c || sameCell(c, s.cell)) { return; }
			s.live.set(c);
			h.setAttribute('aria-valuetext', describe(c));
			C.announce(describe(c));
			write(s.id, c, true);
		});
		h.addEventListener('focus', () => C.announce(h.getAttribute('aria-label') + ': ' + h.getAttribute('aria-valuetext')));
	}

	function placed(ctx, container) {
		const { doc, id, node, p } = ctx;
		const win = doc.defaultView;
		const first = metrics(doc, container);
		if (!first) { return; }
		const start = cellOf(p, node, first);
		const begin = () => {
			const m = metrics(doc, container);
			if (!m) { return null; }
			const cell = cellOf(p, node, m);
			return { id, node, win, m, cell, last: null, live: live(node), target: targetCell(doc, m) };
		};
		const slider = (classes, key, label) => {
			const h = C.slider(doc, classes, key, label);
			C.value(h, 1, LAST_COLUMN_LINE, start.c0, describe(start));
			return h;
		};

		// move: a grip inside the top left corner; the arrow keys step one cell
		const grip = slider('bdo-mv', 'compose-move', T('Move on the grid (arrow keys: one cell)'));
		grip.append(C.iconNode(doc, 'move'));
		C.add(grip, (g) => { grip.style.left = (g.r.left + 6) + 'px'; grip.style.top = (g.r.top + 6) + 'px'; });
		bind(grip, {
			begin,
			plan: (s, dx, dy) => {
				const w = s.cell.c1 - s.cell.c0;
				const h = s.cell.r1 - s.cell.r0;
				const c0 = clamp(s.m.lineX(s.m.x(s.cell.c0) + dx), 1, LAST_COLUMN_LINE - w);
				const r0 = clamp(s.m.lineY(s.m.y(s.cell.r0) + dy), 1, LAST_ROW_LINE + 1 - h);
				return { c0, c1: c0 + w, r0, r1: r0 + h };
			},
			step: (s, [dx, dy]) => {
				const c = s.cell;
				const w = c.c1 - c.c0;
				const h = c.r1 - c.r0;
				const c0 = clamp(c.c0 + dx, 1, LAST_COLUMN_LINE - w);
				const r0 = clamp(c.r0 + dy, 1, LAST_ROW_LINE + 1 - h);
				return { c0, c1: c0 + w, r0, r1: r0 + h };
			},
		});

		// resize: the four edges and the four corners; an edge moves to the nearest line
		['n', 's', 'e', 'w', 'ne', 'nw', 'se', 'sw'].forEach((dir) => {
			const label = T('Resize on the grid') + ': ' + dir.split('').map((d) => SIDES[d]).join(' ');
			const h = slider('bdo-rz bdo-cr', 'resize-' + dir, label);
			h.style.cursor = { n: 'ns-resize', s: 'ns-resize', e: 'ew-resize', w: 'ew-resize', ne: 'nesw-resize', sw: 'nesw-resize', nw: 'nwse-resize', se: 'nwse-resize' }[dir];
			C.add(h, (g) => {
				const r = g.r;
				const x = dir.includes('w') ? r.left : (dir.includes('e') ? r.right : (r.left + r.right) / 2);
				const y = dir.includes('n') ? r.top : (dir.includes('s') ? r.bottom : (r.top + r.bottom) / 2);
				// an edge handle on a very short edge would sit on the corners: the corners do the job
				h.style.display = dir.length === 1 && ((/[ew]/.test(dir) && r.height < 30) || (/[ns]/.test(dir) && r.width < 30)) ? 'none' : '';
				h.style.left = x + 'px';
				h.style.top = y + 'px';
			});
			// the edge a direction moves: [dx, dy] in lines (keys) or pixels (pointer)
			const moveCell = (s, dx, dy, byLines) => {
				const c = Object.assign({}, s.cell);
				if (dir.includes('e')) { c.c1 = clamp(byLines ? c.c1 + dx : s.m.lineX(s.m.x(c.c1) + dx), c.c0 + 1, LAST_COLUMN_LINE); }
				if (dir.includes('w')) { c.c0 = clamp(byLines ? c.c0 + dx : s.m.lineX(s.m.x(c.c0) + dx), 1, c.c1 - 1); }
				if (dir.includes('s')) { c.r1 = clamp(byLines ? c.r1 + dy : s.m.lineY(s.m.y(c.r1) + dy), c.r0 + 1, LAST_ROW_LINE); }
				if (dir.includes('n')) { c.r0 = clamp(byLines ? c.r0 + dy : s.m.lineY(s.m.y(c.r0) + dy), 1, c.r1 - 1); }
				return c;
			};
			bind(h, { begin, plan: (s, dx, dy) => moveCell(s, dx, dy, false), step: (s, [dx, dy]) => moveCell(s, dx, dy, true) });
		});
	}

	/* ---------- the provider: the overlay of a selected compose section, the handles of a selected child ---------- */

	function provider(ctx) {
		const { doc, node, p } = ctx;
		const container = p.type === 'section' ? gridOf(node) : C.composeParent(node);
		if (!container || container.closest('[data-tl-lock]')) { return; }
		if (!gridOverlay(doc, container)) { return; }
		if (p.type !== 'section') { placed(ctx, container); }
	}
	C.providers.push(provider);

	/* ---------- converting a section: Stack ⇄ Compose, tidy up, layers ---------- */

	/** The inner wrapper (or the section itself when it is full width) a section's children sit in. */
	const childrenBox = (node) => (node.classList.contains('tl-compose') ? node : node.querySelector(':scope > .tl-wrap') || node);

	/** Writes a cell into the style of one screen size of an element (in the model; call inside applyChange). */
	function setCell(p, cell, bp) {
		p.style = p.style && !Array.isArray(p.style) ? p.style : {};
		p.style[bp] = Object.assign(p.style[bp] || {}, props(cell));
	}
	function clearPlacement(p) {
		Object.keys(p.style && !Array.isArray(p.style) ? p.style : {}).forEach((s) => {
			[...PLACEMENT, 'layer'].forEach((k) => { delete p.style[s][k]; });
			if (!Object.keys(p.style[s]).length) { delete p.style[s]; }
		});
	}

	/**
	 * The cells the children occupy now: from their boxes on the desktop canvas (with the compose grid laid over the container as it is now),
	 * otherwise one clean column. Returns [[child, cell]] in the same order.
	 */
	function cellsFromLayout(doc, sectionNode, p) {
		const box = childrenBox(sectionNode);
		const win = doc.defaultView;
		const cs = win.getComputedStyle(box);
		const r = C.page(box);
		const left = r.left + num(cs.borderLeftWidth) + num(cs.paddingLeft);
		const top = r.top + num(cs.borderTopWidth) + num(cs.paddingTop);
		const width = box.clientWidth - num(cs.paddingLeft) - num(cs.paddingRight);
		const space = (key) => (C.spaces(doc).find((t) => t.key === key) || { px: 16 }).px;
		const gap = space('s');
		const unit = Math.max(8, space('l'));
		const column = (width - gap * (COLUMNS - 1)) / COLUMNS;
		const lineX = (v) => clamp(Math.round((v - left + gap / 2) / (column + gap)) + 1, 1, LAST_COLUMN_LINE);
		return p.children.map((child) => {
			const node = C.nodeOf(doc, child.id);
			const b = node ? C.page(node) : null;
			if (!b || !b.width || !b.height || state.bp !== 'base') { return [child, null]; }
			const c0 = clamp(lineX(b.left), 1, COLUMNS);
			const r0 = clamp(Math.round((b.top - top) / unit) + 1, 1, LAST_ROW_LINE - 1);
			return [child, { c0, c1: clamp(lineX(b.right), c0 + 1, LAST_COLUMN_LINE), r0, r1: clamp(r0 + Math.max(1, Math.ceil(b.height / unit)), r0 + 1, LAST_ROW_LINE) }];
		});
	}
	/** A clean column: every child full width, one under the other, as many rows as its box needs. */
	function column(doc, p, rowsOf) {
		let row = 1;
		let cut = false;
		const unit = Math.max(8, (C.spaces(doc).find((t) => t.key === 'l') || { px: 24 }).px);
		const cells = p.children.map((child) => {
			const span = clamp(rowsOf(child, unit), 1, LAST_ROW_LINE - 1);
			const cell = { c0: 1, c1: LAST_COLUMN_LINE, r0: Math.min(row, LAST_ROW_LINE - 1), r1: Math.min(row + span, LAST_ROW_LINE) };
			if (row + span > LAST_ROW_LINE) { cut = true; }
			row += span;
			return [child, cell];
		});
		return { cells, cut };
	}
	const boxRows = (doc) => (child, unit) => {
		const node = C.nodeOf(doc, child.id);
		return node ? Math.ceil(C.page(node).height / unit) : 1;
	};

	/**
	 * Changes the layout of a section (call inside applyChange). Stack → Compose keeps the positions of the children on the desktop canvas
	 * (anywhere else: one clean column); Compose → Stack drops every placement and layer.
	 */
	function setLayout(p, layout) {
		const doc = B.previewDoc();
		const sectionNode = doc && C.nodeOf(doc, p.id);
		p.content.layout = layout;
		if (layout !== 'compose') {
			(p.children || []).forEach(clearPlacement);
			return;
		}
		if (!sectionNode || !(p.children || []).length) { return; }
		const measured = cellsFromLayout(doc, sectionNode, p);
		const fallback = column(doc, p, boxRows(doc)).cells;
		measured.forEach(([child, cell], i) => setCell(child, cell || fallback[i][1], 'base'));
	}

	/** Tidy up: the children in reading order (top to bottom, left to right), each as a full-width row, in the DOM order too. */
	function tidy(p) {
		const doc = B.previewDoc();
		const sectionNode = doc && C.nodeOf(doc, p.id);
		const container = sectionNode && gridOf(sectionNode);
		const m = container && metrics(doc, container);
		if (!m) { B.setState(T('The grid is shown only on a screen size where the section is not stacked.'), true); return; }
		const order = p.children.map((child, i) => {
			const node = C.nodeOf(doc, child.id);
			return { child, i, cell: node ? cellOf(child, node, m) : { c0: 1, c1: LAST_COLUMN_LINE, r0: LAST_ROW_LINE, r1: LAST_ROW_LINE } };
		}).sort((a, b) => a.cell.r0 - b.cell.r0 || a.cell.c0 - b.cell.c0 || a.i - b.i);
		let cut = false;
		B.applyChange(() => {
			p.children = order.map((x) => x.child);
			const result = column(doc, p, (child) => { const o = order.find((x) => x.child === child); return o.cell.r1 - o.cell.r0; });
			cut = result.cut;
			result.cells.forEach(([child, cell]) => setCell(child, cell, state.bp));
		});
		B.setState(cut ? T('Tidied up. The elements are taller than the grid allows, the last ones share the bottom rows.') : T('Tidied up: one clean column, in reading order.'), cut);
	}

	function stepLayer(p, direction) {
		const next = LAYERS[clamp(LAYERS.indexOf(modelLayer(p)) + direction, 0, LAYERS.length - 1)];
		B.writeStyle([[p.id, { layer: next === 'base' ? '' : next }]]);
		C.announce(T('Layer') + ': ' + T(next));
	}

	/* ---------- the toolbar ---------- */

	C.extras.push(({ doc, many, main, p, add, separator, icon }) => {
		if (many) { return; }
		if (p.type === 'section') {
			const compose = p.content.layout === 'compose';
			separator();
			add('compose', compose ? T('Compose layout is on: switch back to a stack (the placements are removed)') : T('Compose layout: place the elements freely on a 12-column grid'), () => {
				B.applyChange(() => setLayout(p, compose ? 'stack' : 'compose'));
				B.setState(compose ? T('Back to a stack: the placements were removed.') : T('Compose layout: the elements keep their positions, drag them to any cell.'));
			}, icon(doc, 'compose'), compose);
			if (compose) { add('tidy', T('Tidy up: one clean column in reading order'), () => tidy(p), icon(doc, 'tidy')); }
		} else if (main.n.parent && main.n.parent.type === 'section' && main.n.parent.content.layout === 'compose') {
			separator();
			add('layer-up', T('Bring forward (layer)'), () => stepLayer(p, 1), icon(doc, 'forward'));
			add('layer-down', T('Send backward (layer)'), () => stepLayer(p, -1), icon(doc, 'backward'));
		}
	});

	// the "Layout of the content" field of the panel converts the same way (one history step with the field itself)
	B.on('content', (p, key, value) => { if (p.type === 'section' && key === 'layout') { setLayout(p, value); } });
	B.on('preview', (doc) => { if (!doc.getElementById('tl-bd-compose-style')) { doc.head.append(Object.assign(doc.createElement('style'), { id: 'tl-bd-compose-style', textContent: STYLE })); } });
})();
