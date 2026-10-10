<?php

declare(strict_types=1);

namespace Talea\Admin\Modules;

use Talea\Admin\Module;
use Talea\Core\Response;
use Talea\Extension\Registry;

/**
 * Add-ons (3.0): code from other developers copied into extensions/<slug>/ – switched on and off here, with what each
 * needs and the error that switched it off, and the administration pages add-ons register (Extension\Api::adminPage).
 * Built-in parts of Talea stay in Extensions; nothing is uploaded or downloaded from here.
 */
final class Addons extends Module
{
    public const string IDENT = 'addons';
    public const string HUB = 'features';
    public const string PARENT = 'extensions';
    public const string NAME = 'Add-ons';
    public const string GROUP = 'Administration';
    public const string ICON = 'extensions';
    public const bool ADMIN_ONLY = true;

    protected function actionList(): Response
    {
        $s = $this->app->settings();

        return $this->view('list', 'Add-ons', ['addons' => Registry::discover(), 'enabled' => Registry::enabled($s),
            'errors' => array_map(fn (array $m): string => $s->get('addons_error.' . $m['slug']), Registry::discover()),
            'pages' => Registry::get()->adminPages(), 'safeMode' => ($this->app->config['addons'] ?? true) === false, 'demo' => \Talea\Core\Demo::active()]);
    }

    protected function actionToggle(): Response
    {
        $slug = $this->request->post('slug');
        if (!$this->request->isPost() || \Talea\Core\Demo::active()) {
            return $this->back();
        }
        if ($this->request->post('on') === '1') {
            if (!$this->request->postBool('trust')) {
                return $this->back('Tick that you trust the code of this add-on.', '', [], 'error');
            }
            try {
                Registry::enable($this->app, $slug);
            } catch (\InvalidArgumentException | \DomainException | \PDOException $e) {
                return $this->back($e->getMessage(), '', [], 'error');
            }

            return $this->back('The add-on is switched on.');
        }
        Registry::disable($this->app, $slug);

        return $this->back('The add-on is switched off – its data stays.');
    }

    /** A page an add-on registered: admin.php?module=addons&action=page&p=<slug>.<name> */
    protected function actionPage(): Response
    {
        $page = Registry::get()->adminPages()[$this->request->get('p')] ?? null;
        if ($page === null) {
            return $this->error('The add-on page does not exist or its add-on is switched off.', 404);
        }
        try {
            $html = ($page['render'])($this->request);
        } catch (\Throwable $e) {
            return $this->error(t('The add-on failed: %s', $e->getMessage()), 500);
        }

        return $this->view('page', $page['title'], ['html' => is_string($html) ? $html : '', 'addon' => $page['slug']]);
    }
}
