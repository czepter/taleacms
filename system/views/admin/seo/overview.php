<?php
/**
 * Overview of the "Search engines" section: tiles, the description notice and one card with a switch per feature.
 *
 * @var Talea\Core\App $app
 * @var Talea\Admin\Modules\Seo $module
 * @var string $csrf
 * @var string $score the site check score stored by the audit, '' = none yet
 * @var int $pages visible pages
 * @var int $keywords of them with a focus keyword
 * @var int $redirects
 * @var int $missing addresses visitors could not find this week
 * @var int $noDescription
 * @var array<string, bool> $state feature => on
 * @var array<string, class-string<Talea\Admin\Module>> $modules what the person may open
 */
$tile = fn (string $value, string $unit, string $label, string $url = ''): string => ($url !== '' ? '<a class="tiles-item" href="' . e($url) . '">' : '<div class="tiles-item">')
    . '<strong>' . e($value) . ($unit !== '' ? ' <small>' . e($unit) . '</small>' : '') . '</strong><span>' . e($label) . '</span>' . ($url !== '' ? '</a>' : '</div>');
$open = fn (string $ident): string => $app->url('admin.php?module=' . $ident);
?>
<p class="help"><?= e(t('How your site appears in search results and when someone shares it. Turn on what you need; the rest stays out of the way.')) ?></p>
<div class="tiles">
	<?= $tile($score !== '' ? $score : '—', $score !== '' ? '/ 100' : '', t('Site check'), isset($modules['audit']) ? $open('audit') : '') ?>
	<?= $tile((string) $keywords, t('of %d', $pages), t('Pages with a focus keyword'), isset($modules['pages']) ? $open('pages') : '') ?>
	<?= $tile((string) $redirects, '', t('Active redirects'), isset($modules['redirects']) ? $open('redirects') : '') ?>
	<?= $tile((string) $missing, '', t('Missing pages this week'), isset($modules['redirects']) ? $open('redirects') : '') ?>
</div>
<?php if ($noDescription > 0): ?>
<div class="notice notice-warning notice-actions"><p><?= e(t('%d pages have no description. Search results then show a sentence the search engine picks itself.', $noDescription)) ?></p>
<?php if (isset($modules['audit'])): ?><div class="navigation-row"><a class="navigation" href="<?= e($open('audit')) ?>"><?= e(t('All findings')) ?></a></div><?php endif ?></div>
<?php endif ?>
<h2 class="seo-features-title"><?= e(t('Features')) ?></h2>
<p class="help"><?= e(t('A feature that is off takes its menu item, its fields and its checks with it.')) ?></p>
<div class="seo-features">
<?php foreach ($module::FEATURES as $feature => [$name, $text, , , $screen, $linkText, $icon]): $on = $state[$feature]; ?>
	<article class="seo-feature">
		<div class="seo-feature-head"><span class="cell-icon"><svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><?= $icon ?></svg></span><h3><?= e(t($name)) ?></h3></div>
		<p><?= e(t($text)) ?></p>
		<div class="seo-feature-foot">
<?php if (isset($modules[$screen]) || str_starts_with($screen, 'settings')): ?>
			<a href="<?= e($open($screen)) ?>"><?= e(t($linkText)) ?></a>
<?php else: ?>
			<span></span>
<?php endif ?>
			<?= $app->view->render('admin/list/switch', ['action' => $module->url('feature'), 'fields' => ['feature' => $feature, 'on' => $on ? '0' : '1'], 'on' => $on, 'label' => t('%s on', t($name)), 'csrf' => $csrf]) ?>
		</div>
	</article>
<?php endforeach ?>
</div>
