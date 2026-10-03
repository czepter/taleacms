<?php

declare(strict_types=1);

namespace Kaleta\Admin\Modules;

use Kaleta\Core\Response;

/**
 * Extensions as a separate item of the main menu (formerly a Settings tab).
 *
 * The screen and saving are the same as for the Settings tabs - the module only keeps a fixed "tab" extensions,
 * renders the templates from the config/ folder and returns to its own URL (admin.php?module=extensions).
 */
final class Extensions extends Settings
{
    public const string IDENT = 'extensions';
    public const string NAME = 'Extensions';
    public const string ICON = 'prepinace';

    protected function tab(string $tab): string
    {
        return 'extensions';
    }

    protected function view(string $template, string $heading, array $data = []): Response
    {
        $data += ['app' => $this->app, 'module' => $this, 'csrf' => $this->app->session->csrfField()];

        return $this->kernel->page(self::NAME, $this->app->view->render('admin/settings/' . $template, $data));
    }
}
