/* Talea - admin odds and ends. No libraries, no build step. */

(function () {
	'use strict';

	// translation of admin script texts: the window.TALEA_TRANSLATIONS dictionary comes from image/languages/admin-<code>.js (Czech too); English is the source and has none
	window.T = function (s) { return (window.TALEA_TRANSLATIONS || {})[s] || s; };
	var T = window.T;

	// date and time like date() in PHP, in the site's time zone (<html data-timezone>): Czech 25. 9. 2026 09:31, English 25 Sep 2026 09:31
	window.taleaTime = function (time, timeOnly) {
		var c = {};
		var format = function (timeZone) {
			new Intl.DateTimeFormat('en-GB', { timeZone: timeZone, year: 'numeric', month: 'numeric', day: 'numeric', hour: '2-digit', minute: '2-digit', hourCycle: 'h23' })
				.formatToParts(new Date(time)).forEach(function (p) { c[p.type] = p.value; });
		};
		try { format(document.documentElement.getAttribute('data-timezone') || undefined); } catch (e) { format(undefined); }
		var clock = c.hour + ':' + c.minute;
		if (timeOnly) { return clock; }
		var months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
		return (document.documentElement.lang === 'en' ? c.day + ' ' + months[c.month - 1] + ' ' + c.year : c.day + '. ' + c.month + '. ' + c.year) + ' ' + clock;
	};

	// Select all (2.14): a checkbox with data-select-all="<form id>" ticks every row checkbox that belongs to that form.
	document.addEventListener('change', function (e) {
		var all = e.target;
		if (!all.hasAttribute || !all.hasAttribute('data-select-all')) { return; }
		document.querySelectorAll('input[type="checkbox"][form="' + all.getAttribute('data-select-all') + '"]').forEach(function (box) { box.checked = all.checked; });
	});

	// Search over cards (3.3, Blueprints): input[data-filter-cards="#container"] hides the [data-filter-card] whose text does not
	// contain every word typed, a [data-filter-group] without a visible card, and shows [data-filter-empty] when nothing is left.
	document.addEventListener('input', function (e) {
		var input = e.target;
		if (!input.hasAttribute || !input.hasAttribute('data-filter-cards')) { return; }
		var box = document.querySelector(input.getAttribute('data-filter-cards'));
		if (!box) { return; }
		var words = input.value.toLocaleLowerCase().split(/\s+/).filter(Boolean), any = false;
		box.querySelectorAll('[data-filter-card]').forEach(function (card) {
			var text = card.textContent.toLocaleLowerCase();
			card.hidden = !words.every(function (w) { return text.indexOf(w) !== -1; });
			any = any || !card.hidden;
		});
		box.querySelectorAll('[data-filter-group]').forEach(function (group) { group.hidden = !group.querySelector('[data-filter-card]:not([hidden])'); });
		var empty = box.querySelector('[data-filter-empty]');
		if (empty) { empty.hidden = any; }
	});

	// Confirmation of irreversible actions: data-confirm="text" on a form or a button.
	// A custom dialog instead of window.confirm(), which embedded browsers (e.g. in apps) silently suppress.
	var confirmDialog = null;
	document.addEventListener('submit', function (e) {
		var form = e.target, button = e.submitter;
		var text = (button && button.getAttribute('data-confirm')) || form.getAttribute('data-confirm');
		if (!text || form.confirmed) { return; }
		e.preventDefault();
		if (!confirmDialog) {
			confirmDialog = document.createElement('dialog');
			confirmDialog.className = 'confirmation';
			confirmDialog.innerHTML = '<p></p><div><button type="button" class="btn" data-yes>' + T('Yes, do it') + '</button> <button type="button" class="navigation" data-no>' + T('Cancel') + '</button></div>';
			document.body.appendChild(confirmDialog);
			confirmDialog.querySelector('[data-no]').addEventListener('click', function () { confirmDialog.close(); });
		}
		confirmDialog.querySelector('p').textContent = text;
		// the confirming button says what it does ("Delete", "Disconnect") and is red for a dangerous action (3.1.1)
		var yes = confirmDialog.querySelector('[data-yes]'), label = button && button.tagName === 'BUTTON' ? button.textContent.trim() : '';
		yes.textContent = label !== '' && label.length <= 40 ? label : T('Yes, do it');
		yes.classList.toggle('btn-danger', !!(button && button.classList.contains('danger')));
		confirmDialog.querySelector('[data-yes]').onclick = function () {
			confirmDialog.close();
			form.confirmed = true;
			if (form.requestSubmit) { form.requestSubmit(button || undefined); } else { form.submit(); }
			form.confirmed = false;
		};
		confirmDialog.showModal();
		confirmDialog.querySelector('[data-no]').focus();
	});

	// Light / dark mode (the default follows the system, the choice is remembered in the browser; theme.js sets it before rendering)
	var schemeButton = document.querySelector('[data-theme-switch]');
	if (schemeButton) {
		schemeButton.addEventListener('click', function () {
			var root = document.documentElement;
			var dark = root.getAttribute('data-theme') ? root.getAttribute('data-theme') === 'dark' : window.matchMedia('(prefers-color-scheme: dark)').matches;
			root.setAttribute('data-theme', dark ? 'light' : 'dark');
			try { localStorage.setItem('talea-theme', dark ? 'light' : 'dark'); } catch (e) { /* nothing */ }
		});
	}

	// Expanding the menu on mobile
	var toggle = document.querySelector('.menu-switch');
	if (toggle) {
		toggle.addEventListener('click', function () {
			var openItems = document.body.classList.toggle('menu-open');
			toggle.setAttribute('aria-expanded', openItems ? 'true' : 'false');
		});
		// the open menu covers the page on a phone (2.17): Esc closes it and gives the focus back to the button
		document.addEventListener('keydown', function (e) {
			if (e.key === 'Escape' && document.body.classList.contains('menu-open')) {
				document.body.classList.remove('menu-open');
				toggle.setAttribute('aria-expanded', 'false');
				toggle.focus();
			}
		});
	}

	// A tab bar that scrolls sideways on a phone (2.17, image/editor.css): the active tab is scrolled into view, and with
	// in-page tabs the newly chosen one follows
	function showActiveTab(bar) {
		var active = bar.querySelector('a.active, [role="tab"][aria-selected="true"]');
		if (active && bar.scrollWidth > bar.clientWidth) {
			var a = active.getBoundingClientRect(), b = bar.getBoundingClientRect();
			bar.scrollLeft += (a.left - b.left) - (b.width - a.width) / 2;
		}
	}
	function markEdges(bar) {
		bar.classList.toggle('moved', bar.scrollLeft > 2);
		bar.classList.toggle('at-end', bar.scrollLeft > 2 && bar.scrollLeft + bar.clientWidth >= bar.scrollWidth - 2);
	}
	document.querySelectorAll('.tabs').forEach(function (bar) {
		showActiveTab(bar);
		markEdges(bar);
		bar.addEventListener('scroll', function () { markEdges(bar); }, { passive: true });
		bar.addEventListener('click', function () { setTimeout(function () { showActiveTab(bar); }, 0); });
	});

	// Tabs within one page ("Vzhled webu", Site appearance): arrows, Home and End; after saving the last tab comes back; a field that
	// fails the browser's validation shows its tab. Without the script all panels are visible one below another.
	document.querySelectorAll('[data-tabs]').forEach(function (wrapper) {
		var buttons = Array.prototype.slice.call(wrapper.querySelectorAll('[role="tab"]'));
		var panels = buttons.map(function (t) { return document.getElementById(t.getAttribute('aria-controls')); });
		var shouldSave = wrapper.querySelector('.appearance-save');
		var key = 'tl-tab' + location.search;
		var show = function (i, focusTarget) {
			buttons.forEach(function (t, j) {
				t.setAttribute('aria-selected', i === j ? 'true' : 'false');
				t.tabIndex = i === j ? 0 : -1;
				if (panels[j]) { panels[j].hidden = i !== j; }
			});
			if (shouldSave) { shouldSave.hidden = !wrapper.querySelector('.appearance-form').contains(panels[i]); } // import and export have their own buttons
			if (focusTarget) { buttons[i].focus(); }
			try { sessionStorage.setItem(key, buttons[i].id); } catch (error) { /* private mode */ }
		};
		buttons.forEach(function (t, i) {
			t.addEventListener('click', function () { show(i, false); });
			t.addEventListener('keydown', function (e) {
				var target = { ArrowRight: i + 1, ArrowLeft: i - 1, Home: 0, End: buttons.length - 1 }[e.key];
				if (target === undefined) { return; }
				e.preventDefault();
				show((target + buttons.length) % buttons.length, true);
			});
		});
		wrapper.addEventListener('invalid', function (e) {
			var i = panels.indexOf(e.target.closest('[role="tabpanel"]'));
			if (i >= 0) { show(i, false); }
		}, true);
		var storedValue = null;
		try { storedValue = sessionStorage.getItem(key); } catch (error) { /* private mode */ }
		show(Math.max(0, buttons.findIndex(function (t) { return t.id === storedValue; })), false);
	});

	// "Vzhled webu" (Site appearance): presets and a live preview of the real home page. The token CSS is computed by the server (action nahled) – the single computation in PHP.
	var appearance = document.querySelector('[data-appearance]');
	if (appearance) {
		var preview = document.querySelector('[data-preview]'), frame2 = document.querySelector('[data-frame]');
		var device = 'desktop', timer = null, lastCss = '';
		var insertCss = function () {
			var doc = preview.contentDocument;
			if (!doc || !doc.head || lastCss === '') { return; }
			var style = doc.getElementById('tl-appearance-preview');
			if (!style) { style = doc.createElement('style'); style.id = 'tl-appearance-preview'; doc.head.appendChild(style); }
			style.textContent = lastCss;
		};
		var recalculate = function () {
			var data = new FormData(appearance);
			fetch(appearance.getAttribute('data-preview-url'), { method: 'POST', body: data, credentials: 'same-origin' })
				.then(function (r) { return r.json(); })
				.then(function (j) {
					lastCss = j.css;
					insertCss();
					appearance.querySelector('[data-contrasts]').innerHTML = j.contrasts.map(function (k) {
						var li = document.createElement('li');
						li.className = k.ok ? 'ok' : 'wrong';
						li.innerHTML = '<span></span><strong></strong>';
						li.firstChild.textContent = k.description;
						li.lastChild.textContent = T('%s:1').replace('%s', k.ratio.toLocaleString(document.documentElement.lang || 'cs', { minimumFractionDigits: 1, maximumFractionDigits: 1 }));
						return li.outerHTML;
					}).join('');
				})
				.catch(function () {});
		};
		// samples under the font pickers show the chosen families (their @font-face is on the page, a file loads only when used)
		var showFontSamples = function () {
			appearance.querySelectorAll('[data-font-sample]').forEach(function (sample) {
				var select = appearance.elements[sample.getAttribute('data-font-sample')];
				var option = select && select.options[select.selectedIndex];
				if (option) { sample.style.fontFamily = option.getAttribute('data-family'); }
			});
		};
		var change = function (e) {
			showFontSamples();
			if (e && e.target && e.target.type === 'color') { e.target.parentNode.querySelector('[data-hex]').textContent = e.target.value; }
			appearance.querySelector('[data-unsaved]').hidden = false;
			clearTimeout(timer);
			timer = setTimeout(recalculate, 180);
		};
		appearance.addEventListener('input', change);
		appearance.addEventListener('change', change);
		preview.addEventListener('load', insertCss);
		// a preset fills in the form (sizes are in px in the form, in rem in the design system)
		appearance.querySelectorAll('[data-preset]').forEach(function (btn) {
			btn.addEventListener('click', function () {
				var ds = JSON.parse(btn.getAttribute('data-preset'));
				Object.keys(ds).forEach(function (key) {
					if (typeof ds[key] === 'object') {
						Object.keys(ds[key]).forEach(function (b) {
							var field = appearance.elements['ds[' + key + '][' + b + ']'];
							if (field) { field.value = ds[key][b]; field.parentNode.querySelector('[data-hex]').textContent = ds[key][b]; }
						});
						return;
					}
					var field = appearance.elements['ds[' + key + ']'];
					if (!field) { return; }
					var value = ['base_min', 'base_max', 'width', 'text_width'].indexOf(key) !== -1 ? Math.round(ds[key] * 16) : ds[key];
					if (field instanceof RadioNodeList) { field.value = String(value); return; }
					if (field.tagName === 'SELECT') {
						Array.prototype.forEach.call(field.options, function (o) { if (Math.abs(parseFloat(o.value) - value) < 0.001 || o.value === String(value)) { field.value = o.value; } });
						return;
					}
					field.value = value;
				});
				// like a manual change: recomputes the preview and the form will guard against leaving without saving
				appearance.dispatchEvent(new Event('input', { bubbles: true }));
			});
		});
		// desktop renders at 1280 px wide and is scaled down into the frame, so the site's real breakpoints apply
		var dimension = function () {
			var width = device === 'mobile' ? 390 : 1280, available = frame2.clientWidth, scale = Math.min(1, available / width);
			preview.style.width = width + 'px';
			preview.style.height = (frame2.clientHeight / scale) + 'px';
			preview.style.transform = 'scale(' + scale + ')';
			preview.style.left = Math.max(0, (available - width * scale) / 2) + 'px';
		};
		document.querySelectorAll('[data-device]').forEach(function (btn) {
			btn.addEventListener('click', function () {
				device = btn.getAttribute('data-device');
				document.querySelectorAll('[data-device]').forEach(function (b) { b.setAttribute('aria-pressed', b === btn ? 'true' : 'false'); });
				dimension();
			});
		});
		if (window.ResizeObserver) { new ResizeObserver(dimension).observe(frame2); }
		dimension();
	}

	// General: an option with data-toggle="name:1" shows (or :0 hides) the form part marked data-section="name"
	document.querySelectorAll('[data-toggle]').forEach(function (choice) {
		choice.addEventListener('change', function () {
			var p = choice.getAttribute('data-toggle').split(':');
			document.querySelectorAll('[data-section="' + p[0] + '"]').forEach(function (s) { s.hidden = p[1] !== '1'; });
		});
	});

	// Settings → Mail: choosing a mail service fills the SMTP server, port and encryption from its option and shows its hint
	// (what the user name and the password are); the user name and the password fields are never touched
	document.querySelectorAll('select[data-smtp-service]').forEach(function (service) {
		var form = service.form, region = form.querySelector('select[data-smtp-region]');
		var fill = function () {
			var option = service.options[service.selectedIndex];
			if (!option || !option.hasAttribute('data-host')) { return; }
			var host = option.getAttribute('data-host');
			if (service.value === 'ses' && region) { host = host.replace(/^email-smtp\.[^.]+\./, 'email-smtp.' + region.value + '.'); }
			form.querySelector('#smtp_host').value = host;
			form.querySelector('#smtp_port').value = option.getAttribute('data-port');
			form.querySelector('#smtp_encryption').value = option.getAttribute('data-encryption');
		};
		service.addEventListener('change', function () {
			form.querySelectorAll('[data-smtp-tip]').forEach(function (tip) { tip.hidden = tip.getAttribute('data-smtp-tip') !== service.value; });
			fill();
		});
		if (region) { region.addEventListener('change', fill); }
	});

	// General: a form with data-switch="field" shows only rows whose data-for contains the selected value of the field
	document.querySelectorAll('form[data-switch]').forEach(function (form) {
		var fieldName = form.getAttribute('data-switch');
		var switchTo = function () {
			var chosen = form.querySelector('[name="' + fieldName + '"]:checked') || form.querySelector('select[name="' + fieldName + '"]');
			form.querySelectorAll('[data-for]').forEach(function (row) { row.hidden = !chosen || row.getAttribute('data-for').split(' ').indexOf(chosen.value) === -1; });
		};
		form.addEventListener('change', function (e) { if (e.target.name === fieldName) { switchTo(); } });
		switchTo();
	});

	// A listing as cards on a phone: a cell gets the column label from the header (CSS shows it only in a narrow window). The table
	// roles are added explicitly – otherwise browsers drop them with display: block and a screen reader would lose the columns.
	document.querySelectorAll('table.listing').forEach(function (tab) {
		if (!tab.tHead || !tab.tHead.rows.length) { return; }
		var headers = Array.prototype.map.call(tab.tHead.rows[0].cells, function (th) { th.setAttribute('role', 'columnheader'); return th.textContent.trim(); });
		tab.classList.add('listing-cards');
		tab.setAttribute('role', 'table');
		Array.prototype.forEach.call(tab.querySelectorAll('thead, tbody'), function (group) { group.setAttribute('role', 'rowgroup'); });
		Array.prototype.forEach.call(tab.rows, function (tr) { tr.setAttribute('role', 'row'); });
		Array.prototype.forEach.call(tab.tBodies, function (body) {
			Array.prototype.forEach.call(body.rows, function (tr) {
				Array.prototype.forEach.call(tr.cells, function (td, i) {
					td.setAttribute('role', td.tagName === 'TH' ? 'rowheader' : 'cell');
					if (headers[i]) { td.setAttribute('data-caption', headers[i]); }
				});
			});
		});
	});

	// Warning before leaving a form with unsaved changes
	document.querySelectorAll('form.form').forEach(function (form) {
		var changed = false;
		form.addEventListener('input', function () { changed = true; });
		form.addEventListener('change', function () { changed = true; });
		form.addEventListener('submit', function () { changed = false; });
		window.addEventListener('beforeunload', function (e) {
			if (changed) { e.preventDefault(); e.returnValue = ''; }
		});
	});
	/* ---------- small handlers instead of inline scripts (the admin has a Content-Security-Policy without 'unsafe-inline') ---------- */

	// data-active-when="field=value": fields inside the block are enabled only when the form field has the given value
	// (the number of days only for the frequency "once every N days", the page selection only for
	// "only in selected places")
	var dependent = document.querySelectorAll('[data-active-when]');
	var refreshDependent = function () {
		dependent.forEach(function (block) {
			var condition = block.getAttribute('data-active-when').split('=');
			var field = block.closest('form') && block.closest('form').elements[condition[0]];
			// checkbox: a value only when it is checked ("visible=" = unchecked)
			var value = field && field.type === 'checkbox' ? (field.checked ? field.value : '') : (field ? field.value : '');
			var isEnabled = !field || value === condition[1];
			block.querySelectorAll('input, select, textarea').forEach(function (i) { i.disabled = !isEnabled; });
			block.classList.toggle('inactive', !isEnabled);
		});
	};
	if (dependent.length) { document.addEventListener('change', refreshDependent); refreshDependent(); }

	// the header of a numeric column is aligned like the numbers below it (td.cislo cells in the first row)
	document.querySelectorAll('table.listing').forEach(function (table) {
		var row = table.tBodies[0] && table.tBodies[0].rows[0];
		var header = table.tHead && table.tHead.rows[0];
		if (!row || !header || row.cells.length !== header.cells.length) { return; }
		Array.prototype.forEach.call(row.cells, function (cell, i) { if (cell.classList.contains('number')) { header.cells[i].classList.add('number'); } });
	});

	document.addEventListener('change', function (e) {
		var element = e.target;
		if (element.hasAttribute && element.hasAttribute('data-submit-on-change') && element.form) { element.form.submit(); }
		if (element.hasAttribute && element.hasAttribute('data-show-password')) {
			var password = document.getElementById(element.getAttribute('data-show-password'));
			if (password) { password.type = element.checked ? 'text' : 'password'; }
		}
	});
	// E-mail signature of a person (Collections, 2.10): the button copies the formatted signature and its plain text together,
	// so the mail client pastes the rich one; without the Clipboard API the preview is selected and copied the old way
	var signatureButton = document.querySelector('[data-copy-signature]');
	if (signatureButton) {
		signatureButton.addEventListener('click', function () {
			var preview = document.querySelector('[data-signature-preview]');
			var plain = document.querySelector('[data-signature-text]');
			var html = preview.innerHTML, text = plain ? plain.value : preview.innerText;
			var done = function () { signatureButton.textContent = T('Copied'); };
			var select = function () {
				var range = document.createRange(); range.selectNodeContents(preview);
				var selection = window.getSelection(); selection.removeAllRanges(); selection.addRange(range);
				var copied = false;
				try { copied = document.execCommand('copy'); } catch (e) { /* nothing */ }
				if (copied) { selection.removeAllRanges(); done(); } else { signatureButton.textContent = T('Selected – press Ctrl+C (⌘C) to copy'); }
			};
			if (navigator.clipboard && window.ClipboardItem) {
				navigator.clipboard.write([new ClipboardItem({ 'text/html': new Blob([html], { type: 'text/html' }), 'text/plain': new Blob([text], { type: 'text/plain' }) })]).then(done, select);
			} else { select(); }
		});
	}
	// an address to hand over (the screen mode address, 2.11): a button with data-copy="#id" copies the text of that element;
	// for a text field (a social post draft, 2.13) its current value, edits included
	document.querySelectorAll('[data-copy]').forEach(function (button) {
		button.addEventListener('click', function () {
			var source = document.querySelector(button.getAttribute('data-copy'));
			if (!source) { return; }
			var text = (/^(TEXTAREA|INPUT)$/.test(source.tagName) ? source.value : source.textContent).trim();
			var done = function () { button.textContent = T('Copied'); };
			var select = function () { // without the Clipboard API the address is selected for Ctrl+C
				var range = document.createRange(); range.selectNodeContents(source);
				var selection = window.getSelection(); selection.removeAllRanges(); selection.addRange(range);
				button.textContent = T('Selected – press Ctrl+C (⌘C) to copy');
			};
			if (navigator.clipboard && navigator.clipboard.writeText) { navigator.clipboard.writeText(text).then(done, select); } else { select(); }
		});
	});
	// a schedule shows only the day field of its cadence (3.1.1): the weekday for weekly, the day of the month for monthly
	var cadence = document.getElementById('cadence');
	if (cadence) {
		var showDays = function () { document.querySelectorAll('[data-kadence]').forEach(function (row) { row.hidden = row.getAttribute('data-kadence') !== cadence.value; }); };
		cadence.addEventListener('change', showDays); showDays();
	}
	// the sidebar keeps the current section in view (3.1.1): on a short screen the lower groups are below the fold
	var sidebar = document.querySelector('.header'), activeItem = document.querySelector('.menu li.active');
	if (sidebar && activeItem && sidebar.scrollHeight > sidebar.clientHeight && getComputedStyle(sidebar).overflowY === 'auto') {
		var below = activeItem.getBoundingClientRect().bottom - sidebar.getBoundingClientRect().bottom;
		if (below > 0) { sidebar.scrollTop += below + 48; }
	}
	// "Ask Claude" on the dashboard (3.1): an example request fills the box (to be changed before sending); "Copy for the
	// Claude app" copies the text with the site's address and lets the link open Claude in a new tab
	var askText = document.getElementById('ask-claude-text');
	if (askText) {
		document.querySelectorAll('[data-ask-claude-example]').forEach(function (example) {
			example.addEventListener('click', function () {
				askText.value = example.textContent.trim();
				askText.focus();
				askText.setSelectionRange(askText.value.length, askText.value.length);
			});
		});
		var askCopy = document.querySelector('[data-ask-claude-copy]');
		if (askCopy && navigator.clipboard && navigator.clipboard.writeText) {
			askCopy.addEventListener('click', function () {
				if (askText.value.trim() === '') { return; }
				navigator.clipboard.writeText(askCopy.getAttribute('data-prompt').replace('{text}', askText.value.trim())).then(function () {
					askCopy.textContent = askCopy.getAttribute('data-copied');
				}, function () { /* the link still opens Claude */ });
			});
		}
	}
	// imports in batches: the progress form submits itself (each submission is one batch) until the work is done
	var autoSubmit = document.querySelector('form[data-auto-submit]');
	if (autoSubmit) { setTimeout(function () { autoSubmit.requestSubmit ? autoSubmit.requestSubmit() : autoSubmit.submit(); }, parseInt(autoSubmit.getAttribute('data-auto-submit'), 10) || 1200); }
	/* ---------- command palette: Ctrl/⌘+K – sections, quick actions and news item search ---------- */

	var palette = document.getElementById('palette');
	if (palette && typeof palette.showModal === 'function') {
		var paletteField = palette.querySelector('.palette-field');
		var paletteList = palette.querySelector('.palette-list');
		var paletteCommands = [];
		try { paletteCommands = JSON.parse(document.getElementById('palette-data').textContent) || []; } catch (e) {}
		var paletteNews = [];
		var paletteSelected = 0;
		var paletteTimer = null;
		var removeDiacritics = function (t) { return String(t).toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, ''); };

		var paletteItems = function () {
			var q = removeDiacritics(paletteField.value.trim());
			var words = q.split(/\s+/).filter(Boolean);
			var matching = paletteCommands.filter(function (p) {
				var haystack = removeDiacritics(p.n + ' ' + p.s);
				return words.every(function (s) { return haystack.indexOf(s) !== -1; });
			});
			return (q === '' ? matching.slice(0, 9) : matching.slice(0, 7)).concat(q === '' ? [] : paletteNews);
		};
		var paletteRender = function () {
			var items = paletteItems();
			paletteSelected = Math.max(0, Math.min(paletteSelected, items.length - 1));
			paletteList.textContent = '';
			items.forEach(function (p, i) {
				var li = document.createElement('li');
				li.setAttribute('role', 'option');
				li.setAttribute('aria-selected', i === paletteSelected ? 'true' : 'false');
				var a = document.createElement('a');
				a.href = p.u;
				a.textContent = p.n;
				var s = document.createElement('small');
				s.textContent = p.s;
				a.appendChild(s);
				li.appendChild(a);
				li.addEventListener('mousemove', function () { if (paletteSelected !== i) { paletteSelected = i; paletteRender(); } });
				paletteList.appendChild(li);
			});
			if (items.length === 0) {
				var nothing = document.createElement('li');
				nothing.className = 'palette-none';
				nothing.textContent = T('Nothing like that here.');
				paletteList.appendChild(nothing);
			}
			var selected = paletteList.querySelector('[aria-selected="true"]');
			if (selected && selected.scrollIntoView) { selected.scrollIntoView({ block: 'nearest' }); }
		};
		var paletteOpen = function () {
			if (palette.open) { return; }
			paletteField.value = '';
			paletteNews = [];
			paletteSelected = 0;
			paletteRender();
			palette.showModal();
			paletteField.focus();
		};

		document.addEventListener('keydown', function (e) {
			if ((e.ctrlKey || e.metaKey) && !e.altKey && (e.key === 'k' || e.key === 'K')) {
				e.preventDefault();
				if (palette.open) { palette.close(); } else { paletteOpen(); }
			}
		});
		document.addEventListener('click', function (e) {
			if (e.target.closest && e.target.closest('[data-palette]')) { paletteOpen(); }
			if (e.target === palette) { palette.close(); } // click outside the dialog
		});
		paletteField.addEventListener('input', function () {
			paletteSelected = 0;
			paletteRender();
			clearTimeout(paletteTimer);
			var q = paletteField.value.trim();
			var address = palette.getAttribute('data-articles');
			if (!address || q.length < 2) { paletteNews = []; return; }
			paletteTimer = setTimeout(function () {
				fetch(address + '&q=' + encodeURIComponent(q), { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (d) {
					if (paletteField.value.trim() !== q) { return; } // typing continued in the meantime
					paletteNews = (d.articles || []).map(function (c) { return { n: c.title, u: c.url, s: c.published ? T('news item') : T('news item – unpublished') }; });
					paletteRender();
				}).catch(function () {});
			}, 200);
		});
		paletteField.addEventListener('keydown', function (e) {
			var count = paletteItems().length;
			if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
				e.preventDefault();
				paletteSelected = count === 0 ? 0 : (paletteSelected + (e.key === 'ArrowDown' ? 1 : count - 1)) % count;
				paletteRender();
			} else if (e.key === 'Enter') {
				e.preventDefault();
				var target = paletteList.querySelector('[aria-selected="true"] a');
				if (target) { window.location.href = target.href; }
			}
		});
		// show ⌘K on a Mac
		if (/Mac|iPhone|iPad/.test(navigator.platform || '')) {
			Array.prototype.forEach.call(document.querySelectorAll('[data-palette] kbd'), function (k) { k.textContent = '⌘K'; });
		}
	}
	// Media: crop focal point – a tap in the preview sets both fields (in percent)
	document.querySelectorAll('[data-focal]').forEach(function (box) {
		var formEl = box.closest('form');
		box.addEventListener('click', function (e) {
			var r = box.getBoundingClientRect();
			var x = Math.round((e.clientX - r.left) / r.width * 100);
			var y = Math.round((e.clientY - r.top) / r.height * 100);
			formEl.elements.ohnisko_x.value = x;
			formEl.elements.ohnisko_y.value = y;
			box.querySelector('.focal-point').style.left = x + '%';
			box.querySelector('.focal-point').style.top = y + '%';
		});
	});
	// Media: image description (alt) right in the grid – saved on leaving the field, without reloading the page
	document.querySelectorAll('[data-description-media]').forEach(function (field) {
		var previous = field.value;
		var token = document.querySelector('input[name="_csrf"]');
		field.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); field.blur(); } });
		field.addEventListener('change', function () {
			var data = new FormData();
			data.append('_csrf', token ? token.value : '');
			data.append('media_id', field.getAttribute('data-description-media'));
			data.append('name', field.value);
			field.classList.remove('saved', 'error');
			fetch(field.getAttribute('data-address'), { method: 'POST', body: data, credentials: 'same-origin' })
				.then(function (r) { return r.json(); })
				.then(function (j) { if (!j.ok) { throw new Error(j.error); } previous = field.value; field.classList.add('saved'); })
				.catch(function () { field.value = previous; field.classList.add('error'); });
		});
	});
	// the sign-in is kept alive while the admin is open (otherwise after inactivity a form submit would fail and unsaved text would be lost)
	if (document.querySelector('form[method="post"]')) {
		setInterval(function () {
			if (document.visibilityState === 'visible') { fetch('admin.php?action=token', { credentials: 'same-origin' }).catch(function () { /* offline: nothing */ }); }
		}, 10 * 60 * 1000);
	}
	// a success message confirmed the save: unsaved copies of submitted forms (image/editor.js) are no longer needed
	if (document.querySelector('.notice-ok')) {
		try {
			Object.keys(localStorage).filter(function (k) { return k.indexOf('talea-draft:') === 0; }).forEach(function (k) {
				var d = JSON.parse(localStorage.getItem(k) || 'null');
				if (d && d.submitted && Date.now() - d.submitted < 15 * 60 * 1000) { localStorage.removeItem(k); }
			});
		} catch (e) { /* storage unavailable */ }
	}

	// tooltips of charts and figures (data-tip): immediately on mouse hover, on keyboard focus and after a tap on a touch screen
	var tip = null, tipTarget = null;
	function showTip(el) {
		tipTarget = el;
		if (!tip) {
			tip = document.createElement('div');
			tip.className = 'tip';
			tip.setAttribute('role', 'tooltip');
			document.body.appendChild(tip);
		}
		tip.textContent = el.getAttribute('data-tip');
		tip.hidden = false;
		var r = (el.querySelector('[data-tip-anchor]') || el).getBoundingClientRect(); // chart bar: the tooltip above its height
		var x = Math.min(Math.max(r.left + r.width / 2, tip.offsetWidth / 2 + 8), window.innerWidth - tip.offsetWidth / 2 - 8);
		tip.style.left = x + 'px';
		tip.style.top = Math.max(r.top - 8, tip.offsetHeight + 8) + 'px';
	}
	function hideTip() { tipTarget = null; if (tip) { tip.hidden = true; } }
	document.addEventListener('pointerover', function (e) { var el = e.target.closest && e.target.closest('[data-tip]'); if (el) { showTip(el); } });
	document.addEventListener('pointerout', function (e) { var el = e.target.closest && e.target.closest('[data-tip]'); if (el && !el.contains(e.relatedTarget)) { hideTip(); } });
	document.addEventListener('focusin', function (e) { var el = e.target.closest && e.target.closest('[data-tip]'); if (el) { showTip(el); } });
	document.addEventListener('focusout', hideTip);
	window.addEventListener('scroll', function () { if (tipTarget) { showTip(tipTarget); } }, { passive: true }); // when the page scrolls, the tooltip moves with the element
})();
