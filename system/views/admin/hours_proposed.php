<?php
/**
 * Exceptions to the opening hours proposed by Claude (3.2, Core\Hours::proposed): a drafts-only connection saves them,
 * the site ignores them until a person applies one here – or discards it. Include it inside a form that carries the
 * CSRF field (the buttons post with formaction); renders nothing without proposals.
 *
 * @var list<array{id: int, from: string, to: string, closed: bool, hours: string, note: string, notice_days: int}> $proposed
 * @var string $applyUrl the action that applies one (POST exception=<id>)
 * @var string $discardUrl the action that discards one (POST exception=<id>)
 */
if ($proposed === []) {
    return;
}
?>
<div class="tab-obal" id="proposed-hours"><table class="vypis"><caption class="smltxt"><?= e(t('Proposed by Claude – the site does not use these until you apply them.')) ?></caption><tbody>
<?php foreach ($proposed as $ex): ?>
	<tr><td><?= e(Kaleta\Core\Hours::describe($ex)) ?> <span class="stitek stitek-koncept"><?= e(t('Proposed by Claude')) ?></span></td><td class="smltxt"><?= $ex['notice_days'] > 0 ? e(t('notice %d days ahead', $ex['notice_days'])) : e(t('no notice')) ?></td>
		<td class="akce"><button class="navigace" type="submit" formaction="<?= e($applyUrl) ?>" name="exception" value="<?= (int) $ex['id'] ?>"><?= e(t('Apply')) ?></button>
			<button class="navigace nebezpecne" type="submit" formaction="<?= e($discardUrl) ?>" name="exception" value="<?= (int) $ex['id'] ?>"><?= e(t('Discard')) ?></button></td></tr>
<?php endforeach ?>
</tbody></table></div>
