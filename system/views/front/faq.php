<?php
/**
 * Questions and answers below a news item.
 *
 * @var list<array{0:string, 1:string}> $faq
 */
if ($faq === []) {
    return;
}
?>
<section class="faq obal-uzky">
	<h2><?= e(t('Questions and answers')) ?></h2>
<?php foreach ($faq as [$question, $answer]): ?>
	<details>
		<summary><?= e($question) ?></summary>
		<p><?= nl2br(e($answer)) ?></p>
	</details>
<?php endforeach ?>
</section>
