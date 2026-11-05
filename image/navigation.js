/* Talea - admin navigation: the recently opened screens and the way back, the quick search (Ctrl/⌘+K), the bell, the site picker and
 * the keyboard shortcuts (views/admin/layout.php). Loaded after admin.js, whose window.T translates. No libraries, no build step. */

(function () {
	'use strict';

	var T = window.T;

	/* ---------- where you were: the recently opened screens (in the palette) and the way back out of Settings, Website mode and My account ---------- */
	var screenName = (document.querySelector('main[data-screen]') || { getAttribute: function () { return ''; } }).getAttribute('data-screen') || '';
	var hereUrl = location.pathname + location.search;
	var storage = function (kind) { try { return window[kind]; } catch (e) { return null; } }; // private mode or blocked site data: no memory, nothing breaks
	var remembered = function (kind, key) { try { return JSON.parse(storage(kind).getItem(key) || 'null'); } catch (e) { return null; } };
	var remember = function (kind, key, value) { try { storage(kind).setItem(key, JSON.stringify(value)); } catch (e) { /* nothing */ } };
	var recentKey = 'talea-recent:' + (document.documentElement.getAttribute('data-admin-url') || '');
	var recentScreens = remembered('localStorage', recentKey);
	recentScreens = Array.isArray(recentScreens) ? recentScreens.filter(function (r) { return r && typeof r.n === 'string' && typeof r.u === 'string'; }) : [];
	var returnLinks = document.querySelectorAll('[data-return]');
	if (screenName !== '' && !/\/new(?:$|\?)/.test(location.pathname)) {
		// an edit screen is remembered by what it edits ("Contact"), the rest by its heading
		var titleField = /\/(edit|item)\//.test(location.pathname) ? document.querySelector('main form input[name="title"], main form input[name="name"]') : null;
		var named = titleField && titleField.value.trim() !== '' ? titleField.value.trim() : '';
		recentScreens = [{ n: named || screenName, u: hereUrl, s: named ? screenName : '' }].concat(recentScreens.filter(function (r) { return r.u !== hereUrl; })).slice(0, 8);
		remember('localStorage', recentKey, recentScreens);
		// Settings, Website mode and My account lead back to the last screen outside them (per browser tab), not always to Home
		if (returnLinks.length === 0) { remember('sessionStorage', 'talea-return', { n: screenName, u: hereUrl }); }
	}
	var returnTo = remembered('sessionStorage', 'talea-return');
	if (returnTo && typeof returnTo.u === 'string' && typeof returnTo.n === 'string' && returnTo.u !== hereUrl && returnTo.u.charAt(0) === '/') {
		returnLinks.forEach(function (link) {
			link.href = returnTo.u;
			var label = link.querySelector('span');
			if (label) { label.textContent = returnTo.n; }
		});
	}

	/* ---------- command palette: Ctrl/⌘+K – recently opened, sections, quick actions, single settings and news item search ---------- */

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
			// what was opened lately comes first with an empty field; while typing it is searched too (an edited news item the list does not carry)
			var recent = recentScreens.filter(function (r) { return r.u !== hereUrl; }).map(function (r) { return { n: r.n, u: r.u, s: r.s !== '' ? T('Recently opened') + ' · ' + r.s : T('Recently opened') }; });
			if (q === '') {
				var latest = recent.slice(0, 5).map(function (r) { return r.u; });
				return recent.slice(0, 5).concat(paletteCommands.filter(function (p) { return latest.indexOf(p.u) === -1; }).slice(0, 9));
			}
			var known = paletteCommands.map(function (p) { return p.u; });
			var matching = paletteCommands.concat(recent.filter(function (r) { return known.indexOf(r.u) === -1; })).filter(function (p) {
				var haystack = removeDiacritics(p.n + ' ' + p.s);
				return words.every(function (s) { return haystack.indexOf(s) !== -1; });
			});
			return matching.slice(0, 9).concat(paletteNews);
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
				if (p.f) { a.setAttribute('data-form', p.f); } // opening another site is a POST: the entry submits the switcher's form
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
		var paletteSubmit = function (link) {
			var form = link.getAttribute('data-form') ? document.getElementById(link.getAttribute('data-form')) : null;
			if (!form) { return false; }
			palette.close();
			form.requestSubmit();
			return true;
		};
		paletteList.addEventListener('click', function (e) {
			var link = e.target.closest && e.target.closest('a[data-form]');
			if (link && paletteSubmit(link)) { e.preventDefault(); }
		});
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
				fetch(address + (address.includes('?') ? '&' : '?') + 'q=' + encodeURIComponent(q), { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (d) {
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
				if (target && !paletteSubmit(target)) { window.location.href = target.href; }
			}
		});
		// show ⌘K on a Mac
		if (/Mac|iPhone|iPad/.test(navigator.platform || '')) {
			Array.prototype.forEach.call(document.querySelectorAll('[data-palette] kbd'), function (k) { k.textContent = '⌘K'; });
		}
	}
	/* ---------- the bell: opening it fetches everything that waits (drafts, comments, Claude's results…), not only what the menu counts ---------- */
	var waitingBox = document.querySelector('[data-waiting]');
	if (waitingBox) {
		var waitingLoaded = 0;
		waitingBox.addEventListener('toggle', function (e) {
			if (e.newState !== 'open' || Date.now() - waitingLoaded < 60000) { return; } // once a minute is fresh enough
			waitingLoaded = Date.now();
			fetch(waitingBox.getAttribute('data-waiting'), { credentials: 'same-origin', headers: { Accept: 'text/html' } }).then(function (r) {
				if (!r.ok) { throw new Error(String(r.status)); }
				return r.text();
			}).then(function (html) {
				var list = waitingBox.querySelector('.waiting-list');
				if (list && html.indexOf('<html') === -1) { list.innerHTML = html; } // signed out meanwhile: the sign-in page is not shown in the popover
			}).catch(function () { waitingLoaded = 0; });
		});
	}

	/* ---------- the site picker (multisite): what waits on each site when it opens, and a filter for many sites ---------- */
	var sitesBox = document.querySelector('[data-site-counts]');
	if (sitesBox) {
		var sitesLoaded = 0;
		sitesBox.addEventListener('toggle', function (e) {
			if (e.newState !== 'open') { return; }
			var filter = sitesBox.querySelector('[data-sites-filter]');
			if (filter) { filter.focus(); }
			if (Date.now() - sitesLoaded < 60000) { return; }
			sitesLoaded = Date.now();
			fetch(sitesBox.getAttribute('data-site-counts'), { credentials: 'same-origin', headers: { Accept: 'application/json' } }).then(function (r) {
				if (!r.ok) { throw new Error(String(r.status)); }
				return r.json();
			}).then(function (counts) {
				sitesBox.querySelectorAll('[data-site-count]').forEach(function (slot) {
					var n = parseInt(counts[slot.getAttribute('data-site-count')], 10) || 0;
					slot.textContent = '';
					if (n > 0) {
						var badge = document.createElement('span');
						badge.className = 'menu-count';
						badge.textContent = n > 99 ? '99+' : String(n);
						badge.title = T('%s waiting').replace('%s', String(n));
						var label = document.createElement('span');
						label.className = 'visually-hidden';
						label.textContent = ' – ' + badge.title;
						slot.appendChild(badge);
						slot.appendChild(label);
					}
				});
			}).catch(function () { sitesLoaded = 0; });
		});
		var sitesFilter = sitesBox.querySelector('[data-sites-filter]');
		if (sitesFilter) {
			var fold = function (text) { return String(text).toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, ''); };
			sitesFilter.addEventListener('input', function () {
				var q = fold(sitesFilter.value.trim());
				sitesBox.querySelectorAll('li').forEach(function (li) {
					li.hidden = q !== '' && fold(li.textContent).indexOf(q) === -1;
				});
			});
		}
	}

	/* ---------- keyboard: / quick search, ? the shortcuts, c the New menu, g and a letter goes to a section ---------- */
	var shortcutsDialog = document.getElementById('shortcuts');
	if (shortcutsDialog && typeof shortcutsDialog.showModal === 'function') {
		var goTo = {};
		try { goTo = JSON.parse(document.getElementById('shortcut-data').textContent) || {}; } catch (e) { /* nothing */ }
		var createMenu = document.getElementById('create');
		var awaitingG = false;
		var gTimer = null;
		document.addEventListener('click', function (e) {
			if (e.target.closest && e.target.closest('[data-shortcuts-open]')) {
				var open = document.querySelector('dialog[open]');
				if (open) { open.close(); }
				shortcutsDialog.showModal();
			}
		});
		document.addEventListener('keydown', function (e) {
			if (e.defaultPrevented || e.ctrlKey || e.metaKey || e.altKey || e.isComposing) { return; }
			var target = e.target;
			if (target.isContentEditable || (target.closest && target.closest('input, textarea, select, [contenteditable]')) || document.querySelector('dialog[open]')) { return; }
			if (awaitingG) {
				awaitingG = false;
				clearTimeout(gTimer);
				var address = goTo[e.key.toLowerCase()];
				if (address) { e.preventDefault(); window.location.href = address; }
				return;
			}
			if (e.key === 'g' || e.key === 'G') {
				awaitingG = true;
				gTimer = setTimeout(function () { awaitingG = false; }, 1500);
			} else if (e.key === '/') {
				var search = document.querySelector('[data-palette]');
				if (search) { e.preventDefault(); search.click(); }
			} else if (e.key === '?') {
				e.preventDefault();
				shortcutsDialog.showModal();
			} else if ((e.key === 'c' || e.key === 'C') && createMenu && typeof createMenu.showPopover === 'function') {
				e.preventDefault();
				createMenu.showPopover();
				var first = createMenu.querySelector('a');
				if (first) { first.focus(); }
			}
		});
	}
})();
