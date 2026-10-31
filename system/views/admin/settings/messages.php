<?php
/**
 * Settings → Messages: every text visitors read in e-mails and on the site for courses and bookings (Core\Messages), per language.
 * Each text starts from its template; a field that still holds the template is saved as "no own text".
 *
 * @var list<string> $messageGroups  the groups on offer (courses, bookings)
 */
use Talea\Core\Language;
use Talea\Core\Messages;

$settings = $app->settings();
$group = in_array($app->request->get('group'), $messageGroups, true) ? $app->request->get('group') : ($messageGroups[0] ?? '');
$languages = ['' => Language::AVAILABLE[Language::defaults($settings)][0] ?? Language::defaults($settings)];
foreach (Language::additional($settings) as $code) {
    $languages[$code] = Language::AVAILABLE[$code][0] ?? $code;
}
$language = array_key_exists($app->request->get('language'), $languages) ? $app->request->get('language') : '';
$link = fn (string $g, string $l): string => $app->url('admin.php?module=settings&tab=messages&group=' . $g . ($l !== '' ? '&language=' . $l : ''));
$groupNames = ['courses' => t('Courses'), 'bookings' => t('Bookings')];
?>
<p class="help"><?= e(t('What visitors read in e-mails and on the site. Every text starts from a template; change what you want, the rest stays as it is.')) ?></p>
<input type="hidden" name="group" value="<?= e($group) ?>">
<input type="hidden" name="language" value="<?= e($language) ?>">
<?php if (count($messageGroups) > 1): ?>
<nav class="tabs" aria-label="<?= e(t('Sections')) ?>">
<?php foreach ($messageGroups as $g): ?>
	<a href="<?= e($link($g, $language)) ?>"<?= $g === $group ? ' class="active" aria-current="page"' : '' ?>><?= e($groupNames[$g]) ?></a>
<?php endforeach ?>
</nav>
<?php endif ?>
<?php if (count($languages) > 1): ?>
<nav class="tabs" aria-label="<?= e(t('Language')) ?>">
<?php foreach ($languages as $code => $name): ?>
	<a href="<?= e($link($group, $code)) ?>"<?= $code === $language ? ' class="active" aria-current="page"' : '' ?>><?= e($name) ?></a>
<?php endforeach ?>
</nav>
<?php endif ?>
<?php foreach (['mail' => t('E-mails'), 'page' => t('On the site')] as $kind => $heading):
    $ids = array_keys(array_filter(Messages::CATALOGUE, fn (array $m): bool => $m['group'] === $group && $m['kind'] === $kind));
    if ($ids === []) {
        continue;
    } ?>
<h2><?= e($heading) ?></h2>
<?php foreach ($ids as $id):
    $m = Messages::CATALOGUE[$id];
    $changed = false;
    $invalid = false;
    foreach (Messages::parts($id) as $part) {
        $changed = $changed || trim($settings->get(Messages::key($id, $part, $language))) !== '';
        $invalid = $invalid || in_array(Messages::key($id, $part, $language), $invalidFields ?? [], true);
    }
    $names = array_map(fn (string $n): string => '{' . $n . '}', [...$m['placeholders'], ...$m['links']]); ?>
<details class="advanced"<?= $invalid ? ' open' : '' ?>>
	<summary><?= e(Messages::title($id)) ?><?php if ($changed): ?> <span class="badge"><?= e(t('Changed')) ?></span><?php endif ?></summary>
<?php foreach (Messages::parts($id) as $part):
    $key = Messages::key($id, $part, $language);
    $shown = trim($values[$key] ?? '') !== '' ? $values[$key] : Messages::text($settings, $id, $part, $language);
    $error = in_array($key, $invalidFields ?? [], true) ? ' aria-invalid="true"' : ''; ?>
	<div class="row">
		<label for="<?= e($key) ?>"><?= e($part === 'subject' ? t('Subject') : t('Text')) ?></label>
		<div>
<?php if ($part === 'subject'): ?>
			<input class="textfield wide" type="text" id="<?= e($key) ?>" name="<?= e($key) ?>" value="<?= e($shown) ?>" maxlength="200"<?= $error ?> lang="<?= e(Language::tag($language !== '' ? $language : Language::defaults($settings))) ?>">
<?php else: ?>
			<textarea class="textbox" id="<?= e($key) ?>" name="<?= e($key) ?>" rows="<?= $m['kind'] === 'mail' ? 9 : 3 ?>" maxlength="<?= Talea\Core\Messages::MAX ?>"<?= $error ?> lang="<?= e(Language::tag($language !== '' ? $language : Language::defaults($settings))) ?>"><?= e($shown) ?></textarea>
<?php endif ?>
<?php if ($part === 'body' && $names !== []): ?>
			<span class="help"><?= e(t('Placeholders: %s', implode(' ', $names))) ?><?= $m['links'] !== [] ? ' ' . e(t('A link whose placeholder is missing is added at the end.')) : '' ?></span>
<?php endif ?>
		</div>
	</div>
<?php endforeach ?>
	<p class="buttons">
		<button class="btn" type="submit" name="reset_message" value="<?= e($id) ?>"><?= e(t('Back to the template')) ?></button>
<?php if ($kind === 'mail'): ?>
		<button class="btn" type="submit" name="id" value="<?= e($id) ?>" formaction="<?= e($module->url('message_test')) ?>"><?= e(t('Send me a test')) ?></button>
<?php endif ?>
	</p>
</details>
<?php endforeach ?>
<?php endforeach ?>
<p class="help"><?= e(t('“Send me a test” sends the saved text to your own address, with example values in brackets.')) ?></p>
