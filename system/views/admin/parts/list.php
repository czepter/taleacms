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
?>
<p class="help"><?= e(t('The header and footer appear on every page. Wrappers add sections around content assembled by the system – news items, lists and the 404 message. Until you publish a part from the builder, it has its default design.')) ?></p>
<div class="tab-wrap">
<table class="listing">
<thead><tr><th scope="col"><?= e(t('Part')) ?></th><?php if (count($languages) > 1): ?><th scope="col"><?= e(t('Language')) ?></th><?php endif ?><th scope="col"><?= e(t('Status')) ?></th><th scope="col"><?= e(t('Actions')) ?></th></tr></thead>
<tbody>
<?php foreach ($types as $type => [$name, $description]): ?>
<?php foreach ($languages as $language): $r = $rows[$type . ':' . $language] ?? null; $params = ['type' => $type, 'language' => $language]; ?>
<tr>
	<td><a href="<?= e($module->url('builder', $params)) ?>"><strong><?= e(t($name)) ?></strong></a><br><small class="help"><?= e(t($description)) ?></small></td>
<?php if (count($languages) > 1): ?>
	<td><?= e($languageNames[$language]) ?></td>
<?php endif ?>
	<td><?php if ($r !== null && $r['published']): ?><span class="badge badge-published"><?= e(t('from the builder')) ?></span><?php else: ?><span class="badge"><?= e(t('default')) ?></span><?php endif ?><?= $r !== null && $r['changed'] ? ' <span class="badge badge-draft">' . e(t('unpublished changes')) . '</span>' : '' ?></td>
	<td class="actions"><a href="<?= e($module->url('builder', $params)) ?>"><?= e(t($r === null ? 'Edit in the builder' : 'Builder')) ?></a><?php if (Talea\Builder\PartTemplates::LIST[$type] ?? []): ?> · <a href="<?= e($module->url('templates', $params)) ?>"><?= e(t('Start from a template')) ?></a><?php endif ?><?php if (in_array($type, Talea\Builder\SiteParts::WITH_VARIANTS, true)): ?> · <a href="<?= e($module->url('variant', $params)) ?>"><?= e(t('Add variant')) ?></a><?php endif ?><?php if ($r !== null): ?> ·
		<form class="inline" method="post" action="<?= e($module->url('template', $params)) ?>" data-confirm="<?= e(t('Revert this part to its default design? The builder version stays in the history.')) ?>"><?= $csrf ?><button class="navigation danger" type="submit"><?= e(t('Revert to default')) ?></button></form><?php endif ?></td>
</tr>
<?php foreach ($variants[$type . ':' . $language] ?? [] as $v): $variantParams = $params + ['variant' => $v['variant']]; $onPages = array_filter(array_map(fn (int $i): ?string => $pageNames[$i] ?? null, array_map('intval', json_decode((string) $v['pages'], true) ?: []))); ?>
<tr>
	<td>↳ <a href="<?= e($module->url('builder', $variantParams)) ?>"><?= e($v['name']) ?></a><br><small class="help"><?= $onPages === [] ? e(t('not on any page yet')) : e(t('on pages: %s', implode(', ', $onPages))) ?></small></td>
<?php if (count($languages) > 1): ?>
	<td></td>
<?php endif ?>
	<td><?php if ($v['published']): ?><span class="badge badge-published"><?= e(t('variant')) ?></span><?php else: ?><span class="badge badge-draft"><?= e(t('unpublished')) ?></span><?php endif ?><?= $v['changed'] && $v['published'] ? ' <span class="badge badge-draft">' . e(t('unpublished changes')) . '</span>' : '' ?></td>
	<td class="actions"><a href="<?= e($module->url('builder', $variantParams)) ?>"><?= e(t('Builder')) ?></a> · <a href="<?= e($module->url('templates', $variantParams)) ?>"><?= e(t('Start from a template')) ?></a> · <a href="<?= e($module->url('variant', $variantParams)) ?>"><?= e(t('Pages')) ?></a> ·
		<form class="inline" method="post" action="<?= e($module->url('template', $variantParams)) ?>" data-confirm="<?= e(t('Delete the variant? The selected pages will get the default version.')) ?>"><?= $csrf ?><button class="navigation danger" type="submit"><?= e(t('Delete')) ?></button></form></td>
</tr>
<?php endforeach ?>
<?php endforeach ?>
<?php endforeach ?>
</tbody>
</table>
</div>
