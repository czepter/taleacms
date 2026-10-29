/* Talea – page builder, the inspector (the right panel): the content of the selected element, its style for the current device and
 * state, the advanced settings (tag, classes, anchor, custom CSS and attributes, display conditions) and editing a shared class. */
import { T, D, TYPY, STYLE, BP, contentDir, state, dom, emit, el, icon, clone, newId, query, confirmAction, find, elementPath, elementByPath, labelText,
	applyChange, save, selection, offset, duplicateSelected, removeSelected, redrawPanels, setState, askName, elementCollection, setComponents,
	openComponent } from './core.js';
import { refreshPreview, startPlacing, endPlacing, copyDialog, pasteDialog } from './canvas.js';
import { redrawLeft } from './panels.js';
import { structuredField } from './structured.js';

/* ---------- right panel: properties of the selected element, or class editing ---------- */

export function redrawRight() {
	if (state.editedClass !== null) { classesPanel(); return; }
	const n = state.selected && find(state.selected);
	if (!n) {
		dom.right.replaceChildren(el('div', { class: 'bd-panel' }, el('p', { class: 'bd-empty' }, T('Select an element on the canvas or in the structure. Double-click text to edit it right on the page.')),
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
	dom.right.replaceChildren(
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
		if (v.type === 'radio') {
			// a choice: its options, or empty = the default value of the component
			const options = Object.fromEntries([['', T('Default') + (v.default ? ' (' + v.default + ')' : '')], ...(v.options || []).map((o) => [o, o])]);
			wrapper.append(field({ type: 'choice', label: v.label + ' {{' + v.key + '}}', options }, p.content.values[v.key] || '', (h) => applyChange(() => { p.content.values[v.key] = h; }, 'values:' + p.id + ':' + v.key)));
			return;
		}
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
		if (p.content.component && D.urls.component) {
			panel.append(el('div', { class: 'bd-component-card' },
				el('p', {}, T('A shared template: what you change in it applies everywhere it is used.')),
				D.page.id ? el('button', { type: 'button', class: 'bd-btn bd-btn-main', onclick: () => openComponent(p, true) }, icon('edit'), T('Edit here on the page')) : null,
				el('button', { type: 'button', class: 'bd-btn', onclick: () => openComponent(p, false) }, icon('component'), T('Open in the component builder'))));
		}
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
	// a Collection list offers the chosen collection's fields by their labels where a property holds a field key
	const listCollection = p.type === 'collection_list' ? (D.collections || []).find((k) => k.slug === p.content.collection) || null : null;
	properties.forEach(([key, def]) => panel.append(field(listCollection && key in COLLECTION_FIELD_KEYS ? collectionFieldChoice(def, listCollection, COLLECTION_FIELD_KEYS[key], p.content[key]) : def, p.content[key], (h) => {
		if (JSON.stringify(p.content[key]) !== JSON.stringify(h)) { applyChange(() => { p.content[key] = h; emit('content', p, key, h); }, 'content:' + p.id + ':' + key); }
		if (p.type === 'collection_list' && key === 'collection') { redrawRight(); } // the field choices follow the collection
	}, { error: state.errors[state.selectedPath + '.content.' + key], element: p, key: key })));
}

/** Collection list properties that hold a field key, and the field types each offers (null = any). */
const COLLECTION_FIELD_KEYS = { sort_field: null, filter_field: null, period_start_field: ['date', 'datetime'], period_end_field: ['date', 'datetime'] };

/** A choice of the collection's fields by their labels for a field-key property; a stored key the collection lacks stays offered. */
function collectionFieldChoice(def, collection, types, value) {
	const options = { '': '—' };
	(collection.fields || []).filter((f) => !types || types.includes(f.type)).forEach((f) => { options[f.key] = f.label || f.key; });
	if (value && !(value in options)) { options[value] = T('%s (not a field of this collection)').replace('%s', value); }
	return { type: 'choice', label: def.label, default: '', options: options };
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
			inputEl = el('textarea', { rows: def.type === 'code' ? 8 : 5, dir: def.type === 'lines' ? contentDir : null, oninput: (e) => change(e.target.value) });
			inputEl.value = value || '';
			break;
		case 'html': {
			const ta = el('textarea', { 'data-editor': 'small', dir: contentDir, oninput: (e) => change(e.target.value) });
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
		case 'structured': // typed schema.org data: the control is in structured.js
			return structuredField(def, value, change, { page: D.page });
		case 'items':
			return itemField(def, Array.isArray(value) ? value : [], change, options);
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
			inputEl = el('input', { type: 'text', value: value ?? '', dir: def.type === 'text' ? contentDir : null, placeholder: def.type === 'link' ? T('site page, https://…, #anchor, mailto:, tel:') : null,
				list: def.type === 'link' ? 'bd-dl-links' : null, onfocus: def.type === 'link' ? refreshLinks : null, oninput: (e) => change(e.target.value) });
	}
	wrapper.append(inputEl);
	return wrapper;
}

/**
 * Items of a field (form fields, tabs, questions…). A list whose schema has a summary (the form fields) shows each item as one
 * row – "Name · Short text · Required" – that opens into its settings: the basic ones, the options of a choice, then the price
 * estimate and the condition folded until they are set. Open rows stay open while the inspector redraws; the saved values
 * are the same as without rows.
 */
function itemField(def, previous, change, options) {
	const wrapper = el('div', { class: 'bd-field' }, el('span', {}, T(def.label)));
	// works on a copy: every change (adding, removing and moving too) then goes through zmena → history, save, canvas re-render
	const items = clone(Array.isArray(previous) ? previous : []);
	const save = () => change(clone(items));
	const rows = Array.isArray(def.summary);
	const list = state.selected + ':' + ((options && options.key) || def.label);
	const PARTS = ['', 'estimate', 'condition'];
	const openKey = (i, part) => list + '|' + i + '|' + part;
	const setOpen = (i, part, open) => { if (open) { state.openItems[openKey(i, part)] = true; } else { delete state.openItems[openKey(i, part)]; } };
	// moving or removing an item takes its open rows along
	const reorder = (move) => {
		const flags = items.map((_, j) => PARTS.map((part) => !!state.openItems[openKey(j, part)]));
		flags.forEach((_, j) => PARTS.forEach((part) => setOpen(j, part, false)));
		move(flags);
		flags.forEach((f, j) => PARTS.forEach((part, n) => setOpen(j, part, f[n])));
	};
	const summaryText = (item) => def.summary.map((k) => {
		const d = def.fields[k] || {};
		if (d.type === 'boolean') { return item[k] ? T(d.label) : ''; }
		if (d.type === 'choice') { return T((d.options || {})[item[k] ?? d.default] || String(item[k] ?? '')); }
		return String(item[k] ?? '').trim() || T('(no label)');
	}).filter(Boolean).join(' · ');
	items.forEach((item, i) => {
		// a field with the condition „when“ ({siblings: hodnota}) shows only when another field of the item has the given value (options only for a select)
		const visible = (d) => !d.when || Object.entries(d.when).every(([pk, pv]) => item[pk] === pv);
		let label = null;
		const control = ([k, d]) => field(d, item[k], (h) => {
			item[k] = h;
			save();
			if (label && def.summary.includes(k)) { label.textContent = summaryText(item); } // the row follows, the focus stays
			if (Object.values(def.fields).some((other) => other.when && k in other.when)) { redrawRight(); }
		});
		const actions = el('div', { class: 'bd-item-actions bd-actions' },
			el('button', { type: 'button', title: T('Up'), 'aria-label': T('Up'), onclick: () => { if (i > 0) { reorder((f) => f.splice(i - 1, 0, f.splice(i, 1)[0])); items.splice(i - 1, 0, items.splice(i, 1)[0]); change(clone(items)); redrawRight(); } } }, icon('up')),
			el('button', { type: 'button', class: 'danger', title: T("Remove"), 'aria-label': T("Remove"), onclick: () => { reorder((f) => f.splice(i, 1)); items.splice(i, 1); change(clone(items)); redrawRight(); } }, icon('delete')));
		const fields = Object.entries(def.fields).filter(([, d]) => visible(d));
		if (!rows) {
			wrapper.append(el('div', { class: 'bd-item' }, fields.map(control), actions));
			return;
		}
		const group = (name) => fields.filter(([, d]) => (d.group || '') === name);
		// a folded group (estimate, condition): open when one of its values is set or the user opened it
		const fold = (part, title) => {
			const own = group(part);
			if (!own.length) { return null; }
			const box = el('details', { class: 'bd-item-group', open: own.some(([k]) => String(item[k] ?? '') !== '') || !!state.openItems[openKey(i, part)],
				ontoggle: (e) => { if (e.target === box) { setOpen(i, part, box.open); } } }, el('summary', {}, T(title)), own.map(control));
			return box;
		};
		label = el('span', {}, summaryText(item));
		const choices = group('options');
		const row = el('details', { class: 'bd-item bd-item-row', open: !!state.openItems[openKey(i, '')], ontoggle: (e) => { if (e.target === row) { setOpen(i, '', row.open); } } },
			el('summary', {}, label), group('').map(control),
			choices.length ? el('div', { class: 'bd-item-group', role: 'group', 'aria-label': T('Options') }, choices.map(control)) : null,
			fold('estimate', 'Price estimate'), fold('condition', 'Condition'), actions);
		wrapper.append(row);
	});
	wrapper.append(el('button', { type: 'button', class: 'bd-btn', onclick: () => {
		const newVersion = {};
		Object.entries(def.fields).forEach(([k, d]) => { newVersion[k] = d.default ?? ''; });
		items.push(newVersion);
		if (rows) { setOpen(items.length - 1, '', true); } // the new row opens to be filled in
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
export const datalists = el('div', { hidden: true }, Object.entries(HINTS).map(([type, values]) => el('datalist', { id: 'bd-dl-' + type }, values.map((h) => el('option', { value: h })))),
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
	dom.right.replaceChildren(el('div', { class: 'bd-head-element' }, el('strong', {}, T('Class') + ' .' + name),
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
