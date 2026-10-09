<?php
/**
 * A page of the site (About us, Services, Contact…). On the home page the heading is not output – the home page carries its own content.
 *
 * @var array<string, mixed> $page
 * @var bool $intro  the page is the home page of the site
 * @var string|null $build  ready-made HTML of the page from the builder (sections run full width, without a wrapper)
 */
if ($build !== null) {
    echo $build;

    return;
}
?>
<article class="page<?= $intro ? ' page-intro' : '' ?>">
<?php if (!$intro): ?>
	<h1><?= e($page['title']) ?></h1>
<?php endif ?>
	<div class="text"><?= $page['text'] ?></div>
</article>
