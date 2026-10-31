/* Talea – the page builder's media on the canvas, part 2: the Background popover (compose phase C2, see docs/specs/visual-compose.md).
 *
 * A Background button in the toolbar of a section and of every container opens a popover next to it with three tabs: Colour (the colour tokens of the
 * design system as swatches, read from the canvas itself), Image (choose from Media or upload, the focal point in crop mode, an overlay that darkens
 * the photo, remove) and Video (sections only). Every control is ONE history step; style values go to the screen size the device switcher shows.
 *
 * The popover lives in the overlay layer and is drawn again by every C.render (a saved draft swaps the whole canvas, which would otherwise close it):
 * `active` is all it remembers – the element, the tab and the control that has the focus. Esc and a press outside close it, the focus goes back to the button. */
import { D, T, BP, state, on, find, contains, writeStyle, applyChange, setState, ownValue, inheritedValue, effectiveValue } from './core.js';
import { previewDoc } from './canvas.js';
import { contrastCheck } from './panels.js';
import { C } from './overlay.js';
import { backgroundUrl, backgroundWrites, isSectionOrContainer } from './media.js';

const mk = C.mk;

/** The overlay chips: a flat colour over the photo (Style `overlay`) or the darkening gradient from the bottom (Style `gradient`). */
const DARKEN = 'rgb(0 0 0 / 0.3)';
const DARKEN_MORE = 'rgb(0 0 0 / 0.55)';
const DARKEN_BOTTOM = 'linear-gradient(180deg, transparent, rgb(0 0 0 / 0.55))';

Object.assign(C.ICONS, { background: '<path d="m19 11-8-8-8.6 8.6a2 2 0 0 0 0 2.8l5.2 5.2c.8.8 2 .8 2.8 0L19 11Z"/><path d="m5 2 5 5"/><path d="M2 13h15"/><path d="M22 20a2 2 0 1 1-4 0c0-1.6 1.7-2.4 2-4 .3 1.6 2 2.4 2 4Z"/>' });

const STYLE = `
#tl-bd-overlay .bdb{position:absolute;margin:0;inset:auto;width:calc(304px*var(--s));max-width:calc(100vw - 16px*var(--s));max-height:calc(100vh - 16px*var(--s));overflow:auto;padding:calc(10px*var(--s));border:0;border-radius:calc(10px*var(--s));
	background:#1d1d1f;color:#fff;font:calc(13px*var(--s))/1.35 system-ui,sans-serif;pointer-events:auto;box-shadow:0 calc(6px*var(--s)) calc(22px*var(--s)) rgb(0 0 0/.4),0 0 0 calc(1px*var(--s)) rgb(255 255 255/.35);touch-action:manipulation}
#tl-bd-overlay .bdb *{box-sizing:border-box}
#tl-bd-overlay .bdb-head{display:flex;align-items:center;justify-content:space-between;gap:calc(8px*var(--s));margin-bottom:calc(8px*var(--s))}
#tl-bd-overlay .bdb-head strong{font-size:calc(13px*var(--s))}
#tl-bd-overlay .bdb-tabs{display:flex;gap:calc(2px*var(--s));margin-bottom:calc(10px*var(--s));padding:calc(2px*var(--s));border-radius:calc(8px*var(--s));background:rgb(255 255 255/.1)}
#tl-bd-overlay .bdb-tab{flex:1;min-height:calc(28px*var(--s));padding:0 calc(8px*var(--s));border:0;border-radius:calc(6px*var(--s));background:transparent;color:#fff;font:600 calc(12px*var(--s))/1 system-ui,sans-serif;cursor:pointer}
#tl-bd-overlay .bdb-tab[aria-selected="true"]{background:#fff;color:#1d1d1f}
#tl-bd-overlay .bdb-btn,#tl-bd-overlay .bdb-x{display:inline-flex;align-items:center;justify-content:center;min-height:calc(30px*var(--s));padding:0 calc(10px*var(--s));border:calc(1px*var(--s)) solid rgb(255 255 255/.4);border-radius:calc(6px*var(--s));background:transparent;color:#fff;font:600 calc(12px*var(--s))/1 system-ui,sans-serif;cursor:pointer}
#tl-bd-overlay .bdb-x{min-width:calc(30px*var(--s));padding:0;border-color:transparent;font-size:calc(18px*var(--s))}
#tl-bd-overlay .bdb-btn:hover,#tl-bd-overlay .bdb-x:hover{background:rgb(255 255 255/.18)}
#tl-bd-overlay .bdb-btn:disabled{opacity:.4;cursor:not-allowed}
#tl-bd-overlay .bdb-btn:disabled:hover{background:transparent}
#tl-bd-overlay .bdb-btn[aria-pressed="true"]{background:#ff4f2e;border-color:#ff4f2e}
#tl-bd-overlay .bdb :focus-visible{outline:calc(2px*var(--s)) solid #8ab4f8;outline-offset:calc(1px*var(--s))}
#tl-bd-overlay .bdb-sw{display:flex;flex-wrap:wrap;gap:calc(6px*var(--s))}
#tl-bd-overlay .bdb-s{position:relative;width:calc(34px*var(--s));height:calc(34px*var(--s));padding:0;border:calc(1px*var(--s)) solid rgb(255 255 255/.5);border-radius:50%;cursor:pointer}
#tl-bd-overlay .bdb-s[aria-pressed="true"]{box-shadow:0 0 0 calc(2px*var(--s)) #1d1d1f,0 0 0 calc(4px*var(--s)) #ff4f2e}
#tl-bd-overlay .bdb-s.bdb-none{background:linear-gradient(135deg,transparent calc(50% - 1px*var(--s)),#ff4f2e 0 calc(50% + 1px*var(--s)),transparent 0)}
#tl-bd-overlay .bdb-row{display:flex;flex-wrap:wrap;gap:calc(6px*var(--s));margin-top:calc(8px*var(--s))}
#tl-bd-overlay .bdb-label{margin:calc(12px*var(--s)) 0 calc(4px*var(--s));font-size:calc(11px*var(--s));font-weight:700;letter-spacing:.04em;text-transform:uppercase;color:rgb(255 255 255/.7)}
#tl-bd-overlay .bdb-prev{display:block;width:100%;height:calc(96px*var(--s));object-fit:cover;border-radius:calc(6px*var(--s));background:rgb(255 255 255/.08)}
#tl-bd-overlay .bdb-note{margin:calc(6px*var(--s)) 0 0;color:rgb(255 255 255/.75);font-size:calc(12px*var(--s))}
#tl-bd-overlay .bdb-warn{margin:calc(8px*var(--s)) 0 0;padding:calc(6px*var(--s)) calc(8px*var(--s));border-radius:calc(6px*var(--s));background:rgb(255 150 40/.2);color:#ffd9a8;font-size:calc(12px*var(--s))}
`;

