<?php

declare(strict_types=1);

namespace Kaleta\Mcp\Handlers;

use Kaleta\Admin\Modules\Media;
use Kaleta\Admin\Modules\Categories;
use Kaleta\Admin\Modules\Pages;
use Kaleta\Core\App;
use Kaleta\Core\Language;
use Kaleta\Core\Notices;
use Kaleta\Front\SiteIdentity;
use Kaleta\Builder\SiteParts;
use Kaleta\Builder\DesignSystem;
use Kaleta\Builder\Library;
use Kaleta\Builder\Collections;
use Kaleta\Builder\Publisher;
use Kaleta\Builder\Build;
use Kaleta\Builder\HtmlConverter;

/**
 * MCP tools: collections (one method per tool, see Mcp\Catalog). Part of Mcp\Tools.
 *
 * @phpstan-ignore trait.unused
 */
trait CollectionTools
{
    /**
     * Collection field types as the tools name them (choice) and as the collections store them (radio).
     *
     * @param array<int, mixed> $fields
     * @return array<int, mixed>
     */
    private static function fieldTypes(array $fields, bool $forStorage): array
    {
        [$from, $to] = $forStorage ? ['choice', 'radio'] : ['radio', 'choice'];

        return array_map(fn (mixed $f): mixed => is_array($f) && ($f['type'] ?? null) === $from ? array_replace($f, ['type' => $to]) : $f, $fields);
    }

    /** list_collections */
    private function toolListCollections(string $name, array $a): mixed
    {
        $db = $this->app->db();

        return array_map(fn (array $k): array => ['collection' => $k['slug'], 'name' => $k['name'], 'item_pages' => (bool) $k['detail'], 'redirect_hidden_to' => (string) ($k['hidden_redirect'] ?? ''), 'fields' => self::fieldTypes($k['fields'], false),
            'preset' => (string) ($k['preset'] ?? ''),
            'items' => (int) $db->value('SELECT COUNT(*) FROM {collection_items} WHERE collection_id = ? AND deleted_at IS NULL', [$k['collection_id']])]
            + (($sd = \Kaleta\Builder\CollectionSchema::of($k)) !== null ? ['structured_data' => ['type' => $sd['type'], 'fields' => $sd['fields'], 'currency' => $sd['currency']]] : []), Collections::all($db));
    }

    /** list_collection_presets (2.11) */
    private function toolListCollectionPresets(string $name, array $a): mixed
    {
        $existing = $this->app->db()->pairs("SELECT preset, slug FROM {collections} WHERE preset <> '' ORDER BY collection_id DESC");

        return ['presets' => array_map(fn (array $p): array => $p + ['existing_collection' => $existing[$p['preset']] ?? ''], \Kaleta\Builder\Presets::describe()),
            'next' => 'create_collection {"preset":"<key>","name":"…"} creates one; then add items with save_collection_item and put a Collection list on a page.'];
    }

