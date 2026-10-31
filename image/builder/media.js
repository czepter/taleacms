/* Talea – the page builder's media on the canvas: crop mode of an image (compose phase C1, see docs/specs/visual-compose.md).
 *
 * Crop mode shows the WHOLE photo around the element's box at its cover scale, dims what lies outside the box (the crop window) and lets the
 * person choose which part shows: press anywhere on the photo and drag, the point under the pointer stays the focal point. While dragging the photo
 * holds still and the bright window slides over it; on release the photo settles so the window is back on the box. The toolbar turns into the
 * ratio strip (Original, 1:1 … 3:4) with Reset, Cancel and Done; arrows, Home, 1–7, Enter and Esc do the same from the keyboard.
 *
 * The canvas follows live through temporary inline values on the node; Done writes ONE history step with focal_point, aspect_ratio (and object_fit
 * cover when a ratio crops) to the style of the CURRENT screen size. Cancel writes nothing. The object-position maths (frame W×H, photo w×h,
 * scale s = max(W/w, H/h), at rest the photo's left is (W − dispW) · fx) is the maths of the browser itself; nothing is recomputed on the server. */
import { T, state, on, find, selection, selectedIds, writeStyle, setState, ownValue, inheritedValue } from './core.js';
import { previewDoc, endGesture } from './canvas.js';
import { C } from './overlay.js';

const mk = C.mk;

const KEYS = ['focal_point', 'aspect_ratio', 'object_fit'];
/** key (the choices of Style aspect_ratio), button text, title */
const RATIOS = [['', T('Original'), T('Original shape (1)')], ['1', '1:1', T('Square (2)')], ['4/3', '4:3', '4 : 3 (3)'], ['3/2', '3:2', '3 : 2 (4)'],
	['16/9', '16:9', '16 : 9 (5)'], ['21/9', '21:9', '21 : 9 (6)'], ['3/4', '3:4', T('Portrait, 3 : 4 (7)')]];
