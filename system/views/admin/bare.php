<?php
/**
 * A focused frame for one decision: the logo, the site name, the heading and the content – no menu, no search, no draft-look
 * bar. The OAuth consent screen uses it, the same way the sign-in page stands on its own.
 *
 * @var Talea\Core\App $app
 * @var string $heading
 * @var string $content ready-made HTML
 */
?>
<!doctype html>
<html lang="<?= e(Talea\Core\Language::code()) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<script src="<?= e($app->url('image/theme.js')) ?>?v=<?= e(TALEA_VERSION) ?>"></script>
<title><?= e($heading) ?> – Talea</title>
<?= Talea\Front\SiteIdentity::icons($app->settings(), $app->request->basePath(), true) ?>
<link rel="stylesheet" href="<?= e($app->url('image/admin.css')) ?>?v=<?= e(TALEA_VERSION) ?>">
</head>
<body class="login">
<main class="login-card login-card-wide">
<?= $app->view->render('admin/logo', ['height' => 32]) ?>
<p class="login-site"><?= e($app->settings()->get('site_name')) ?></p>
<h1><?= e($heading) ?></h1>
<?= $content ?>
</main>
</body>
</html>