    /** create_collection */
    private function toolCreateCollection(string $name, array $a): mixed
    {
        $auth = $this->app->auth();
        $db = $this->app->db();
        $adminOnly = function () use ($auth): void {
            if (!$auth->isAdmin()) {
                throw new \DomainException('Only the site administrator can use this tool.');
            }
        };

        $adminOnly();
        if (($a['preset'] ?? '') !== '') {
            // a ready-made collection (2.10 people, 2.11 Builder\Presets)
            [$id, $pageId, $extraPages] = \Kaleta\Builder\Presets::createWithPage($this->app, (string) $a['preset'], (string) ($a['name'] ?? '')) ?? [null, null, []];
            if ($id === null) {
                throw new \InvalidArgumentException('Unknown preset – use one of: ' . implode(', ', array_keys(\Kaleta\Builder\Presets::all())) . ' (list_collection_presets).');
            }
            $created = (array) Collections::byId($db, $id);
            $preset = (array) \Kaleta\Builder\Presets::get((string) $a['preset']);

            return ['collection' => $created['slug'], 'name' => $created['name'], 'fields' => self::fieldTypes($created['fields'], false), 'redirect_hidden_to' => $created['hidden_redirect'], 'preset' => (string) $a['preset'],
                'list_page' => $pageId !== null ? ['id' => $pageId, 'path' => '/' . $created['slug'], 'visible' => false, 'note' => 'A hidden page listing the items; add an intro, then publish it with update_page visible=true when the user wants.'] : null]
                // further hidden list pages of the preset (2.11): a notice board's archive
                + ($extraPages !== [] ? ['more_pages' => array_map(fn (array $p): array => $p + ['visible' => false], $extraPages)] : [])
                + ['how_to_use' => (string) ($preset['claude'] ?? '')];
        }
        $collectionName = mb_substr(trim((string) ($a['name'] ?? '')), 0, 100);
        if ($collectionName === '') {
            throw new \InvalidArgumentException('The collection name is missing.');
        }
        $seo = $this->availableCollectionSlug((string) ($a['slug'] ?? '') !== '' ? (string) $a['slug'] : $collectionName, 0);
        $field = Collections::sanitizeFields(self::fieldTypes(is_array($a['fields'] ?? null) ? $a['fields'] : [], true));
        $redirect = Collections::cleanRedirect((string) ($a['redirect_hidden_to'] ?? ''));
        if ($redirect === null) {
            throw new \InvalidArgumentException('redirect_hidden_to must be an address on the site (/team) or https://…');
        }
        $db->insert('collections', ['name' => $collectionName, 'slug' => $seo, 'detail' => empty($a['item_pages']) ? 0 : 1, 'hidden_redirect' => $redirect, 'fields' => (string) json_encode($field, JSON_UNESCAPED_UNICODE), 'updated_at' => date('Y-m-d H:i:s'),
            'schema_org' => self::collectionSchema($a['structured_data'] ?? null, $field)]);

        return ['collection' => $seo, 'fields' => self::fieldTypes($field, false)] + ($redirect !== '' ? ['redirect_hidden_to' => $redirect] : []);
    }

    /** update_collection */
    private function toolUpdateCollection(string $name, array $a): mixed
    {
        $auth = $this->app->auth();
        $db = $this->app->db();
        $adminOnly = function () use ($auth): void {
            if (!$auth->isAdmin()) {
                throw new \DomainException('Only the site administrator can use this tool.');
            }
        };

        $adminOnly();
        $collection = $this->collection((string) ($a['collection'] ?? ''));
        $changes = ['updated_at' => date('Y-m-d H:i:s')];
        if (isset($a['name']) && trim((string) $a['name']) !== '') {
            $changes['name'] = mb_substr(trim((string) $a['name']), 0, 100);
        }
        if (isset($a['slug']) && trim((string) $a['slug']) !== '') {
            $changes['slug'] = $this->availableCollectionSlug((string) $a['slug'], (int) $collection['collection_id']);
        }
        if (array_key_exists('item_pages', $a)) {
            $changes['detail'] = empty($a['item_pages']) ? 0 : 1;
        }
        if (array_key_exists('redirect_hidden_to', $a)) {
            $changes['hidden_redirect'] = Collections::cleanRedirect((string) $a['redirect_hidden_to'])
                ?? throw new \InvalidArgumentException('redirect_hidden_to must be an address on the site (/team) or https://…');
        }
        if (is_array($a['fields'] ?? null)) {
            $changes['fields'] = (string) json_encode(Collections::sanitizeFields(self::fieldTypes($a['fields'], true)), JSON_UNESCAPED_UNICODE);
        }
        if (array_key_exists('structured_data', $a)) {
            $changes['schema_org'] = self::collectionSchema($a['structured_data'], json_decode($changes['fields'] ?? '', true) ?: $collection['fields']);
        }
        $db->update('collections', $changes, ['collection_id' => $collection['collection_id']]);
        \Kaleta\Front\Cache::clear();
        $newVersion = (array) $db->one('SELECT * FROM {collections} WHERE collection_id = ?', [$collection['collection_id']]);

        return ['collection' => $newVersion['slug'], 'name' => $newVersion['name'], 'item_pages' => (bool) $newVersion['detail'], 'redirect_hidden_to' => (string) $newVersion['hidden_redirect'], 'fields' => self::fieldTypes(json_decode((string) $newVersion['fields'], true) ?: [], false)];
    }

