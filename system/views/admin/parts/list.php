<?php
/**
 * Site parts: header, footer and wrappers – status (from the layout / from the builder) and the way into the builder.
 *
 * @var Talea\Core\App $app
 * @var Talea\Admin\Modules\SiteParts $module
 * @var string $csrf
 * @var array<string, array{0:string, 1:string}> $types
 * @var list<string> $languages  '' = default language of the site
 * @var array<string, string> $languageNames
 * @var array<string, array<string, mixed>> $rows  "type:language" => status of the part
 * @var array<string, list<array<string, mixed>>> $variants  "type:language" => variants (header, footer)
 * @var array<int, string> $pageNames
 */
$icon = require __DIR__ . '/../icons.php';
?>
<p class="help"><?= e(t('The header and footer appear on every page. Wrappers add sections around content assembled by the system – news items, lists and the 404 message. Until you publish a part from the builder, it has its default design.')) ?></p>
<div class="tab-wrap">
<table class="listing">
<thead><tr><th scope="col"><?= e(t('Part')) ?></th><?php if (count($languages) > 1): ?><th scope="col"><?= e(t('Language')) ?></th><?php endif ?><th scope="col"><?= e(t('Status')) ?></th><th scope="col"><span class="visually-hidden"><?= e(t('Actions')) ?></span></th></tr></thead>
<tbody>
<?php foreach ($types as $type => [$name, $description]): ?>
<?php foreach ($languages as $language): $r = $rows[$type . ':' . $language] ?? null; $params = ['type' => $type, 'language' => $language]; $builder = $module->url('builder', $params); ?>
<tr>
	<td class="cell-main"><div class="cell-text"><a class="cell-title" href="<?= e($builder) ?>"><?= e(t($name)) ?></a><span class="cell-sub" title="<?= e(t($description)) ?>"><?= e(t($description)) ?></span></div></td>
<?php if (count($languages) > 1): ?>
	<td><?= e($languageNames[$language]) ?></td>
<?php endif ?>
	<td><?= $app->view->render('admin/list/status', ['kind' => $r !== null && $r['published'] ? 'live' : 'draft', 'label' => $r !== null && $r['published'] ? t('from the builder') : t('default'),
        'notes' => $r !== null && $r['changed'] ? [['text' => t('Changes not published'), 'url' => $builder]] : []]) ?></td>
	<td class="cell-actions"><?= $app->view->render('admin/list/actions', ['id' => $type . '-' . ($language ?: 'default'), 'name' => t($name), 'edit' => $builder, 'csrf' => $csrf, 'menu' => array_merge(
        Talea\Builder\PartTemplates::LIST[$type] ?? [] ? [['url' => $module->url('templates', $params), 'label' => t('Start from a template'), 'icon' => 'layers']] : [],
        in_array($type, Talea\Builder\SiteParts::WITH_VARIANTS, true) ? [['url' => $module->url('variant', $params), 'label' => t('Add variant'), 'icon' => 'plus']] : [],
        $r !== null ? ['-', ['post' => $module->url('template', $params), 'fields' => [], 'label' => t('Revert to default'), 'icon' => 'restore', 'confirm' => t('Revert this part to its default design? The builder version stays in the history.'), 'danger' => true]] : [],
    )]) ?></td>
</tr>
<?php foreach ($variants[$type . ':' . $language] ?? [] as $v): $variantParams = $params + ['variant' => $v['variant']]; $variantBuilder = $module->url('builder', $variantParams); $onPages = array_filter(array_map(fn (int $i): ?string => $pageNames[$i] ?? null, array_map('intval', json_decode((string) $v['pages'], true) ?: []))); ?>
<tr>
	<td class="cell-main"><div class="cell-row" style="padding-inline-start:20px"><span class="cell-tree"><?= $icon('subpage') ?></span><div class="cell-text"><a class="cell-title" href="<?= e($variantBuilder) ?>"><?= e($v['name']) ?></a><span class="cell-sub"><?= $onPages === [] ? e(t('not on any page yet')) : e(t('on pages: %s', implode(', ', $onPages))) ?></span></div></div></td>
<?php if (count($languages) > 1): ?>
	<td></td>
<?php endif ?>
	<td><?= $app->view->render('admin/list/status', ['kind' => $v['published'] ? 'live' : 'draft', 'label' => $v['published'] ? t('variant') : t('unpublished'),
        'notes' => $v['changed'] && $v['published'] ? [['text' => t('Changes not published'), 'url' => $variantBuilder]] : []]) ?></td>
	<td class="cell-actions"><?= $app->view->render('admin/list/actions', ['id' => $type . '-' . ($language ?: 'default') . '-' . $v['variant'], 'name' => $v['name'], 'edit' => $variantBuilder, 'csrf' => $csrf, 'menu' => [
        ['url' => $module->url('templates', $variantParams), 'label' => t('Start from a template'), 'icon' => 'layers'],
        ['url' => $module->url('variant', $variantParams), 'label' => t('Pages'), 'icon' => 'pages'], '-',
        ['post' => $module->url('template', $variantParams), 'fields' => [], 'label' => t('Delete'), 'confirm' => t('Delete the variant? The selected pages will get the default version.'), 'danger' => true],
    ]]) ?></td>
</tr>
<?php endforeach ?>
<?php endforeach ?>
<?php endforeach ?>
</tbody>
</table>
</div>
