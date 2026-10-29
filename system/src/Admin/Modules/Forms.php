<?php

declare(strict_types=1);

namespace Talea\Admin\Modules;

use Talea\Admin\BuilderActions;
use Talea\Admin\Module;
use Talea\Builder\Build;
use Talea\Builder\Elements\Form;
use Talea\Builder\FormRecords;
use Talea\Builder\Publisher;
use Talea\Core\Language;
use Talea\Core\Response;
use Talea\Front\Forms as SiteForms;

/**
 * The forms of the site and the answers each one received (Enquiries section, docs/specs/form-builder.md). A form is a record
 * (Builder\FormRecords) edited in the form builder – the page builder opened on the form – and pages place it with the Form
 * element. The list also shows forms still written inside a page draft and forms that are gone but have answers. It shares the
 * permission of Enquiries: the answers are enquiries.
 */
final class Forms extends Module
{
    use BuilderActions;

    public const string IDENT = 'forms';
    public const string NAME = 'Forms';
    public const string GROUP = 'Enquiries';
    public const string ICON = 'b-contact';
    public const string EXTENSION = 'enquiries';
    public const string SHARES_PERMISSION_OF = 'enquiries';
    public const string TABLE = 'forms';

    /**
     * Where a form stands: placed on the published site, published with changes waiting, never published, published but placed
     * nowhere, still written inside a page draft, or gone – its answers stay.
     */
    public const array STATES = ['live' => 'On the site', 'changes' => 'Unpublished changes', 'draft' => 'Not published yet', 'unplaced' => 'Not placed on any page',
        'written' => 'Only in a draft', 'removed' => 'Removed'];

    /** What a new form starts with (FormRecords::starterBuild). */
    public const array STARTERS = ['enquiry' => ['Enquiry', 'Name, e-mail, phone, message and consent'], 'download' => ['Download', 'Name, e-mail and consent; sends a file by e-mail'],
        'empty' => ['Empty', 'Only an e-mail field']];

    private const int PER_PAGE = 50;

    protected function actionList(): Response
    {
        $forms = $this->forms();
        $shown = $this->request->get('status');
        $search = mb_substr(trim($this->request->get('search')), 0, 100);
        $counts = ['' => count($forms), 'new' => count(array_filter($forms, fn (array $f): bool => $f['new'] > 0))]
            + array_map(fn (string $state): int => count(array_filter($forms, fn (array $f): bool => $f['state'] === $state)), array_combine(array_keys(self::STATES), array_keys(self::STATES)));
        $list = array_filter($forms, fn (array $f): bool => match ($shown) {
            'new' => $f['new'] > 0,
            '' => true,
            default => $f['state'] === $shown,
        } && ($search === '' || mb_stripos($f['name'] . ' ' . $f['where'], $search) !== false));

        return $this->view('list', 'Forms', ['forms' => $list, 'counts' => $counts, 'shown' => isset($counts[$shown]) ? $shown : '', 'search' => $search]);
    }

    protected function actionNew(): Response
    {
        return $this->view('new', 'New form', ['back' => $this->backToBuilder()]);
    }

    /** Creates a form from a starter and opens it in the form builder (back to the page builder it was asked from, when it was). */
    protected function actionCreate(): Response
    {
        if (!$this->request->isPost()) {
            return $this->back();
        }
        $name = mb_substr(trim($this->request->post('name')), 0, 120);
        if ($name === '') {
            return $this->back('The form needs a name.', 'new', array_filter(['back' => $this->request->post('back')]), 'error');
        }
        $starter = isset(self::STARTERS[$this->request->post('starter')]) ? $this->request->post('starter') : 'enquiry';
        $build = FormRecords::starterBuild($starter);
        $build['children'][0]['content']['name'] = $name;
        $now = date('Y-m-d H:i:s');
        $id = $this->db->insert('forms', ['name' => $name, 'build_draft' => Build::toJson($build), 'created_at' => $now, 'updated_at' => $now]);
        \Talea\Admin\ChangeLog::write($this->app, self::IDENT, 'form created', mb_substr($name, 0, 80));
        $back = $this->request->post('back');

        return Response::redirect($this->url('builder', ['id' => $this->publicId($id)] + ($back !== '' && $this->backToBuilderUrl($back) ? ['back' => $back] : [])));
    }

