<?php
/**
 * Accessibility toolbar for visitors (2.14): larger text (root font-size steps), higher contrast (a class on <html> that
 * maps the design system colours to black on white), underlined links and reduced motion. The choice stays in this browser
 * (localStorage "tl-accessibility", no cookies) and is applied before the first paint. A popover like the appearance switcher:
 * keyboard and screen-reader accessible (a button with aria-pressed per option, Esc closes it).
 */
$options = ['text' => t('Larger text'), 'contrast' => t('High contrast'), 'underline' => t('Underline links'), 'calm' => t('Reduce motion')];
?>
<div class="tl-accessibility" data-accessibility>
<style>
:root.tl-text-1 { font-size: 112.5%; }
:root.tl-text-2 { font-size: 125%; }
:root.tl-contrast { --tl-color-text: #000000; --tl-color-background: #ffffff; --tl-color-surface: #ffffff; --tl-color-primary: #0000c8; --tl-color-secondary: #005a00; --tl-color-on-primary: #ffffff; --tl-color-muted: #000000; --tl-color-line: #000000; --text: #000000; --base: #ffffff; --text-muted: #000000; --line: #000000; color-scheme: light; }
:root.tl-contrast body { background: #ffffff; color: #000000; }
:root.tl-contrast a { color: #0000c8; }
:root.tl-contrast img, :root.tl-contrast video { filter: contrast(1.15); }
:root.tl-underline a { text-decoration: underline; text-decoration-thickness: 0.09em; text-underline-offset: 0.12em; }
:root.tl-calm *, :root.tl-calm *::before, :root.tl-calm *::after { animation-duration: 0.001ms !important; animation-iteration-count: 1 !important; transition-duration: 0.001ms !important; scroll-behavior: auto !important; }
.tl-accessibility { position: fixed; left: 12px; bottom: 56px; z-index: 998; font: 14px/1.4 system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif; }
.tl-accessibility-btn { display: inline-flex; align-items: center; gap: 6px; padding: 6px 10px; border: 1px solid #475467; border-radius: 999px; background: #ffffff; color: #14171f; cursor: pointer; font: inherit; }
.tl-accessibility-btn:focus-visible, .tl-accessibility-panel button:focus-visible { outline: 3px solid #0000c8; outline-offset: 2px; }
.tl-accessibility-panel { position: fixed; left: 12px; bottom: 100px; margin: 0; padding: 8px; border: 1px solid #475467; border-radius: 10px; background: #ffffff; color: #14171f; box-shadow: 0 12px 40px rgb(0 0 0 / 0.18); min-width: 220px; }
.tl-accessibility-panel button { display: flex; width: 100%; align-items: center; justify-content: space-between; gap: 12px; padding: 8px 10px; border: 0; border-radius: 6px; background: none; color: inherit; font: inherit; text-align: left; cursor: pointer; }
.tl-accessibility-panel button:hover { background: #f2f4f7; }
.tl-accessibility-panel button[aria-pressed="true"]::after { content: "✓"; font-weight: 700; }
.tl-accessibility-panel button[aria-pressed="false"]::after { content: ""; width: 1em; }
.tl-accessibility-panel .tl-accessibility-reset { margin-top: 4px; border-top: 1px solid #d0d5dd; border-radius: 0; font-size: 0.9em; }
@media print { .tl-accessibility { display: none; } }
</style>
<button type="button" class="tl-accessibility-btn" popovertarget="tl-accessibility-panel" aria-label="<?= e(t('Accessibility options')) ?>"><svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="4.5" r="1.8"/><path d="M5 9.5c4.7 1 9.3 1 14 0M12 10.5v4.5M12 15l-3 6M12 15l3 6"/></svg><span><?= e(t('Accessibility')) ?></span></button>
<div id="tl-accessibility-panel" class="tl-accessibility-panel" popover role="group" aria-label="<?= e(t('Accessibility options')) ?>">
<?php foreach ($options as $key => $labelText): ?>
	<button type="button" data-accessibility-option="<?= $key ?>" aria-pressed="false"><?= e($labelText) ?></button>
<?php endforeach ?>
	<button type="button" class="tl-accessibility-reset" data-accessibility-option=""><?= e(t('Default settings')) ?></button>
</div>
<script>
(function () {
	var KEY = 'tl-accessibility', root = document.documentElement, box = document.querySelector('[data-accessibility]');
	var read = function () { try { var v = JSON.parse(localStorage.getItem(KEY) || '{}'); return v && typeof v === 'object' ? v : {}; } catch (e) { return {}; } };
	var write = function (v) { try { localStorage.setItem(KEY, JSON.stringify(v)); } catch (e) { /* storage unavailable – the choice lasts for this page */ } };
	var apply = function (v) {
		root.classList.remove('tl-text-1', 'tl-text-2', 'tl-contrast', 'tl-underline', 'tl-calm');
		if (v.text) { root.classList.add('tl-text-' + v.text); }
		['contrast', 'underline', 'calm'].forEach(function (k) { if (v[k]) { root.classList.add('tl-' + k); } });
		box.querySelectorAll('[data-accessibility-option]').forEach(function (b) {
			var k = b.getAttribute('data-accessibility-option');
			if (k) { b.setAttribute('aria-pressed', v[k] ? 'true' : 'false'); }
		});
	};
	var state = read();
	apply(state);
	box.addEventListener('click', function (e) {
		var b = e.target.closest('[data-accessibility-option]');
		if (!b) { return; }
		var k = b.getAttribute('data-accessibility-option');
		if (!k) { state = {}; } else if (k === 'text') { state.text = ((state.text || 0) + 1) % 3; } else { state[k] = !state[k]; }
		write(state);
		apply(state);
	});
})();
</script>
</div>
