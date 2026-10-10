<?php
/**
 * @var string $base
 * @var list<array{nazev:string, ok:bool, info:string}> $requirements
 * @var array<string, string> $data
 * @var array<string, string> $errors
 * @var string $language  installation language (cs, en)
 * @var array<string, string> $languages
 * @var list<string> $extensions  checked extensions
 */
$error = fn (string $field): string => isset($errors[$field]) ? '<span class="error-field" role="alert">' . e($errors[$field]) . '</span>' : '';
$fulfilled = !in_array(false, array_column($requirements, 'ok'), true);
$step = 0; // numbering of the visible steps (in a container the server check and the database step are not shown)
$n = function () use (&$step): int {
	return ++$step;
};
?>
<!doctype html>
<html lang="<?= e($language) ?>">
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
<nav class="languages" aria-label="Language">
<?php foreach ($languages as $code => $languageName): ?>
	<a href="?language=<?= e($code) ?>"<?= $code === $language ? ' class="active" aria-current="true"' : '' ?> lang="<?= e($code) ?>"><?= e($languageName) ?></a>
<?php endforeach ?>
</nav>
<?php if ($language === 'de'): ?>
<nav class="languages" aria-label="<?= e(t('Form of address')) ?>">
	<a href="?language=de&amp;register=formal"<?= $register !== 'informal' ? ' class="active" aria-current="true"' : '' ?>><?= e(t('Formal (Sie)')) ?></a>
	<a href="?language=de&amp;register=informal"<?= $register === 'informal' ? ' class="active" aria-current="true"' : '' ?>><?= e(t('Informal (du)')) ?></a>
</nav>
<?php endif ?>
<header class="intro">
	<div class="brand"><?php $height = 40; $markOnly = false; require TALEA_SYSTEM . '/views/admin/logo.php'; ?></div>
	<h1><?= e(t('Talea installation')) ?></h1>
	<p><?= e(t('A few short steps and your website is running. Everything can be changed later in the administration.')) ?></p>
</header>

<?php if (empty($envDb) || !$fulfilled): ?>
<section class="step">
	<h2><span><?= $n() ?></span> <?= e(t('Server check')) ?></h2>
	<ul class="check">
<?php foreach ($requirements as $p): ?>
		<li<?= $p['ok'] ? '' : ' class="wrong"' ?>><div><?= e($p['name']) ?> <small>– <?= e($p['info']) ?></small></div></li>
<?php endforeach ?>
	</ul>
</section>
<?php endif ?>

<?php if (!$fulfilled): ?>
<p class="notice notice-error" role="alert"><?= e(t('The server does not meet the requirements. Fix the items marked with a cross and reload the page.')) ?></p>
<?php else: ?>
<?php if ($errors !== []): ?>
<p class="notice notice-error" role="alert"><?= e(t('The installation could not be completed – check the highlighted fields.')) ?></p>
<?php endif ?>
<form method="post" autocomplete="off">
<input type="hidden" name="language" value="<?= e($language) ?>">
<input type="hidden" name="register" value="<?= e($register ?? 'formal') ?>">
<?php if (empty($envDb)): ?>
<section class="step">
	<h2><span><?= $n() ?></span> <?= e(t('Database')) ?></h2>
	<p><?= e(t('MySQL, MariaDB or PostgreSQL. Create an empty database beforehand – in your hosting control panel.')) ?></p>
	<div class="field">
		<div class="full"><label for="db_driver"><?= e(t('Database type')) ?></label><select id="db_driver" name="db_driver">
			<option value="mysql"<?= $data['db_driver'] === 'mysql' ? ' selected' : '' ?>>MySQL / MariaDB</option>
			<option value="pgsql"<?= $data['db_driver'] === 'pgsql' ? ' selected' : '' ?>>PostgreSQL</option>
		</select><?= $error('db_driver') ?></div>
		<div class="full with-port">
			<div><label for="db_host"><?= e(t('Server')) ?></label><input type="text" id="db_host" name="db_host" value="<?= e($data['db_host']) ?>"><?= $error('db_host') ?></div>
			<div><label for="db_port"><?= e(t('Port')) ?></label><input type="number" id="db_port" name="db_port" value="<?= e($data['db_port']) ?>" placeholder="3306 / 5432"></div>
		</div>
		<div><label for="db_name"><?= e(t('Database name')) ?></label><input type="text" id="db_name" name="db_name" value="<?= e($data['db_name']) ?>" required><?= $error('db_name') ?></div>
		<div><label for="db_prefix"><?= e(t('Table prefix')) ?></label><input type="text" id="db_prefix" name="db_prefix" value="<?= e($data['db_prefix']) ?>" required><?= $error('db_prefix') ?></div>
		<div><label for="db_user"><?= e(t('User')) ?></label><input type="text" id="db_user" name="db_user" value="<?= e($data['db_user']) ?>" required><?= $error('db_user') ?></div>
		<div><label for="db_password"><?= e(t('Password')) ?></label><input type="password" id="db_password" name="db_password" autocomplete="off"></div>
	</div>
