<?php
/**
 * Translation overview (2.14, Core\Translations): pages, news items and collection items in the default language × the
 * site's other languages – present, missing, or older than the original.
 *
 * @var Talea\Core\App $app
 * @var Talea\Admin\Modules\Pages $module
 * @var string $csrf
 * @var list<string> $languages
 * @var list<array<string, mixed>> $rows
 * @var bool $assistant  the AI assistant can translate news items
 * @var bool $news  the user may open News
 * @var bool $collections  the user may open Collections
 */
use Talea\Core\Language;
use Talea\Core\Translations;

$types = ['page' => t('Pages'), 'news' => t('News'), 'collection_item' => t('Collection items')];
$editUrl = fn (array $row, string $id): string => match ($row['type']) {
    'page' => $module->url('edit', ['id' => $id]),
    'news' => $app->url('admin.php?module=news&action=edit&id=' . $id),
    default => $app->url('admin.php?module=collections&action=item&id=' . $row['collection']['public_id'] . '&item=' . $id),
};
$createUrl = fn (array $row, string $code): string => match ($row['type']) {
    'page' => $module->url('new', ['language' => $code, 'translation_of' => $row['id']]),
    'news' => $app->url('admin.php?module=news&action=new&translation_of=' . $row['id']),
    default => $app->url('admin.php?module=collections&action=item&id=' . $row['collection']['public_id'] . '&item=0&language=' . $code . '&original=' . $row['id']),
};
$counts = ['missing' => 0, 'outdated' => 0];
foreach ($rows as $row) {
    foreach ($row['translations'] as $cell) {
        if (isset($counts[$cell['status']])) {
            $counts[$cell['status']]++;
        }
    }
}
?>
<p class="navigation-row"><a class="navigation" href="<?= e($module->url()) ?>"><?= e(t('Back to overview')) ?></a></p>
<?php if ($languages === []): ?>
<?= $app->view->render('admin/empty', ['icon' => 'pages', 'heading' => t('The site has a single language.'), 'text' => t('Add language versions in Settings → General; this screen then shows which translations are missing.'), 'action' => [$app->url('admin.php?module=settings&tab=general'), t('Settings')]]) ?>
<?php else: ?>
<p class="small-text"><?= e(t('Missing translations: %d, older than the original: %d.', $counts['missing'], $counts['outdated'])) ?> <?= e(t('“Older” means the original changed after the translation was last saved.')) ?></p>
<?php foreach ($types as $type => $heading): $ofType = array_values(array_filter($rows, fn (array $r): bool => $r['type'] === $type)); if ($ofType === [] || ($type === 'news' && !$news) || ($type === 'collection_item' && !$collections)) { continue; } ?>
<h2><?= e($heading) ?></h2>
<div class="tab-wrap">
<table class="listing translations">
<thead><tr><th scope="col"><?= e(t('Name')) ?></th><th scope="col"><?= e(t('Changed')) ?></th><?php foreach ($languages as $code): ?><th scope="col"><?= e(Language::AVAILABLE[$code][0]) ?></th><?php endforeach ?></tr></thead>
<tbody>
<?php foreach ($ofType as $row): ?>
<tr>
	<td><a href="<?= e($editUrl($row, $row['id'])) ?>"><?= e($row['title']) ?></a><?= $type === 'collection_item' ? ' <small>' . e($row['collection']['name']) . '</small>' : '' ?></td>
	<td class="number"><?= $row['changed'] ? e(format_date($row['changed'])) : '–' ?></td>
<?php foreach ($languages as $code): $cell = $row['translations'][$code]; ?>
	<td data-status="<?= e($cell['status']) ?>"><?php if ($cell['status'] === Translations::MISSING): ?>
		<span class="badge badge-draft"><?= e(t('missing')) ?></span>
<?php if ($type === 'news' && $assistant): ?>
		<form class="inline" method="post" action="<?= e($app->url('admin.php?module=news&action=translate')) ?>" data-confirm="<?= e(t('Translate the saved version with the assistant? A draft is created for you to read before publishing. Translation can take up to a minute.')) ?>"><?= $csrf ?><input type="hidden" name="news_id" value="<?= (int) $row['id'] ?>"><input type="hidden" name="translate_to" value="<?= e($code) ?>"><button class="navigation" type="submit"><?= e(t('Translate')) ?></button></form>
<?php else: ?>
		<a class="navigation" href="<?= e($createUrl($row, $code)) ?>"><?= e(t('Create')) ?></a>
<?php endif ?>
<?php elseif ($cell['status'] === Translations::OUTDATED): ?>
		<a class="badge badge-draft" href="<?= e($editUrl($row, $cell['id'])) ?>" title="<?= e(t('The original changed after this translation was saved.')) ?>"><?= e(t('older than original')) ?></a>
<?php else: ?>
		<a class="badge badge-published" href="<?= e($editUrl($row, $cell['id'])) ?>"><?= e(t('translated')) ?></a>
<?php endif ?></td>
<?php endforeach ?>
</tr>
<?php endforeach ?>
</tbody>
</table>
</div>
<?php endforeach ?>
<?php endif ?>