const STEP = { ArrowLeft: [-1, 0], ArrowRight: [1, 0], ArrowUp: [0, -1], ArrowDown: [0, 1] };
const clamp = (v, low, high) => Math.max(low, Math.min(high, v));
const isSvg = (url) => /\.svg(\?|#|$)/i.test(url);

Object.assign(C.ICONS, { crop: '<path d="M6 2v14a2 2 0 0 0 2 2h14"/><path d="M18 22V8a2 2 0 0 0-2-2H2"/>' });

const STYLE = `
#tl-bd-overlay .bdc{position:absolute;left:0;top:0;overflow:hidden;pointer-events:none}
#tl-bd-overlay .bdc-catch{position:absolute;inset:0;pointer-events:auto}
#tl-bd-overlay .bdc-photo{position:absolute;max-width:none;pointer-events:auto;cursor:crosshair;touch-action:none;user-select:none;-webkit-user-select:none;-webkit-user-drag:none;outline:calc(1px*var(--s)) dashed rgb(255 255 255/.7)}
#tl-bd-overlay .bdc-win{--hw:calc(.5px*var(--s));position:absolute;pointer-events:none;box-shadow:0 0 0 9999px rgb(18 18 18/.58);outline:calc(2px*var(--s)) solid #fff;outline-offset:calc(-1px*var(--s));
	background:linear-gradient(to right,transparent calc(100%/3 - var(--hw)),rgb(255 255 255/.6) 0 calc(100%/3 + var(--hw)),transparent 0 calc(200%/3 - var(--hw)),rgb(255 255 255/.6) 0 calc(200%/3 + var(--hw)),transparent 0),
	linear-gradient(to bottom,transparent calc(100%/3 - var(--hw)),rgb(255 255 255/.6) 0 calc(100%/3 + var(--hw)),transparent 0 calc(200%/3 - var(--hw)),rgb(255 255 255/.6) 0 calc(200%/3 + var(--hw)),transparent 0)}
#tl-bd-overlay .bdc-cross{position:absolute;width:calc(30px*var(--s));height:calc(30px*var(--s));transform:translate(-50%,-50%);border-radius:50%;pointer-events:none;outline:0;
	border:calc(2px*var(--s)) solid #fff;box-shadow:0 0 0 calc(1px*var(--s)) rgb(0 0 0/.5),inset 0 0 0 calc(1px*var(--s)) rgb(0 0 0/.5);
	background:linear-gradient(#fff,#fff) center/calc(2px*var(--s)) 60% no-repeat,linear-gradient(#fff,#fff) center/60% calc(2px*var(--s)) no-repeat}
#tl-bd-overlay .bdc-cross:focus-visible{outline:calc(3px*var(--s)) solid #8ab4f8;outline-offset:calc(3px*var(--s))}
#tl-bd-overlay .bdc-label{position:absolute;pointer-events:none;padding:calc(3px*var(--s)) calc(7px*var(--s));border-radius:calc(4px*var(--s));background:#1d1d1f;color:#fff;font-size:calc(12px*var(--s));white-space:nowrap}
#tl-bd-overlay .bdc-settle .bdc-photo,#tl-bd-overlay .bdc-settle .bdc-win,#tl-bd-overlay .bdc-settle .bdc-cross,#tl-bd-overlay .bdc-settle .bdc-label{transition:left .2s ease,top .2s ease}
#tl-bd-overlay .bdo-b.bdc-own{position:relative}
#tl-bd-overlay .bdo-b.bdc-own::after{content:'';position:absolute;top:calc(3px*var(--s));right:calc(3px*var(--s));width:calc(6px*var(--s));height:calc(6px*var(--s));border-radius:50%;background:#fff}
#tl-bd-overlay .bdo-b.bdc-done{background:#ff4f2e}
@media (prefers-reduced-motion:reduce){#tl-bd-overlay .bdc-settle .bdc-photo,#tl-bd-overlay .bdc-settle .bdc-win,#tl-bd-overlay .bdc-settle .bdc-cross,#tl-bd-overlay .bdc-settle .bdc-label{transition:none}}
`;

/* ---------- what the model and the canvas say ---------- */

const parseFocal = (text, pattern) => { const m = (pattern || /^(\d+)% (\d+)%$/).exec(text || ''); return m ? [clamp(+m[1], 0, 100), clamp(+m[2], 0, 100)] : null; };

/** The image of a selected element and the node it sits in (a bare <img>, or the <figure> / <a> around it); null where there is nothing to crop. */
function target(doc, id) {
	const n = id && find(id);
	const node = n && C.nodeOf(doc, id);
	const src = n && n.p.content.src;
	if (!n || n.p.type !== 'image' || !src || isSvg(src) || n.p.locked || !node || node.closest('[data-tl-lock]')) { return null; }
	const img = node.tagName === 'IMG' ? node : node.querySelector('img');
	return img ? { p: n.p, node, img } : null;
}
/** object-fit: contain shows the whole photo, so there is nothing to crop. */
const contained = (p) => (ownValue(p, state.bp, 'object_fit') || inheritedValue(p, 'object_fit')) === 'contain';

/* ---------- crop mode ---------- */

let crop = null;
/** Nothing else is going on on the canvas: crop mode may open and a dropped file may replace. */
const idle = () => !crop && !state.gesture && !state.placing && !state.editingCanvas;

const current = (key) => (crop.work[key] !== undefined ? crop.work[key] : crop.inherited[key]);
const focal = () => parseFocal(current('focal_point')) || [50, 50];
const label = () => { const [x, y] = focal(); return T('Focal point %s %, %s %').replace('%s', String(x)).replace('%s', String(y)); };

/** The node shows the state of crop mode live; the temporary values go away with the canvas that the saved draft brings (or with restore). */
function apply() {
	const [x, y] = focal();
	const s = crop.node.style;
	s.aspectRatio = current('aspect_ratio') || 'auto';
	s.objectFit = 'cover';
	s.setProperty('--tl-focal', x + '% ' + y + '%');
	s.objectPosition = x + '% ' + y + '%';
}
const measure = () => { crop.frame = C.page(crop.img); };

function place(e, left, top, width, height) {
	e.style.left = C.round(left) + 'px';
	e.style.top = C.round(top) + 'px';
	if (width !== undefined) { e.style.width = C.round(width) + 'px'; e.style.height = C.round(height) + 'px'; }
}

/** Positions photo, window, crosshair and label from the focal point (and the anchor while a drag holds the photo still). */
function draw() {
	const c = crop;
	const F = c.frame;
	const W = F.width;
	const H = F.height;
	const s = Math.max(W / c.w, H / c.h);
	const dw = c.w * s;
	const dh = c.h * s;
	const [x, y] = focal();
	const left = c.drag ? c.drag.left : F.left + (W - dw) * x / 100;
	const top = c.drag ? c.drag.top : F.top + (H - dh) * y / 100;
	c.size = { dw, dh };
	const root = c.doc.documentElement;
	c.box.style.width = root.scrollWidth + 'px';
	c.box.style.height = root.scrollHeight + 'px';
	place(c.photo, left, top, dw, dh);
	place(c.win, c.drag ? left + (dw - W) * x / 100 : F.left, c.drag ? top + (dh - H) * y / 100 : F.top, W, H);
	place(c.cross, left + dw * x / 100, top + dh * y / 100);
	const m = C.factor();
	place(c.label, left + dw * x / 100 + 20 * m, top + dh * y / 100 + 18 * m);
	c.label.textContent = label();
	c.cross.setAttribute('aria-valuenow', String(x));
	c.cross.setAttribute('aria-valuetext', label());
	placeToolbar();
}

function placeToolbar() {
	const c = crop;
	const m = C.factor();
	const b = c.tb.getBoundingClientRect();
	let top = c.frame.top - b.height - 6 * m;
	if (top < c.view.scrollY + 4 * m) { top = c.frame.bottom + 6 * m; } // the frame touches the top of the view: the toolbar goes below it
	place(c.tb, Math.max(4 * m, Math.min(c.frame.left, c.doc.documentElement.clientWidth + c.view.scrollX - b.width - 4 * m)), Math.max(0, top));
}

/** The ratio buttons show the current shape as pressed, with a dot when this screen size overrides a shape that a wider one sets. */
function sync() {
	const ratio = current('aspect_ratio') || '';
	crop.tb.querySelectorAll('[data-ratio]').forEach((b) => {
		const on = b.getAttribute('data-ratio') === ratio;
		b.setAttribute('aria-pressed', on ? 'true' : 'false');
		b.classList.toggle('bdc-own', on && crop.work.aspect_ratio !== undefined && !!crop.inherited.aspect_ratio);
		b.title = b.getAttribute('data-title') + (b.classList.contains('bdc-own') ? ' · ' + T('Set for this screen size only') : '');
	});
}

function setFocal(x, y, announce) {
	crop.work.focal_point = clamp(Math.round(x), 0, 100) + '% ' + clamp(Math.round(y), 0, 100) + '%';
	apply();
	draw();
	if (announce) { C.announce(label()); }
}
function pickRatio(key) {
	if (key === '') { crop.work.aspect_ratio = undefined; } else {
		crop.work.aspect_ratio = key;
		if (!current('object_fit')) { crop.work.object_fit = 'cover'; } // a shape crops only with cover
	}
	apply();
	measure(); // the box has a new height
	sync();
	draw();
	C.announce(crop.tb.querySelector('[data-ratio="' + key + '"]').getAttribute('data-title'));
}
function reset() {
	crop.work.focal_point = undefined;
	crop.work.aspect_ratio = undefined;
	apply();
	measure();
	sync();
	draw();
	C.announce(T('Reset') + '. ' + label());
}

/** Closes crop mode. commit = Done: what changed is ONE history step; otherwise (or when nothing changed) the node is put back as it was. */
function close(commit) {
	const c = crop;
	if (!c) { return; }
	crop = null;
	c.view.removeEventListener('keydown', onKey, true);
	window.removeEventListener('keydown', onKey, true);
	let wrote = false;
	if (commit && state.bp === c.bp) {
		// a shape crops only with cover; Original again leaves the fit alone when this step was the one that added it
		const ratio = c.work.aspect_ratio || c.inherited.aspect_ratio;
		if (ratio && !c.work.object_fit && !c.inherited.object_fit) { c.work.object_fit = 'cover'; } else if (!ratio && c.work.object_fit === 'cover' && c.orig.object_fit === undefined) { c.work.object_fit = undefined; }
		const props = {};
		KEYS.forEach((k) => { if (c.work[k] !== c.orig[k]) { props[k] = c.work[k] === undefined ? '' : c.work[k]; } });
		if (Object.keys(props).length) { writeStyle([[c.id, props]]); wrote = true; } // the temporary values stay until the saved draft swaps the canvas (as in phase A)
	}
	if (!wrote) { if (c.saved === null) { c.node.removeAttribute('style'); } else { c.node.setAttribute('style', c.saved); } }
	c.box.remove();
	c.tb.remove();
	endGesture();
	C.afterGesture();
	if (commit) { setState(wrote ? T('Crop applied.') : ''); } else { C.announce(T('Cancelled.')); }
}

function onKey(e) {
	if (!crop) { return; }
	const t = e.target;
	if (t && (t.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(t.tagName))) { return; }
	const mod = e.ctrlKey || e.metaKey;
	const onButton = !!(t && t.closest && t.closest('button'));
	const [x, y] = focal();
	let handled = true;
	if (e.key === 'Escape') { close(false); } else if (e.key === 'Enter' && !onButton && !mod) { close(true); } else if (e.key in STEP && !mod && !e.altKey) {
		const step = e.shiftKey ? 1 : 5;
		setFocal(x + STEP[e.key][0] * step, y + STEP[e.key][1] * step, true);
	} else if (e.key === 'Home' && !mod) { setFocal(50, 50, true); } else if (/^[1-7]$/.test(e.key) && !mod && !e.altKey) { pickRatio(RATIOS[+e.key - 1][0]); } else { handled = false; }
	if (handled) { e.preventDefault(); e.stopPropagation(); } else if (mod) { e.stopPropagation(); } // undo, copy, delete … wait until the crop is closed
}

/** Pointer: the point under the pointer becomes the focal point; the photo holds still at the place it had when the pointer went down. */
function pointer(photo) {
	const to = (e) => {
		const c = crop;
		const at = (v, from, size) => { const raw = clamp((v - from) / size * 100, 0, 100); return e.altKey ? Math.round(raw) : ([0, 50, 100].find((t) => Math.abs(raw - t) <= 3) ?? Math.round(raw)); };
		setFocal(at(e.clientX + c.view.scrollX, c.drag.left, c.size.dw), at(e.clientY + c.view.scrollY, c.drag.top, c.size.dh));
	};
	photo.addEventListener('pointerdown', (e) => {
		if ((e.pointerType === 'mouse' && e.button !== 0) || !crop || crop.drag) { return; }
		e.preventDefault();
		e.stopPropagation();
		try { photo.setPointerCapture(e.pointerId); } catch (x) { /* the pointer is already gone */ }
		const r = C.page(photo);
		crop.drag = { left: r.left, top: r.top };
		crop.box.classList.remove('bdc-settle');
		to(e);
	});
	photo.addEventListener('pointermove', (e) => { if (crop && crop.drag) { e.preventDefault(); to(e); } });
	const end = (e) => {
		if (!crop || !crop.drag) { return; }
		try { photo.releasePointerCapture(e.pointerId); } catch (x) { /* released */ }
		crop.drag = null;
		crop.box.classList.add('bdc-settle'); // the photo settles so that the window is back on the element's box
		draw();
		C.announce(label());
	};
	photo.addEventListener('pointerup', end);
	photo.addEventListener('pointercancel', end);
}

function open(t) {
	const doc = t.node.ownerDocument;
	const [w, h] = t.size || [t.img.naturalWidth, t.img.naturalHeight];
	if (!w || !h) {
		// a lazy image below the fold has not been fetched: the same file is loaded once more (from the cache afterwards) just to learn its size
		const probe = new doc.defaultView.Image();
		probe.onload = () => { if (!crop && probe.naturalWidth) { open(Object.assign({}, t, { size: [probe.naturalWidth, probe.naturalHeight] })); } };
		probe.onerror = () => setState(T('The image could not be loaded.'), true);
		probe.src = t.img.currentSrc || t.img.src;
		return true;
	}
	const p = t.p;
	const orig = {};
	const inherited = {};
	KEYS.forEach((k) => { orig[k] = ownValue(p, state.bp, k); inherited[k] = inheritedValue(p, k); });
	// the file's own point is what the image shows without a placement (ImageHtml writes it as object-position:var(--tl-focal, x% y%))
	const file = parseFocal(t.img.getAttribute('style'), /object-position:\s*var\(--tl-focal,\s*(\d+)%\s+(\d+)%\)/);
	inherited.focal_point = inherited.focal_point || (file ? file[0] + '% ' + file[1] + '%' : '50% 50%');
	const root = C.ensure(doc);
	root.replaceChildren(); // the handles and the toolbar of the element step aside
	C.items = [];
	C.layer = root;
	C.node = null;
	C.geometry = null;
	state.gesture = true; // no overlay redraw and no canvas swap while the crop mode is open
	const box = mk(doc, 'div', { class: 'bdc bdc-settle' });
	const catcher = mk(doc, 'div', { class: 'bdc-catch' });
	catcher.addEventListener('pointerdown', (e) => { e.preventDefault(); e.stopPropagation(); }); // a press beside the photo neither selects nor drags
	const photo = mk(doc, 'img', { class: 'bdc-photo', src: t.img.currentSrc || t.img.src, alt: '', draggable: 'false' });
	const win = mk(doc, 'div', { class: 'bdc-win' });
	const cross = mk(doc, 'div', { class: 'bdc-cross', tabindex: '0', role: 'slider', 'aria-label': T('Focal point'), 'aria-valuemin': '0', 'aria-valuemax': '100', 'data-bdo': 'crop-focal' });
	const text = mk(doc, 'div', { class: 'bdc-label', 'aria-hidden': 'true' });
	box.append(catcher, photo, win, cross, text);
	const tb = mk(doc, 'div', { class: 'bdo-tb', role: 'toolbar', 'aria-label': T('Crop toolbar') });
	tb.addEventListener('mousedown', (e) => e.preventDefault()); // a click on a button leaves the focus on the crosshair
	const button = (name, content, title, run, classes) => {
		const b = mk(doc, 'button', { type: 'button', class: 'bdo-b ' + (classes || ''), 'data-bdo': name, title, 'aria-label': title });
		b.append(content);
		b.addEventListener('click', (e) => { e.preventDefault(); e.stopPropagation(); run(); });
		tb.append(b);
		return b;
	};
	const words = (s) => { const x = mk(doc, 'span'); x.textContent = s; return x; };
	RATIOS.forEach(([key, name, title]) => {
		const b = button('crop-ratio-' + (key || 'original').replace('/', '-'), words(name), title, () => pickRatio(key));
		b.setAttribute('data-ratio', key);
		b.setAttribute('data-title', title);
		b.setAttribute('aria-label', name === T('Original') ? title : name);
	});
	tb.append(mk(doc, 'span', { class: 'bdo-sep', role: 'separator' }));
	button('crop-reset', words(T('Reset')), T('Remove the focal point and the shape of this screen size'), reset);
	button('crop-cancel', words(T('Cancel')), T('Cancel (Esc)'), () => close(false));
	button('crop-done', words(T('Done')), T('Done (Enter)'), () => close(true), 'bdc-done');
	root.append(box, tb);

	crop = { id: p.id, p, doc, view: doc.defaultView, node: t.node, img: t.img, w, h, bp: state.bp, saved: t.node.getAttribute('style'), orig, inherited, work: Object.assign({}, orig),
		box, photo, win, cross, label: text, tb, drag: null, frame: null, size: null };
	pointer(photo);
	apply();
	measure();
	sync();
	draw();
	crop.view.addEventListener('keydown', onKey, true);
	window.addEventListener('keydown', onKey, true);
	cross.focus({ preventScroll: true });
	setState(T('Drag the photo to choose what shows.'));
	C.announce(label());
	return true;
}

/* ---------- the ways in: the toolbar button, Enter (keys.js), a double-click ---------- */

C.crop = () => {
	const doc = previewDoc();
	const t = doc && idle() && selectedIds().length === 1 ? target(doc, state.selected) : null;
	return !!t && !contained(t.p) && open(t);
};

C.extras.push(({ doc, many, p, add, separator, icon }) => {
	if (many || p.type !== 'image' || !p.content.src || isSvg(p.content.src)) { return; }
	separator();
	const b = add('crop', T('Crop: choose the part of the photo that shows (Enter, or double-click)'), () => { const t = target(doc, p.id); if (t) { open(t); } }, icon(doc, 'crop'));
	if (contained(p)) { b.disabled = true; b.title = T('Shows the whole image'); b.setAttribute('aria-label', b.title); } else if (['focal_point', 'aspect_ratio'].some((k) => ownValue(p, state.bp, k) && inheritedValue(p, k))) {
		b.classList.add('bdc-own'); // this screen size overrides what a wider one sets
		b.title += ' · ' + T('Set for this screen size only');
		b.setAttribute('aria-label', b.title);
	}
});

on('preview', (doc) => {
	if (!doc.getElementById('tl-bd-media-style')) { doc.head.append(Object.assign(doc.createElement('style'), { id: 'tl-bd-media-style', textContent: STYLE })); }
	if (crop) { close(false); }
	doc.addEventListener('dblclick', (e) => {
		const hit = idle() && !e.target.closest('#tl-bd-overlay') && e.target.closest('[data-tl-id]');
		const t = hit && target(doc, hit.getAttribute('data-tl-id'));
		if (!t || contained(t.p)) { return; }
		e.preventDefault();
		e.stopPropagation(); // the editor's own double-click (edit the text) has nothing to do on a photo
		if (state.selected !== t.p.id || selectedIds().length !== 1) { selection(t.p.id); }
		open(t);
	}, true);
});
// another element is selected or the screen size changes: the crop cannot stay open on something that is not there any more
// (a plain resize of the editor does not close it: the status line wrapping its hint resizes the canvas too)
on('mark', () => { if (crop && (state.selected !== crop.id || state.bp !== crop.bp)) { close(false); } });
on('resize', () => { if (crop && state.bp !== crop.bp) { close(false); } });
