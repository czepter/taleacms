<?php

declare(strict_types=1);

namespace Talea\Admin\Modules;

use Talea\Admin\Module;
use Talea\Admin\BuilderActions;
use Talea\Core\Language;
use Talea\Core\Calendar;
use Talea\Core\Notices;
use Talea\Core\Registrations;
use Talea\Core\Response;
use Talea\Builder\Collections as KolekceObsahu;
use Talea\Builder\Publisher;

/**
 * Collections – custom content types (references, team, products, branches…). The field definition and the item template
 * are changed by the administrator, the items by anyone with access to the module. The Collection list element in the
 * builder puts them on the site.
 */
final class Collections extends Module
{
    use BuilderActions {
        actionBuilder as protected openBuilder;
    }

    /** Confirmed registrations the screen lists before "Show all". */
    private const int CONFIRMED_SHOWN = 10;

    public const string IDENT = 'collections';
    public const string NAME = 'Collections';
    public const string GROUP = 'Content';
    public const string ICON = 'collections';
    public const string TABLE = 'collections';

    /** At most this many collections stand in the admin sidebar; the others are reached through Collections. */
    public const int MENU_LIMIT = 8;

    /**
     * Every collection by name for the admin sidebar and the command palette (one query per page): public_id, name and
     * admin_menu ("Show in the admin menu"). A notice board stays out while its feature is off, as in the list.
     *
     * @return list<array{public_id: string, name: string, admin_menu: int}>
     */
    public static function navigation(\Talea\Core\App $app): array
    {
        $notices = \Talea\Core\Extensions::isEnabled($app->settings(), 'notice_board') ? '' : " WHERE preset <> 'notices'";

        return $app->db()->all('SELECT public_id, name, admin_menu FROM {collections}' . $notices . ' ORDER BY name');
    }

    protected function actionList(): Response
    {
        return $this->view('list', 'Collections', [
            'presets' => \Talea\Builder\Presets::available($this->app->settings()),
            'collection' => array_filter($this->db->all('SELECT k.collection_id, k.public_id, k.name, k.slug, k.detail, k.preset, (SELECT COUNT(*) FROM {collection_items} p WHERE p.collection_id = k.collection_id AND p.deleted_at IS NULL) AS count FROM {collections} k ORDER BY k.name'), fn (array $k): bool => $k['preset'] !== 'notices' || \Talea\Core\Extensions::isEnabled($this->app->settings(), 'notice_board')),
        ]);
    }

    /* ---------- collection definition (administrator) ---------- */

    /** @return array<string, string> collections an item link can point to (2.10): address => name */
    private function otherCollections(int $idk): array
    {
        return $this->db->pairs('SELECT slug, name FROM {collections} WHERE collection_id <> ? ORDER BY name', [$idk]);
    }

    /** A ready-made collection (2.10, 2.11 Builder\Presets): its fields, item pages, structured data and the redirect of hidden items. */
    protected function actionPreset(): Response
    {
        if (($refusal = $this->admin()) !== null || !$this->request->isPost()) {
            return $refusal ?? $this->back();
        }
        [$id, $pageId] = \Talea\Builder\Presets::createWithPage($this->app, $this->request->post('preset')) ?? [null, null];
        if ($id === null) {
            return $this->back('Unknown template.', '', [], 'error');
        }
        // the message says what really happened - the list page is made only when its address is free
        $path = '/' . (string) $this->db->value('SELECT slug FROM {collections} WHERE collection_id = ?', [$id]);
        $page = $pageId === null ? $this->db->one("SELECT title FROM {pages} WHERE slug = ? AND language = '' AND deleted_at IS NULL", [ltrim($path, '/')]) : null;

        return $this->back(match (true) {
            $pageId !== null => t('Created, with a hidden page %s that lists it – add the first items, then publish the page in Pages.', $path),
            $page !== null => t('Created. The page “%s” already uses the address %s, so no list page was added – open that page in the builder and add the Collection list element.', (string) $page['title'], $path),
            default => t('Created. The address %s is taken, so no list page was added – add the Collection list element to a page in the builder.', $path),
        }, 'items', ['id' => $this->publicId($id)]);
    }

    protected function actionNew(): Response
    {
        return $this->admin() ?? $this->view('form', 'New collection', ['k' => ['collection_id' => 0, 'public_id' => '', 'name' => '', 'slug' => '', 'detail' => 0, 'admin_menu' => 1, 'fields' => [
            ['key' => '', 'label' => t('Description'), 'type' => 'lines'], ['key' => '', 'label' => t('Image'), 'type' => 'image'],
        ]], 'otherCollections' => $this->otherCollections(0)]);
    }

    protected function actionEdit(): Response
    {
        $k = KolekceObsahu::byId($this->db, $this->idParam());

        return $this->admin() ?? ($k === null ? $this->error('The collection does not exist.', 404) : $this->view('form', $k['name'], ['k' => $k, 'otherCollections' => $this->otherCollections((int) $k['collection_id'])]));
    }

