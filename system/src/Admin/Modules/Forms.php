<?php

declare(strict_types=1);

namespace Talea\Admin\Modules;

use Talea\Admin\Module;
use Talea\Builder\Build;
use Talea\Builder\Elements\Form;
use Talea\Builder\SiteParts;
use Talea\Core\Response;
use Talea\Front\Forms as SiteForms;

/**
 * The forms of the site and the answers each one received (Enquiries section). A form is not a record of its own: it is a
 * Form element in a build, named by its source and element id like the enquiries it sends (Front\Forms), so the list is
 * read from the builds of pages, site parts, pop-ups and item templates every time. Forms are edited in the builder; this
 * screen only reads. It shares the permission of Enquiries: the answers are enquiries.
 */
final class Forms extends Module
{
    public const string IDENT = 'forms';
    public const string NAME = 'Forms';
    public const string GROUP = 'Enquiries';
    public const string ICON = 'b-contact';
    public const string EXTENSION = 'enquiries';
    public const string SHARES_PERMISSION_OF = 'enquiries';

    /** Where a form stands: published, only in a draft (or on a hidden page, an off pop-up), or nowhere any more – its answers stay. */
    public const array STATES = ['live' => 'On the site', 'draft' => 'Only in a draft', 'removed' => 'Removed'];

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
            'live', 'draft', 'removed' => $f['state'] === $shown,
            default => true,
        } && ($search === '' || mb_stripos($f['name'] . ' ' . $f['where'], $search) !== false));

        return $this->view('list', 'Forms', ['forms' => $list, 'counts' => $counts, 'shown' => isset($counts[$shown]) ? $shown : '', 'search' => $search]);
    }

    protected function actionDetail(): Response
    {
        $form = $this->forms()[SiteForms::internalSource($this->db, $this->request->get('source')) . '|' . $this->request->get('element')] ?? null;
        if ($form === null) {
            return $this->error('The form does not exist.', 404);
        }
        $where = ['source = ?', 'element = ?', "category <> 'spam'", 'deleted_at IS NULL'];
        $params = [$form['source'], $form['element']];
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
        $all = $this->db->one("SELECT COUNT(*) AS total, SUM(CASE WHEN status < 2 THEN 1 ELSE 0 END) AS open, SUM(CASE WHEN created_at >= NOW() - INTERVAL 30 DAY THEN 1 ELSE 0 END) AS recent FROM {enquiries} WHERE source = ? AND element = ? AND category <> 'spam' AND deleted_at IS NULL", [$form['source'], $form['element']]) ?? [];

        $days = in_array($this->request->getInt('days'), [7, 30, 365], true) ? $this->request->getInt('days') : 30;
        $steps = \Talea\Front\FormSteps::steps($this->db, $form['source'], $form['element'], $days);

        return $this->view('detail', $form['name'] !== '' ? $form['name'] : t('Form'), ['days' => $days, 'steps' => $steps, 'drop' => \Talea\Front\FormSteps::biggestDrop($steps),
            'sent' => \Talea\Front\FormSteps::sent($this->db, $form['source'], $form['element'], $days), 'form' => $form, 'filter' => $filter, 'search' => $search, 'pageNumber' => $pageNumber, 'perPage' => self::PER_PAGE,
            'enquiries' => $this->db->all('SELECT public_id, created_at, email, status, page, data' . $sql . ' ORDER BY enquiry_id DESC LIMIT ' . self::PER_PAGE . ' OFFSET ' . (($pageNumber - 1) * self::PER_PAGE), $params),
            'shownTotal' => (int) $this->db->value('SELECT COUNT(*)' . $sql, $params),
            'totals' => ['total' => (int) ($all['total'] ?? 0), 'new' => $form['new'], 'open' => (int) ($all['open'] ?? 0), 'recent' => (int) ($all['recent'] ?? 0)]]);
    }

    /** The address of a form's answers. @param array{public: string, element: string} $form */
    public function detailUrl(array $form): string
    {
        return $this->url('detail', ['source' => $form['public'], 'element' => $form['element']]);
    }

    /**
     * Every form of the site, published ones first, then those only in a draft, then those that are gone but sent
     * answers; with their answer counts (spam left out, as in Enquiries).
     *
     * @return array<string, array{source: string, public: string, element: string, name: string, fields: list<array<string, mixed>>, where: string, kind: string, edit: string, state: string, total: int, new: int, last: ?string}> key "source|element"
     */
    private function forms(): array
    {
        $forms = [];
        $add = function (string $source, string $public, ?string $published, ?string $draft, string $where, string $kind, string $edit) use (&$forms): void {
            foreach (['live' => $published, 'draft' => $draft] as $state => $json) {
                foreach (SiteForms::elements($this->db, $json === null ? null : Build::fromJson($json), Form::TYPE) as $p) {
                    $forms[$source . '|' . $p['id']] ??= ['source' => $source, 'public' => $public, 'element' => (string) $p['id'], 'name' => (string) ($p['content']['name'] ?? ''),
                        'fields' => array_values((array) ($p['content']['fields'] ?? [])), 'where' => $where, 'kind' => $kind, 'edit' => $edit, 'state' => $state, 'total' => 0, 'new' => 0, 'last' => null];
                }
            }
        };
        foreach ($this->db->all('SELECT page_id, public_id, title, visible, build, build_draft FROM {pages} WHERE deleted_at IS NULL AND (build IS NOT NULL OR build_draft IS NOT NULL) ORDER BY sort_order, title') as $p) {
            $add('page:' . $p['page_id'], 'page:' . $p['public_id'], $p['visible'] ? $p['build'] : null, $p['build_draft'] ?? $p['build'], (string) $p['title'], t('Page'),
                $this->app->url('admin.php?module=pages&action=builder&id=' . $p['public_id']));
        }
        foreach ($this->db->all('SELECT type, language, variant, name, build, build_draft FROM {site_parts} ORDER BY type, language, variant') as $c) {
            $source = 'part:' . $c['type'] . ':' . $c['language'] . ($c['variant'] !== '' ? ':' . $c['variant'] : '');
            $add($source, $source, $c['build'], $c['build_draft'], trim(t(SiteParts::TYPES[$c['type']][0] ?? $c['type']) . ' ' . ($c['variant'] !== '' ? $c['name'] : '') . ' ' . strtoupper((string) $c['language'])), t('Site part'),
                $this->app->url('admin.php?module=parts&action=builder&type=' . rawurlencode((string) $c['type']) . ($c['variant'] !== '' ? '&variant=' . rawurlencode((string) $c['variant']) : '') . '&language=' . rawurlencode((string) $c['language'])));
        }
        foreach ($this->db->all('SELECT popup_id, public_id, name, active, build, build_draft FROM {popups} ORDER BY sort_order, name') as $c) {
            $add('popup:' . $c['popup_id'], 'popup:' . $c['public_id'], $c['active'] ? $c['build'] : null, $c['build_draft'] ?? $c['build'], (string) $c['name'], t('Pop-up'),
                $this->app->url('admin.php?module=popups&action=builder&id=' . $c['public_id']));
        }
        foreach ($this->db->all('SELECT collection_id, public_id, name, detail, build, build_draft FROM {collections} ORDER BY name') as $k) {
            $add('collection:' . $k['collection_id'], 'collection:' . $k['public_id'], $k['detail'] ? $k['build'] : null, $k['build_draft'] ?? $k['build'], (string) $k['name'], t('Item template'),
                $this->app->url('admin.php?module=collections&action=builder&id=' . $k['public_id']));
        }
        uasort($forms, fn (array $a, array $b): int => ($a['state'] === 'draft') <=> ($b['state'] === 'draft'));
        foreach ($this->db->all("SELECT source, element, MAX(form) AS form, COUNT(*) AS total, SUM(CASE WHEN status = 0 THEN 1 ELSE 0 END) AS fresh, MAX(created_at) AS last FROM {enquiries} WHERE element <> '' AND category <> 'spam' AND deleted_at IS NULL GROUP BY source, element ORDER BY last DESC") as $e) {
            $key = $e['source'] . '|' . $e['element'];
            // a form that is gone still has its answers: it stays in the list with the name its last answers carried
            $forms[$key] ??= ['source' => (string) $e['source'], 'public' => $this->publicSource((string) $e['source']), 'element' => (string) $e['element'], 'name' => (string) $e['form'],
                'fields' => [], 'where' => '', 'kind' => '', 'edit' => '', 'state' => 'removed', 'total' => 0, 'new' => 0, 'last' => null];
            $forms[$key] = ['total' => (int) $e['total'], 'new' => (int) $e['fresh'], 'last' => (string) $e['last']] + $forms[$key];
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