    /** delete_collection */
    private function toolDeleteCollection(string $name, array $a): mixed
    {
        $auth = $this->app->auth();
        $db = $this->app->db();
        $need = function (bool $allowed, string $message): void {
            if (!$allowed) {
                throw new \DomainException($message);
            }
        };
        $collection = function () use ($a, $db): array {
            return \Kaleta\Builder\Collections::bySlug($db, (string) ($a['collection'] ?? '')) ?? throw new \InvalidArgumentException('The collection does not exist. Use list_collections.');
        };

        $need($auth->isAdmin(), 'Collections can be deleted only by an administrator.');
        $k = $collection();
        $notices = Notices::count($db, $k); // an official notice board keeps its notices for good (2.11)
        $need($notices === 0, sprintf(Notices::REFUSAL_COLLECTION, $notices));
        $db->delete('collections', ['collection_id' => $k['collection_id']]); // items and templates go with it (foreign keys)
        \Kaleta\Front\Cache::clear();

        return ['deleted' => $k['slug']];
    }

    /** list_collection_items */
    private function toolListCollectionItems(string $name, array $a): mixed
    {
        $auth = $this->app->auth();
        $db = $this->app->db();

        $collection = $this->collection((string) ($a['collection'] ?? ''));

        $whereParts = ['collection_id = ?', 'deleted_at IS NULL']; // the trash is in list_trash
        $args = [$collection['collection_id']];
        if (isset($a['language']) && is_string($a['language'])) {
            $whereParts[] = 'language = ?';
            $args[] = $a['language'];
        }
        if (!empty($a['visible_only']) || !$auth->hasModule('collections')) {
            $whereParts[] = 'visible = 1'; // without the Collections section only published items
        }
        if (is_string($a['search'] ?? null) && trim($a['search']) !== '') {
            $whereParts[] = '(name LIKE ? OR data LIKE ?)';
            $pattern = '%' . addcslashes(mb_substr(trim($a['search']), 0, 100), '%_\\') . '%';
            array_push($args, $pattern, $pattern);
        }
        $rows = $db->all('SELECT * FROM {collection_items} WHERE ' . implode(' AND ', $whereParts) . ' ORDER BY sort_order, name LIMIT 5000', $args);
        if (is_string($a['field'] ?? null) && $a['field'] !== '') {
            // exact match of a field value (JSON in the database – filtered here, without depending on the MySQL version)
            $rows = array_values(array_filter($rows, fn (array $r): bool => (string) ((json_decode((string) $r['data'], true) ?: [])[$a['field']] ?? '') === (string) ($a['value'] ?? '')));
        }
        $pageNumber = max(1, (int) ($a['page'] ?? 1));
        // an events calendar (2.11): how many registered for the next occurrence and whether registration is open – counts only
        $calendar = \Kaleta\Core\Calendar::fields($collection) !== null && $auth->hasModule('enquiries');
        $registration = function (array $r) use ($calendar, $collection, $db): array {
            if (!$calendar) {
                return [];
            }
            $r['data'] = json_decode((string) $r['data'], true) ?: [];
            $v = \Kaleta\Core\Calendar::values($db, $collection, $r, fn (string $p): string => $p, date('Y-m-d H:i'));

            return ['registration' => ['state' => $v['_registration'][0], 'registered' => \Kaleta\Core\Calendar::registered($db, $collection, $r, (array) \Kaleta\Core\Calendar::fields($collection)),
                'places_left' => $v['places_left'][0] !== '' ? (int) $v['places_left'][0] : null]];
        };

        // a document library (2.11): how often each document was downloaded, and the stable address of its file
        $downloads = \Kaleta\Core\Documents::fileField($collection) !== null ? \Kaleta\Core\Documents::counts($db, (int) $collection['collection_id']) : null;
        $documentOutput = fn (array $r): array => $downloads === null ? [] : ['downloads' => ['last_30_days' => $downloads[(int) $r['item_id']][0] ?? 0, 'total' => $downloads[(int) $r['item_id']][1] ?? 0]]
            + ($collection['detail'] ? ['latest_url' => $this->app->request->origin() . $this->app->url(($r['language'] !== '' ? $r['language'] . '/' : '') . $collection['slug'] . '/' . $r['slug'] . '/latest')] : []);

        return ['total' => count($rows), 'page' => $pageNumber, 'pages' => max(1, (int) ceil(count($rows) / 50)), 'items' => array_map(fn (array $r): array => ['id' => (int) $r['item_id'], 'name' => $r['name'], 'slug' => $r['slug'], 'order' => (int) $r['sort_order'], 'visible' => (bool) $r['visible'],
            'language' => $r['language'], 'values' => json_decode((string) $r['data'], true) ?: new \stdClass()]
            + array_filter(['seo_title' => $r['seo_title'], 'description' => $r['description'], 'image' => $r['image'], 'noindex' => (bool) $r['noindex'], 'publish_at' => $r['publish_at']]) + self::validityOutput($r) + $registration($r) + $documentOutput($r), array_slice($rows, ($pageNumber - 1) * 50, 50))];
    }