    protected function actionSave(): Response
    {
        if (($refusal = $this->admin()) !== null || !$this->request->isPost()) {
            return $refusal ?? $this->back();
        }
        $r = $this->request;
        if (($guard = $this->refuseUnknownId('collection_id', 'The collection does not exist.')) !== null) {
            return $guard;
        }
        $id = $this->idParam('collection_id');
        $previous = $id > 0 ? KolekceObsahu::byId($this->db, $id) : null;
        $name = mb_substr(trim($r->post('name')), 0, 100);
        if ($name === '') {
            return $this->back('The collection needs a name.', $id > 0 ? 'edit' : 'new', $id > 0 ? ['id' => $this->publicId($id)] : [], 'error');
        }
        $seo = slugify($r->post('slug') !== '' ? $r->post('slug') : $name, 110);
        if (in_array($seo, \Talea\Front\Kernel::reservedSlugs(), true) || isset(Language::AVAILABLE[$seo]) || \Talea\Core\Routes::isNewsSlug($seo, $this->db) || $this->db->value('SELECT collection_id FROM {collections} WHERE slug = ? AND collection_id <> ?', [$seo, $id]) !== null) {
            return $this->back(t('The address “%s” is already used by the system or another collection.', $seo), $id > 0 ? 'edit' : 'new', $id > 0 ? ['id' => $this->publicId($id)] : [], 'error');
        }
        // the fields in the order of their position (admin.js renumbers it after a drag); a ticked "remove" drops one;
        // Earlier / Later without a script (move = "<index>:-1|1") swaps it with its neighbour and comes back to the form
        $rows = [];
        foreach (is_array($_POST['fields'] ?? null) ? $_POST['fields'] : [] as $i => $f) {
            if (is_array($f) && empty($f['remove'])) {
                $rows[(int) $i] = $f;
            }
        }
        uasort($rows, fn (array $a, array $b): int => (int) ($a['position'] ?? 0) <=> (int) ($b['position'] ?? 0));
        $move = preg_match('/^(\d+):(-1|1)$/', $r->post('move'), $m) ? [(int) $m[1], (int) $m[2]] : null;
        if ($move !== null && isset($rows[$move[0]])) {
            $order = array_keys($rows);
            $at = (int) array_search($move[0], $order, true);
            if (isset($order[$at + $move[1]])) {
                [$order[$at], $order[$at + $move[1]]] = [$order[$at + $move[1]], $order[$at]];
                $rows = array_replace(array_flip($order), $rows);
            }
        }
        // the key of an existing field does not change (item values are stored under it); new fields get it from the label
        $field = KolekceObsahu::sanitizeFields(array_values($rows));
        $redirect = KolekceObsahu::cleanRedirect($r->post('hidden_redirect'));
        if ($redirect === null) {
            return $this->back('The redirect of hidden items must be an address on the site (/team) or https://…', $id > 0 ? 'edit' : 'new', $id > 0 ? ['id' => $this->publicId($id)] : [], 'error');
        }
        $data = ['name' => $name, 'slug' => $seo, 'detail' => $r->postBool('detail') ? 1 : 0, 'admin_menu' => $r->postBool('admin_menu') ? 1 : 0, 'hidden_redirect' => $redirect, 'fields' => (string) json_encode($field, JSON_UNESCAPED_UNICODE), 'updated_at' => date('Y-m-d H:i:s')];
        if (is_array($_POST['schema'] ?? null)) {
            $schema = \Talea\Builder\CollectionSchema::sanitize($_POST['schema'], $field);
            $data['schema_org'] = $schema === null ? null : (string) json_encode($schema, JSON_UNESCAPED_UNICODE);
        }
        if ($previous !== null) {
            $this->db->update('collections', $data, ['collection_id' => $id]);
        } else {
            $id = $this->db->insert('collections', $data);
        }
        \Talea\Front\Cache::clear();
        if ($move !== null) {
            return $this->back('The collection was saved.', 'edit', ['id' => $this->publicId($id)]);
        }

        return $this->back('The collection was saved.', 'items', ['id' => $this->publicId($id)]);
    }

    protected function actionDelete(): Response
    {
        if (($refusal = $this->admin()) !== null) {
            return $refusal;
        }
        if ($this->request->isPost()) {
            $k = KolekceObsahu::byId($this->db, $this->idParam('collection_id'));
            // an official notice board keeps its notices for good (2.11, Core\Notices)
            if ($k !== null && ($count = Notices::count($this->db, $k)) > 0) {
                return $this->back(t(Notices::REFUSAL_COLLECTION, $count), 'edit', ['id' => $k['public_id']], 'error');
            }
            $this->db->delete('collections', ['collection_id' => $this->idParam('collection_id')]);
        }

        return $this->back('The collection and its items were deleted.');
    }

    /* ---------- items ---------- */

