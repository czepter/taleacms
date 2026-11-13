<?php
/**
 * The tabs of the fleet console: what needs attention, all sites, the calendar of certificates and domains.
 *
 * @var Talea\Admin\Modules\Fleet $module
 * @var string $current today | all | calendar
 * @var int $needing
 * @var int $total
 */
?>
<nav class="tabs" aria-label="<?= e(t('Which sites')) ?>">
	<a href="<?= e($module->url('')) ?>"<?= $current === 'today' ? ' class="active" aria-current="page"' : '' ?>><?= e(t('Today')) ?> (<?= $needing ?>)</a>
	<a href="<?= e($module->url('', ['show' => 'all'])) ?>"<?= $current === 'all' ? ' class="active" aria-current="page"' : '' ?>><?= e(t('All sites')) ?> (<?= $total ?>)</a>
	<a href="<?= e($module->url('calendar')) ?>"<?= $current === 'calendar' ? ' class="active" aria-current="page"' : '' ?>><?= e(t('Calendar')) ?></a>
</nav>
