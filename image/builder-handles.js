/* Talea – the page builder's direct manipulation on the canvas, part 2: the handles (compose phase A, see docs/specs/visual-compose.md).
 *
 *   spacing   padding (inside) and margin (outside) handles on the four sides, a gap handle between the first two children – snapping to the
 *             spacing tokens of the design system (read from the canvas, never recomputed here); Alt = both opposite sides, Shift = all sides,
 *             Ctrl / Cmd = a free px value
 *   resize    right edge (width as a share of the parent in twelfths, or the column span in a grid), bottom edge (min height, or the row span)
 *             and the corner for both
 *   dividers  between the columns of a grid or of a row: set the ratio (2fr 1fr 1fr)
 *
 * Every handle is a focusable slider: arrow keys change the value by one step, the value is announced politely. A gesture writes the normal
 * style of the CURRENT screen size (desktop / tablet / phone) in one history step, so MCP, the sanitizer, the renderer and the exports see
 * nothing new. */
(function () {
	'use strict';

	const B = window.taleaBuilder;
	const C = window.taleaCompose;
	if (!B || !C) { return; }
	const { T, state } = B;
	const mk = C.mk;

	const AXIS = { top: 'y', bottom: 'y', left: 'x', right: 'x' };
	const PADDING_PROPERTY = { top: 'padding_y', bottom: 'padding_y', left: 'padding_x', right: 'padding_x' };
	// style property -> the inline CSS property that shows it live
	const CSS = { padding_y: 'paddingBlock', padding_x: 'paddingInline', margin_top: 'marginBlockStart', margin_bottom: 'marginBlockEnd', margin_left: 'marginInlineStart', margin_right: 'marginInlineEnd',
		gap: 'gap', width: 'width', min_height: 'minHeight', flex: 'flex', column_span: 'gridColumn', row_span: 'gridRow', columns: 'gridTemplateColumns' };
	const GROW_ARROWS = { ArrowRight: 1, ArrowDown: 1, ArrowLeft: -1, ArrowUp: -1 }; // sizes grow to the right and downwards
	const VALUE_ARROWS = { ArrowRight: 1, ArrowUp: 1, ArrowLeft: -1, ArrowDown: -1 }; // spacing: up and right = more
	const mods = (e) => ({ free: !!(e.ctrlKey || e.metaKey), shift: !!e.shiftKey, alt: !!e.altKey });
	const pct = (n) => C.round(n, 2) + '%';
	const gcd = (a, b) => (b ? gcd(b, a % b) : a);
	const num = (v) => parseFloat(v) || 0;

	/** The value the model holds for a style property in the current screen size ('' = not set there). */
	function modelValue(id, key) {
		const n = B.find(id);
		const st = n && n.p.style && !Array.isArray(n.p.style) ? n.p.style[state.bp] : null;
		return st && st[key] !== undefined ? String(st[key]) : '';
	}
	const same = (id, props) => Object.keys(props).every((k) => modelValue(id, k) === props[k]);

	/** Temporary inline values on nodes for the live view: set({property: css}) and restore(). The canvas is swapped once the draft is saved. */
	function inline(...nodes) {
		const saved = nodes.map((n) => [n, n.getAttribute('style')]);
		return {
			set: (values, node) => { (node ? [node] : nodes).forEach((n) => Object.entries(values).forEach(([k, v]) => { n.style[CSS[k] || k] = v; })); },
			restore: () => saved.forEach(([n, s]) => { if (s === null) { n.removeAttribute('style'); } else { n.setAttribute('style', s); } }),
		};
	}

	function slider(doc, classes, key, label) {
		return mk(doc, 'div', { class: 'bdo-h ' + classes, tabindex: '0', role: 'slider', 'aria-label': label, 'data-bdo-h': '', 'data-bdo': key });
	}
	/** What a screen reader says about the handle: the range, the position in it and the value in words. */
	function value(h, min, max, now, text) {
		h.setAttribute('aria-valuemin', String(min));
		h.setAttribute('aria-valuemax', String(max));
		h.setAttribute('aria-valuenow', String(now));
		h.setAttribute('aria-valuetext', text);
	}
	/** The spacing handle's value: the position of the nearest token on the scale (0 … the largest) and its name. */
	function spaceValue(doc, h, px) {
		const tokens = C.spaces(doc);
		const v = C.snapSpace(doc, px, false);
		value(h, 0, tokens.length - 1, Math.max(0, tokens.findIndex((t) => t.key === v.key)), C.spaceName(v));
	}

	/**
	 * A handle with a pointer gesture and the arrow keys.
	 *   begin(event | null) → the session {id, node, live, …} (null refuses)
	 *   plan(session, dx, dy, modifiers) → {props, css, label}: what to write and what to show now
	 *   key(session, direction, event) → the plan one step further
	 *   commit(props, byKeyboard) writes it
	 */
	function bind(h, { begin, plan, key, commit, arrows }) {
		C.drag(h, {
			start: (e) => begin(e),
			move: (s, dx, dy, e) => {
				const p = plan(s, dx, dy, mods(e));
				if (!p) { return; }
				s.last = p;
				s.live.set(p.css);
				const win = h.ownerDocument.defaultView;
				C.say(p.label, e.clientX + win.scrollX, e.clientY + win.scrollY);
				if (s.guides) { C.showGuides(s.guides, C.page(s.node)); }
			},
			end: (s, moved) => {
				if (!moved || !s.last || !s.last.props || same(s.id, s.last.props)) { s.live.restore(); return; }
				commit(s.last.props, false);
			},
			cancel: (s) => s.live.restore(),
		});
		h.addEventListener('keydown', (e) => {
			if (e.key === 'Escape') { e.stopPropagation(); h.blur(); return; }
			if (!(e.key in arrows)) { return; }
			e.preventDefault();
			e.stopPropagation(); // the arrows belong to the handle here, not to "select the next element"
			const s = begin(null);
			const p = s && key(s, arrows[e.key], e);
			if (!p || !p.props) { return; }
			s.live.set(p.css);
			h.setAttribute('aria-valuetext', p.label);
			C.announce(p.label);
			if (!same(s.id, p.props)) { commit(p.props, true); }
		});
		h.addEventListener('focus', () => C.announce(h.getAttribute('aria-label') + (h.getAttribute('aria-valuetext') ? ': ' + h.getAttribute('aria-valuetext') : '')));
	}

	/** The index of the token a px value is nearest to, moved by direction (clamped). */
	function tokenStep(doc, px, direction) {
		const tokens = C.spaces(doc);
		const v = C.snapSpace(doc, px, false);
		return tokens[Math.max(0, Math.min(tokens.length - 1, tokens.findIndex((t) => t.key === v.key) + direction))];
	}

	/* ---------- spacing: padding, margin, gap ---------- */

	function spacing(ctx) {
		const { doc, id, node } = ctx;
		const win = doc.defaultView;
		const sides = ['top', 'right', 'bottom', 'left'];
		const name = { top: T('top'), right: T('right'), bottom: T('bottom'), left: T('left') };

		// the bands show what the padding and the margin occupy
		sides.forEach((side) => {
			['mar', 'pad'].forEach((kind) => {
				const band = mk(doc, 'div', { class: 'bdo-band ' + (kind === 'pad' ? 'bdo-pad' : 'bdo-mar') });
				C.add(band, (g) => {
					const r = g.r;
					const v = kind === 'pad' ? g.pad : g.mar;
					const box = kind === 'pad'
						? { top: [r.left, r.top, r.width, v.top], bottom: [r.left, r.bottom - v.bottom, r.width, v.bottom], left: [r.left, r.top + v.top, v.left, r.height - v.top - v.bottom], right: [r.right - v.right, r.top + v.top, v.right, r.height - v.top - v.bottom] }[side]
						: { top: [r.left, r.top - v.top, r.width, v.top], bottom: [r.left, r.bottom, r.width, v.bottom], left: [r.left - v.left, r.top, v.left, r.height], right: [r.right, r.top, v.right, r.height] }[side];
					band.style.display = box[2] > 0.5 && box[3] > 0.5 && box[2] < 4000 ? '' : 'none';
					Object.assign(band.style, { left: box[0] + 'px', top: box[1] + 'px', width: Math.max(0, box[2]) + 'px', height: Math.max(0, box[3]) + 'px' });
				});
			});
		});

		const spaceHandle = (kind, side) => {
			const padding = kind === 'padding';
			const title = padding ? T('Padding') : T('Margin');
			const h = slider(doc, padding ? 'bdo-sp' : 'bdo-sp bdo-m', kind + '-' + side, title + ' ' + name[side]);
			h.dataset.axis = AXIS[side];
			const sign = padding ? (side === 'top' || side === 'left' ? 1 : -1) : (side === 'top' || side === 'left' ? -1 : 1);
			const along = padding ? 0.35 : 0.65; // padding and margin handles sit side by side on an edge
			const current = () => { const g = C.measure(node); return (padding ? g.pad : g.mar)[side]; };
			C.add(h, (g) => {
				const r = g.r;
				const v = (padding ? g.pad : g.mar)[side];
				const cx = r.left + r.width * along;
				const cy = r.top + r.height * along;
				const at = { top: [cx, padding ? r.top + v : r.top - v], bottom: [cx, padding ? r.bottom - v : r.bottom + v], left: [padding ? r.left + v : r.left - v, cy], right: [padding ? r.right - v : r.right + v, cy] }[side];
				h.style.left = Math.max(6, Math.min(doc.documentElement.clientWidth - 6, at[0])) + 'px';
				h.style.top = Math.max(2, at[1]) + 'px';
			});
			const planFor = (px, m) => {
				const v = C.snapSpace(doc, px, m.free);
				const keys = padding
					? (m.shift ? ['padding_y', 'padding_x'] : [PADDING_PROPERTY[side]])
					: (m.shift ? ['margin_top', 'margin_right', 'margin_bottom', 'margin_left'] : (m.alt ? (AXIS[side] === 'y' ? ['margin_top', 'margin_bottom'] : ['margin_left', 'margin_right']) : ['margin_' + side]));
				const props = {};
				const css = {};
				keys.forEach((k) => { props[k] = v.key; css[k] = v.css; });
				// auto margins ("centre") would fight with a fixed margin on a side
				if (!padding && keys.some((k) => k === 'margin_left' || k === 'margin_right') && modelValue(id, 'center')) { props.center = ''; }
				return { props, css, label: title + (m.shift ? '' : ' ' + name[side]) + ': ' + C.spaceName(v) };
			};
			bind(h, {
				arrows: VALUE_ARROWS,
				begin: () => ({ id, node, doc, px0: current(), live: inline(node) }),
				plan: (s, dx, dy, m) => planFor(s.px0 + sign * (AXIS[side] === 'x' ? dx : dy), m),
				key: (s, direction, e) => planFor(e.ctrlKey || e.metaKey ? s.px0 + direction : tokenStep(doc, s.px0, direction).px, mods(e)),
				commit: (props, keyboard) => { B.writeStyle([[id, props]], keyboard ? 'kb:' + id + ':' + kind + side : undefined); if (keyboard) { C.later(); } },
			});
			spaceValue(doc, h, current());
		};
		sides.forEach((side) => spaceHandle('margin', side));
		sides.forEach((side) => spaceHandle('padding', side));

		// the gap between the children of a flex or grid container: a handle at the start of the gap between the first two
		if (!/flex|grid/.test(win.getComputedStyle(node).display)) { return; }
		const kids = () => Array.from(node.children).filter((k) => { const r = k.getBoundingClientRect(); const p = win.getComputedStyle(k).position; return r.width > 0 && r.height > 0 && p !== 'absolute' && p !== 'fixed'; });
		const spot = () => {
			const [a, b] = kids().slice(0, 2).map(C.page);
			if (!a || !b) { return null; }
			if (b.left >= a.right - 1 && b.top < a.bottom) { return { axis: 'x', x: (a.right + b.left) / 2, y: a.top + 8 }; }
			if (b.top >= a.bottom - 1) { return { axis: 'y', x: a.left + 8, y: (a.bottom + b.top) / 2 }; }
			return null;
		};
		if (!spot()) { return; }
		const h = slider(doc, 'bdo-gap', 'gap', T('Gap between elements'));
		C.add(h, () => { const s = spot(); h.style.display = s ? '' : 'none'; if (s) { h.dataset.axis = s.axis; h.style.left = s.x + 'px'; h.style.top = s.y + 'px'; } });
		const current = () => { const s = spot(); const cs = win.getComputedStyle(node); return num(s && s.axis === 'y' ? cs.rowGap : cs.columnGap); };
		const planFor = (px, m) => { const v = C.snapSpace(doc, px, m.free); return { props: { gap: v.key }, css: { gap: v.css }, label: T('Gap') + ': ' + C.spaceName(v) }; };
		bind(h, {
			arrows: VALUE_ARROWS,
			begin: () => ({ id, node, doc, px0: current(), axis: (spot() || { axis: 'x' }).axis, live: inline(node) }),
			plan: (s, dx, dy, m) => planFor(s.px0 + (s.axis === 'x' ? dx : dy), m),
			key: (s, direction, e) => planFor(e.ctrlKey || e.metaKey ? s.px0 + direction : tokenStep(doc, s.px0, direction).px, mods(e)),
			commit: (props, keyboard) => { B.writeStyle([[id, props]], keyboard ? 'kb:' + id + ':gap' : undefined); if (keyboard) { C.later(); } },
		});
		spaceValue(doc, h, current());
	}

	/* ---------- resize: width, min height, column and row span ---------- */

	/** What the element sits in: the parent's content width and, in a grid, the tracks (page coordinates) the element can span. */
	function environment(doc, node) {
		const win = doc.defaultView;
		const parent = node.parentElement;
		const pcs = parent ? win.getComputedStyle(parent) : null;
		const env = { parentWidth: parent ? Math.max(1, parent.clientWidth - num(pcs.paddingLeft) - num(pcs.paddingRight)) : 1, grid: false, flexRow: false, columns: [], rows: [] };
		if (!pcs) { return env; }
		env.flexRow = /flex/.test(pcs.display) && /^row/.test(pcs.flexDirection);
		if (/grid/.test(pcs.display)) {
			const pr = C.page(parent);
			const tracks = (list, gap, start) => { let at = start; return list.split(' ').map(parseFloat).filter((x) => !isNaN(x)).map((size) => { const t = { from: at, to: at + size }; at += size + gap; return t; }); };
			env.columns = tracks(pcs.gridTemplateColumns, num(pcs.columnGap), pr.left + num(pcs.borderLeftWidth) + num(pcs.paddingLeft));
			env.rows = tracks(pcs.gridTemplateRows, num(pcs.rowGap), pr.top + num(pcs.borderTopWidth) + num(pcs.paddingTop));
			env.grid = env.columns.length > 1 || env.rows.length > 1;
		}
		return env;
	}
	const nearest = (list, x, key) => list.reduce((best, t, i) => (Math.abs(t[key] - x) < Math.abs(list[best][key] - x) ? i : best), 0);

	/** A target width in px → the plan: a column span in a grid, otherwise a share of the parent in twelfths ('50%'), or free px. */
	function planWidth(s, target, free) {
		const g = C.measure(s.node);
		if (s.env.columns.length > 1 && !free) {
			const start = nearest(s.env.columns, g.r.left, 'from');
			const span = nearest(s.env.columns.slice(start), g.r.left + target, 'to') + 1;
			const v = span <= 1 ? '' : (start === 0 && span >= s.env.columns.length ? '1 / -1' : 'span ' + Math.min(4, span));
			return { props: { column_span: v }, css: { column_span: v || 'auto' }, label: T('Columns') + ': ' + (v === '1 / -1' ? T('full width') : span) };
		}
		let v;
		if (free) { v = C.px(target); } else { const k = Math.max(1, Math.min(12, Math.round(target / s.env.parentWidth * 12))); v = k === 12 ? '100%' : pct(k * 100 / 12); }
		const props = { width: v };
		const css = { width: v };
		// in a row a child that fills the free space ignores its width
		if (s.env.flexRow && /^[1-9]/.test(s.cs.flexGrow)) { props.flex = '0 0 auto'; css.flex = '0 0 auto'; }
		return { props, css, label: T('Width') + ': ' + v.replace('%', ' %') };
	}
	/** A target height in px → the plan: a row span in a grid, otherwise min height in rem, or free px. */
	function planHeight(s, target, free) {
		const g = C.measure(s.node);
		if (s.env.rows.length > 1 && !free) {
			const start = nearest(s.env.rows, g.r.top, 'from');
			const span = nearest(s.env.rows.slice(start), g.r.top + target, 'to') + 1;
			const v = span <= 1 ? '' : 'span ' + Math.min(4, span);
			return { props: { row_span: v }, css: { row_span: v || 'auto' }, label: T('Rows') + ': ' + span };
		}
		const v = free ? C.px(target) : Math.max(1, Math.round(target / C.rootPx(s.doc))) + 'rem';
		return { props: { min_height: v }, css: { min_height: v }, label: T('Min height') + ': ' + v };
	}
	/** The size that ends one track further (direction 1) or closer (-1): the target of a span step by keyboard. */
	function trackStep(tracks, from, to, direction) {
		const start = nearest(tracks, from, 'from');
		const current = nearest(tracks.slice(start), to, 'to');
		return tracks[start + Math.max(0, Math.min(tracks.length - start - 1, current + direction))].to - from;
	}

	function resizing(ctx) {
		const { doc, id, node } = ctx;
		const win = doc.defaultView;
		[['e', T('Resize width'), 'x'], ['s', T('Resize height'), 'y'], ['se', T('Resize width and height'), 'xy']].forEach(([dir, label, axes]) => {
			const h = slider(doc, 'bdo-rz', 'resize-' + dir, label);
			h.style.cursor = { e: 'ew-resize', s: 'ns-resize', se: 'nwse-resize' }[dir];
			const size = C.measure(node);
			if (dir === 's') { value(h, 0, 4000, Math.round(size.r.height), Math.round(size.r.height) + ' px'); } else { const share = Math.round(size.r.width / environment(doc, node).parentWidth * 100); value(h, 0, 100, Math.min(100, share), share + ' %'); }
			C.add(h, (g) => {
				const r = g.r;
				const at = { e: [r.right, r.top + Math.min(r.height * 0.15, 80)], s: [r.left + Math.min(r.width * 0.15, 80), r.bottom], se: [r.right, r.bottom] }[dir];
				// an edge handle on a short edge would sit on the spacing handles: the corner does the job there
				h.style.display = (dir === 'e' && r.height < 90) || (dir === 's' && r.width < 90) ? 'none' : '';
				h.style.left = at[0] + 'px';
				h.style.top = at[1] + 'px';
			});
			// the corner writes only the direction it was really dragged in (a purely horizontal drag does not set a height)
			const combine = (s, w, height, m, moved = { x: true, y: true }) => {
				const plans = [];
				if (axes.includes('x') && moved.x) { plans.push(planWidth(s, w, m.free)); }
				if (axes.includes('y') && moved.y) { plans.push(planHeight(s, height, m.free)); }
				return { props: Object.assign({}, ...plans.map((p) => p.props)), css: Object.assign({}, ...plans.map((p) => p.css)), label: plans.map((p) => p.label).join(' · ') };
			};
			bind(h, {
				arrows: GROW_ARROWS,
				begin: () => ({ id, node, doc, cs: win.getComputedStyle(node), env: environment(doc, node), live: inline(node), start: C.measure(node), guides: C.prepareGuides(doc, [node]) }),
				plan: (s, dx, dy, m) => {
					const g = s.start;
					// a centred element grows to both sides, so its size changes twice as fast as the edge moves
					const f = Math.abs(g.mar.left - g.mar.right) < 1 && g.mar.left > 1 && Math.abs(g.r.width + g.mar.left + g.mar.right - s.env.parentWidth) < 2 ? 2 : 1;
					return combine(s, g.r.width + f * dx, g.r.height + dy, m, { x: axes.length === 1 || Math.abs(dx) > 2, y: axes.length === 1 || Math.abs(dy) > 2 });
				},
				key: (s, direction, e) => {
					const g = s.start;
					const m = mods(e);
					const vertical = e.key === 'ArrowUp' || e.key === 'ArrowDown';
					if (vertical ? !axes.includes('y') : !axes.includes('x')) { return null; }
					let w = g.r.width;
					let height = g.r.height;
					if (vertical) {
						const rem = C.rootPx(doc);
						height = m.free ? height + direction * 8 : (s.env.rows.length > 1 ? trackStep(s.env.rows, g.r.top, g.r.bottom, direction) : (Math.round(height / rem) + direction) * rem);
					} else {
						w = m.free ? w + direction * 8 : (s.env.columns.length > 1 ? trackStep(s.env.columns, g.r.left, g.r.right, direction) : (Math.round(w / s.env.parentWidth * 12) + direction) * s.env.parentWidth / 12);
					}
					return combine(s, w, height, m);
				},
				commit: (props, keyboard) => { B.writeStyle([[id, props]], keyboard ? 'kb:' + id + ':resize' : undefined); if (keyboard) { C.later(); } },
			});
		});
	}

	/** The Alt+arrow keys on the selected element: the same step as the arrow keys on a focused resize handle. */
	C.keyResize = (key, shift) => {
		const doc = B.previewDoc();
		const handle = C.layer && C.layer.querySelector('[data-bdo="resize-' + (key === 'ArrowLeft' || key === 'ArrowRight' ? 'e' : 's') + '"]');
		if (!doc || !handle) { return false; }
		handle.dispatchEvent(new doc.defaultView.KeyboardEvent('keydown', { key, shiftKey: shift, bubbles: true, cancelable: true }));
		return true;
	};

	/* ---------- dividers between columns ---------- */

	/** The columns of a grid (2–6 tracks) or the children of one flex row (all elements of the page): where the dividers go. */
	function columnsOf(doc, node) {
		const win = doc.defaultView;
		const cs = win.getComputedStyle(node);
		if (/grid/.test(cs.display)) {
			const widths = cs.gridTemplateColumns.split(' ').map(parseFloat).filter((x) => !isNaN(x));
			return widths.length >= 2 && widths.length <= 6 ? { kind: 'grid', widths, gap: num(cs.columnGap) } : null;
		}
		if (/flex/.test(cs.display) && /^row/.test(cs.flexDirection)) {
			const kids = Array.from(node.children).filter((k) => { const r = k.getBoundingClientRect(); return r.width > 0 && r.height > 0 && win.getComputedStyle(k).position !== 'absolute'; });
			const top = kids.length ? kids[0].getBoundingClientRect().top : 0;
			if (kids.length >= 2 && kids.every((k) => Math.abs(k.getBoundingClientRect().top - top) < 4 && k.hasAttribute('data-tl-id'))) { return { kind: 'flex', kids, widths: kids.map((k) => k.getBoundingClientRect().width) }; }
		}
		return null;
	}
	/** Widths in px as twelfths of their total (each at least 1, adding up to 12). */
	function twelfths(widths) {
		const total = widths.reduce((a, b) => a + b, 0);
		const k = widths.map((w) => Math.max(1, Math.round(w / total * 12)));
		let sum = k.reduce((a, b) => a + b, 0);
		while (sum !== 12) { const step = sum > 12 ? -1 : 1; const i = sum > 12 ? k.indexOf(Math.max(...k)) : k.indexOf(Math.min(...k)); k[i] += step; sum += step; }
		return k;
	}

	function dividers(ctx) {
		const { doc } = ctx;
		const win = doc.defaultView;
		// the selected container itself, or the row its element sits in
		const parent = ctx.node.parentElement;
		const container = columnsOf(doc, ctx.node) ? ctx.node : (parent && parent.hasAttribute('data-tl-id') && columnsOf(doc, parent) ? parent : null);
		if (!container || container.closest('[data-tl-lock]')) { return; }
		const cid = container.getAttribute('data-tl-id');
		if (!B.find(cid)) { return; }

		/** The x of each gap between neighbours (page coordinates). */
		const gaps = (info) => {
			if (info.kind === 'flex') { return info.kids.slice(0, -1).map((k, i) => (C.page(k).right + C.page(info.kids[i + 1]).left) / 2); }
			const cs = win.getComputedStyle(container);
			let at = C.page(container).left + num(cs.borderLeftWidth) + num(cs.paddingLeft);
			return info.widths.slice(0, -1).map((w) => { at += w; const x = at + info.gap / 2; at += info.gap; return x; });
		};
		const first = columnsOf(doc, container);
		for (let index = 0; index < first.widths.length - 1; index++) { divider(index, first); }

		function divider(index, info0) {
			const h = slider(doc, 'bdo-dv', 'divider-' + index, T('Column divider') + ' ' + (index + 1));
			C.add(h, () => {
				const info = columnsOf(doc, container);
				h.style.display = info && info.widths.length === info0.widths.length ? '' : 'none';
				if (h.style.display) { return; }
				const r = C.page(container);
				h.style.left = gaps(info)[index] + 'px';
				h.style.top = (r.top + r.height / 2) + 'px';
				h.style.height = Math.max(24, r.height - 8) + 'px';
			});
			const begin = () => {
				const info = columnsOf(doc, container);
				if (!info || info.widths.length !== info0.widths.length) { return null; }
				const cs = win.getComputedStyle(container);
				return { id: cid, node: container, doc, info, widths: info.widths, k: twelfths(info.widths), live: inline(...(info.kind === 'grid' ? [container] : info.kids)),
					rowWidth: container.clientWidth - num(cs.paddingLeft) - num(cs.paddingRight) };
			};
			/** The boundary after column `index` at `boundary` twelfths of the row; both neighbours keep at least one twelfth. */
			const planFor = (s, boundary, equal) => {
				const k = s.k.slice();
				if (equal && s.info.kind === 'grid') { return { props: { columns: String(k.length) }, css: { columns: 'repeat(' + k.length + ', minmax(0, 1fr))' }, label: T('Equal columns') }; }
				const pair = k[index] + k[index + 1];
				k[index] = Math.max(1, Math.min(pair - 1, boundary - k.slice(0, index).reduce((a, b) => a + b, 0)));
				k[index + 1] = pair - k[index];
				if (s.info.kind === 'grid') {
					const d = k.reduce(gcd);
					const value = k.map((x) => x / d + 'fr').join(' ');
					return { props: { columns: value }, css: { columns: value }, label: k.map((x) => x / d).join(' : ') };
				}
				// a row of elements: the two neighbours take a share of the row, together exactly as wide as before
				const sum = s.widths[index] + s.widths[index + 1];
				const a = Math.round(k[index] / pair * sum / s.rowWidth * 10000) / 100;
				const b = Math.floor((sum / s.rowWidth * 100 - a) * 100) / 100;
				return { props: null, flex: [[s.info.kids[index], a], [s.info.kids[index + 1], b]], css: {}, label: pct(a) + ' : ' + pct(b) };
			};
			const show = (s, p) => { if (p.flex) { p.flex.forEach(([kid, value]) => s.live.set({ width: pct(value), flex: '0 0 auto' }, kid)); } else { s.live.set(p.css, container); } };
			const write = (s, p, keyboard) => {
				const key = keyboard ? 'kb:' + cid + ':divider' + index : undefined;
				if (p.flex) { B.writeStyle(p.flex.map(([kid, value]) => [kid.getAttribute('data-tl-id'), { width: pct(value), flex: '0 0 auto' }]), key); } else if (!same(cid, p.props)) { B.writeStyle([[cid, p.props]], key); } else { s.live.restore(); }
				if (keyboard) { C.later(); }
			};
			C.drag(h, {
				start: () => begin(),
				move: (s, dx, dy, e) => {
					const before = s.widths.slice(0, index + 1).reduce((a, b) => a + b, 0);
					const p = planFor(s, Math.round((before + dx) / s.widths.reduce((a, b) => a + b, 0) * 12), e.shiftKey);
					s.last = p;
					show(s, p);
					C.say(p.label, e.clientX + win.scrollX, e.clientY + win.scrollY);
				},
				end: (s, moved) => { if (!moved || !s.last) { s.live.restore(); return; } write(s, s.last, false); },
				cancel: (s) => s.live.restore(),
			});
			h.addEventListener('keydown', (e) => {
				if (e.key === 'Escape') { e.stopPropagation(); h.blur(); return; }
				if (e.key !== 'ArrowLeft' && e.key !== 'ArrowRight') { return; }
				e.preventDefault();
				e.stopPropagation();
				const s = begin();
				if (!s) { return; }
				const p = planFor(s, s.k.slice(0, index + 1).reduce((a, b) => a + b, 0) + (e.key === 'ArrowRight' ? 1 : -1), e.shiftKey);
				show(s, p);
				h.setAttribute('aria-valuetext', p.label);
				C.announce(p.label);
				write(s, p, true);
			});
			h.addEventListener('focus', () => C.announce(h.getAttribute('aria-label') + ': ' + h.getAttribute('aria-valuetext')));
			const parts = twelfths(info0.widths);
			value(h, 1, 11, parts.slice(0, index + 1).reduce((a, b) => a + b, 0), parts.join(' : '));
		}
	}

	C.providers.push(spacing, resizing, dividers);

	/** Enter on the selected element moves the focus to its first handle; Tab then walks them. */
	C.focusHandles = () => {
		const first = C.layer && C.layer.querySelector('[data-bdo-h]');
		if (first) { first.focus(); }
		return !!first;
	};
})();