/** The popover that is open: { id, tab, focus (the data-bdo of the control to give the focus back to), pending (give it back once drawn) } – or null. */
let active = null;
let uploader = null;

const hasVideo = (p) => p.type === 'section';
/** A text button of a panel: a click remembers it for the focus the redraw gives back. */
const act = (doc, name, label, run, disabled, title) => {
	const b = mk(doc, 'button', { type: 'button', class: 'bdb-btn', 'data-bdo': name, disabled: disabled || null, title: title || null });
	b.textContent = label;
	b.addEventListener('click', () => { active.focus = name; active.pending = true; run(); });
	return b;
};
const button = () => { const doc = previewDoc(); return doc && doc.querySelector('#tl-bd-overlay [data-bdo="background"]'); };
const OPENER_ATTRIBUTES = { 'aria-pressed': 'true', 'aria-expanded': 'true' };

/** Takes the popover off the page right away (no redraw of the overlay: a press on a handle outside must still find its handle). */
function hide(refocus) {
	const doc = previewDoc();
	active = null;
	if (!doc) { return; }
	const pop = doc.getElementById('tl-bd-bg');
	if (pop) { try { pop.hidePopover(); } catch (e) { /* already hidden */ } pop.remove(); }
	const b = button();
	if (b) { Object.keys(OPENER_ATTRIBUTES).forEach((k) => b.setAttribute(k, 'false')); if (refocus) { b.focus({ preventScroll: true }); } }
}
function open(id) {
	const n = find(id);
	active = { id, tab: n && effectiveValue(n.p, 'background_image') ? 'image' : 'colour', focus: null, pending: true };
	C.render();
}
const toggle = (id) => { if (active && active.id === id) { hide(true); } else { open(id); } };

/* ---------- the writes: each one history step ---------- */

