/* Talea – forms on the site (Builder\Elements\Form): the answers kept in the visitor's browser, multi-step forms, the conditions and
 * the live price estimate, the drop-off beacon and the delayed submit. Front\Seo::head() adds this file; Front\PageFrame keeps it only on
 * a page with a form (data-form). Without the script every question is shown and the form still sends. */
(function () {
	'use strict';

	/* texts: English in the code, the translation in the data-texts attribute of this <script> tag (Front\Seo::FORM_SCRIPT_TEXTS) */
	var texts = {};
	try {
		var tag = document.currentScript;
		texts = JSON.parse((tag && tag.getAttribute('data-texts')) || '{}') || {};
	} catch (e) { texts = {}; }
	function T(text) { return typeof texts[text] === 'string' && texts[text] !== '' ? texts[text] : text; }
	function A(text) { return String(T(text)).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;'); }
	// numbers keep the Western digits the server writes on a right-to-left page
	var locale = function () { var lang = document.documentElement.lang; return document.documentElement.dir === 'rtl' && lang ? lang + '-u-nu-latn' : lang || undefined; };

	/* the values of the answer fields (p0, p1[] …): a string, or a list for checkboxes and the two times of a time range; a hidden
	   field is the page's own (a collection item's value, the basket) and is never stored or brought back */
	function snapshot(f) {
		var values = {};
		Array.prototype.forEach.call(f.elements, function (p) {
			if (!/^p\d+(\[\])?$/.test(p.name) || p.type === 'file' || p.type === 'hidden') { return; }
			var many = /\[\]$/.test(p.name), name = p.name.replace(/\[\]$/, '');
			if ((p.type === 'checkbox' || p.type === 'radio') && !p.checked) { return; }
			if (many) { (values[name] = values[name] || []).push(p.value); } else { values[name] = p.value; }
		});
		return values;
	}
	function restore(f, stored) {
		var seen = {};
		Array.prototype.forEach.call(f.elements, function (p) {
			if (!/^p\d+(\[\])?$/.test(p.name) || p.type === 'file' || p.type === 'hidden') { return; }
			var many = /\[\]$/.test(p.name), name = p.name.replace(/\[\]$/, ''), value = stored[name];
			if (value === undefined) { if (p.type === 'checkbox' || p.type === 'radio') { p.checked = false; } return; }
			if (many !== Array.isArray(value)) { return; }
			if (p.type === 'checkbox' || p.type === 'radio') { p.checked = many ? value.indexOf(p.value) !== -1 : p.value === value; return; }
			if (many) { seen[name] = (seen[name] || 0) + 1; p.value = value[seen[name] - 1] || ''; } else { p.value = value; }
		});
	}

	/* ---------- form logic (Builder\FormLogic, Builder\Elements\Form): the same rules as on the server decide which questions are
	   shown (skip, show only when) and what the live estimate says; the server checks the answers again and is the one that counts.
	   Without the script every question is shown and the estimate shows its base. ---------- */

	var FormLogic = (function () {
		var NUMERIC = /^[+-]?(\d+\.?\d*|\.\d+)([eE][+-]?\d+)?$/;
		// the number of an answer: a number (a comma decimal is fine) or the hours of a time range (past midnight wraps); null otherwise
		function amountOf(field, answer) {
			if (Array.isArray(answer)) { return null; }
			answer = String(answer).trim();
			if (field.type === 'timerange') {
				var m = /^(\d{1,2}):(\d{2})-(\d{1,2}):(\d{2})$/.exec(answer);
				if (!m || +m[1] > 23 || +m[3] > 23 || +m[2] > 59 || +m[4] > 59) { return null; }
				var minutes = (+m[3] * 60 + +m[4]) - (+m[1] * 60 + +m[2]);
				return (minutes < 0 ? minutes + 1440 : minutes) / 60;
			}
			answer = answer.replace(/,/g, '.');
			return NUMERIC.test(answer) ? Number(answer) : null;
		}
		function holds(c, field, answer) {
			var op = String(c.op || ''), value = String(c.value === undefined || c.value === null ? '' : c.value).trim();
			var list = Array.isArray(answer) ? answer.map(function (v) { return String(v).trim(); }) : null;
			var text = list ? '' : String(answer).trim();
			switch (op) {
				case 'is': return list ? list.indexOf(value) !== -1 : text === value;
				case 'is_not': return !holds({ op: 'is', value: value }, field, answer);
				case 'contains': return list ? list.indexOf(value) !== -1 : value !== '' && text.toLowerCase().indexOf(value.toLowerCase()) !== -1;
				case 'not_contains': return !holds({ op: 'contains', value: value }, field, answer);
				case 'empty': return list ? list.length === 0 : text === '';
				case 'not_empty': return !holds({ op: 'empty' }, field, answer);
				case 'gt': case 'lt': case 'between':
					var n = amountOf(field, answer), a = amountOf({}, value), b = amountOf({}, c.to === undefined ? '' : c.to);
					if (n === null || a === null) { return false; }
					if (op === 'gt') { return n > a; }
					if (op === 'lt') { return n < a; }
					return b !== null && n >= Math.min(a, b) && n <= Math.max(a, b);
				case 'weekday': case 'month':
					var d = /^(\d{4})-(\d{2})-(\d{2})$/.exec(text);
					if (list || !d || !NUMERIC.test(value)) { return false; }
					var date = new Date(Date.UTC(+d[1], +d[2] - 1, +d[3]));
					if (date.getUTCFullYear() !== +d[1] || date.getUTCMonth() !== +d[2] - 1 || date.getUTCDate() !== +d[3]) { return false; }
					return (op === 'weekday' ? date.getUTCDay() || 7 : date.getUTCMonth() + 1) === Math.trunc(Number(value));
			}
			return false;
		}
		function positions(logic) {
			var position = Object.create(null);
			logic.fields.forEach(function (f, i) { if (f.key && !(f.key in position)) { position[f.key] = i; } });
			return position;
		}
		// a rule holds when all (or any) of its conditions do; a condition sees a hidden question as empty, and one that names the
		// question itself or a later one is false; a rule without conditions holds
		function ruleHolds(logic, position, shown, answers, rule, before) {
			var results = (rule.when || []).map(function (c) {
				var p = position[c.field];
				if (p === undefined || p >= before) { return false; }
				var raw = answers[p] === undefined ? '' : answers[p];
				return holds(c, logic.fields[p], shown[p] ? raw : (Array.isArray(raw) ? [] : ''));
			});
			if (!results.length) { return true; }
			return rule.match === 'any' ? results.indexOf(true) !== -1 : results.indexOf(false) === -1;
		}
		// which questions are shown, in order: hidden when any skip rule for it holds; with show-only-when rules at least one must hold
		function visible(logic, answers) {
			var position = positions(logic), shown = [];
			logic.fields.forEach(function (f, i) {
				var skipped = false, shows = [];
				(logic.rules || []).forEach(function (rule) {
					(rule.then || []).forEach(function (action) {
						if (action.field !== f.key || (action.do !== 'skip' && action.do !== 'show_when')) { return; }
						var holding = ruleHolds(logic, position, shown, answers, rule, i);
						if (action.do === 'skip') { skipped = skipped || holding; } else { shows.push(holding); }
					});
				});
				shown[i] = !skipped && (!shows.length || shows.indexOf(true) !== -1);
			});
			return shown;
		}
		function elementsOf(form, i) { return Array.prototype.slice.call(form.querySelectorAll('[name="p' + i + '"], [name="p' + i + '[]"]')); }
		// the answers by question number, as the server reads them
		function answers(form, logic) {
			return logic.fields.map(function (f, i) {
				var els = elementsOf(form, i), on = els.filter(function (el) { return el.checked; });
				if (f.type === 'timerange') { return els.length === 2 && els[0].value && els[1].value ? els[0].value + '-' + els[1].value : ''; }
				if (f.type === 'checkboxes') { return on.map(function (el) { return el.value; }); }
				if (f.type === 'radio') { return on.length ? on[0].value : ''; }
				if (!els.length) { return ''; }
				return els[0].type === 'checkbox' ? (els[0].checked ? els[0].value : '') : els[0].value;
			});
		}
		function round2(x) {
			var a = Math.abs(x), r = /e/.test(String(a)) ? Math.round(a * 100) / 100 : Number(Math.round(a + 'e2') + 'e-2');
			return x < 0 ? -r : r;
		}
		// the sum: base, prices of the chosen options, numbers times their price per unit (shown questions only), then the add and
		// quantity rules top to bottom (factors and result variants never reach the browser)
		function total(form, logic, shown) {
			var position = positions(logic), given = answers(form, logic), sum = (logic.estimate && logic.estimate.base) || 0;
			logic.fields.forEach(function (f, i) {
				if (!shown[i]) { return; }
				elementsOf(form, i).forEach(function (el) {
					if (el.tagName === 'SELECT') { el = el.selectedOptions[0]; } else if ((el.type === 'checkbox' || el.type === 'radio') && !el.checked) { return; }
					if (el && el.hasAttribute('data-price')) { sum += parseFloat(el.getAttribute('data-price')) || 0; }
				});
				var per = elementsOf(form, i)[0];
				if (f.type === 'number' && per && per.hasAttribute('data-price-per') && amountOf(f, given[i]) !== null) { sum += amountOf(f, given[i]) * parseFloat(per.getAttribute('data-price-per')); }
			});
			(logic.rules || []).forEach(function (rule) {
				if (!ruleHolds(logic, position, shown, given, rule, logic.fields.length)) { return; }
				(rule.then || []).forEach(function (action) {
					var amount = null;
					if (action.do === 'add') { amount = Number(action.amount) || 0; }
					if (action.do === 'quantity') {
						var p = position[action.field], n = p !== undefined && shown[p] ? amountOf(logic.fields[p], given[p]) : null;
						amount = n === null ? null : n * (Number(action.amount) || 0);
					}
					if (amount !== null && round2(amount) !== 0) { sum += round2(amount); }
				});
			});
			return round2(sum);
		}
		// the estimate as visitors read it: below/above in percent, rounded outwards, held up by the minimum (FormLogic::range and format)
		function shownRange(value, estimate) {
			var below = Math.max(0, Math.min(90, estimate.below || 0)), above = Math.max(0, Math.min(90, estimate.above || 0)), step = Math.max(0, Math.min(100000, estimate.round || 0)), minimum = estimate.minimum || 0;
			var low, high, kind;
			if (!below && !above) {
				low = high = step > 0 ? Math.round(value / step) * step : value;
				kind = step > 0 ? 'about' : 'plain';
			} else {
				low = Math.round(value * (1 - below / 100) * 1e6) / 1e6;
				high = Math.round(value * (1 + above / 100) * 1e6) / 1e6;
				if (step > 0) { low = Math.floor(low / step) * step; high = Math.ceil(high / step) * step; }
				kind = 'range';
			}
			if (minimum > high) { low = high = minimum; kind = 'from'; } else { low = Math.max(low, minimum); }
			var money = function (v) {
				var digits = Math.abs(v - Math.round(v)) < 0.005 ? 0 : 2;
				return ((estimate.prefix || '') + new Intl.NumberFormat(locale(), { minimumFractionDigits: digits, maximumFractionDigits: digits }).format(v) + (estimate.suffix ? ' ' + estimate.suffix : '')).trim();
			};
			if (kind === 'about') { return T('about %s').replace('%s', function () { return money(low); }); }
			if (kind === 'from') { return T('from %s').replace('%s', function () { return money(low); }); }
			return kind === 'range' ? money(low) + ' – ' + money(high) : money(low);
		}
		return { visible: visible, total: total, answers: answers, shownRange: shownRange };
	})();
	window.taleaFormLogic = FormLogic;

	function refreshForm(form, logic) {
		var shown = FormLogic.visible(logic, FormLogic.answers(form, logic));
		// a question a rule shows or skips sits in a box; hidden, its fields send nothing
		form.querySelectorAll('.tl-condition[data-field]').forEach(function (box) {
			var on = shown[parseInt(box.getAttribute('data-field'), 10)] !== false;
			box.hidden = !on;
			box.querySelectorAll('input, select, textarea').forEach(function (el) { el.disabled = !on; });
		});
		if (logic.estimate) {
			var text = FormLogic.shownRange(FormLogic.total(form, logic, shown), logic.estimate);
			form.querySelectorAll('[data-estimate] output').forEach(function (out) { out.textContent = text; });
		}
	}

	document.querySelectorAll('form[data-form]').forEach(function (form) {
		var id = form.getAttribute('data-form'), key = 'tl-form-' + id, logic = null, wait = form.querySelector('input[data-wait]');
		try { logic = JSON.parse(form.getAttribute('data-logic') || 'null'); } catch (error) { logic = null; }
		try { var stored = JSON.parse(sessionStorage.getItem(key) || 'null'); if (stored) { restore(form, stored); } } catch (error) { /* nothing */ }

		/* drop-off counters (Front\FormSteps): a beacon per step reached, once per page view, without a cookie or any storage */
		var reached = {}, action = form.getAttribute('action') || '';
		function reach(step) {
			var source = form.querySelector('input[name=source]'), element = form.querySelector('input[name=element]');
			if (reached[step] || !navigator.sendBeacon || !source || !element || !/form$/.test(action)) { return; }
			reached[step] = true;
			var data = new FormData();
			data.append('source', source.value);
			data.append('element', element.value);
			data.append('step', String(step));
			try { navigator.sendBeacon(action.replace(/form$/, 'form-step'), data); } catch (error) { /* no counter */ }
		}

		var wrapper = form.querySelector('[data-steps]');
		var steps = wrapper ? Array.prototype.slice.call(wrapper.children).filter(function (el) { return el.classList.contains('tl-step'); }) : [];
		var current = 0, nav = null, back = null, next = null, progress = null, submit = form.querySelector('button[type=submit]'), submitRow = submit ? submit.closest('.tl-field') : null;
		// a step whose questions are all hidden by rules is skipped as a whole
		function skipped(i) {
			var fields = steps[i].querySelectorAll('.tl-field');
			return fields.length > 0 && !Array.prototype.some.call(fields, function (el) { return !el.closest('.tl-condition[hidden]'); });
		}
		function path() { return steps.map(function (s, i) { return i; }).filter(function (i) { return !skipped(i); }); }
		function layout() {
			if (!nav) { return; }
			var list = path(), at = list.indexOf(current);
			steps.forEach(function (step, j) { step.hidden = j !== current; });
			nav.firstChild.textContent = T('Step') + ' ' + (at + 1) + ' / ' + list.length;
			progress.max = list.length;
			progress.value = at + 1;
			back.hidden = at <= 0;
			next.hidden = at === list.length - 1;
			if (submitRow) { submitRow.hidden = at !== list.length - 1; }
		}
		function enter(i) {
			current = i;
			layout();
			reach(i + 1);
			// focus follows the visitor to the new step (Next, Back and the browser Back alike); the button that held it may be gone
			var first = steps[current].querySelector('input:not([disabled]):not([type=hidden]), select:not([disabled]), textarea:not([disabled])');
			if (first) { first.focus(); }
		}
		function forward() {
			var invalid = Array.prototype.filter.call(steps[current].querySelectorAll('input, select, textarea'), function (el) { return !el.disabled && !el.checkValidity(); })[0];
			if (invalid) { invalid.reportValidity(); return; }
			var target = path().filter(function (i) { return i > current; })[0];
			if (target === undefined) { return; }
			reach(1); // a later step is never counted without the first
			try { history.pushState({ tlForm: id, tlStep: target, tlFrom: current }, ''); } catch (error) { /* nothing */ }
			enter(target);
		}

		if (steps.length) {
			nav = document.createElement('p');
			nav.className = 'tl-steps-navigation';
			nav.innerHTML = '<span aria-live="polite"></span><button type="button" class="tl-button tl-button--outline">' + A('Back') + '</button><button type="button" class="tl-button tl-button--primary">' + A('Next') + '</button>';
			wrapper.after(nav);
			progress = document.createElement('progress');
			progress.className = 'tl-form-progress';
			progress.setAttribute('aria-hidden', 'true');
			wrapper.before(progress);
			back = nav.children[1];
			next = nav.children[2];
			steps.forEach(function (step, i) { if (step.querySelector('[aria-invalid="true"]')) { current = i; } }); // after an error: the step with the marked field
			next.addEventListener('click', forward);
			back.addEventListener('click', function () {
				var before = path().filter(function (i) { return i < current; }).pop();
				if (history.state && history.state.tlForm === id && history.state.tlStep === current && history.state.tlFrom === before) { history.back(); } else if (before !== undefined) { enter(before); }
			});
			// the Back button of the browser goes one step back, not off the page
			window.addEventListener('popstate', function (e) {
				var s = e.state;
				if (!s || s.tlForm !== id || !steps[s.tlStep]) { return; }
				// a step the answers now skip: the nearest one that is still on the path
				var list = path(), target = list.indexOf(s.tlStep) !== -1 ? s.tlStep : (list.filter(function (i) { return i < s.tlStep; }).pop() ?? list[0]);
				if (target !== undefined) { enter(target); }
			});
			// choosing a tile moves on (pointer only: arrow keys on a radio group also "click", with no detail)
			form.addEventListener('click', function (e) {
				var radio = e.target;
				if (e.detail > 0 && radio.matches && radio.matches('input[type=radio]') && radio.closest('[data-advance]')) { update(); forward(); }
			});
			try { history.replaceState({ tlForm: id, tlStep: current, tlFrom: -1 }, ''); } catch (error) { /* nothing */ }
		}

		function update() {
			form.querySelectorAll('input[type=range]').forEach(function (range) {
				var out = form.querySelector('output[for="' + range.id + '"]');
				if (out) { out.textContent = new Intl.NumberFormat(locale()).format(Number(range.value)); }
			});
			if (logic) { refreshForm(form, logic); }
			layout();
		}
		function remember() { try { sessionStorage.setItem(key, JSON.stringify(snapshot(form))); } catch (error) { /* private mode */ } }
		form.addEventListener('input', function () { reach(1); update(); remember(); });
		form.addEventListener('change', function () { update(); remember(); });
		update();

		form.addEventListener('submit', function (e) {
			remember();
			// the bot protection rejects a form submitted a few seconds after the page loads (with autofill even a person
			// can manage that) – instead of an error, the submit is delayed by the remaining time
			// counted from the server's first response (the page was created before it), not from clicking the link
			var navigation = performance.getEntriesByType ? performance.getEntriesByType('navigation')[0] : null;
			var remaining = wait ? parseInt(wait.getAttribute('data-wait'), 10) * 1000 + 250 - (performance.now() - (navigation ? navigation.responseStart : 0)) : 0;
			if (remaining > 0) {
				e.preventDefault();
				var button = form.querySelector('[type=submit]');
				if (button) { button.disabled = true; button.setAttribute('aria-busy', 'true'); }
				setTimeout(function () { form.submit(); }, remaining);
			}
		});
	});
})();
