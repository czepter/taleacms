<?php
/**
 * "Fill in the fields": one input per field of a form that a personal link can fill in (Core\FormLinks::PREFILLABLE), in the shape
 * the person sees it – a longer text, a choice, options to tick, a date. Sent as prefill[<field key>] (prefill[<key>][] for options).
 *
 * @var list<array<string, mixed>> $fields the form's fields
 * @var array<string, string|list<string>> $values what the link fills in now, by field key
 */
use Talea\Builder\Elements\Form;
use Talea\Core\FormLinks;

$shown = array_filter($fields, fn (array $f): bool => (string) ($f['key'] ?? '') !== '' && in_array($f['type'] ?? '', FormLinks::PREFILLABLE, true));
?>
<?php if ($shown === []): ?>
<p class="help"><?= e(t('This form has no field a link can fill in.')) ?></p>
<?php endif ?>
<?php foreach ($shown as $f): $key = (string) $f['key']; $id = 'prefill-' . $key; $name = 'prefill[' . $key . ']'; $value = $values[$key] ?? ''; $one = is_array($value) ? '' : (string) $value; ?>
<div class="row"><label for="<?= e($id) ?>"><?= bdi((string) $f['label']) ?></label><div>
<?= match ($f['type']) {
    'textarea' => '<textarea class="textbox" id="' . e($id) . '" name="' . e($name) . '" rows="6" maxlength="5000">' . e($one) . '</textarea>',
    'select', 'radio' => '<select class="textfield" id="' . e($id) . '" name="' . e($name) . '"><option value="">–</option>' . implode('', array_map(fn (string $o): string => '<option' . ($o === $one ? ' selected' : '') . '>' . e($o) . '</option>', array_map('strval', Form::options($f)))) . '</select>',
    'checkboxes' => '<span class="options" id="' . e($id) . '">' . implode('', array_map(fn (string $o): string => '<label><input type="checkbox" name="' . e($name) . '[]" value="' . e($o) . '"' . (in_array($o, (array) $value, true) ? ' checked' : '') . '> ' . e($o) . '</label>', array_map('strval', Form::options($f)))) . '</span>',
    'date' => '<input class="textfield" type="date" id="' . e($id) . '" name="' . e($name) . '" value="' . e($one) . '">',
    'email' => '<input class="textfield wide" type="email" id="' . e($id) . '" name="' . e($name) . '" value="' . e($one) . '" maxlength="190">',
    default => '<input class="textfield wide" type="text" id="' . e($id) . '" name="' . e($name) . '" value="' . e($one) . '" maxlength="300">',
} ?>
</div></div>
<?php endforeach ?>
