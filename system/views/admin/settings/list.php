<?php
/**
 * Settings: tabs + the form of the selected tab (settings/<tab>.php).
 *
 * @var Kaleta\Admin\Modules\Settings $module
 * @var string $csrf
 * @var string $tab
 * @var array<string, string> $values
 * @var list<array{skupina:string, nazev:string, stav:string, info:string}> $checks
 * @var string $siteUrl
 * @var list<string> $enabledExtensions
 * @var list<array{soubor:string, velikost:int, cas:int}> $backups
 * @var array<string, mixed>|null $update
 * @var list<array{kategorie:string, pocet:int}> $consents
 * @var list<string> $errorLog  last lines of the error log
 * @var string $remoteStatus  result of the last backup upload off the server
 * @var string $tasksToken  secret part of the /ulohy url for cron
 * @var array<string, mixed>|null $domainWatch  the last domain and mail check (Core\DomainWatch), null = none yet
 * @var array<int, string> $pages  pages for choosing the home page (the "Základní" (General) tab)
 */
use Kaleta\Admin\Modules\Settings;

/** Form row: $field('key', 'Label', 'text|radky|kod|ano|cislo|url|email', 'hint', [attributes]) */
$invalidFields ??= [];
$field = function (string $key, string $labelText, string $kind = 'text', string $hint = '', string $attributes = '') use ($values, $app, $invalidFields): void {
    $h = $values[$key] ?? '';
    if (in_array($key, $invalidFields, true)) {
        $attributes .= ' aria-invalid="true"'; // an unsaved value to fix (the message at the top says what is wrong)
    }
    $labelText = t($labelText);
    $hint = $hint === '' ? '' : t($hint);
    // a hint without its own HTML: menu paths ("Nastavení → Pošta") turn into links
    $hintHtml = $hint !== '' ? '<span class="napoveda">' . (str_contains($hint, '<') ? $hint : Kaleta\Admin\MenuPaths::links($app->url('admin.php'), $hint, ['config', 'vzhled', 'bloky'])) . '</span>' : '';
    echo '<div class="radek">';
    if ($kind === 'ano') {
        echo '<span class="popisek">' . e($labelText) . '</span><div class="volby"><label><input type="checkbox" name="' . e($key) . '" value="1"' . ($h === '1' ? ' checked' : '') . '> ' . e(t('Yes')) . '</label>' . $hintHtml . '</div>';
    } elseif ($kind === 'radky' || $kind === 'kod') {
        echo '<label for="' . e($key) . '">' . e($labelText) . '</label><div><textarea class="textbox nizky' . ($kind === 'kod' ? ' kod' : '') . '" id="' . e($key) . '" name="' . e($key) . '" rows="4" ' . $attributes . '>' . e($h) . '</textarea>' . $hintHtml . '</div>';
    } else {
        $type = ['cislo' => 'number', 'url' => 'url', 'email' => 'email'][$kind] ?? 'text';
        echo '<label for="' . e($key) . '">' . e($labelText) . '</label><div><input class="textpole' . ($type === 'number' ? '' : ' siroke') . '" type="' . $type . '" id="' . e($key) . '" name="' . e($key) . '" value="' . e($h) . '" ' . $attributes . '>' . $hintHtml . '</div>';
    }
    echo '</div>';
};
?>
<?php if ($module::IDENT === 'settings'): ?>
<nav class="zalozky" aria-label="<?= e(t('Settings sections')) ?>">
<?php foreach (Settings::TABS as $key => $name): ?>
	<a href="<?= e($module->url('', ['tab' => $key])) ?>"<?= $tab === $key ? ' class="aktivni" aria-current="page"' : '' ?>><?= e(t($name)) ?></a>
<?php endforeach ?>
</nav>
<?php endif ?>
<form class="formular" method="post" action="<?= e($module->url('save')) ?>">
<?php /* the first submit button in the form determines what Enter does: save the settings (not a backup, an update check or a test e-mail) */ ?>
<button type="submit" class="vychozi-odeslani" tabindex="-1" aria-hidden="true"><?= e(t('Save settings')) ?></button>
<?= $csrf ?>
<input type="hidden" name="tab" value="<?= e($tab) ?>">
<?php require __DIR__ . '/' . $tab . '.php'; ?>
<?php if ($tab !== 'health'): ?>
<p class="tlacitka"><input class="tl" type="submit" value="<?= e(t('Save settings')) ?>"></p>
<?php endif ?>
</form>