const setColour = (id, key) => {
	const n = find(id);
	// "none" on a smaller screen size has to override the colour a wider one passes on; on desktop it removes the value
	writeStyle([[id, { background: key === '' && n && state.bp !== 'base' && inheritedValue(n.p, 'background') ? 'transparent' : key }]]);
	C.render();
};
/** bp: the screen size the person was on when the file was chosen (an upload takes its time). */
const setImage = (id, url, bp = state.bp) => {
	const n = find(id);
	if (!n) { return; }
	writeStyle(backgroundWrites(n.p, bp, url));
	setState(T('Background image set.'));
	C.render();
};
function removeImage(id) {
	const n = find(id);
	if (!n) { return; }
	const props = { background_image: '', background_focal: '', overlay: '' };
	if (ownValue(n.p, state.bp, 'gradient') === DARKEN_BOTTOM) { props.gradient = ''; } // the darkening lay over this photo; any other gradient is the person's own
	writeStyle([[id, props]]);
	setState(T('Background image removed.'));
	C.render();
}
function setOverlay(id, kind) {
	const n = find(id);
	if (!n) { return; }
	const props = {};
	// the overlay of a wider screen size is switched off here by a transparent one; the darkening gradient only where this size set it
	props.overlay = { dark: DARKEN, darker: DARKEN_MORE }[kind] || (state.bp !== 'base' && inheritedValue(n.p, 'overlay') ? 'transparent' : '');
	if (kind === 'bottom') {
		props.gradient = DARKEN_BOTTOM;
		// Style::css layers a gradient over the photo only within one state: a size that takes the photo from a wider one would lose it, so the photo (and its point) is written here too
		const photo = effectiveValue(n.p, 'background_image');
		if (photo && !ownValue(n.p, state.bp, 'background_image')) {
			props.background_image = photo;
			props.background_focal = effectiveValue(n.p, 'background_focal'); // its own point, or the one the wider size passes on
		}
	} else if (ownValue(n.p, state.bp, 'gradient') === DARKEN_BOTTOM) { props.gradient = ''; }
	writeStyle([[id, props]]);
	C.render();
}
const setVideo = (id, url) => {
	const n = find(id);
	if (!n) { return; }
	applyChange(() => { n.p.content.background_video = url; });
	setState(url ? T('Background video set.') : T('Background video removed.'));
	C.render();
};

/** The file chooser lives in the editor page, not in the canvas: a saved draft swaps the canvas while the dialog is open. */
function chooseFile(id) {
	if (!uploader) {
		uploader = Object.assign(document.createElement('input'), { type: 'file', accept: 'image/*', hidden: true });
		uploader.setAttribute('data-bdo', 'bg-upload-input');
		document.body.append(uploader);
		uploader.addEventListener('change', async () => {
			const file = uploader.files[0];
			const target = uploader.dataset.id;
			const bp = state.bp;
			uploader.value = '';
			if (!file) { return; }
			setState(T('Uploading…'));
			const [uploaded] = await C.upload([file]);
			if (uploaded) { setImage(target, uploaded.url, bp); }
		});
	}
	uploader.dataset.id = id;
	uploader.click();
}

/* ---------- the popover ---------- */

const NAMES = () => ({ colour: T('Colour'), image: T('Image'), video: T('Video') });

function colourPanel(doc, p) {
	const current = effectiveValue(p, 'background') || '';
	const box = mk(doc, 'div', { class: 'bdb-sw', role: 'group', 'aria-label': T('Background colour') });
	const swatch = (key, name, none) => {
		const on = key === '' ? !current || current === 'transparent' : current === key;
		const b = mk(doc, 'button', { type: 'button', class: 'bdb-s' + (none ? ' bdb-none' : ''), 'data-bdo': 'bg-sw-' + (key || 'none'), title: name, 'aria-label': name, 'aria-pressed': on ? 'true' : 'false' });
		if (!none) { b.style.background = 'var(--tl-color-' + key + ')'; } // the canvas resolves the token (dark mode too): nothing is computed here
		b.addEventListener('click', () => { active.focus = b.getAttribute('data-bdo'); setColour(p.id, key); });
		box.append(b);
	};
	swatch('', T('None'), true);
	Object.entries(D.schema.tokens.colors).forEach(([key, name]) => swatch(key, T(name)));
	return [box, mk(doc, 'p', { class: 'bdb-note' }, T('A free colour stays in the style panel.'))];
}

