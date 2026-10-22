<?php
/**
 * Home, the admin start screen: a welcome with the way into the website, the first steps in groups, the site card,
 * what waits for the person, Ask Claude and what was edited last. Figures and charts live in Analytics.
 *
 * @var Talea\Core\App $app
 * @var array<string, class-string<Talea\Admin\Module>> $modules
 * @var list<array{0: string, 1: string, 2?: string, 3?: string}> $warnings  [text, url, link text, action that dismisses it]
 * @var list<array{kind: string, title: string, edited: string, url: string, status: string}> $edited
 * @var array<string, mixed>|null $ask "Ask Claude" (3.1), null without the Requests section or the Claude connection
 * @var list<array{kind: string, label: string, count: int, url: string, examples: list<string>}> $pending "Waiting for you" (3.2, Core\PendingReview)
 * @var list<array{name: string, description: string, url: string, done: bool, group: string}> $firstSteps
 * @var array{name: string, url: string, drafts: int, look: bool} $site
 */
$user = $app->auth()->user() ?? [];
$firstName = explode(' ', trim((string) (($user['name'] ?? '') ?: ($user['username'] ?? ''))))[0];
$groups = [];
foreach ($firstSteps as $step) {
    $groups[$step['group']][] = $step;
}
$openGroup = null; // the first group with something left to do is open
foreach ($groups as $name => $steps) {
    if ($openGroup === null && array_filter($steps, fn (array $k): bool => !$k['done']) !== []) {
        $openGroup = $name;
    }
}
$waiting = $warnings !== [] || $pending !== [];
?>
<div class="home-header">
	<h1><?= e(t('Welcome, %s', $firstName)) ?></h1>
	<p class="navigation-row">
		<?= $app->view->render('admin/guide_link', ['url' => Talea\Admin\Guide::forScreen('', '', '', Talea\Core\Language::code())]) ?>
		<a class="navigation" href="<?= e($app->url('')) ?>" target="_blank" rel="noopener"><?= e(t('View site')) ?></a>
<?php if (isset($modules['pages'])): ?>
		<a class="btn" href="<?= e($app->url('admin.php?module=pages&action=website')) ?>"><?= e(t('Edit website')) ?></a>
<?php endif ?>
	</p>
</div>
<?php if ($firstSteps !== [] || isset($modules['pages'])): ?>
<section class="home-start" aria-labelledby="home-start-heading">
	<div class="home-section-head">
		<h2 id="home-start-heading"><?= e(t('Get started')) ?></h2>
<?php if ($firstSteps !== []): ?>
		<form method="post" action="<?= e($app->url('admin.php?action=hide_first_steps')) ?>"><?= $app->session->csrfField() ?><button class="navigation" type="submit"><?= e(t('Hide')) ?></button></form>
<?php endif ?>
	</div>
	<div class="home-start-grid">
<?php if ($firstSteps !== []): ?>
		<div class="home-steps">
<?php foreach ($groups as $name => $steps): $done = count(array_filter($steps, fn (array $k): bool => $k['done'])); ?>
			<details class="home-step-group"<?= $name === $openGroup ? ' open' : '' ?>>
				<summary><span class="home-step-name"><?= e(t($name)) ?></span> <span class="home-step-count"><?= $done ?> / <?= count($steps) ?></span> <progress max="<?= count($steps) ?>" value="<?= $done ?>"><?= $done ?> / <?= count($steps) ?></progress></summary>
				<ul>
<?php foreach ($steps as $step): ?>
					<li<?= $step['done'] ? ' class="done"' : '' ?>><a href="<?= e($step['url']) ?>"><strong><?= e(t($step['name'])) ?></strong><span><?= e(t($step['description'])) ?></span></a></li>
<?php endforeach ?>
				</ul>
			</details>
<?php endforeach ?>
		</div>
<?php endif ?>
		<div class="home-site">
			<a class="home-site-preview" href="<?= e($app->url('')) ?>" target="_blank" rel="noopener" aria-label="<?= e(t('View site')) ?>"><iframe src="<?= e($app->url('')) ?>" title="<?= e(t('Home page preview')) ?>" loading="lazy" tabindex="-1" inert></iframe></a>
			<div class="home-site-body">
				<h3><?= e($site['name']) ?></h3>
				<a class="home-site-url" href="<?= e($app->url('')) ?>" target="_blank" rel="noopener"><?= e(preg_replace('#^https?://#', '', $site['url'])) ?></a>
<?php if ($site['drafts'] > 0 || $site['look']): ?>
				<p class="home-site-state"><?= e($site['look'] ? t('%d pages and the look have unpublished changes.', $site['drafts']) : t('%d pages have unpublished changes.', $site['drafts'])) ?></p>
				<a class="navigation" href="<?= e($app->url($site['look'] ? 'admin.php?module=appearance' : 'admin.php?module=pages')) ?>"><?= e(t('Review and publish')) ?></a>
<?php else: ?>
				<p class="home-site-state home-site-live"><?= e(t('Everything is published.')) ?></p>
<?php endif ?>
<?php if (isset($modules['appearance'])): ?>
				<a class="navigation" href="<?= e($app->url('admin.php?module=appearance&action=looks')) ?>"><?= e(t('Try on another look')) ?></a>
<?php endif ?>
			</div>
		</div>
	</div>
</section>
<?php endif ?>
<?php if ($waiting): ?>
<section class="awaiting-you" aria-labelledby="awaiting-you-heading">
	<h2 id="awaiting-you-heading"><?= e(t('Waiting for you')) ?></h2>
	<ul>
<?php foreach ($warnings as $w): [$text, $url] = $w; ?>
		<li class="home-warning"><span><?= e($text) ?></span>
			<span class="navigation-row"><a href="<?= e($url) ?>"><?= e($w[2] ?? t('Fix')) ?></a><?php if (isset($w[3])): ?>
				<form class="inline" method="post" action="<?= e($w[3]) ?>"><?= $app->session->csrfField() ?><input type="hidden" name="back" value="overview"><button class="navigation" type="submit"><?= e(t('Ignore all')) ?></button></form><?php endif ?></span></li>
<?php endforeach ?>
<?php foreach ($pending as $p): ?>
		<li data-kind="<?= e($p['kind']) ?>"><a href="<?= e($app->url($p['url'])) ?>"><strong><?= format_count($p['count']) ?></strong> <?= e(t($p['label'])) ?></a>
<?php if ($p['examples'] !== []): ?>
			<span class="small-text"><?= e(implode(' · ', $p['examples'])) ?><?= $p['count'] > count($p['examples']) && $p['kind'] !== 'draft_comments' ? ' …' : '' ?></span>
<?php endif ?>
		</li>
<?php endforeach ?>
	</ul>
</section>
<?php endif ?>
<?php if ($ask !== null): ?>
<?= $app->view->render('admin/ask_claude', ['app' => $app, 'ask' => $ask]) ?>
<?php endif ?>
<?php if ($edited !== []): ?>
<section class="home-edited" aria-labelledby="home-edited-heading">
	<h2 id="home-edited-heading"><?= e(t('Recently edited')) ?></h2>
	<ul>
<?php foreach ($edited as $u): ?>
		<li><a href="<?= e($u['url']) ?>"><?= e($u['title']) ?></a> <span class="small-text"><?= e($u['kind']) ?> · <?= e(format_date($u['edited'], true)) ?></span><?= $u['status'] !== '' ? ' <span class="badge badge-draft">' . e($u['status']) . '</span>' : '' ?></li>
<?php endforeach ?>
	</ul>
</section>
<?php endif ?>