    /** save_collection_item */
    private function toolSaveCollectionItem(string $name, array $a): mixed
    {
        $auth = $this->app->auth();
        $db = $this->app->db();
        $siteSettings = $this->app->settings();

        if (!$auth->hasModule('collections')) {
            throw new \DomainException('Only editors and administrators can change collections.');
        }
        $collection = $this->collection((string) ($a['collection'] ?? ''));
        $previous = isset($a['id']) ? $db->one('SELECT * FROM {collection_items} WHERE item_id = ? AND collection_id = ?', [(int) $a['id'], $collection['collection_id']]) : null;
        if (isset($a['id']) && $previous === null) {
            throw new \InvalidArgumentException('The item is not in the collection. Use list_collection_items.');
        }
        if ($previous !== null && $previous['deleted_at'] !== null) {
            // saving would put it back on the site while it still waits in the trash to be deleted
            throw new \InvalidArgumentException('The item is in the trash. Bring it back with restore_from_trash first.');
        }
        // a drafts-only connection (3.2) creates hidden items and changes hidden ones – what visitors see stays a person's call
        $draftsOnly = $auth->draftsOnly();
        if ($draftsOnly) {
            $proposeInstead = ' Write the change you propose (the item and its values) into the note of the request or the summary of the run for a person to apply – or the user connects Claude with full access.';
            if ($previous !== null && ((int) $previous['visible'] === 1 || $previous['publish_at'] !== null)) {
                throw new \DomainException('This connection can only save drafts, and this item is on the site (or scheduled to be published), so it cannot be changed here.' . $proposeInstead);
            }
            if ($previous !== null && !empty($a['visible'])) {
                throw new \DomainException('This connection can only save drafts: it cannot make an item visible.' . $proposeInstead);
            }
            if (is_string($a['publish_at'] ?? null) && trim($a['publish_at']) !== '') {
                throw new \DomainException('This connection can only save drafts: it cannot schedule an item to be published.' . $proposeInstead);
            }
            unset($a['visible']); // a new item stays hidden whatever visible says
        }
        // on update the name is optional – the current one stays
        $itemName = mb_substr(trim((string) ($a['name'] ?? $previous['name'] ?? '')), 0, 200);
        if ($itemName === '') {
            throw new \InvalidArgumentException('The item needs a name.');
        }
        if (isset($a['values']) && !is_array($a['values'])) {
            throw new \InvalidArgumentException('The data parameter must be an object {"key":"value"} with the collection fields.');
        }
        $errors = [];
        $data = Collections::sanitizeData($collection['fields'], (is_array($a['values'] ?? null) ? $a['values'] : []) + (json_decode((string) ($previous['data'] ?? '{}'), true) ?: []), $errors);
        $url = trim((string) ($a['slug'] ?? ''));
        $seo = $url !== '' ? slugify($url, 150) : ($previous['slug'] ?? slugify($itemName, 150));
        if ($seo === '' || $seo === '_sample') {
            throw new \InvalidArgumentException('The item address is not valid.');
        }
        // the slug is unique within a language: an item's translation should have the same one (the language
        // switcher and hreflang find it by the slug)
        $itemLanguage = array_key_exists('language', $a) ? Language::column($siteSettings, (string) $a['language']) : (string) ($previous['language'] ?? '');
        $seo = \Kaleta\Core\Slug::makeUnique($seo, fn (string $a): bool => $db->value('SELECT item_id FROM {collection_items} WHERE collection_id = ? AND language = ? AND slug = ? AND item_id <> ?', [$collection['collection_id'], $itemLanguage, $a, (int) ($previous['item_id'] ?? 0)]) !== null);
        $row = ['name' => $itemName, 'slug' => $seo, 'data' => (string) json_encode($data, JSON_UNESCAPED_UNICODE), 'updated_at' => date('Y-m-d H:i:s')]
            + (array_key_exists('language', $a) ? ['language' => $itemLanguage] : [])
            + (array_key_exists('order', $a) ? ['sort_order' => max(-9999, min(9999, (int) $a['order']))] : [])
            + (array_key_exists('visible', $a) ? ['visible' => (int) (bool) $a['visible']] : []);
        // SEO and scheduled publishing (1.9): only what was sent changes, the rest stays
        $pageKeys = ['seo_title' => 'seo_title', 'description' => 'description', 'share_image' => 'image', 'noindex' => 'noindex', 'publish_at' => 'publish_at'];
        if (array_intersect(array_keys($pageKeys), array_keys($a)) !== []) {
            $input = [];
            foreach ($pageKeys as $field => $column) {
                if (array_key_exists($field, $a)) {
                    $input[$column] = $a[$field];
                }
            }
            $fields = Collections::pageFields($input + ($previous ?? []), (bool) ($row['visible'] ?? $previous['visible'] ?? 0));
            $row = array_intersect_key($fields, $input) + $row;
            if (array_key_exists('publish_at', $a)) {
                $row['publish_at'] = $fields['publish_at'];
                $row['visible'] = $fields['visible'];
            }
        }
        $row += self::validityDates($a); // true until and review by (2.10)
        // a notice that is (or was) on the board cannot be hidden (2.11, Core\Notices)
        if (Notices::refusesHiding($collection, $data, (bool) ($row['visible'] ?? $previous['visible'] ?? 0))) {
            throw new \DomainException(Notices::REFUSAL_HIDE . ($draftsOnly ? ' This connection can only save hidden drafts: give a posting date in the future, or propose the notice in the note for a person.' : ' Pass visible true, or a posting date in the future.'));
        }
        if ($previous !== null) {
            Collections::saveVersion($this->app, $previous, $row);
            $db->update('collection_items', $row, ['item_id' => $previous['item_id']]);
            $itemId = (int) $previous['item_id'];
        } else {
            $row += ['collection_id' => $collection['collection_id'], 'created_at' => date('Y-m-d H:i:s'), 'visible' => 0];
            $itemId = $db->insert('collection_items', $row);
        }
        Notices::recordSave($this->app, $collection, $previous, $row, $itemId); // the audit trail of a notice board (2.11)
        \Kaleta\Front\Cache::clear(); // item pages, lists, the sitemap and llms.txt show the change at once (as after a save in the admin)

        // a key the collection does not have (a typo, "name" in values instead of the parameter) would otherwise be silently dropped
        $unknownKeys = array_values(array_diff(array_keys(is_array($a['values'] ?? null) ? $a['values'] : []), array_column($collection['fields'], 'key')));

        return ['id' => $itemId, 'collection' => $collection['slug'], 'invalid_fields' => array_keys($errors)] + ($unknownKeys !== [] ? ['unknown_keys' => $unknownKeys] : []) + self::validityOutput($row)
            + ($draftsOnly ? ['visible' => false, 'next' => 'Saved hidden: a person reviews the item and makes it visible (Collections, or Waiting for you on the dashboard).'] : []) + [
            'url' => $collection['detail'] ? $this->app->request->origin() . $this->app->url(($itemLanguage !== '' ? $itemLanguage . '/' : '') . $collection['slug'] . '/' . $seo) : null]
            // a document (2.11): the stable address of its current file, for links and buttons
            + ($collection['detail'] && \Kaleta\Core\Documents::fileField($collection) !== null ? ['latest_url' => $this->app->request->origin() . $this->app->url(($itemLanguage !== '' ? $itemLanguage . '/' : '') . $collection['slug'] . '/' . $seo . '/latest')] : []);
    }