</section>
<?php endif ?>

<section class="step">
	<h2><span><?= $n() ?></span> <?= e(t('Site and administrator')) ?></h2>
	<p><?= e(t('The account you will first sign in to the administration with.')) ?></p>
	<div class="field">
		<div class="full"><label for="site_name"><?= e(t('Site name')) ?></label><input type="text" id="site_name" name="site_name" value="<?= e($data['site_name']) ?>" required></div>
		<fieldset class="full sites">
			<legend><?= e(t('Start with a website')) ?></legend>
<?php foreach (Talea\Builder\Library::SITES as $key => $w): $colors = Talea\Builder\DesignSystem::PRESETS[$w['preset']][2]['colors']; ?>
			<label class="site"><input type="radio" name="starter" value="<?= e($key) ?>"<?= ($data['starter'] ?: 'business') === $key ? ' checked' : '' ?>>
				<span class="swatches"><i style="background:<?= e($colors['primary']) ?>"></i><i style="background:<?= e($colors['secondary']) ?>"></i><i style="background:<?= e($colors['surface']) ?>"></i></span>
				<strong><?= e(t($w['name'])) ?></strong><small><?= e(t($w['description'])) ?></small></label>
<?php endforeach ?>
			<label class="site"><input type="radio" name="starter" value="export"<?= $data['starter'] === 'export' ? ' checked' : '' ?>>
				<span class="swatches"><i></i><i></i><i></i></span>
				<strong><?= e(t('Start from an export')) ?></strong><small><?= e(t('An empty site for moving another Talea site here – right after installation you import its export in Import and export.')) ?></small></label>
			<span class="help"><?= e(t('A starter site brings Home, About us, Services and Contact pages with sample texts and its own style – edit the content in the builder and the style in Site appearance.')) ?></span>
		</fieldset>
		<div><label for="user"><?= e(t('User name')) ?></label><input type="text" id="user" name="username" value="<?= e($data['username']) ?>" required><?= $error('username') ?></div>
		<div><label for="name"><?= e(t('First and last name')) ?></label><input type="text" id="name" name="name" value="<?= e($data['name']) ?>"><span class="help"><?= e(t('Shown with news items.')) ?></span></div>
		<div class="full"><label for="email"><?= e(t('Email')) ?></label><input type="email" id="email" name="email" value="<?= e($data['email']) ?>"><?= $error('email') ?></div>
		<div><label for="password"><?= e(t('Password')) ?></label><input type="password" id="password" name="password" autocomplete="new-password" minlength="10" required><?= $error('password') ?><span class="help"><?= e(t('At least 10 characters.')) ?></span></div>
		<div><label for="password2"><?= e(t('Repeat password')) ?></label><input type="password" id="password2" name="password2" autocomplete="new-password" required></div>
		<div class="full"><label for="site_language"><?= e(t('Site language')) ?></label><select id="site_language" name="site_language">
<?php foreach (Talea\Core\Language::AVAILABLE as $code => [$languageName]): ?>
			<option value="<?= e($code) ?>"<?= $data['site_language'] === $code ? ' selected' : '' ?>><?= e($languageName) ?></option>
<?php endforeach ?>
		</select><span class="help"><?= e(t('The sample pages and the texts for visitors are created in this language. The administration stays in the language of the installation.')) ?></span></div>
		<div class="full"><label for="time_zone"><?= e(t('Time zone')) ?></label><select id="time_zone" name="time_zone">
<?php foreach (DateTimeZone::listIdentifiers() as $timeZone): ?>
			<option value="<?= e($timeZone) ?>"<?= $data['time_zone'] === $timeZone ? ' selected' : '' ?>><?= e(str_replace('_', ' ', $timeZone)) ?></option>
<?php endforeach ?>
		</select><span class="help"><?= e(t('Scheduled news posts are published and dates are shown according to it.')) ?></span></div>
	</div>
</section>

<section class="step">
	<h2><span><?= $n() ?></span> <?= e(t('What you want switched on')) ?></h2>
	<p><?= e(t('Features can be switched on or off at any time in the administration (Features). Switching off deletes nothing.')) ?></p>
	<div class="extensions">
<?php foreach (Talea\Core\Extensions::CATALOG as $key => [$extensionName, $extensionDescription]): ?>
		<label class="site"><input type="checkbox" name="extensions[]" value="<?= e($key) ?>"<?= in_array($key, $extensions, true) ? ' checked' : '' ?>>
			<strong><?= e(t($extensionName)) ?></strong><small><?= e(t($extensionDescription)) ?></small></label>
<?php endforeach ?>
	</div>
</section>

<div class="actions">
	<button class="button" type="submit"><?= e(t('Install Talea')) ?></button>
	<small><?= e(t('Creates the database tables and the config.php file.')) ?></small>
</div>
</form>
<?php endif ?>
</main>
</body>
</html>
