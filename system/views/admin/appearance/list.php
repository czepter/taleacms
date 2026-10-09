<?php
/**
 * Site appearance: styles (ready-made sets of colours, fonts and corner rounding – DesignSystem::PRESETS) and the design system
 * in tabs (colours, dark mode, font and sizes, shapes, brand, import and export) with a live preview of the real home page.
 * Tabs are switched by image/admin.js (data-tabs); without the script the whole form is visible at once.
 * The preview is handled by image/admin.js (data-appearance): after every change it requests the token CSS (action nahled) and puts it into the iframe.
 *
 * @var Kaleta\Core\App $app
 * @var Kaleta\Admin\Modules\Appearance $module
 * @var string $csrf
 * @var array<string, mixed> $ds
 * @var list<array{popis:string, pomer:float, ok:bool}> $contrasts
 * @var array<string, array{nazev:string, popis:string, ds:array<string, mixed>}> $presets
 * @var array<string, string> $values
 * @var list<array{id: int, summary: string, created: string, author: ?string}> $versions earlier published looks (Core\Look)
 */
use Kaleta\Front\SiteIdentity;
use Kaleta\Builder\DesignSystem;

$px = fn (float $rem): string => (string) round($rem * 16);
$contrastsHtml = function (array $contrasts): string {
    $html = '';
    foreach ($contrasts as $k) {
        $html .= '<li class="' . ($k['ok'] ? 'ok' : 'wrong') . '"><span>' . e(t($k['description'])) . '</span><strong>' . e(t('%s:1', format_number($k['ratio']))) . '</strong></li>';
    }

    return $html;
};
// the style the current appearance is based on (colours and heading font as in the style)
$current = null;
foreach ($presets as $key => $p) {
    if ($p['ds']['colors']['primary'] === $ds['colors']['primary'] && $p['ds']['colors']['secondary'] === $ds['colors']['secondary'] && $p['ds']['font_heading'] === $ds['font_heading']) {
        $current = $key;
        break;
    }
}
$tabs = ['style' => 'Style', 'colors' => 'Colours', 'dark' => 'Dark mode', 'font' => 'Fonts and sizes', 'tvary' => 'Shapes', 'tag' => 'Logo and icon', 'export' => 'Import and export'];
?>
<div class="appearance" data-tabs>
<div class="tabs" role="tablist" aria-label="<?= e(t('Parts of the appearance')) ?>">
<?php foreach ($tabs as $key => $name): ?>
	<button type="button" role="tab" id="zalozka-<?= $key ?>" aria-controls="panel-<?= $key ?>" aria-selected="<?= $key === 'style' ? 'true' : 'false' ?>"<?= $key === 'style' ? '' : ' tabindex="-1"' ?>><?= e(t($name)) ?></button>
<?php endforeach ?>
</div>
<form class="form appearance-form" method="post" action="<?= e($module->url('save')) ?>" data-appearance data-preview-url="<?= e($module->url('preview')) ?>">
<?= $csrf ?>

<div role="tabpanel" id="panel-style" aria-labelledby="tab-style">
<fieldset>
<legend><?= e(t('Styles')) ?></legend>
<p class="help"><?= e(t('A style is a ready set of colours, fonts, sizes and corner radius. Pick one with a click and fine-tune it in the other tabs – the content of the site does not change.')) ?></p>
<div class="appearance-presets">
<?php foreach ($presets as $key => $p): ?>
	<button type="button" class="appearance-preset" data-preset="<?= e((string) json_encode($p['ds'], JSON_UNESCAPED_SLASHES)) ?>">
		<span class="appearance-swatches"><?php foreach (['primary', 'secondary', 'text', 'surface'] as $b): ?><i style="background:<?= e($p['ds']['colors'][$b]) ?>"></i><?php endforeach ?></span>
		<strong style="font-family:<?= e(SiteIdentity::TITLE_FONTS[$p['ds']['font_heading']][2]) ?>"><?= e(t($p['name'])) ?></strong>
		<small><?= e(t($p['description'])) ?></small>
