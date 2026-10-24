<?php /** @var string $text @var \Talea\Core\App $app */ ?>
<p class="notice notice-error"><?= e(t($text)) ?></p>
<p class="navigation-row"><a class="navigation" href="<?= e($app->url('admin.php')) ?>"><?= e(t('Back to the dashboard')) ?></a></p>
