<?php
/**
 * A page of the site (About us, Services, Contact…). On the home page the heading is not output – the home page carries its own content.
 *
 * @var array<string, mixed> $stranka
 * @var bool $uvod  the page is the home page of the site
 * @var string|null $stavba  ready-made HTML of the page from the builder (sections run full width, without a wrapper)
 */
if ($stavba !== null) {
    echo $stavba;

    return;
}
?>
<article class="stranka<?= $uvod ? ' stranka-uvod' : '' ?>">
<?php if (!$uvod): ?>
	<h1><?= e($stranka['title']) ?></h1>
<?php endif ?>
	<div class="text"><?= $stranka['text'] ?></div>
</article>