    /** delete_collection_item */
    private function toolDeleteCollectionItem(string $name, array $a): mixed
    {
        $auth = $this->app->auth();
        $db = $this->app->db();
        $id = (int) ($a['id'] ?? 0);
        $need = function (bool $allowed, string $message): void {
            if (!$allowed) {
                throw new \DomainException($message);
            }
        };
        $collection = function () use ($a, $db): array {
            return \Kaleta\Builder\Collections::bySlug($db, (string) ($a['collection'] ?? '')) ?? throw new \InvalidArgumentException('The collection does not exist. Use list_collections.');
        };

        $need($auth->hasModule('collections'), 'Collection items can be deleted only by users with the Collections section.');
        $k = $collection();
        // the permanent archive of an official notice board (2.11, Core\Notices)
        $need(!Notices::isNotices($k), Notices::REFUSAL_DELETE . ' (save_collection_item with values {"taken_down": "YYYY-MM-DD"}; the notice then moves to the archive.)');
        if (!\Kaleta\Admin\Modules\Collections::trashItem($db, $id, (int) $k['collection_id'])) {
            throw new \InvalidArgumentException('The item is not in this collection (or it is already in the trash). Use list_collection_items.');
        }

        return ['trashed' => $id, 'restore' => 'restore_from_trash with type collection_item within 30 days'];
    }

