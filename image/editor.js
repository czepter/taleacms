/* Talea - text editor (news, pages) and working with images. No libraries, no build step.
 *
 *   <textarea data-editor>            WYSIWYG editor (data-editor="maly" = shortened toolbar)
 *   <input data-image>              image URL field + "Choose from gallery" button and a preview
 *   <textarea data-gallery>         photos of a collection gallery field as tiles: upload, drop, reorder, describe
 *   <form data-upload>             uploading by dragging files
 *
 * The form always submits the content of the original <textarea> - without JavaScript it stays a plain HTML field.
 */

(function () {
	'use strict';

	// translation of admin texts (image/languages/admin-*.js); on the site (edit in place) there is no admin.js, the dictionary is read directly
	var T = window.T || function (s) { return (window.TALEA_TRANSLATIONS || {})[s] || s; };

	var SCRIPT = document.querySelector('script[data-admin-url]');
	var ADMIN = SCRIPT.getAttribute('data-admin-url');
	var MAX_FILE = parseInt(SCRIPT.getAttribute('data-max-file') || '0', 10); // the server's per-file limit in bytes (0 = no limit)
	var MAX_SIDE = parseInt(SCRIPT.getAttribute('data-max-page') || '2000', 10);
	var CSRF = (document.querySelector('input[name="_csrf"]') || {}).value || '';
	var GALLERY = ADMIN + '/media';
	var NEWS_ID = (document.querySelector('form[data-draft] input[name="news_id"]') || {}).value || ''; // public id of the news item ('' = a new one)
	var LANGUAGE = document.documentElement.lang || 'cs'; // date and time format by the page language
	var time = function (t, timeOnly) { return window.taleaTime ? window.taleaTime(t, timeOnly) : new Date(t).toLocaleString(LANGUAGE); }; // image/admin.js

	function esc(t) { return String(t).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;'); }

	// Error notice in a custom dialog - embedded browsers suppress the system alert() just like confirm().
	var noticeDialog = null;
	function announce(text) {
		if (!noticeDialog) {
			noticeDialog = document.createElement('dialog');
			noticeDialog.className = 'confirmation';
			noticeDialog.setAttribute('role', 'alertdialog');
			noticeDialog.innerHTML = '<p></p><div><button type="button" class="btn" data-close>' + T('Close') + '</button></div>';
			document.body.appendChild(noticeDialog);
			noticeDialog.querySelector('[data-close]').addEventListener('click', function () { noticeDialog.close(); });
		}
		noticeDialog.querySelector('p').textContent = text;
		if (!noticeDialog.open) { noticeDialog.showModal(); }
	}

	/* ---------- HTML cleanup (pasting from Word and the web) ---------- */

	var ALLOWED = { P: [], H2: [], H3: [], H4: [], STRONG: [], EM: [], B: [], I: [], U: [], S: [], SUB: [], SUP: [], MARK: [], BR: [], HR: [],
		A: ['href', 'title', 'target', 'rel'], UL: [], OL: [], LI: [], BLOCKQUOTE: [], CODE: [], PRE: [],
		FIGURE: ['class'], FIGCAPTION: [], IMG: ['src', 'alt', 'width', 'height', 'loading', 'data-id'],
		TABLE: [], THEAD: [], TBODY: [], TR: [], TH: ['colspan', 'rowspan'], TD: ['colspan', 'rowspan'], IFRAME: ['src', 'width', 'height', 'allowfullscreen', 'title'] };
	var TAG_REPLACEMENTS = { DIV: 'P', H1: 'H2', H5: 'H4', H6: 'H4' };
	// removed with their content: the head of a pasted page, Word's markup and every raw-text element – their text is never shown,
	// and the serializer writes it unescaped, so it must not survive into the markup
	var DISCARD = /^(SCRIPT|STYLE|META|LINK|TITLE|HEAD|O:P|XML|TEXTAREA|NOSCRIPT|XMP|NOEMBED|NOFRAMES|PLAINTEXT|TEMPLATE)$/;
	// a pasted frame only from a service the site embeds itself – the same list as [data-insert] in image/web.js
	var EMBED = /^https:\/\/(www\.youtube-nocookie\.com\/embed\/|player\.vimeo\.com\/video\/|maps\.google\.com\/maps\?|calendly\.com\/|calendar\.google\.com\/calendar\/appointments\/schedules\/|docs\.google\.com\/forms\/d\/e\/|forms\.office\.com\/Pages\/ResponsePage\.aspx\?|tally\.so\/embed\/|form\.typeform\.com\/to\/|airtable\.com\/embed\/|open\.spotify\.com\/embed\/|w\.soundcloud\.com\/player\/\?)/;
	var REL = ['noopener', 'noreferrer', 'nofollow', 'ugc', 'sponsored'];

	// The browser drops whitespace and control characters from an address before it reads the scheme ("java\tscript:", " JavaScript:");
	// entities are already decoded in an attribute value.
	function unsafeUrl(url) { return /^(javascript|vbscript|data):/i.test(String(url).replace(/[\u0000-\u0020\u007f-\u009f]/g, '')); }

	/** The address of a pasted frame the site allows (a YouTube frame turns into its cookie-free variant), otherwise ''. */
	function embedSrc(src) {
		src = String(src).trim().replace(/^https:\/\/(www\.)?youtube\.com\/embed\//, 'https://www.youtube-nocookie.com/embed/');
		return EMBED.test(src) ? src : '';
	}

	// strict: HTML from outside (a paste) – a frame stays only with an allowed address
	function sanitize(node, strict) {
		Array.prototype.slice.call(node.childNodes).forEach(function (n) {
			if (n.nodeType === 8) { n.remove(); return; }
			if (n.nodeType !== 1) { return; }
			var tag = n.tagName;
			if (DISCARD.test(tag)) { n.remove(); return; }
			sanitize(n, strict);
			if (TAG_REPLACEMENTS[tag]) {
				var fresh = n.ownerDocument.createElement(TAG_REPLACEMENTS[tag]);
				while (n.firstChild) { fresh.appendChild(n.firstChild); }
				n.replaceWith(fresh);
				return;
			}
			if (!ALLOWED[tag]) {
				while (n.firstChild) { n.parentNode.insertBefore(n.firstChild, n); }
				n.remove();
				return;
			}
			Array.prototype.slice.call(n.attributes).forEach(function (a) {
				if (ALLOWED[tag].indexOf(a.name) === -1 || ((a.name === 'href' || a.name === 'src') && unsafeUrl(a.value))) { n.removeAttribute(a.name); }
			});
			if (tag === 'IFRAME') {
				n.textContent = ''; // a frame's content is raw text the serializer writes as it is ("</iframe>" inside it would end the frame)
				var src = embedSrc(n.getAttribute('src') || '');
				if (src) { n.setAttribute('src', src); } else if (strict) { n.remove(); }
			} else if (tag === 'A') {
				// a new window only as _blank and never with window.opener; rel only with the values links need
				if (n.hasAttribute('target') && n.getAttribute('target') !== '_blank') { n.removeAttribute('target'); }
				var rel = (n.getAttribute('rel') || '').toLowerCase().split(/\s+/).filter(function (t, i, all) { return REL.indexOf(t) !== -1 && all.indexOf(t) === i; });
				if (n.getAttribute('target') === '_blank' && rel.indexOf('noopener') === -1) { rel.unshift('noopener'); }
				if (rel.length) { n.setAttribute('rel', rel.join(' ')); } else { n.removeAttribute('rel'); }
			}
		});
	}

	/** Empty paragraphs out, non-breaking spaces into spaces – on the nodes, never with a regular expression on the markup. */
	function tidy(box) {
		Array.prototype.slice.call(box.querySelectorAll('p')).forEach(function (p) {
			if (Array.prototype.every.call(p.childNodes, function (c) { return (c.nodeType === 3 && /^\s*$/.test(c.data)) || (c.nodeType === 1 && c.tagName === 'BR'); })) { p.remove(); }
		});
		var walker = box.ownerDocument.createTreeWalker(box, 4); // NodeFilter.SHOW_TEXT
		for (var t = walker.nextNode(); t; t = walker.nextNode()) {
			if (t.data.indexOf('\u00a0') !== -1) { t.data = t.data.replace(/\u00a0/g, ' '); }
		}
	}

	// Parsed in the inert document behind <template>: in the admin page itself a pasted <img onerror> would load and run before
	// sanitize() removed it. Not createHTMLDocument(): Chromium loads images there, so the handler fires (CSP only blocks it).
	var inert = document.createElement('template').content.ownerDocument;
	// Chromium checks the admin's CSP for every on…= attribute it parses, even in the inert document (and reports a violation);
	// inside a tag they are renamed first. Not a filter: sanitize() keeps only the attributes it allows, so they go anyway.
	function defuse(html) {
		return String(html).replace(/<[a-z][^>]*>/gi, function (tag) { return tag.replace(/([\s"'\/])on([a-z]+\s*=)/gi, '$1data-defused-on$2'); });
	}
	function cleanOnce(html, strict, keepEmpty) {
		var box = inert.createElement('div');
		box.innerHTML = defuse(html);
		sanitize(box, strict);
		if (!keepEmpty) { tidy(box); }
		return box.innerHTML.trim();
	}

	/**
	 * The cleaned HTML. It is parsed again (insertHTML, innerHTML, the saved field), so it must come out the same when cleaned
	 * once more – nested paragraphs or a table the parser rebuilds settle in a pass or two; markup that does not settle is
	 * given back as plain text.
	 */
	function cleanHtml(html, strict, keepEmpty) {
		var out = cleanOnce(html, strict, keepEmpty);
		for (var i = 0; i < 4; i++) {
			var again = cleanOnce(out, strict, keepEmpty);
			if (again === out) { return out; }
			out = again;
		}
		var box = inert.createElement('div');
		box.innerHTML = out;
		return esc(box.textContent);
	}

	/** Shows cleaned HTML in an element of the admin page as nodes imported from the inert document – no string is parsed here. */
	function showClean(target, html, strict, keepEmpty) {
		var box = inert.createElement('div');
		box.innerHTML = cleanHtml(html, strict, keepEmpty);
		var fragment = document.createDocumentFragment();
		Array.prototype.forEach.call(box.childNodes, function (n) { fragment.appendChild(document.importNode(n, true)); });
		target.replaceChildren(fragment);
	}

	/* ---------- uploading ---------- */

	// A phone photo (5–10 MB) is scaled down to MAX_STRANA px already in the browser – the server would scale it anyway – so it
	// does not hit the hosting limit. createImageBitmap keeps the EXIF rotation; EXIF data (location too) is dropped, as in server processing.
	function shrink(file) {
		if (!/^image\/(jpeg|png|webp)$/.test(file.type) || !window.createImageBitmap) { return Promise.resolve(file); }
		return createImageBitmap(file, { imageOrientation: 'from-image' }).then(function (bitmap) {
			// the same rule as Core\Images::ratio: a tall image (a full-page screenshot) is measured by width, not by the longer side
			var w = bitmap.width, h = bitmap.height;
			var ratio = h > 2 * w ? Math.min(1, MAX_SIDE / w, 3 * MAX_SIDE / h) : Math.min(1, MAX_SIDE / Math.max(w, h));
			if (ratio === 1 && (!MAX_FILE || file.size <= MAX_FILE)) { bitmap.close(); return file; }
			var canvas = document.createElement('canvas');
			canvas.width = Math.round(bitmap.width * ratio);
			canvas.height = Math.round(bitmap.height * ratio);
			canvas.getContext('2d').drawImage(bitmap, 0, 0, canvas.width, canvas.height);
			bitmap.close();
			// quality 0.9; when the result is still over the server limit, a lower one is tried (PNG has no quality)
			var write = function (quality) {
				return new Promise(function (done) { canvas.toBlob(done, file.type, quality); }).then(function (blob) {
					if (blob && MAX_FILE && blob.size > MAX_FILE && file.type !== 'image/png' && quality > 0.6) { return write(Math.round((quality - 0.1) * 10) / 10); }
					// a browser that cannot write the type (WebP in Safari) returns another one – then the original file is preferred
					return blob && blob.type === file.type && (ratio < 1 || blob.size < file.size)
						? new File([blob], file.name, { type: file.type, lastModified: file.lastModified }) : file;
				});
			};
			return write(0.9);
		}).catch(function () { return file; });
	}

	// Scales images down and sets aside files the server would reject anyway (a whole request over the limit would fail without explanation).
	function prepareFiles(files) {
		return Promise.all(Array.prototype.map.call(files, shrink)).then(function (finished) {
			var errors = [];
			var ok = finished.filter(function (s) {
				if (!MAX_FILE || s.size <= MAX_FILE) { return true; }
				errors.push(s.name + ': ' + T('The file is larger than the server allows (%s at most). Make it smaller or ask your hosting provider to raise the limit.').replace('%s', SCRIPT.getAttribute('data-max-file-text') || ''));
				return false;
			});
			if (errors.length) { announce(errors.join('\n')); }
			return ok;
		});
	}

	function upload(selected) {
		return prepareFiles(selected).then(function (files) { return files.length ? send(files) : []; });
	}

	function send(files) {
		var data = new FormData();
		var folder = document.querySelector('.gallery-window[open] select');
		data.append('_csrf', CSRF);
		data.append('folder_id', folder && folder.value !== '' && folder.value !== 'article' ? folder.value : '0');
		files.forEach(function (s) { data.append('files[]', s); });
		return fetch(GALLERY + '/upload?format=json', { method: 'POST', body: data, credentials: 'same-origin' })
			.then(function (r) { return r.json(); })
			.then(function (j) {
				if (j.errors && j.errors.length) { announce(j.errors.join('\n')); }
				return j.images || [];
			})
			.catch(function () { announce(T('Upload failed. Check your connection and try again.')); return []; });
	}

	function hasImages(transfer) {
		return transfer && transfer.files && transfer.files.length && Array.prototype.every.call(transfer.files, function (f) { return /^image\//.test(f.type); });
	}

	/* ---------- gallery dialog ---------- */

	var modal = null;

	// vice = true: tapping selects images and they are inserted at once as a photo gallery
	function pickImage(backwards, more, withAttachments) {
		var selected = [];
		if (!modal) {
			modal = document.createElement('dialog');
			modal.className = 'gallery-window';
			modal.innerHTML = '<div class="gallery-window-head"><strong>' + T('Media') + '</strong>'
				+ '<label class="btn">' + T('Upload new') + '<input type="file" multiple hidden></label>'
				// on a phone and tablet: take a photo straight into the text (CSS shows the button only on touch devices)
				+ '<label class="navigation gallery-take-photo">' + T('Take a photo') + '<input type="file" accept="image/*" capture="environment" hidden></label>'
				+ '<button type="button" class="btn" data-insert hidden></button>' // "Insert gallery (n)" – only when selecting several photos
				+ '<button type="button" class="navigation" data-close>' + T('Close') + '</button></div>'
				+ '<div class="gallery-window-filter"><select aria-label="' + T('Folder') + '"></select>'
				+ '<input class="textfield" type="search" placeholder="' + T('Search media…') + '" aria-label="' + T('Search media') + '"></div>'
				+ '<p class="placeholder"></p><div class="gallery-grid"></div>' // the hint text is set on every opening;
			document.body.appendChild(modal);
			modal.querySelector('[data-close]').addEventListener('click', function () { modal.close(); });
			modal.querySelector('[data-insert]').addEventListener('click', function () { modal.close(); modal.onPick(modal.chosen.slice()); });
			modal.querySelector('select').addEventListener('change', function () { load(this.value); });
			var waiting = null; // search runs only after a short pause in typing, not after every letter
			modal.querySelector('input[type=search]').addEventListener('input', function () {
				clearTimeout(waiting);
				waiting = setTimeout(function () { load(modal.querySelector('select').value); }, 300);
			});
			Array.prototype.forEach.call(modal.querySelectorAll('input[type=file]'), function (inputEl) { inputEl.addEventListener('change', function () {
				upload(this.files).then(function (newItems) { newItems.reverse().forEach(function (o) { add(o, true); }); });
				this.value = '';
			}); });
			modal.addEventListener('dragover', function (e) { e.preventDefault(); });
			modal.addEventListener('drop', function (e) {
				e.preventDefault();
				if (e.dataTransfer.files.length) { upload(e.dataTransfer.files).then(function (newItems) { newItems.reverse().forEach(function (o) { add(o, true); }); }); }
			});
		}
		var grid = modal.querySelector('.gallery-grid');
		function add(o, upward) {
			var b = document.createElement('button');
			b.type = 'button';
			b.className = 'gallery-item';
			if (o.file && !modal.withFiles) { return; } // main image, logo, gallery: images only
			b.innerHTML = o.file ? '<span class="gallery-file"><span></span></span><span></span>' : '<img loading="lazy" alt=""><span></span>';
			if (o.file) { b.firstChild.firstChild.textContent = o.extension; } else { b.firstChild.src = o.thumbnail; }
			b.lastChild.textContent = o.name || T('untitled');
			b.addEventListener('click', function () {
				if (!modal.multiple) { modal.close(); modal.onPick(o); return; }
				var i = modal.chosen.indexOf(o);
				if (i === -1) { modal.chosen.push(o); } else { modal.chosen.splice(i, 1); }
				b.classList.toggle('selected', i === -1);
				b.setAttribute('aria-pressed', i === -1 ? 'true' : 'false');
				modal.mark();
			});
			if (upward) { grid.prepend(b); } else { grid.appendChild(b); }
		}
		// filter: "" = all, "clanek" = images of this news item, number = folder (0 = unfiled)
		function load(filter) {
			var query = filter === 'article' ? '&article=' + encodeURIComponent(NEWS_ID) : (filter !== '' ? '&section=' + filter : '');
			var search = modal.querySelector('input[type=search]').value.trim();
			if (search !== '') { query += '&search=' + encodeURIComponent(search); }
			grid.textContent = T('Loading…');
			fetch(GALLERY + '/listing?' + query, { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (j) {
				var selection = modal.querySelector('select');
				selection.textContent = '';
				[['', T('All media')]].concat(NEWS_ID ? [['article', T('In this text')]] : [], [['0', T("Unsorted")]], j.folders.map(function (s) { return [String(s.id), T('Folder: ') + s.name]; })).forEach(function (v) {
					var o = document.createElement('option');
					o.value = v[0]; o.textContent = v[1]; o.selected = v[0] === filter;
					selection.appendChild(o);
				});
				grid.textContent = j.images.length ? '' : (search !== '' ? T('Nothing matches your search.') : T('No images here yet.'));
				j.images.forEach(function (o) { add(o, false); });
				additional(query, 2, j.images.length);
			});
		}
		// the server returns 60 items per page: a full page = offer more, so older files are reachable too
		function additional(query, pageNumber, loaded) {
			if (loaded < 60) { return; }
			var btn = document.createElement('button');
			btn.type = 'button';
			btn.className = 'navigation media-more';
			btn.textContent = T('Load more');
			btn.addEventListener('click', function () {
				btn.disabled = true;
				fetch(GALLERY + '/listing?' + query + '&page=' + pageNumber, { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (j) {
					btn.remove();
					j.images.forEach(function (o) { add(o, false); });
					additional(query, pageNumber + 1, j.images.length);
				});
			});
			grid.appendChild(btn);
		}
		modal.onPick = backwards;
		modal.multiple = !!more;
		modal.withFiles = !!withAttachments && !more;
		modal.chosen = selected;
		modal.mark = function () {
			var btn = modal.querySelector('[data-insert]');
			btn.hidden = !modal.multiple;
			btn.disabled = modal.chosen.length < 2;
			btn.textContent = modal.chosen.length < 2 ? T('Select at least 2 photos') : T('Insert gallery (') + modal.chosen.length + ')';
		};
		modal.mark();
		modal.querySelector('.placeholder').textContent = more
			? T('Click photos in the order they should appear. You can also drop files here.')
			: T('Click an image to insert it. You can also drop files here – they upload to the selected folder.');
		modal.showModal();
		load(modal.querySelector('select').value || '');
	}

	function imageHtml(o) {
		return '<figure><img src="' + esc(o.url) + '" alt="' + esc(o.name) + '" width="' + o.width + '" height="' + o.height + '" loading="lazy" data-id="' + o.id + '">'
			+ (o.description ? '<figcaption>' + esc(o.description) + '</figcaption>' : '') + '</figure><p><br></p>';
	}

	function attachmentHtml(o) {
		return '<p><a href="' + esc(o.url) + '" title="' + esc(o.extension + ', ' + o.size) + '">' + esc(o.name || o.extension) + '</a> (' + esc(o.extension + ', ' + o.size) + ')</p>';
	}

	function galleryHtml(images) {
		return '<figure class="gallery">' + images.map(function (o) {
			return '<img src="' + esc(o.url) + '" alt="' + esc(o.description || o.name) + '" width="' + o.width + '" height="' + o.height + '" loading="lazy" data-id="' + o.id + '">';
		}).join('') + '</figure><p><br></p>';
	}

	/* ---------- editor ---------- */

	var BUTTONS = [
		['¶', T('Paragraph'), function () { statement('formatBlock', 'P'); }],
		['H2', T("Subheading"), function () { statement('formatBlock', 'H2'); }, 'large'],
		['H3', T('Smaller subheading'), function () { statement('formatBlock', 'H3'); }, 'large'],
		['B', T('Bold (Ctrl+B)'), function () { statement('bold'); }],
		['I', T('Italic (Ctrl+I)'), function () { statement('italic'); }],
		[T('link'), T('Insert link (Ctrl+K)'), link],
		[T('• list'), T('Bulleted list'), function () { statement('insertUnorderedList'); }],
		[T('1. list'), T('Numbered list'), function () { statement('insertOrderedList'); }, 'large'],
		[T('“quote”'), T('Quote'), function () { statement('formatBlock', 'BLOCKQUOTE'); }, 'large'],
		[T('image'), T('Insert an image from Media'), null, 'large'],
		[T('gallery'), T('Insert a photo gallery – visitors can browse the photos full screen'), 'gallery', 'large'],
		[T('table'), T('Insert a 3 × 3 table with a header; add rows and columns with the buttons above the table'), function () {
			var row = function (tag) { return '<tr><' + tag + '><br></' + tag + '><' + tag + '><br></' + tag + '><' + tag + '><br></' + tag + '></tr>'; };
			statement('insertHTML', '<table><thead>' + row('th') + '</thead><tbody>' + row('td') + row('td') + '</tbody></table><p><br></p>');
		}, 'large'],
		['—', T("Divider"), function () { statement('insertHorizontalRule'); }, 'large'],
		['Tx', T('Remove formatting'), function () { statement('removeFormat'); statement('unlink'); }]
	];

	function statement(name, value) { document.execCommand(name, false, value || null); }

	/* Link dialog: a URL, or an own news item found by its title. Embedded browsers suppress the system prompt(). */
	var linkDialog = null;

	function link() {
		var selection = window.getSelection();
		var scope = selection.rangeCount ? selection.getRangeAt(0).cloneRange() : null;
		var node = selection.anchorNode && (selection.anchorNode.nodeType === 1 ? selection.anchorNode : selection.anchorNode.parentElement);
		var anchor = node && node.closest('a');
		var surface = node && node.closest('.editor-surface');
		if (!surface || !scope) { return; }
		if (!linkDialog) {
			linkDialog = document.createElement('dialog');
			linkDialog.className = 'gallery-window link-window';
			linkDialog.innerHTML = '<form method="dialog"><div class="gallery-window-head"><strong>' + T('Link') + '</strong></div>'
				+ '<label>' + T("Address") + '<input class="textfield wide" type="text" name="address" placeholder="https://… ' + T('or') + ' /o-nas" autocomplete="off"></label>'
				+ '<label>' + T('…or find a news item on the site') + '<input class="textfield wide" type="search" name="search" placeholder="' + T('part of the headline') + '" autocomplete="off"></label>'
				+ '<div class="link-results" aria-live="polite"></div>'
				+ '<label class="link-option"><input type="checkbox" name="new_window"> ' + T('open in a new window') + '</label>'
				+ '<div class="link-buttons"><button type="submit" class="btn" value="ok">' + T('Insert link') + '</button> <button type="button" class="navigation" data-cancel>' + T('Remove link') + '</button> <button type="button" class="navigation" data-close>' + T('Close') + '</button></div></form>';
			document.body.appendChild(linkDialog);
			var timer = null;
			linkDialog.querySelector('[name=search]').addEventListener('input', function () {
				var q = this.value.trim(), results = linkDialog.querySelector('.link-results');
				clearTimeout(timer);
				if (q.length < 2) { results.textContent = ''; return; }
				timer = setTimeout(function () {
					fetch(ADMIN + '/news/search_json?q=' + encodeURIComponent(q), { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (j) {
						results.textContent = j.articles.length ? '' : T('Nothing found.');
						j.articles.forEach(function (c) {
							var b = document.createElement('button');
							b.type = 'button';
							b.textContent = c.title + (c.published ? '' : ' (' + T("unpublished") + ')');
							b.addEventListener('click', function () { linkDialog.querySelector('[name=address]').value = c.url; linkDialog.querySelector('[name=address]').focus(); });
							results.appendChild(b);
						});
					});
				}, 250);
			});
			linkDialog.querySelector('[data-close]').addEventListener('click', function () { linkDialog.close('close'); });
			linkDialog.querySelector('[data-cancel]').addEventListener('click', function () { linkDialog.close('cancel'); });
			linkDialog.addEventListener('close', function () { linkDialog.finish(linkDialog.returnValue); });
		}
		var f = linkDialog.querySelector('form');
		f.address.value = anchor ? anchor.getAttribute('href') : '';
		f.search.value = '';
		f.new_window.checked = !!(anchor && anchor.target === '_blank');
		linkDialog.querySelector('.link-results').textContent = '';
		linkDialog.querySelector('[data-cancel]').hidden = !anchor;
		linkDialog.returnValue = '';
		linkDialog.finish = function (result) {
			surface.focus();
			selection.removeAllRanges();
			if (anchor) { var r = document.createRange(); r.selectNode(anchor); selection.addRange(r); } else { selection.addRange(scope); }
			var url = f.address.value.trim();
			if (result === 'cancel') { statement('unlink'); } else if (result === 'ok' && url !== '' && !unsafeUrl(url)) {
				var target = f.new_window.checked ? ' target="_blank" rel="noopener"' : '';
				var text = selection.isCollapsed ? url : (anchor ? anchor.innerHTML : selection.toString().replace(/&/g, '&amp;').replace(/</g, '&lt;'));
				statement('insertHTML', '<a href="' + url.replace(/&/g, '&amp;').replace(/"/g, '&quot;') + '"' + target + '>' + (anchor || !selection.isCollapsed ? text : url.replace(/&/g, '&amp;').replace(/</g, '&lt;')) + '</a>');
			}
			surface.dispatchEvent(new Event('input', { bubbles: true }));
		};
		linkDialog.showModal();
		f.address.focus();
	}

	function createEditor(field) {
		var small = field.getAttribute('data-editor') === 'small';
		var wrapper = document.createElement('div');
		wrapper.className = 'editor' + (small ? ' editor-small' : '');
		var tabList = document.createElement('div');
		tabList.className = 'editor-tools';
		tabList.setAttribute('role', 'toolbar');
		var surface = document.createElement('div');
		surface.className = 'editor-surface';
		surface.contentEditable = 'true';
		if (field.dir) { surface.dir = field.dir; } // the text of a right-to-left page (Arabic) is written from the right; the toolbar stays as it is
		surface.style.setProperty('--ed-gallery-label', JSON.stringify(T('Photo gallery'))); // the label above a photo gallery is drawn by editor.css; a text in CSS could not be translated
		surface.setAttribute('role', 'textbox');
		surface.setAttribute('aria-multiline', 'true');
		surface.setAttribute('aria-label', (field.labels && field.labels[0] ? field.labels[0].textContent : "Text"));
		var state = document.createElement('div');
		state.className = 'editor-status';
		var source = false;

		function toField() { if (!source) { field.value = cleanHtml(surface.innerHTML); } count(); field.dispatchEvent(new Event('input', { bubbles: true })); }
		// the field's HTML is shown through the cleanup as inert nodes: it also comes from the builder canvas and the AI assistant
		function fromField() { showClean(surface, field.value.trim() || '<p><br></p>', false, true); }
		function count() {
			var wordCount = (surface.innerText.trim().match(/\S+/g) || []).length;
			state.firstChild.textContent = wordCount + T(' words') + (small ? '' : T(' · reading time about ') + Math.max(1, Math.round(wordCount / 200)) + ' min');
		}
		function insertImage(gallery) {
			var scope = window.getSelection().rangeCount ? window.getSelection().getRangeAt(0).cloneRange() : null;
			pickImage(function (o) {
				surface.focus();
				if (scope && surface.contains(scope.startContainer)) { window.getSelection().removeAllRanges(); window.getSelection().addRange(scope); }
				statement('insertHTML', gallery ? galleryHtml(o) : (o.file ? attachmentHtml(o) : imageHtml(o)));
				toField();
			}, gallery, true);
		}

		BUTTONS.forEach(function (t) {
			if (small && t[3] === 'large') { return; }
			var b = document.createElement('button');
			b.type = 'button';
			b.textContent = t[0];
			b.title = t[1];
			if (t[0] === 'B') { b.style.fontWeight = 'bold'; }
			if (t[0] === 'I') { b.style.fontStyle = 'italic'; }
			b.addEventListener('mousedown', function (e) { e.preventDefault(); });
			b.addEventListener('click', function () { if (source) { return; } surface.focus(); if (typeof t[2] === 'function') { t[2](); } else { insertImage(t[2] === 'gallery'); } toField(); });
			tabList.appendChild(b);
		});
		var html = document.createElement('button');
		html.type = 'button';
		html.textContent = 'HTML';
		html.title = T('Switch to source code');
		html.className = 'editor-html';
		html.setAttribute('aria-pressed', 'false');
		html.addEventListener('click', function () {
			source = !source;
			if (source) { field.value = cleanHtml(surface.innerHTML).replace(/<\/(p|h2|h3|h4|ul|ol|li|blockquote|figure)>/g, '</$1>\n'); } else { fromField(); }
			wrapper.classList.toggle('editor-source', source);
			html.setAttribute('aria-pressed', source ? 'true' : 'false');
			(source ? field : surface).focus();
		});
		tabList.appendChild(html);
		state.appendChild(document.createElement('span'));
		state.appendChild(document.createElement('span'));

		field.parentNode.insertBefore(wrapper, field);
		wrapper.appendChild(tabList);
		wrapper.appendChild(surface);
		wrapper.appendChild(field);
		wrapper.appendChild(state);
		field.classList.add('editor-field');
		fromField();
		statement('defaultParagraphSeparator', 'p');

		// table editing: the toolbar shows when the cursor is in a table
		var tabBar = document.createElement('div');
		tabBar.className = 'editor-table-bar';
		tabBar.hidden = true;
		var cell = function () {
			var node = window.getSelection().anchorNode;
			node = node && (node.nodeType === 1 ? node : node.parentElement);
			var b = node && node.closest('td, th');
			return b && surface.contains(b) ? b : null;
		};
		[[T('+ row'), function (b) {
			var fresh = b.parentNode.cloneNode(true);
			Array.prototype.forEach.call(fresh.children, function (c) { var td = document.createElement('td'); td.innerHTML = '<br>'; c.replaceWith(td); });
			var body = b.closest('table').querySelector('tbody') || b.closest('table');
			if (b.parentNode.parentNode.tagName === 'THEAD') { body.prepend(fresh); } else { b.parentNode.after(fresh); }
		}], [T('+ column'), function (b) {
			var i = b.cellIndex;
			Array.prototype.forEach.call(b.closest('table').rows, function (r) { var c = document.createElement(r.cells[i].tagName); c.innerHTML = '<br>'; r.cells[i].after(c); });
		}], [T('− row'), function (b) {
			var t = b.closest('table');
			if (t.rows.length > 1) { b.parentNode.remove(); } else { t.remove(); }
		}], [T('− column'), function (b) {
			var i = b.cellIndex, t = b.closest('table');
			if (t.rows[0].cells.length > 1) { Array.prototype.forEach.call(t.rows, function (r) { r.deleteCell(i); }); } else { t.remove(); }
		}], [T('delete table'), function (b) { b.closest('table').remove(); }]].forEach(function (a) {
			var btn = document.createElement('button');
			btn.type = 'button';
			btn.textContent = a[0];
			btn.addEventListener('mousedown', function (e) { e.preventDefault(); });
			btn.addEventListener('click', function () { var b = cell(); if (b) { a[1](b); toField(); tabBar.hidden = !cell(); } });
			tabBar.appendChild(btn);
		});
		tabList.after(tabBar);
		document.addEventListener('selectionchange', function () { tabBar.hidden = source || !cell(); });

		surface.addEventListener('input', toField);
		surface.addEventListener('blur', toField);
		surface.addEventListener('keydown', function (e) {
			// stopPropagation: the command palette (admin.js) has the same shortcut - in the editor it means "insert link"
			if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') { e.preventDefault(); e.stopPropagation(); link(); toField(); }
		});
		surface.addEventListener('paste', function (e) {
			var transfer = e.clipboardData;
			if (hasImages(transfer)) {
				e.preventDefault();
				upload(transfer.files).then(function (newItems) { newItems.forEach(function (o) { statement('insertHTML', imageHtml(o)); }); toField(); });
				return;
			}
			var inserted = transfer.getData('text/html');
			if (inserted) { e.preventDefault(); statement('insertHTML', cleanHtml(inserted, true)); toField(); }
		});
		surface.addEventListener('dragover', function (e) { if (hasImages(e.dataTransfer) || (e.dataTransfer.types || []).indexOf('Files') !== -1) { e.preventDefault(); wrapper.classList.add('editor-drag-over'); } });
		surface.addEventListener('dragleave', function () { wrapper.classList.remove('editor-drag-over'); });
		surface.addEventListener('drop', function (e) {
			wrapper.classList.remove('editor-drag-over');
			// a file other than an image (PDF…): not uploaded, but the browser must not open it in place of the form - unsaved text would be gone
			if (!hasImages(e.dataTransfer)) { if (e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files.length) { e.preventDefault(); } return; }
			e.preventDefault();
			upload(e.dataTransfer.files).then(function (newItems) { surface.focus(); newItems.forEach(function (o) { statement('insertHTML', imageHtml(o)); }); toField(); });
		});
		if (field.form) { field.form.addEventListener('submit', function () { if (!source) { field.value = cleanHtml(surface.innerHTML); } }); }
		count();
		// the editor helper (accessibility check, AI assistant) redraws the editor after writing into the field
		window.taleaEditors = window.taleaEditors || {};
		if (field.id) { window.taleaEditors[field.id] = { refresh: function () { fromField(); count(); } }; }
		return { refresh: fromField, status: state.lastChild };
	}

	/* ---------- automatic saving of unsaved text to the browser ---------- */

	function autosave(form, editors) {
		var key = 'talea-draft:' + form.getAttribute('data-draft');
		var field = Array.prototype.filter.call(form.elements, function (p) { return p.name && p.name !== '_csrf' && p.type !== 'password' && p.type !== 'file' && p.type !== 'submit'; });
		var timer = null;

		function save() {
			var data = { time: Date.now(), fields: {} };
			field.forEach(function (p) { if (p.type === 'checkbox' || p.type === 'radio') { if (p.checked) { data.fields[p.name] = p.value; } else if (p.type === 'checkbox') { data.fields[p.name] = null; } } else { data.fields[p.name] = p.value; } });
			try { localStorage.setItem(key, JSON.stringify(data)); } catch (e) { /* the browser did not allow storage - the server remains */ }
			editors.forEach(function (ed) { ed.status.textContent = T('draft saved in your browser at ') + time(Date.now(), true); });
			lastData = data;
			if (!serverTimer) { serverTimer = setTimeout(saveToServer, 15000); }
		}
		// the unsaved state goes to the server at most once every 15 seconds: it can be continued from another device
		var draftUrl = form.getAttribute('data-draft-url'), serverTimer = null, lastData = null;
		function saveToServer() {
			serverTimer = null;
			if (!draftUrl || !lastData) { return; }
			var fd = new FormData();
			fd.append('_csrf', CSRF);
			fd.append('news_id', NEWS_ID);
			fd.append('fields', JSON.stringify(lastData.fields));
			fetch(draftUrl, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (j) {
				if (j.ok) { editors.forEach(function (ed) { ed.status.textContent = T('draft also saved on the server at ') + time(Date.now(), true); }); }
			}).catch(function () { /* offline, the copy stays in the browser */ });
		}
		function discardOnServer() {
			if (!draftUrl) { return; }
			var fd = new FormData();
			fd.append('_csrf', CSRF);
			fd.append('news_id', NEWS_ID);
			fetch(draftUrl, { method: 'POST', body: fd, credentials: 'same-origin' }).catch(function () { /* nothing */ });
		}
		form.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(save, 1500); });
		// on submit the copy is not deleted, only marked: when saving fails (expired sign-in, connection outage), the text remains to restore.
		// It is deleted only after a confirmed save (success message, image/admin.js), or when it matches the saved content.
		form.addEventListener('submit', function () { clearTimeout(timer); save(); try { var d = JSON.parse(localStorage.getItem(key) || 'null'); if (d) { d.submitted = Date.now(); localStorage.setItem(key, JSON.stringify(d)); } } catch (e) { /* nothing */ } });

		var storedForm = null;
		try { storedForm = JSON.parse(localStorage.getItem(key) || 'null'); } catch (e) { /* nothing */ }
		// the newer of the two copies: this device's browser, or the server (writing from another device)
		var fromServer = null;
		try { fromServer = JSON.parse((document.getElementById('draft-server') || {}).textContent || 'null'); } catch (e) { /* nothing */ }
		var isFromServer = !!(fromServer && fromServer.fields && (!storedForm || !storedForm.time || fromServer.time > storedForm.time));
		if (isFromServer) { storedForm = fromServer; }
		if (!storedForm || !storedForm.fields || Date.now() - storedForm.time > 14 * 86400000) { return; }
		var differs = field.some(function (p) { return (p.tagName === 'TEXTAREA' || p.type === 'text') && storedForm.fields[p.name] !== undefined && storedForm.fields[p.name] !== p.value; });
		if (!differs) { if (!isFromServer) { try { localStorage.removeItem(key); } catch (e) { /* nothing */ } } return; }
		var tabList = document.createElement('p');
		tabList.className = 'notice';
		tabList.innerHTML = T(isFromServer ? 'The server holds an unsaved draft from ' : 'Your browser holds an unsaved draft from ') + time(storedForm.time) + '. <button type="button" class="navigation">' + T('Restore it') + '</button> <button type="button" class="navigation">' + T('Discard') + '</button>';
		form.parentNode.insertBefore(tabList, form);
		tabList.children[0].addEventListener('click', function () {
			field.forEach(function (p) {
				if (!(p.name in storedForm.fields)) { return; }
				if (p.type === 'checkbox') { p.checked = storedForm.fields[p.name] !== null; } else if (p.type === 'radio') { p.checked = p.value === storedForm.fields[p.name]; } else { p.value = storedForm.fields[p.name]; }
			});
			editors.forEach(function (ed) { ed.refresh(); });
			document.querySelectorAll('[data-image], [data-gallery]').forEach(function (p) { p.dispatchEvent(new Event('change')); });
			tabList.remove();
		});
		tabList.children[1].addEventListener('click', function () { try { localStorage.removeItem(key); } catch (e) { /* nothing */ } discardOnServer(); tabList.remove(); });
	}

	/* ---------- the featured image field ---------- */

	document.querySelectorAll('[data-image]').forEach(function (field) {
		var btn = document.createElement('button');
		btn.type = 'button';
		btn.className = 'navigation';
		btn.textContent = T('Choose from Media');
		var preview = document.createElement('img');
		preview.className = 'image-preview';
		preview.alt = '';
		function show() { preview.hidden = field.value.trim() === ''; if (!preview.hidden) { preview.src = field.value; } }
		field.after(btn, preview);
		btn.addEventListener('click', function () { pickImage(function (o) { field.value = o.url; show(); field.dispatchEvent(new Event('input', { bubbles: true })); }); });
		field.addEventListener('change', show);
		preview.addEventListener('error', function () { preview.hidden = true; });
		show();
	});

	/* ---------- a file field of a collection (2.11): any file from Media, shown by its name ---------- */

	document.querySelectorAll('[data-file]').forEach(function (field) {
		var btn = document.createElement('button');
		btn.type = 'button';
		btn.className = 'navigation';
		btn.textContent = T('Choose from Media');
		field.after(btn);
		btn.addEventListener('click', function () { pickImage(function (o) { field.value = o.url; field.dispatchEvent(new Event('input', { bubbles: true })); }, false, true); });
	});

	/* ---------- a gallery field of a collection: photo tiles edited right in the item form ---------- */

	// The textarea stays the value the form sends ("path | description" per line); the tiles write it on every change.
	// Photos are uploaded straight into the field (button or drop), reordered by dragging or the arrows, removed with ✕.
	document.querySelectorAll('textarea[data-gallery]').forEach(function (field) {
		function read() {
			return field.value.split(/\r?\n/).map(function (line) {
				var at = line.indexOf('|');
				return { src: (at === -1 ? line : line.slice(0, at)).trim(), alt: at === -1 ? '' : line.slice(at + 1).trim() };
			}).filter(function (p) { return p.src !== ''; });
		}
		var photos = read();
		var grid = document.createElement('div');
		grid.className = 'gallery-field';
		grid.setAttribute('role', 'group');
		if (field.labels && field.labels[0]) { grid.setAttribute('aria-label', field.labels[0].textContent); }
		var input = document.createElement('input');
		input.type = 'file';
		input.accept = 'image/*';
		input.multiple = true;
		input.hidden = true;
		var dragged = null;

		function write() {
			field.value = photos.map(function (p) { return p.src + (p.alt !== '' ? ' | ' + p.alt.replace(/[\r\n|]+/g, ' ') : ''); }).join('\n');
			field.dispatchEvent(new Event('input', { bubbles: true }));
		}
		function move(from, to) {
			if (to < 0 || to >= photos.length || to === from) { return; }
			photos.splice(to, 0, photos.splice(from, 1)[0]);
			write();
			draw();
		}
		function tool(label, glyph, action) {
			var btn = document.createElement('button');
			btn.type = 'button';
			btn.className = 'icon-btn';
			btn.setAttribute('aria-label', T(label));
			btn.title = T(label);
			btn.textContent = glyph;
			btn.addEventListener('click', action);
			return btn;
		}
		function add(files) {
			upload(files).then(function (images) {
				images.forEach(function (o) { photos.push({ src: o.url, alt: (o.name || '').replace(/[\r\n|]+/g, ' ') }); });
				if (images.length) { write(); draw(); }
			});
		}
		function draw() {
			grid.textContent = '';
			photos.forEach(function (p, i) {
				var tile = document.createElement('div');
				tile.className = 'gallery-field-photo';
				tile.draggable = true;
				var img = document.createElement('img');
				img.src = p.src;
				img.alt = '';
				img.loading = 'lazy';
				var number = document.createElement('span');
				number.className = 'gallery-field-number';
				number.textContent = String(i + 1);
				var tools = document.createElement('span');
				tools.className = 'gallery-field-tools';
				tools.append(tool('Move forward', '‹', function () { move(i, i - 1); }), tool('Move back', '›', function () { move(i, i + 1); }),
					tool('Remove photo', '✕', function () { photos.splice(i, 1); write(); draw(); }));
				var alt = document.createElement('input');
				alt.className = 'textfield';
				alt.value = p.alt;
				alt.maxLength = 300;
				alt.placeholder = T('Description');
				alt.setAttribute('aria-label', T('Description of photo %s').replace('%s', String(i + 1)));
				alt.addEventListener('input', function () { p.alt = alt.value; write(); });
				tile.append(img, number, tools, alt);
				tile.addEventListener('dragstart', function (e) { dragged = i; e.dataTransfer.effectAllowed = 'move'; tile.classList.add('dragging'); });
				tile.addEventListener('dragend', function () { dragged = null; tile.classList.remove('dragging'); });
				tile.addEventListener('dragover', function (e) { if (dragged !== null) { e.preventDefault(); } });
				tile.addEventListener('drop', function (e) { if (dragged !== null) { e.preventDefault(); e.stopPropagation(); move(dragged, i); } });
				grid.appendChild(tile);
			});
			var drop = document.createElement('button');
			drop.type = 'button';
			drop.className = 'gallery-field-add';
			drop.innerHTML = '<strong></strong><span></span>';
			drop.firstChild.textContent = T('Upload photos');
			drop.lastChild.textContent = T('or drop them here');
			drop.addEventListener('click', function () { input.click(); });
			grid.appendChild(drop);
		}
		input.addEventListener('change', function () { if (input.files.length) { add(input.files); } input.value = ''; });
		grid.addEventListener('dragover', function (e) { if (dragged === null && hasImages(e.dataTransfer)) { e.preventDefault(); grid.classList.add('editor-drag-over'); } });
		grid.addEventListener('dragleave', function () { grid.classList.remove('editor-drag-over'); });
		grid.addEventListener('drop', function (e) {
			grid.classList.remove('editor-drag-over');
			if (dragged === null && hasImages(e.dataTransfer)) { e.preventDefault(); add(e.dataTransfer.files); }
		});
		// a restored browser draft writes the textarea: the tiles follow it
		field.addEventListener('change', function () { photos = read(); draw(); });
		field.hidden = true;
		var help = field.parentNode.querySelector('.gallery-help'); // the line format is for a browser without the tiles
		if (help) { help.hidden = true; }
		field.after(grid, input);
		draw();
	});

	/* ---------- uploading by dragging on the gallery page ---------- */

	document.querySelectorAll('[data-upload]').forEach(function (form) {
		var inputEl = form.querySelector('input[type=file]');
		form.addEventListener('dragover', function (e) { e.preventDefault(); form.classList.add('upload-active'); });
		form.addEventListener('dragleave', function () { form.classList.remove('upload-active'); });
		form.addEventListener('drop', function (e) {
			e.preventDefault();
			form.classList.remove('upload-active');
			if (e.dataTransfer.files.length) { inputEl.files = e.dataTransfer.files; form.requestSubmit(); }
		});
		// before submitting, scale photos down and set aside files over the server limit (form.submit() does not trigger this handler again)
		form.addEventListener('submit', function (e) {
			if (!window.DataTransfer) { return; }
			e.preventDefault();
			var button = form.querySelector('[type=submit]');
			if (button) { button.disabled = true; }
			prepareFiles(inputEl.files).then(function (files) {
				if (button) { button.disabled = false; }
				if (!files.length) { inputEl.value = ''; return; }
				var transfer = new DataTransfer();
				files.forEach(function (s) { transfer.items.add(s); });
				inputEl.files = transfer.files;
				form.submit();
			});
		});
	});

	window.taleaCreateEditor = createEditor; // the page builder creates the editor itself over a dynamic field
	window.taleaPickImage = pickImage; // picking an image from Media for the builder (the callback gets {url, name, …})

	var editors = Array.prototype.map.call(document.querySelectorAll('textarea[data-editor]'), createEditor);
	var draftForm = document.querySelector('form[data-draft]');
	if (draftForm) { autosave(draftForm, editors); }
})();
