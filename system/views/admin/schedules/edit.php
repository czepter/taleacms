<?php
/**
 * One schedule (2.17): what the run does, the administrator's text, how often and when.
 *
 * @var Kaleta\Core\App $app
 * @var Kaleta\Admin\Modules\Schedules $module
 * @var string $csrf
 * @var array<string, mixed>|null $s the schedule, null for a new one
 */
use Kaleta\Core\AgentSchedules;

$isNew = $s === null;
$task = (string) ($s['task'] ?? 'review');
$cadence = (string) ($s['cadence'] ?? 'weekly');
$day = (int) ($s['day'] ?? 1);
?>
<p><a href="<?= e($module->url('')) ?>">← <?= e(t('All schedules')) ?></a></p>
<form class="formular" method="post" action="<?= e($module->url('save')) ?>">
<?= $csrf ?><input type="hidden" name="id" value="<?= (int) ($s['id'] ?? 0) ?>">
<fieldset>
<legend><?= e($isNew ? t('New schedule') : t('Schedule')) ?></legend>
<div class="radek"><label for="name"><?= e(t('Name')) ?></label><div><input class="textpole siroke" id="name" name="name" required maxlength="150" value="<?= e((string) ($s['name'] ?? '')) ?>" placeholder="<?= e(t('e.g. Monday site review')) ?>"></div></div>
<div class="radek"><label for="task"><?= e(t('What the run does')) ?></label><div><select id="task" name="task">
<?php foreach (AgentSchedules::TASKS as $key => [$label]): ?><option value="<?= e($key) ?>"<?= $task === $key ? ' selected' : '' ?>><?= e(t($label)) ?></option><?php endforeach ?>
</select>
<span class="napoveda"><?= e(t('The built-in tasks are instructions for Claude; every run ends with the rules: drafts only, never publish, never delete, stop and report anything that needs a person.')) ?></span>
<dl class="smltxt">
<?php foreach (AgentSchedules::TASKS as $key => [$label, $text]): if ($text === '') { continue; } ?>
	<dt><?= e(t($label)) ?></dt><dd><?= e(t($text)) ?></dd>
<?php endforeach ?>
</dl></div></div>
<div class="radek"><label for="text"><?= e(t('Instructions')) ?></label><div><textarea class="textbox" id="text" name="text" rows="6" maxlength="<?= AgentSchedules::MAX_TEXT ?>"><?= e((string) ($s['text'] ?? '')) ?></textarea>
<span class="napoveda"><?= e(t('For custom instructions this is the whole task; for a built-in task it is added to it (e.g. “only the pages in the Services section”). Claude reads it as the administrator’s instructions – it still works as drafts only.')) ?></span></div></div>
</fieldset>
<fieldset>
<legend><?= e(t('How often')) ?></legend>
<div class="radek"><label for="cadence"><?= e(t('Cadence')) ?></label><div><select id="cadence" name="cadence">
<?php foreach (AgentSchedules::CADENCES as $key => $label): ?><option value="<?= e($key) ?>"<?= $cadence === $key ? ' selected' : '' ?>><?= e(t($label)) ?></option><?php endforeach ?>
</select></div></div>
<div class="radek"><label for="weekday"><?= e(t('Day of the week')) ?></label><div><select id="weekday" name="weekday">
<?php foreach (AgentSchedules::WEEKDAYS as $n => $label): ?><option value="<?= $n ?>"<?= $day === $n ? ' selected' : '' ?>><?= e(t($label)) ?></option><?php endforeach ?>
</select><span class="napoveda"><?= e(t('For a weekly schedule.')) ?></span></div></div>
<div class="radek"><label for="monthday"><?= e(t('Day of the month')) ?></label><div><input class="textpole kratke" type="number" id="monthday" name="monthday" min="1" max="28" value="<?= $cadence === 'monthly' ? $day : 1 ?>">
<span class="napoveda"><?= e(t('For a monthly schedule, 1–28 – so that every month has it.')) ?></span></div></div>
<div class="radek"><label for="time"><?= e(t('Time')) ?></label><div><input class="textpole kratke" type="time" id="time" name="time" required value="<?= e((string) ($s['time'] ?? '07:00')) ?>">
<span class="napoveda"><?= e(t('In the site’s time zone (%s). Set the routine in Claude to run a little later – it picks the run up when it is due.', $app->settings()->get('time_zone'))) ?></span></div></div>
<div class="radek"><label for="active"><?= e(t('Active')) ?></label><div><label class="prepinac"><input type="checkbox" id="active" name="active" value="1"<?= $isNew || (int) $s['active'] === 1 ? ' checked' : '' ?>> <?= e(t('runs are handed out when due')) ?></label></div></div>
</fieldset>
<p class="tlacitka"><input class="tl" type="submit" value="<?= e(t('Save')) ?>">
<?php if (!$isNew): ?> <a class="navigace" href="<?= e($module->url('history', ['id' => (int) $s['id']])) ?>"><?= e(t('History')) ?></a>
 <button class="navigace nebezpecne" type="submit" formaction="<?= e($module->url('delete')) ?>" data-potvrdit="<?= e(t('Delete the schedule and the history of its runs?')) ?>"><?= e(t('Delete')) ?></button><?php endif ?></p>
</form>