    /** list_item_versions and restore_item_version */
    private function toolListItemVersions(string $name, array $a): mixed
    {
        $auth = $this->app->auth();
        $db = $this->app->db();
        $id = (int) ($a['id'] ?? 0);
        $need = function (bool $allowed, string $message): void {
            if (!$allowed) {
                throw new \DomainException($message);
            }
        };
        $collection = function () use ($a, $db): array {
            return \Kaleta\Builder\Collections::bySlug($db, (string) ($a['collection'] ?? '')) ?? throw new \InvalidArgumentException('The collection does not exist. Use list_collections.');
        };

        $need($auth->hasModule('collections'), 'Collection items can be changed only by an editor or an administrator.');
        $k = $collection();
        $item = $db->one('SELECT * FROM {collection_items} WHERE item_id = ? AND collection_id = ? AND deleted_at IS NULL', [$id, $k['collection_id']]) ?? throw new \InvalidArgumentException('The item is not in the collection. Use list_collection_items.');
        if ($name === 'list_item_versions') {
            return ['versions' => array_map(fn (array $v): array => ['id' => (int) $v['revision_id'], 'saved' => substr((string) $v['created_at'], 0, 16), 'by' => (string) ($v['user_name'] ?? '')],
                \Kaleta\Builder\Publisher::listAll($db, ['part' => 'item:' . $id]))];
        }
        $version = \Kaleta\Builder\Collections::loadVersion($db, $id, (int) ($a['version'] ?? 0)) ?? throw new \InvalidArgumentException('The version does not exist. Use list_item_versions.');
        if ($db->value('SELECT 1 FROM {collection_items} WHERE collection_id = ? AND language = ? AND slug = ? AND item_id <> ?', [$k['collection_id'], $item['language'], $version['slug'] ?? '', $id]) !== null) {
            unset($version['slug']); // the address is taken by another item meanwhile
        }
        \Kaleta\Builder\Collections::saveVersion($this->app, $item, $version);
        $db->update('collection_items', $version + ['updated_at' => date('Y-m-d H:i:s')], ['item_id' => $id]);
        Notices::recordSave($this->app, $k, $item, $version, $id);
        \Kaleta\Front\Cache::clear();

        return ['restored' => $id, 'name' => $version['name'] ?? $item['name']];
    }

