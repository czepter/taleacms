<?php
/**
 * The fieldset that restricts a page, an item or a news item to member groups (Core\Members).
 *
 * @var Talea\Core\App $app
 * @var string $type page | item | news
 * @var int $row the row's number, 0 = new
 * @var int|null $from a new language version: the original's number, whose groups start ticked (Members::inheritFromOriginal)
 */
use Talea\Core\Members;

if (!Members::enabled($app->settings())) {
    return;
}
$groups = Members::groups($app->db());
$from = (int) ($from ?? 0);
$chosen = match (true) {
    // the form shown again after a refused save keeps what was ticked: otherwise saving it again would make the content public
    $app->request->isPost() && $app->request->post('member_groups_present') === '1' => $app->request->postList('member_groups'),
    $row > 0 => Members::contentGroupPublicIds($app->db(), $type, $row),
    $from > 0 => Members::contentGroupPublicIds($app->db(), $type, $from),
    default => [],
};
?>
<div class="row">
	<span class="caption"><?= e(t('Members only')) ?></span>
	<div class="options">
<?php if ($groups === []): ?>
		<span class="help"><?= e(t('Create groups under Members to restrict this to them.')) ?></span>
<?php else: ?>
		<input type="hidden" name="member_groups_present" value="1">
<?php foreach ($groups as $g): ?>
		<label><input type="checkbox" name="member_groups[]" value="<?= e($g['public_id']) ?>"<?= in_array($g['public_id'], $chosen, true) ? ' checked' : '' ?>> <?= e($g['name']) ?></label><br>
<?php endforeach ?>
		<span class="help"><?= e(t('Tick one or more groups: only their members can read it. Nothing ticked = public. Restricted content is never cached, indexed or searchable.')) ?></span>
<?php endif ?>
	</div>
</div>
