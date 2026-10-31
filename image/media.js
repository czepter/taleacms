/* Talea - the Media screen (image/media.js): the upload tray, drop to upload and dragging files and folders into folders. No libraries, no build step.
 * It runs where an element has [data-media-screen] (system/views/admin/media/list.php), which also holds the addresses it posts to.
 * Scaling photos down before they are sent is image/editor.js (window.taleaPrepareUpload); texts come from window.T (image/admin.js). */

(function () {
	'use strict';

	var screen = document.querySelector('[data-media-screen]');
	if (!screen) { return; }

	var T = function (s) { return window.T ? window.T(s) : s; };
	var CSRF = (document.querySelector('input[name="_csrf"]') || {}).value || '';
	var fmt = function (text) { var a = Array.prototype.slice.call(arguments, 1); return text.replace(/%[sd]/g, function () { return a.length ? a.shift() : ''; }); };

	/* ---------- upload tray and drop to upload ---------- */

	// The Upload button opens the file chooser, files dragged from the computer onto the screen (or onto a folder) are taken too.
	// Files go one at a time through upload?format=json, scaled down first, and the tray shows what happened to each.

	var tray = null, items = [], active = null, stopped = false;
	var chooser = screen.querySelector('[data-upload-input]');
	var pick = document.querySelector('[data-upload-pick]');
	var overlay = screen.querySelector('[data-drop-overlay]');

	var el = function (tag, className, text) {
		var node = document.createElement(tag);
		if (className) { node.className = className; }
		if (text !== undefined) { node.textContent = text; }
		return node;
	};
	var count = function (state) { return items.filter(function (i) { return i.state === state; }).length; };

	var closeTray = function () {
		stopTray();
		var reload = count('done') > 0;
		tray.node.remove();
		tray = null; items = [];
		if (reload) { window.location.reload(); } // the new files show in the list
	};

	var stopTray = function () {
		stopped = true;
		if (active && active.xhr) { active.xhr.abort(); }
		items.forEach(function (i) { if (i.state === 'waiting') { setRow(i, 'cancelled', T('Cancelled')); } });
	};

	var buildTray = function () {
		var node = el('aside', 'upload-tray');
		node.setAttribute('role', 'status');
		var head = el('div', 'tray-head'), buttons = el('span', 'tray-buttons');
		var title = el('strong');
		var minimise = el('button', 'icon-btn', '–'), close = el('button', 'icon-btn', '×');
		minimise.type = close.type = 'button';
		minimise.setAttribute('aria-label', T('Minimise')); close.setAttribute('aria-label', T('Close'));
		minimise.addEventListener('click', function () { node.classList.toggle('minimised'); });
		close.addEventListener('click', closeTray);
		buttons.append(minimise, close); head.append(title, buttons);
		var bar = el('div', 'tray-progress'), fill = el('span');
		bar.appendChild(fill);
		var list = el('ul', 'tray-list');
		var foot = el('div', 'tray-foot'), cancel = el('button', 'navigation', T('Cancel the rest'));
		cancel.type = 'button';
		cancel.addEventListener('click', stopTray);
		var describe = el('a', 'btn', T('Describe the new images'));
		describe.href = screen.getAttribute('data-describe-url');
		describe.hidden = true;
		foot.append(cancel, describe);
		node.append(head, bar, list, foot);
		document.body.appendChild(node);
		return { node: node, title: title, fill: fill, list: list, cancel: cancel, describe: describe };
	};

	var setRow = function (item, state, text, extra) {
		item.state = state;
		item.row.dataset.state = state;
		item.sub.textContent = text;
		if (extra) { item.sub.append(' · ', extra); }
		item.bar.hidden = state !== 'progress';
		refresh();
	};

	// the title and the overall bar follow what has finished
	var refresh = function () {
		if (!tray) { return; }
		var finished = items.filter(function (i) { return i.state !== 'waiting' && i.state !== 'scaling' && i.state !== 'progress'; }).length;
		var current = items.filter(function (i) { return i.state === 'scaling' || i.state === 'progress'; })[0];
		tray.title.textContent = current ? fmt(T('Uploading %d of %d to %s'), finished + 1, items.length, current.folder.name) : fmt(T('Uploaded %d of %d files to %s'), count('done'), items.length, items[items.length - 1].folder.name);
		tray.fill.style.width = Math.round(100 * (finished + (current && current.fraction || 0)) / items.length) + '%';
		tray.cancel.hidden = !current && count('waiting') === 0;
		tray.describe.hidden = !items.some(function (i) { return i.image; }) || !!current;
	};

	var addRow = function (file, folder) {
		var item = { file: file, folder: folder, state: 'waiting', fraction: 0, image: false };
		item.row = el('li', 'tray-row');
		var text = el('div', 'cell-text');
		item.sub = el('span', 'cell-sub');
		item.bar = el('div', 'bar');
		item.bar.hidden = true;
		item.barFill = el('span');
		item.bar.appendChild(item.barFill);
		text.append(el('span', 'cell-title', file.name), item.sub, item.bar);
		item.row.append(el('span', 'mini', (file.name.split('.').pop() || '').toUpperCase().slice(0, 4)), text, el('span', 'tray-mark'));
		tray.list.appendChild(item.row);
		items.push(item);
		setRow(item, 'waiting', T('Waiting'));

		return item;
	};

	var fail = function (item, reason) { setRow(item, 'failed', reason); };

	var send = function (item, file) {
		return new Promise(function (resolve) {
			var xhr = new XMLHttpRequest(), data = new FormData();
			item.xhr = xhr;
			data.append('_csrf', CSRF);
			data.append('folder_id', item.folder.id);
			data.append('files[]', file);
			xhr.open('POST', screen.getAttribute('data-upload-url'));
			xhr.upload.onprogress = function (e) {
				if (!e.lengthComputable) { return; }
				item.fraction = e.loaded / e.total;
				item.barFill.style.width = Math.round(100 * item.fraction) + '%';
				item.sub.textContent = T('Uploading') + ' ' + Math.round(100 * item.fraction) + '%';
				refresh();
			};
			xhr.onload = function () {
				var j = null;
				try { j = JSON.parse(xhr.responseText); } catch (e) { j = null; }
				if (j && j.duplicates && j.duplicates.length) {
					var d = j.duplicates[0], link = el('a', '', T('Open it'));
					link.href = screen.getAttribute('data-item-url') + (screen.getAttribute('data-item-url').indexOf('?') < 0 ? '?' : '&') + 'item=' + encodeURIComponent(d.id);
					setRow(item, 'skipped', fmt(T('Already in Media as %s'), d.name), link);
				} else if (j && j.images && j.images.length) {
					item.image = !j.images[0].file;
					setRow(item, 'done', T('Uploaded'));
				} else {
					var message = j && j.errors && j.errors[0] ? j.errors[0].replace(file.name + ': ', '') : T('Upload failed. Check your connection and try again.');
					fail(item, message);
				}
				resolve();
			};
			xhr.onerror = function () { fail(item, T('Upload failed. Check your connection and try again.')); resolve(); };
			xhr.onabort = function () { setRow(item, 'cancelled', T('Cancelled')); resolve(); };
			xhr.send(data);
		});
	};

	var next = function () {
		var item = items.filter(function (i) { return i.state === 'waiting'; })[0];
		if (!item || stopped) { active = null; refresh(); return; }
		active = item;
		setRow(item, 'scaling', T('Scaling down…'));
		window.taleaPrepareUpload(item.file).then(function (prepared) {
			if (stopped) { return setRow(item, 'cancelled', T('Cancelled')); }
			if (prepared.error) { return fail(item, prepared.error); }
			setRow(item, 'progress', T('Uploading') + ' 0%');
			return send(item, prepared.file);
		}).then(next);
	};

	var enqueue = function (files, folder) {
		files = Array.prototype.slice.call(files);
		if (!files.length) { return; }
		if (!tray) { tray = buildTray(); }
		tray.node.classList.remove('minimised');
		stopped = false;
		files.forEach(function (file) { addRow(file, folder); });
		if (!active) { next(); }
	};

	window.addEventListener('beforeunload', function (e) { if (active) { e.preventDefault(); } });

	var here = function () { return { id: screen.getAttribute('data-folder'), name: screen.getAttribute('data-folder-name') }; };
	if (pick && chooser) {
		pick.addEventListener('click', function (e) { e.preventDefault(); chooser.click(); }); // cancels the no-JavaScript popover with its form
		chooser.addEventListener('change', function () { enqueue(chooser.files, here()); chooser.value = ''; });
	}

	// drop to upload: the overlay names the folder; over a folder (card, tree, path) that one is outlined instead
	var depth = 0, over = null;
	var withFiles = function (e) { return e.dataTransfer && Array.prototype.indexOf.call(e.dataTransfer.types || [], 'Files') >= 0; };
	var targetOf = function (e) { return e.target.closest ? e.target.closest('[data-media-drop]') : null; };
	var droppedFiles = function (transfer) {
		var list = [];
		Array.prototype.forEach.call(transfer.items && transfer.items.length ? transfer.items : transfer.files, function (entry) {
			var file = entry.getAsFile ? entry.getAsFile() : entry;
			var folder = entry.webkitGetAsEntry ? entry.webkitGetAsEntry() : null;
			if (file && !(folder && folder.isDirectory) && (!entry.kind || entry.kind === 'file')) { list.push(file); }
		});
		return list;
	};
	var calm = function () {
		depth = 0;
		if (over) { over.classList.remove('drop-over'); over = null; }
		overlay.hidden = true;
	};
	if (overlay) {
		var pattern = overlay.querySelector('[data-drop-title]');
		var says = function (name) { pattern.textContent = fmt(pattern.getAttribute('data-pattern'), name); };
		document.addEventListener('dragenter', function (e) { if (withFiles(e)) { depth++; } });
		document.addEventListener('dragleave', function (e) { if (withFiles(e) && --depth <= 0) { calm(); } });
		document.addEventListener('dragover', function (e) {
			if (!withFiles(e)) { return; }
			e.preventDefault();
			e.dataTransfer.dropEffect = 'copy';
			var target = targetOf(e);
			if (target !== over) {
				if (over) { over.classList.remove('drop-over'); }
				if (target) { target.classList.add('drop-over'); }
				over = target;
			}
			overlay.hidden = !!target;
			says(here().name);
		});
		document.addEventListener('drop', function (e) {
			if (!withFiles(e)) { return; }
			e.preventDefault();
			var target = targetOf(e);
			var folder = target ? { id: target.getAttribute('data-id'), name: target.getAttribute('data-name') } : here();
			calm();
			enqueue(droppedFiles(e.dataTransfer), folder);
		});
	}

	/* ---------- dragging into folders ---------- */

	// Media: dragging files (the selection, or the one tile when it is not selected) and folder cards onto a folder card, a folder in
	// the left menu or a link of the path moves them (docs/specs/media-library.md). Dropping files from the computer is above.
	var dragging = null, hover = null, label = null;
	var level = screen.getAttribute('data-level');
	var targets = function () { return document.querySelectorAll('[data-media-drop]'); };
	var allowed = function (target) { // not into the folder it is in, not into itself or one of its subfolders
		var id = target.getAttribute('data-id');
		return id !== level && !(dragging.folder && (id === dragging.folder || dragging.below.indexOf(id) >= 0));
	};
	var done = function () {
		dragging = hover = null;
		document.body.classList.remove('media-dragging');
		targets().forEach(function (t) { t.classList.remove('drop-ok', 'drop-over'); });
		if (label) { label.remove(); label = null; }
	};
	var tell = function (text, type) {
		var note = document.createElement('p');
		note.className = 'notice notice-' + type;
		note.setAttribute('role', 'status');
		note.textContent = text;
		var box = screen.querySelector('.media-content');
		box.insertBefore(note, box.firstChild);
	};
	// A file is dragged by its tile or, in the table, by its picture and name; a folder by the picture and name of its card (never by
	// the forms of its menu, whose fields must stay selectable). The card carries the folder's data.
	document.addEventListener('dragstart', function (e) {
		var grip = e.target.closest ? e.target.closest('[data-media-drag]') : null;
		if (!grip || !screen.contains(grip)) { return; }
		var folder = grip.getAttribute('data-media-drag') === 'folder';
		var source = folder ? grip.closest('[data-media-drop]') : grip;
		var id = source.getAttribute('data-id');
		var ids = [id];
		if (!folder) {
			var ticked = Array.prototype.map.call(document.querySelectorAll('input[type="checkbox"][name="selected[]"]:checked'), function (box) { return box.value; });
			if (ticked.indexOf(id) >= 0) { ids = ticked; }
		}
		dragging = { ids: ids, folder: folder ? id : '', below: (source.getAttribute('data-below') || '').split(' ').filter(Boolean) };
		e.dataTransfer.effectAllowed = 'move';
		e.dataTransfer.setData('application/x-talea-media', id); // Firefox starts no drag without data
		setTimeout(function () { // after the browser took the picture of the dragged element
			if (!dragging) { return; }
			document.body.classList.add('media-dragging');
			targets().forEach(function (t) { if (allowed(t)) { t.classList.add('drop-ok'); } });
		}, 0);
	});
	document.addEventListener('dragover', function (e) {
		if (!dragging) { return; }
		var target = e.target.closest ? e.target.closest('[data-media-drop]') : null;
		if (target && !target.classList.contains('drop-ok')) { target = null; }
		if (target) { e.preventDefault(); e.dataTransfer.dropEffect = 'move'; }
		if (target !== hover) {
			if (hover) { hover.classList.remove('drop-over'); }
			if (target) { target.classList.add('drop-over'); }
			hover = target;
		}
		if (!target) { if (label) { label.hidden = true; } return; }
		if (!label) { label = document.createElement('div'); label.className = 'drop-label'; document.body.appendChild(label); }
		label.hidden = false;
		label.textContent = dragging.folder ? T('Move folder here') : dragging.ids.length === 1 ? T('Move 1 file here') : T('Move %d files here').replace('%d', dragging.ids.length);
		label.style.left = (e.clientX + 14) + 'px';
		label.style.top = (e.clientY + 14) + 'px';
	});
	document.addEventListener('drop', function (e) {
		if (!dragging || !hover) { return; }
		e.preventDefault();
		var data = new FormData(), folder = dragging.folder;
		data.append('_csrf', CSRF);
		if (folder) {
			data.append('folder_id', folder);
			data.append('parent', hover.getAttribute('data-id'));
		} else {
			data.append('bulk', 'move');
			data.append('to_folder', hover.getAttribute('data-id'));
			dragging.ids.forEach(function (id) { data.append('selected[]', id); });
		}
		var address = screen.getAttribute(folder ? 'data-folder-move-url' : 'data-move-url');
		done();
		fetch(address, { method: 'POST', body: data, credentials: 'same-origin' })
			.then(function (r) { return r.json(); })
			.then(function (j) { if (j.ok) { window.location.reload(); } else { tell(j.error || T('Nothing was moved.'), 'error'); } }) // the reload shows the notice with Undo
			.catch(function () { tell(T('Nothing was moved.'), 'error'); });
	});
	document.addEventListener('dragend', done); // also after Esc
})();
