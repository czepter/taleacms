/* Kaleta - editor helper: accessibility check of the content and the AI assistant. No libraries.
 *
 *   <fieldset data-kontrola>          the running check is listed here (alt texts, headings, links, tables)
 *   <form data-asistent="adresa">     form fields get "✦ Navrhnout" (Suggest) buttons (only with the extension enabled)
 *
 * The assistant saves nothing - a suggestion is only inserted into the form field and the person can edit it further.
 */
// the script is in the page content, i.e. before admin.js with the translation dictionary (window.T) – it starts only after all scripts load
document.addEventListener('DOMContentLoaded', function () {
	'use strict';

	var T = window.T || function (s) { return s; }; // translation of admin texts (image/jazyky/admin-*.js)

	var form = document.querySelector('form.form-clanek');
	if (!form) { return; }
	var field = function (id) { return form.querySelector('#' + id); };

	function set(id, value) {
		var p = field(id);
		p.value = value;
		if (window.kaletaEditory && window.kaletaEditory[id]) { window.kaletaEditory[id].obnov(); }
		p.dispatchEvent(new Event('input', { bubbles: true }));
	}
	function tree(html) { return new DOMParser().parseFromString('<div>' + html + '</div>', 'text/html').body.firstChild; }
	function esc(t) { return String(t).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;'); }
	function element(tag, className, text) { var e = document.createElement(tag); if (className) { e.className = className; } if (text) { e.textContent = text; } return e; }

	/* ---------- accessibility check ---------- */

	var panel = form.querySelector('[data-kontrola]');
	var assistantUrl = form.getAttribute('data-asistent') || '';

	function check() {
		if (!panel) { return; }
		var output = panel.querySelector('[data-kontrola-vysledek]');
		var findings = [];
		output.textContent = '';

		['uvod', 'text'].forEach(function (id) {
			var root = tree(field(id).value);
			Array.prototype.forEach.call(root.querySelectorAll('img'), function (img, i) {
				if ((img.getAttribute('alt') || '').trim() !== '') { return; }
				var row = element('div', 'kontrola-obrazek');
				var preview = element('img'); preview.src = img.getAttribute('src'); preview.alt = '';
				var inputEl = element('input', 'textpole'); inputEl.type = 'text'; inputEl.maxLength = 200; inputEl.placeholder = T('what the image shows');
				inputEl.setAttribute('aria-label', T('Image description for blind visitors'));
				var save = function () {
					if (inputEl.value.trim() === '') { return; }
					var k = tree(field(id).value);
					k.querySelectorAll('img')[i].setAttribute('alt', inputEl.value.trim());
					set(id, k.innerHTML);
				};
				inputEl.addEventListener('change', save);
				inputEl.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); save(); } });
				row.appendChild(preview); row.appendChild(inputEl);
				if (assistantUrl) {
					var ai = element('button', 'navigace ai-tl', '✦'); ai.type = 'button'; ai.title = T('Suggest a description with the assistant'); ai.setAttribute('aria-label', ai.title);
					ai.addEventListener('click', function () {
						ai.disabled = true; ai.textContent = '…';
						ask('alt', { image: img.getAttribute('src') }).then(function (j) {
							if (j.navrhy && j.navrhy[0]) { inputEl.value = j.navrhy[0]; inputEl.focus(); } else { announce(j.chyba || T('The assistant suggested nothing.')); }
						}).finally(function () { ai.disabled = false; ai.textContent = '✦'; });
					});
					row.appendChild(ai);
				}
				findings.push([T('Image without a description – neither a blind visitor nor a search engine knows what it shows. Add a description and confirm with Enter:'), row]);
			});

			var level = 1;
			Array.prototype.forEach.call(root.querySelectorAll('h2, h3, h4'), function (h) {
				var u = parseInt(h.tagName.charAt(1), 10);
				if (u > level + 1) { findings.push([T('Subheading “') + h.textContent.trim().slice(0, 50) + T('” skips a level (H') + u + ' without H' + (u - 1) + T(' above it). Screen readers build the outline of the text from the levels.')]); }
				if (h.textContent.trim() === '') { findings.push([T('Empty subheading – delete it.')]); }
				level = u;
			});
			Array.prototype.forEach.call(root.querySelectorAll('a'), function (a) {
				var t = a.textContent.trim().toLowerCase();
				if (/^(zde|tady|tu|sem|klikn[ěe]te( zde)?|více|vice|odkaz|link|here|click here)$/.test(t) || /^https?:\/\//.test(t)) {
					findings.push([T('Link “') + a.textContent.trim().slice(0, 40) + T('” does not say where it leads. Use link text that makes sense on its own.')]);
				}
			});
			Array.prototype.forEach.call(root.querySelectorAll('table'), function (t) { if (!t.querySelector('th')) { findings.push([T('The table has no header (TH cells) – a screen reader cannot tell what each column means.')]); } });
			Array.prototype.forEach.call(root.querySelectorAll('iframe'), function (f) { if (!(f.getAttribute('title') || '').trim()) { findings.push([T('An embedded video or frame has no name (title attribute).')]); } });
		});
		if (field('title').value.length > 110) { findings.push([T('The headline is over 110 characters – it will be cut off in search results and on social networks.')]); }
		if (field('title').value.length > 12 && field('title').value === field('title').value.toUpperCase()) { findings.push([T('An ALL-CAPS headline is hard to read and screen readers may spell it out.')]); }
		if (tree(field('uvod').value).textContent.trim() === '') { findings.push([T('The intro is missing – the news list and social sharing need it.')]); }

		panel.classList.toggle('kontrola-ok', findings.length === 0);
		panel.querySelector('legend').textContent = T('Accessibility check') + (findings.length ? ' (' + findings.length + ')' : '');
		if (!findings.length) { output.appendChild(element('p', 'kontrola-vporadku', T('✓ Images have descriptions; headings and links are fine.'))); return; }
		var ul = element('ul', 'kontrola-seznam');
		findings.forEach(function (n) { var li = element('li', '', n[0]); if (n[1]) { li.appendChild(n[1]); } ul.appendChild(li); });
		output.appendChild(ul);
	}

	var timer = null;
	form.addEventListener('input', function (e) { if (panel && panel.contains(e.target)) { return; } clearTimeout(timer); timer = setTimeout(check, 900); });
	check();

	/* ---------- AI assistant ---------- */

	if (!assistantUrl) { return; }

	var modal = null;
	function dialog(heading) {
		if (!modal) {
			modal = element('dialog', 'galerie-okno ai-okno');
			modal.innerHTML = '<div class="galerie-okno-hlava"><strong></strong><button type="button" class="navigace" data-zavri>' + T('Close') + '</button></div><div class="ai-obsah"></div>';
			document.body.appendChild(modal);
			modal.querySelector('[data-zavri]').addEventListener('click', function () { modal.close(); });
		}
		modal.querySelector('strong').textContent = '✦ ' + heading;
		var content = modal.querySelector('.ai-obsah');
		content.textContent = '';
		if (!modal.open) { modal.showModal(); }
		return content;
	}
	function announce(text) { dialog(T('Assistant')).appendChild(element('p', 'hlaska hlaska-chyba', text)); }

	function ask(task, additional) {
		var data = new FormData();
		data.append('_csrf', form.querySelector('input[name="_csrf"]').value);
		data.append('ukol', task);
		['title', 'uvod', 'text'].forEach(function (id) { data.append(id, field(id).value); });
		Object.keys(additional || {}).forEach(function (k) { data.append(k, additional[k]); });
		return fetch(assistantUrl, { method: 'POST', body: data, credentials: 'same-origin' })
			.then(function (r) { return r.json(); })
			.catch(function () { return { chyba: T('The connection to the assistant failed. Try again.') }; });
	}

	/* task => [field, button label, dialog heading, how to write the suggestion into the field] */
	var TASKS = {
		titulky: ['title', T('Suggest'), T('Headline suggestions'), function (n) { set('title', n); }],
		perex: ['uvod', T('Suggest'), T('Lead paragraph suggestions'), function (n) { set('uvod', '<p>' + esc(n) + '</p>'); }],
		korektura: ['text', T('Proofread'), T('Proofread'), null],
		seo: ['seo_popis', T('Suggest'), T('Search engine description'), function (n) { set('seo_popis', n); }],
		stitky: ['stitky', T('Suggest'), T('Tag suggestions'), function (n) {
			var have = field('stitky').value.split(',').map(function (s) { return s.trim(); }).filter(Boolean);
			n.split(',').map(function (s) { return s.trim(); }).filter(Boolean).forEach(function (s) { if (have.map(function (m) { return m.toLowerCase(); }).indexOf(s.toLowerCase()) === -1) { have.push(s); } });
			set('stitky', have.join(', '));
		}]
	};

	function showSuggestions(task, j) {
		var u = TASKS[task];
		var content = dialog(u[2]);
		if (j.chyba || !j.navrhy || !j.navrhy.length) { content.appendChild(element('p', 'hlaska hlaska-chyba', j.chyba || T('The assistant suggested nothing. Try again.'))); return; }
		j.navrhy.forEach(function (n) {
			var row = element('div', 'ai-navrh');
			row.appendChild(element('p', '', n));
			var b = element('button', 'tl', T('Use')); b.type = 'button';
			b.addEventListener('click', function () { u[3](n); modal.close(); });
			row.appendChild(b);
			content.appendChild(row);
		});
		content.appendChild(element('p', 'napoveda', T('The suggestion is only inserted into the field – you can keep editing it. Nothing is saved until you save the form.')));
	}

	function showProofreading(j) {
		var content = dialog(T('Proofread'));
		if (j.chyba) { content.appendChild(element('p', 'hlaska hlaska-chyba', j.chyba)); return; }
		if (!j.opravy.length) { content.appendChild(element('p', 'kontrola-vporadku', T('✓ The assistant found nothing to fix.'))); return; }
		var items = j.opravy.map(function (o) {
			// a correction can be applied only where the original passage is found in the field exactly (and does not span formatting)
			var whereParts = ['title', 'uvod', 'text'].filter(function (id) { return field(id).value.indexOf(id === 'title' ? o.puvodni : esc(o.puvodni)) !== -1; })[0];
			var row = element('label', 'ai-navrh ai-oprava');
			var box = element('input'); box.type = 'checkbox'; box.checked = !!whereParts; box.disabled = !whereParts;
			var text = element('span');
			text.appendChild(element('del', '', o.puvodni)); text.appendChild(document.createTextNode(' → ')); text.appendChild(element('ins', '', o.oprava));
			text.appendChild(element('small', '', (o.duvod ? ' ' + o.duvod : '') + (whereParts ? '' : T(' – this passage spans formatting, please fix it by hand'))));
			row.appendChild(box); row.appendChild(text);
			content.appendChild(row);
			return { o: o, kde: whereParts, box: box };
		});
		var b = element('button', 'tl', T('Fix selected')); b.type = 'button';
		b.addEventListener('click', function () {
			var values = {};
			items.forEach(function (p) {
				if (!p.kde || !p.box.checked) { return; }
				var h = values[p.kde] !== undefined ? values[p.kde] : field(p.kde).value;
				values[p.kde] = p.kde === 'title' ? h.replace(p.o.puvodni, function () { return p.o.oprava; }) : h.replace(esc(p.o.puvodni), function () { return esc(p.o.oprava); });
			});
			Object.keys(values).forEach(function (id) { set(id, values[id]); });
			modal.close();
		});
		content.appendChild(b);
	}

	Object.keys(TASKS).forEach(function (task) {
		var u = TASKS[task];
		var badge = form.querySelector('label[for="' + u[0] + '"]');
		if (!badge || !field(u[0])) { return; }
		var b = element('button', 'ai-tl', '✦ ' + u[1]); b.type = 'button';
		b.title = task === 'korektura' ? T('The assistant checks spelling, typos and typography') : T('The assistant suggests wording based on the text');
		b.addEventListener('click', function () {
			b.disabled = true; b.textContent = T('✦ thinking…');
			ask(task).then(function (j) { if (task === 'korektura') { showProofreading(j); } else { showSuggestions(task, j); } })
				.finally(function () { b.disabled = false; b.textContent = '✦ ' + u[1]; });
		});
		badge.appendChild(document.createTextNode(' '));
		badge.appendChild(b);
	});
});
