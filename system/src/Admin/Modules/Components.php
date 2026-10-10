<?php

declare(strict_types=1);

namespace Kaleta\Admin\Modules;

use Kaleta\Admin\Module;
use Kaleta\Admin\BuilderActions;
use Kaleta\Core\Language;
use Kaleta\Core\Response;
use Kaleta\Builder\Components as KomponentyStavby;
use Kaleta\Builder\Publisher;
use Kaleta\Builder\Build;

/**
 * Components – reusable blocks (service card, contact block, call to action…). They are created in the builder with the
 * button "Save as component" or here; they are edited in the builder and a change shows
 * everywhere they are used.
 */
final class Components extends Module
{
    use BuilderActions {
        actionBuilder as protected openBuilder;
    }

    public const string IDENT = 'components';
    public const string NAME = 'Components';
    public const string GROUP = 'Appearance';
    public const string ICON = 'component';
    public const bool ADMIN_ONLY = true;

    protected function actionList(): Response
    {
        $components = KomponentyStavby::all($this->db);
        foreach ($components as &$k) {
            $k['places'] = $this->usages((int) $k['component_id']);
            $k['usage'] = count($k['places']);
        }
        unset($k);

        return $this->view('list', 'Components', ['components' => $components]);
    }

    protected function actionNew(): Response
    {
        return $this->view('form', 'New component', ['k' => ['component_id' => 0, 'name' => '', 'properties' => []]]);
    }

    protected function actionEdit(): Response
    {
        $k = KomponentyStavby::byId($this->db, $this->request->getInt('id'));

        return $k === null ? $this->error('The component does not exist.', 404) : $this->view('form', $k['name'], ['k' => $k]);
    }

    protected function actionSave(): Response
    {
        if (!$this->request->isPost()) {
            return $this->back();
        }
        $id = $this->request->postInt('component_id');
        $name = mb_substr(trim($this->request->post('name')), 0, 100);
        if ($name === '') {
            return $this->back('The component needs a name.', $id > 0 ? 'edit' : 'new', $id > 0 ? ['id' => $id] : [], 'error');
        }
        $data = ['name' => $name, 'properties' => (string) json_encode(KomponentyStavby::sanitizeProperties(is_array($_POST['properties'] ?? null) ? $_POST['properties'] : []), JSON_UNESCAPED_UNICODE), 'updated_at' => date('Y-m-d H:i:s')];
        if ($id > 0 && KomponentyStavby::byId($this->db, $id) !== null) {
            $this->db->update('components', $data, ['component_id' => $id]);
        } else {
            $id = $this->db->insert('components', $data + ['build_draft' => Build::toJson(['v' => Build::VERSION, 'children' => [Build::fresh('section')]])]);
        }
        \Kaleta\Front\Cache::clear();

        return $this->back('The component was saved.');
    }

    protected function actionDelete(): Response
    {
        if ($this->request->isPost()) {
            $this->db->delete('components', ['component_id' => $this->request->postInt('component_id')]);
            \Kaleta\Front\Cache::clear();
        }

        return $this->back('The component was deleted. Places where it was used will be empty.');
    }

    /** From the builder: the selected element becomes a component (JSON). The editor then replaces it with a use of the component. */
    protected function actionFromElement(): Response
    {
        $element = $this->request->isPost() ? json_decode((string) ($_POST['element'] ?? ''), true) : null;
        $name = mb_substr(trim($this->request->post('name')), 0, 100);
        if (!is_array($element) || $name === '') {
            return Response::json(['ok' => false, 'error' => t('Name or element is missing.')], 400);
        }
        [$build] = Build::sanitize(['v' => Build::VERSION, 'children' => [$element]], true);
        if ($build['children'] === []) {
            return Response::json(['ok' => false, 'error' => t('A component cannot be created from this element.')], 400);
        }
        $id = $this->db->insert('components', ['name' => $name, 'properties' => '[]', 'build' => Build::toJson($build), 'updated_at' => date('Y-m-d H:i:s')]);

        return Response::json(['ok' => true, 'id' => $id, 'components' => self::listForEditor($this->db)]);
    }

