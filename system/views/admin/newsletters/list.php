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
echo $app->view->render('admin/list/head', ['count' => (string) count($newsletters),
    'links' => [[$app->url('admin.php?module=subscribers'), t('Confirmed subscribers: %d', $confirmed)]], 'primary' => [$module->url('new'), t('New newsletter')]]);
?>
<?php if ($problem !== null): ?>
<p class="notice notice-warning"><?= e(t($problem)) ?></p>
<?php endif ?>
<?php if ($newsletters === []): ?>
<?= $app->view->render('admin/empty', ['icon' => 'mailing', 'heading' => t('No newsletters yet.'), 'text' => t('Send your latest news to subscribers in an e-mail that follows the look of your site – colours, fonts and logo come from the design system.'), 'action' => [$module->url('new'), t('Write a newsletter')]]) ?>
<?php else: ?>
<div class="tab-wrap">
<table class="listing">
<thead><tr><th scope="col"><?= e(t('Subject')) ?></th><th scope="col"><?= e(t('Status')) ?></th><th scope="col"><?= e(t('Recipients')) ?></th><th scope="col"><?= e(t('Sent')) ?></th><th scope="col"><?= e(t('Failed')) ?></th><th scope="col"><span class="visually-hidden"><?= e(t('Actions')) ?></span></th></tr></thead>
<tbody>
<?php foreach ($newsletters as $n):
    $edit = $module->url('edit', ['id' => $n['public_id']]);
    $out = $n['status'] === 'sending' || $n['status'] === 'sent';
    [$kind, $label, $note] = match ($n['status']) {
        'sent' => ['live', t('Sent'), format_date((string) $n['finished_at'], true)],
        'sending' => ['planned', t('Sending'), t('%d of %d', (int) $n['sent_count'] + (int) $n['failed_count'], (int) $n['recipients'])],
        'scheduled' => ['planned', t('Scheduled'), format_date((string) $n['scheduled_at'], true)],
        default => ['draft', t('Draft'), null],
    };
?>
<tr>
	<td class="cell-main"><div class="cell-text"><a class="cell-title" href="<?= e($edit) ?>"><?= e($n['subject']) ?></a></div></td>
	<td><?= $app->view->render('admin/list/status', ['kind' => (int) $n['failed_count'] > 0 ? 'error' : $kind, 'label' => $label, 'notes' => $note !== null ? [['text' => $note, 'kind' => 'neutral']] : []]) ?></td>
	<td class="number"><?= $out ? (int) $n['recipients'] : '—' ?></td>
	<td class="number"><?= $out ? (int) $n['sent_count'] : '—' ?></td>
	<td class="number"><?= (int) $n['failed_count'] > 0 ? '<strong>' . (int) $n['failed_count'] . '</strong>' : ($n['status'] === 'sent' ? '0' : '—') ?></td>
	<td class="cell-actions"><?= $app->view->render('admin/list/actions', ['id' => $n['public_id'], 'name' => $n['subject'], 'edit' => $edit, 'csrf' => $csrf,
        'menu' => $n['status'] === 'sending' ? [] : [['post' => $module->url('delete'), 'fields' => ['id' => $n['public_id']], 'label' => t('Delete'), 'confirm' => t('Delete the newsletter?'), 'danger' => true]]]) ?></td>
</tr>
<?php endforeach ?>
</tbody>
</table>
</div>
<p class="help"><?= e(t('Nothing tracks whether subscribers open the e-mail. Links to the site carry utm parameters, so Statistics show the visits the newsletter brought.')) ?></p>
<?php endif ?>
