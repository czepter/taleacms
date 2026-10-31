<?php
/**
 * The <option>s of a folder select: the whole tree, names indented by level (docs/specs/media-library.md).
 *
 * @var list<array<string, mixed>> $tree      Core\MediaFolders::tree()
 * @var string $topLabel                       the option with the empty value ("Not in a folder", "Top level")
 * @var int|null $selected                     folder_id that is selected (null = the top option)
 * @var list<int> $disabled                    folder_ids that cannot be chosen (a folder and its subfolders when it is moved)
 */
$selected ??= null;
$disabled ??= [];
?>
<option value=""<?= $selected === null ? ' selected' : '' ?>><?= e($topLabel) ?></option>
<?php foreach ($tree as $f): ?>
<option value="<?= e($f['public_id']) ?>"<?= $selected === $f['folder_id'] ? ' selected' : '' ?><?= in_array($f['folder_id'], $disabled, true) ? ' disabled' : '' ?>><?= str_repeat("\u{00A0}\u{00A0}", (int) $f['depth']) ?><?= e($f['name']) ?></option>
<?php endforeach ?>