function imagePanel(doc, p, node) {
	const url = effectiveValue(p, 'background_image') ? backgroundUrl(node) : '';
	const fixed = doc.defaultView.getComputedStyle(node).backgroundAttachment === 'fixed';
	const out = [url ? mk(doc, 'img', { class: 'bdb-prev', src: url, alt: '' }) : mk(doc, 'p', { class: 'bdb-note' }, T('No background image.'))];
	const ownImage = !!ownValue(p, state.bp, 'background_image');
	out.push(mk(doc, 'div', { class: 'bdb-row' },
		act(doc, 'bg-pick', T('Choose from Media'), () => window.taleaPickImage && window.taleaPickImage((o) => setImage(p.id, o.url))),
		act(doc, 'bg-upload', T('Upload'), () => chooseFile(p.id)),
		act(doc, 'bg-focal', T('Set focal point'), () => { C.cropBackground(p.id); }, !url || fixed, fixed ? T('Not available with a background that stays fixed.') : null),
		act(doc, 'bg-remove', T('Remove image'), () => removeImage(p.id), !ownImage, url && !ownImage ? T('This image comes from a larger screen size. Remove it there.') : null)));
	const g = effectiveValue(p, 'gradient');
	const o = effectiveValue(p, 'overlay');
	const now = g === DARKEN_BOTTOM ? 'bottom' : (!o || o === 'transparent' ? 'none' : ({ [DARKEN]: 'dark', [DARKEN_MORE]: 'darker' }[o] || ''));
	out.push(mk(doc, 'div', { class: 'bdb-label' }, T('Overlay')));
	const chips = mk(doc, 'div', { class: 'bdb-row', role: 'group', 'aria-label': T('Overlay') });
	[['none', T('None')], ['dark', T('Darken')], ['darker', T('Darken more')], ['bottom', T('Darken at the bottom')]].forEach(([kind, name]) => {
		const b = mk(doc, 'button', { type: 'button', class: 'bdb-btn', 'data-bdo': 'bg-ov-' + kind, disabled: url ? null : true, 'aria-pressed': now === kind ? 'true' : 'false' });
		b.textContent = name;
		b.addEventListener('click', () => { active.focus = b.getAttribute('data-bdo'); setOverlay(p.id, kind); });
		chips.append(b);
	});
	out.push(chips);
	return out;
}

function videoPanel(doc, p) {
	const video = p.content.background_video || '';
	const name = mk(doc, 'p', { class: 'bdb-note' }, video ? decodeURIComponent(video.split('/').pop()) : T('No background video.'));
	return [name,
		mk(doc, 'div', { class: 'bdb-row' },
			act(doc, 'bg-video-pick', T('Choose a video from Media'), () => window.taleaPickImage && window.taleaPickImage((o) => {
				if (!/\.(mp4|webm)$/i.test(o.url || '')) { setState(T('Choose a video in MP4 or WebM format.'), true); return; }
				setVideo(p.id, o.url);
			}, false, true)),
			act(doc, 'bg-video-remove', T('Remove video'), () => setVideo(p.id, ''), !video)),
		mk(doc, 'p', { class: 'bdb-note' }, T('Same video on every screen size. Hidden for visitors who ask for less motion; the image is shown instead.'))];
}

/** The check of the builder (panels.js) for this element, run once per canvas: a colour change is seen once the saved draft has swapped it in. */
function warning(doc, p) {
	if (!doc.__bdContrast) { doc.__bdContrast = contrastCheck(); }
	const found = doc.__bdContrast.find(([id]) => id === p.id || contains(p, id));
	return found ? mk(doc, 'p', { class: 'bdb-warn', role: 'status' }, found[1]) : null;
}

