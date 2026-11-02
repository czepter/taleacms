<?php
/**
 * @var string $base
 * @var bool $alreadyInstalled
 * @var bool|null $deleted the installer deleted itself (null: a container, the file belongs to the image)
 * @var bool $fromExport "Move a Talea site here": the next step is the import
 * @var string|null $mcp address of the Claude connection, when it is switched on (2.5: the next step after installing)
 */
$steps = Talea\Install\Installer::STEPS;
$current = count($steps); // every step is done
$interactive = false;
?>
<!doctype html>
<html lang="<?= e($language ?? 'en') ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= e(t('Talea installation')) ?></title>
<link rel="icon" type="image/svg+xml" href="<?= e($base) ?>/image/talea-mark.svg">
<link rel="alternate icon" type="image/png" sizes="32x32" href="<?= e($base) ?>/image/talea-mark-32.png">
<link rel="apple-touch-icon" href="<?= e($base) ?>/image/talea-mark-180.png">
<link rel="stylesheet" href="<?= e($base) ?>/image/install.css?v=<?= e(TALEA_VERSION) ?>">
</head>
<body>
<div class="setup">
<aside class="setup-side"><?php require TALEA_SYSTEM . '/views/install/sidebar.php'; ?></aside>
<main class="setup-main">
<div class="setup-inner">
<?php if ($alreadyInstalled): ?>
<h1 class="page-title"><?= e(t('Talea is already installed')) ?></h1>
<p class="lead"><?= e(t('The config.php file exists, so the installer changes nothing.')) ?></p>
<?php else: ?>
<h1 class="page-title"><?= e(t('Done, your website is running')) ?></h1>
<p class="lead"><?= e(t('The database is ready and the configuration has been written.')) ?></p>
<?php endif ?>
<?php if ($deleted): ?>
<p class="notice notice-ok"><?= e(t('For security reasons install.php has deleted itself – there is nothing else you need to do.')) ?></p>
<?php elseif ($deleted === false): ?>
<p class="notice <?= $alreadyInstalled ? 'notice-error' : 'notice-ok' ?>"><?= e(t('For security reasons, now delete this file from the server:')) ?> <strong>install.php</strong>.</p>
<?php endif ?>
<div class="next">
<?php if (!empty($fromExport)): ?>
	<section class="next-item">
		<h2><?= e(t('Import')) ?></h2>
		<p><?= e(t('The site is empty. Sign in and drop the export of your Talea site on the Import and export page.')) ?></p>
		<a class="btn" href="<?= e($base) ?>/admin/transfer"><?= e(t('Continue with the import')) ?></a>
	</section>
<?php else: ?>
	<section class="next-item">
		<h2><?= e(t('Administration')) ?></h2>
		<p><?= e(t('Sign in with the account you have just created and start with your pages.')) ?></p>
		<a class="btn" href="<?= e($base) ?>/admin"><?= e(t('Go to the administration')) ?></a>
	</section>
<?php endif ?>
	<section class="next-item">
		<h2><?= e(t('Your site')) ?></h2>
<?php if (empty($fromExport)): ?>
		<p><?= e(t('The starter pages are live with placeholder texts in square brackets.')) ?></p>
<?php endif ?>
		<a class="navigation" href="<?= e($base) ?>/"><?= e(t('View site')) ?></a>
	</section>
</div>
<?php if (!empty($mcp) && empty($fromExport)): ?>
<section class="claude">
	<h2><?= e(t('Build it with Claude')) ?></h2>
	<p><?= e(t('In Claude, open Settings → Connectors, add a custom connector with this address and sign in with the account you have just created:')) ?></p>
	<p><code><?= e($mcp) ?></code></p>
	<p><?= e(t('Then tell Claude about your business, for example:')) ?></p>
	<blockquote class="prompt"><?= e(t('We are [company], we do [services] in [city]. Rewrite the pages of my Talea site for us, match the colours to our logo and leave everything as drafts for me to check.')) ?></blockquote>
	<p><a href="<?= e(Talea\Admin\Guide::url('claude-connect', $language ?? 'en')) ?>" target="_blank" rel="noopener"><?= e(t('Guide: connect Claude')) ?></a></p>
</section>
<?php endif ?>
</div>
</main>
</div>
</body>
</html>
