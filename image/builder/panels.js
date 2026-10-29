/* Talea – page builder, the panels around the canvas: the top bar (history, devices, versions, sharing, comments, help with the
 * tour, publishing with the pre-publish check) and the left panel (Add: elements, my and ready-made sections, the AI; Structure: the tree). */
import { T, D, TYPY, BP, state, root, dom, el, icon, text, query, confirmAction, find, contains, withNewIds, newElement, labelText, applyChange, back, forward,
	save, stopSaving, rejectInvalid, conflictDialog, selection, insert, move, redraw, redrawPanels, setState } from './core.js';
import { previewSize, refreshPreview, hiddenOnCanvas, showSpot, dropAt, endPlacing } from './canvas.js';

/* ---------- top bar ---------- */

let tabList;
export function createBar() {
	dom.stateText = el('span', { class: 'bd-status', role: 'status' }, state.changed ? T('Draft in progress') : T("Published"));
	tabList = el('header', { class: 'bd-bar' });
	root.classList.toggle('bd-in-component', !!D.context); // a component opened from a page's canvas
	root.append(tabList);
	redrawBar();
}
let barLook = '';
export function redrawBar() {
	// the bar is rebuilt only when what it shows changes – re-rendering under the cursor would "swallow" a click in progress
	const look = [state.changed, state.bp, state.zoom, state.undo.length > 0, state.redo.length > 0, D.page.published].join();
	if (look === barLook && tabList.childElementCount) { return; }
	barLook = look;
	const bpTl = Object.entries({ base: 'desktop', tablet: 'tablet', mobile: 'mobile' }).map(([bp, ik]) =>
		el('button', { type: 'button', title: BP[bp], 'aria-label': BP[bp], 'aria-pressed': String(state.bp === bp), onclick: () => { state.bp = bp; previewSize(dom.preview); redrawBar(); redrawPanels(); } }, icon(ik)));
	// less frequent things of the page sit in the "…" menu, so the bar stays short (focus mode, #115)
	const pageMore = el('div', { id: 'bd-page-more', class: 'bd-more', popover: 'auto' },
		el('label', { class: 'bd-more-field' }, el('span', {}, T('Preview size')), el('select', { class: 'bd-magnifier', onchange: (e) => { state.zoom = e.target.value; previewSize(dom.preview); } },
			[['', T('Fit')], ['1920', T('Wide monitor (1920 px)')], ['100', '100 %'], ['75', '75 %'], ['50', '50 %']].map(([k, n]) => el('option', { value: k, selected: state.zoom === k }, n)))),
		D.page.published && state.changed ? el('button', { type: 'button', onclick: () => { pageMore.hidePopover(); discard(); } }, icon('undo'), el('span', {}, T('Discard changes'))) : null);
	const pageMoreButton = el('button', { type: 'button', class: 'bd-btn bd-icon-btn', title: T('More'), 'aria-label': T('More'), popovertarget: 'bd-page-more' }, icon('more'));
	pageMore.addEventListener('toggle', (e) => {
		if (e.newState !== 'open') { return; }
		const r = pageMoreButton.getBoundingClientRect();
		pageMore.style.top = (r.bottom + 4) + 'px';
		pageMore.style.left = Math.max(8, r.right - pageMore.offsetWidth) + 'px';
	});
	tabList.replaceChildren(...[
		el('div', { class: 'bd-bar-start' },
			el('a', { class: 'bd-btn', href: D.back.url, title: D.back.text }, T('Done')),
			el('div', { class: 'bd-group', role: 'group', 'aria-label': T('History') },
				el('button', { type: 'button', title: T('Undo (Ctrl+Z)'), disabled: !state.undo.length, onclick: back }, icon('undo')),
				el('button', { type: 'button', title: T('Redo (Ctrl+Shift+Z)'), disabled: !state.redo.length, onclick: forward }, icon('redo')))),
		el('div', { class: 'bd-name' }, el('h1', {}, D.page.title), D.context ? el('small', { class: 'bd-context' }, sharedBy(D.context.places)) : null,
			el('small', {}, state.changed ? T('draft in progress – visitors see the published version') : T('no changes against the live site')), dom.stateText),
		el('div', { class: 'bd-bar-end' },
			el('div', { class: 'bd-group', role: 'group', 'aria-label': T("Device") }, bpTl),
			el('button', { type: 'button', class: 'bd-btn bd-icon-btn', title: T('Published versions'), 'aria-label': T('Versions'), onclick: versionsDialog }, icon('version')),
			D.urls.share ? el('button', { type: 'button', class: 'bd-btn bd-icon-btn', title: T('Share a link to the draft preview'), 'aria-label': T('Share'), onclick: shareDialog }, icon('share')) : null,
			D.comments ? el('button', { type: 'button', class: 'bd-btn bd-icon-btn', title: T('Comments from people with a preview link'), 'aria-label': T('Comments'), onclick: commentsDialog }, icon('comment'),
				openComments().length ? el('span', { class: 'bd-count' }, String(openComments().length)) : null) : null,
			el('a', { class: 'bd-btn bd-icon-btn', href: D.page.url, target: '_blank', rel: 'noopener', title: T('Open the published page'), 'aria-label': T('Open the published page') }, icon('eye')),
			el('button', { type: 'button', class: 'bd-btn bd-icon-btn', title: T('Help and keyboard shortcuts (?)'), 'aria-label': T('Help'), onclick: hint }, icon('placeholder')),
			pageMoreButton, pageMore,
			el('button', { type: 'button', class: 'bd-btn bd-btn-main', disabled: (!state.changed && D.page.published) || D.page.can_publish === false,
				title: D.page.can_publish === false ? T('Only an editor or administrator can publish. Your changes stay saved as a draft.') : null, onclick: publishAfterCheck }, T('Publish'))),
	].filter(Boolean));
}

