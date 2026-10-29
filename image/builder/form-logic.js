/* Talea – the logic overlay of a form in the page builder (form calculator). inspector.js calls formLogicField()
 * for the form's "logic" property: the inspector shows a summary and "Edit logic…". The overlay is a
 * <dialog> with the tabs Rules · Results · Try it; its left column lists the form's questions for picking only (they are edited in the
 * inspector). It edits a copy and writes it back with the editor's applyChange (one undo step) when it closes or before a try-out;
 * the old one-condition pairs (show_when_field / show_when_value) appear as rules and are cleared then. The arithmetic stays on the
 * server (Builder\FormLogic): Try it saves the draft and asks the build_form_try action, which answers like MCP try_form. */
import { T, D, state, el, clone, find, applyChange, save, query } from './core.js';

const LIMITS = { rules: 50, when: 10, then: 5, results: 10 }; // Builder\FormLogic::MAX_*
const PRICE = /\s*\|\s*-?\d[\d  ]*(?:[.,]\d+)?\s*$/u; // "Label | 1200": the price is not part of the answer
const CHOICE = { select: 'options', radio: 'choices', checkboxes: 'checkbox_options' };
const NUMERIC = ['number', 'timerange'];
const OPS = { is: 'is', is_not: 'is not', contains: 'contains', not_contains: 'does not contain', gt: 'is greater than', lt: 'is less than',
	between: 'is between', weekday: 'falls on a', month: 'is in', empty: 'is empty', not_empty: 'is not empty' };
const ACTIONS = { add: 'Add amount', quantity: 'Quantity × amount', multiply: 'Multiply', skip: 'Skip question', show_when: 'Show question only when', result: 'Show result variant' };
const AFTER_CONTACT_ONLY = ['multiply', 'result'];
const TABS = { rules: 'Rules', results: 'Results', try: 'Try it' };
const RULE_TYPE = 'application/x-talea-rule'; // what a rule carries while it is dragged
const lang = document.documentElement.lang || undefined;
const dayName = (n) => new Intl.DateTimeFormat(lang, { weekday: 'long', timeZone: 'UTC' }).format(new Date(Date.UTC(2024, 0, n))); // 1 January 2024 was a Monday (ISO day 1)
const monthName = (n) => new Intl.DateTimeFormat(lang, { month: 'long', timeZone: 'UTC' }).format(new Date(Date.UTC(2024, n - 1, 1)));
const amount = (n) => new Intl.NumberFormat(lang, { maximumFractionDigits: 2 }).format(n);

const number = (v) => (v !== '' && Number.isFinite(Number(v)) ? Number(v) : v);

const optionsOf = (f) => String((f && f[CHOICE[f.type]]) || '').split(/\r?\n/).map((o) => o.replace(PRICE, '').trim()).filter(Boolean);
const operatorsOf = (f) => [...(CHOICE[f.type] ? (f.type === 'checkboxes' ? ['contains', 'not_contains'] : ['is', 'is_not']) : NUMERIC.includes(f.type) ? ['gt', 'lt', 'between'] : f.type === 'date' ? ['weekday', 'month'] : []), 'empty', 'not_empty'];
/** The questions of a form: every field with a key except steps and the estimate itself. */
const questionsOf = (fields) => fields.filter((f) => f.key && f.type !== 'step' && f.type !== 'estimate');

/** The old one-condition pairs as rules, the way Builder\FormLogic::rules() reads them. */
function legacyRules(fields) {
	const byLabel = {};
	fields.forEach((f) => { const l = String(f.label || '').trim().toLowerCase(); if (!(l in byLabel)) { byLabel[l] = f; } });
	return fields.filter((f) => f.key && String(f.show_when_field || '').trim()).map((f) => {
		const target = byLabel[String(f.show_when_field).trim().toLowerCase()];
		const expected = String(f.show_when_value || '').trim();
		const condition = { field: target ? target.key : '', op: expected === '*' ? 'not_empty' : (target && target.type === 'checkboxes' ? 'contains' : 'is') };
		if (expected !== '*') { condition.value = expected; }
		return { label: '', match: 'all', when: [condition], then: [{ do: 'show_when', field: f.key }] };
	});
}

