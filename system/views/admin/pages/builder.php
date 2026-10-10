<?php
/**
 * Full-screen page builder. All controls are assembled by image/builder.js from the data below; without JavaScript it only explains why.
 * The canvas is a real page of the site (?build=draft&editor=1) – what the editor shows is exactly what the visitor will see.
 *
 * @var Talea\Core\App $app
 * @var array<string, mixed> $data  build, schema, library, classes, action urls (Modules\Pages::actionBuilder)
 * @var string $title
 */
$version = rawurlencode(TALEA_VERSION);
$language = Talea\Core\Language::code();
?>
<!doctype html>
<html lang="<?= e($language) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<script src="<?= e($app->url('image/theme.js')) ?>?v=<?= $version ?>"></script>
<title><?= e(t('Builder')) ?>: <?= e($title) ?> – Talea</title>
<link rel="icon" type="image/svg+xml" href="<?= e($app->url('')) ?>image/talea-mark.svg">
<link rel="stylesheet" href="<?= e($app->url('image/admin.css')) ?>?v=<?= $version ?>">
<link rel="stylesheet" href="<?= e($app->url('image/editor.css')) ?>?v=<?= $version ?>">
<link rel="stylesheet" href="<?= e($app->url('image/builder.css')) ?>?v=<?= $version ?>">
</head>
<body class="builder-body">
<?= $app->session->csrfField() ?>
<noscript><p class="notice notice-error"><?= e(t('The builder needs JavaScript. The page content can also be edited without it in the page form.')) ?></p></noscript>
<div class="bd-narrow" role="note"><p><strong><?= e(t('The builder needs a larger screen.')) ?></strong> <?= e(t('Build pages on a computer or tablet. On a phone, edit texts with the “Edit here” button right on the website.')) ?></p>
	<p><a href="<?= e($app->url('admin.php')) ?>"><?= e(t('Back to the administration')) ?></a></p></div>
<div class="bd" id="builder" hidden></div>
<script type="application/json" id="builder-data"><?= json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<?php if (is_file(TALEA_ROOT . '/image/languages/admin-' . $language . '.js')): ?>
<script src="<?= e($app->url('image/languages/admin-' . $language . '.js')) ?>?v=<?= $version ?>"></script>
<?php endif ?>
<script src="<?= e($app->url('image/admin.js')) ?>?v=<?= $version ?>" defer></script>
<script src="<?= e($app->url('image/editor.js')) ?>?v=<?= $version ?>" data-admin-url="<?= e($app->url('admin.php')) ?>" data-max-file="<?= Talea\Core\Files::limit() ?>" data-max-file-text="<?= e(Talea\Core\Files::limitText()) ?>" data-max-page="<?= Talea\Core\Images::MAX_SIDE ?>" defer></script>
<script src="<?= e($app->url('image/builder.js')) ?>?v=<?= $version ?>" defer></script>
<script src="<?= e($app->url('image/builder-structured.js')) ?>?v=<?= $version ?>" defer></script>
</body>
</html>
