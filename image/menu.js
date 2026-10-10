/* Kaleta – menu editor ("Vzhled → Menu", Appearance → Menu). Items: page, custom link, news, group; one submenu level under an item,
 * and inside a submenu a group may have items of its own (a column of the mega menu). Each item can have an icon and a short description.
 * Order by dragging or with arrows (keyboard too); the right arrow moves an item into the submenu of the item above it (inside a submenu
 * only into a group above it).
 * The state is an array of items; on form submit it goes as JSON into a hidden field – the server cleans it (Core\Menu::sanitize).
 */
// the script is in the page content, i.e. before admin.js with the translation dictionary (window.T) – it starts only after all scripts load
document.addEventListener('DOMContentLoaded', function () {
	'use strict';

	const T = window.T || ((s) => s);
	const formEl = document.querySelector('[data-menu]');
	if (!formEl) { return; }
	const data = JSON.parse(formEl.querySelector('[data-menu-data]').textContent);
	const list = formEl.querySelector('[data-menu-list]');
	const empty = formEl.querySelector('[data-menu-empty]');
	const pages = Object.fromEntries(data.pages.map((s) => [s.page_id, s]));
	const icons = data.icons || { '': '' };
	const normalize = (p) => Object.assign({ children: [] }, p, { children: (p.children || []).map(normalize) });
	const items = data.items.map(normalize);
	const NAMES = { page: T('Page'), link: T('Link'), news: T("News"), group: T('Group') };
	const MAX_DEPTH = 2; // top level, submenu, items of a group inside the submenu
	let dragged = null;

	function el(tag, attributes, ...children) {
		const e = document.createElement(tag);
		for (const [k, v] of Object.entries(attributes || {})) {
			if (v === null || v === undefined || v === false) { continue; }
			if (k.startsWith('on')) { e.addEventListener(k.slice(2), v); } else { e.setAttribute(k, v === true ? '' : v); }
		}
		children.flat().forEach((d) => { if (d !== null && d !== undefined && d !== false) { e.append(d instanceof Node ? d : String(d)); } });
		return e;
	}

	/** The array the item lies in, and its index: path [i] = top level, [i, j] = submenu of item i, [i, j, k] = items of group j. */
	const field = (path) => path.slice(0, -1).reduce((p, i) => p[i].children, items);
	const item = (path) => field(path)[path[path.length - 1]];
	const depthOf = (path) => path.length - 1;
	/** Whether the item can move into the item above it: at the top level under anything, inside a submenu only under a group. */
	const canIndent = (p, path) => {
		const i = path[path.length - 1];
		if (i === 0 || p.children.length || depthOf(path) >= MAX_DEPTH) { return false; }
		return depthOf(path) === 0 || field(path)[i - 1].type === 'group';
	};

	function move(path, direction) {
		const p = field(path);
		const i = path[path.length - 1];
		const j = i + direction;
		if (j < 0 || j >= p.length) { return; }
		[p[i], p[j]] = [p[j], p[i]];
		render([...path.slice(0, -1), j]);
	}
	function indent(path) {
		const i = path[path.length - 1];
		const arr = field(path);
		if (!canIndent(arr[i], path)) { return; }
		const [p] = arr.splice(i, 1);
		arr[i - 1].children.push(p);
		render([...path.slice(0, -1), i - 1, arr[i - 1].children.length - 1]);
	}
	function outdent(path) {
		if (path.length < 2) { return; }
		const parentPath = path.slice(0, -1);
		const [p] = field(path).splice(path[path.length - 1], 1);
		const target = field(parentPath);
		target.splice(parentPath[parentPath.length - 1] + 1, 0, p);
		render([...parentPath.slice(0, -1), parentPath[parentPath.length - 1] + 1]);
	}
	function remove(path) {
		const arr = field(path);
		const i = path[path.length - 1];
		const [p] = arr.splice(i, 1);
		// the submenu of a removed item moves one level up, it does not disappear
		arr.splice(i, 0, ...p.children);
		render(null);
	}

	function row(p, path) {
		const depth = depthOf(path);
		const pageName = p.type === 'page' ? (pages[p.page_id] || { title: T('deleted page') }).title : '';
		const btn = (text, description, fn, disabled) => el('button', { type: 'button', class: 'menu-btn', title: description, 'aria-label': description, disabled: disabled, onclick: () => fn(path) }, text);
		const text = el('input', { class: 'textfield', type: 'text', maxlength: 80, value: p.text || '', 'aria-label': T('Menu text'),
			placeholder: p.type === 'page' ? pageName : p.type === 'news' ? T("News") : T('Menu text'),
			oninput: (e) => { p.text = e.target.value; } });
		const icon = el('select', { class: 'menu-icon-select', 'aria-label': T('Icon'), title: T('Icon'), onchange: (e) => { p.icon = e.target.value; } },
			Object.entries(icons).map(([k, v]) => el('option', { value: k, selected: k === (p.icon || '') }, v)));
		const description = el('input', { class: 'textfield menu-description-field', type: 'text', maxlength: 120, value: p.description || '', placeholder: T('Description (mega menu)'),
			'aria-label': T('Description (mega menu)'), oninput: (e) => { p.description = e.target.value; } });
		const i = path[path.length - 1];
		const rowEl = el('div', { class: 'menu-row' },
			el('span', { class: 'menu-grip', draggable: 'true', 'aria-hidden': 'true', title: T('Drag to reorder') }, '⠿'),
			el('span', { class: 'badge' }, NAMES[p.type]),
			p.type === 'page' && pages[p.page_id] && pages[p.page_id].hidden ? el('span', { class: 'badge badge-draft', title: T('A hidden page does not appear in the menu on the site.') }, T('hidden')) : null,
			icon,
			text,
			p.type === 'link' ? el('input', { class: 'textfield', type: 'text', maxlength: 500, value: p.url || '', placeholder: 'https://… ' + T('or') + ' /path', 'aria-label': T('Link address'), oninput: (e) => { p.url = e.target.value.trim(); } }) : null,
			p.type === 'link' ? el('label', { class: 'menu-window' }, el('input', { type: 'checkbox', checked: !!p.new_window, onchange: (e) => { p.new_window = e.target.checked; } }), ' ' + T('new window')) : null,
			description,
			el('span', { class: 'menu-actions' },
				btn('↑', T('Move up'), (c) => move(c, -1), i === 0),
				btn('↓', T('Move down'), (c) => move(c, 1), i === field(path).length - 1),
				depth < MAX_DEPTH ? btn('→', T(depth === 0 ? 'Into the submenu of the item above' : 'Into the group above (a column of the mega menu)'), indent, !canIndent(p, path)) : null,
				depth > 0 ? btn('←', T('Out of the submenu, one level up'), outdent) : null,
				btn('✕', T('Remove from menu'), remove)));
		const li = el('li', { class: 'menu-item' }, rowEl);
		li.dataset.path = path.join(',');
		// dragged by the handle (the whole item would prevent selecting text in the fields)
		const handle = rowEl.querySelector('.menu-grip');
		handle.addEventListener('dragstart', (e) => { dragged = path; e.dataTransfer.effectAllowed = 'move'; e.dataTransfer.setData('text/plain', ''); e.dataTransfer.setDragImage(rowEl, 10, 10); li.classList.add('menu-dragging'); });
		handle.addEventListener('dragend', () => { dragged = null; li.classList.remove('menu-dragging'); list.querySelectorAll('.menu-target').forEach((x) => x.classList.remove('menu-target')); });
		rowEl.addEventListener('dragover', (e) => {
			if (!dragged || (item(dragged).children.length && path.length > 1)) { return; } // an item with a submenu stays at the top level
			e.preventDefault();
			rowEl.classList.add('menu-target');
		});
		rowEl.addEventListener('dragleave', () => rowEl.classList.remove('menu-target'));
		rowEl.addEventListener('drop', (e) => {
			e.preventDefault();
			if (!dragged || dragged.join() === path.join()) { return; }
			// inserted before the target item, at its level (the arrays are looked up first – removing the dragged item shifts the indexes)
			const target = item(path);
			const destination = field(path);
			const [p2] = field(dragged).splice(dragged[dragged.length - 1], 1);
			if (path.length > 1) { p2.children = []; }
			destination.splice(destination.indexOf(target), 0, p2);
			dragged = null;
			render(null);
		});
		if (p.children.length) {
			li.append(el('ol', { class: 'menu-submenu' }, p.children.map((d, j) => row(d, [...path, j]))));
		}
		return li;
	}

	function render(focusTarget) {
		list.replaceChildren(...items.map((p, i) => row(p, [i])));
		empty.hidden = items.length > 0;
		if (focusTarget) {
			// after a move with an arrow, focus stays on the moved item (keyboard control)
			const li = list.querySelector('[data-path="' + focusTarget.join(',') + '"]');
			if (li) { li.querySelector('.menu-row input').focus(); }
		}
	}

	formEl.querySelectorAll('[data-menu-add]').forEach((b) => b.addEventListener('click', () => {
		const type = b.dataset.menuAdd;
		const newVersion = { type: type, text: '', children: [] };
		if (type === 'page') {
			const selection = formEl.querySelector('[data-menu-page]');
			if (!selection.value) { return; }
			newVersion.page_id = selection.value;
		}
		if (type === 'link') { newVersion.url = ''; newVersion.new_window = false; }
		items.push(newVersion);
		render([items.length - 1]);
	}));

	/** A notice in a custom dialog (not window.alert – that cannot be styled or translated); on close, focus returns to the invalid field. */
	function notify(text, field) {
		const d = el('dialog', { class: 'confirmation', role: 'alertdialog', 'aria-modal': 'true' },
			el('p', {}, text),
			el('div', {}, el('button', { type: 'button', class: 'btn', onclick: () => d.close() }, T("OK"))));
		d.setAttribute('aria-label', text);
		d.addEventListener('close', () => { d.remove(); field.focus(); });
		document.body.append(d);
		d.showModal();
	}

	formEl.addEventListener('submit', (e) => {
		// the server would silently drop a link without a URL or text and a group without text – better to say so right away
		const invalid = list.querySelectorAll('.menu-item');
		for (const li of invalid) {
			const p = item(li.dataset.path.split(',').map(Number));
			if ((p.type === 'link' && (!p.text || !p.url)) || (p.type === 'group' && !p.text)) {
				e.preventDefault();
				// the text or (for a link) the URL is missing: focus the field that is empty
				const empty = [...li.querySelector('.menu-row').querySelectorAll('input.textfield:not(.menu-description-field)')].find((x) => !x.value.trim()) || li.querySelector('input');
				empty.setAttribute('aria-invalid', 'true');
				empty.addEventListener('input', () => empty.removeAttribute('aria-invalid'), { once: true });
				notify(p.type === 'link' ? T('A custom link needs both text and an address.') : T('A group needs text.'), empty);
				return;
			}
		}
		formEl.querySelector('input[name="items"]').value = JSON.stringify(items);
	});

	render(null);
});
