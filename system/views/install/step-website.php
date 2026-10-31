<div class="row"><label for="site_name"><?= e(t('Site name')) ?></label><div><input type="text" id="site_name" name="site_name" class="textfield wide" value="<?= e($data['site_name']) ?>" maxlength="150" required<?= $invalid('site_name') ?>><?= $error('site_name') ?><span class="help"><?= e(t('Shown in the browser tab, in search results and in e-mails.')) ?></span></div></div>
<div class="row"><label for="site_language"><?= e(t('Site language')) ?></label><div><select id="site_language" name="site_language" class="wide">
<?php foreach (Talea\Core\Language::AVAILABLE as $code => [$languageName]): ?>
	<option value="<?= e($code) ?>"<?= $data['site_language'] === $code ? ' selected' : '' ?>><?= e($languageName) ?></option>
<?php endforeach ?>
</select><span class="help"><?= e(t('The sample pages and the texts for visitors are created in this language. The administration stays in the language of the installation.')) ?></span></div></div>
<div class="row"><label for="time_zone"><?= e(t('Time zone')) ?></label><div><select id="time_zone" name="time_zone" class="wide">
<?php foreach (DateTimeZone::listIdentifiers() as $timeZone): ?>
	<option value="<?= e($timeZone) ?>"<?= $data['time_zone'] === $timeZone ? ' selected' : '' ?>><?= e(str_replace('_', ' ', $timeZone)) ?></option>
<?php endforeach ?>
</select><span class="help"><?= e(t('Scheduled news posts are published and dates are shown according to it.')) ?></span></div></div>
