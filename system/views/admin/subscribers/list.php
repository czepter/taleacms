<?php
/**
 * @var Talea\Admin\Modules\Subscribers $module
 * @var Talea\Core\App $app
 * @var string $csrf
 * @var list<array<string, mixed>> $subscribers
 * @var int $total
 * @var int $confirmed
 * @var string $search
 * @var int $pageNumber
 * @var string $service connected mailing service (empty = none)
 * @var array{pending: ?string, failed: ?string} $queue
 */
use Talea\Core\Newsletter;

$admin = $app->auth()->isAdmin();
echo $app->view->render('admin/list/head', ['count' => (string) $total,
    'secondary' => $confirmed > 0 ? '<a class="navigation" href="' . e($module->url('csv')) . '">' . e(t('Export confirmed (CSV)')) . ' · ' . $confirmed . '</a>' : '']);
?>
<p class="small-text"><?= e(t('Addresses from the Newsletter sign-up element. Only people who confirmed by the link in the e-mail count as subscribers. Send them news under Newsletters, or with your own tool – the export includes the unsubscribe link.')) ?></p>
<?php if ($service !== ''): ?>
<div class="notice notice-actions">
	<p><?= e(t('Confirmed subscribers go to %s automatically; those who unsubscribe are removed from it.', t(Newsletter::SERVICES[$service][0]))) ?>
	<?= (int) $queue['pending'] > 0 ? e(t('Waiting to be sent: %d.', (int) $queue['pending'])) : '' ?> <?= (int) $queue['failed'] > 0 ? '<strong>' . e(t('Failed: %d.', (int) $queue['failed'])) . '</strong>' : '' ?></p>
<?php if ($admin): ?>
	<div class="navigation-row"><form class="inline" method="post" action="<?= e($module->url('sync')) ?>"><?= $csrf ?><button class="navigation" type="submit"><?= e(t('Send all confirmed subscribers to the service')) ?></button></form>
	<?php if ((int) $queue['failed'] > 0): ?><form class="inline" method="post" action="<?= e($module->url('retry')) ?>"><?= $csrf ?><button class="navigation" type="submit"><?= e(t('Try the failed ones again')) ?></button></form><?php endif ?>
	<a href="<?= e($app->url('admin.php?module=extensions#newsletter')) ?>"><?= e(t('Service settings')) ?></a></div>
<?php endif ?>
</div>
<?php elseif ($admin): ?>
<p class="small-text"><?= e(t('Using Brevo, MailerLite, Mailchimp, Ecomail or SmartEmailing? Once connected in Features, the site sends confirmed subscribers straight to your list.')) ?> <a href="<?= e($app->url('admin.php?module=extensions#newsletter')) ?>"><?= e(t('Connect a service')) ?></a></p>
<?php endif ?>
<?= $app->view->render('admin/list/toolbar', ['tabs' => [], 'tabsLabel' => '', 'action' => $app->url('admin.php'), 'hidden' => ['module' => 'subscribers'],
    'search' => ['name' => 'search', 'value' => $search, 'placeholder' => t('Search e-mail'), 'label' => t('Search e-mail')]]) ?>
<?php if ($subscribers === []): ?>
<?= $app->view->render('admin/empty', ['icon' => 'newsletter_signup', 'heading' => t($search !== '' ? 'Nothing found.' : 'No subscribers yet.'), 'text' => t('Put the Newsletter sign-up element on the website – for example in the footer.')]) ?>
<?php else: ?>
<div class="tab-wrap"><table class="listing">
<thead><tr><th scope="col"><?= e(t('Email')) ?></th><th scope="col"><?= e(t('Status')) ?></th><?php if ($service !== ''): ?><th scope="col"><?= e(t('Service')) ?></th><?php endif ?><th scope="col"><?= e(t('Subscribed')) ?></th><th scope="col"><span class="visually-hidden"><?= e(t('Actions')) ?></span></th></tr></thead>
<tbody>
<?php foreach ($subscribers as $o): ?>
<tr>
	<td class="cell-main"><div class="cell-text"><span class="cell-title"><?= bdi($o['email']) ?></span><?= (string) $o['source'] !== '' ? '<span class="cell-sub">' . e($o['source']) . '</span>' : '' ?></div></td>
	<td><?= $app->view->render('admin/list/status', (int) $o['status'] === 1 ? ['kind' => 'live', 'label' => t('confirmed')] : ['kind' => 'hidden', 'label' => t('awaiting confirmation')]) ?></td>
<?php if ($service !== ''): ?>
	<td><?= match ((string) $o['sync']) {
        'ok' => $app->view->render('admin/list/status', ['kind' => 'live', 'label' => t('sent')]),
        'pending' => $app->view->render('admin/list/status', ['kind' => 'draft', 'label' => t('waiting')]),
        'error' => $app->view->render('admin/list/status', ['kind' => 'error', 'label' => t('error'), 'note' => mb_strimwidth(t((string) $o['sync_error']), 0, 80, '…'), 'noteKind' => 'error']),
        default => '—',
    } ?></td>
<?php endif ?>
	<td class="cell-weak"><?= e(format_ago($o['created_at'])) ?></td>
	<td class="cell-actions"><?= $app->view->render('admin/list/actions', ['id' => $o['public_id'], 'name' => (string) $o['email'], 'edit' => null, 'csrf' => $csrf, 'menu' => [
        ['post' => $module->url('delete'), 'fields' => ['subscriber_id' => $o['public_id']], 'label' => t('Delete'), 'confirm' => t('Remove the address from the subscriber list?'), 'danger' => true],
    ]]) ?></td>
</tr>
<?php endforeach ?>
</tbody>
</table></div>
<?php if ($total > 100): ?>
<p class="navigation-row">
<?php if ($pageNumber > 1): ?><a class="navigation" href="<?= e($module->url('', ['search' => $search, 'page' => $pageNumber - 1])) ?>"><?= e(t('Previous')) ?></a><?php endif ?>
<?php if ($pageNumber * 100 < $total): ?><a class="navigation" href="<?= e($module->url('', ['search' => $search, 'page' => $pageNumber + 1])) ?>"><?= e(t('Next')) ?></a><?php endif ?>
</p>
<?php endif ?>
<?php endif ?>