/** Where a component opened from a page is used: its changes apply there too. */
function sharedBy(places) {
	if (!places.length) { return T('Not used anywhere yet'); }
	return T('Changes apply to: %s').replace('%s', places.slice(0, 3).join(', ') + (places.length > 3 ? ' ' + T('and %d more').replace('%d', String(places.length - 3)) : ''));
}

/** The first placeholder in square brackets in the strings of an element's content (arrays and objects included), or null. */
function placeholder(value) {
	if (typeof value === 'string') { const m = value.replace(/<[^>]*>/g, '').match(/\[[^\[\]{}<>\n]{0,60}\p{L}[^\[\]{}<>\n]{0,60}\]/u); return m ? m[0] : null; }
	if (value && typeof value === 'object') { for (const v of Object.values(value)) { const m = placeholder(v); if (m) { return m; } } }
	return null;
}

/** What the page lacks for a visitor or a search engine: buttons without a link, images without a file or description, the heading outline. */
function check() {
	const findings = [];
	const headings = [];
	const tags = (x) => typeof x === 'string' && x.includes('{{');
	(function walk(children) {
		children.forEach((p) => {
			const o = p.content || {};
			if (p.type === 'button' && (!o.link || o.link === '#')) { findings.push([p.id, T('The button “%s” leads nowhere – add a link.').replace('%s', o.text || '')]); }
			if (p.type === 'image' && !o.src) { findings.push([p.id, T('No image selected – it will not appear on the site.')]); }
			if (p.type === 'image' && o.src && !o.alt && !tags(o.src)) { findings.push([p.id, T('The image has no description for blind visitors (alt).')]); }
			// sample content: a placeholder in square brackets ([number], [Customer’s name]) – the same pattern as Builder\SampleContent::PLACEHOLDER
			const sample = placeholder(o);
			if (sample) { findings.push([p.id, T('Sample content “%s” – replace it with your own or delete it.').replace('%s', sample[0])]); }
			const level = p.type === 'heading' && /^h([1-6])$/.exec(p.tag || 'h2'); // a heading with the p tag (big number, label) is not in the outline
			if (level) { headings.push([p.id, Number(level[1]), text(o.text)]); }
			// a component use brings the headings of its component (a hero component usually carries the page's h1) – Builder\Check::outline
			const component = p.type === 'component' && (D.components || []).find((k) => String(k.id) === String(o.component));
			if (component) { (component.outline || []).forEach(([n, t]) => headings.push([p.id, n, t])); }
			if (p.children) { walk(p.children); }
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
	const doc = dom.preview && dom.preview.contentDocument;
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
export function hint() {
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
export function tour(step) {
	const stops = [
		[dom.left, T('Elements and ready-made sections'), T('The + on the left opens the elements and ready-made sections. Click to insert one after the selected element, drag it anywhere on the page. The button below it shows the page as a tree.')],
		[dom.frame2, T('The page as visitors will see it'), T('Click to select an element, double-click to edit text. At the top you switch the preview to tablet and mobile – the style then changes only for that width.')],
		[dom.right, T('Content, style and advanced'), T('On the right you change the text, links, colours, spacing and behaviour of the selected element. Take colours and sizes from the list – they keep the website consistent.')],
		[tabList, T('Saving and publishing'), T('Changes save automatically as a draft. Visitors see them only after Publish – before that we warn you about missing links, descriptions and low contrast.')],
	];
	document.querySelectorAll('.bd-highlighted').forEach((x) => x.classList.remove('bd-highlighted'));
	try { localStorage.setItem('tl-bd-tour', '1'); } catch (e) { /* private mode */ }
	if (step >= stops.length) { return; }
	const [target, heading, text] = stops[step];
	if (target === dom.left && !state.leftOpen) { state.leftOpen = true; state.leftTab = 'add'; redrawLeft(); }
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

function versionsDialog() {
	query(D.urls.versions).then((j) => {
		if (j.ok === false) { setState(j.error, true); return; }
		const list = el('ul');
		const changes = el('div', { class: 'bd-compare', hidden: true });
		// what changed: the text of the version against the current draft (the server compares, layout is not compared)
		const compare = (r) => save().then(() => query(D.urls.compare + (D.urls.compare.includes('?') ? '&' : '?') + 'revision_id=' + r.revision_id)).then((o) => {
			if (!o.ok) { setState(o.error, true); return; }
			changes.replaceChildren(el('p', {}, T('Version from %s').replace('%s', r.when), ' → ', T('current draft'), '. ', el('ins', {}, T('added') + ': ' + o.added), ' · ', el('del', {}, T('deleted') + ': ' + o.deleted)),
				o.added + o.deleted === 0 ? el('p', { class: 'bd-empty' }, T('The text has not changed since this version (formatting and image changes are not compared).'))
					: el('div', { html: o.html })); // Core\Diff escapes the text itself: only <p>, <ins> and <del> are markup
			changes.hidden = false;
		});
		(j.revisions || []).forEach((r) => list.append(el('li', {}, el('span', {}, r.when, r.who ? ' · ' + r.who : ''),
			el('button', { type: 'button', class: 'bd-btn', onclick: () => compare(r) }, T('what changed')),
			el('button', { type: 'button', class: 'bd-btn', onclick: () => { d.close(); stopSaving().then(() => query(D.urls.restore, { revision_id: r.revision_id })).then((o) => {
				if (!o.ok) { setState(o.error, true); return; }
				state.undo.push(JSON.stringify(state.build)); state.build = o.build; state.saved = JSON.stringify(o.build); state.version = o.version; state.conflict = false;
				state.changed = true; state.selected = null;
				setState(T('The older version is in the draft – publish it when ready')); redraw(); refreshPreview();
			}); } }, T('Load into draft')))));
		const d = el('dialog', { class: 'bd-dialog' }, el('div', {}, el('h2', {}, T('Published versions')), j.revisions && j.revisions.length ? list : el('p', { class: 'bd-empty' }, T('No older versions yet.')), changes),
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

let leftContent;
/** Focus mode (#115): the page, the bar and the properties; the rail on the left opens Add or Structure over the canvas. */
function redrawRail() {
	root.classList.toggle('bd-left-open', state.leftOpen);
	const item = (key, ik, name) => el('button', { type: 'button', title: name, 'aria-label': name, 'aria-pressed': String(state.leftOpen && state.leftTab === key),
		onclick: () => { state.leftOpen = !(state.leftOpen && state.leftTab === key); state.leftTab = key; redrawLeft(); } }, icon(ik));
	dom.rail.replaceChildren(item('add', 'plus', T('Add elements and sections')), item('structure', 'structure', T('Structure')));
}
export function redrawLeft() {
	if (dom.rail) { redrawRail(); }
	const tabItem = (key, name) => el('button', { type: 'button', role: 'tab', 'aria-selected': String(state.leftTab === key), onclick: () => { state.leftTab = key; redrawLeft(); } }, name);
	leftContent = el('div', { class: 'bd-panel' });
	dom.left.replaceChildren(el('div', { class: 'bd-tabs', role: 'tablist' }, tabItem('add', T("Add")), tabItem('structure', T('Structure'))), leftContent);
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

/**
 * AI: the Ask box (#31) – a short request edits the draft. Nothing is sent before the click; the server applies the answer as a draft
 * change (checked like any build), the result is one step in the history: Undo takes it back.
 */
function aiAsk() {
	const field = el('textarea', { rows: 3, maxlength: 255, placeholder: T('E.g.: Make the hero shorter. Add a pricing section with three plans.') });
	const selected = state.selected && find(state.selected);
	const d = el('dialog', { class: 'bd-dialog' }, el('div', {}, el('h2', {}, T('Ask the assistant')),
		el('label', { class: 'bd-field' }, el('span', {}, T('What should change on this page?')), field),
		selected ? el('p', { class: 'bd-empty', style: 'text-align:left;padding:0' }, T('The selected element is passed on as a hint: ') + labelText(selected.p || selected)) : null,
		el('p', { class: 'bd-empty', style: 'text-align:left;padding:0' }, T('When you click Send, the structure and texts of this page and your request are sent to %s. Nothing is sent before, nothing is published, and the change can be undone. Prices, names and opening hours are left for you to fill in.').replace('%s', D.ai_provider))),
	el('footer', {}, el('button', { type: 'button', class: 'bd-btn', onclick: () => d.close() }, T('Cancel')),
		el('button', { type: 'button', class: 'bd-btn bd-btn-main', onclick: () => {
			const request = field.value.trim();
			if (!request) { field.focus(); return; }
			d.close();
			setState(T('The assistant is working…'));
			save().then((ok) => (ok ? query(D.urls.ask, { request: request, selected: state.selected || '', version: state.version }) : null)).then((j) => {
				if (!j) { return; }
				if (!j.ok) { setState(j.error || T('The assistant did not respond.'), true); return; }
				applyChange(() => { state.build = j.build; });
				state.saved = JSON.stringify(j.build);
				state.version = j.version || state.version;
				if (state.selected && !find(state.selected)) { state.selected = null; }
				redrawPanels();
				refreshPreview();
				setState(T('Done: %s – Ctrl+Z undoes it. It is a draft; nothing is published.').replace('%s', j.summary || ''));
			});
		} }, T('Send'))));
	d.addEventListener('close', () => d.remove());
	document.body.append(d);
	d.showModal();
	field.focus();
}

function addPanel() {
	if (D.ai) {
		leftContent.append(el('button', { type: 'button', class: 'bd-btn bd-ai-section', onclick: aiAsk }, '✨ ' + T('Ask the assistant')),
			el('button', { type: 'button', class: 'bd-btn bd-ai-section', onclick: aiSection }, '✨ ' + T('Create a section with AI')));
	}
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
		el('button', { onmouseenter: (e) => showSectionPreview(e.currentTarget, s.key), onmouseleave: hideSectionPreview, onfocus: (e) => showSectionPreview(e.currentTarget, s.key), onblur: hideSectionPreview, type: 'button', draggable: 'true', ondragstart: (e) => startDrag(e, { section: s.key }), ondragend: endDrag, onclick: () => query(D.urls.section + (D.urls.section.includes('?') ? '&' : '?') + 'key=' + encodeURIComponent(s.key), { ok: 1 }).then((j) => {
			if (!j.ok) { setState(j.error, true); return; }
			D.classes = j.classes;
			insert(j.element);
		}) }, el('strong', {}, s.name), el('small', {}, s.description)));
}

export function redrawTree() {
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
			onmouseenter: () => { const t = dom.preview && dom.preview.contentDocument && dom.preview.contentDocument.querySelector('[data-tl-id="' + p.id + '"]'); if (t) { t.classList.add('tl-bd-hover'); } },
			onmouseleave: () => { const t = dom.preview && dom.preview.contentDocument && dom.preview.contentDocument.querySelector('[data-tl-id="' + p.id + '"]'); if (t) { t.classList.remove('tl-bd-hover'); } },
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

/** Opens the Add panel (elements and sections), which inserts after the selected element ("Add section" on the canvas, #116). */
export function openAdd() { state.leftOpen = true; state.leftTab = 'add'; redrawLeft(); }

/* ---------- dragging from the Add panel onto the canvas (where it lands: canvas.js) ---------- */

function startDrag(e, what) {
	hideSectionPreview();
	state.dragging = what;
	e.dataTransfer.effectAllowed = what.move ? 'move' : 'copy';
	e.dataTransfer.setData('text/plain', 'talea');
}

function endDrag() {
	state.dragging = null;
	const doc = dom.preview && dom.preview.contentDocument;
	if (doc) { showSpot(doc, null); }
}

