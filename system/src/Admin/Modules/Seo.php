<?php

declare(strict_types=1);

namespace Talea\Admin\Modules;

use Talea\Admin\Module;
use Talea\Core\Extensions;
use Talea\Core\NotFound;
use Talea\Core\Response;

/**
 * Overview of the "Search engines" section: a few numbers, what needs attention and one switch per feature. A switch
 * writes the extension or the setting the feature already has; Claude does the same through update_settings.
 */
final class Seo extends Module
{
    public const string IDENT = 'seo';
    public const string NAME = 'Overview';
    public const string GROUP = 'Search engines';
    public const string ICON = 'search';
    public const bool ADMIN_ONLY = true;

    /**
     * feature => [name, description, kind (extension | setting), key, screen (module ident or settings tab), link text, icon paths]
     *
     * @var array<string, array{0: string, 1: string, 2: string, 3: string, 4: string, 5: string, 6: string}>
     */
    public const array FEATURES = [
        'page_check' => ['Page check', 'A focus keyword for each page and news item, a score from 0 to 100 and a list of what to fix, right where you edit.', 'extension', 'page_check', 'pages', 'Pages',
            '<circle cx="12" cy="12" r="9"/><path d="m8 12 3 3 5-6"/>'],
        'schema_org' => ['Structured data', 'Tells search engines what a page is: an article, a product, an event, a recipe, a business.', 'setting', 'schema_org', 'settings&tab=seo', 'Settings',
            '<path d="M8 4H6a2 2 0 0 0-2 2v4l-2 2 2 2v4a2 2 0 0 0 2 2h2"/><path d="M16 4h2a2 2 0 0 1 2 2v4l2 2-2 2v4a2 2 0 0 1-2 2h-2"/>'],
        'redirects' => ['Redirects', 'Send an old address to a new one. A changed page address adds one by itself.', 'extension', 'redirects', 'redirects', 'Redirects',
            '<path d="M4 7h11l-3-3M20 17H9l3 3"/>'],
        'indexnow' => ['Instant indexing', 'Tells search engines about a new or changed page the moment it is published, instead of waiting for the next visit.', 'setting', 'indexnow', 'settings&tab=seo', 'Settings',
            '<path d="M13 3 5 14h6l-1 7 8-11h-6z"/>'],
        'llms_txt' => ['Overview for AI assistants', 'A short file at /llms.txt that lists your pages for AI assistants that read websites.', 'setting', 'llms_txt', 'settings&tab=seo', 'Settings',
            '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5M9 13h6M9 17h4"/>'],
    ];

    public static function featureOn(\Talea\Core\Settings $settings, string $feature): bool
    {
        [, , $kind, $key] = self::FEATURES[$feature];

        return $kind === 'extension' ? Extensions::isEnabled($settings, $key) : $settings->bool($key);
    }

    protected function actionList(): Response
    {
        $settings = $this->app->settings();
        $pages = (int) $this->db->value('SELECT COUNT(*) FROM {pages} WHERE deleted_at IS NULL AND visible = TRUE');
        $keywords = (int) $this->db->value("SELECT COUNT(*) FROM {pages} WHERE deleted_at IS NULL AND visible = TRUE AND focus_keywords <> ''");
        $noDescription = 0;
        foreach ((new \Talea\Core\Audit($this->app))->run() as $finding) {
            $noDescription += $finding['kind'] === 'description' ? 1 : 0;
        }
        $state = [];
        foreach (array_keys(self::FEATURES) as $feature) {
            $state[$feature] = self::featureOn($settings, $feature);
        }

        return $this->view('overview', 'Search engines', [
            'score' => $settings->get('seo_audit_score'), 'pages' => $pages, 'keywords' => $keywords,
            'redirects' => (int) $this->db->value('SELECT COUNT(*) FROM {redirects}'), 'missing' => count(NotFound::pending($this->app, 7)),
            'noDescription' => $noDescription, 'state' => $state, 'modules' => $this->kernel->modules(),
        ]);
    }

    protected function actionFeature(): Response
    {
        $feature = $this->request->post('feature');
        if (!$this->request->isPost() || !isset(self::FEATURES[$feature])) {
            return $this->back();
        }
        $on = $this->request->post('on') === '1';
        $settings = $this->app->settings();
        [$name, , $kind, $key] = self::FEATURES[$feature];
        if ($kind === 'extension') {
            $enabled = array_values(array_diff(Extensions::enabled($settings), [$key]));
            Extensions::save($settings, $on ? [...$enabled, $key] : $enabled);
        } else {
            $settings->set($key, $on ? '1' : '0');
        }

        return $this->back($on ? t('%s is on.', t($name)) : t('%s is off.', t($name)));
    }
}
