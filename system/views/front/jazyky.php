<?php
/**
 * Language version switcher. The layout gets it ready-made in the variable $jazyky_html (empty if the site has a single language).
 * Two to three languages = a switcher in one row, more = a button with a menu (Popover API, no JavaScript). Colours, corner
 * rounding and font come from the design system (tokens --ka-…), so it matches the appearance of the site.
 *
 * The label "Language" is deliberately in English (even a visitor who does not understand the page language understands it), hence lang="en".
 *
 * The Language switcher element (e.g. in the footer) chooses the style: "rada" (row), or "nabidka" (menu) with the full language
 * name in the button, and the direction in which the menu opens (upwards in the footer).
 *
 * @var array<string, array{nazev:string, url:string, aktivni:bool, preklad:bool}> $languages
 * @var string|null $style auto (a row up to three languages, a menu for more) | rada | nabidka
 * @var string|null $direction dolu | nahoru
 * @var string|null $attributes attributes of the element from the builder (id, classes)
 */
$active = array_key_first(array_filter($languages, fn (array $j): bool => $j['active'])) ?? array_key_first($languages);
$style ??= 'auto';
$attributes ??= '';
?>
<?php if ($style === 'row' || ($style === 'auto' && count($languages) <= 3)): ?>
<nav<?= Kaleta\Builder\Elements\Text::withClass($attributes, 'ka-languages') ?> lang="en" aria-label="Language">
<?php foreach ($languages as $code => $j): ?>
	<a href="<?= e($j['url']) ?>" hreflang="<?= e($code) ?>" lang="<?= e($code) ?>" title="<?= e($j['name']) ?>"<?= $j['active'] ? ' aria-current="true"' : '' ?>><?= e(strtoupper($code)) ?></a>
<?php endforeach ?>
</nav>
<?php else: $id = 'ka-languages-' . bin2hex(random_bytes(3)); ?>
<nav<?= Kaleta\Builder\Elements\Text::withClass($attributes, 'ka-languages-select' . (($direction ?? '') === 'nahoru' ? ' ka-languages-select--up' : '')) ?> lang="en" aria-label="Language">
	<button type="button" class="ka-languages-btn" popovertarget="<?= $id ?>" style="anchor-name: --<?= $id ?>" aria-label="Language: <?= e($languages[$active]['name']) ?>">
		<svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"/></svg>
		<span><?= e($style === 'dropdown' ? $languages[$active]['name'] : strtoupper((string) $active)) ?></span><?php if ($style === 'dropdown'): ?>
		<svg class="ka-languages-arrow" viewBox="0 0 24 24" width="12" height="12" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg><?php endif ?>
	</button>
	<ul id="<?= $id ?>" popover style="position-anchor: --<?= $id ?>">
<?php foreach ($languages as $code => $j): ?>
		<li><a href="<?= e($j['url']) ?>" hreflang="<?= e($code) ?>" lang="<?= e($code) ?>"<?= $j['active'] ? ' aria-current="true"' : '' ?>><span><?= e($j['name']) ?></span><small><?= e(strtoupper($code)) ?></small></a></li>
<?php endforeach ?>
	</ul>
</nav>
<?php endif ?>
