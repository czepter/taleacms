<?php
/**
 * A newsletter: the draft form with a preview, the test e-mail and sending; a newsletter being sent or sent is read-only.
 *
 * @var Kaleta\Core\App $app
 * @var Kaleta\Admin\Modules\Newsletters $module
 * @var string $csrf
 * @var array<string, mixed> $n
 * @var list<array<string, mixed>> $news published news items to choose from
 * @var list<string> $languages site languages (empty = one language)
 * @var bool $newsEnabled
 * @var int $confirmed confirmed subscribers
 * @var ?string $problem why the site cannot send now
 * @var bool $canPublish
 * @var string $email the signed-in user's address for the test
 */
use Kaleta\Core\Language;
use Kaleta\Core\Mailing;

$id = (int) $n['id'];
$editable = in_array($n['status'], ['draft', 'scheduled'], true);
$chosen = array_map('intval', array_filter(explode(',', (string) $n['news_ids'])));
?>
<?php if ($n['status'] === 'sending' || $n['status'] === 'sent'): ?>
<div class="notice">
	<p><strong><?= e(t($n['status'] === 'sent' ? 'Sent' : 'Sending')) ?></strong> · <?= e(t('Recipients')) ?>: <?= (int) $n['recipients'] ?> · <?= e(t('Sent')) ?>: <?= (int) $n['sent_count'] ?> · <?= e(t('Failed')) ?>: <?= (int) $n['failed_count'] ?></p>
	<p class="help"><?= e(t('Started %s', format_date((string) $n['started_at'], true))) ?><?= $n['finished_at'] !== null ? ' · ' . e(t('finished %s', format_date((string) $n['finished_at'], true))) : ' · ' . e(t('the e-mails go out in batches each time cron runs')) ?></p>
</div>
<?php else: ?>
<?php if ($problem !== null): ?>
<p class="notice notice-warning"><?= e(t($problem)) ?></p>
<?php endif ?>
<form class="form" method="post" action="<?= e($module->url('save')) ?>">
<?= $csrf ?>
<input type="hidden" name="id" value="<?= $id ?>">
<div class="row"><label for="subject"><?= e(t('Subject')) ?></label><div><input class="textfield wide" id="subject" name="subject" value="<?= e($n['subject']) ?>" maxlength="200" required></div></div>
<div class="row"><label for="preheader"><?= e(t('Preview text')) ?></label><div><input class="textfield wide" id="preheader" name="preheader" value="<?= e($n['preheader']) ?>" maxlength="200"><span class="help"><?= e(t('Shown next to the subject in the inbox. Optional.')) ?></span></div></div>
<div class="row"><label for="intro"><?= e(t('Introduction')) ?></label><div><textarea class="textfield wide" id="intro" name="intro" rows="6" maxlength="5000"><?= e($n['intro']) ?></textarea><span class="help"><?= e(t('Plain text; an empty line starts a new paragraph, web addresses become links.')) ?></span></div></div>
<?php if ($newsEnabled): ?>
<fieldset>
<legend><?= e(t('News')) ?></legend>
<div class="row"><span class="caption"><?= e(t('News in the e-mail')) ?></span><div class="options options-below-stacked">
	<label><input type="radio" name="news_mode" value="latest"<?= $n['news_mode'] === 'latest' ? ' checked' : '' ?>> <?= e(t('the latest')) ?> <span data-active-when="news_mode=latest"><input class="textfield number-short" type="number" name="news_count" min="1" max="<?= Mailing::MAX_NEWS ?>" size="3" value="<?= (int) $n['news_count'] ?>" aria-label="<?= e(t('Number of news items')) ?>"></span> <?= e(t('news items at the time of sending')) ?></label>
	<label><input type="radio" name="news_mode" value="chosen"<?= $n['news_mode'] === 'chosen' ? ' checked' : '' ?>> <?= e(t('chosen news items')) ?></label>
	<label><input type="radio" name="news_mode" value="none"<?= $n['news_mode'] === 'none' ? ' checked' : '' ?>> <?= e(t('no news – only the introduction and the button')) ?></label>
</div></div>
<div class="row" data-active-when="news_mode=chosen"><span class="caption"><?= e(t('Chosen news items')) ?></span><div class="options options-list">
<?php foreach ($news as $c): ?>
	<label><input type="checkbox" name="news_ids[]" value="<?= (int) $c['news_id'] ?>"<?= in_array((int) $c['news_id'], $chosen, true) ? ' checked' : '' ?>> <?= e(($c['language'] !== '' ? strtoupper((string) $c['language']) . ' · ' : '') . $c['title']) ?> <span class="help"><?= e(format_date((string) $c['published_at'])) ?></span></label>
