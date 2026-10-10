<?php
/**
 * Newsletters: drafts, scheduled, being sent and sent, with counts.
 *
 * @var Talea\Core\App $app
 * @var Talea\Admin\Modules\Newsletters $module
 * @var string $csrf
 * @var list<array<string, mixed>> $newsletters
 * @var int $confirmed confirmed subscribers
 * @var ?string $problem why the site cannot send now
 */
?>
<p class="navigation-row"><a class="btn" href="<?= e($module->url('new')) ?>"><?= e(t('New newsletter')) ?></a>
	<a class="navigation" href="<?= e($app->url('admin.php?module=subscribers')) ?>"><?= e(t('Confirmed subscribers: %d', $confirmed)) ?></a></p>
<?php if ($problem !== null): ?>
<p class="notice notice-warning"><?= e(t($problem)) ?></p>
<?php endif ?>
<?php if ($newsletters === []): ?>
<?= $app->view->render('admin/empty', ['icon' => 'mailing', 'heading' => t('No newsletters yet.'), 'text' => t('Send your latest news to subscribers in an e-mail that follows the look of your site – colours, fonts and logo come from the design system.'), 'action' => [$module->url('new'), t('Write a newsletter')]]) ?>
<?php else: ?>
<div class="tab-wrap">
<table class="listing">
<thead><tr><th scope="col"><?= e(t('Subject')) ?></th><th scope="col"><?= e(t('Status')) ?></th><th scope="col"><?= e(t('Recipients')) ?></th><th scope="col"><?= e(t('Sent')) ?></th><th scope="col"><?= e(t('Failed')) ?></th><th scope="col"><?= e(t('Actions')) ?></th></tr></thead>
<tbody>
<?php foreach ($newsletters as $n): ?>
<tr>
	<td><a href="<?= e($module->url('edit', ['id' => $n['public_id']])) ?>"><strong><?= e($n['subject']) ?></strong></a></td>
	<td><?= match ($n['status']) {
        'sent' => '<span class="badge badge-published">' . e(t('Sent')) . '</span> <span class="help">' . e(format_date((string) $n['finished_at'], true)) . '</span>',
        'sending' => '<span class="badge">' . e(t('Sending')) . '</span> <span class="help">' . e(t('%d of %d', (int) $n['sent_count'] + (int) $n['failed_count'], (int) $n['recipients'])) . '</span>',
        'scheduled' => '<span class="badge">' . e(t('Scheduled')) . '</span> <span class="help">' . e(format_date((string) $n['scheduled_at'], true)) . '</span>',
        default => '<span class="badge badge-draft">' . e(t('Draft')) . '</span>',
    } ?></td>
	<td class="number"><?= $n['status'] === 'sending' || $n['status'] === 'sent' ? (int) $n['recipients'] : '—' ?></td>
	<td class="number"><?= $n['status'] === 'sending' || $n['status'] === 'sent' ? (int) $n['sent_count'] : '—' ?></td>
	<td class="number"><?= (int) $n['failed_count'] > 0 ? '<strong>' . (int) $n['failed_count'] . '</strong>' : ($n['status'] === 'sent' ? '0' : '—') ?></td>
	<td class="actions"><a href="<?= e($module->url('edit', ['id' => $n['public_id']])) ?>"><?= e(in_array($n['status'], ['draft', 'scheduled'], true) ? t('Edit') : t('View')) ?></a>
<?php if ($n['status'] !== 'sending'): ?>
		· <form class="inline" method="post" action="<?= e($module->url('delete')) ?>" data-confirm="<?= e(t('Delete the newsletter?')) ?>"><?= $csrf ?><input type="hidden" name="id" value="<?= e($n['public_id']) ?>"><button class="navigation danger" type="submit"><?= e(t('Delete')) ?></button></form>
<?php endif ?>
	</td>
</tr>
<?php endforeach ?>
</tbody>
</table>
</div>
<p class="help"><?= e(t('Nothing tracks whether subscribers open the e-mail. Links to the site carry utm parameters, so Statistics show the visits the newsletter brought.')) ?></p>
<?php endif ?>
