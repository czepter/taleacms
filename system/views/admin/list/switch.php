<?php
/**
 * An on/off switch that posts at once (docs/specs/admin-lists.md): replaces "Turn on / Turn off" links in lists.
 *
 * @var string $action
 * @var array<string, string> $fields
 * @var bool $on
 * @var string $label   what the switch turns on ("Newsletter pop-up on")
 * @var string $csrf
 */
?>
<form class="inline" method="post" action="<?= e($action) ?>"><?= $csrf ?><?php foreach ($fields as $name => $value): ?><input type="hidden" name="<?= e($name) ?>" value="<?= e($value) ?>"><?php endforeach ?><button class="switch" type="submit" role="switch" aria-checked="<?= $on ? 'true' : 'false' ?>" aria-label="<?= e($label) ?>"></button></form>
