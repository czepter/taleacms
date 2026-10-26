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
    public const string GROUP = 'Search engines';
    public const string ICON = 'audit';
    public const bool ADMIN_ONLY = true;

    protected function actionList(): Response
    {
        $audit = (new \Talea\Core\Audit($this->app))->scored(true);
        $category = $this->request->get('category');
        $category = in_array($category, \Talea\Core\Audit::CATEGORY, true) ? $category : '';
        $groups = [];
        $counts = [];
        foreach ($audit['findings'] as $f) {
            $counts[\Talea\Core\Audit::CATEGORY[$f['kind']]] = ($counts[\Talea\Core\Audit::CATEGORY[$f['kind']]] ?? 0) + 1;
            if ($category === '' || \Talea\Core\Audit::CATEGORY[$f['kind']] === $category) {
                $groups[$f['kind']][] = $f;
            }
        }

        return $this->view('list', 'Site audit', ['groups' => $groups, 'total' => count($audit['findings']), 'audit' => $audit, 'category' => $category, 'counts' => $counts]);
    }
}