    protected function actionDelete(): Response
    {
        if (!$this->request->isPost()) {
            return $this->back();
        }
        $form = FormRecords::byId($this->db, $this->idParam());
        if ($form === null) {
            return $this->error('The form does not exist.', 404);
        }
        $places = FormRecords::placements($this->app, (string) $form['public_id']);
        if ($places !== []) {
            return $this->back(t('The form is placed on: %s. Take it off those pages first.', implode(', ', array_unique(array_column($places, 'where')))), '', [], 'error');
        }
        $this->db->delete('forms', ['form_id' => $form['form_id']]);
        $this->db->delete('build_revisions', ['part' => 'form:' . (int) $form['form_id']]);
        \Talea\Admin\ChangeLog::write($this->app, self::IDENT, 'form deleted', mb_substr((string) $form['name'], 0, 80));
        FormRecords::forget();

        return $this->back('The form was deleted. Its answers stay in Enquiries.');
    }

    protected function actionDetail(): Response
    {
        $key = $this->request->get('id') !== '' ? $this->request->get('id') : SiteForms::internalSource($this->db, $this->request->get('source')) . '|' . $this->request->get('element');
        $forms = $this->forms();
        // an address by place (an older link, a form written inside a page before it became a record) names the record placed there
        $form = $forms[$key] ?? array_values(array_filter($forms, fn (array $f): bool => in_array(explode('|', $key, 2), $f['places'], true)))[0] ?? null;
        if ($form === null) {
            return $this->error('The form does not exist.', 404);
        }
        [$where, $params] = $form['form_id'] > 0 ? [['form_id = ?'], [$form['form_id']]] : [['source = ?', 'element = ?'], [$form['source'], $form['element']]];
        $identity = $where;
        $identityParams = $params;
        $where = [...$where, "category <> 'spam'", 'deleted_at IS NULL'];
        $filter = $this->request->get('status');
        $where[] = match ($filter) {
            'open' => 'status < 2',
            'resolved' => 'status = 2',
            default => 'TRUE',
        };
        $search = mb_substr(trim($this->request->get('search')), 0, 100);
        if ($search !== '') {
            // the data is JSON with \uXXXX instead of diacritics – the search also looks in that form (as in Enquiries)
            $where[] = '(' . $this->db->dialect()->likeInsensitive('email') . ' OR ' . $this->db->dialect()->likeInsensitive('data') . ' OR ' . $this->db->dialect()->likeInsensitive('data') . ')';
            array_push($params, '%' . addcslashes($search, '%_\\') . '%', '%' . addcslashes($search, '%_\\') . '%', '%' . addcslashes(substr((string) json_encode($search), 1, -1), '%_\\') . '%');
        }
        $sql = ' FROM {enquiries} WHERE ' . implode(' AND ', $where);
        $pageNumber = max(1, $this->request->getInt('page', 1));
        $all = $this->db->one("SELECT COUNT(*) AS total, SUM(CASE WHEN status < 2 THEN 1 ELSE 0 END) AS open, SUM(CASE WHEN created_at >= NOW() - INTERVAL 30 DAY THEN 1 ELSE 0 END) AS recent FROM {enquiries} WHERE "
            . implode(' AND ', $identity) . " AND category <> 'spam' AND deleted_at IS NULL", $identityParams) ?? [];

        $days = in_array($this->request->getInt('days'), [7, 30, 365], true) ? $this->request->getInt('days') : 30;
        // the drop-off of every place the form is on, added up; titled by the form as it is published
        $steps = [];
        $multiStep = false;
        $sent = 0;
        foreach ($form['places'] as [$source, $element]) {
            foreach (\Talea\Front\FormSteps::steps($this->db, $source, $element, $days) as $i => $s) {
                $steps[$i] = isset($steps[$i]) ? ['reached' => $steps[$i]['reached'] + $s['reached']] + $steps[$i] : $s;
            }
            $multiStep = $multiStep || \Talea\Front\FormSteps::stepCount($this->db, $source, $element) > 1 || (int) $this->db->value('SELECT COUNT(*) FROM {form_steps} WHERE source = ? AND element = ?', [$source, $element]) > 0;
            $sent += \Talea\Front\FormSteps::sent($this->db, $source, $element, $days);
        }

        return $this->view('detail', $form['name'] !== '' ? $form['name'] : t('Form'), ['days' => $days, 'steps' => array_values($steps), 'multiStep' => $multiStep,
            'drop' => \Talea\Front\FormSteps::biggestDrop(array_values($steps)), 'sent' => $sent, 'form' => $form, 'filter' => $filter, 'search' => $search, 'pageNumber' => $pageNumber, 'perPage' => self::PER_PAGE,
            'enquiries' => $this->db->all('SELECT public_id, created_at, email, status, page, data' . $sql . ' ORDER BY enquiry_id DESC LIMIT ' . self::PER_PAGE . ' OFFSET ' . (($pageNumber - 1) * self::PER_PAGE), $params),
            'shownTotal' => (int) $this->db->value('SELECT COUNT(*)' . $sql, $params),
            'totals' => ['total' => (int) ($all['total'] ?? 0), 'new' => $form['new'], 'open' => (int) ($all['open'] ?? 0), 'recent' => (int) ($all['recent'] ?? 0)]]);
    }