    protected function actionItems(): Response
    {
        $k = KolekceObsahu::byId($this->db, $this->idParam());
        if ($k === null) {
            return $this->error('The collection does not exist.', 404);
        }

        [$siteLanguages, $language, $column] = $this->readLanguageFilter();
        $trash = $this->request->get('status') === 'trash';
        $where = 'collection_id = ? AND deleted_at IS ' . ($trash ? 'NOT NULL' : 'NULL');
        $params = [$k['collection_id']];
        if ($column !== null) {
            $where .= ' AND language = ?';
            $params[] = $column;
        }
        // every choice field is a filter too (?field_<key>=<option>): a job by its kind, a product by its range; an unknown option is no filter
        $choiceFields = array_values(array_filter($k['fields'], fn (array $f): bool => ($f['type'] ?? '') === 'radio' && preg_match(KolekceObsahu::KEY_PATTERN, (string) ($f['key'] ?? '')) === 1));
        $fieldFilter = [];
        foreach ($trash ? [] : $choiceFields as $f) {
            if (in_array($value = $this->request->get('field_' . $f['key']), (array) ($f['options'] ?? []), true)) {
                $fieldFilter[$f['key']] = $value;
                $where .= ' AND ' . $this->db->dialect()->jsonExtract('data', '$.' . $f['key']) . ' = ?'; // the key matched KEY_PATTERN above
                $params[] = $value;
            }
        }

        $items = $this->db->all('SELECT item_id, public_id, name, slug, sort_order, visible, language, created_at, deleted_at, valid_until, review_by, data, image FROM {collection_items} WHERE ' . $where
            . ' ORDER BY ' . ($trash ? 'deleted_at DESC' : 'language, sort_order, name'), $params);
        // a calendar collection shows the seats of each course (null = not a calendar, no column)
        $seats = null;
        if (!$trash && Calendar::fields($k) !== null) {
            $seats = [];
            foreach ($items as $p) {
                $p['data'] = json_decode((string) $p['data'], true) ?: [];
                if (($course = Registrations::course($k, $p)) !== null) {
                    $seats[(int) $p['item_id']] = ['course' => $course] + Registrations::counts($this->db, (int) $p['item_id']);
                }
            }
        }

        return $this->view('items', $k['name'], ['k' => $k, 'languages' => Language::additional($this->app->settings()), 'siteLanguages' => $siteLanguages, 'language' => $language,
            'choiceFields' => $choiceFields, 'fieldFilter' => $fieldFilter,
            'trash' => $trash, 'noticeBoard' => Notices::isNotices($k), 'inTrash' => (int) $this->db->value('SELECT COUNT(*) FROM {collection_items} WHERE collection_id = ? AND deleted_at IS NOT NULL', [$k['collection_id']]),
            // a document library (2.11): how often each document was downloaded, and its stable address
            'downloads' => \Talea\Core\Documents::fileField($k) !== null ? \Talea\Core\Documents::counts($this->db, (int) $k['collection_id']) : null,
            'items' => $items, 'seats' => $seats]);
    }

    /** The registration screen of a course: seats, then the registrations by state with the actions that apply. */
    protected function actionRegistrations(): Response
    {
        [$k, $item, $course] = $this->courseOfRequest();
        if ($course === null) {
            return $this->error('This is not a course with seats.', 404);
        }
        $itemId = (int) $item['item_id'];
        $counts = Registrations::counts($this->db, $itemId);
        $rows = Registrations::list($this->db, $itemId);
        $forms = [];
        $names = [];
        foreach ($rows as $group) {
            foreach ($group as $row) {
                $key = $row['source'] . "\0" . $row['element'];
                $forms[$key] ??= \Talea\Front\Forms::findElement($this->db, (string) $row['source'], (string) $row['element'], 'form');
                $names[(int) $row['enquiry_id']] = Registrations::nameOf($forms[$key], (string) $row['data']);
            }
        }
        $free = $course['capacity'] > 0 ? max(0, $course['capacity'] - $counts['taken']) : PHP_INT_MAX;
        $confirmed = $rows['confirmed'];
        $all = $this->request->get('all') === '1';

        return $this->view('registrations', t('Registrations: %s', $item['name']), ['k' => $k, 'item' => $item, 'course' => $course, 'counts' => $counts, 'rows' => $rows, 'names' => $names, 'free' => $free,
            'confirmedShown' => $all ? $confirmed : array_slice($confirmed, 0, self::CONFIRMED_SHOWN), 'confirmedCount' => count($confirmed), 'all' => $all,
            // ponytail: the form's own e-mail field is not cheap to reach from here; a registration without an address is the same problem seen from the data
            'noEmail' => (int) $this->db->value("SELECT COUNT(*) FROM {enquiries} WHERE deleted_at IS NULL AND email = '' AND item_id IN (" . implode(',', Registrations::group($this->db, $itemId)) . ') AND seat_state IS NOT NULL AND seat_state <> \'\'')]);
    }