<?php if ($key === $current): ?>		<span class="badge badge-published"><?= e(t('current')) ?></span>
<?php endif ?>
	</button>
<?php endforeach ?>
</div>
<p class="help"><?= e(t('You do not have to put a look for your brand together by hand: connect Claude and write, for example, “Set the look of the site to our brand – primary colour #0E6E6E, serif headings, subtle rounding”. It saves the colours and fonts to the design system, and you see the result here.')) ?> <a href="<?= e($app->url('admin.php?module=extensions#claude')) ?>"><?= e(t('How to connect Claude')) ?></a></p>
</fieldset>
</div>

<div role="tabpanel" id="panel-colors" aria-labelledby="tab-colors">
<fieldset>
<legend><?= e(t('Colours')) ?></legend>
<div class="appearance-colors">
<?php foreach (DesignSystem::COLORS as $key => $name): ?>
	<label class="appearance-color">
		<input type="color" name="ds[colors][<?= e($key) ?>]" value="<?= e($ds['colors'][$key]) ?>">
		<span><?= e(t($name)) ?><small data-hex><?= e($ds['colors'][$key]) ?></small></span>
	</label>
<?php endforeach ?>
</div>
<p class="help"><?= e(t('Shades (muted text, lines, soft primary colour) and the button text colour are derived automatically.')) ?></p>
<h2 class="appearance-subheading"><?= e(t('Readability')) ?></h2>
<ul class="appearance-contrasts" data-contrasts><?= $contrastsHtml($contrasts) ?></ul>
<p class="help"><?= e(t('Text should have a contrast of at least 4.5 : 1 (WCAG AA). Pairs marked in red will be hard to read for some visitors.')) ?></p>
</fieldset>
</div>

<div role="tabpanel" id="panel-dark" aria-labelledby="tab-dark">
<fieldset>
<legend><?= e(t('Dark mode')) ?></legend>
<div class="options">
	<label><input type="radio" name="dark_mode" value="off" data-toggle="dark:0"<?= !in_array($values['dark_mode'], ['auto', 'dark'], true) ? ' checked' : '' ?>> <?= e(t('off – the site is always light')) ?></label><br>
	<label><input type="radio" name="dark_mode" value="auto" data-toggle="dark:1"<?= $values['dark_mode'] === 'auto' ? ' checked' : '' ?>> <?= e(t('according to the visitor\'s device')) ?></label><br>
	<label><input type="radio" name="dark_mode" value="dark" data-toggle="dark:1"<?= $values['dark_mode'] === 'dark' ? ' checked' : '' ?>> <?= e(t('always dark')) ?></label>
</div>
<div class="appearance-colors" data-section="dark"<?= !in_array($values['dark_mode'], ['auto', 'dark'], true) ? ' hidden' : '' ?>>
<?php foreach (['text' => 'Text', 'background' => 'Background', 'surface' => 'Surface'] as $key => $name): ?>
	<label class="appearance-color">
		<input type="color" name="ds[colors_dark][<?= e($key) ?>]" value="<?= e($ds['colors_dark'][$key]) ?>">
		<span><?= e(t($name)) ?><small data-hex><?= e($ds['colors_dark'][$key]) ?></small></span>
	</label>
<?php endforeach ?>
	<p class="help"><?= e(t('Check your logo: a dark logo on a transparent background would disappear on a dark site.')) ?></p>
</div>
<label class="appearance-switch" data-section="dark"<?= !in_array($values['dark_mode'], ['auto', 'dark'], true) ? ' hidden' : '' ?>><input type="checkbox" name="theme_switcher" value="1"<?= $values['theme_switcher'] === '1' ? ' checked' : '' ?>> <?= e(t('Switcher for visitors – in the header they choose light, dark or matching their device (the choice is remembered in their browser)')) ?></label>
</fieldset>
</div>

<div role="tabpanel" id="panel-font" aria-labelledby="tab-font">
<fieldset>
<legend><?= e(t('Font')) ?></legend>
<div class="row">
	<label for="ds-font-heading"><?= e(t('Headings')) ?></label>
	<select id="ds-font-heading" name="ds[font_heading]">
