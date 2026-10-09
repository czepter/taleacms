<?php

declare(strict_types=1);

namespace Kaleta\Admin\Modules;

use Kaleta\Admin\Module;
use Kaleta\Admin\BuilderActions;
use Kaleta\Core\Language;
use Kaleta\Core\Response;
use Kaleta\Builder\Popups as Okna;
use Kaleta\Builder\Publisher;
use Kaleta\Builder\Build;

/**
 * Popups: the content is built in the builder as a site part, the settings define the type, trigger, rules and frequency.
 * The popup appears on the site once it is published and enabled. The counters of views, closes and conversions are without cookies.
 */
final class Popups extends Module
{
    use BuilderActions;

    public const string IDENT = 'popups';
    public const string NAME = 'Pop-ups';
    public const string GROUP = 'Appearance';
    public const string ICON = 'popups';
    public const bool ADMIN_ONLY = true;

    protected function actionList(): Response
    {
        return $this->view('list', 'Pop-ups', ['popups' => Okna::all($this->db)]);
    }

    protected function actionNew(): Response
    {
        return $this->view('new', 'New pop-up', []);
    }

    /** A new popup from a ready-made pattern: build draft in the site language, type and trigger from the pattern, disabled – straight into the builder. */
    protected function actionCreate(): Response
    {
        $r = $this->request;
        $pattern = Okna::LIBRARY[$r->post('template')] ?? null;
        if (!$r->isPost() || $pattern === null) {
            return $this->back('', 'new');
        }
        $name = mb_substr(trim($r->post('name')), 0, 100) ?: t($pattern[0]);
        $id = $this->db->insert('popups', [
            'name' => $name, 'slug' => Okna::address($this->db, $name), 'type' => $pattern[2], 'trigger_type' => $pattern[3], 'value' => $pattern[4],
            'rules' => (string) json_encode(Okna::defaultRules()), 'frequency' => 'session', 'days' => 7, 'active' => 0,
            'build_draft' => Build::toJson(Okna::libraryBuild((string) $r->post('template'), Language::defaults($this->app->settings()))), 'updated_at' => date('Y-m-d H:i:s'),
        ]);

        return Response::redirect($this->url('builder', ['id' => $id]));
    }

    protected function actionEdit(): Response
    {
        $p = Okna::byId($this->db, $this->request->getInt('id'));

        return $p === null ? $this->error('The pop-up does not exist.', 404) : $this->view('form', $p['name'], ['p' => $p] + $this->options());
    }

    protected function actionSave(): Response
    {
        $r = $this->request;
        $p = $r->isPost() ? Okna::byId($this->db, $r->postInt('popup_id')) : null;
        if ($p === null) {
            return $this->back();
        }
        $name = mb_substr(trim($r->post('name')), 0, 100);
        if ($name === '') {
            return $this->back('The pop-up needs a name.', 'edit', ['id' => $p['popup_id']], 'error');
        }
        $url = $r->post('slug') !== '' ? slugify($r->post('slug'), 60) : $p['slug'];
        if (!preg_match(Okna::ADDRESS_PATTERN, $url) || $this->db->value('SELECT popup_id FROM {popups} WHERE slug = ? AND popup_id <> ?', [$url, $p['popup_id']]) !== null) {
            return $this->back(t('Another window already uses the address “%s”.', $url), 'edit', ['id' => $p['popup_id']], 'error');
        }
        // "on the whole site": the choice of places is inactive in the form and is not sent – it stays saved in case you return to it
        $selected = $r->post('where') === 'selected';
        $rules = Okna::sanitizeRules([
            'where' => $r->post('where'),
            'pages' => $selected ? (is_array($_POST['pages'] ?? null) ? $_POST['pages'] : []) : $p['rules']['pages'],
            'collections' => $selected ? (is_array($_POST['collections'] ?? null) ? $_POST['collections'] : []) : $p['rules']['collections'],
            'news' => $selected ? $r->postBool('news') : $p['rules']['news'], 'language' => $r->post('language'), 'from' => $r->post('from'), 'to' => $r->post('to'),
            'device' => $r->post('device'), 'campaign' => $r->post('campaign'), 'referrer' => $r->post('referrer'),
        ]);
        $this->db->update('popups', [
            'name' => $name, 'slug' => $url,
            'type' => isset(Okna::TYPES[$r->post('type')]) ? $r->post('type') : $p['type'],
            'trigger_type' => isset(Okna::TRIGGERS[$r->post('trigger_type')]) ? $r->post('trigger_type') : $p['trigger_type'],
            'value' => max(0, min(3600, $r->postInt('value'))),
            'frequency' => isset(Okna::FREQUENCIES[$r->post('frequency')]) ? $r->post('frequency') : $p['frequency'],
            'days' => $r->post('days') !== '' ? max(1, min(365, $r->postInt('days', 7))) : (int) $p['days'], // the field is active only for the frequency "days"
            'sort_order' => max(-9999, min(9999, $r->postInt('sort_order', 100))),
            'rules' => (string) json_encode($rules, JSON_UNESCAPED_UNICODE), 'updated_at' => date('Y-m-d H:i:s'),
            // true until and review by (2.10, Core\Validity): empty or not a date = none
            'valid_until' => \Kaleta\Core\Validity::date($r->post('valid_until')), 'review_by' => \Kaleta\Core\Validity::date($r->post('review_by')),
        ], ['popup_id' => $p['popup_id']]);
        \Kaleta\Front\Cache::clear();

        return $this->back('The pop-up settings were saved.');
    }