    /** The holder's decision on one registration (confirm | decline | cancel | promote); the visitor is told by e-mail. */
    protected function actionRegistration(): Response
    {
        if (!$this->request->isPost()) {
            return $this->back();
        }
        [$k, $item, $course] = $this->courseOfRequest();
        if ($course === null) {
            return $this->back('This is not a course with seats.', '', [], 'error');
        }
        $back = ['id' => $k['public_id'], 'item' => $item['public_id']];
        $enquiryId = $this->db->internalId('enquiries', $this->request->post('registration'));
        $row = $enquiryId > 0 ? $this->db->one('SELECT item_id FROM {enquiries} WHERE enquiry_id = ?', [$enquiryId]) : null;
        if ($row === null || !in_array((int) $row['item_id'], Registrations::group($this->db, (int) $item['item_id']), true)) {
            return $this->back('The registration does not exist.', 'registrations', $back, 'error');
        }
        $do = $this->request->post('do');
        if (!in_array($do, ['confirm', 'decline', 'cancel', 'promote'], true)) {
            return $this->back('Unknown action.', 'registrations', $back, 'error');
        }
        try {
            Registrations::transition($this->app, $enquiryId, $do, mb_substr(trim($this->request->post('message')), 0, 2000));
        } catch (\DomainException $e) {
            return $this->back($e->getMessage(), 'registrations', $back, 'error');
        }

        return $this->back('The registration was updated.', 'registrations', $back);
    }

    /** The collection, the item and its registration rules named by ?id= and ?item= (also in a POST). @return array{0: array, 1: array, 2: ?array} */
    private function courseOfRequest(): array
    {
        $k = KolekceObsahu::byId($this->db, $this->idParam());
        $item = $k !== null ? $this->db->one('SELECT * FROM {collection_items} WHERE item_id = ? AND collection_id = ? AND deleted_at IS NULL', [$this->idParam('item', 'collection_items'), $k['collection_id']]) : null;
        if ($item === null) {
            return [[], [], null];
        }
        $item['data'] = json_decode((string) $item['data'], true) ?: [];

        return [$k, $item, Registrations::course($k, $item)];
    }

    protected function actionItem(): Response
    {
        $k = KolekceObsahu::byId($this->db, $this->idParam());
        if ($k === null) {
            return $this->error('The collection does not exist.', 404);
        }
        $idp = $this->idParam('item', 'collection_items');
        // an item in the trash is not edited (saving would publish it again) – it comes back through Restore first
        $p = $idp > 0 ? $this->db->one('SELECT * FROM {collection_items} WHERE item_id = ? AND collection_id = ? AND deleted_at IS NULL', [$idp, $k['collection_id']]) : null;
        if ($idp > 0 && $p === null) {
            return $this->error('The item does not exist.', 404);
        }
        if ($p === null) {
            // a new translation from the translation overview (2.14): the original's values, hidden, in the chosen language with the same address
            $language = Language::column($this->app->settings(), $this->request->get('language'));
            $original = $language !== '' ? $this->db->one("SELECT * FROM {collection_items} WHERE item_id = ? AND collection_id = ? AND language = '' AND deleted_at IS NULL", [$this->idParam('original', 'collection_items'), $k['collection_id']]) : null;
            $p = ['item_id' => 0, 'public_id' => '', 'name' => $original['name'] ?? '', 'slug' => $original['slug'] ?? '', 'data' => $original['data'] ?? '{}', 'sort_order' => $original['sort_order'] ?? 100, 'visible' => $original === null ? 1 : 0,
                'language' => $language, 'created_at' => date('Y-m-d H:i:s'), 'seo_title' => '', 'description' => '', 'image' => $original['image'] ?? '', 'noindex' => 0, 'publish_at' => null, 'valid_until' => null, 'review_by' => null];
        }
        $p['data'] = json_decode((string) $p['data'], true) ?: [];
        $settings = $this->app->settings();
        $host = (string) (parse_url($settings->get('site_url') ?: $this->request->origin(), PHP_URL_HOST) ?? '');
        $address = $k['detail'] ? ($p['language'] !== '' ? $p['language'] . '/' : '') . $k['slug'] . '/' . $p['slug'] : '';

        return $this->view('item', $p['name'] !== '' ? $p['name'] : t('New item'), ['k' => $k, 'p' => $p, 'host' => $host, 'address' => $address,
            // the search result preview: the title and description as Front\CollectionPages hands them to the item page's head
            'serp' => ['serpTitle' => \Talea\Core\SeoTitle::compose($settings, 'item', $p['seo_title'] !== '' ? (string) $p['seo_title'] : (string) $p['name'], $p['seo_title'] !== '', ['category' => (string) $k['name']]),
                'serpDescription' => (string) $p['description'], 'serpAddress' => $host . '/' . $address,
                'dir' => Language::dirAttribute(Language::ofContent($settings, (string) $p['language']))],
            'seoCheck' => $k['detail'] && $p['item_id'] > 0 && \Talea\Core\ContentCheck::enabled($this->app->settings()) ? \Talea\Core\SeoScore::panel($this->app, 'item', $this->db->one('SELECT * FROM {collection_items} WHERE item_id = ?', [(int) $p['item_id']])) : null,
            'seats' => $p['item_id'] > 0 && ($course = Registrations::course($k, $p)) !== null ? ['course' => $course] + Registrations::counts($this->db, (int) $p['item_id']) : null,
            'versions' => $p['item_id'] > 0 ? \Talea\Builder\Publisher::listAll($this->db, ['part' => 'item:' . (int) $p['item_id']]) : [],
            // the audit trail of a notice (2.11, Core\Notices), newest first
            'noticeLog' => $p['item_id'] > 0 && Notices::isNotices($k) ? array_reverse(Notices::entries($this->db, (int) $k['collection_id'], (int) $p['item_id'])) : []]);
    }

