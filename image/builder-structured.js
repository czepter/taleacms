/* Talea – the structured data control of the page builder (HF-11). Loaded after builder.js, which calls window.taleaStructuredField()
 * for a field of the type "structured". The vocabulary comes in the builder data (Builder\StructuredData::forEditor), never from the network.
 * The value is {type, fields} like the server stores it; the preview mirrors Builder\StructuredData::render (without absolute addresses
 * and without the links to the company and website nodes, which the server adds). */
(function () {
	'use strict';

	const T = window.T || ((s) => s);
	const dataElement = document.getElementById('builder-data');
	if (!dataElement) { return; }
	const D = JSON.parse(dataElement.textContent);
	const VOCABULARY = D.structured || {};
	let counter = 0;

	function el(tag, attributes, ...children) {
		const e = document.createElement(tag);
		for (const [k, v] of Object.entries(attributes || {})) {
			if (v === null || v === undefined || v === false) { continue; }
			if (k.startsWith('on')) { e.addEventListener(k.slice(2), v); } else { e.setAttribute(k, v === true ? '' : v); }
		}
		for (const c of children.flat()) { if (c !== null && c !== undefined && c !== false) { e.append(c instanceof Node ? c : String(c)); } }
		return e;
	}
	const clone = (o) => JSON.parse(JSON.stringify(o));
	const empty = (v) => v === undefined || v === null || v === '' || (Array.isArray(v) && !v.length);

	/** The node without empty values, fields in the order of the vocabulary (what the server would keep). */
	function prune(node) {
		const definition = (VOCABULARY[node.type] || {}).properties || {};
		const fields = {};
		for (const [name, p] of Object.entries(definition)) {
			let v = (node.fields || {})[name];
			if (p.multiple) { v = (Array.isArray(v) ? v : []).map((x) => (p.type === 'thing' ? prune(x) : x)).filter((x) => (p.type === 'thing' ? Object.keys(x.fields).length : !empty(x))); } else if (p.type === 'thing' && v) { v = prune(v); if (!Object.keys(v.fields).length) { v = undefined; } }
			if (!empty(v)) { fields[name] = v; }
		}
		return { type: node.type, fields };
	}

	/** JSON-LD of a pruned node (the server converts site paths into addresses and links the site's own nodes). */
	function jsonld(node) {
		const definition = (VOCABULARY[node.type] || {}).properties || {};
		const out = { '@type': node.type };
		for (const [name, v] of Object.entries(node.fields)) {
			const p = definition[name];
			const one = (x) => (p.type === 'image' ? { '@type': 'ImageObject', url: x } : p.type === 'enum' ? 'https://schema.org/' + x : p.type === 'thing' ? jsonld(x) : x);
			out[name] = p.multiple ? v.map(one) : one(v);
		}
		return out;
	}

	function control(def, value, change, options) {
		const page = (options && options.page) || {};
		const node = value && VOCABULARY[value.type] ? clone(value) : { type: '', fields: {} };
		node.fields = node.fields || {};
		const root = el('div', { class: 'bd-field bd-sd' }, el('span', {}, T(def.label || 'Structured data')));
		const form = el('div', { class: 'bd-sd-form' });
		const checklist = el('ul', { class: 'bd-sd-check', 'aria-live': 'polite' });
		const preview = el('pre', { class: 'bd-sd-preview', tabindex: '0', 'aria-label': T('JSON-LD preview') });
		const status = el('p', { class: 'bd-sd-hint' });

		const refresh = () => {
			const clean = node.type ? prune(node) : null;
			preview.textContent = clean ? JSON.stringify(Object.assign({ '@context': 'https://schema.org' }, jsonld(clean)), null, 2) : '';
			checklist.replaceChildren();
			if (clean) {
				for (const [name, p] of Object.entries(VOCABULARY[clean.type].properties)) {
					if ((p.required || p.recommended) && empty(clean.fields[name])) {
						checklist.append(el('li', { class: p.required ? 'bd-sd-required' : 'bd-sd-recommended' }, (p.required ? T('Required') : T('Recommended')) + ': ' + p.label));
					}
				}
				if (!checklist.children.length) { checklist.append(el('li', { class: 'bd-sd-ok' }, T('Nothing missing'))); }
			}
		};
		const emit = () => { refresh(); change(node.type ? prune(node) : { type: '', fields: {} }); };

		/** A scalar control by the property type; set(value) stores it (undefined = removes). */
		function scalar(p, v, set, id, label) {
			const text = (type, extra) => el('input', Object.assign({ type, id, value: v ?? '', oninput: (e) => set(e.target.value === '' ? undefined : e.target.value) }, extra));
			switch (p.type) {
				case 'long_text': { const t = el('textarea', { id, rows: 3, oninput: (e) => set(e.target.value || undefined) }); t.value = v || ''; return t; }
				case 'number': return text('number', { step: 'any', oninput: (e) => set(e.target.value === '' ? undefined : Number(e.target.value)) });
				case 'integer': return text('number', { step: '1', oninput: (e) => set(e.target.value === '' ? undefined : parseInt(e.target.value, 10)) });
				case 'date': return text('date');
				case 'datetime': return el('input', { type: 'datetime-local', id, value: typeof v === 'string' ? v.replace(' ', 'T').slice(0, 16) : '', oninput: (e) => set(e.target.value || undefined) });
				case 'boolean': return el('input', { type: 'checkbox', id, checked: v === true, 'aria-label': label, onchange: (e) => set(e.target.checked) });
				case 'enum': return el('select', { id, onchange: (e) => set(e.target.value || undefined) }, el('option', { value: '' }, '—'), p.options.map((o) => el('option', { value: o, selected: o === v }, o)));
				case 'image': {
					const input = text('text', { placeholder: 'media/…' });
					return el('span', { class: 'bd-field-row' }, input, el('button', { type: 'button', class: 'bd-btn', onclick: () => window.taleaPickImage && window.taleaPickImage((o) => { input.value = o.url; set(o.url); }) }, T('Media')));
				}
				case 'url': return text('text', { placeholder: 'https://…', inputmode: 'url' });
				case 'duration': return text('text', { placeholder: 'PT1H30M' });
				default: return text('text');
			}
		}

		/** The form of one thing ({type, fields} object kept by the caller); onChange runs after every edit. */
		function thing(target, depth, onChange) {
			const definition = VOCABULARY[target.type].properties;
			target.fields = target.fields || {};
			return el('div', { class: 'bd-sd-props' }, Object.entries(definition).map(([name, p]) => property(target, name, p, depth, onChange)));
		}

		function property(target, name, p, depth, onChange) {
			const id = 'bd-sd-' + (++counter);
			const label = p.label + (p.required ? ' *' : '');
			const head = el('label', { for: id, title: p.hint || null }, label, p.recommended ? el('em', {}, ' · ' + T('recommended')) : null);
			const box = el('div', { class: 'bd-sd-prop' + (p.required ? ' bd-sd-prop-required' : '') });

			if (p.type === 'thing') {
				const make = (get, set, ordinal) => {
					const current = get();
					const kids = el('div', { class: 'bd-sd-nested' });
					const draw = () => {
						kids.replaceChildren();
						const sub = get();
						if (p.of.length > 1) {
							kids.append(el('select', { 'aria-label': T('Type'), onchange: (e) => { set({ type: e.target.value, fields: {} }); onChange(); draw(); } }, p.of.map((t) => el('option', { value: t, selected: t === sub.type }, (VOCABULARY[t] || { label: t }).label))));
						}
						kids.append(thing(sub, depth + 1, onChange));
					};
					draw();
					const open = current && Object.keys(current.fields || {}).length > 0;
					return el('details', { class: 'bd-sd-details', open: open || p.required || ordinal > 0 }, el('summary', {}, p.label + (ordinal ? ' ' + ordinal : '') + (p.required ? ' *' : '')), kids);
				};
				if (p.multiple) {
					const list = el('div', { class: 'bd-sd-list' });
					const draw = () => {
						list.replaceChildren();
						const items = (target.fields[name] = Array.isArray(target.fields[name]) ? target.fields[name] : []);
						items.forEach((item, i) => list.append(el('div', { class: 'bd-sd-row' }, make(() => items[i], (x) => { items[i] = x; }, i + 1),
							el('button', { type: 'button', class: 'bd-btn', 'aria-label': T('Remove') + ' ' + p.label + ' ' + (i + 1), onclick: () => { items.splice(i, 1); onChange(); draw(); } }, '×'))));
					};
					draw();
					box.append(el('span', { class: 'bd-sd-title' }, label), list, el('button', { type: 'button', class: 'bd-btn', onclick: () => { (target.fields[name] = target.fields[name] || []).push({ type: p.of[0], fields: {} }); draw(); } }, T('Add') + ' ' + p.label));
				} else {
					target.fields[name] = target.fields[name] && target.fields[name].type ? target.fields[name] : { type: p.of[0], fields: {} };
					box.append(make(() => target.fields[name], (x) => { target.fields[name] = x; }, 0));
				}
				return box;
			}

			if (p.multiple) {
				const list = el('div', { class: 'bd-sd-list' });
				const draw = () => {
					list.replaceChildren();
					const items = (target.fields[name] = Array.isArray(target.fields[name]) ? target.fields[name] : []);
					items.forEach((item, i) => list.append(el('div', { class: 'bd-sd-row' }, scalar(p, item, (v) => { items[i] = v; onChange(); }, id + '-' + i, p.label),
						el('button', { type: 'button', class: 'bd-btn', 'aria-label': T('Remove') + ' ' + p.label + ' ' + (i + 1), onclick: () => { items.splice(i, 1); onChange(); draw(); } }, '×'))));
				};
				draw();
				box.append(el('span', { class: 'bd-sd-title' }, label), list, el('button', { type: 'button', class: 'bd-btn', onclick: () => { target.fields[name].push(''); draw(); } }, T('Add') + ' ' + p.label));
				return box;
			}

			const input = scalar(p, target.fields[name], (v) => { if (v === undefined) { delete target.fields[name]; } else { target.fields[name] = v; } onChange(); }, id, p.label);
			box.append(head, input);
			if (p.type === 'boolean') { box.classList.add('bd-sd-tick'); }

			return box;
		}

		const draw = () => {
			form.replaceChildren();
			if (node.type) { form.append(thing(node, 0, emit)); }
			refresh();
		};

		// the type picker: a search field filters the list of types
		const search = el('input', { type: 'search', placeholder: T('Search a type (Product, Event…)'), 'aria-label': T('Search a type') });
		const select = el('select', { 'aria-label': T('Type') });
		const typeOptions = () => {
			const q = search.value.trim().toLowerCase();
			const current = node.type;
			select.replaceChildren(el('option', { value: '' }, T('Choose a type…')),
				...Object.entries(VOCABULARY).filter(([k, t]) => k === current || !q || (k + ' ' + t.label).toLowerCase().includes(q)).map(([k, t]) => el('option', { value: k, selected: k === current }, t.label)));
		};
		search.addEventListener('input', typeOptions);
		select.addEventListener('change', () => {
			const previous = node.fields;
			node.type = select.value;
			// what the new type also has stays; the rest goes
			node.fields = {};
			if (node.type) { for (const k of Object.keys(VOCABULARY[node.type].properties)) { if (previous[k] !== undefined) { node.fields[k] = previous[k]; } } }
			draw();
			emit();
		});
		typeOptions();

		// fills the title, the description and the image of the edited page into the properties that are still empty
		const fill = el('button', { type: 'button', class: 'bd-btn', onclick: () => {
			if (!node.type) { status.textContent = T('Choose a type first.'); return; }
			const properties = VOCABULARY[node.type].properties;
			const base = (D.urls && D.urls.admin ? D.urls.admin : '').replace(/admin\.php.*$/, '');
			const image = !page.image ? '' : /^(https?:)?\/\//.test(page.image) || page.image.startsWith('/') ? page.image : base + page.image;
			let count = 0;
			for (const [name, v] of [['name', page.title], ['headline', page.title], ['description', page.description], ['image', image]]) {
				if (v && properties[name] && !properties[name].multiple && empty(node.fields[name])) { node.fields[name] = v; count++; }
			}
			status.textContent = count ? T('Filled %s properties from the page.').replace('%s', String(count)) : T('Nothing to fill – the properties are filled or the page has no such data.');
			draw();
			emit();
		} }, T('Fill from page'));

		root.append(el('div', { class: 'bd-sd-picker' }, search, select), form, el('div', { class: 'bd-sd-tools' }, fill), status,
			el('span', { class: 'bd-sd-title' }, T('Still missing')), checklist, el('span', { class: 'bd-sd-title' }, T('JSON-LD preview')), preview);
		draw();

		return root;
	}

	window.taleaStructuredField = control;
})();
