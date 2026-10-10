<?php
/**
 * Comment mode of a shared draft preview (2.15, Front\DraftComments): a floating button, a small form with the visitor's name
 * and the comment, and a script that attaches the comment to the element the visitor clicks (data-tl-id) or quotes the text
 * they selected. Self-contained – the preview page has no other script of its own.
 *
 * @var string $target      signed preview target, e.g. page:12
 * @var string $key     the preview key (it allows comments)
 * @var string $back     where the form comes back to – the preview address
 * @var string $action     POST address
 * @var string $result '' | ok | error | limit – the result of the previous send
 */
?>
<div class="tl-comments" id="tl-comment" data-tl-comments>
<style>
.tl-comments{position:fixed;right:1rem;bottom:1rem;z-index:2147482000;font:14px/1.45 system-ui,sans-serif;color:#16181d}
.tl-comments-btn{display:inline-flex;align-items:center;gap:.5rem;padding:.7rem 1.1rem;border:0;border-radius:999px;background:#16181d;color:#fff;font:600 14px/1 system-ui,sans-serif;cursor:pointer;box-shadow:0 6px 24px rgba(0,0,0,.25)}
.tl-comments-panel{position:fixed;right:1rem;bottom:4.5rem;width:min(22rem,calc(100vw - 2rem));max-height:calc(100vh - 6rem);overflow:auto;padding:1rem;border-radius:12px;background:#fff;color:#16181d;box-shadow:0 10px 40px rgba(0,0,0,.3)}
.tl-comments-panel h2{margin:0 0 .5rem;font-size:1.05rem}
.tl-comments-panel p{margin:0 0 .6rem}
.tl-comments-panel label{display:block;margin:0 0 .6rem;font-weight:600}
.tl-comments-panel input[type=text],.tl-comments-panel textarea{display:block;width:100%;box-sizing:border-box;margin-top:.25rem;padding:.5rem;border:1px solid #c6c9d0;border-radius:8px;background:#fff;color:#16181d;font:inherit;font-weight:400}
.tl-comments-panel textarea{min-height:6rem;resize:vertical}
.tl-comments-target,.tl-comments-quote{padding:.4rem .6rem;border-radius:8px;background:#f1f3f6;font-size:13px}
.tl-comments-target button{margin-left:.4rem;border:0;background:none;color:inherit;font:inherit;cursor:pointer;text-decoration:underline}
.tl-comments-note{color:#5a5f6b;font-size:13px}
.tl-comments-result{padding:.5rem .7rem;border-radius:8px;background:#e6f4ea;color:#14532d}
.tl-comments-result.error{background:#fdecea;color:#7f1d1d}
.tl-comment-selected{outline:3px solid #ff4f2e !important;outline-offset:2px}
[data-tl-comments-mode] [data-tl-id]{cursor:crosshair}
</style>
<button type="button" class="tl-comments-btn" aria-expanded="false" aria-controls="tl-comment-panel"><?= e(t('Comment on this draft')) ?></button>
<form class="tl-comments-panel" id="tl-comment-panel" method="post" action="<?= e($action) ?>" hidden>
	<h2><?= e(t('Comment on this draft')) ?></h2>
<?php if ($result === 'ok'): ?>
	<p class="tl-comments-result" role="status"><?= e(t('Thank you, your comment was sent to the people who edit this page.')) ?></p>
<?php elseif ($result === 'limit'): ?>
	<p class="tl-comments-result error" role="status"><?= e(t('That is a lot of comments in a short time – please try again in a few minutes.')) ?></p>
<?php elseif ($result === 'error'): ?>
	<p class="tl-comments-result error" role="status"><?= e(t('The comment could not be saved. Write your name and the comment and try again.')) ?></p>
<?php endif ?>
	<p class="tl-comments-note"><?= e(t('Click a part of the page to attach the comment to it, or select a piece of text to quote it.')) ?></p>
	<p class="tl-comments-target" data-tl-comment-element hidden><?= e(t('Attached to:')) ?> <span></span><button type="button"><?= e(t('detach')) ?></button></p>
	<p class="tl-comments-quote" data-tl-comment-quote hidden>„<span></span>“</p>
	<input type="hidden" name="target" value="<?= e($target) ?>"><input type="hidden" name="key" value="<?= e($key) ?>"><input type="hidden" name="back" value="<?= e($back) ?>">
	<input type="hidden" name="element" value=""><input type="hidden" name="quote" value="">
	<div style="position:absolute;left:-9999px" aria-hidden="true"><label><?= e(t('Leave this field empty')) ?> <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>
	<label><?= e(t('Your name')) ?> <input type="text" name="name" required maxlength="<?= Talea\Core\DraftComments::MAX_NAME ?>" autocomplete="name"></label>
	<label><?= e(t('Comment')) ?> <textarea name="text" required maxlength="<?= Talea\Core\DraftComments::MAX_TEXT ?>"></textarea></label>
	<p><button type="submit" class="tl-comments-btn"><?= e(t('Send comment')) ?></button></p>
	<p class="tl-comments-note"><?= e(t('The comment goes to the people who edit this page. Nothing on the site changes by itself.')) ?></p>
</form>
<script>
(function () {
	var box = document.getElementById('tl-comment'), button = box.querySelector('.tl-comments-btn'), form = document.getElementById('tl-comment-panel');
	var elementField = form.elements.element, quoteField = form.elements.citace, elementRow = form.querySelector('[data-tl-comment-element]'), quoteRow = form.querySelector('[data-tl-comment-quote]');
	var selected = null;
	function open(on) {
		form.hidden = !on; button.setAttribute('aria-expanded', on ? 'true' : 'false');
		if (on) { document.documentElement.setAttribute('data-tl-comments-mode', ''); form.elements.name.focus(); } else { document.documentElement.removeAttribute('data-tl-comments-mode'); }
	}
	function attach(target) {
		if (selected) { selected.classList.remove('tl-comment-selected'); }
		selected = target;
		if (target) {
			target.classList.add('tl-comment-selected');
			elementField.value = target.getAttribute('data-tl-id');
			elementRow.querySelector('span').textContent = (target.textContent || '').trim().replace(/\s+/g, ' ').slice(0, 60) || elementField.value;
		} else { elementField.value = ''; }
		elementRow.hidden = !target;
	}
	button.addEventListener('click', function () { open(form.hidden); });
	elementRow.querySelector('button').addEventListener('click', function () { attach(null); });
	document.addEventListener('click', function (e) {
		if (form.hidden || box.contains(e.target)) { return; }
		var target = e.target.closest('[data-tl-id]');
		if (!target) { return; }
		e.preventDefault(); // in comment mode a click chooses the element, it does not follow links
		attach(target);
	}, true);
	document.addEventListener('mouseup', function (e) {
		if (form.hidden || box.contains(e.target)) { return; }
		var text = String(window.getSelection && window.getSelection()).trim().replace(/\s+/g, ' ').slice(0, <?= Talea\Core\DraftComments::MAX_QUOTE ?>);
		if (text) { quoteField.value = text; quoteRow.querySelector('span').textContent = text; quoteRow.hidden = false; }
	});
	try { form.elements.name.value = localStorage.getItem('tl-comment-name') || ''; } catch (e) { /* storage blocked */ }
	form.addEventListener('submit', function () { try { localStorage.setItem('tl-comment-name', form.elements.name.value); } catch (e) { /* storage blocked */ } });
	if (<?= $result !== '' ? 'true' : 'false' ?>) { open(true); }
})();
</script>
</div>
