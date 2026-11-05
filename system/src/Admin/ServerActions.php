<?php

declare(strict_types=1);

namespace Talea\Admin;

use Talea\Core\Response;

/**
 * Settings actions that touch the server rather than the site's own options (System status and Backups): the signing key,
 * the permissions of config.php, the error log. Used by Admin\Modules\Settings (and so by System status).
 */
trait ServerActions
{
    /** A new signing key: the links and signatures made with the old one stop working (the form lists them). */
    protected function actionNewKey(): Response
    {
        if (!$this->request->isPost() || !$this->app->auth()->isAdmin()) {
            return $this->back('', '', ['tab' => 'backups']);
        }
        if (!(new \Talea\Core\Antispam($this->db, $this->app->settings()))->renew()) {
            return $this->back('The signing key comes from TALEA_SECRET_KEY – change the variable on the server instead.', '', ['tab' => 'backups'], 'error');
        }
        \Talea\Front\Cache::clearAll();
        \Talea\Admin\ChangeLog::write($this->app, 'settings', 'new_key', 'signing key renewed');
        \Talea\Core\Events::record($this->db, 'security.key_renewed', 'warning', t('The signing key was renewed.'));

        return $this->back('The signing key is renewed.', '', ['tab' => 'backups']);
    }

    /** One click: config.php readable by its owner only. */
    protected function actionProtectConfig(): Response
    {
        $file = TALEA_ROOT . '/config.php';
        if (!$this->request->isPost() || !\Talea\Core\Owners::isFirst($this->db) || !is_file($file)) {
            return $this->back('', '', ['tab' => 'health']);
        }
        if (!\Talea\Core\Config::protectFile($file)) {
            return $this->back('The permissions of config.php could not be changed – change them on the server (chmod 600 config.php).', '', ['tab' => 'health'], 'error');
        }
        \Talea\Admin\ChangeLog::write($this->app, 'settings', 'protect_config', 'config.php');

        return $this->back('config.php can now be read only by the site\'s own user.', '', ['tab' => 'health']);
    }

    protected function actionDeleteLog(): Response
    {
        if ($this->request->isPost() && \Talea\Core\Owners::isFirst($this->db) && is_file(TALEA_ROOT . '/storage/log/errors.log')) {
            file_put_contents(TALEA_ROOT . '/storage/log/errors.log', '');
        }

        return $this->back('The error log is empty.', '', ['tab' => 'health']);
    }
}