<?php foreach (SiteIdentity::TITLE_FONTS as $key => [$name, $description]): if ($key === 'vychozi') { continue; } ?>
		<option value="<?= e($key) ?>"<?= $ds['font_heading'] === $key ? ' selected' : '' ?>><?= e(t($name) . ' – ' . t($description)) ?></option>
<?php endforeach ?>
<?php foreach ($ds['custom_fonts'] as $i => $vp): ?>
		<option value="custom-<?= $i + 1 ?>"<?= $ds['font_heading'] === 'custom-' . ($i + 1) ? ' selected' : '' ?>><?= e($vp['name'] . ' – ' . t('custom font')) ?></option>
<?php endforeach ?>
	</select>
</div>
<div class="row">
	<label for="ds-font-body"><?= e(t('Text')) ?></label>
	<div><select id="ds-font-body" name="ds[font_body]">
<?php foreach (SiteIdentity::TEXT_FONTS as $key => [$name, $description]): if ($key === 'vychozi') { continue; } ?>
		<option value="<?= e($key) ?>"<?= $ds['font_body'] === $key ? ' selected' : '' ?>><?= e(t($name) . ' – ' . t($description)) ?></option>
<?php endforeach ?>
<?php foreach ($ds['custom_fonts'] as $i => $vp): ?>
		<option value="custom-<?= $i + 1 ?>"<?= $ds['font_body'] === 'custom-' . ($i + 1) ? ' selected' : '' ?>><?= e($vp['name'] . ' – ' . t('custom font')) ?></option>
<?php endforeach ?>
	</select>
	<span class="help"><?= e(t('System fonts download nothing. A custom font is stored on your own server – it does not need visitor consent either.')) ?></span></div>
</div>
<details class="advanced"<?= $ds['custom_fonts'] !== [] ? ' open' : '' ?>>
<summary><?= e(t('Brand fonts (WOFF2)')) ?></summary>
<p class="help"><?= e(t('Upload the font files (.woff2) to Media and paste their address here. One variable font file is enough, or a regular and a bold weight. After saving, choose the font above.')) ?></p>
<?php for ($i = 0; $i < 3; $i++): $vp = $ds['custom_fonts'][$i] ?? ['name' => '', 'file' => '', 'bold' => '']; ?>
<div class="row">
	<span class="caption"><?= e(t('Font %d', $i + 1)) ?></span>
	<div class="field-beside">
		<input class="textfield" type="text" name="ds[custom_fonts][<?= $i ?>][name]" value="<?= e($vp['name']) ?>" maxlength="40" placeholder="<?= e(t('name, e.g. Bricolage Grotesque')) ?>" aria-label="<?= e(t('Name of font %d', $i + 1)) ?>">
		<input class="textfield" type="text" name="ds[custom_fonts][<?= $i ?>][soubor]" value="<?= e($vp['file']) ?>" placeholder="media/…/pismo.woff2" aria-label="<?= e(t('File of font %d', $i + 1)) ?>">
		<input class="textfield" type="text" name="ds[custom_fonts][<?= $i ?>][bold]" value="<?= e($vp['bold']) ?>" placeholder="<?= e(t('bold weight (optional)')) ?>" aria-label="<?= e(t('Bold weight of font %d', $i + 1)) ?>">
	</div>
</div>
<?php endfor ?>
</details>
</fieldset>

<fieldset>
<legend><?= e(t('Sizes')) ?></legend>
<p class="help"><?= e(t('Type and spacing grow smoothly with the window width – from phone to large monitor. Headings are multiples of the base font size.')) ?></p>
<div class="appearance-grid">
	<label><span><?= e(t('Base font size on phones')) ?></span><span class="appearance-unit"><input type="number" name="ds[base_min]" value="<?= e($px($ds['base_min'])) ?>" min="13" max="24" step="1"> px</span></label>
	<label><span><?= e(t('Base font size on monitors')) ?></span><span class="appearance-unit"><input type="number" name="ds[base_max]" value="<?= e($px($ds['base_max'])) ?>" min="13" max="25" step="1"> px</span></label>