    /** The address of a form's answers. @param array{query: array<string, string>} $form */
    public function detailUrl(array $form): string
    {
        return $this->url('detail', $form['query']);
    }

    /**
     * The forms the builder's Form element can place, with the fields a placement can give a width (Elements\FormEmbed); the
     * fields are the draft's, as the builder's canvas shows the form.
     *
     * @return list<array{id: string, name: string, published: bool, fields: list<array{key: string, label: string, type: string}>}>
     */
    public static function listForEditor(\Talea\Core\Db $db): array
    {
        return array_map(fn (array $f): array => ['id' => (string) $f['public_id'], 'name' => (string) $f['name'], 'published' => $f['build'] !== null,
            'fields' => array_values(array_map(fn (array $field): array => ['key' => (string) ($field['key'] ?? ''), 'label' => (string) ($field['label'] ?? ''), 'type' => (string) ($field['type'] ?? '')],
                array_filter((array) (FormRecords::definition(Build::fromJson($f['build_draft'] ?? $f['build']))['content']['fields'] ?? []), fn (mixed $field): bool => is_array($field) && !in_array($field['type'] ?? '', ['hidden', 'estimate'], true))))],
            FormRecords::all($db));
    }

    /* ---------- the form builder ---------- */

    protected function loadBuildTarget(): ?array
    {
        $form = FormRecords::byId($this->db, $this->idParam('id', null, true));

        return $form === null ? null : [
            'row' => $form, 'build' => $form['build'], 'draft' => $form['build_draft'], 'language' => Language::defaults($this->app->settings()),
            'title' => t('Form: %s', $form['name']), 'revisions' => ['part' => 'form:' . (int) $form['form_id']], 'params' => ['id' => $form['public_id']],
        ];
    }

    protected function shapeBuild(array $build): array
    {
        return FormRecords::definitionBuild($build);
    }

    protected function saveDraft(array $target, ?string $draft): void
    {
        $this->db->update('forms', ['build_draft' => $draft === null ? null : Build::toJson(FormRecords::definitionBuild(Build::fromJson($draft))), 'updated_at' => date('Y-m-d H:i:s')],
            ['form_id' => $target['row']['form_id']]);
        FormRecords::forget();
    }

    protected function publishTarget(array $target): void
    {
        Publisher::form($this->app, (array) FormRecords::byId($this->db, (int) $target['row']['form_id']));
    }

    protected function describeTarget(array $target): array
    {
        $form = $target['row'];
        $url = $this->app->url('_form/' . $form['public_id']);

        return [
            'url' => $url . '?build=draft', 'visible' => true, 'parts' => false, 'form' => true, 'preview' => $url . '?build=draft&editor=1',
            'back' => $this->backToBuilder() ?? ['url' => $this->url(), 'text' => t('Forms')], 'settings' => null,
        ];
    }

    /** Whether an address is this administration's builder (the way back after creating a form from a page). */
    private function backToBuilderUrl(string $back): bool
    {
        $admin = $this->app->url('admin.php') . '/';

        return str_starts_with($back, $admin) && preg_match('#^[a-z_]+/builder(?:/[A-Za-z0-9_.-]+)?(?:\?[^\s"<>]*)?$#', substr($back, strlen($admin))) === 1;
    }

