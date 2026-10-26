<?php
/**
 * The robots directives as checkboxes, shared by seo/fields.php (news, collection items) and pages/settings/advanced.php.
 * The empty robots[] marker goes before them, so unticking clears a directive; limits set elsewhere (max-snippet …) are
 * carried along as hidden fields.
 *
 * @var string $robots the stored comma list (Core\Robots::normalize)
 * @var string $labelClass the class of each checkbox's label, '' for none
 */
$set = array_filter(explode(',', $robots));
$flags = [
    'nofollow' => 'Do not follow links (nofollow)', 'noarchive' => 'No cached copy (noarchive)',
    'noimageindex' => 'Do not index the images (noimageindex)', 'nosnippet' => 'No text excerpt (nosnippet)',
];
?>
	<input type="hidden" name="robots[]" value="">
<?php foreach (array_diff($set, array_keys($flags)) as $limit): ?>
	<input type="hidden" name="robots[]" value="<?= e($limit) ?>">
<?php endforeach ?>
<?php foreach ($flags as $flag => $label): ?>
	<label<?= $labelClass !== '' ? ' class="' . e($labelClass) . '"' : '' ?>><input type="checkbox" name="robots[]" value="<?= $flag ?>"<?= in_array($flag, $set, true) ? ' checked' : '' ?>> <?= e(t($label)) ?></label>
<?php endforeach ?>