    /** Enable or disable the popup on the site; only a published one can be enabled. */
    protected function actionToggle(): Response
    {
        $p = $this->request->isPost() ? Okna::byId($this->db, $this->request->postInt('popup_id')) : null;
        if ($p === null) {
            return $this->back();
        }
        // from the popup settings you stay in the settings, from the list in the list
        [$action, $args] = $this->request->post('back_to') === 'edit' ? ['edit', ['id' => $p['popup_id']]] : ['', []];
        if (!$p['active'] && $p['build'] === null) {
            return $this->back('Publish the pop-up in the builder first – then you can turn it on.', $action, $args, 'error');
        }
        $this->db->update('popups', ['active' => $p['active'] ? 0 : 1], ['popup_id' => $p['popup_id']]);
        \Kaleta\Front\Cache::clear();

        return $this->back($p['active'] ? 'The pop-up is off – it no longer shows on the site.' : 'The pop-up is on and shows on the site according to its rules.', $action, $args);
    }

    protected function actionReset(): Response
    {
        if ($this->request->isPost()) {
            $this->db->update('popups', ['impressions' => 0, 'closes' => 0, 'conversions' => 0], ['popup_id' => $this->request->postInt('popup_id')]);
        }

        return $this->back('The pop-up counters were reset.');
    }

    protected function actionDelete(): Response
    {
        if ($this->request->isPost()) {
            $this->db->delete('popups', ['popup_id' => $this->request->postInt('popup_id')]);
            \Kaleta\Front\Cache::clear();
        }

        return $this->back('The pop-up was deleted.');
    }

    /** Pages and collections for the choice "where the popup appears", site languages. @return array<string, mixed> */
    private function options(): array
    {
        $siteSettings = $this->app->settings();
        $languages = array_merge([Language::defaults($siteSettings)], Language::additional($siteSettings));

        return [
            'pages' => $this->db->all('SELECT page_id, title, language FROM {pages} WHERE deleted_at IS NULL ORDER BY language, sort_order, title LIMIT 500'),
            'collection' => $this->db->all('SELECT slug, name FROM {collections} WHERE detail = 1 ORDER BY name'),
            'languages' => count($languages) > 1 ? array_combine($languages, array_map(fn (string $j): string => Language::AVAILABLE[$j][0] ?? $j, $languages)) : [],
        ];
    }

    /* ---------- builder ---------- */

    protected function loadBuildTarget(): ?array
    {
        $p = Okna::byId($this->db, $this->request->getInt('id'));

        return $p === null ? null : [
            'row' => $p, 'build' => $p['build'], 'draft' => $p['build_draft'], 'language' => Language::defaults($this->app->settings()),
            'title' => t('Pop-up: %s', $p['name']), 'revisions' => ['part' => 'popup:' . $p['popup_id']], 'params' => ['id' => $p['popup_id']],
        ];
    }

    protected function saveDraft(array $target, ?string $draft): void
    {
        $this->db->update('popups', ['build_draft' => $draft], ['popup_id' => $target['row']['popup_id']]);
    }

    protected function publishTarget(array $target): void
    {
        Publisher::popup($this->app, $target['row']);
    }

    protected function describeTarget(array $target): array
    {
        $p = $target['row'];
        $url = $this->app->url('_popup/' . $p['popup_id']);

        return [
            'url' => $url . '?build=koncept', 'preview' => $url . '?build=koncept&editor=1', 'visible' => (bool) $p['active'], 'parts' => false,
            'back' => ['url' => $this->url(), 'text' => t('Pop-ups')], 'settings' => $this->url('edit', ['id' => $p['popup_id']]),
            'settings_text' => t('Pop-up settings (when and where it shows)'), 'signature' => 'popup:' . $p['popup_id'],
        ];
    }
}