    /**
     * Every form of the site: the records first, then forms still written inside a draft, then forms that are gone but sent
     * answers; with their answer counts (spam left out, as in Enquiries). Records are keyed by public id, the others by
     * "source|element".
     *
     * @return array<string, array{query: array<string, string>, form_id: int, source: string, element: string, name: string, fields: list<array<string, mixed>>, where: string, kind: string, edit: string, state: string, total: int, new: int, last: ?string, places: list<array{0: string, 1: string}>, deletable: bool}>
     */
    private function forms(): array
    {
        FormRecords::adoptAll($this->app); // forms an import or a kit wrote into published builds become records
        $forms = [];
        $placed = [];
        $answers = [];
        foreach ($this->db->all("SELECT form_id, COUNT(*) AS total, SUM(CASE WHEN status = 0 THEN 1 ELSE 0 END) AS fresh, MAX(created_at) AS last FROM {enquiries} WHERE form_id IS NOT NULL AND category <> 'spam' AND deleted_at IS NULL GROUP BY form_id") as $e) {
            $answers[(int) $e['form_id']] = ['total' => (int) $e['total'], 'new' => (int) $e['fresh'], 'last' => (string) $e['last']];
        }
        foreach (FormRecords::all($this->db) as $f) {
            $places = FormRecords::placements($this->app, (string) $f['public_id']);
            $live = array_values(array_filter($places, fn (array $p): bool => $p['state'] === 'live'));
            foreach ($places as $p) {
                $placed[$p['source'] . '|' . $p['element']] = true;
            }
            $state = match (true) {
                $f['build'] === null => 'draft',
                $live === [] => 'unplaced',
                $f['build_draft'] !== null && $f['build_draft'] !== $f['build'] => 'changes',
                default => 'live',
            };
            $forms[(string) $f['public_id']] = ['query' => ['id' => (string) $f['public_id']], 'form_id' => (int) $f['form_id'], 'source' => 'form:' . $f['form_id'], 'element' => '', 'name' => (string) $f['name'],
                'fields' => array_values((array) (FormRecords::definition(Build::fromJson($f['build'] ?? $f['build_draft']))['content']['fields'] ?? [])),
                'where' => implode(', ', array_unique(array_column($places, 'where'))), 'kind' => $places === [] ? '' : t('%d places', count(array_unique(array_column($places, 'where')))),
                'edit' => $this->url('builder', ['id' => (string) $f['public_id']]), 'state' => $state, 'places' => array_map(fn (array $p): array => [$p['source'], $p['element']], $live),
                'deletable' => $places === [], 'total' => 0, 'new' => 0, 'last' => null];
            $forms[(string) $f['public_id']] = ($answers[(int) $f['form_id']] ?? []) + $forms[(string) $f['public_id']];
        }
        // a form written inside a draft becomes a record when its build is published (FormRecords::adopt)
        foreach (FormRecords::stored($this->db, '%"type":"' . Form::TYPE . '"%') as $place) {
            foreach (SiteForms::elements($this->db, Build::fromJson($place['build_draft']), Form::TYPE, false) as $p) {
                if ($place['source'] === '') {
                    continue;
                }
                $forms[$place['source'] . '|' . $p['id']] ??= ['query' => ['source' => $this->publicSource($place['source']), 'element' => (string) $p['id']], 'form_id' => 0, 'source' => $place['source'], 'element' => (string) $p['id'],
                    'name' => (string) ($p['content']['name'] ?? ''), 'fields' => array_values((array) ($p['content']['fields'] ?? [])), 'where' => $place['where'], 'kind' => '',
                    'edit' => $this->app->url($place['edit']), 'state' => 'written', 'places' => [], 'deletable' => false, 'total' => 0, 'new' => 0, 'last' => null];
            }
        }
        foreach ($this->db->all("SELECT source, element, MAX(form) AS form, COUNT(*) AS total, SUM(CASE WHEN status = 0 THEN 1 ELSE 0 END) AS fresh, MAX(created_at) AS last FROM {enquiries} WHERE form_id IS NULL AND element <> '' AND category <> 'spam' AND deleted_at IS NULL GROUP BY source, element ORDER BY last DESC") as $e) {
            $key = $e['source'] . '|' . $e['element'];
            if (isset($placed[$key])) {
                continue;
            }
            // a form that is gone still has its answers: it stays in the list with the name its last answers carried
            $forms[$key] ??= ['query' => ['source' => $this->publicSource((string) $e['source']), 'element' => (string) $e['element']], 'form_id' => 0, 'source' => (string) $e['source'], 'element' => (string) $e['element'],
                'name' => (string) $e['form'], 'fields' => [], 'where' => '', 'kind' => '', 'edit' => '', 'state' => 'removed', 'places' => [], 'deletable' => false, 'total' => 0, 'new' => 0, 'last' => null];
            $forms[$key] = ['total' => (int) $e['total'], 'new' => (int) $e['fresh'], 'last' => (string) $e['last'], 'places' => [[(string) $e['source'], (string) $e['element']]]] + $forms[$key];
        }

        return $forms;
    }

    /** The source as an address carries it: a page, pop-up or item template by its public id, a site part as it is. */
    private function publicSource(string $source): string
    {
        if (!preg_match('/^(page|collection|popup):(\d+)$/', $source, $m)) {
            return $source;
        }

        return $m[1] . ':' . $this->db->publicId(['page' => 'pages', 'collection' => 'collections', 'popup' => 'popups'][$m[1]], (int) $m[2]);
    }
}