function draw(doc, p, node) {
	const root = C.layer;
	const opener = root.querySelector('[data-bdo="background"]');
	if (!opener) { active = null; return; }
	Object.keys(OPENER_ATTRIBUTES).forEach((k) => opener.setAttribute(k, 'true'));
	const title = T('Background') + ' · ' + BP[state.bp];
	const tabs = ['colour', 'image'].concat(hasVideo(p) ? ['video'] : []);
	if (!tabs.includes(active.tab)) { active.tab = 'colour'; }
	const names = NAMES();
	const pop = mk(doc, 'div', { id: 'tl-bd-bg', class: 'bdb', popover: 'manual', role: 'dialog', 'aria-label': title });
	const close = mk(doc, 'button', { type: 'button', class: 'bdb-x', 'data-bdo': 'bg-close', title: T('Close (Esc)'), 'aria-label': T('Close (Esc)') }, '×');
	close.addEventListener('click', () => hide(true));
	pop.append(mk(doc, 'div', { class: 'bdb-head' }, mk(doc, 'strong', {}, title), close));
	const select = (tab) => { active.tab = tab; active.focus = 'bg-tab-' + tab; active.pending = true; C.render(); };
	const list = mk(doc, 'div', { class: 'bdb-tabs', role: 'tablist', 'aria-label': T('Background') });
	tabs.forEach((tab) => {
		const b = mk(doc, 'button', { type: 'button', role: 'tab', class: 'bdb-tab', id: 'bdb-tab-' + tab, 'data-bdo': 'bg-tab-' + tab, 'aria-selected': active.tab === tab ? 'true' : 'false',
			'aria-controls': 'bdb-panel', tabindex: active.tab === tab ? '0' : '-1' }, names[tab]);
		b.addEventListener('click', () => select(tab));
		list.append(b);
	});
	list.addEventListener('keydown', (e) => {
		const at = tabs.indexOf(active.tab);
		const to = { ArrowRight: tabs[(at + 1) % tabs.length], ArrowLeft: tabs[(at + tabs.length - 1) % tabs.length], Home: tabs[0], End: tabs[tabs.length - 1] }[e.key];
		if (to) { e.preventDefault(); select(to); }
	});
	const panel = mk(doc, 'div', { id: 'bdb-panel', role: 'tabpanel', 'aria-labelledby': 'bdb-tab-' + active.tab });
	panel.append(...(active.tab === 'colour' ? colourPanel(doc, p) : active.tab === 'image' ? imagePanel(doc, p, node) : videoPanel(doc, p)));
	const warn = warning(doc, p);
	pop.append(list, panel);
	if (warn) { pop.append(warn); }
	pop.addEventListener('focusin', (e) => { const k = e.target.getAttribute && e.target.getAttribute('data-bdo'); if (k) { active.focus = k; } });
	// keys typed in the popover are for the popover (Delete must not delete the element, arrows must not select siblings); Ctrl/Cmd shortcuts such as undo go on
	pop.addEventListener('keydown', (e) => { if (!e.ctrlKey && !e.metaKey && e.key !== 'Escape') { e.stopPropagation(); } });
	root.append(pop);
	pop.showPopover();
	const win = doc.defaultView;
	const m = C.factor();
	const at = C.page(opener);
	const box = pop.getBoundingClientRect();
	const left = Math.max(win.scrollX + 4 * m, Math.min(at.left, win.scrollX + doc.documentElement.clientWidth - box.width - 4 * m));
	let top = at.bottom + 6 * m;
	if (top + box.height > win.scrollY + win.innerHeight - 4 * m) { top = Math.max(win.scrollY + 4 * m, at.top - box.height - 6 * m); }
	pop.style.left = left + 'px';
	pop.style.top = top + 'px';
	if (active.pending) {
		active.pending = false;
		// after the render: C.render gives the focus back to the control it remembered (and that moves active.focus), the popover then takes it for the one wanted now
		const want = active.focus;
		queueMicrotask(() => { const f = pop.isConnected && (pop.querySelector('[data-bdo="' + want + '"]:not(:disabled)') || pop.querySelector('[role="tab"][aria-selected="true"]')); if (f) { f.focus({ preventScroll: true }); } });
	}
}

C.providers.push(({ doc, id, p, node }) => {
	if (!active) { return; }
	if (active.id !== id) { active = null; return; }
	draw(doc, p, node);
});

C.extras.push(({ many, p, add, icon, doc }) => {
	if (many || p.locked || !isSectionOrContainer(p)) { return; }
	const b = add('background', T('Background: colour, photo or video behind this element'), () => toggle(p.id), icon(doc, 'background'), active && active.id === p.id);
	b.setAttribute('aria-haspopup', 'dialog');
	b.setAttribute('aria-expanded', active && active.id === p.id ? 'true' : 'false');
});

on('preview', (doc) => {
	if (!doc.getElementById('tl-bd-background-style')) { doc.head.append(Object.assign(doc.createElement('style'), { id: 'tl-bd-background-style', textContent: STYLE })); }
	// the canvas that is being replaced: when the focus was in the popover, the new one gives it back to the control
	const old = previewDoc();
	if (active && old && old !== doc && old.activeElement && old.activeElement.closest && old.activeElement.closest('#tl-bd-bg')) { active.pending = true; }
	doc.addEventListener('keydown', (e) => {
		if (!active || state.gesture || e.key !== 'Escape') { return; }
		e.preventDefault();
		e.stopPropagation();
		hide(true);
	}, true);
	doc.addEventListener('pointerdown', (e) => {
		if (!active || state.gesture || !e.target.closest || e.target.closest('#tl-bd-bg') || e.target.closest('[data-bdo="background"]')) { return; }
		hide(false);
	}, true);
});
on('mark', () => { if (active && (state.selected !== active.id || state.multi.length)) { hide(false); } });
