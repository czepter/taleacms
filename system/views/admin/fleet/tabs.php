<?php
/**
 * The tabs of the fleet console: what needs attention, all sites, the calendar of certificates and domains.
 *
 * @var Talea\Admin\Modules\Fleet $module
 * @var string $current attention | all | calendar
 * @var int $needing
 * @var int $total
 */
?>
<nav class="tabs" aria-label="<?= e(t('Which sites')) ?>">
	<a href="<?= e($module->url('')) ?>"<?= $current === 'attention' ? ' class="active" aria-current="page"' : '' ?>><?= e(t('Need attention')) ?> (<?= $needing ?>)</a>
	<a href="<?= e($module->url('', ['show' => 'all'])) ?>"<?= $current === 'all' ? ' class="active" aria-current="page"' : '' ?>><?= e(t('All sites')) ?> (<?= $total ?>)</a>
	<a href="<?= e($module->url('calendar')) ?>"<?= $current === 'calendar' ? ' class="active" aria-current="page"' : '' ?>><?= e(t('Calendar')) ?></a>
</nav>