    protected function actionSaveItem(): Response
    {
        $r = $this->request;
        $k = $r->isPost() ? KolekceObsahu::byId($this->db, $this->idParam('collection_id')) : null;
        if ($k === null) {
            return $this->back();
        }
        if (($guard = $this->refuseUnknownId('item_id', 'The item does not exist.', 'collection_items')) !== null) {
            return $guard;
        }
        $idp = $this->idParam('item_id', 'collection_items');
        $name = mb_substr(trim($r->post('name')), 0, 200);
        if ($name === '') {
            return $this->back('The item needs a name.', 'item', ['id' => $k['public_id'], 'item' => $this->publicId($idp, 'collection_items')], 'error');
        }
        $errors = [];
        $data = KolekceObsahu::sanitizeData($k['fields'], is_array($_POST['data'] ?? null) ? $_POST['data'] : [], $errors);
        // the browser asks for a required field first; formatted text and a gallery have no input of their own to ask with
        if (($missing = KolekceObsahu::missingRequired($k['fields'], $data)) !== []) {
            return $this->back(t('Fill in the required fields: %s', implode(', ', $missing)), 'item', $idp > 0 ? ['id' => $k['public_id'], 'item' => $this->publicId($idp, 'collection_items')] : ['id' => $k['public_id']], 'error');
        }
        $seo = slugify($r->post('slug') !== '' ? $r->post('slug') : $name, 150);
        // the slug is unique within a language: a translation of the item can have the same one (/compare/wordpress, /de/compare/wordpress)
        $language = Language::column($this->app->settings(), $r->post('language'));
        $seo = \Talea\Core\Slug::makeUnique($seo, fn (string $a): bool => $this->db->value('SELECT item_id FROM {collection_items} WHERE collection_id = ? AND language = ? AND slug = ? AND item_id <> ?', [$k['collection_id'], $language, $a, $idp]) !== null);
        $row = ['collection_id' => $k['collection_id'], 'name' => $name, 'slug' => $seo, 'data' => (string) json_encode($data, JSON_UNESCAPED_UNICODE),
            'sort_order' => max(-9999, min(9999, $r->postInt('sort_order'))), 'language' => $language, 'updated_at' => date('Y-m-d H:i:s'),
            'valid_until' => \Talea\Core\Validity::date($r->post('valid_until')), 'review_by' => \Talea\Core\Validity::date($r->post('review_by'))] // 2.10
            + KolekceObsahu::pageFields($_POST, $r->postBool('visible')) + \Talea\Core\SeoFields::fromInput($_POST);
        // a notice that is (or was) on the board cannot be hidden (2.11, Core\Notices)
        if (Notices::refusesHiding($k, $data, (bool) $row['visible'])) {
            return $this->back(t(Notices::REFUSAL_HIDE), 'item', ['id' => $k['public_id'], 'item' => $this->publicId($idp, 'collection_items')], 'error');
        }
        $previous = $idp > 0 ? $this->db->one('SELECT * FROM {collection_items} WHERE item_id = ? AND collection_id = ? AND deleted_at IS NULL', [$idp, $k['collection_id']]) : null;
        if ($previous !== null) {
            KolekceObsahu::saveVersion($this->app, $previous, $row);
            $this->db->update('collection_items', $row, ['item_id' => $idp]);
        } else {
            $idp = $this->db->insert('collection_items', $row + ['created_at' => date('Y-m-d H:i:s')]);
            \Talea\Core\Slug::fromId($this->db, 'collection_items', $idp); // a name with nothing to transliterate: item-<id>
        }
        \Talea\Core\Members::saveFromForm($this->app, 'item', $idp);
        Notices::recordSave($this->app, $k, $previous, $row, $idp);
        \Talea\Core\SeoScore::refresh($this->app, 'item', $idp);
        if (Calendar::fields($k) !== null) {
            Registrations::refill($this->app, [$idp]); // a raised capacity frees seats for the waiting list
        }
        \Talea\Front\Cache::clear();
        if ($errors !== []) {
            return $this->back(t('The item is saved, but these fields had an invalid value and were left empty: %s', implode(', ', $errors)), 'item', ['id' => $k['public_id'], 'item' => $this->publicId($idp, 'collection_items')], 'error');
        }

        // "Save and continue" stays in the editor
        return $r->post('after_save') === 'stay'
            ? $this->back('The item was saved.', 'item', ['id' => $k['public_id'], 'item' => $this->publicId($idp, 'collection_items')])
            : $this->back('The item was saved.', 'items', ['id' => $k['public_id']]);
    }