    /** @return list<array{id: int, name: string, properties: list<array<string, string>>}> */
    public static function listForEditor(\Kaleta\Core\Db $db): array
    {
        return array_map(fn (array $k): array => ['id' => (int) $k['component_id'], 'name' => $k['name'], 'properties' => $k['properties']], KomponentyStavby::all($db));
    }

    /* ---------- editing in the builder ---------- */

    protected function actionBuilder(): Response
    {
        return $this->openBuilder();
    }

    protected function loadBuildTarget(): ?array
    {
        $k = KomponentyStavby::byId($this->db, $this->request->getInt('id'));

        return $k === null ? null : [
            'row' => $k, 'build' => $k['build'], 'draft' => $k['build_draft'], 'language' => Language::defaults($this->app->settings()),
            'title' => t('Component: %s', $k['name']), 'revisions' => ['part' => 'component:' . (int) $k['component_id']], 'params' => ['id' => (int) $k['component_id']],
        ];
    }

    protected function saveDraft(array $target, ?string $draft): void
    {
        $this->db->update('components', ['build_draft' => $draft], ['component_id' => $target['row']['component_id']]);
    }

    protected function publishTarget(array $target): void
    {
        Publisher::component($this->app, $target['row']);
    }

    protected function describeTarget(array $target): array
    {
        $k = $target['row'];
        $url = $this->app->url('_component/' . (int) $k['component_id']);

        return [
            'url' => $url . '?build=draft', 'preview' => $url . '?build=draft&editor=1', 'visible' => true, 'parts' => false,
            'back' => ['url' => $this->url(), 'text' => t('Components')], 'settings' => $this->url('edit', ['id' => (int) $k['component_id']]),
            // hint of the {{properties}} in the editor (the same as for a collection, only without built-in values)
            'collection' => ['slug' => '', 'name' => $k['name'], 'fields' => $k['properties'], 'detail' => false, 'builtin' => false],
        ];
    }

    /**
     * Where the component is used (pages, site parts, collections, other components) – names for the list and the delete confirmation.
     *
     * @return list<string>
     */
    private function usages(int $idm): array
    {
        $pattern = '%"type":"component"%"component":"' . $idm . '"%';
        $whereParts = ' WHERE (build LIKE ? OR build_draft LIKE ?)';
        $usages = [];
        foreach ($this->db->all('SELECT title, deleted_at IS NOT NULL AS trashed FROM {pages}' . $whereParts . ' ORDER BY deleted_at IS NOT NULL, title', [$pattern, $pattern]) as $r) {
            $usages[] = t('page “%s”', $r['title']) . ($r['trashed'] ? ' (' . t('in trash') . ')' : '');
        }
        foreach ($this->db->all('SELECT type, language, name FROM {site_parts}' . $whereParts . ' ORDER BY type, language, variant', [$pattern, $pattern]) as $r) {
            $usages[] = mb_strtolower(t(\Kaleta\Builder\SiteParts::TYPES[$r['type']][0] ?? $r['type'])) . ($r['name'] !== '' ? ' „' . $r['name'] . '“' : '') . ($r['language'] !== '' ? ' (' . $r['language'] . ')' : '');
        }
        foreach ($this->db->all('SELECT name FROM {collections}' . $whereParts . ' ORDER BY name', [$pattern, $pattern]) as $r) {
            $usages[] = t('collection detail “%s”', $r['name']);
        }
        // a component inside itself does not count (and is not even rendered on the site)
        foreach ($this->db->all('SELECT name FROM {components}' . $whereParts . ' AND component_id <> ? ORDER BY name', [$pattern, $pattern, $idm]) as $r) {
            $usages[] = t('component “%s”', $r['name']);
        }

        return $usages;
    }
}
