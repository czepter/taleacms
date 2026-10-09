<?php
/**
 * @var Kaleta\Admin\Modules\Subscribers $module
 * @var Kaleta\Core\App $app
 * @var string $csrf
 * @var list<array<string, mixed>> $subscribers
 * @var int $total
 * @var int $confirmed
 * @var string $search
 * @var int $pageNumber
 * @var string $service connected mailing service (empty = none)
 * @var array{pending: ?string, failed: ?string} $queue
 */
use Kaleta\Core\Newsletter;

$admin = $app->auth()->isAdmin();
?>
<p class="small-text"><?= e(t('Addresses from the Newsletter sign-up element. Only people who confirmed by the link in the e-mail count as subscribers. Send them news under Newsletters, or with your own tool – the export includes the unsubscribe link.')) ?></p>
<?php if ($service !== ''): ?>
<div class="notice">
	<p><?= e(t('Confirmed subscribers go to %s automatically; those who unsubscribe are removed from it.', t(Newsletter::SERVICES[$service][0]))) ?>
	<?= (int) $queue['pending'] > 0 ? e(t('Waiting to be sent: %d.', (int) $queue['pending'])) : '' ?> <?= (int) $queue['failed'] > 0 ? '<strong>' . e(t('Failed: %d.', (int) $queue['failed'])) . '</strong>' : '' ?></p>
<?php if ($admin): ?>
	<p><form class="inline" method="post" action="<?= e($module->url('sync')) ?>"><?= $csrf ?><button class="navigation" type="submit"><?= e(t('Send all confirmed subscribers to the service')) ?></button></form>
	<?php if ((int) $queue['failed'] > 0): ?><form class="inline" method="post" action="<?= e($module->url('retry')) ?>"><?= $csrf ?><button class="navigation" type="submit"><?= e(t('Try the failed ones again')) ?></button></form><?php endif ?>
	<a href="<?= e($app->url('admin.php?module=extensions#newsletter')) ?>"><?= e(t('Service settings')) ?></a></p>
<?php endif ?>
</div>
<?php elseif ($admin): ?>
<p class="small-text"><?= e(t('Using Brevo, MailerLite, Mailchimp, Ecomail or SmartEmailing? Once connected in Features, the site sends confirmed subscribers straight to your list.')) ?> <a href="<?= e($app->url('admin.php?module=extensions#newsletter')) ?>"><?= e(t('Connect a service')) ?></a></p>
<?php endif ?>
<form class="navigation-row" method="get" action="<?= e($app->url('admin.php')) ?>" role="search">
	<input type="hidden" name="module" value="subscribers">
	<input class="textfield" type="search" name="search" value="<?= e($search) ?>" placeholder="<?= e(t('Search e-mail')) ?>" aria-label="<?= e(t('Search e-mail')) ?>">
	<button class="navigation" type="submit"><?= e(t('Filter')) ?></button>
<?php if ($confirmed > 0): ?>
	<a class="btn" href="<?= e($module->url('csv')) ?>"><?= e(t('Export confirmed (CSV)')) ?> · <?= $confirmed ?></a>
<?php else: ?>
	<button class="btn" type="button" disabled><?= e(t('Export confirmed (CSV)')) ?> · 0</button>
<?php endif ?>
</form>
<?php if ($subscribers === []): ?>
<?= $app->view->render('admin/empty', ['icon' => 'newsletter_signup', 'heading' => t($search !== '' ? 'Nothing found.' : 'No subscribers yet.'), 'text' => t('Put the Newsletter sign-up element on the website – for example in the footer.')]) ?>
<?php else: ?>
<div class="tab-wrap"><table class="listing">
<thead><tr><th scope="col"><?= e(t('Email')) ?></th><th scope="col"><?= e(t('Status')) ?></th><?php if ($service !== ''): ?><th scope="col"><?= e(t('Service')) ?></th><?php endif ?><th scope="col"><?= e(t('Subscribed')) ?></th><th scope="col"><?= e(t('Page')) ?></th><th scope="col"><?= e(t('Actions')) ?></th></tr></thead>
<tbody>
<?php foreach ($subscribers as $o): ?>
<tr<?= (int) $o['status'] === 1 ? '' : ' class="unpublished"' ?>>
	<td><?= e($o['email']) ?></td>
	<td><?= (int) $o['status'] === 1 ? '<span class="badge badge-published">' . e(t('confirmed')) . '</span>' : e(t('awaiting confirmation')) ?></td>
<?php if ($service !== ''): ?>
	<td><?= match ((string) $o['sync']) {
        'ok' => '<span class="badge badge-published">' . e(t('sent')) . '</span>',
        'pending' => '<span class="badge">' . e(t('waiting')) . '</span>',
        'error' => '<span class="badge badge-draft" title="' . e(t((string) $o['sync_error'])) . '">' . e(t('error')) . '</span> <span class="help">' . e(mb_strimwidth(t((string) $o['sync_error']), 0, 80, '…')) . '</span>',
        default => '—',
    } ?></td>
<?php endif ?>
	<td class="number"><?= e(format_date($o['created_at'], true)) ?></td>
	<td class="small-text"><?= e($o['source']) ?></td>
	<td class="actions"><form class="inline" method="post" action="<?= e($module->url('delete')) ?>" data-confirm="<?= e(t('Remove the address from the subscriber list?')) ?>"><?= $csrf ?><input type="hidden" name="subscriber_id" value="<?= (int) $o['subscriber_id'] ?>"><button class="navigation danger" type="submit"><?= e(t('Delete')) ?></button></form></td>
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
