<?php

declare(strict_types=1);

namespace Talea\Admin\Modules;

use Talea\Admin\Module;
use Talea\Core\Response;

/** Site audit (1.9): broken links, missing descriptions, duplicate titles, the menu, builder checks and frequent 404s (Core\Audit). */
final class Audit extends Module
{
    public const string IDENT = 'audit';
    public const string NAME = 'Site audit';
    public const string GROUP = 'Site care';
    public const string ICON = 'audit';
    public const bool ADMIN_ONLY = true;

    protected function actionList(): Response
    {
        $findings = (new \Talea\Core\Audit($this->app))->run();
        $groups = [];
        foreach ($findings as $f) {
            $groups[$f['kind']][] = $f;
        }

        return $this->view('list', 'Site audit', ['groups' => $groups, 'total' => count($findings)]);
    }
}
