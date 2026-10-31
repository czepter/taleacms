<ul class="features">
<?php foreach (Talea\Core\Extensions::CATALOG as $key => [$extensionName, $extensionDescription]): ?>
	<li><label><input type="checkbox" name="extensions[]" value="<?= e($key) ?>"<?= in_array($key, $extensions, true) ? ' checked' : '' ?>><strong><?= e(t($extensionName)) ?></strong><small><?= e(t($extensionDescription)) ?></small></label></li>
<?php endforeach ?>
</ul>