    /** Actions the list does with ticked items (2.14): show, hide, language version, trash. A notice board keeps its notices (2.11). */
    protected function actionBulkItems(): Response
    {
        $idk = $this->idParam('collection_id');
        $k = $this->request->isPost() ? KolekceObsahu::byId($this->db, $idk) : null;
        $action = $this->request->post('bulk');
        if ($k === null || !in_array($action, ['visible', 'hide', 'language', 'trash'], true)) {
            return $this->back('Unknown action.', 'items', ['id' => $this->publicId($idk)], 'error');
        }
        if (Notices::isNotices($k) && $action !== 'visible') {
            return $this->back(t($action === 'trash' ? Notices::REFUSAL_DELETE : Notices::REFUSAL_HIDE), 'items', ['id' => $this->publicId($idk)], 'error');
        }
        $language = Language::column($this->app->settings(), $this->request->post('language'));
        $done = 0;
        $skipped = 0;
        foreach (array_unique(array_map(fn (string $uuid): int => $this->db->internalId('collection_items', $uuid), $this->request->postList('selected'))) as $idp) {
            $p = $this->db->one('SELECT item_id, name, slug, language FROM {collection_items} WHERE item_id = ? AND collection_id = ? AND deleted_at IS NULL', [$idp, $idk]);
            // the address is unique within a language: an item whose address the target language already has stays where it is
            if ($p === null || ($action === 'language' && $this->db->value('SELECT 1 FROM {collection_items} WHERE collection_id = ? AND language = ? AND slug = ? AND item_id <> ?', [$idk, $language, $p['slug'], $idp]) !== null)) {
                $skipped++;
                continue;
            }
            $now = date('Y-m-d H:i:s');
            match ($action) {
                'visible' => $this->db->update('collection_items', ['visible' => 1, 'publish_at' => null, 'updated_at' => $now], ['item_id' => $idp]),
                'hide' => $this->db->update('collection_items', ['visible' => 0, 'updated_at' => $now], ['item_id' => $idp]),
                'language' => $this->db->update('collection_items', ['language' => $language, 'updated_at' => $now], ['item_id' => $idp]),
                default => self::trashItem($this->db, $idp, $idk),
            };
            \Talea\Admin\ChangeLog::write($this->app, 'collections', 'bulk ' . ['visible' => 'shown', 'hide' => 'hidden', 'language' => 'language ' . ($language ?: 'default'), 'trash' => 'moved to trash'][$action], mb_substr($k['slug'] . ': ' . $p['name'], 0, 80));
            $done++;
        }
        if ($done > 0) {
            \Talea\Front\Cache::clear();
        }
        $message = match ($action) {
            'visible' => t('Items published: %d.', $done), 'hide' => t('Items hidden: %d.', $done),
            'language' => t('Items moved to the language version: %d.', $done), default => t('Items moved to the trash: %d.', $done),
        };

        return $this->back($message . ($skipped > 0 ? ' ' . t('Skipped: %d (the address is taken in that language).', $skipped) : ''), 'items', ['id' => $this->publicId($idk)], $done > 0 ? 'ok' : 'error');
    }

    /** E-mail signature of a person (2.10, Builder\EmailSignature): the preview, a copy button, the plain text and where to paste it. */
    protected function actionSignature(): Response
    {
        $k = KolekceObsahu::byId($this->db, $this->idParam());
        $p = $k === null ? null : $this->db->one('SELECT * FROM {collection_items} WHERE item_id = ? AND collection_id = ? AND deleted_at IS NULL', [$this->idParam('item', 'collection_items'), $k['collection_id']]);
        if ($k === null || $p === null) {
            return $this->error('The item does not exist.', 404);
        }
        $p['data'] = json_decode((string) $p['data'], true) ?: [];

        return $this->view('signature', t('E-mail signature: %s', $p['name']), ['k' => $k, 'p' => $p, 'signature' => \Talea\Builder\EmailSignature::forItem($this->app, $k, $p)]);
    }

