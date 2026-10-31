<?php
/**
 * One screen of the installer wizard: the sidebar with the steps, the step, Back and Continue. The whole page is one form, so the buttons of
 * the sidebar (back to a finished step, the language) belong to it too.
 *
 * @var string $base
 * @var list<string> $failed  requirements of the server that are not met
 * @var string $step  the step shown
 * @var list<string> $steps
 * @var array<string, string> $data
 * @var array<string, string> $errors
 * @var list<string> $extensions  checked extensions
 * @var bool $envDb  the database comes from the environment
 * @var array<string, array<string, mixed>> $blueprints
 * @var list<string> $groups
 * @var array<string, array<string, mixed>> $looks  ranked for the business
 * @var list<string> $topics  looks topics of the business
 * @var string $chosenLook
 * @var string $preview  address of the preview page, '' = none
 * @var string $language  installation language (cs, de, en)
 * @var string $register
 * @var array<string, string> $languages
 */
$error = fn (string $field): string => isset($errors[$field]) ? '<span class="error-field" id="' . e($field) . '_error">' . e($errors[$field]) . '</span>' : '';
$invalid = fn (string $field): string => isset($errors[$field]) ? ' aria-invalid="true" aria-describedby="' . e($field) . '_error"' : '';
$position = (int) array_search($step, $steps, true);
$titles = [
	'website' => [t('Your website'), t('A few short steps and your website is running. Everything can be changed later in the administration.')],
	'business' => [t('What kind of business is it?'), t('Talea sets up the lists your kind of business needs – a menu, rooms, courses, a team – and the facts visitors ask about. Everything starts empty; nothing is invented for you.')],
	'look' => [t('Look'), t('Colours, fonts, header and footer. The looks that suit your business come first; the preview shows your home page.')],
	'features' => [t('Features'), t('Features can be switched on or off at any time in the administration (Features). Switching off deletes nothing.')],
	'account' => [t('Your account'), t('The administrator you will first sign in with. More people can be invited later.')],
];
// what the other steps asked travels in hidden fields; a step's own fields are its inputs (the password is asked last and never travels)
$carried = array_diff_key(['site_name' => 1, 'site_language' => 1, 'time_zone' => 1, 'blueprint' => 1, 'username' => 1, 'name' => 1, 'email' => 1], match ($step) {
	'website' => ['site_name' => 1, 'site_language' => 1, 'time_zone' => 1], 'business' => ['blueprint' => 1], 'account' => ['username' => 1, 'name' => 1, 'email' => 1], default => [],
});
?>
<!doctype html>
<html lang="<?= e($language) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= e(t('Talea installation')) ?> – <?= e($titles[$step][0]) ?></title>
<link rel="icon" type="image/svg+xml" href="<?= e($base) ?>/image/talea-mark.svg">
<link rel="alternate icon" type="image/png" sizes="32x32" href="<?= e($base) ?>/image/talea-mark-32.png">
<link rel="apple-touch-icon" href="<?= e($base) ?>/image/talea-mark-180.png">
<link rel="stylesheet" href="<?= e($base) ?>/image/install.css?v=<?= e(TALEA_VERSION) ?>">
</head>
<body>
<form id="wizard" class="setup" method="post" autocomplete="off">
<button class="visually-hidden" type="submit" name="go" value="next" tabindex="-1" aria-hidden="true"></button>
<input type="hidden" name="step" value="<?= e($step) ?>">
<input type="hidden" name="language" value="<?= e($language) ?>">
<input type="hidden" name="register" value="<?= e($register) ?>">
<?php foreach (array_intersect_key($data, $carried) as $name => $value): ?>
<input type="hidden" name="<?= e($name) ?>" value="<?= e($value) ?>">
<?php endforeach ?>
<?php if ($step !== 'look'): ?>
<input type="hidden" name="look" value="<?= e($data['look']) ?>">
<?php else: ?>
<input type="hidden" name="look_kept" value="<?= e($data['look']) ?>">
<?php endif ?>
<?php if ($step !== 'features'): foreach ($extensions as $key): ?>
<input type="hidden" name="extensions[]" value="<?= e($key) ?>">
<?php endforeach; endif ?>
<aside class="setup-side"><?php $current = $position; $interactive = true; require TALEA_SYSTEM . '/views/install/sidebar.php'; ?></aside>
<main class="setup-main">
<div class="setup-inner<?= $step === 'business' || $step === 'look' ? ' wide' : '' ?>">
<p class="wizard-count"><?= e(sprintf(t('Step %d of %d'), $position + 1, count($steps))) ?></p>
<div class="bar" aria-hidden="true"><?php foreach ($steps as $i => $key): ?><i<?= $i < $position ? ' class="ok"' : ($i === $position ? ' class="on"' : '') ?>></i><?php endforeach ?></div>
<h1 class="page-title"><?= e($titles[$step][0]) ?></h1>
<p class="lead"><?= e($titles[$step][1]) ?></p>
<?php if ($failed !== []): ?>
<p class="notice notice-error" role="alert"><?= e(t('The server does not meet the requirements:')) ?> <?= e(implode(', ', $failed)) ?></p>
<?php else: ?>
<?php if ($errors !== []): ?>
<p class="notice notice-error" role="alert"><?= e(t('Check the highlighted fields before you can continue.')) ?></p>
<?php endif ?>
<?php if ($envDb): foreach ($errors as $field => $message): if (str_starts_with($field, 'db_')): ?>
<p class="notice notice-error" role="alert"><?= e($message) ?></p>
<?php endif; endforeach; endif ?>
<div class="wizard-card<?= $step === 'features' ? ' flush' : '' ?>">
<?php require TALEA_SYSTEM . '/views/install/step-' . $step . '.php'; ?>
<div class="buttons wizard-nav">
<?php if ($position > 0): ?>
	<button class="navigation" type="submit" name="go" value="prev" formnovalidate><?= e(t('Back')) ?></button>
<?php endif ?>
<?php if ($step === 'features'): ?>
	<p class="wizard-hint"><?= e(t('Takes a few seconds.')) ?></p>
<?php elseif ($step === 'business'): ?>
	<p class="wizard-hint"><?= e(t('Next: the looks that suit it best.')) ?></p>
<?php endif ?>
	<button class="btn" type="submit" name="go" value="next"><?= e($step === 'account' ? t('Install Talea') : t('Continue')) ?></button>
</div>
</div>
<?php endif ?>
</div>
</main>
</form>
</body>
</html>