<?php endforeach ?>
<?php if ($news === []): ?><p class="help"><?= e(t('No published news items yet.')) ?></p><?php endif ?>
</div></div>
</fieldset>
<?php else: ?>
<input type="hidden" name="news_mode" value="none">
<?php endif ?>
<fieldset>
<legend><?= e(t('Button')) ?></legend>
<div class="row"><label for="button_label"><?= e(t('Button text')) ?></label><div><input class="textfield" id="button_label" name="button_label" value="<?= e($n['button_label']) ?>" maxlength="80"><span class="help"><?= e(t('Optional, for example “See all news” or “Book an appointment”.')) ?></span></div></div>
<div class="row"><label for="button_url"><?= e(t('Button link')) ?></label><div><input class="textfield wide" id="button_url" name="button_url" value="<?= e($n['button_url']) ?>" maxlength="500" placeholder="/news"><span class="help"><?= e(t('A path on the site (/contact) or a full https:// address.')) ?></span></div></div>
</fieldset>
<?php if ($languages !== []): ?>
<div class="row"><label for="language"><?= e(t('Language')) ?></label><div><select id="language" name="language">
<?php foreach ($languages as $code): ?>
	<option value="<?= e($code) ?>"<?= ($n['language'] !== '' ? $n['language'] : $languages[0]) === $code ? ' selected' : '' ?>><?= e(Language::AVAILABLE[$code][0] ?? $code) ?></option>
<?php endforeach ?>
</select><span class="help"><?= e(t('The language of the footer texts and of the latest news items.')) ?></span></div></div>
<?php endif ?>
<p class="buttons"><input class="btn" type="submit" value="<?= e(t('Save draft')) ?>">
<?php if ($email !== ''): ?>
	<button class="navigation" type="submit" name="test" value="1"><?= e(t('Save and send a test to %s', $email)) ?></button>
<?php endif ?>
</p>
</form>
<?php endif ?>
<?php if ($id > 0): ?>
<h2><?= e(t('Preview')) ?></h2>
<iframe class="mailing-preview" src="<?= e($module->url('preview', ['id' => $id])) ?>" title="<?= e(t('Preview of the e-mail')) ?>" sandbox="allow-popups allow-popups-to-escape-sandbox"></iframe>
<?php if ($editable): ?>
<h2><?= e(t('Send to subscribers')) ?></h2>
<?php if ($n['status'] === 'scheduled'): ?>
<p><?= e(t('Scheduled for %s – the latest news items are taken at that moment.', format_date((string) $n['scheduled_at'], true))) ?></p>
<?php if ($canPublish): ?>
<form method="post" action="<?= e($module->url('unschedule')) ?>"><?= $csrf ?><input type="hidden" name="id" value="<?= $id ?>"><button class="navigation" type="submit"><?= e(t('Cancel scheduling')) ?></button></form>
<?php endif ?>
<?php elseif (!$canPublish): ?>
<p class="help"><?= e(t('Sending to subscribers needs the publishing permission – ask an editor or an administrator.')) ?></p>
<?php else: ?>
<form class="form" method="post" action="<?= e($module->url('send')) ?>" data-confirm="<?= e(t('Send the newsletter to %d confirmed subscribers? Save your changes first – the saved version goes out.', $confirmed)) ?>">
<?= $csrf ?>
<input type="hidden" name="id" value="<?= $id ?>">
<div class="row"><span class="caption"><?= e(t('When')) ?></span><div class="options">
	<label><input type="radio" name="when" value="now" checked> <?= e(t('now')) ?></label>
	<label><input type="radio" name="when" value="later"> <?= e(t('at')) ?> <span data-active-when="when=later"><input class="textfield" type="datetime-local" name="at" aria-label="<?= e(t('Date and time of sending')) ?>"></span></label>
</div></div>
<p class="buttons"><button class="btn" type="submit"<?= $problem !== null || $confirmed === 0 ? ' disabled' : '' ?>><?= e(t('Send to %d subscribers', $confirmed)) ?></button></p>
</form>
<?php endif ?>
<?php endif ?>
<?php endif ?>
<div class="navigation-row actions-bottom">
<a class="navigation" href="<?= e($module->url()) ?>"><?= e(t('All newsletters')) ?></a>
<?php if ($id > 0 && $n['status'] !== 'sending'): ?>
<form class="inline" method="post" action="<?= e($module->url('delete')) ?>" data-confirm="<?= e(t('Delete the newsletter?')) ?>"><?= $csrf ?><input type="hidden" name="id" value="<?= $id ?>"><button class="navigation danger" type="submit"><?= e(t('Delete the newsletter')) ?></button></form>
<?php endif ?>
</div>
