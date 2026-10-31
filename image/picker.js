/* Talea - the image picker: the dialog "Choose an image" the editors open from the text editor, image and file fields, collection
 * galleries and the page builder (docs/specs/media-library.md, "Picker in the editor"). No libraries, no build step.
 *
 *   window.taleaPickImage(callback, multiple, withAttachments, fileField)
 *     callback          gets the chosen file { id, name, url, thumbnail, width, height, file, extension, … }, or a list of them (multiple)
 *     multiple          several photos in the order they were clicked (a photo gallery), at least 2
 *     withAttachments   documents are offered too (PDF, audio …); they come back with file: true
 *     fileField         the dialog says "Choose a file"
 *
 * Uploading (scaling photos down, the duplicate answer) is image/editor.js: window.taleaUploadFiles.
 * The listing is admin/media/listing (Admin\Modules\Media::actionListing): the images, 60 per page, and the folder tree.
 */

(function () {
	'use strict';

	var T = window.T || function (s) { return (window.TALEA_TRANSLATIONS || {})[s] || s; };
	var SCRIPT = document.querySelector('script[data-admin-url]');
	if (!SCRIPT) { return; }
	var GALLERY = SCRIPT.getAttribute('data-admin-url') + '/media';
	var CSRF = (document.querySelector('input[name="_csrf"]') || {}).value || '';
	var NEWS_ID = (document.querySelector('form[data-draft] input[name="news_id"]') || {}).value || ''; // public id of the news item ('' = a new one)
	var PAGE = 60; // files per page of the listing

	var el = function (tag, className, text) {
		var node = document.createElement(tag);
		if (className) { node.className = className; }
		if (text !== undefined) { node.textContent = text; }
		return node;
	};
	var baseName = function (url) { return String(url).split('/').pop(); };
	var label = function (o) { return o.name || baseName(o.url); };

	var ui = null; // the dialog and its parts, built on the first opening
	var s = null; // what the opening is about: callback, mode, view, selection

	/* ---------- the dialog ---------- */

	function build() {
		var dialog = el('dialog', 'gallery-window picker');
		dialog.setAttribute('aria-labelledby', 'picker-title');
		ui = { dialog: dialog };

		var head = el('div', 'picker-head');
		ui.title = el('h2', '', ''); ui.title.id = 'picker-title';
		var actions = el('div', 'picker-actions');
		ui.chooser = el('input'); ui.chooser.type = 'file'; ui.chooser.multiple = true; ui.chooser.hidden = true;
		var uploadButton = el('button', 'navigation', T('Upload')); uploadButton.type = 'button'; // real buttons that open the hidden file fields: reachable by keyboard
		uploadButton.addEventListener('click', function () { ui.chooser.click(); });
		ui.camera = el('input'); ui.camera.type = 'file'; ui.camera.accept = 'image/*'; ui.camera.setAttribute('capture', 'environment'); ui.camera.hidden = true;
		var cameraButton = el('button', 'navigation gallery-take-photo', T('Take a photo')); cameraButton.type = 'button'; // on a phone and tablet only (CSS: touch devices)
		cameraButton.addEventListener('click', function () { ui.camera.click(); });
		var close = el('button', 'navigation picker-close', T('Close')); close.type = 'button';
		close.addEventListener('click', function () { dialog.close(); });
		actions.append(uploadButton, ui.chooser, cameraButton, ui.camera, close);
		head.append(ui.title, actions);

		ui.rail = el('nav', 'picker-rail'); ui.rail.setAttribute('aria-label', T('Media'));

		var main = el('div', 'picker-main');
		ui.search = el('input', 'textfield'); ui.search.type = 'search'; ui.search.placeholder = T('Search media…'); ui.search.setAttribute('aria-label', T('Search media'));
		ui.hint = el('p', 'help');
		ui.empty = el('p', 'help'); // Loading…, nothing found
		ui.grid = el('div', 'gallery-grid');
		main.append(ui.search, ui.hint, ui.empty, ui.grid);

		ui.side = el('aside', 'picker-side'); ui.side.setAttribute('aria-label', T('Selected image'));
		ui.nothing = el('p', 'help', T('Select an image to see its details.'));
		ui.detail = el('div', 'picker-detail');
		ui.preview = el('div', 'picker-preview');
		var info = el('dl', 'picker-info');
		ui.file = el('dd'); ui.size = el('dd'); ui.used = el('dd');
		ui.sizeRow = [el('dt', '', T('Dimensions')), ui.size];
		info.append(el('dt', '', T('File')), ui.file, ui.sizeRow[0], ui.sizeRow[1], el('dt', '', T('Used')), ui.used);
		ui.field = el('div', 'picker-field');
		var alt = el('label', '', T('Description (alternative text)')); alt.htmlFor = 'picker-alt';
		ui.alt = el('input', 'textfield'); ui.alt.type = 'text'; ui.alt.id = 'picker-alt'; ui.alt.maxLength = 150; ui.alt.placeholder = T('What is in the image?');
		ui.altHelp = el('span', 'help');
		ui.field.append(alt, ui.alt, ui.altHelp);
		ui.detail.append(ui.preview, info, ui.field);
		ui.side.append(ui.nothing, ui.detail);

		var body = el('div', 'picker-body');
		body.append(ui.rail, main, ui.side);

		var foot = el('div', 'picker-foot');
		ui.where = el('span', 'help'); ui.where.setAttribute('role', 'status');
		var cancel = el('button', 'navigation', T('Cancel')); cancel.type = 'button';
		cancel.addEventListener('click', function () { dialog.close(); });
		ui.insert = el('button', 'btn'); ui.insert.type = 'button';
		foot.append(ui.where, cancel, ui.insert);

		dialog.append(head, body, foot);
		document.body.append(dialog);

		ui.insert.addEventListener('click', insert);
		var waiting = null; // search runs only after a short pause in typing, not after every letter
		ui.search.addEventListener('input', function () { clearTimeout(waiting); waiting = setTimeout(load, 300); });
		ui.alt.addEventListener('input', showAlt);
		ui.alt.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); insert(); } });
		[ui.chooser, ui.camera].forEach(function (input) {
			input.addEventListener('change', function () { send(input.files); input.value = ''; });
		});
		// files dropped on the dialog go into the selected folder
		dialog.addEventListener('dragover', function (e) { e.preventDefault(); dialog.classList.add('picker-drop'); });
		dialog.addEventListener('dragleave', function (e) { if (e.target === dialog || !dialog.contains(e.relatedTarget)) { dialog.classList.remove('picker-drop'); } });
		dialog.addEventListener('drop', function (e) {
			e.preventDefault();
			dialog.classList.remove('picker-drop');
			if (e.dataTransfer.files.length) { send(e.dataTransfer.files); }
		});
	}

	/* ---------- the left menu ---------- */

	function railLink(text, view, depth, count) {
		var b = el('button', 'picker-link'); b.type = 'button';
		if (depth) { b.style.paddingInlineStart = (10 + 14 * depth) + 'px'; }
		b.append(el('span', 'picker-link-name', text));
		if (count !== undefined) { b.append(el('span', 'picker-link-count', String(count))); }
		if (s.view === view) { b.setAttribute('aria-current', 'true'); }
		b.addEventListener('click', function () { s.view = view; load(); });
		ui.rail.append(b);
	}

	function drawRail() {
		ui.rail.textContent = '';
		railLink(T('All media'), '');
		railLink(T('Recently uploaded'), 'recent');
		if (NEWS_ID) { railLink(T('In this text'), 'article'); }
		if (s.folders.length) {
			ui.rail.append(el('p', 'picker-heading', T('Folders')));
			s.folders.forEach(function (f) { railLink(f.name, f.id, f.depth, f.count); });
		}
	}

	// "Weddings / Castle" – where the files shown now are
	function location() {
		if (s.view === '') { return T('All media'); }
		if (s.view === 'recent') { return T('Recently uploaded'); }
		if (s.view === 'article') { return T('In this text'); }
		var names = [];
		for (var id = s.view; id; ) {
			var f = s.folders.filter(function (x) { return x.id === id; })[0];
			if (!f) { break; }
			names.unshift(f.name);
			id = f.parent;
		}
		return names.join(' / ');
	}

	/* ---------- the files ---------- */

	var chosenAt = function (o) { return s.chosen.map(function (c) { return c.id; }).indexOf(o.id); };

	function tile(o, upward) {
		if (o.file && !s.withFiles) { return; } // main image, logo, gallery: images only
		var b = el('button', 'gallery-item'); b.type = 'button';
		if (o.file) {
			var badge = el('span', 'gallery-file'); badge.append(el('span', '', o.extension));
			b.append(badge);
		} else {
			var img = el('img'); img.alt = ''; img.loading = 'lazy'; img.src = o.thumbnail;
			b.append(img);
		}
		b.append(el('span', o.name ? '' : 'picker-unnamed', label(o)));
		var on = chosenAt(o) !== -1;
		b.classList.toggle('selected', on);
		b.setAttribute('aria-pressed', on ? 'true' : 'false');
		b.addEventListener('click', function () { choose(o); });
		b.addEventListener('dblclick', function () { if (!s.multiple) { choose(o); insert(); } });
		b.addEventListener('keydown', function (e) { if (e.key === 'Enter' && !s.multiple) { e.preventDefault(); choose(o); insert(); } });
		if (upward) { ui.grid.prepend(b); } else { ui.grid.append(b); }
	}

	// a click selects (several: toggles, in the order of the clicks); the side column shows the one image
	function choose(o) {
		var i = chosenAt(o);
		if (s.multiple) {
			if (i === -1) { s.chosen.push(o); } else { s.chosen.splice(i, 1); }
		} else {
			s.chosen = [o];
		}
		mark();
	}

	// the selected state of the tiles, the side column and the Insert button follow s.chosen
	function mark() {
		var ids = s.chosen.map(function (c) { return c.id; });
		s.tiles.forEach(function (t) {
			var on = ids.indexOf(t.o.id) !== -1;
			t.b.classList.toggle('selected', on);
			t.b.setAttribute('aria-pressed', on ? 'true' : 'false');
		});
		var one = s.multiple ? null : s.chosen[0] || null;
		ui.nothing.hidden = !!one;
		ui.detail.hidden = !one;
		if (one) {
			ui.preview.textContent = '';
			if (one.file) {
				ui.preview.append(el('span', 'gallery-file')); ui.preview.firstChild.append(el('span', '', one.extension));
			} else {
				var img = el('img'); img.alt = ''; img.src = one.thumbnail || one.url;
				ui.preview.append(img);
			}
			ui.file.textContent = baseName(one.url);
			ui.sizeRow.forEach(function (n) { n.hidden = !!one.file; });
			ui.size.textContent = one.width + ' × ' + one.height + ' px';
			ui.used.textContent = one.used === undefined ? '–' : one.used === 0 ? T('Not used yet') : one.used === 1 ? T('1 place') : T('%d places').replace('%d', one.used);
			ui.field.hidden = !!one.file;
			if (s.shown !== one.id) { ui.alt.value = one.name; s.shown = one.id; } // what was typed stays while the listing reloads
			showAlt();
		}
		var n = s.chosen.length;
		if (s.multiple) {
			ui.insert.textContent = n < 2 ? T('Select at least 2 photos') : T('Insert gallery (') + n + ')';
		} else {
			ui.insert.textContent = s.fileField || (one && one.file) ? T('Insert file') : T('Insert image');
		}
		ui.insert.disabled = s.multiple ? n < 2 : n === 0;
	}

	// a description of a file the person may not edit is used for this insertion only; Media keeps its own
	function showAlt() {
		var empty = ui.alt.value.trim() === '';
		var mine = !s.chosen[0] || s.chosen[0].editable !== false;
		ui.altHelp.textContent = !mine ? T('This description is used only here; Media keeps its own.')
			: empty ? T('This image has no description yet. Add one – it is saved in Media for every place that uses it.') : T('Saved in Media for every place that uses it.');
		ui.altHelp.classList.toggle('picker-warning', mine && empty);
	}

	function insert() {
		if (ui.insert.disabled) { return; }
		var o = s.chosen[0], callback = s.callback;
		if (s.multiple) { ui.dialog.close(); callback(s.chosen.slice()); return; }
		var typed = ui.alt.value.trim();
		var done = function (file) { ui.dialog.close(); callback(file); };
		if (o.file || typed === '' || typed === o.name) { return done(o); }
		var used = Object.assign({}, o, { name: typed }); // the description this insertion uses, whether Media keeps it or not
		if (o.editable === false) { return done(used); } // not the person's file: nothing is posted
		// the description goes to Media first, so every place that uses the image has it; a failure never keeps the image from being inserted
		var data = new FormData();
		data.append('_csrf', CSRF); data.append('media_id', o.id); data.append('name', typed);
		fetch(GALLERY + '/save_caption', { method: 'POST', body: data, credentials: 'same-origin' })
			.then(function (r) { return r.json(); })
			.then(function (j) { if (j.ok) { o.name = typed; } })
			.catch(function () {})
			.then(function () { done(used); });
	}

	function more(query, pageNumber, loaded) {
		if (loaded < PAGE) { return; }
		var b = el('button', 'navigation media-more', T('Load more')); b.type = 'button';
		b.addEventListener('click', function () {
			b.disabled = true;
			fetch(GALLERY + '/listing?' + query + '&page=' + pageNumber, { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (j) {
				b.remove();
				j.images.forEach(function (o) { add(o, false); });
				more(query, pageNumber + 1, j.images.length);
			});
		});
		ui.grid.append(b);
	}

	function add(o, upward) {
		var before = ui.grid.children.length;
		tile(o, upward);
		if (ui.grid.children.length > before) { s.tiles.push({ o: o, b: upward ? ui.grid.firstChild : ui.grid.lastChild }); }
	}

	// the listing of the current view and search; the folders (with their counts) come with it
	function load() {
		var query = s.view === 'recent' ? 'recent=1' : s.view === 'article' ? 'article=' + encodeURIComponent(NEWS_ID) : s.view !== '' ? 'folder=' + encodeURIComponent(s.view) : '';
		var search = ui.search.value.trim();
		if (search !== '') { query += (query ? '&' : '') + 'search=' + encodeURIComponent(search); }
		ui.empty.textContent = T('Loading…'); ui.empty.hidden = false;
		var ticket = ++s.ticket; // an answer that comes after a newer request is dropped
		return fetch(GALLERY + '/listing?' + query, { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (j) {
			if (ticket !== s.ticket) { return; }
			s.folders = j.folders;
			if (s.view !== '' && s.view !== 'recent' && s.view !== 'article' && !j.folders.some(function (f) { return f.id === s.view; })) { s.view = ''; } // the folder is gone
			drawRail();
			ui.where.textContent = location();
			ui.grid.textContent = '';
			ui.empty.textContent = search !== '' ? T('Nothing matches your search.') : T('No images here yet.');
			ui.empty.hidden = j.images.length > 0;
			s.tiles = [];
			// a chosen file that this page lists is replaced by the listed one: it knows where the file is used
			s.chosen = s.chosen.map(function (c) { return j.images.filter(function (o) { return o.id === c.id; })[0] || c; });
			j.images.forEach(function (o) { add(o, false); });
			more(query, 2, j.images.length);
			mark();
		});
	}

	/* ---------- uploading ---------- */

	// into the open folder; a file that is already in Media is selected instead of added again
	function send(files) {
		var folder = s.folders.some(function (f) { return f.id === s.view; }) ? s.view : '';
		window.taleaUploadFiles(files, folder).then(function (items) {
			if (!items.length) { return; }
			var duplicate = items.some(function (o) { return o.duplicate; });
			if (s.multiple) {
				items.forEach(function (o) { if (chosenAt(o) === -1) { s.chosen.push(o); } });
			} else {
				s.chosen = [items[0]];
			}
			load().then(function () {
				mark();
				if (duplicate) { ui.where.textContent = T('Already in Media – the existing file is selected.'); }
			});
		});
	}

	/* ---------- opening ---------- */

	window.taleaPickImage = function (callback, multiple, withAttachments, fileField) {
		if (!ui) { build(); }
		s = {
			callback: callback, multiple: !!multiple, withFiles: !!withAttachments && !multiple, fileField: !!fileField,
			view: '', folders: [], chosen: [], tiles: [], ticket: 0, shown: null,
		};
		ui.title.textContent = s.fileField ? T('Choose a file') : s.multiple ? T('Choose images') : T('Choose an image');
		ui.hint.textContent = s.multiple ? T('Click photos in the order they should appear. You can also drop files here.') : '';
		ui.hint.hidden = !s.multiple;
		ui.side.hidden = s.multiple; // photos of a gallery are described in the gallery, not here
		ui.search.value = '';
		mark();
		ui.dialog.showModal();
		load();
	};
})();
