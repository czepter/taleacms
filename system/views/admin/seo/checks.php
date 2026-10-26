<?php
/**
 * The check of the SEO tab (Core\ContentCheck::analyse): the score ring with its verdict and the focus keywords it was
 * checked against, then what to fix – the count per group, the open problems first, the passed checks folded away. Shown in
 * the tab and returned alone by the live check (pages/seo_check), whose answer replaces [data-seo-checks].
 *
 * @var array{score: ?int, groups: array<string, list<array{check: string, ok: bool, message: string, why?: string}>>} $analysis
 * @var list<array{check: string, ok: bool, message: string}> $samples placeholders still in the content
 * @var list<string> $keywords the first is the one checked
 * @var string $marksUrl Website mode with the marks on the preview; '' for a text page
 * @var bool $scored the score is shown
 */
$score = $analysis['score'];
$band = Talea\Core\ContentCheck::band($score);
$open = $samples;
$passed = $counts = [];
foreach ($analysis['groups'] as $group => $rows) {
    $counts[$group] = 0;
    foreach ($rows as $row) {
        if ($row['ok']) {
            $passed[] = $row;
        } else {
            $open[] = $row;
            $counts[$group]++;
        }
    }
}
$verdict = ['good' => 'Good', 'mid' => 'Needs work', 'low' => 'Poor', 'none' => 'No focus keyword'][$band];
$icon = fn (bool $ok): string => '<svg class="icon" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/>'
    . ($ok ? '<path d="m8 12 3 3 5-6"/>' : '<path d="M12 7.5v5.5M12 16.5h.01"/>') . '</svg>';
// the problems the preview can mark on the page ("SEO on the page")
$onPage = ['images_alt', 'single_h1'];
$item = function (array $row) use ($icon, $onPage, $marksUrl): string {
    $more = $row['ok'] ? '' : trim(e($row['why'] ?? '') . (in_array($row['check'], $onPage, true) && $marksUrl !== '' ? ' <a href="' . e($marksUrl) . '">' . e(t('Show on page')) . '</a>' : ''));

    return '<li class="' . ($row['ok'] ? 'pass' : 'fail') . '" data-check="' . e($row['check']) . '">' . $icon($row['ok']) . '<span>' . e($row['message']) . ($more !== '' ? '<small>' . $more . '</small>' : '') . '</span></li>';
};
?>
<div class="seo-checks" data-seo-checks>
<?php if ($scored): ?>
	<section class="website-settings-section seo-score-card" aria-label="<?= e(t('SEO score')) ?>">
		<div class="seo-ring seo-ring-<?= $band ?>"><svg viewBox="0 0 84 84" aria-hidden="true"><circle class="seo-ring-track" cx="42" cy="42" r="35"/><?php if ($score !== null): ?><circle class="seo-ring-fill" cx="42" cy="42" r="35" stroke-dasharray="<?= round($score * 2.199, 1) ?> 219.9"/><?php endif ?></svg><strong><span class="guide-hidden"><?= e(t('SEO score')) ?> </span><?= $score ?? '–' ?></strong></div>
		<div class="seo-score-text">
			<span class="website-score website-score-<?= $band ?>"><?= e(t($verdict)) ?></span>
<?php if ($keywords !== []): ?>
			<ul class="seo-chips" aria-label="<?= e(t('Focus keywords')) ?>">
<?php foreach ($keywords as $i => $keyword): ?>
				<li<?= $i === 0 ? ' class="primary" title="' . e(t('The page is checked against this keyword.')) . '"' : '' ?>><?= e($keyword) ?></li>
<?php endforeach ?>
			</ul>
<?php else: ?>
			<p class="help"><?= e(t('Add a focus keyword below to get a score.')) ?></p>
<?php endif ?>
		</div>
	</section>
<?php endif ?>
	<section class="website-settings-section" aria-labelledby="seo-fix">
		<h3 class="seo-heading" id="seo-fix"><span><?= e(t('To fix')) ?></span><span><?= count($open) ?></span></h3>
		<p class="seo-groups">
<?php foreach ($counts as $group => $count): ?>
			<span><?= e(t(Talea\Core\ContentCheck::GROUPS[$group])) ?> <b<?= $count === 0 ? ' class="none"' : '' ?>><?= $count ?></b></span>
<?php endforeach ?>
		</p>
<?php if ($open !== []): ?>
		<ul class="seo-check-list"><?= implode('', array_map($item, $open)) ?></ul>
<?php else: ?>
		<p class="help"><?= e(t('Nothing to fix.')) ?></p>
<?php endif ?>
<?php if ($passed !== []): ?>
		<details class="seo-passed">
			<summary><?= e(t('Passed checks: %d', count($passed))) ?></summary>
			<ul class="seo-check-list"><?= implode('', array_map($item, $passed)) ?></ul>
		</details>
<?php endif ?>
	</section>
</div>