    /** Copy of an item (hidden, with a free slug) – a quick start for a similar reference, team member, product. */
    protected function actionDuplicateItem(): Response
    {
        $idk = $this->idParam('collection_id');
        $p = $this->request->isPost() ? $this->db->one('SELECT * FROM {collection_items} WHERE item_id = ? AND collection_id = ? AND deleted_at IS NULL', [$this->idParam('item_id', 'collection_items'), $idk]) : null;
        if ($p === null) {
            return $this->back('', 'items', ['id' => $this->publicId($idk)]);
        }
        $seo = \Talea\Core\Slug::makeUnique($p['slug'] . '-kopie', fn (string $a): bool => $this->db->value('SELECT 1 FROM {collection_items} WHERE collection_id = ? AND language = ? AND slug = ?', [$idk, $p['language'], $a]) !== null);
        $copy = ['collection_id' => $idk, 'name' => mb_substr(t('%s (copy)', $p['name']), 0, 200), 'slug' => $seo, 'data' => $p['data'],
            'seo_title' => $p['seo_title'], 'description' => $p['description'], 'image' => $p['image'], 'noindex' => $p['noindex'],
            'sort_order' => $p['sort_order'], 'visible' => 0, 'language' => $p['language'], 'created_at' => date('Y-m-d H:i:s')];
        $id = $this->db->insert('collection_items', $copy);
        \Talea\Core\Members::copyGroups($this->db, 'item', (int) $p['item_id'], $id);
        Notices::recordSave($this->app, (array) KolekceObsahu::byId($this->db, $idk), null, $copy, $id);

        return $this->back('The copy of the item is hidden – edit it and publish it.', 'item', ['id' => $this->publicId($idk), 'item' => $this->publicId($id, 'collection_items')]);
    }

    /** An earlier version of the item back (1.9); the current one goes to the history first. */
    protected function actionRestoreItemVersion(): Response
    {
        $idk = $this->idParam('collection_id');
        $idp = $this->idParam('item_id', 'collection_items');
        $item = $this->request->isPost() ? $this->db->one('SELECT * FROM {collection_items} WHERE item_id = ? AND collection_id = ? AND deleted_at IS NULL', [$idp, $idk]) : null;
        $version = $item === null ? null : KolekceObsahu::loadVersion($this->db, $idp, $this->request->postInt('revision_id'));
        if ($version === null) {
            return $this->back('The version does not exist.', 'items', ['id' => $this->publicId($idk)], 'error');
        }
        // the address of a restored version may be taken by another item in the meantime
        if ($this->db->value('SELECT 1 FROM {collection_items} WHERE collection_id = ? AND language = ? AND slug = ? AND item_id <> ?', [$idk, $item['language'], $version['slug'] ?? '', $idp]) !== null) {
            unset($version['slug']);
        }
        KolekceObsahu::saveVersion($this->app, $item, $version);
        $this->db->update('collection_items', $version + ['updated_at' => date('Y-m-d H:i:s')], ['item_id' => $idp]);
        \Talea\Core\SeoScore::refresh($this->app, 'item', $idp);
        Notices::recordSave($this->app, (array) KolekceObsahu::byId($this->db, $idk), $item, $version, $idp);
        \Talea\Front\Cache::clear();

        return $this->back('The earlier version of the item is back; the one before it is in the history.', 'item', ['id' => $this->publicId($idk), 'item' => $this->publicId($idp, 'collection_items')]);
    }

    /** To the trash: the item disappears from the site at once and can be restored for 30 days. */
    protected function actionDeleteItem(): Response
    {
        $idk = $this->idParam('collection_id');
        if ($this->request->isPost()) {
            if ($this->isNoticeBoard($idk)) {
                return $this->back(t(Notices::REFUSAL_DELETE), 'items', ['id' => $this->publicId($idk)], 'error'); // the permanent archive (2.11)
            }
            self::trashItem($this->db, $this->idParam('item_id', 'collection_items'), $idk);
        }

        return $this->back('The item is in the trash – it is no longer on the site; you can restore it for 30 days.', 'items', ['id' => $this->publicId($idk)]);
    }

    /** Whether a collection is an official notice board (2.11, Core\Notices) – its notices are never deleted. */
    private function isNoticeBoard(int $idk): bool
    {
        return Notices::isNotices((array) KolekceObsahu::byId($this->db, $idk));
    }

    /** The whole audit trail of a notice board as CSV (administrators, 2.11, Core\Notices). */
    protected function actionNoticeLog(): Response
    {
        if (($refusal = $this->admin()) !== null) {
            return $refusal;
        }
        $k = KolekceObsahu::byId($this->db, $this->idParam());
        if ($k === null || !Notices::isNotices($k) || !\Talea\Core\Extensions::isEnabled($this->app->settings(), 'notice_board')) {
            return $this->error('The collection is not an official notice board.', 404);
        }
        \Talea\Admin\ChangeLog::write($this->app, 'collections', 'notice log CSV', $k['slug']);

        return new Response(Notices::csv($this->db, $k), 200, ['Content-Type' => 'text/csv; charset=utf-8', 'Content-Disposition' => 'attachment; filename="' . $k['slug'] . '-log-' . date('Y-m-d') . '.csv"']);
    }

    /** Back from the trash – hidden, so it does not appear on the site before it is checked. */
    protected function actionRestoreItem(): Response
    {
        $idk = $this->idParam('collection_id');
        if ($this->request->isPost() && self::restoreItem($this->db, $idp = $this->idParam('item_id', 'collection_items'), $idk)) {
            \Talea\Core\SeoScore::refresh($this->app, 'item', $idp);
        }

        return $this->back('The item was restored as hidden.', 'items', ['id' => $this->publicId($idk)]);
    }