<?php foreach (['ratio_min' => 'Headings on phones', 'ratio_max' => 'Headings on monitors'] as $key => $labelText): ?>
	<label><span><?= e(t($labelText)) ?></span><select name="ds[<?= $key ?>]">
<?php foreach (DesignSystem::RATIOS as $value => $name): ?>
		<option value="<?= e($value) ?>"<?= abs((float) $value - $ds[$key]) < 0.001 ? ' selected' : '' ?>><?= e(t($name)) ?></option>
<?php endforeach ?>
	</select></label>
<?php endforeach ?>
	<label><span><?= e(t('Content width')) ?></span><span class="appearance-unit"><input type="number" name="ds[sirka]" value="<?= e($px($ds['width'])) ?>" min="640" max="1920" step="16"> px</span></label>
	<label><span><?= e(t('Text width (news and text pages)')) ?></span><span class="appearance-unit"><input type="number" name="ds[text_width]" value="<?= e($px($ds['text_width'])) ?>" min="448" max="960" step="16"> px</span></label>
</div>
</fieldset>

<fieldset>
<legend><?= e(t('Typography styles')) ?></legend>
<p class="help"><?= e(t('Named text styles you pick for an element in the builder (Style → Typography). A change here applies everywhere the style is used.')) ?></p>
<div class="tab-wrap"><table class="listing appearance-typography">
<thead><tr><th scope="col"><?= e(t('Style')) ?></th><th scope="col"><?= e(t('Size (scale step)')) ?></th><th scope="col"><?= e(t('Weight')) ?></th></tr></thead>
<tbody>
<?php foreach (DesignSystem::TYPOGRAPHY as $key => [$name, $step, $weight, $lineHeight, $forHeadings]): $custom = $ds['typography'][$key] ?? []; ?>
<tr>
	<th scope="row"><span style="font: var(--ka-type-<?= e($key) ?>, inherit)<?= $key === 'eyebrow' ? ';text-transform:uppercase;letter-spacing:.08em' : '' ?>"><?= e(t($name)) ?></span></th>
	<td><select name="ds[typography][<?= e($key) ?>][krok]" aria-label="<?= e(t('Size: %s', t($name))) ?>">
<?php foreach (DesignSystem::STEPS as $k): ?>
		<option value="<?= e($k) ?>"<?= ($custom['step'] ?? $step) === $k ? ' selected' : '' ?>><?= e($k === '0' ? t('0 – base font') : $k) ?></option>
<?php endforeach ?>
	</select></td>
	<td><select name="ds[typography][<?= e($key) ?>][weight]" aria-label="<?= e(t('Weight: %s', t($name))) ?>">
<?php foreach (DesignSystem::FONT_WEIGHTS as $w => $weightName): ?>
		<option value="<?= $w ?>"<?= (int) ($custom['weight'] ?? $weight) === $w ? ' selected' : '' ?>><?= e(t($weightName)) ?></option>
<?php endforeach ?>
	</select></td>
</tr>
<?php endforeach ?>
</tbody>
</table></div>
</fieldset>
</div>

<div role="tabpanel" id="panel-shapes" aria-labelledby="tab-shapes">
<fieldset>
<legend><?= e(t('Corner radius')) ?></legend>
<div class="appearance-radius">
<?php foreach (['0' => 'sharp', 's' => 'subtle', 'm' => 'medium', 'l' => 'large', 'full' => 'round'] as $key => $name): ?>
	<label><input type="radio" name="ds[zaobleni]" value="<?= e($key) ?>"<?= $ds['radius'] === $key ? ' checked' : '' ?>><i style="border-radius:<?= e($key === 'full' ? '999px' : DesignSystem::RADII[$key]) ?>"></i><?= e(t($name)) ?></label>
<?php endforeach ?>
</div>
<p class="help"><?= e(t('Buttons, cards, images and form fields across the site get this radius.')) ?></p>
</fieldset>
</div>

