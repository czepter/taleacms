/* Talea - Site appearance (admin): presets and a live preview of the real home page. No libraries, no build step.
 *
 * Loaded by views/admin/appearance/list.php only, in the page content, i.e. before admin.js and the dictionary: it starts on DOMContentLoaded.
 */
document.addEventListener('DOMContentLoaded', function () {
	'use strict';

	var T = function (s) { return window.T ? window.T(s) : s; }; // translation of admin texts (image/languages/admin-*.js)

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
});
