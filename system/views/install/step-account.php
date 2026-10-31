<?php if (!$envDb): ?>
<fieldset class="database">
<legend class="caption"><?= e(t('Database')) ?></legend>
<p class="help"><?= e(t('MySQL, MariaDB or PostgreSQL. Create an empty database beforehand – in your hosting control panel.')) ?> <?= e(t('SQLite needs no server: the database is a file in storage/database/ – leave the server, user and password empty.')) ?></p>
<div class="row"><label for="db_driver"><?= e(t('Database type')) ?></label><div><select id="db_driver" name="db_driver" class="wide">
	<option value="mysql"<?= $data['db_driver'] === 'mysql' ? ' selected' : '' ?>>MySQL / MariaDB</option>
	<option value="pgsql"<?= $data['db_driver'] === 'pgsql' ? ' selected' : '' ?>>PostgreSQL</option>
	<option value="sqlite"<?= $data['db_driver'] === 'sqlite' ? ' selected' : '' ?>><?= e(t('SQLite (a file, no server)')) ?></option>
</select></div></div>
<div class="row server"><label for="db_host"><?= e(t('Server')) ?></label><div class="pair"><div><input type="text" id="db_host" name="db_host" class="textfield wide" value="<?= e($data['db_host']) ?>"<?= $invalid('db_host') ?>><?= $error('db_host') ?></div><input type="number" id="db_port" name="db_port" class="textfield" value="<?= e($data['db_port']) ?>" placeholder="3306 / 5432" aria-label="<?= e(t('Port')) ?>"></div></div>
<div class="row"><label for="db_name"><?= e(t('Database name')) ?></label><div><input type="text" id="db_name" name="db_name" class="textfield wide" value="<?= e($data['db_name']) ?>"<?= $invalid('db_name') ?>><?= $error('db_name') ?></div></div>
<div class="row"><label for="db_prefix"><?= e(t('Table prefix')) ?></label><div><input type="text" id="db_prefix" name="db_prefix" class="textfield wide" value="<?= e($data['db_prefix']) ?>" required<?= $invalid('db_prefix') ?>><?= $error('db_prefix') ?></div></div>
<div class="row server"><label for="db_user"><?= e(t('User')) ?></label><div><input type="text" id="db_user" name="db_user" class="textfield wide" value="<?= e($data['db_user']) ?>"<?= $invalid('db_user') ?>><?= $error('db_user') ?></div></div>
<div class="row server"><label for="db_password"><?= e(t('Password')) ?></label><div><input type="password" id="db_password" name="db_password" class="textfield wide" autocomplete="off"></div></div>
</fieldset>
<?php endif ?>
<div class="row"><label for="user"><?= e(t('User name')) ?></label><div><input type="text" id="user" name="username" class="textfield wide" value="<?= e($data['username']) ?>" autocomplete="username" required<?= $invalid('username') ?>><?= $error('username') ?><span class="help"><?= e(t('You can also sign in with your e-mail address.')) ?></span></div></div>
<div class="row"><label for="name"><?= e(t('First and last name')) ?></label><div><input type="text" id="name" name="name" class="textfield wide" value="<?= e($data['name']) ?>" autocomplete="name"><span class="help"><?= e(t('Shown with news items.')) ?></span></div></div>
<div class="row"><label for="email"><?= e(t('Email')) ?></label><div><input type="email" id="email" name="email" class="textfield wide" value="<?= e($data['email']) ?>" autocomplete="email"<?= $invalid('email') ?>><?= $error('email') ?><span class="help"><?= e(t('For password resets and notices.')) ?></span></div></div>
<div class="row"><label for="password"><?= e(t('Password')) ?></label><div><input type="password" id="password" name="password" class="textfield wide" autocomplete="new-password" minlength="10" required<?= $invalid('password') ?>><?= $error('password') ?><span class="help"><?= e(t('At least 10 characters.')) ?></span></div></div>
<div class="row"><label for="password2"><?= e(t('Repeat password')) ?></label><div><input type="password" id="password2" name="password2" class="textfield wide" autocomplete="new-password" required></div></div>