<div role="tabpanel" id="panel-brand" aria-labelledby="tab-brand">
<fieldset>
<legend><?= e(t('Logo and icon')) ?></legend>
<div class="row"><label for="logo"><?= e(t('Logo')) ?></label><div><input class="textfield wide" type="text" id="logo" name="logo" value="<?= e($values['logo']) ?>" maxlength="255" placeholder="<?= e(t('without a logo, the site name is shown in the header')) ?>" data-image><span class="help"><?= e(t('Preferably a PNG with a transparent background, at least 120 px high.')) ?></span></div></div>
<div class="row"><label for="favicon"><?= e(t('Site icon')) ?></label><div><input class="textfield wide" type="text" id="favicon" name="favicon" value="<?= e($values['favicon']) ?>" maxlength="255" data-image><span class="help"><?= e(t('A small square image shown on the browser tab and in bookmarks. 256×256 px is enough.')) ?></span></div></div>
</fieldset>

</div>

<p class="help"><?= e(t('Colours, fonts, sizes and shapes go to the draft look first – with changes of shared classes and menus. Visitors see them once you publish the look.')) ?></p>
<p class="buttons appearance-save"><input class="btn" type="submit" value="<?= e(t('Save appearance')) ?>"> <span class="help" data-unsaved hidden><?= e(t('The preview shows unsaved changes.')) ?></span></p>
</form>

<div role="tabpanel" id="panel-export" aria-labelledby="tab-export" class="appearance-export">
<fieldset>
<legend><?= e(t('Design tokens (Figma, Tokens Studio)')) ?></legend>
<p class="help"><?= e(t('Colours, fonts, sizes and typography styles in the W3C Design Tokens format (DTCG). A Kaleta export can be loaded back in full; from another tool the colours are taken.')) ?></p>
<p class="navigation-row"><a class="navigation" href="<?= e($module->url('tokens')) ?>"><?= e(t('Download tokens (.tokens.json)')) ?></a></p>
<form class="navigation-row" method="post" action="<?= e($module->url('tokens_import')) ?>" enctype="multipart/form-data" data-confirm="<?= e(t('Load tokens? They will overwrite the appearance settings above.')) ?>">
	<?= $csrf ?>
	<input type="file" name="tokens" accept=".json,application/json" required aria-label="<?= e(t('Tokens file')) ?>">
	<button class="navigation" type="submit"><?= e(t('Load tokens')) ?></button>
</form>
</fieldset>
<fieldset>
<legend><?= e(t('Earlier looks')) ?></legend>
<?php if ($versions === []): ?>
<p class="help"><?= e(t('Each time you publish the look, the one before is kept here (the last 20).')) ?></p>
<?php else: ?>
<ul class="appearance-version">
<?php foreach ($versions as $v): ?>
	<li><strong><?= e(format_date($v['created'], true)) ?></strong><?= $v['author'] !== null ? ' · ' . e((string) $v['author']) : '' ?><br><span class="help"><?= e(t('before: %s', $v['summary'])) ?></span>
		<form class="inline" method="post" action="<?= e($module->url('restore_look')) ?>"><?= $csrf ?><input type="hidden" name="id" value="<?= (int) $v['id'] ?>"><button class="navigation" type="submit"><?= e(t('Back to this look')) ?></button></form></li>
<?php endforeach ?>
</ul>
<?php endif ?>
</fieldset>
</div>

<aside class="appearance-preview">
	<div class="appearance-preview-bar">
		<span><?= e(t('Home page preview')) ?></span>
		<span class="appearance-device" role="group" aria-label="<?= e(t('Devices')) ?>">
			<button type="button" data-device="desktop" aria-pressed="true"><?= e(t('Desktop')) ?></button>
			<button type="button" data-device="mobile" aria-pressed="false"><?= e(t('Phone')) ?></button>
		</span>
	</div>
	<div class="appearance-frame" data-frame><iframe src="<?= e($app->url('') . '?preview=vzhled') ?>" title="<?= e(t('Home page preview')) ?>" data-preview></iframe></div>
</aside>
</div>
