<?php
/**
 * @var string $base
 * @var bool $alreadyInstalled
 * @var bool $deleted the installer deleted itself
 * @var bool $fromExport "Start from an export": the next step is the import
 * @var string|null $mcp address of the Claude connection, when it is switched on (2.5: the next step after installing)
 * @var string|null $cron the cron line for the background jobs (2.8)
 */
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
<main class="installer">
<header class="intro">
	<div class="brand"><?php $height = 40; $markOnly = false; require TALEA_SYSTEM . '/views/admin/logo.php'; ?></div>
<?php if ($alreadyInstalled): ?>
	<h1><?= e(t('Talea is already installed')) ?></h1>
	<p><?= e(t('The config.php file exists, so the installer changes nothing.')) ?></p>
<?php else: ?>
	<h1><?= e(t('Done, your website is running')) ?></h1>
	<p><?= e(t('The database is ready and the configuration has been written.')) ?></p>
<?php endif ?>
</header>
<?php if ($deleted): ?>
<p class="notice notice-ok"><?= e(t('For security reasons install.php has deleted itself – there is nothing else you need to do.')) ?></p>
<?php else: ?>
<p class="notice <?= $alreadyInstalled ? 'notice-error' : 'notice-ok' ?>"><?= e(t('For security reasons, now delete this file from the server:')) ?> <strong>install.php</strong>.</p>
<?php endif ?>
<?php if (!empty($fromExport)): ?>
<p><?= e(t('The site is empty. Sign in and import the export of your Talea site in Import and export → Import from Talea.')) ?></p>
<div class="actions">
	<a class="button" href="<?= e($base) ?>/admin.php?module=transfer"><?= e(t('Continue with the import')) ?></a>
<?php else: ?>
<div class="actions">
	<a class="button" href="<?= e($base) ?>/admin.php"><?= e(t('Go to the administration')) ?></a>
<?php endif ?>
	<a class="button second" href="<?= e($base) ?>/"><?= e(t('View site')) ?></a>
</div>
<?php if (!empty($mcp) && empty($fromExport)): ?>
<section class="claude">
	<h2><?= e(t('Build it with Claude')) ?></h2>
	<p><?= e(t('In Claude, open Settings → Connectors, add a custom connector with this address and sign in with the account you have just created:')) ?></p>
	<p><code><?= e($mcp) ?></code></p>
	<p><?= e(t('Then tell Claude about your business, for example:')) ?></p>
	<blockquote><?= e(t('We are [company], we do [services] in [city]. Rewrite the pages of my Talea site for us, match the colours to our logo and leave everything as drafts for me to check.')) ?></blockquote>
	<p><a href="<?= e(Talea\Admin\Guide::url('claude-connect', $language ?? 'en')) ?>" target="_blank" rel="noopener"><?= e(t('Guide: connect Claude')) ?></a></p>
</section>
<?php endif ?>
<?php if (!empty($cron)): ?>
<section class="claude">
	<h2><?= e(t('Background jobs')) ?></h2>
	<p><?= e(t('The site publishes scheduled news, sends mail, makes backups and checks itself in the background. It works on visits alone; for exact timing add this line to your hosting\'s cron (every 5 minutes). You will find it later in System status.')) ?></p>
	<p><code><?= e($cron) ?></code></p>
</section>
<?php endif ?>
</main>
</body>
</html>
