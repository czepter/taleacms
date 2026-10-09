<?php
/**
 * Comment mode of a shared draft preview (2.15, Front\DraftComments): a floating button, a small form with the visitor's name
 * and the comment, and a script that attaches the comment to the element the visitor clicks (data-ka-id) or quotes the text
 * they selected. Self-contained – the preview page has no other script of its own.
 *
 * @var string $target      signed preview target, e.g. page:12
 * @var string $key     the preview key (it allows comments)
 * @var string $back     where the form comes back to – the preview address
 * @var string $action     POST address
 * @var string $result '' | ok | error | limit – the result of the previous send
 */
?>
<div class="ka-comments" id="ka-comment" data-ka-comments>
<style>
.ka-comments{position:fixed;right:1rem;bottom:1rem;z-index:2147482000;font:14px/1.45 system-ui,sans-serif;color:#16181d}
.ka-comments-btn{display:inline-flex;align-items:center;gap:.5rem;padding:.7rem 1.1rem;border:0;border-radius:999px;background:#16181d;color:#fff;font:600 14px/1 system-ui,sans-serif;cursor:pointer;box-shadow:0 6px 24px rgba(0,0,0,.25)}
.ka-comments-panel{position:fixed;right:1rem;bottom:4.5rem;width:min(22rem,calc(100vw - 2rem));max-height:calc(100vh - 6rem);overflow:auto;padding:1rem;border-radius:12px;background:#fff;color:#16181d;box-shadow:0 10px 40px rgba(0,0,0,.3)}
.ka-comments-panel h2{margin:0 0 .5rem;font-size:1.05rem}
.ka-comments-panel p{margin:0 0 .6rem}
.ka-comments-panel label{display:block;margin:0 0 .6rem;font-weight:600}
.ka-comments-panel input[type=text],.ka-comments-panel textarea{display:block;width:100%;box-sizing:border-box;margin-top:.25rem;padding:.5rem;border:1px solid #c6c9d0;border-radius:8px;background:#fff;color:#16181d;font:inherit;font-weight:400}
.ka-comments-panel textarea{min-height:6rem;resize:vertical}
.ka-comments-target,.ka-comments-quote{padding:.4rem .6rem;border-radius:8px;background:#f1f3f6;font-size:13px}
.ka-comments-target button{margin-left:.4rem;border:0;background:none;color:inherit;font:inherit;cursor:pointer;text-decoration:underline}
.ka-comments-note{color:#5a5f6b;font-size:13px}
.ka-comments-result{padding:.5rem .7rem;border-radius:8px;background:#e6f4ea;color:#14532d}
.ka-comments-result.error{background:#fdecea;color:#7f1d1d}
.ka-comment-selected{outline:3px solid #ff4f2e !important;outline-offset:2px}
[data-ka-comments-mode] [data-ka-id]{cursor:crosshair}
</style>
<button type="button" class="ka-comments-btn" aria-expanded="false" aria-controls="ka-comment-panel"><?= e(t('Comment on this draft')) ?></button>
<form class="ka-comments-panel" id="ka-comment-panel" method="post" action="<?= e($action) ?>" hidden>
	<h2><?= e(t('Comment on this draft')) ?></h2>
<?php if ($result === 'ok'): ?>
	<p class="ka-comments-result" role="status"><?= e(t('Thank you, your comment was sent to the people who edit this page.')) ?></p>
<?php elseif ($result === 'limit'): ?>
	<p class="ka-comments-result error" role="status"><?= e(t('That is a lot of comments in a short time – please try again in a few minutes.')) ?></p>
<?php elseif ($result === 'error'): ?>
	<p class="ka-comments-result error" role="status"><?= e(t('The comment could not be saved. Write your name and the comment and try again.')) ?></p>
<?php endif ?>
	<p class="ka-comments-note"><?= e(t('Click a part of the page to attach the comment to it, or select a piece of text to quote it.')) ?></p>
	<p class="ka-comments-target" data-ka-comment-element hidden><?= e(t('Attached to:')) ?> <span></span><button type="button"><?= e(t('detach')) ?></button></p>
	<p class="ka-comments-quote" data-ka-comment-quote hidden>„<span></span>“</p>
	<input type="hidden" name="target" value="<?= e($target) ?>"><input type="hidden" name="key" value="<?= e($key) ?>"><input type="hidden" name="back" value="<?= e($back) ?>">
	<input type="hidden" name="element" value=""><input type="hidden" name="quote" value="">
	<div style="position:absolute;left:-9999px" aria-hidden="true"><label><?= e(t('Leave this field empty')) ?> <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>
	<label><?= e(t('Your name')) ?> <input type="text" name="name" required maxlength="<?= Kaleta\Core\DraftComments::MAX_NAME ?>" autocomplete="name"></label>
	<label><?= e(t('Comment')) ?> <textarea name="text" required maxlength="<?= Kaleta\Core\DraftComments::MAX_TEXT ?>"></textarea></label>
	<p><button type="submit" class="ka-comments-btn"><?= e(t('Send comment')) ?></button></p>
	<p class="ka-comments-note"><?= e(t('The comment goes to the people who edit this page. Nothing on the site changes by itself.')) ?></p>
</form>
<script>
(function () {
	var box = document.getElementById('ka-comment'), button = box.querySelector('.ka-comments-btn'), form = document.getElementById('ka-comment-panel');
	var elementField = form.elements.element, quoteField = form.elements.citace, elementRow = form.querySelector('[data-ka-comment-element]'), quoteRow = form.querySelector('[data-ka-comment-quote]');
	var selected = null;
	function open(on) {
		form.hidden = !on; button.setAttribute('aria-expanded', on ? 'true' : 'false');
		if (on) { document.documentElement.setAttribute('data-ka-comments-mode', ''); form.elements.name.focus(); } else { document.documentElement.removeAttribute('data-ka-comments-mode'); }
	}
	function attach(target) {
		if (selected) { selected.classList.remove('ka-comment-selected'); }
		selected = target;
		if (target) {
			target.classList.add('ka-comment-selected');
			elementField.value = target.getAttribute('data-ka-id');
			elementRow.querySelector('span').textContent = (target.textContent || '').trim().replace(/\s+/g, ' ').slice(0, 60) || elementField.value;
		} else { elementField.value = ''; }
		elementRow.hidden = !target;
	}
	button.addEventListener('click', function () { open(form.hidden); });
	elementRow.querySelector('button').addEventListener('click', function () { attach(null); });
	document.addEventListener('click', function (e) {
		if (form.hidden || box.contains(e.target)) { return; }
		var target = e.target.closest('[data-ka-id]');
		if (!target) { return; }
		e.preventDefault(); // in comment mode a click chooses the element, it does not follow links
		attach(target);
	}, true);
	document.addEventListener('mouseup', function (e) {
		if (form.hidden || box.contains(e.target)) { return; }
		var text = String(window.getSelection && window.getSelection()).trim().replace(/\s+/g, ' ').slice(0, <?= Kaleta\Core\DraftComments::MAX_QUOTE ?>);
		if (text) { quoteField.value = text; quoteRow.querySelector('span').textContent = text; quoteRow.hidden = false; }
	});
	try { form.elements.name.value = localStorage.getItem('ka-comment-name') || ''; } catch (e) { /* storage blocked */ }
	form.addEventListener('submit', function () { try { localStorage.setItem('ka-comment-name', form.elements.name.value); } catch (e) { /* storage blocked */ } });
	if (<?= $result !== '' ? 'true' : 'false' ?>) { open(true); }
})();
</script>
</div>
