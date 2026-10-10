/* Talea – the page builder's direct manipulation on the canvas, part 3: the keyboard (compose phase A, see docs/specs/visual-compose.md).
 *
 *   arrow keys            select the previous / next element among siblings (in a grid: the cell above / below)
 *   Ctrl/Cmd+Shift+arrow  move the selected elements one place; at the end of a container the element hops into the next container
 *   Alt+arrow             resize the selected element by one step (width in twelfths or a column, height by 1 rem or a row)
 *   Ctrl/Cmd+G, +Shift+G  group / ungroup
 *   Enter                 move the focus to the handles of the selected element (Tab walks them, arrows change a value, Esc leaves)
 * Esc cancels a gesture in progress (builder-overlay.js). The handles' own arrow keys are in builder-handles.js. */
(function () {
	'use strict';

	const B = window.taleaBuilder;
	const C = window.taleaCompose;
	if (!B || !C) { return; }
	const { state } = B;
	const ARROWS = ['ArrowUp', 'ArrowDown', 'ArrowLeft', 'ArrowRight'];

	/** The previous / next element among the siblings; in a grid the arrows up and down jump by a row of cells. */
	function selectSibling(key) {
		const n = state.selected && B.find(state.selected);
		if (!n) { return; }
		let step = key === 'ArrowUp' || key === 'ArrowLeft' ? -1 : 1;
		const doc = B.previewDoc();
		const node = doc && C.nodeOf(doc, state.selected);
		const parent = node && node.parentElement;
		if (parent && (key === 'ArrowUp' || key === 'ArrowDown') && /grid/.test(doc.defaultView.getComputedStyle(parent).display)) {
			const columns = doc.defaultView.getComputedStyle(parent).gridTemplateColumns.split(' ').length;
			step *= Math.max(1, columns);
		}
		const next = n.siblings[n.i + step];
		if (next && !next.locked) { B.selection(next.id); B.setState(B.labelText(next)); C.announce(B.labelText(next)); }
	}

	function onKey(e) {
		if (e.defaultPrevented || state.gesture) { return; }
		const target = e.target;
		const typing = target.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(target.tagName);
		if (typing || document.querySelector('dialog[open], .bd-more:popover-open')) { return; }
		const mod = e.ctrlKey || e.metaKey;
		// the arrows and Enter act on the canvas only when the focus is on the page itself or on nothing – not on a button or in the structure
		const onCanvas = target.ownerDocument !== document || target === document.body;
		if (mod && e.key.toLowerCase() === 'g') { e.preventDefault(); if (e.shiftKey) { B.ungroup(); } else { B.group(); } return; }
		if (!state.selected || !onCanvas || target.closest('#tl-bd-overlay')) { return; }
		if (ARROWS.includes(e.key)) {
			if (mod && e.shiftKey) { e.preventDefault(); B.nudge(e.key === 'ArrowUp' || e.key === 'ArrowLeft' ? -1 : 1); } else if (e.altKey && !mod) { e.preventDefault(); C.keyResize(e.key, e.shiftKey); } else if (!mod && !e.shiftKey && !e.altKey) { e.preventDefault(); selectSibling(e.key); }
		} else if (e.key === 'Enter' && !mod && !e.altKey && !e.shiftKey && !state.placing && target.tagName !== 'A' && target.tagName !== 'BUTTON') {
			if (C.focusHandles()) { e.preventDefault(); }
		}
	}

	document.addEventListener('keydown', onKey);
	B.on('preview', (doc) => doc.addEventListener('keydown', onKey));
})();
