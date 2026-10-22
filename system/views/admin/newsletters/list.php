<?php
/**
 * The overview of the E-mail section: the list of subscribers and how it grows, the kinds of newsletter to start from,
 * the newsletters (drafts, scheduled, being sent, sent) and how they are sent.
 *
 * @var Talea\Core\App $app
 * @var Talea\Admin\Modules\Newsletters $module
 * @var string $csrf
 * @var list<array<string, mixed>> $newsletters
 * @var int $confirmed confirmed subscribers
 * @var int $awaiting sign-ups that have not confirmed yet
 * @var string $service the connected mailing service ('' = none)
 * @var ?string $problem why the site cannot send now
 * @var list<string> $openModules the modules the person may open (the menu paths in $problem become links to them)
 */
$withNews = Talea\Core\Extensions::isEnabled($app->settings(), 'news');
$kinds = array_filter([
    'latest' => $withNews ? ['News', 'Latest news', 'Takes the newest news items at the time of sending – write the introduction once, send whenever.'] : null,
    'chosen' => $withNews ? ['News', 'Hand-picked news', 'Choose the news items yourself – for a monthly review or a theme.'] : null,
    'none' => ['Announcement', 'Message only', 'An introduction and a button, no news – for an offer, an event or an invitation.'],
]);
echo $app->view->render('admin/list/head', ['primary' => [$module->url('new'), t('Write a newsletter')]]);
?>
<section class="email-audience" aria-labelledby="email-audience-heading">
	<div class="email-audience-sketch" aria-hidden="true"><span></span><span><span></span><span></span></span></div>
	<div class="email-audience-body">
		<div>
			<h2 id="email-audience-heading"><?= e(t('Grow your list')) ?></h2>
			<p><?= e(t('Add the Newsletter sign-up element to a page. Only people who confirm by the link in the e-mail count as subscribers.')) ?></p>
			<p><a href="<?= e($app->url('admin.php?module=subscribers')) ?>"><strong><?= e(t('Confirmed subscribers: %d', $confirmed)) ?></strong></a><?= $awaiting > 0 ? ' <span class="small-text">· ' . e(t('awaiting confirmation: %d', $awaiting)) . '</span>' : '' ?></p>
		</div>
		<p class="navigation-row">
<?php if ($app->auth()->hasModule('pages')): ?>
			<a class="navigation" href="<?= e($app->url('admin.php?module=pages')) ?>"><?= e(t('Add a sign-up form')) ?></a>
<?php endif ?>
<?php if ($confirmed > 0): ?>
			<a class="navigation" href="<?= e($app->url('admin.php?module=subscribers&action=csv')) ?>"><?= e(t('Export confirmed (CSV)')) ?></a>
<?php endif ?>
		</p>
	</div>
</section>
<section class="email-kinds-section" aria-labelledby="email-kinds-heading">
	<h2 id="email-kinds-heading"><?= e(t('What do you want to send?')) ?></h2>
	<div class="email-kinds">
<?php foreach ($kinds as $mode => [$label, $name, $text]): ?>
		<a class="email-kind" href="<?= e($module->url('new', ['mode' => $mode])) ?>">
			<span class="email-kind-text"><span class="email-kind-label"><?= e(t($label)) ?></span><strong><?= e(t($name)) ?></strong><span><?= e(t($text)) ?></span></span>
			<span class="email-kind-sketch email-kind-<?= e($mode) ?>" aria-hidden="true"><span></span><span></span><span></span><span></span></span>
		</a>
<?php endforeach ?>
	</div>
</section>
<section aria-labelledby="email-sent-heading">
	<h2 id="email-sent-heading"><?= e(t('Your newsletters')) ?></h2>
<?php if ($newsletters === []): ?>
	<p class="small-text"><?= e(t('Send your latest news to subscribers in an e-mail that follows the look of your site – colours, fonts and logo come from the design system.')) ?></p>
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
		<td class="cell-main"><div class="cell-text"><a class="cell-title" href="<?= e($edit) ?>"><?= e($n['subject'] !== '' ? $n['subject'] : t('(no subject)')) ?></a></div></td>
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
<?php endif ?>
</section>
<section class="email-delivery" aria-label="<?= e(t('Sending')) ?>">
	<div class="<?= $problem === null ? 'email-ok' : 'email-problem' ?>">
		<h2><?= e($problem === null ? t('Sending is set up') : t('Sending is not set up yet')) ?></h2>
		<p><?= $problem === null ? e(t('Your SMTP server sends in batches. Every e-mail carries a one-click unsubscribe link. Send yourself a test before it goes out.')) : Talea\Admin\MenuPaths::links($app->url('admin.php'), t($problem), $openModules) ?></p>
	</div>
	<div>
		<h2><?= e(t('Use your own mailing tool?')) ?></h2>
		<p><?= e($service !== '' ? t('Confirmed subscribers go to %s automatically; those who unsubscribe are removed from it.', t(Talea\Core\Newsletter::SERVICES[$service][0] ?? $service)) : t('Using Brevo, MailerLite, Mailchimp, Ecomail or SmartEmailing? Once connected in Features, the site sends confirmed subscribers straight to your list.')) ?></p>
<?php if ($app->auth()->isAdmin()): ?>
		<p><a href="<?= e($app->url('admin.php?module=extensions#newsletter')) ?>"><?= e($service !== '' ? t('Service settings') : t('Connect a service')) ?></a></p>
<?php endif ?>
	</div>
</section>
<p class="help"><?= e(t('Nothing tracks whether subscribers open the e-mail. Links to the site carry utm parameters, so Statistics show the visits the newsletter brought.')) ?></p>
