<?php
/**
 * A whistleblowing case (2.14) – for the chosen readers only: the decrypted report, the attachments, the messages, a reply
 * to the reporter and the status.
 *
 * @var Kaleta\Core\App $app
 * @var Kaleta\Admin\Modules\Whistleblowing $module
 * @var string $csrf
 * @var array<string, mixed> $case
 * @var array{text: string, contact: string, attachments: list<array{name: string, path: string, size: int}>} $contents
 * @var list<array{from: string, text: string, created_at: string}> $messages
 * @var array{acknowledgement: bool, feedback: bool} $overdue
 * @var array{acknowledge_by: string, feedback_due: string} $deadlines
 */
use Kaleta\Core\Whistleblowing;

?>
<p class="navigace-radek"><a class="navigace" href="<?= e($module->url()) ?>">← <?= e(t('All cases')) ?></a></p>
<div class="formular">
<dl class="poptavka">
	<dt><?= e(t('Received')) ?></dt><dd><?= e(format_date((string) $case['created_at'], true)) ?></dd>
	<dt><?= e(t('Status')) ?></dt><dd><span class="stitek<?= $case['status'] === 'closed' ? ' stitek-vydano' : ($case['status'] === 'received' ? ' stitek-koncept' : '') ?>"><?= e(t(Whistleblowing::STATUSES[$case['status']] ?? (string) $case['status'])) ?></span></dd>
	<dt><?= e(t('Acknowledge by')) ?></dt><dd><?= $case['acknowledged_at'] !== null ? e(t('acknowledged %s', format_date((string) $case['acknowledged_at'], true))) : e(format_date($deadlines['acknowledge_by'], true)) . ($overdue['acknowledgement'] ? ' <span class="stitek stitek-chyba">' . e(t('overdue')) . '</span>' : '') ?></dd>
	<dt><?= e(t('Feedback by')) ?></dt><dd><?= $case['status'] === 'closed' ? e(t('closed %s', format_date((string) $case['closed_at'], true))) : e(format_date((string) $case['feedback_due'], true)) . ($overdue['feedback'] ? ' <span class="stitek stitek-chyba">' . e(t('overdue')) . '</span>' : '') ?></dd>
	<dt><?= e(t('Reporter')) ?></dt><dd><?= $contents['contact'] !== '' ? nl2br(e($contents['contact'])) : '<span class="napoveda">' . e(t('anonymous')) . '</span>' ?></dd>
	<dt><?= e(t('Report')) ?></dt><dd class="wb-text"><?= nl2br(e($contents['text'])) ?></dd>
<?php if ($contents['attachments'] !== []): ?>
	<dt><?= e(t('Attachments')) ?></dt><dd><?php foreach ($contents['attachments'] as $i => $a): ?><a href="<?= e($module->url('attachment', ['id' => (int) $case['id'], 'index' => $i])) ?>"><?= e($a['name']) ?></a> <span class="smltxt">(<?= e(Kaleta\Core\Files::size((int) $a['size'])) ?>)</span><br><?php endforeach ?></dd>
<?php endif ?>
</dl>
<h2><?= e(t('Messages')) ?></h2>
<?php if ($messages === []): ?>
<p class="smltxt"><?= e(t('No messages yet. Your first answer acknowledges the receipt.')) ?></p>
<?php else: ?>
<?php foreach ($messages as $m): ?>
<p><strong><?= e(t($m['from'] === 'handler' ? 'Handler' : 'Reporter')) ?></strong> · <span class="smltxt"><?= e(format_date($m['created_at'], true)) ?></span><br><?= nl2br(e($m['text'])) ?></p>
<?php endforeach ?>
<?php endif ?>
<form method="post" action="<?= e($module->url('reply')) ?>">
	<?= $csrf ?><input type="hidden" name="id" value="<?= (int) $case['id'] ?>">
	<div class="radek"><label for="wb-reply"><?= e(t('Reply to the reporter')) ?></label><div><textarea class="textbox nizky" id="wb-reply" name="text" rows="5" maxlength="<?= Whistleblowing::MAX_MESSAGE ?>" required></textarea>
		<span class="napoveda"><?= e(t('The reporter reads it after opening the case with the case number and the access code. Nothing is e-mailed.')) ?></span></div></div>
	<p class="tlacitka"><button class="tl" type="submit"><?= e(t('Send the reply')) ?></button></p>
</form>
<form class="vradku" method="post" action="<?= e($module->url('status')) ?>">
	<?= $csrf ?><input type="hidden" name="id" value="<?= (int) $case['id'] ?>">
	<label for="wb-status"><?= e(t('Status')) ?></label> <select id="wb-status" name="status">
<?php foreach (Whistleblowing::STATUSES as $key => $label): ?>
		<option value="<?= e($key) ?>"<?= $case['status'] === $key ? ' selected' : '' ?>><?= e(t($label)) ?></option>
<?php endforeach ?>
	</select> <button class="navigace" type="submit"><?= e(t('Change the status')) ?></button>
	<span class="napoveda"><?= e(t('A closed case is deleted after the retention period set in the channel.')) ?></span>
</form>
</div>
