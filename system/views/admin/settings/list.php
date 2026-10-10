<?php
/**
 * Settings: tabs + the form of the selected tab (settings/<tab>.php).
 *
 * @var Talea\Admin\Modules\Settings $module
 * @var string $csrf
 * @var string $tab
 * @var array<string, string> $values
 * @var list<array{group:string, nazev:string, state:string, info:string}> $checks
 * @var string $siteUrl
 * @var list<string> $enabledExtensions
 * @var list<array{file:string, size:int, time:int}> $backups
 * @var array<string, mixed>|null $update
 * @var list<array{categories:string, count:int}> $consents
 * @var list<string> $errorLog  last lines of the error log
 * @var string $remoteStatus  result of the last backup upload off the server
 * @var string $tasksToken  secret part of the /tasks url for cron
 * @var array<int, string> $pages  pages for choosing the home page (the "General" tab)
 * @var list<array{name: string, provider: string, purpose: string, duration: string, category: string}> $cookieTable  cookies and storage the site uses (2.14, the Privacy tab)
 * @var array{time?: int, pages?: int, cookies?: list<string>, error?: string} $cookieScan  the last scan of the site's own pages (2.14)
 * @var array<string, mixed>|null $statementPage  the accessibility statement page created from the audit (2.14), null = none yet
 */
use Talea\Admin\Modules\Settings;

/** Form row: $field('key', 'Label', 'text|radky|kod|ano|cislo|url|email', 'hint', [attributes]) */
$invalidFields ??= [];
$field = function (string $key, string $labelText, string $kind = 'text', string $hint = '', string $attributes = '') use ($values, $app, $invalidFields): void {
    $h = $values[$key] ?? '';
    if (in_array($key, $invalidFields, true)) {
        $attributes .= ' aria-invalid="true"'; // an unsaved value to fix (the message at the top says what is wrong)
    }
    $labelText = t($labelText);
    $hint = $hint === '' ? '' : t($hint);
    // a hint without its own HTML: menu paths ("Settings → Mail") turn into links
    $hintHtml = $hint !== '' ? '<span class="help">' . (str_contains($hint, '<') ? $hint : Talea\Admin\MenuPaths::links($app->url('admin.php'), $hint, ['settings', 'appearance', 'menu', 'business', 'status', 'claude_settings'])) . '</span>' : '';
    echo '<div class="row">';
    if ($kind === 'flag') {
        echo '<span class="caption">' . e($labelText) . '</span><div class="options"><label><input type="checkbox" name="' . e($key) . '" value="1"' . ($h === '1' ? ' checked' : '') . '> ' . e(t('Yes')) . '</label>' . $hintHtml . '</div>';
    } elseif ($kind === 'lines' || $kind === 'code') {
        echo '<label for="' . e($key) . '">' . e($labelText) . '</label><div><textarea class="textbox low' . ($kind === 'code' ? ' code' : '') . '" id="' . e($key) . '" name="' . e($key) . '" rows="4" ' . $attributes . '>' . e($h) . '</textarea>' . $hintHtml . '</div>';
    } else {
        $type = ['number' => 'number', 'url' => 'url', 'email' => 'email'][$kind] ?? 'text';
        echo '<label for="' . e($key) . '">' . e($labelText) . '</label><div><input class="textfield' . ($type === 'number' ? '' : ' wide') . '" type="' . $type . '" id="' . e($key) . '" name="' . e($key) . '" value="' . e($h) . '" ' . $attributes . '>' . $hintHtml . '</div>';
    }
    echo '</div>';
};
?>
<?php if ($module::IDENT === 'settings'): ?>
<nav class="tabs" aria-label="<?= e(t('Settings sections')) ?>">
<?php foreach (array_diff_key(Settings::TABS, Settings::MOVED_TABS) as $key => $name): ?>
	<a href="<?= e($module->url('', ['tab' => $key])) ?>"<?= $tab === $key ? ' class="active" aria-current="page"' : '' ?>><?= e(t($name)) ?></a>
<?php endforeach ?>
</nav>
<?php endif ?>
<form class="form" method="post" action="<?= e($module->url('save')) ?>">
<?php /* the first submit button in the form determines what Enter does: save the settings (not a backup, an update check or a test e-mail) */ ?>
<button type="submit" class="default-sending" tabindex="-1" aria-hidden="true"><?= e(t('Save settings')) ?></button>
<?= $csrf ?>
<input type="hidden" name="tab" value="<?= e($tab) ?>">
<?php require __DIR__ . '/' . $tab . '.php'; ?>
<?php if (!in_array($tab, ['health', 'console'], true)): ?>
<p class="buttons"><input class="btn" type="submit" value="<?= e(t('Save settings')) ?>"></p>
<?php endif ?>
</form>