    /** list_notice_log (2.11, Core\Notices): the append-only audit trail of an official notice board – administrators */
    private function toolListNoticeLog(string $name, array $a): mixed
    {
        if (!$this->app->auth()->isAdmin()) {
            throw new \DomainException('The notice log is read by administrators.');
        }
        $db = $this->app->db();
        $k = Collections::bySlug($db, (string) ($a['collection'] ?? '')) ?? throw new \InvalidArgumentException('The collection does not exist. Use list_collections.');
        if (!Notices::isNotices($k)) {
            throw new \InvalidArgumentException('The collection is not an official notice board (preset notices). Use list_collections.');
        }
        $entries = Notices::entries($db, (int) $k['collection_id'], isset($a['id']) ? (int) $a['id'] : null);

        return ['collection' => $k['slug'], 'count' => count($entries),
            'entries' => array_map(fn (array $r): array => ['id' => (int) $r['id'], 'item' => (int) $r['item_id'], 'name' => (string) ($r['name'] ?? ''), 'action' => (string) $r['action'],
                'at' => (string) $r['at'], 'by' => (string) $r['by'], 'fields' => $r['fields'] !== [] ? $r['fields'] : new \stdClass()], array_slice($entries, -500)),
            'note' => 'Oldest first (the last 500). fields: key => [old, new] for created and changed; {posted: date} and {taken_down: date} are written by the hourly job once each. Nothing in the log can be edited or deleted.'];
    }

    /** get_email_signature (2.10, Builder\EmailSignature): the signature of one person by the item id or slug */
    private function toolGetEmailSignature(string $name, array $a): mixed
    {
        $db = $this->app->db();
        $k = Collections::bySlug($db, (string) ($a['collection'] ?? '')) ?? throw new \InvalidArgumentException('The collection does not exist. Use list_collections.');
        $id = (int) ($a['id'] ?? 0);
        $slug = trim((string) ($a['slug'] ?? ''));
        $visible = $this->app->auth()->hasModule('collections') ? '' : ' AND visible = 1'; // without the Collections section only people on the site
        $item = match (true) {
            $id > 0 => $db->one('SELECT * FROM {collection_items} WHERE item_id = ? AND collection_id = ? AND deleted_at IS NULL' . $visible, [$id, $k['collection_id']]),
            $slug !== '' => $db->one('SELECT * FROM {collection_items} WHERE slug = ? AND collection_id = ? AND deleted_at IS NULL' . $visible . ' ORDER BY language LIMIT 1', [$slug, $k['collection_id']]),
            default => null,
        };
        if ($item === null) {
            throw new \InvalidArgumentException('The item is not in the collection. Give its id or slug from list_collection_items.');
        }
        $item['data'] = json_decode((string) $item['data'], true) ?: [];

        return ['name' => $item['name'], 'people_collection' => \Kaleta\Builder\EmailSignature::isPeople($k), 'fields' => array_filter(\Kaleta\Builder\EmailSignature::fields($k))]
            + \Kaleta\Builder\EmailSignature::forItem($this->app, $k, $item);
    }

    /** restore_item_version: the same as list_item_versions */
    private function toolRestoreItemVersion(string $name, array $a): mixed
    {
        return $this->toolListItemVersions($name, $a);
    }
}
