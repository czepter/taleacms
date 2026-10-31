/* Talea – page builder, the entry module (system/views/admin/pages/builder.php loads only this file, as type="module").
 * No libraries and no build step: native ES modules, imported without a version key, so the server sends image/builder/ with
 * Cache-Control: no-cache (docker/Caddyfile, system/nginx.example.conf, .htaccess) and a browser never mixes an old part with a new one.
 *
 *   core.js       state, data, helpers, the tree, changes, history and saving, selection and tree edits, keys
 *   canvas.js     the canvas (the real page in an iframe), dropping on it, the clipboard
 *   panels.js     the top bar (with the tour) and the left panel (Add, Structure)
 *   inspector.js  the right panel: content, style, advanced, shared classes
 *   overlay.js, handles.js, keys.js, compose.js, media.js, background.js   direct manipulation on the canvas (docs/specs/visual-compose.md)
 *   structured.js the control of the schema.org element's data
 *   form-logic.js the rules and results of a form (its logic overlay)
 *
 * The parts import core.js and each other without a circle; what core.js calls in them is registered here in `ui`.
 */
import { D, T, TYPY, state, root, dom, ui, csrf, el, icon, on, find, contains, newElement, withNewIds, applyChange, writeStyle, selection, selectMany, toggleSelected,
	selectedIds, topSelected, removeSelected, duplicateSelected, group, ungroup, nudge, placeElements, redrawPanels, redraw, setState, askName, labelText, query, save,
	keys, rejectInvalid, refreshToken, adoptSanitized } from './core.js';
import { previewSize, refreshPreview, markInPreview, showSpot, canvasSpot, dropAt, startPlacing, endPlacing, editOnCanvas, copy, pasteFromClipboard, onPaste,
	previewDoc, previewScale, endGesture } from './canvas.js';
import { createBar, redrawBar, redrawLeft, redrawTree, hint, tour, openAdd } from './panels.js';
import { redrawRight, datalists } from './inspector.js';
import './overlay.js';
import './handles.js';
import './keys.js';
import './compose.js';
import './media.js';
import './background.js';

Object.assign(ui, { redrawBar, redrawLeft, redrawTree, hint, redrawRight, refreshPreview, markInPreview, showSpot, endPlacing, copy, pasteFromClipboard });

/** A handle for the browser test (tools/test-browser.mjs) only: no module of the editor reads it, they import what they need. */
window.taleaBuilder = {
	D, T, TYPY, state, el, icon, on, find, contains, newElement, withNewIds, applyChange, writeStyle, selection, selectMany, toggleSelected, selectedIds, topSelected,
	remove: removeSelected, duplicate: duplicateSelected, group, ungroup, nudge, placeElements, dropAt, canvasSpot, showSpot, startPlacing, endPlacing, editOnCanvas,
	redrawPanels, redrawRight, setState, askName, labelText, query, refreshPreview, markInPreview, save, openAdd, previewDoc, scale: previewScale, endGesture,
};

createBar();
dom.left = el('aside', { class: 'bd-left', 'aria-label': T('Elements and structure') });
dom.right = el('aside', { class: 'bd-right', 'aria-label': T('Properties') });
dom.frame2 = el('div', { class: 'bd-frame', 'data-bp': 'base' });
dom.scale = el('span', { class: 'bd-scale', 'aria-hidden': 'true' });
dom.rail = el('nav', { class: 'bd-rail', 'aria-label': T('Elements and structure') });
root.append(dom.left, el('main', { class: 'bd-canvas' }, dom.rail, dom.frame2, dom.scale), dom.right, datalists);
new ResizeObserver(() => previewSize(dom.preview)).observe(dom.frame2);
root.hidden = false;
redraw();
const chosen = new URLSearchParams(window.location.search).get('select'); // Website mode's "Add a type" opens with the new element selected
if (chosen) { selection(chosen); }
refreshPreview();
document.addEventListener('keydown', keys);
document.addEventListener('paste', onPaste);
// no tour on a phone: there the builder shows only "needs a bigger screen" instead of itself (builder.css, same width);
// it is not marked as seen, so it starts on the first opening on a desktop or tablet
const narrow = window.matchMedia('(max-width: 719px)').matches;
try { if (!narrow && !localStorage.getItem('tl-bd-tour')) { setTimeout(() => tour(0), 800); } } catch (e) { /* private mode */ }
state.saved = JSON.stringify(state.build);
root.addEventListener('focusout', () => setTimeout(adoptSanitized, 0));
window.addEventListener('focus', () => { if (state.retries && rejectInvalid()) { (state.signed_in ? refreshToken() : Promise.resolve()).then(save); } });
// unsaved changes: the browser asks whether to really leave the page; if so, they are sent once more in the background (keepalive)
window.addEventListener('beforeunload', (e) => { if (rejectInvalid() || state.saving) { save(); e.preventDefault(); e.returnValue = ''; } });
window.addEventListener('pagehide', () => {
	if (!rejectInvalid() || state.conflict) { return; }
	const f = new FormData();
	f.append('_csrf', csrf); f.append('build', JSON.stringify(state.build)); f.append('version', state.version);
	try { fetch(D.urls.save, { method: 'POST', body: f, credentials: 'same-origin', keepalive: true }); } catch (e) { /* over the keepalive limit – the warning has already been shown */ }
});