    protected function actionDeleteItemPermanently(): Response
    {
        $idk = $this->idParam('collection_id');
        if ($this->request->isPost()) {
            if ($this->isNoticeBoard($idk)) {
                return $this->back(t(Notices::REFUSAL_DELETE), 'items', ['id' => $this->publicId($idk), 'status' => 'trash'], 'error');
            }
            $this->db->run('DELETE FROM {collection_items} WHERE item_id = ? AND collection_id = ? AND deleted_at IS NOT NULL', [$this->idParam('item_id', 'collection_items'), $idk]);
        }

        return $this->back('The item was deleted permanently.', 'items', ['id' => $this->publicId($idk), 'status' => 'trash']);
    }

    /** Moves an item to the trash (admin and MCP); returns whether it was there to move. */
    public static function trashItem(\Talea\Core\Db $db, int $idp, int $idk): bool
    {
        $moved = $db->run('UPDATE {collection_items} SET deleted_at = NOW(), visible = FALSE WHERE item_id = ? AND collection_id = ? AND deleted_at IS NULL', [$idp, $idk])->rowCount() > 0;
        \Talea\Front\Cache::clear();

        return $moved;
    }

    public static function restoreItem(\Talea\Core\Db $db, int $idp, int $idk): bool
    {
        return $db->run('UPDATE {collection_items} SET deleted_at = NULL WHERE item_id = ? AND collection_id = ? AND deleted_at IS NOT NULL', [$idp, $idk])->rowCount() > 0;
    }

    /** Items longer than 30 days in the trash are deleted permanently (with pages and news, on an admin visit). */
    public static function emptyTrash(\Talea\Core\Db $db): void
    {
        $db->run('DELETE FROM {collection_items} WHERE deleted_at < NOW() - INTERVAL 30 DAY');
    }

    /* ---------- item template in the builder (administrator) ---------- */

    protected function actionBuilder(): Response
    {
        if (($refusal = $this->admin()) !== null) {
            return $refusal;
        }
        $k = $this->template();
        if ($k !== null && $k['build'] === null && $k['build_draft'] === null) {
            KolekceObsahu::writeTemplate($this->db, $k, ['build_draft' => KolekceObsahu::initialTemplateDraft($this->db, $k), 'updated_at' => date('Y-m-d H:i:s')]);
        }

        return $this->openBuilder();
    }

    protected function loadBuildTarget(): ?array
    {
        if (!$this->app->auth()->isAdmin()) {
            return null;
        }
        $k = $this->template();
        if ($k === null) {
            return null;
        }
        $language = $k['template_language'];

        return [
            'row' => $k, 'build' => $k['build'], 'draft' => $k['build_draft'], 'language' => Language::ofContent($this->app->settings(), $language),
            'title' => t('Detail: %s', $k['name']) . ($language !== '' ? ' (' . strtoupper($language) . ')' : ''), 'revisions' => ['part' => KolekceObsahu::templateKey($k)],
            'params' => ['id' => $k['public_id']] + ($language !== '' ? ['language' => $language] : []),
        ];
    }

    /** Collection with the template of the language from the URL (?language=de; without it, or with a language the site does not have, the default language). */
    private function template(): ?array
    {
        $k = KolekceObsahu::byId($this->db, $this->idParam('id', null, true));
        $language = $this->request->get('language');

        return $k === null ? null : KolekceObsahu::inLanguage($this->db, $k, in_array($language, Language::additional($this->app->settings()), true) ? $language : '');
    }

    protected function saveDraft(array $target, ?string $draft): void
    {
        KolekceObsahu::writeTemplate($this->db, $target['row'], ['build_draft' => $draft]);
    }

    protected function publishTarget(array $target): void
    {
        Publisher::collection($this->app, $target['row']);
    }

    protected function describeTarget(array $target): array
    {
        $k = $target['row'];
        $language = $k['template_language'];
        // preview on the first item in the template's language (an additional language has URLs /<language>/…)
        $seo = $this->db->value('SELECT slug FROM {collection_items} WHERE collection_id = ? AND language = ? AND visible = TRUE AND deleted_at IS NULL ORDER BY sort_order, name LIMIT 1', [$k['collection_id'], $language]);
        $url = $this->app->url(($language !== '' ? $language . '/' : '') . $k['slug'] . '/' . ($seo ?? '_sample'));

        return [
            'url' => $url, 'preview' => $url . '?build=draft&editor=1', 'visible' => (bool) $k['detail'], 'parts' => false,
            'back' => ['url' => $this->url('items', ['id' => $k['public_id']]), 'text' => $k['name']], 'settings' => $this->url('edit', ['id' => $k['public_id']]), 'settings_text' => t('Collection fields and settings'),
            'collection' => ['slug' => $k['slug'], 'name' => $k['name'], 'fields' => $k['fields'], 'detail' => (bool) $k['detail']],
            'signature' => KolekceObsahu::templateKey($k),
        ];
    }

    private function admin(): ?Response
    {
        return $this->app->auth()->isAdmin() ? null : $this->error('Only the site administrator can change the collection definition and detail template.', 403);
    }
}
