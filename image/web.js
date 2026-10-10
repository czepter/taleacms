/* Talea - site script for visitors. No libraries; everything is an optional enhancement, the site works without JavaScript too.
 * The mobile menu, dialogs and expanding are handled by HTML and CSS (popover, <details>), not by this script. */
(function () {
	'use strict';

	/* ---------- transition between pages (View Transitions are driven only by the template CSS): when the browser interrupts
	   or skips it (fast clicking through, the new page does not allow the transition), that is fine – no unhandled error in the console ---------- */

	window.addEventListener('pagereveal', function (e) {
		if (!e.viewTransition) { return; }
		e.viewTransition.ready.catch(function () { /* transition skipped */ });
		e.viewTransition.finished.catch(function () { /* transition skipped */ });
	});

	/* ---------- texts: English in the code, the translation for the language version is sent by Front\Seo::head() in the data-texts attribute of the <script> tag ---------- */

	var texts = {};
	try {
		var htmlTag = document.currentScript || document.querySelector('script[data-texts]');
		texts = JSON.parse((htmlTag && htmlTag.getAttribute('data-texts')) || '{}') || {};
	} catch (e) { texts = {}; }
	/* without the attribute (a custom template loads the script differently) the texts stay English */
	function T(text) { return typeof texts[text] === 'string' && texts[text] !== '' ? texts[text] : text; }
	function E(value) { return String(value).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;'); }
	function A(text) { return E(T(text)); }
	/* an address the script makes a link from: only http(s) or an address on the site, never javascript: or data: (3.3.2);
	   anything else is '' – the link is left out */
	function safeUrl(value) {
		var url;
		try { url = new URL(String(value || ''), location.href); } catch (e) { return ''; }
		return value && (url.protocol === 'https:' || url.protocol === 'http:') ? url.href : '';
	}

	/* ---------- photo viewer: photo galleries and single images in the text ---------- */

	var modal = null, photos = [], position = 0, opener = null, swiped = false;

	function show(i) {
		position = (i + photos.length) % photos.length;
		var source = photos[position];
		modal.querySelector('img').src = source.getAttribute('src');
		modal.querySelector('img').alt = source.alt;
		var description = source.alt || ((source.closest('figure') || document).querySelector('figcaption') || {}).textContent || '';
		modal.querySelector('p').textContent = (photos.length > 1 ? (position + 1) + ' / ' + photos.length + (description ? ' · ' : '') : '') + description;
	}

	function open(list, index, fullscreen) {
		if (!modal) {
			modal = document.createElement('dialog');
			modal.className = 'tl-lightbox';
			modal.innerHTML = '<img alt="" draggable="false"><p aria-live="polite"></p><button type="button" data-step="-1" aria-label="' + A('Previous photo') + '">‹</button>'
				+ '<button type="button" data-step="1" aria-label="' + A('Next photo') + '">›</button><button type="button" data-close aria-label="' + A('Close') + '">×</button>';
			document.body.appendChild(modal);
			modal.addEventListener('click', function (e) {
				if (swiped) { swiped = false; return; } // the pointer went up after a swipe, that is not a click
				var step = e.target.getAttribute('data-step');
				if (step) { show(position + parseInt(step, 10)); } else if (e.target.tagName !== 'IMG') { modal.close(); }
			});
			modal.addEventListener('keydown', function (e) {
				if (e.key === 'ArrowLeft') { show(position - 1); }
				if (e.key === 'ArrowRight') { show(position + 1); }
			});
			// swipe with a finger, a pen or a mouse (pointer events); a vertical move is left to the browser (touch-action: pan-y)
			var start = null;
			modal.addEventListener('pointerdown', function (e) { start = e.clientX; swiped = false; });
			modal.addEventListener('pointerup', function (e) {
				var offset = start === null ? 0 : e.clientX - start;
				start = null;
				if (Math.abs(offset) > 50 && photos.length > 1) { swiped = true; show(position + (offset < 0 ? 1 : -1)); }
			});
			modal.addEventListener('pointercancel', function () { start = null; });
			// the focus goes back to the photo that opened the viewer
			modal.addEventListener('close', function () {
				if (document.fullscreenElement === modal && document.exitFullscreen) { document.exitFullscreen().catch(function () { /* already left */ }); }
				if (opener && document.contains(opener)) { opener.focus(); }
			});
		}
		photos = list;
		modal.querySelectorAll('[data-step]').forEach(function (b) { b.hidden = photos.length < 2; });
		show(index);
		modal.showModal();
		// the "grid, opens in full screen" layout asks the browser for real full screen (refused without a user gesture or support: the viewer still covers the page)
		if (fullscreen && modal.requestFullscreen) { modal.requestFullscreen().catch(function () { /* not allowed here */ }); }
	}

	function openFrom(img) {
		var gallery = img.closest('figure.gallery, .tl-gallery');
		var list = Array.prototype.slice.call((gallery || img.closest('.text, .lead')).querySelectorAll(gallery ? 'img' : 'figure:not(.gallery) img'));
		if (list.indexOf(img) === -1) { list = [img]; }
		opener = img;
		open(list, list.indexOf(img), !!(gallery && gallery.hasAttribute('data-fullscreen')));
	}

	document.addEventListener('click', function (e) {
		var img = e.target;
		if (img.tagName !== 'IMG' || img.closest('a') || !img.closest('.text, .lead, figure.gallery, .tl-gallery')) { return; }
		openFrom(img);
	});

	/* a gallery photo is a button for the keyboard too: Tab reaches it, Enter or Space opens the viewer */
	document.querySelectorAll('.tl-gallery img').forEach(function (img) {
		if (img.closest('a')) { return; }
		img.tabIndex = 0;
		img.setAttribute('role', 'button');
	});
	document.addEventListener('keydown', function (e) {
		var img = e.target;
		if ((e.key === 'Enter' || e.key === ' ') && img.tagName === 'IMG' && img.closest('.tl-gallery') && img.getAttribute('role') === 'button') {
			e.preventDefault();
			openFrom(img);
		}
	});

	/* ---------- justified rows: the photos of a row get one height so the row fills the width exactly; the width and height attributes give
	   the shapes, so nothing jumps (without them or without this script the CSS rows still wrap) ---------- */

	document.querySelectorAll('.tl-gallery--justified').forEach(function (gallery) {
		var images = Array.prototype.slice.call(gallery.querySelectorAll('img'));
		var ratios = images.map(function (img) { return img.getAttribute('width') / img.getAttribute('height'); });
		if (!images.length || ratios.some(function (r) { return !(r > 0); })) { return; }
		var lastWidth = 0;
		function place(row, rowRatios, height) {
			row.forEach(function (img, i) {
				img.style.flex = 'none';
				img.style.height = height + 'px';
				img.style.width = Math.floor(rowRatios[i] * height) + 'px';
			});
		}
		function layout() {
			var width = gallery.clientWidth;
			if (!width || width === lastWidth) { return; }
			lastWidth = width;
			var gap = parseFloat(getComputedStyle(gallery).columnGap) || 0;
			var target = (width < 600 ? 8 : 12) * (parseFloat(getComputedStyle(document.documentElement).fontSize) || 16);
			var row = [], rowRatios = [], sum = 0;
			images.forEach(function (img, i) {
				row.push(img); rowRatios.push(ratios[i]); sum += ratios[i];
				var free = width - gap * (row.length - 1);
				if (sum * target >= free) { place(row, rowRatios, free / sum); row = []; rowRatios = []; sum = 0; }
			});
			if (row.length) { place(row, rowRatios, target); } // the last row keeps the target height instead of stretching
		}
		layout();
		if (window.ResizeObserver) { new ResizeObserver(layout).observe(gallery); }
	});

	/* ---------- submenu: Esc closes a panel opened by focus or mouse and returns focus to the menu item (WCAG 1.4.13) ---------- */

	document.addEventListener('keydown', function (e) {
		if (e.key !== 'Escape') { return; }
		var li = e.target.closest && e.target.closest('.tl-nav li.submenu');
		if (li && !li.closest('.tl-nav-menu:popover-open')) {
			li.classList.add('closed');
			var top = li.querySelector(':scope > a, :scope > .menu-group');
			if (top && top !== e.target) { top.focus(); }
		}
		// a panel opened only by mouse hover
		document.querySelectorAll('.tl-nav li.submenu:hover').forEach(function (h) { h.classList.add('closed'); });
	});
	['focusout', 'mouseout'].forEach(function (event) {
		document.addEventListener(event, function (e) {
			var li = e.target.closest && e.target.closest('.tl-nav li.submenu.closed');
			if (li && !li.contains(e.relatedTarget)) { li.classList.remove('closed'); }
		});
	});

	/* ---------- sharing a news item: system sharing (phone) and copying the link ---------- */

	document.querySelectorAll('[data-share]').forEach(function (btn) {
		if (!navigator.share) { return; }
		btn.hidden = false;
		btn.addEventListener('click', function () {
			navigator.share({ title: btn.getAttribute('data-title'), url: btn.getAttribute('data-address') }).catch(function () { /* the visitor closed sharing */ });
		});
	});
	document.addEventListener('click', function (e) {
		var btn = e.target.closest && e.target.closest('[data-copy]');
		if (!btn || !navigator.clipboard) { return; }
		var previous = btn.textContent;
		navigator.clipboard.writeText(btn.getAttribute('data-copy')).then(function () {
			btn.textContent = btn.getAttribute('data-done');
			setTimeout(function () { btn.textContent = previous; }, 2000);
		});
	});

	/* ---------- tabs (ARIA tabs): without the script all panels are visible ---------- */

	document.querySelectorAll('[data-tabs]').forEach(function (z) {
		var cards = Array.prototype.slice.call(z.querySelectorAll('[role="tab"]'));
		function switchTo(card, focusTarget) {
			cards.forEach(function (k) {
				var picked = k === card;
				k.setAttribute('aria-selected', picked ? 'true' : 'false');
				k.tabIndex = picked ? 0 : -1;
				document.getElementById(k.getAttribute('aria-controls')).hidden = !picked;
			});
			if (focusTarget) { card.focus(); }
		}
		cards.forEach(function (k, i) {
			k.addEventListener('click', function () { switchTo(k, false); });
			k.addEventListener('keydown', function (e) {
				var additional = { ArrowRight: i + 1, ArrowLeft: i - 1, Home: 0, End: cards.length - 1 }[e.key];
				if (additional === undefined) { return; }
				e.preventDefault();
				switchTo(cards[(additional + cards.length) % cards.length], true);
			});
		});
		z.setAttribute('data-enabled', '');
		if (cards.length) { switchTo(cards[0], false); }
	});

	/* ---------- carousel: arrows scroll the strip by the width of the visible slides ---------- */

	document.querySelectorAll('[data-carousel], [data-slideshow]').forEach(function (k) {
		var strip = k.querySelector('.tl-carousel-strip, .tl-gallery-strip');
		var arrows = k.querySelectorAll('[data-step]');
		function state() {
			arrows[0].disabled = strip.scrollLeft <= 2;
			arrows[1].disabled = strip.scrollLeft + strip.clientWidth >= strip.scrollWidth - 2;
		}
		arrows.forEach(function (b) {
			b.addEventListener('click', function () { strip.scrollBy({ left: parseInt(b.getAttribute('data-step'), 10) * strip.clientWidth, behavior: 'smooth' }); });
		});
		strip.addEventListener('scroll', state, { passive: true });
		k.setAttribute('data-enabled', '');
		state();
	});

	/* ---------- before and after: the range input moves the divider (the clip of the after image); without the script both photos stand side by side ---------- */

	document.querySelectorAll('[data-before-after]').forEach(function (s) {
		var control = s.querySelector('input[type="range"]');
		if (!control) { return; }
		var move = function () { s.style.setProperty('--tl-split', control.value + '%'); };
		control.addEventListener('input', move);
		s.setAttribute('data-enabled', '');
		move();
	});

	/* ---------- pop-ups: a #popup-<address> link opens one ---------- */

	// an opened popup gets focus (a screen reader announces it, the keyboard continues inside); on close, focus returns where it came from
	var openModal = function (modal) {
		if (!modal.showPopover || modal.matches(':popover-open')) { return; }
		var fromUrl = document.activeElement;
		modal.showPopover();
		var target = modal.querySelector('h1, h2, h3, input, select, textarea, a[href], button:not(.tl-popup-close)') || modal.querySelector('button');
		if (target) { if (!target.matches('a, button, input, select, textarea')) { target.setAttribute('tabindex', '-1'); } target.focus(); }
		modal.addEventListener('toggle', function revert(e) {
			if (e.newState !== 'closed') { return; }
			modal.removeEventListener('toggle', revert);
			if (fromUrl && fromUrl.focus && document.contains(fromUrl)) { fromUrl.focus(); }
		});
	};
	document.addEventListener('click', function (e) {
		var link = e.target.closest && e.target.closest('a[href^="#"]');
		var modal = link && link.getAttribute('href').length > 1 && document.getElementById(link.getAttribute('href').slice(1));
		if (!modal || !modal.hasAttribute('popover') || !modal.showPopover) { return; }
		e.preventDefault();
		openModal(modal);
	});
	// a URL with the anchor of a popup or of an element in it (return after a form submit or subscription) opens the popup right away, so the confirmation is visible
	if (location.hash.length > 1) {
		var anchor = document.getElementById(decodeURIComponent(location.hash.slice(1)));
		var inModal = anchor && anchor.closest('[popover]');
		if (inModal) { openModal(inModal); }
	}
	/* ---------- popups (Builder\Popups): trigger, browser rules, frequency and counters – no cookies ---------- */

	var popups = document.querySelectorAll('.tl-popup[data-popup]');
	if (popups.length) {
		var session = function () { try { return sessionStorage; } catch (error) { return null; } };
		var persistent = function () { try { return localStorage; } catch (error) { return null; } };
		var read = function (u, k) { try { return u ? u.getItem(k) : null; } catch (error) { return null; } };
		var write = function (u, k, v) { try { if (u) { u.setItem(k, v); } } catch (error) { /* private mode */ } };
		// visit: page count, campaign and where it came from (the first page of the visit) – only in the visitor's sessionStorage
		var pageCount = (parseInt(read(session(), 'tl-pages'), 10) || 0) + 1;
		write(session(), 'tl-pages', String(pageCount));
		if (read(session(), 'tl-campaign') === null) {
			var utm = [];
			new URLSearchParams(location.search).forEach(function (v, k) { if (k.indexOf('utm_') === 0) { utm.push(v); } });
			write(session(), 'tl-campaign', utm.join(' ').toLowerCase());
			var fromUrl = '';
			try { fromUrl = document.referrer && new URL(document.referrer).host !== location.host ? new URL(document.referrer).host : ''; } catch (error) { /* invalid URL */ }
			write(session(), 'tl-referrer', fromUrl.toLowerCase());
		}
		var phone = window.matchMedia('(max-width: 767px)').matches;
		var report = function (modal, event) {
			if (!modal.getAttribute('data-counter')) { return; } // signed-in administrator – not counted
			var data = new FormData();
			data.append('popup', modal.getAttribute('data-popup'));
			data.append('event', event);
			try { navigator.sendBeacon(modal.getAttribute('data-counter'), data); } catch (error) { /* no counter */ }
		};
		var cookiesSeen = function () { var l = document.getElementById('cookies-bar'); return l && !l.hidden; };

		popups.forEach(function (modal) {
			var id = modal.getAttribute('data-popup');
			var key = 'tl-popup-' + id;
			var frequency = modal.getAttribute('data-frequency');
			var dialog = modal.classList.contains('tl-popup--window') || modal.classList.contains('tl-popup--fullscreen');
			var conversion = false;
			var openItems = false;

			// conversion: return after a form submit or a subscription in the popup (the anchor in the URL points inside the popup)
			var target = location.hash.length > 1 && document.getElementById(decodeURIComponent(location.hash.slice(1)));
			if (target && modal.contains(target) && (modal.querySelector('[data-sent]') || new URLSearchParams(location.search).get('subscription') === 'ok')) {
				conversion = true;
				report(modal, 'conversion');
				track({ event: 'popup_conversion', popup_id: modal.getAttribute('data-popup'), popup_name: modal.getAttribute('aria-label') || '' });
				write(persistent(), key + '-submitted', '1');
			}
			modal.addEventListener('toggle', function (e) {
				if (e.newState === 'open') {
					openItems = true;
					if (!conversion) { report(modal, 'view'); } // a popup opened for the thank-you after a submit is not counted again
					if (frequency === 'session' || frequency === 'until_submitted') { write(session(), key, '1'); }
					if (frequency === 'days') { write(persistent(), key, String(Date.now())); }
				} else if (openItems) {
					openItems = false;
					if (!conversion) { report(modal, 'close'); }
					if (frequency === 'until_closed') { write(persistent(), key, 'closed'); }
				}
			});
			var open = function () {
				// nothing else opens over an open popup; a bar or a panel does not block the popup
				if (!modal.showPopover || modal.matches(':popover-open') || document.querySelector('.tl-popup--window:popover-open, .tl-popup--fullscreen:popover-open, dialog[open]')) { return false; }
				if (dialog) { openModal(modal); } else { modal.showPopover(); }
				return true;
			};
			if (modal.hasAttribute('data-open')) { open(); return; } // draft preview

			// browser rules: device, campaign, where the visitor came from
			var device = modal.getAttribute('data-device');
			if ((device === 'phone' && !phone) || (device === 'desktop' && phone)) { return; }
			var search = function (attribute, sessionKey) {
				var wanted = (modal.getAttribute(attribute) || '').toLowerCase();
				return wanted === '' || (read(session(), sessionKey) || '').indexOf(wanted) !== -1;
			};
			if (!search('data-campaign', 'tl-campaign') || !search('data-referrer', 'tl-referrer')) { return; }
			// frequency: when the popup does not show by itself again
			var was = read(persistent(), key);
			if ((frequency === 'session' && read(session(), key)) || (frequency === 'until_submitted' && (read(session(), key) || read(persistent(), key + '-submitted')))
				|| (frequency === 'until_closed' && was === 'closed')
				|| (frequency === 'days' && was && Date.now() - parseInt(was, 10) < (parseInt(modal.getAttribute('data-days'), 10) || 1) * 864e5)) { return; }

			var done = false;
			var run = function () {
				if (done) { return; }
				// the popup does not cover the cookie bar: it waits until the visitor deals with it
				if (cookiesSeen()) {
					var l = document.getElementById('cookies-bar');
					new MutationObserver(function (z, observer) { if (l.hidden) { observer.disconnect(); run(); } }).observe(l, { attributes: true, attributeFilter: ['hidden'] });
					return;
				}
				// nor does it close a menu the visitor is using right now (a popover on a phone): it waits until they close it
				var menu = document.querySelector('.tl-nav [popover]:popover-open');
				if (menu) {
					menu.addEventListener('toggle', function delay(e) {
						if (e.newState !== 'closed') { return; }
						menu.removeEventListener('toggle', delay);
						setTimeout(run, 400);
					});
					return;
				}
				done = open();
			};
			var value = parseInt(modal.getAttribute('data-value'), 10) || 0;
			switch (modal.getAttribute('data-trigger')) {
			case 'time':
				setTimeout(run, value * 1000);
				break;
			case 'scroll':
				var offset = function () {
					var path = document.documentElement.scrollHeight - window.innerHeight;
					if (path <= 0 || window.scrollY / path * 100 >= value) { window.removeEventListener('scroll', offset); run(); }
				};
				window.addEventListener('scroll', offset, { passive: true });
				break;
			case 'exit':
				document.addEventListener('mouseout', function (e) { if (!e.relatedTarget && e.clientY <= 0) { run(); } });
				break;
			case 'idle':
				var timer;
				var retry = function () { clearTimeout(timer); timer = setTimeout(run, Math.max(1, value) * 1000); };
				['mousemove', 'keydown', 'scroll', 'touchstart'].forEach(function (u) { window.addEventListener(u, retry, { passive: true }); });
				retry();
				break;
			case 'pages':
				if (pageCount >= Math.max(1, value)) { setTimeout(run, 1500); }
				break;
			default: // click – a #popup-<slug> link opens it (links to popups are handled above)
			}
		});
		// Esc also closes a panel and a bar (popover="manual" does not close by itself)
		document.addEventListener('keydown', function (e) {
			if (e.key !== 'Escape') { return; }
			document.querySelectorAll('.tl-popup[popover="manual"]:popover-open').forEach(function (o) { o.hidePopover(); });
		});
	}

	/* ---------- counter and countdown ---------- */

	var calm = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
	var numbers = new Intl.NumberFormat(document.documentElement.lang || 'cs');
	// counter: the number is final in the HTML (it stays visible until it appears), on first appearing on screen it counts up once from zero
	document.querySelectorAll('[data-counter]').forEach(function (c) {
		var target = parseInt(c.getAttribute('data-counter'), 10);
		if (calm || !target || !('IntersectionObserver' in window)) { return; }
		var observer = new IntersectionObserver(function (records) {
			if (!records[0].isIntersecting) { return; }
			observer.disconnect();
			var start = null;
			var step = function (t) {
				start = start || t;
				var share = Math.min(1, (t - start) / 1400);
				c.textContent = numbers.format(Math.round(target * (1 - Math.pow(1 - share, 3))));
				if (share < 1) { requestAnimationFrame(step); }
			};
			requestAnimationFrame(step);
		}, { threshold: 0.3 });
		observer.observe(c);
	});
	// countdown: the server printed the state at render time (the page may come from a cache), here it is recalculated every second
	document.querySelectorAll('[data-countdown]').forEach(function (o) {
		var target = Date.parse(o.getAttribute('data-countdown'));
		var parts = {};
		o.querySelectorAll('[data-part]').forEach(function (d) { parts[d.getAttribute('data-part')] = d; });
		var two = function (n) { return (n < 10 ? '0' : '') + n; };
		var tick = function () {
			var remaining = Math.floor((target - Date.now()) / 1000);
			if (remaining <= 0) {
				var end = document.createElement('p');
				end.className = 'tl-countdown-end';
				end.textContent = o.getAttribute('data-end');
				o.replaceWith(end);
				return;
			}
			parts.d.textContent = Math.floor(remaining / 86400);
			parts.h.textContent = two(Math.floor(remaining % 86400 / 3600));
			parts.m.textContent = two(Math.floor(remaining % 3600 / 60));
			parts.s.textContent = two(remaining % 60);
			setTimeout(tick, 1000 - Date.now() % 1000);
		};
		if (!isNaN(target) && parts.s) { tick(); }
	});

	/* ---------- the data layer (Google Tag Manager, GA4): Talea pushes conversion events only when the site has one ---------- */
	function track(data) { if (Array.isArray(window.dataLayer)) { window.dataLayer.push(data); } }

	/* ---------- reCAPTCHA v3 (2.6): the token is fetched when the form is sent, then the form goes on as usual ---------- */
	document.querySelectorAll('input[data-recaptcha]').forEach(function (input) {
		var f = input.form;
		if (!f) { return; }
		f.addEventListener('submit', function (e) {
			if (input.value || typeof window.grecaptcha === 'undefined') { return; } // without the script the server answers with a message
			e.preventDefault();
			e.stopImmediatePropagation();
			window.grecaptcha.ready(function () {
				window.grecaptcha.execute(input.getAttribute('data-recaptcha'), { action: 'submit' }).then(function (token) {
					input.value = token;
					if (f.requestSubmit) { f.requestSubmit(); } else { f.submit(); }
				});
			});
		}, true);
	});

	/* ---------- forms: after an error restore the filled-in values, after a submit report a conversion ---------- */

	// the values are kept only by the visitor's browser (sessionStorage) and disappear after a successful submit; nothing is written to the URL
	document.querySelectorAll('form[data-form]').forEach(function (f) {
		var key = 'tl-form-' + f.getAttribute('data-form');
		var wait = f.querySelector('input[data-wait]');
		f.addEventListener('submit', function (e) {
			var values = {};
			Array.prototype.forEach.call(f.elements, function (p) {
				if (!/^p\d+$/.test(p.name)) { return; }
				if (p.type === 'checkbox' || p.type === 'radio') { if (p.checked) { values[p.name] = p.value; } } else { values[p.name] = p.value; }
			});
			try { sessionStorage.setItem(key, JSON.stringify(values)); } catch (error) { /* private mode */ }
			// the bot protection rejects a form submitted a few seconds after the page loads (with autofill even a person
			// can manage that) – instead of an error, the submit is delayed by the remaining time
			// counted from the server's first response (the page was created before it), not from clicking the link
			var navigation = performance.getEntriesByType ? performance.getEntriesByType('navigation')[0] : null;
			var remaining = wait ? parseInt(wait.getAttribute('data-wait'), 10) * 1000 + 250 - (performance.now() - (navigation ? navigation.responseStart : 0)) : 0;
			if (remaining > 0) {
				e.preventDefault();
				var button = f.querySelector('[type=submit]');
				if (button) { button.disabled = true; button.setAttribute('aria-busy', 'true'); }
				setTimeout(function () { f.submit(); }, remaining);
			}
		});
		if (!f.hasAttribute('data-restore')) { return; }
		var storedForm = null;
		try { storedForm = JSON.parse(sessionStorage.getItem(key) || 'null'); } catch (error) { /* nothing */ }
		if (!storedForm) { return; }
		Array.prototype.forEach.call(f.elements, function (p) {
			if (!(p.name in storedForm)) { return; }
			if (p.type === 'checkbox' || p.type === 'radio') { p.checked = p.value === storedForm[p.name]; } else { p.value = storedForm[p.name]; }
		});
	});
	var sent = new URLSearchParams(location.search).get('sent');
	Array.prototype.map.call(document.querySelectorAll('[data-sent]'), function (h) { return h.getAttribute('data-sent'); }).concat(sent ? [sent] : []).forEach(function (name) {
		try { Object.keys(sessionStorage).forEach(function (k) { if (k.indexOf('tl-form-') === 0) { sessionStorage.removeItem(k); } }); } catch (error) { /* nothing */ }
		// conversion tracking: a custom script listens for the event, Google Tag Manager gets an entry in dataLayer
		window.dispatchEvent(new CustomEvent('talea:form_sent', { detail: { form: name } }));
				track({ event: 'generate_lead', form_name: name });
	});
	if (new URLSearchParams(location.search).get('subscription') === 'ok') { track({ event: 'sign_up', method: 'newsletter_signup' }); }

	/* ---------- multi-step forms, conditions and the price estimate (2.12, Builder\Elements\Form): without the script every
	   step and every field is shown, and the server checks the answers and computes the estimate itself ---------- */

	function answersOf(form, name) {
		var values = [];
		form.querySelectorAll('[name="' + name + '"], [name="' + name + '[]"]').forEach(function (el) {
			if (el.disabled) { return; } // a field hidden by its own condition answers nothing
			if (el.type === 'checkbox' || el.type === 'radio') { if (el.checked) { values.push(el.value); } } else if (el.value !== '') { values.push(el.value); }
		});
		return values;
	}
	function refreshForm(form) {
		// in document order, so a field hidden by an earlier condition also hides the fields that depend on it
		form.querySelectorAll('[data-when]').forEach(function (box) {
			var values = answersOf(form, box.getAttribute('data-when')), expected = box.getAttribute('data-when-value');
			var show = expected === '*' ? values.length > 0 : values.indexOf(expected) !== -1;
			box.hidden = !show;
			box.querySelectorAll('input, select, textarea').forEach(function (el) { el.disabled = !show; });
		});
		form.querySelectorAll('[data-estimate]').forEach(function (box) {
			var total = parseFloat(box.getAttribute('data-base')) || 0;
			form.querySelectorAll('option[data-price]').forEach(function (o) { if (o.selected && !o.parentNode.disabled) { total += parseFloat(o.getAttribute('data-price')); } });
			form.querySelectorAll('input[data-price]').forEach(function (el) { if (el.checked && !el.disabled) { total += parseFloat(el.getAttribute('data-price')); } });
			form.querySelectorAll('input[data-price-per]').forEach(function (el) {
				var n = parseFloat(el.value.replace(',', '.'));
				if (!el.disabled && !isNaN(n)) { total += n * parseFloat(el.getAttribute('data-price-per')); }
			});
			var currency = box.getAttribute('data-currency');
			box.querySelector('output').textContent = new Intl.NumberFormat(document.documentElement.lang || undefined, { maximumFractionDigits: total % 1 ? 2 : 0 }).format(total) + (currency ? '\u00a0' + currency : '');
		});
	}
	document.querySelectorAll('form[data-form]').forEach(function (form) {
		if (form.querySelector('[data-when], [data-estimate]')) {
			form.addEventListener('input', function () { refreshForm(form); });
			form.addEventListener('change', function () { refreshForm(form); });
			refreshForm(form);
		}
		var wrapper = form.querySelector('[data-steps]');
		if (!wrapper) { return; }
		var steps = Array.prototype.slice.call(wrapper.children).filter(function (el) { return el.classList.contains('tl-step'); });
		var submit = form.querySelector('button[type=submit]'), submitRow = submit ? submit.closest('.tl-field') : null;
		var nav = document.createElement('p');
		nav.className = 'tl-steps-navigation';
		nav.innerHTML = '<span aria-live="polite"></span><button type="button" class="tl-button tl-button--outline">' + A('Back') + '</button><button type="button" class="tl-button tl-button--primary">' + A('Next') + '</button>';
		wrapper.after(nav);
		var back = nav.children[1], next = nav.children[2], current = 0;
		steps.forEach(function (step, i) { if (step.querySelector('[aria-invalid="true"]')) { current = i; } }); // after an error: the step with the marked field
		function show(i) {
			current = i;
			steps.forEach(function (step, j) { step.hidden = j !== i; });
			nav.firstChild.textContent = T('Step') + ' ' + (i + 1) + ' / ' + steps.length;
			back.hidden = i === 0;
			next.hidden = i === steps.length - 1;
			if (submitRow) { submitRow.hidden = i !== steps.length - 1; }
		}
		next.addEventListener('click', function () {
			var invalid = Array.prototype.filter.call(steps[current].querySelectorAll('input, select, textarea'), function (el) { return !el.disabled && !el.checkValidity(); })[0];
			if (invalid) { invalid.reportValidity(); return; }
			show(current + 1);
			var first = steps[current].querySelector('input:not([disabled]), select:not([disabled]), textarea:not([disabled])');
			if (first) { first.focus(); }
		});
		back.addEventListener('click', function () { show(current - 1); });
		show(current);
	});

	/* ---------- conversion events for Google Tag Manager (2.6): calls, e-mails and downloads; only when the site has a data layer.
	   Contact clicks (2.12): a click on a phone number, an e-mail address or a WhatsApp link is a lead for the site's own statistics
	   too – a beacon with the type and the page path goes to POST /conversion (Core\Conversions) without cookies or identifiers; the
	   server counts it once per visitor, page and type a day. Only when the statistics are on: Front\Seo::head() then puts the
	   endpoint into the data-conversion attribute of this <script> tag. ---------- */
	var clicksTag = document.currentScript || document.querySelector('script[data-conversion]');
	var clicksEndpoint = clicksTag && clicksTag.getAttribute('data-conversion');
	document.addEventListener('click', function (e) {
		var a = e.target.closest && e.target.closest('a[href]');
		if (!a) { return; }
		var href = a.getAttribute('href') || '';
		var type = /^tel:/i.test(href) ? 'tel' : /^mailto:/i.test(href) ? 'mailto' : /^(?:https?:\/\/(?:wa\.me|api\.whatsapp\.com)\/|whatsapp:)/i.test(href) ? 'whatsapp' : '';
		if (type && clicksEndpoint && navigator.sendBeacon && !navigator.webdriver) {
			var beacon = new URLSearchParams();
			beacon.set('type', type);
			beacon.set('path', location.pathname);
			try { navigator.sendBeacon(clicksEndpoint, beacon); } catch (error) { /* no counter */ }
		}
		if (!Array.isArray(window.dataLayer)) { return; }
		if (type === 'tel') { track({ event: 'click_phone', link_url: href }); return; }
		if (type === 'mailto') { track({ event: 'click_email', link_url: href }); return; }
		var file = /\.(pdf|zip|docx?|xlsx?|pptx?|odt|ods|csv|txt|rar|7z|dmg|exe|epub|mp3|mp4)(?:[?#]|$)/i.exec(a.pathname || '');
		if (file) { track({ event: 'file_download', file_name: (a.pathname.split('/').pop() || ''), file_extension: file[1].toLowerCase(), link_url: a.href }); }
	});

	/* ---------- A/B tests (Builder\Experiments): the head script has already chosen the version (html[data-variants="<id>:a|b …"]); here
	   the view and the goals are counted – a beacon to POST /experiment per view and goal, without cookies or identifiers, only
	   where the page names the endpoint (statistics on, not signed in, no preview). A "page reached" goal is measured only for
	   visitors who accepted the cookie bar: the browser has to remember the version between the two pages. ---------- */
	(function () {
		var tag = document.getElementById('tl-experiments'), config = null;
		try { config = tag ? JSON.parse(tag.textContent) : null; } catch (error) { config = null; }
		if (!config) { return; }
		var chosen = {}, remembered = {}, counted = {};
		(document.documentElement.getAttribute('data-variants') || '').split(' ').forEach(function (pair) {
			var parts = pair.split(':');
			if (parts[1]) { chosen[parts[0]] = parts[1]; }
		});
		if (/(?:^|; )talea_consent=[^;]*(?:analytics|marketing)/.test(document.cookie)) {
			try { remembered = JSON.parse(localStorage.getItem('tl-x') || '{}') || {}; } catch (error) { remembered = {}; }
		}
		function send(id, variant, event) {
			if (!config.url || !navigator.sendBeacon || navigator.webdriver) { return; }
			var beacon = new URLSearchParams();
			beacon.set('experiment', id);
			beacon.set('variant', variant);
			beacon.set('event', event);
			try { navigator.sendBeacon(config.url, beacon); } catch (error) { /* no counter */ }
		}
		function reached(x) {
			if (counted[x.id] || !chosen[x.id]) { return; }
			counted[x.id] = true;
			send(x.id, chosen[x.id], 'goal');
		}
		(config.x || []).forEach(function (x) {
			if (chosen[x.id] && (x.goal !== 'page' || remembered[x.id] === chosen[x.id])) { send(x.id, chosen[x.id], 'view'); }
		});
		(config.g || []).forEach(function (g) {
			if ((remembered[g.id] === 'a' || remembered[g.id] === 'b') && location.pathname === g.path) { send(g.id, remembered[g.id], 'goal'); }
		});
		document.addEventListener('submit', function (e) {
			var form = e.target, type = form.matches ? (form.matches('form[data-booking]') ? 'booking' : form.matches('form[data-form]') ? 'form' : '') : '';
			if (type) { (config.x || []).forEach(function (x) { if (x.goal === type) { reached(x); } }); }
		}, true);
		document.addEventListener('click', function (e) {
			var link = e.target.closest && e.target.closest('a[href]');
			if (!link) { return; }
			(config.x || []).forEach(function (x) {
				if (x.goal === 'click' && x.target !== '' && (link.getAttribute('href') === x.target || link.pathname === x.target)) { reached(x); }
			});
		});
	}());

	/* ---------- a third-party player is embedded only after a click ---------- */

	document.addEventListener('click', function (e) {
		var btn = e.target.closest && e.target.closest('[data-insert]');
		if (!btn) { return; }
		// only services the site embeds itself (YouTube without cookies, Vimeo, Google map, the Embed element's services –
		// Builder\Elements\Embed::SERVICES) – never another URL nor javascript:
		var address = btn.getAttribute('data-insert') || '';
		if (!/^https:\/\/(www\.youtube-nocookie\.com\/embed\/|player\.vimeo\.com\/video\/|maps\.google\.com\/maps\?|calendly\.com\/|calendar\.google\.com\/calendar\/appointments\/schedules\/|docs\.google\.com\/forms\/d\/e\/|forms\.office\.com\/Pages\/ResponsePage\.aspx\?|tally\.so\/embed\/|form\.typeform\.com\/to\/|airtable\.com\/embed\/|open\.spotify\.com\/embed\/|w\.soundcloud\.com\/player\/\?)/.test(address)) { return; }
		var border = document.createElement('iframe');
		border.src = address;
		border.title = btn.getAttribute('data-title') || '';
		border.allow = 'autoplay; encrypted-media; picture-in-picture; fullscreen';
		border.allowFullscreen = true;
		border.loading = 'lazy';
		btn.replaceWith(border);
	});
	// color scheme switcher (views/front/color-scheme.php): the choice is remembered in the browser, the template head applies it before rendering
	(function () {
		var options = document.querySelectorAll('[data-theme-option]');
		if (!options.length) { return; }
		var root = document.documentElement;
		function mark(v) {
			document.querySelectorAll('.tl-theme').forEach(function (n) { n.setAttribute('data-option', v); });
			options.forEach(function (b) { b.setAttribute('aria-pressed', String(b.getAttribute('data-theme-option') === v)); });
		}
		var storedValue = null;
		try { storedValue = localStorage.getItem('tl-theme'); } catch (e) { /* storage unavailable */ }
		var defaults = (document.querySelector('.tl-theme') || root).getAttribute('data-theme-default') || 'auto';
		mark(storedValue === 'auto' || storedValue === 'light' || storedValue === 'dark' ? storedValue : defaults);
		document.addEventListener('click', function (e) {
			var b = e.target.closest && e.target.closest('[data-theme-option]');
			if (!b) { return; }
			var v = b.getAttribute('data-theme-option');
			if (v === 'auto') { root.removeAttribute('data-theme'); } else { root.setAttribute('data-theme', v); }
			try { localStorage.setItem('tl-theme', v); } catch (err) { /* the choice applies to this page only */ }
			mark(v);
			var offer = b.closest('[popover]');
			if (offer && offer.matches(':popover-open')) { offer.hidePopover(); }
		});
	})();

	/* ---------- collection lists: filters and pages without reloading the page (2.10). The links work without JavaScript too
	   (they carry ?f-<id>= / ?s-<id>=); here only the list is fetched and swapped, the address changes, Back works ---------- */

	function swapList(wrapper, url, push) {
		var id = wrapper.getAttribute('data-collection');
		wrapper.setAttribute('aria-busy', 'true');
		fetch(url, { credentials: 'same-origin', headers: { 'X-Requested-With': 'talea-list' } }).then(function (r) {
			if (!r.ok) { throw new Error('HTTP ' + r.status); }
			return r.text();
		}).then(function (html) {
			var fresh = new DOMParser().parseFromString(html, 'text/html').querySelector('[data-collection="' + id + '"]');
			if (!fresh) { throw new Error('no list'); }
			wrapper.replaceWith(fresh);
			document.dispatchEvent(new CustomEvent('talea:list')); // new cards: the comparison marks its boxes again
			if (push) { history.pushState({ collection: id }, '', url); }
			var current = fresh.querySelector('.tl-collection-filters [aria-current], .tl-collection-pages [aria-current]');
			if (current) { current.focus({ preventScroll: true }); }
		}).catch(function () { location.href = url; }); // anything unexpected: the ordinary page load
	}

	document.addEventListener('click', function (e) {
		var link = e.target.closest && e.target.closest('[data-collection] .tl-collection-filters a, [data-collection] .tl-collection-pages a');
		if (!link || e.ctrlKey || e.metaKey || e.shiftKey || e.altKey || e.button !== 0 || !window.fetch || !window.DOMParser) { return; }
		e.preventDefault();
		swapList(link.closest('[data-collection]'), link.href, true);
	});
	window.addEventListener('popstate', function (e) {
		var id = e.state && e.state.collection;
		var wrapper = id ? document.querySelector('[data-collection="' + id + '"]') : null;
		if (wrapper) { swapList(wrapper, location.href, false); }
	});

	/* ---------- store locator (2.11, Builder\Elements\StoreLocator): the list is complete without the script; here a search box filters it as
	   you type, "Nearest to me" asks for the position only after the click (nothing is sent anywhere) and sorts by distance, and a Leaflet map
	   with OpenStreetMap tiles loads only after a click – until then no third party is contacted. Texts come translated in data attributes ---------- */

	document.querySelectorAll('[data-locator]').forEach(function (locator) {
		var list = locator.querySelector('.tl-locator-list');
		var items = list ? Array.prototype.slice.call(list.children) : [];
		var message = locator.querySelector('[data-message]');
		var controls = locator.querySelector('.tl-locator-controls');
		if (!list || !controls) { return; }
		controls.hidden = false;
		function say(text) { if (message) { message.textContent = text || ''; } }
		function position(li) {
			var lat = parseFloat(li.getAttribute('data-lat')), lng = parseFloat(li.getAttribute('data-lng'));
			return isNaN(lat) || isNaN(lng) ? null : [lat, lng];
		}

		var search = locator.querySelector('[data-search]');
		var empty = locator.querySelector('[data-empty]');
		if (search) {
			search.addEventListener('input', function () {
				var needle = search.value.trim().toLowerCase(), shown = 0;
				items.forEach(function (li) {
					var hit = needle === '' || (li.getAttribute('data-text') || '').indexOf(needle) !== -1;
					li.hidden = !hit;
					if (hit) { shown++; }
				});
				if (empty) { empty.hidden = shown > 0; }
			});
		}

		var nearest = locator.querySelector('[data-nearest]');
		if (nearest && !navigator.geolocation) { nearest.hidden = true; }
		if (nearest && navigator.geolocation) {
			// great-circle distance in km (haversine) – precise enough to order branches by
			var distance = function (a, b) {
				var r = Math.PI / 180, dLat = (b[0] - a[0]) * r, dLng = (b[1] - a[1]) * r;
				var h = Math.sin(dLat / 2) * Math.sin(dLat / 2) + Math.cos(a[0] * r) * Math.cos(b[0] * r) * Math.sin(dLng / 2) * Math.sin(dLng / 2);
				return 6371 * 2 * Math.atan2(Math.sqrt(h), Math.sqrt(1 - h));
			};
			nearest.addEventListener('click', function () {
				nearest.disabled = true;
				say('');
				navigator.geolocation.getCurrentPosition(function (here) {
					nearest.disabled = false;
					var me = [here.coords.latitude, here.coords.longitude];
					var format = new Intl.NumberFormat(document.documentElement.lang || undefined, { minimumFractionDigits: 1, maximumFractionDigits: 1 });
					var sorted = items.map(function (li) {
						var at = position(li), km = at ? distance(me, at) : Infinity;
						var out = li.querySelector('[data-distance]');
						if (out) { out.textContent = at ? format.format(km) + ' km' : ''; }
						return { li: li, km: km };
					}).sort(function (a, b) { return a.km - b.km; });
					sorted.forEach(function (s) { list.appendChild(s.li); }); // branches without a location stay at the end
					items = sorted.map(function (s) { return s.li; });
					say(locator.getAttribute('data-text-sorted'));
				}, function (error) {
					nearest.disabled = false;
					say(locator.getAttribute(error.code === 1 ? 'data-text-declined' : 'data-text-error'));
				}, { timeout: 15000, maximumAge: 300000 });
			});
		}

		var mapButton = locator.querySelector('[data-map]');
		var mapBox = locator.querySelector('.tl-locator-map');
		// Leaflet only from the site's own copy (StoreLocator::LEAFLET_PATH): a script from anywhere else would run with the
		// site's rights (3.3.2)
		var base = '';
		try {
			var leaflet = new URL(locator.getAttribute('data-leaflet') || '', location.href);
			base = leaflet.origin === location.origin && /\/image\/vendor\/leaflet\/$/.test(leaflet.pathname) && !leaflet.search && !leaflet.hash ? leaflet.href : '';
		} catch (e) { base = ''; }
		if (!base && mapButton) { mapButton.hidden = true; }
		if (base && mapButton && mapBox) {
			var loaded = function (id, make) { // one Leaflet on the page even with several locators
				var existing = document.getElementById(id);
				if (existing) { return existing; }
				var tag = make();
				tag.id = id;
				document.head.appendChild(tag);
				return tag;
			};
			var draw = function () {
				// Leaflet's own attribution control writes HTML: the credit is our own control instead, its text set as text in a
				// fixed link (3.3.2); an older cached page sends the credit as HTML – the inert parser keeps just its text
				var map = window.L.map(mapBox, { scrollWheelZoom: false, attributionControl: false });
				window.L.Icon.Default.imagePath = base + 'images/';
				var credit = new DOMParser().parseFromString(locator.getAttribute('data-attribution') || '', 'text/html').body.textContent.trim() || '© OpenStreetMap';
				new (window.L.Control.extend({ options: { position: 'bottomright' }, onAdd: function () {
					var box = document.createElement('div'), link = document.createElement('a');
					box.className = 'leaflet-control-attribution leaflet-control';
					link.href = 'https://www.openstreetmap.org/copyright';
					link.textContent = credit;
					box.appendChild(link);
					return box;
				} }))().addTo(map);
				window.L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19 }).addTo(map);
				var bounds = [];
				items.forEach(function (li) {
					var at = position(li);
					if (!at) { return; }
					var popup = document.createElement('div');
					['.tl-locator-name', '.tl-locator-address'].forEach(function (part) { var n = li.querySelector(part); if (n) { popup.appendChild(n.cloneNode(true)); } });
					window.L.marker(at).addTo(map).bindPopup(popup);
					bounds.push(at);
				});
				if (bounds.length) { map.fitBounds(bounds, { padding: [24, 24], maxZoom: 15 }); } else { map.setView([50, 15], 4); }
			};
			mapButton.addEventListener('click', function () {
				mapButton.disabled = true;
				loaded('tl-leaflet-css', function () { var l = document.createElement('link'); l.rel = 'stylesheet'; l.href = base + 'leaflet.css'; return l; });
				var script = loaded('tl-leaflet-js', function () { var s = document.createElement('script'); s.src = base + 'leaflet.js'; s.defer = true; return s; });
				var ready = function () {
					mapBox.hidden = false;
					mapButton.hidden = true;
					draw();
					mapBox.focus(); // Leaflet makes the container focusable; the keyboard moves the map from here
				};
				if (window.L) { ready(); } else { script.addEventListener('load', ready); }
				script.addEventListener('error', function () { mapButton.disabled = false; say(locator.getAttribute('data-text-error')); });
			});
		}
	});

	/* ---------- the enquiry basket and comparing products (2.11, Builder\Products): kept only in this browser (localStorage, no
	   cookies). Add to enquiry works without the script too – it opens the enquiry page with the product. The server checks
	   every basket line against the products when the form is sent. ---------- */

	var BASKET = 'talea-enquiry', COMPARE = 'talea-compare';
	function load(key, empty) { try { var v = JSON.parse(localStorage.getItem(key) || 'null'); return v && typeof v === 'object' ? v : empty; } catch (e) { return empty; } }
	function store(key, value) { try { localStorage.setItem(key, JSON.stringify(value)); } catch (e) { /* storage blocked: the basket lasts for this page */ } }
	var basket = load(BASKET, { lines: [], page: '' }), compare = load(COMPARE, { c: '', url: '', items: [] });
	if (!Array.isArray(basket.lines)) { basket.lines = []; }
	if (!Array.isArray(compare.items)) { compare.items = []; }

	function addLine(line) {
		var same = basket.lines.filter(function (l) { return l.c === line.c && l.i === line.i && (l.v || '') === (line.v || ''); })[0];
		if (same) { same.q = Math.min(9999, same.q + line.q); } else if (basket.lines.length < 50) { basket.lines.push(line); }
		store(BASKET, basket);
	}

	document.addEventListener('submit', function (e) {
		var form = e.target.closest && e.target.closest('form[data-product]');
		if (!form) { return; }
		var product;
		try { product = JSON.parse(form.getAttribute('data-product')); } catch (err) { return; }
		e.preventDefault();
		var variant = form.querySelector('[name=variant]'), quantity = form.querySelector('[name=quantity]');
		addLine({ c: product.c, i: product.i, n: product.n, v: variant ? variant.value : '', q: Math.max(1, Math.min(9999, parseInt(quantity ? quantity.value : '1', 10) || 1)) });
		basket.page = safeUrl(form.getAttribute('data-basket')) || basket.page;
		store(BASKET, basket);
		var status = form.querySelector('.tl-enquiry-button-status');
		var page = safeUrl(basket.page);
		if (status) { status.innerHTML = A('Added to the enquiry.') + (page ? ' <a href="' + E(page) + '">' + A('Show the enquiry') + '</a>' : ''); }
		renderBasket();
		renderBar();
	});

	function markCompared() {
		document.querySelectorAll('form[data-product] [data-compare]').forEach(function (box) {
			var product = JSON.parse(box.form.getAttribute('data-product'));
			box.checked = compare.c === product.c && compare.items.some(function (it) { return it.i === product.i; });
		});
	}
	document.addEventListener('change', function (e) {
		var box = e.target.closest && e.target.closest('form[data-product] [data-compare]');
		if (!box) { return; }
		var product = JSON.parse(box.form.getAttribute('data-product'));
		if (compare.c !== product.c) { compare = { c: product.c, url: safeUrl(box.form.getAttribute('data-compare-url')), items: [] }; } // one collection at a time
		compare.items = compare.items.filter(function (it) { return it.i !== product.i; });
		if (box.checked && compare.items.length >= 4) {
			box.checked = false;
			var status = box.form.querySelector('.tl-enquiry-button-status');
			if (status) { status.textContent = T('You can compare up to four products.'); }
		} else if (box.checked) {
			compare.items.push({ i: product.i, n: product.n });
		}
		store(COMPARE, compare);
		renderBar();
	});
	document.addEventListener('talea:list', markCompared);

	/* the floating bar: the enquiry (when it is not on this page) and the comparison */
	function renderBar() {
		var old = document.querySelector('.tl-bar-compare');
		if (old) { old.remove(); }
		var parts = [];
		// the addresses come from localStorage, which an older version or another page could have filled: checked again (3.3.2)
		var page = safeUrl(basket.page), compareUrl = safeUrl(compare.url);
		if (basket.lines.length && page && !document.querySelector('[data-basket-field]')) {
			parts.push('<a href="' + E(page) + '">' + A('Enquiry') + ' (' + basket.lines.length + ')</a>');
		}
		if (compare.items.length && compareUrl) {
			parts.push('<a href="' + E(compareUrl + '?i=' + compare.items.map(function (it) { return encodeURIComponent(it.i); }).join(',')) + '">' + A('Compare') + ' (' + compare.items.length + ')</a> <button type="button">' + A('Clear') + '</button>');
		}
		if (!parts.length) { return; }
		var bar = document.createElement('div');
		bar.className = 'tl-bar-compare';
		bar.innerHTML = parts.join(' · ');
		var clear = bar.querySelector('button');
		if (clear) { clear.addEventListener('click', function () { compare.items = []; store(COMPARE, compare); markCompared(); renderBar(); }); }
		document.body.appendChild(bar);
	}

	/* the basket field of a form: the lines with a quantity and Remove; the hidden field carries them as JSON */
	function renderBasket() {
		document.querySelectorAll('[data-basket-field]').forEach(function (field) {
			var wrapper = field.closest('.tl-basket-field'), list = wrapper.querySelector('[data-basket-list]'), empty = wrapper.querySelector('.tl-basket-empty');
			list.innerHTML = '';
			basket.lines.forEach(function (line, index) {
				var li = document.createElement('li');
				li.innerHTML = '<span></span><label>' + A('Quantity') + ' <input type="number" min="1" max="9999" inputmode="numeric"></label> <button type="button">' + A('Remove') + '</button>';
				li.querySelector('span').textContent = line.n + (line.v ? ' – ' + line.v : '');
				var input = li.querySelector('input');
				input.value = line.q;
				input.addEventListener('change', function () { line.q = Math.max(1, Math.min(9999, parseInt(input.value, 10) || 1)); input.value = line.q; store(BASKET, basket); sync(); });
				li.querySelector('button').addEventListener('click', function () { basket.lines.splice(index, 1); store(BASKET, basket); renderBasket(); });
				list.appendChild(li);
			});
			if (empty) { empty.hidden = basket.lines.length > 0; }
			sync();
			function sync() { field.value = JSON.stringify(basket.lines.map(function (l) { return { c: l.c, i: l.i, v: l.v || '', q: l.q }; })); }
		});
	}

	if (document.querySelector('[data-basket-sent]')) { basket.lines = []; store(BASKET, basket); } // the enquiry was sent
	document.querySelectorAll('[data-basket-field]').forEach(function (field) {
		// a product opened without the script (?product=…) joins the basket
		try { JSON.parse(field.value || '[]').forEach(function (l) { if (!basket.lines.some(function (b) { return b.c === l.c && b.i === l.i && (b.v || '') === (l.v || ''); })) { addLine({ c: l.c, i: l.i, n: l.n || l.i, v: l.v, q: l.q }); } }); } catch (err) { /* nothing */ }
		basket.page = location.pathname + '#enquiry';
		store(BASKET, basket);
	});
	renderBasket();
	markCompared();
	renderBar();
})();

/* ---------- language versions: on the first visit the version in the browser's language, then always the visitor's choice ----------
 * No cookies – the choice is in localStorage. Redirects only on entering the site (not while browsing), only to a page that has
 * a translation in that language (hreflang links in the head), and never search engine bots. A click in the language switcher changes the choice. */
(function () {
	var alternatives = document.querySelectorAll('link[rel="alternate"][hreflang]:not([hreflang="x-default"])');
	if (alternatives.length < 2 || navigator.webdriver || /bot|crawl|spider|slurp|facebookexternalhit|preview|lighthouse|headless/i.test(navigator.userAgent)) { return; }
	var save = function (language) { try { localStorage.setItem('tl-language', language); } catch (e) { /* storage unavailable – nothing */ } };
	document.addEventListener('click', function (e) {
		var link = e.target.closest && e.target.closest('.tl-languages a[hreflang], .tl-languages-select a[hreflang]');
		if (link) { save(link.getAttribute('hreflang')); }
	});
	var storedItem = null;
	try { storedItem = localStorage.getItem('tl-language'); } catch (e) { return; }
	if (storedItem) { return; }
	var version = {};
	alternatives.forEach(function (l) { version[l.getAttribute('hreflang').toLowerCase().slice(0, 2)] = l.href; });
	var current = (document.documentElement.lang || '').toLowerCase().slice(0, 2);
	var wanted = null;
	(navigator.languages && navigator.languages.length ? navigator.languages : [navigator.language || '']).some(function (j) {
		j = String(j).toLowerCase().slice(0, 2);
		if (version[j]) { wanted = j; return true; }
		return false;
	});
	save(wanted || current);
	var fromSite = document.referrer !== '' && document.referrer.indexOf(location.origin + '/') === 0;
	if (wanted && wanted !== current && !fromSite) { location.replace(version[wanted]); }
})();

/* ---------- online booking (3.0, Builder\Elements\Booking): the plain select of the next free times becomes a small month
 * calendar of days with free times (/_booking/days) and the times of the chosen day (/_booking/slots); the people are
 * filtered by the chosen service. Without the script the server-rendered select works on its own. ---------- */
(function () {
	var forms = document.querySelectorAll('form[data-booking]');
	if (!forms.length) { return; }
	var texts = {};
	try { var tag = document.querySelector('script[data-texts]'); texts = JSON.parse((tag && tag.getAttribute('data-texts')) || '{}') || {}; } catch (e) { texts = {}; }
	function T(text) { return typeof texts[text] === 'string' && texts[text] !== '' ? texts[text] : text; }
	var lang = document.documentElement.lang || undefined;
	function pad(n) { return (n < 10 ? '0' : '') + n; }
	function ymd(d) { return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); }
	function load(url, params, done) {
		var query = Object.keys(params).map(function (k) { return k + '=' + encodeURIComponent(params[k]); }).join('&');
		fetch(url + '?' + query, { credentials: 'same-origin' }).then(function (r) { return r.ok ? r.json() : null; }).then(done).catch(function () { done(null); });
	}
	forms.forEach(function (form) {
		var calendar = form.querySelector('[data-calendar]'), times = form.querySelector('[data-times]'), chosen = form.querySelector('[data-selected]'), plain = form.querySelector('[data-no-script]'), slot = form.querySelector('select[name="slot"]');
		if (!calendar || !times || !chosen || !slot) { return; }
		if (plain) { plain.hidden = true; }
		calendar.hidden = false;
		slot.required = false; // the script fills it in; the server checks it anyway
		var today = new Date(); today.setHours(0, 0, 0, 0);
		var month = new Date(today.getFullYear(), today.getMonth(), 1), day = null, freeDays = [], monthRequest = 0, timesRequest = 0; // own counters: an answer for the times must not make the month's answer look stale (3.2.2)
		function service() { var el = form.querySelector('input[name="service"]:checked'); return el ? el.value : ''; }
		function staff() { var el = form.querySelector('input[name="staff"]:checked') || form.querySelector('input[name="staff"][type="hidden"]'); return el ? el.value : '0'; }
		function filterStaff() {
			var s = service();
			form.querySelectorAll('label[data-services]').forEach(function (label) {
				var fits = !s || label.getAttribute('data-services').split(',').indexOf(s) !== -1, input = label.querySelector('input');
				label.hidden = !fits;
				if (!fits && input && input.checked) { var anyone = form.querySelector('input[name="staff"][value="0"]'); if (anyone) { anyone.checked = true; } }
			});
		}
		function setSlot(value, label) {
			slot.innerHTML = '';
			if (value) { var option = document.createElement('option'); option.value = value; option.textContent = label; option.selected = true; slot.appendChild(option); }
			chosen.hidden = !value;
			chosen.textContent = value ? T('Chosen time: %s').replace('%s', label) : '';
		}
		function button(text, onClick, attributes) {
			var b = document.createElement('button'); b.type = 'button'; b.textContent = text;
			Object.keys(attributes || {}).forEach(function (k) { b.setAttribute(k, attributes[k]); });
			b.addEventListener('click', onClick);
			return b;
		}
		function renderMonth() {
			var s = service();
			timesRequest++; // times still loading for the previous month or service are dropped
			times.hidden = true; times.innerHTML = '';
			if (!s) { calendar.innerHTML = '<p class="tl-booking-empty">' + T('Choose a service first.') + '</p>'; return; }
			var ticket = ++monthRequest, key = month.getFullYear() + '-' + pad(month.getMonth() + 1);
			calendar.innerHTML = '<p class="tl-booking-empty">' + T('Loading…') + '</p>';
			load(form.getAttribute('data-days-url'), { service: s, staff: staff(), month: key }, function (data) {
				if (ticket !== monthRequest) { return; }
				freeDays = data && data.days ? data.days : [];
				calendar.innerHTML = '';
				var head = document.createElement('div'); head.className = 'tl-booking-month';
				var previous = button('‹', function () { month = new Date(month.getFullYear(), month.getMonth() - 1, 1); renderMonth(); }, { 'aria-label': T('Previous month') });
				previous.disabled = month <= new Date(today.getFullYear(), today.getMonth(), 1);
				var next = button('›', function () { month = new Date(month.getFullYear(), month.getMonth() + 1, 1); renderMonth(); }, { 'aria-label': T('Next month') });
				next.disabled = month >= new Date(today.getFullYear(), today.getMonth() + 12, 1);
				var title = document.createElement('span'); title.textContent = new Intl.DateTimeFormat(lang, { month: 'long', year: 'numeric' }).format(month);
				head.appendChild(previous); head.appendChild(title); head.appendChild(next);
				calendar.appendChild(head);
				var grid = document.createElement('div'); grid.className = 'tl-booking-days'; grid.setAttribute('role', 'group');
				for (var w = 0; w < 7; w++) { // Monday first
					var name = document.createElement('span'); name.textContent = new Intl.DateTimeFormat(lang, { weekday: 'short' }).format(new Date(2024, 0, 1 + w)); grid.appendChild(name);
				}
				var offset = (month.getDay() + 6) % 7;
				for (var i = 0; i < offset; i++) { grid.appendChild(document.createElement('span')); }
				var last = new Date(month.getFullYear(), month.getMonth() + 1, 0).getDate();
				for (var d = 1; d <= last; d++) {
					var date = new Date(month.getFullYear(), month.getMonth(), d), value = ymd(date);
					var cell = button(String(d), function (e) { pickDay(e.currentTarget); }, { 'data-day': value, 'aria-pressed': day === value ? 'true' : 'false', 'aria-label': new Intl.DateTimeFormat(lang, { dateStyle: 'full' }).format(date) });
					cell.disabled = freeDays.indexOf(value) === -1;
					grid.appendChild(cell);
				}
				calendar.appendChild(grid);
			});
		}
		/* picking a day marks it in the month already shown (no reload of the month) and loads its free times */
		function pickDay(cell) {
			day = cell.getAttribute('data-day');
			setSlot('', '');
			calendar.querySelectorAll('button[data-day]').forEach(function (b) { b.setAttribute('aria-pressed', b === cell ? 'true' : 'false'); });
			loadTimes();
		}
		function loadTimes() {
			if (!day) { return; }
			var ticket = ++timesRequest;
			times.hidden = false; times.innerHTML = '<p class="tl-booking-empty">' + T('Loading…') + '</p>';
			load(form.getAttribute('data-slots'), { service: service(), staff: staff(), day: day }, function (data) {
				if (ticket !== timesRequest) { return; } // another day was picked meanwhile
				times.innerHTML = '';
				var slots = data && data.slots ? data.slots : [];
				if (!slots.length) { times.innerHTML = '<p class="tl-booking-empty">' + T('No free times on this day.') + '</p>'; return; }
				var label = new Intl.DateTimeFormat(lang, { dateStyle: 'medium' }).format(new Date(day + 'T12:00:00'));
				slots.forEach(function (time) {
					times.appendChild(button(time, function (e) {
						times.querySelectorAll('button').forEach(function (b) { b.setAttribute('aria-pressed', 'false'); });
						e.currentTarget.setAttribute('aria-pressed', 'true');
						setSlot(day + ' ' + time, label + ' ' + time);
					}, { 'aria-pressed': 'false' }));
				});
			});
		}
		var submit = form.querySelector('button[data-request]');
		function syncButton() { // a service that needs confirmation is requested, not booked
			var el = form.querySelector('input[name="service"]:checked');
			if (submit) { submit.textContent = el && el.hasAttribute('data-confirmation') ? submit.getAttribute('data-request') : submit.getAttribute('data-book'); }
		}
		form.querySelectorAll('input[name="service"], input[name="staff"]').forEach(function (input) {
			input.addEventListener('change', function () { syncButton(); filterStaff(); day = null; setSlot('', ''); renderMonth(); });
		});
		form.addEventListener('submit', function (e) {
			if (!slot.value) { e.preventDefault(); calendar.scrollIntoView({ block: 'center' }); var first = times.querySelector('button') || calendar.querySelector('button:not(:disabled)'); if (first) { first.focus(); } }
		});
		filterStaff();
		setSlot('', '');
		renderMonth();
	});
})();