const counts = (rules, results) => T('%d rules, %d results').replace('%d', String(rules)).replace('%d', String(results));

/** The inspector part: the summary and the button that opens the overlay. */
function field(def, value, change, ctx) {
	const p = ctx.element;
	const logic = value && typeof value === 'object' ? value : {};
	const rules = (Array.isArray(logic.rules) ? logic.rules.length : 0) + legacyRules(p.content.fields || []).length;
	// what the server dropped or left inactive when it saved the logic (the overlay is closed by then)
	const errors = state.selectedPath ? Object.entries(state.errors || {}).filter(([k]) => k.startsWith(state.selectedPath + '.content.logic')).map(([, t]) => t) : [];
	return el('div', { class: 'bd-field bd-logic-field' }, el('span', {}, T('Rules and results')),
		el('p', { class: 'bd-logic-summary' }, counts(rules, Array.isArray(logic.results) ? logic.results.length : 0)),
		errors.length ? el('ul', { class: 'bd-errors' }, errors.map((t) => el('li', {}, t))) : null,
		el('button', { type: 'button', class: 'bd-btn', onclick: () => overlay(p.id) }, T('Edit logic…')));
}

function overlay(id) {
	const element = () => (find(id) || {}).p;
	const start = element();
	if (!start) { return; }
	const fields = start.content.fields || [];
	const questions = questionsOf(fields);
	const byKey = Object.fromEntries(questions.map((f) => [f.key, f]));
	const position = Object.fromEntries(fields.map((f, i) => [f.key, i]));
	const stored = start.content.logic && typeof start.content.logic === 'object' ? clone(start.content.logic) : {};
	const work = { rules: [...legacyRules(fields), ...(Array.isArray(stored.rules) ? stored.rules : [])], results: Array.isArray(stored.results) ? stored.results : [] };
	const live = (start.content.calculation || 'live') !== 'after_contact';
	const name = (k) => (byKey[k] ? byKey[k].label || T('(no label)') : (k ? T('(missing question)') : '…'));
	let tab = 'rules';
	let current = -1; // the rule last worked on: a question picked in the left column goes there

	/** Writes the copy into the build as one change; the converted old pairs are cleared. False = nothing to write. */
	function commit() {
		const p = element();
		if (!p) { return false; }
		const legacy = (p.content.fields || []).some((f) => f.key && (f.show_when_field || f.show_when_value));
		if (!legacy && JSON.stringify(p.content.logic) === JSON.stringify(work)) { return false; }
		applyChange(() => {
			p.content.logic = clone(work);
			(p.content.fields || []).forEach((f) => { if (f.key && (f.show_when_field || f.show_when_value)) { f.show_when_field = ''; f.show_when_value = ''; } });
		});
		return true;
	}

	/* ---------- Rules ---------- */

	function sentence(r) {
		const condition = (c) => {
			const value = c.op === 'weekday' && c.value ? dayName(Number(c.value)) : c.op === 'month' && c.value ? monthName(Number(c.value)) : (c.value ?? '');
			const tail = c.op === 'empty' || c.op === 'not_empty' ? '' : ' ' + (c.op === 'between' ? value + ' ' + T('and') + ' ' + (c.to ?? '') : value);
			return name(c.field) + ' ' + T(OPS[c.op] || '…') + tail;
		};
		const variant = (k) => { const v = work.results.find((x) => x.key === k); return v ? v.heading || v.key : k || '…'; };
		const action = (a) => ({
			add: () => T('add %s').replace('%s', a.amount ?? ''),
			quantity: () => T('add %s per unit of %s').replace('%s', a.amount ?? '').replace('%s', name(a.field)),
			multiply: () => T('multiply by %s').replace('%s', a.by ?? ''),
			skip: () => T('skip %s').replace('%s', name(a.field)),
			show_when: () => T('show %s only then').replace('%s', name(a.field)),
			result: () => T('show the result “%s”').replace('%s', variant(a.variant)),
		}[a.do] || (() => '…'))();
		const when = (r.when || []).length ? T('When %s').replace('%s', r.when.map(condition).join(' ' + T(r.match === 'any' ? 'or' : 'and') + ' ')) : T('Always');
		return when + ' → ' + ((r.then || []).length ? r.then.map(action).join(', ') : '…');
	}

	/** The position of the last question a rule asks about; a rule may hide or show only questions after it (no circles). */
	const latestOf = (r) => Math.max(-1, ...(r.when || []).map((c) => position[c.field] ?? -1));
	/** The position of the first question a rule hides or shows; its conditions may ask only about questions before it. */
	const firstTargetOf = (r) => Math.min(Infinity, ...(r.then || []).filter((a) => (a.do === 'skip' || a.do === 'show_when') && byKey[a.field]).map((a) => position[a.field]));
	const isNumber = (v) => v !== undefined && v !== null && String(v).trim() !== '' && Number.isFinite(Number(String(v).replace(',', '.')));
	const incomplete = (a) => ({
		add: () => !isNumber(a.amount), quantity: () => !isNumber(a.amount) || !byKey[a.field], multiply: () => !isNumber(a.by),
		skip: () => !byKey[a.field], show_when: () => !byKey[a.field], result: () => !work.results.some((v) => v.key === a.variant),
	}[a.do] || (() => true))();
	/** What Builder\FormLogic::sanitize would do with the rule: drop it, or keep it doing nothing; said on the rule while it is edited. */
	function problems(r) {
		const out = [];
		if (!r.then.length) { out.push(T('This rule has no action yet: it is dropped when the form is saved.')); }
		if (r.then.some(incomplete)) { out.push(T('An action is missing its amount, question or result: the rule does not work until you complete it.')); }
		if (firstTargetOf(r) <= latestOf(r)) { out.push(T('A rule can only hide or show a question that comes after the questions it asks about: this rule is dropped when the form is saved.')); }
		if (r.when.some((c) => !byKey[c.field] || !c.op)) { out.push(T('A condition has no question yet: the rule does nothing until you choose one.')); }
		if (r.when.some((c) => ['is', 'is_not', 'contains', 'not_contains'].includes(c.op) && optionsOf(byKey[c.field]).length && !optionsOf(byKey[c.field]).includes(c.value))) {
			out.push(T('A condition names an answer the question no longer offers: the rule does nothing until you fix it.'));
		}
		// the browser sees a file's name, the server nothing: a rule cannot ask about an upload (FormLogic::sanitize marks it broken)
		if (r.when.some((c) => byKey[c.field] && byKey[c.field].type === 'file')) { out.push(T('A condition asks about a file upload, which rules cannot read: the rule does nothing until you fix it.')); }
		if (r.broken && !out.length) { out.push(T('This rule names a question, answer or result that no longer exists: it does nothing until you fix it.')); }
		return out;
	}

	/** A select of the questions; disabled(f) greys out what may not be chosen. */
	const questionSelect = (label, value, onchange, filter, disabled) => el('select', { 'aria-label': label, onchange },
		el('option', { value: '' }, '—'), value && !byKey[value] ? el('option', { value, selected: true }, T('(missing question)')) : null,
		questions.filter(filter || (() => true)).map((q) => el('option', { value: q.key, selected: q.key === value, disabled: disabled && disabled(q) }, q.label || T('(no label)'))));

	/** Sets what a changed question or operator needs: an answer of the question, a number, a day or month, or nothing. */
	function settle(c) {
		const f = byKey[c.field];
		if (!f) { c.op = ''; delete c.value; delete c.to; return; }
		if (!operatorsOf(f).includes(c.op)) { c.op = operatorsOf(f)[0]; }
		delete c.to;
		if (c.op === 'empty' || c.op === 'not_empty') { delete c.value; return; }
		const options = optionsOf(f);
		c.value = ['weekday', 'month'].includes(c.op) ? '1' : options.length && !NUMERIC.includes(f.type) ? options[0] : '';
		if (c.op === 'between') { c.to = ''; }
	}

	function valueControl(c, touched) {
		const f = byKey[c.field];
		if (!c.op || c.op === 'empty' || c.op === 'not_empty') { return null; }
		const set = (key) => (e) => { c[key] = e.target.value; touched(); };
		const pick = (label, list) => el('select', { 'aria-label': label, onchange: (e) => { c.value = e.target.value; touched(); } }, list.map(([v, text]) => el('option', { value: v, selected: String(c.value) === v }, text)));
		if (c.op === 'weekday') { return pick(T('Value'), [1, 2, 3, 4, 5, 6, 7].map((n) => [String(n), dayName(n)])); }
		if (c.op === 'month') { return pick(T('Value'), [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12].map((n) => [String(n), monthName(n)])); }
		const options = optionsOf(f);
		if (options.length && ['is', 'is_not', 'contains', 'not_contains'].includes(c.op)) {
			const list = options.map((o) => [o, o]);
			if (c.value && !options.includes(c.value)) { list.unshift([c.value, T('%s (no longer an answer)').replace('%s', c.value)]); }
			return pick(T('Value'), list);
		}
		const numeric = ['gt', 'lt', 'between'].includes(c.op);
		const input = (label, key) => el('input', { type: numeric ? 'number' : 'text', step: numeric ? 'any' : null, 'aria-label': label, value: c[key] ?? '', oninput: set(key) });
		return c.op === 'between' ? el('span', { class: 'bd-logic-between' }, input(T('Value'), 'value'), T('and'), input(T('to'), 'to')) : input(T('Value'), 'value');
	}

	function conditionRow(r, c, j, redraw, touched) {
		return el('div', { class: 'bd-logic-row' },
			questionSelect(T('Question'), c.field, (e) => { c.field = e.target.value; settle(c); redraw(); }, null, (q) => q.type === 'file' || position[q.key] >= firstTargetOf(r)),
			el('select', { 'aria-label': T('Condition'), disabled: !byKey[c.field], onchange: (e) => { c.op = e.target.value; settle(c); redraw(); } },
				(byKey[c.field] ? operatorsOf(byKey[c.field]) : [c.op]).concat(c.op && byKey[c.field] && !operatorsOf(byKey[c.field]).includes(c.op) ? [c.op] : [])
					.map((op) => el('option', { value: op, selected: op === c.op }, T(OPS[op] || '…')))),
			valueControl(c, touched),
			el('button', { type: 'button', class: 'bd-btn', 'aria-label': T('Remove condition'), title: T('Remove condition'), onclick: () => { r.when.splice(j, 1); redraw(); } }, '×'));
	}

	function actionRow(r, a, j, redraw, touched) {
		const latest = latestOf(r);
		const num = (label, key) => el('input', { type: 'number', step: 'any', 'aria-label': label, value: a[key] ?? '', oninput: (e) => { a[key] = number(e.target.value); touched(); } });
		const params = {
			add: () => [num(T('Amount'), 'amount')],
			quantity: () => [questionSelect(T('Which question'), a.field, (e) => { a.field = e.target.value; touched(); }, (q) => NUMERIC.includes(q.type)), '×', num(T('Amount'), 'amount')],
			multiply: () => [num(T('Factor'), 'by')],
			skip: () => [questionSelect(T('Which question'), a.field, (e) => { a.field = e.target.value; touched(); }, null, (q) => position[q.key] <= latest)],
			result: () => [el('select', { 'aria-label': T('Result'), onchange: (e) => { a.variant = e.target.value; touched(); } }, el('option', { value: '' }, '—'),
				work.results.map((v) => el('option', { value: v.key, selected: v.key === a.variant }, v.heading || v.key)),
				a.variant && !work.results.some((v) => v.key === a.variant) ? el('option', { value: a.variant, selected: true }, T('(missing result)')) : null)],
		};
		params.show_when = params.skip;
		return el('div', { class: 'bd-logic-row' },
			el('select', { 'aria-label': T('Action'), onchange: (e) => {
				const kind = e.target.value;
				Object.keys(a).forEach((k) => delete a[k]);
				Object.assign(a, { do: kind }, { add: { amount: 0 }, quantity: { field: '', amount: 0 }, multiply: { by: 1 }, skip: { field: '' }, show_when: { field: '' }, result: { variant: (work.results[0] || {}).key || '' } }[kind]);
				redraw();
			} }, Object.entries(ACTIONS).map(([k, text]) => el('option', { value: k, selected: k === a.do }, T(text) + (live && AFTER_CONTACT_ONLY.includes(k) ? ' (' + T('after contact only') + ')' : '')))),
			(params[a.do] || (() => []))(),
			el('button', { type: 'button', class: 'bd-btn', 'aria-label': T('Remove action'), title: T('Remove action'), onclick: () => { r.then.splice(j, 1); redraw(); } }, '×'));
	}

	function ruleCard(r, i) {
		const li = el('li', { class: 'bd-logic-rule' });
		r.when = Array.isArray(r.when) ? r.when : [];
		r.then = Array.isArray(r.then) ? r.then : [];
		const draw = () => {
			const text = el('p', { class: 'bd-logic-sentence' }, sentence(r));
			const warnings = el('div', { class: 'bd-logic-warnings', 'aria-live': 'polite' });
			const check = () => {
				const found = problems(r);
				warnings.replaceChildren(...found.map((t) => el('p', { class: 'bd-logic-warning' }, t)));
				li.classList.toggle('bd-logic-broken', found.length > 0);
			};
			const touched = () => { delete r.broken; text.textContent = sentence(r); check(); };
			const redraw = () => { touched(); draw(); };
			const grip = el('span', { class: 'bd-logic-grip', title: T('Drag to change the order'), 'aria-hidden': 'true', onpointerdown: () => { li.draggable = true; }, onpointerup: () => { li.draggable = false; } }, '⠿');
			li.replaceChildren(...[
				el('div', { class: 'bd-logic-rule-head' }, grip, el('strong', {}, (i + 1) + '.'), text,
					el('button', { type: 'button', class: 'bd-btn', 'aria-label': T('Up'), title: T('Up'), disabled: i === 0, onclick: () => move(i, i - 1) }, '↑'),
					el('button', { type: 'button', class: 'bd-btn', 'aria-label': T('Remove rule'), title: T('Remove rule'), onclick: () => { work.rules.splice(i, 1); drawPane(); } }, '×')),
				warnings,
				el('label', { class: 'bd-logic-label' }, el('span', {}, T('Label (a line in the breakdown)')), el('input', { type: 'text', maxlength: 120, value: r.label || '', oninput: (e) => { r.label = e.target.value; touched(); } })),
				el('div', { class: 'bd-logic-part' },
					el('select', { 'aria-label': T('Which conditions must hold'), onchange: (e) => { r.match = e.target.value; touched(); } },
						el('option', { value: 'all', selected: r.match !== 'any' }, T('When all of these hold')), el('option', { value: 'any', selected: r.match === 'any' }, T('When any of these holds'))),
					r.when.map((c, j) => conditionRow(r, c, j, redraw, touched)),
					r.when.length ? null : el('p', { class: 'bd-logic-hint' }, T('No condition: the rule always applies.')),
					el('button', { type: 'button', class: 'bd-btn', disabled: r.when.length >= LIMITS.when, onclick: () => { r.when.push({ field: '', op: '' }); redraw(); } }, T('Add condition'))),
				el('div', { class: 'bd-logic-part' }, el('span', { class: 'bd-logic-title' }, T('Then')),
					r.then.map((a, j) => actionRow(r, a, j, redraw, touched)),
					el('button', { type: 'button', class: 'bd-btn', disabled: r.then.length >= LIMITS.then, onclick: () => { r.then.push({ do: 'add', amount: 0 }); redraw(); } }, T('Add action')))].filter(Boolean));
			check();
		};
		li.addEventListener('focusin', () => { current = i; });
		li.addEventListener('dragstart', (e) => { e.dataTransfer.setData(RULE_TYPE, String(i)); e.dataTransfer.effectAllowed = 'move'; });
		li.addEventListener('dragend', () => { li.draggable = false; });
		li.addEventListener('dragover', (e) => { if (e.dataTransfer.types.includes(RULE_TYPE)) { e.preventDefault(); } });
		li.addEventListener('drop', (e) => {
			if (!e.dataTransfer.types.includes(RULE_TYPE)) { return; }
			e.preventDefault();
			const from = Number(e.dataTransfer.getData(RULE_TYPE));
			if (Number.isInteger(from) && from !== i) { move(from, i); }
		});
		draw();
		return li;
	}

	function move(from, to) {
		const [r] = work.rules.splice(from, 1);
		work.rules.splice(to, 0, r);
		current = to;
		drawPane();
	}

	function rulesPane() {
		const errors = Object.entries(state.errors || {}).filter(([k]) => k.startsWith(state.selectedPath + '.content.logic') || k === state.selectedPath + '.content.calculation');
		const list = el('ol', { class: 'bd-logic-rules' }, work.rules.map(ruleCard));
		return [
			el('p', { class: 'bd-logic-hint' }, T('Rules run from top to bottom. A question can only be hidden or shown by questions before it.')),
			errors.length ? el('ul', { class: 'bd-errors' }, errors.map(([, t]) => el('li', {}, t))) : null,
			work.rules.length ? list : el('p', { class: 'bd-empty' }, T('No rules yet.')),
			el('button', { type: 'button', class: 'bd-btn', disabled: work.rules.length >= LIMITS.rules, onclick: () => { work.rules.push({ label: '', match: 'all', when: [], then: [] }); current = work.rules.length - 1; drawPane(); } }, T('Add rule')),
		];
	}

	/* ---------- Results ---------- */

	function resultsPane() {
		const links = document.getElementById('bd-dl-links');
		if (links && !links.options.length) { links.replaceChildren(...(D.links || []).map(([url, title]) => el('option', { value: url, label: title }))); }
		const card = (v, i) => {
			const legend = el('legend', {}, v.heading || T('Result %s').replace('%s', String(i + 1)));
			const text = (label, key, max, extra) => el('label', { class: 'bd-field' }, el('span', {}, label),
				el('input', Object.assign({ type: 'text', maxlength: max, value: v[key] || '', oninput: (e) => { v[key] = e.target.value; if (key === 'heading') { legend.textContent = e.target.value || T('Result %s').replace('%s', String(i + 1)); } } }, extra || {})));
			const area = el('textarea', { rows: 3, maxlength: 2000, oninput: (e) => { v.text = e.target.value; } });
			area.value = v.text || '';
			const image = el('input', { type: 'text', value: v.image || '', placeholder: 'media/…', oninput: (e) => { v.image = e.target.value; } });
			return el('fieldset', { class: 'bd-logic-result' }, legend,
				text(T('Heading'), 'heading', 120),
				el('label', { class: 'bd-field' }, el('span', {}, T('Text')), area),
				el('label', { class: 'bd-tick' }, el('input', { type: 'checkbox', checked: v.show_amount !== false, onchange: (e) => { v.show_amount = e.target.checked; } }), T('Show the amount')),
				text(T('Button text'), 'button_text', 80),
				text(T('Button link'), 'button_link', 500, { list: 'bd-dl-links', placeholder: T('site page, https://…, #anchor, mailto:, tel:') }),
				el('label', { class: 'bd-field' }, el('span', {}, T('Image')), el('span', { class: 'bd-field-row' }, image,
					el('button', { type: 'button', class: 'bd-btn', onclick: () => window.taleaPickImage && window.taleaPickImage((o) => { image.value = o.url; v.image = o.url; }) }, T('Media')))),
				el('button', { type: 'button', class: 'bd-btn', onclick: () => { work.results.splice(i, 1); drawPane(); } }, T('Remove result')));
		};
		const fresh = () => { let k; do { k = 'v' + Math.random().toString(36).slice(2, 6).padEnd(4, '0'); } while (work.results.some((v) => v.key === k)); return k; };
		return [
			el('p', { class: 'bd-logic-hint' }, T('A rule with “Show result variant” ends in one of these instead of the range; the first matching rule wins. Without one the visitor sees the range with its breakdown.')),
			work.results.length ? el('div', { class: 'bd-logic-results' }, work.results.map(card)) : el('p', { class: 'bd-empty' }, T('No result variants yet.')),
			el('button', { type: 'button', class: 'bd-btn', disabled: work.results.length >= LIMITS.results, onclick: () => {
				work.results.push({ key: fresh(), heading: '', text: '', show_amount: true, button_text: '', button_link: '', image: '' }); drawPane();
			} }, T('Add result')),
		];
	}

	/* ---------- Try it ---------- */

	const answers = {};
	let timer = null;
	let asked = 0;
	function tryPane() {
		const boxes = {};
		const status = el('p', { class: 'bd-logic-hint', 'aria-live': 'polite' });
		const shown = el('output', { class: 'bd-logic-shown' });
		const lines = el('ul', { class: 'bd-logic-lines' });
		const variant = el('p', { class: 'bd-logic-variant' });
		const out = el('div', { class: 'bd-logic-try-out' }, el('span', { class: 'bd-logic-title' }, T('Result')), shown, lines, variant, status);
		const run = () => {
			const n = ++asked;
			commit();
			out.setAttribute('aria-busy', 'true');
			const url = D.urls.form_try + (D.urls.form_try.includes('?') ? '&' : '?') + 'element=' + encodeURIComponent(id) + '&answers=' + encodeURIComponent(JSON.stringify(answers));
			save().then((ok) => (ok ? query(url) : { error: T('The draft could not be saved.') })).then((j) => {
				if (n !== asked) { return; } // a newer try-out is on its way
				out.removeAttribute('aria-busy');
				if (!j || !Array.isArray(j.visible)) { status.textContent = (j && j.error) || T('The server returned an unexpected response.'); return; }
				Object.entries(boxes).forEach(([k, box]) => { box.hidden = !j.visible.includes(k); });
				shown.textContent = j.shown || '—';
				lines.replaceChildren(...j.lines.map((l) => el('li', {}, el('span', {}, l.label), el('span', {}, amount(l.amount)))),
					el('li', { class: 'bd-logic-total' }, el('span', {}, T('Total')), el('span', {}, amount(j.total))));
				const v = work.results.find((x) => x.key === j.variant);
				variant.textContent = j.variant ? T('Result variant: %s').replace('%s', v ? v.heading || v.key : j.variant) : '';
				status.textContent = live ? T('The estimate shows while answering: factors and result variants are not applied.') : '';
			});
		};
		const later = () => { clearTimeout(timer); timer = setTimeout(run, 300); };
		const set = (k, v) => { if (v === '' || (Array.isArray(v) && !v.length)) { delete answers[k]; } else { answers[k] = v; } later(); };
		const input = (q) => {
			const options = optionsOf(q);
			if (q.type === 'checkboxes') {
				const ticked = new Set(answers[q.key] || []);
				return el('fieldset', { class: 'bd-logic-try-q' }, el('legend', {}, q.label || T('(no label)')), options.map((o) => el('label', { class: 'bd-tick' },
					el('input', { type: 'checkbox', checked: ticked.has(o), onchange: (e) => { if (e.target.checked) { ticked.add(o); } else { ticked.delete(o); } set(q.key, [...ticked]); } }), o)));
			}
			let control;
			if (CHOICE[q.type]) {
				control = el('select', { onchange: (e) => set(q.key, e.target.value) }, el('option', { value: '' }, '—'), options.map((o) => el('option', { value: o, selected: answers[q.key] === o }, o)));
			} else if (q.type === 'timerange') {
				const [from, to] = String(answers[q.key] || '-').split('-');
				const both = () => { const [a, b] = control.querySelectorAll('input'); set(q.key, a.value && b.value ? a.value + '-' + b.value : ''); };
				control = el('span', { class: 'bd-field-row' }, el('input', { type: 'time', value: from, 'aria-label': (q.label || '') + ' ' + T('from'), oninput: both }), '–', el('input', { type: 'time', value: to, 'aria-label': (q.label || '') + ' ' + T('to'), oninput: both }));
				return el('div', { class: 'bd-logic-try-q' }, el('span', {}, q.label || T('(no label)')), control);
			} else {
				control = el('input', { type: q.type === 'number' ? 'number' : q.type === 'date' ? 'date' : 'text', step: q.type === 'number' ? 'any' : null, value: answers[q.key] || '', oninput: (e) => set(q.key, e.target.value) });
			}
			return el('label', { class: 'bd-logic-try-q' }, el('span', {}, q.label || T('(no label)')), control);
		};
		const form = el('div', { class: 'bd-logic-try' }, questions.map((q) => (boxes[q.key] = input(q))));
		run();
		return [el('p', { class: 'bd-logic-hint' }, T('Answer as a visitor would: the server computes the result from the current draft, the same way as on the website.')),
			el('div', { class: 'bd-logic-try-grid' }, form, out)];
	}

	/* ---------- the dialog ---------- */

	const pane = el('section', { class: 'bd-logic-pane', role: 'tabpanel', id: 'bd-logic-pane' });
	const tabs = el('div', { class: 'bd-tabs', role: 'tablist' });
	const drawTabs = () => tabs.replaceChildren(...Object.entries(TABS).map(([k, text]) => el('button', { type: 'button', role: 'tab', 'aria-selected': String(tab === k), 'aria-controls': 'bd-logic-pane',
		onclick: () => { tab = k; drawTabs(); drawPane(); } }, T(text))));
	function drawPane() {
		pane.replaceChildren(...({ rules: rulesPane, results: resultsPane, try: tryPane }[tab])().filter(Boolean));
	}
	/** A question picked in the left column: a new condition of the rule last worked on, or the question's answer in Try it. */
	function pickQuestion(q) {
		if (tab === 'try') { const box = pane.querySelectorAll('.bd-logic-try-q')[questions.indexOf(q)]; const c = box && box.querySelector('input, select'); if (c) { c.focus(); } return; }
		if (tab !== 'rules') { tab = 'rules'; drawTabs(); }
		if (!work.rules.length) { work.rules.push({ label: '', match: 'all', when: [], then: [] }); }
		const r = work.rules[current >= 0 && current < work.rules.length ? current : work.rules.length - 1];
		if (r.when.length < LIMITS.when) { const c = { field: q.key, op: '' }; settle(c); r.when.push(c); delete r.broken; }
		current = work.rules.indexOf(r);
		drawPane();
	}

	const heading = el('h2', { id: 'bd-logic-heading' }, T('Form logic') + (start.content.name ? ': ' + start.content.name : ''));
	const d = el('dialog', { class: 'bd-dialog bd-logic', 'aria-labelledby': 'bd-logic-heading' },
		el('div', { class: 'bd-logic-head' }, heading, tabs),
		el('div', { class: 'bd-logic-body' },
			el('aside', { class: 'bd-logic-questions', 'aria-label': T('Questions') }, el('span', { class: 'bd-logic-title' }, T('Questions')),
				questions.length ? el('ol', {}, questions.map((q) => el('li', {}, el('button', { type: 'button', title: T('Use in a condition'), disabled: q.type === 'file', onclick: () => pickQuestion(q) }, q.label || T('(no label)'))))) : el('p', { class: 'bd-empty' }, T('The form has no questions yet.'))),
			pane),
		el('footer', {}, live ? el('p', { class: 'bd-logic-hint' }, T('The estimate shows while answering: “Multiply” and result variants work only when it shows after the contact details (Show the estimate, in the inspector).')) : null,
			el('button', { type: 'button', class: 'bd-btn bd-btn-main', onclick: () => d.close() }, T('Done'))));
	d.addEventListener('close', () => {
		clearTimeout(timer);
		commit();
		d.remove();
		const opener = document.querySelector('.bd-logic-field > .bd-btn');
		if (opener) { opener.focus(); }
	});
	document.body.append(d);
	drawTabs();
	drawPane();
	d.showModal();
}

export { field as formLogicField };
