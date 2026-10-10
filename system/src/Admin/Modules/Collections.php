<?php

declare(strict_types=1);

namespace Kaleta\Admin\Modules;

use Kaleta\Admin\Module;
use Kaleta\Admin\BuilderActions;
use Kaleta\Core\Language;
use Kaleta\Core\Notices;
use Kaleta\Core\Response;
use Kaleta\Builder\Collections as KolekceObsahu;
use Kaleta\Builder\Publisher;

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

    public const string IDENT = 'collections';
    public const string NAME = 'Collections';
    public const string GROUP = 'Content';
    public const string ICON = 'collections';
    public const string TABLE = 'collections';

    protected function actionList(): Response
    {
        return $this->view('list', 'Collections', [
            'presets' => \Kaleta\Builder\Presets::all(),
            'collection' => $this->db->all('SELECT k.collection_id, k.public_id, k.name, k.slug, k.detail, (SELECT COUNT(*) FROM {collection_items} p WHERE p.collection_id = k.collection_id AND p.deleted_at IS NULL) AS count FROM {collections} k ORDER BY k.name'),
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
        $id = self::createPreset($this->app, $this->request->post('preset'));

        return $id === null ? $this->back('Unknown template.', '', [], 'error')
            : $this->back('The collection was created with a hidden page that lists it – add the first items, then publish the page.', 'items', ['id' => $this->publicId($id)]);
    }

    /** Creates a ready-made collection; returns its id, or null for an unknown preset. Also for MCP (create_collection preset). */
    public static function createPreset(\Kaleta\Core\App $app, string $preset, string $name = ''): ?int
    {
        return \Kaleta\Builder\Presets::create($app, $preset, $name);
    }

    protected function actionNew(): Response
    {
        return $this->admin() ?? $this->view('form', 'New collection', ['k' => ['collection_id' => 0, 'public_id' => '', 'name' => '', 'slug' => '', 'detail' => 0, 'fields' => [
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
        if (in_array($seo, Pages::RESERVED_SLUGS, true) || isset(Language::AVAILABLE[$seo]) || \Kaleta\Core\Routes::isNewsSlug($seo, $this->db) || $this->db->value('SELECT collection_id FROM {collections} WHERE slug = ? AND collection_id <> ?', [$seo, $id]) !== null) {
            return $this->back(t('The address “%s” is already used by the system or another collection.', $seo), $id > 0 ? 'edit' : 'new', $id > 0 ? ['id' => $this->publicId($id)] : [], 'error');
        }
        // the key of an existing field does not change (item values are stored under it); new fields get it from the label
        $field = KolekceObsahu::sanitizeFields(is_array($_POST['fields'] ?? null) ? array_values($_POST['fields']) : []);
        $redirect = KolekceObsahu::cleanRedirect($r->post('hidden_redirect'));
        if ($redirect === null) {
            return $this->back('The redirect of hidden items must be an address on the site (/team) or https://…', $id > 0 ? 'edit' : 'new', $id > 0 ? ['id' => $this->publicId($id)] : [], 'error');
        }
        $data = ['name' => $name, 'slug' => $seo, 'detail' => $r->postBool('detail') ? 1 : 0, 'hidden_redirect' => $redirect, 'fields' => (string) json_encode($field, JSON_UNESCAPED_UNICODE), 'updated_at' => date('Y-m-d H:i:s')];
        if (is_array($_POST['schema'] ?? null)) {
            $schema = \Kaleta\Builder\CollectionSchema::sanitize($_POST['schema'], $field);
            $data['schema_org'] = $schema === null ? null : (string) json_encode($schema, JSON_UNESCAPED_UNICODE);
        }
        if ($previous !== null) {
            $this->db->update('collections', $data, ['collection_id' => $id]);
        } else {
            $id = $this->db->insert('collections', $data);
        }
        \Kaleta\Front\Cache::clear();

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

        return $this->view('items', $k['name'], ['k' => $k, 'languages' => Language::additional($this->app->settings()), 'siteLanguages' => $siteLanguages, 'language' => $language,
            'trash' => $trash, 'noticeBoard' => Notices::isNotices($k), 'inTrash' => (int) $this->db->value('SELECT COUNT(*) FROM {collection_items} WHERE collection_id = ? AND deleted_at IS NOT NULL', [$k['collection_id']]),
            // a document library (2.11): how often each document was downloaded, and its stable address
            'downloads' => \Kaleta\Core\Documents::fileField($k) !== null ? \Kaleta\Core\Documents::counts($this->db, (int) $k['collection_id']) : null,
            'items' => $this->db->all('SELECT item_id, public_id, name, slug, sort_order, visible, language, created_at, deleted_at, valid_until, review_by FROM {collection_items} WHERE collection_id = ? AND deleted_at IS ' . ($trash ? 'NOT NULL' : 'NULL')
                . ($column !== null ? ' AND language = ?' : '') . ' ORDER BY ' . ($trash ? 'deleted_at DESC' : 'language, sort_order, name'), $column !== null ? [$k['collection_id'], $column] : [$k['collection_id']])]);
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

        return $this->view('item', $p['name'] !== '' ? $p['name'] : t('New item'), ['k' => $k, 'p' => $p,
            'versions' => $p['item_id'] > 0 ? \Kaleta\Builder\Publisher::listAll($this->db, ['part' => 'item:' . (int) $p['item_id']]) : [],
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
        $seo = slugify($r->post('slug') !== '' ? $r->post('slug') : $name, 150);
        // the slug is unique within a language: a translation of the item can have the same one (/compare/wordpress, /de/compare/wordpress)
        $language = Language::column($this->app->settings(), $r->post('language'));
        $seo = \Kaleta\Core\Slug::makeUnique($seo, fn (string $a): bool => $this->db->value('SELECT item_id FROM {collection_items} WHERE collection_id = ? AND language = ? AND slug = ? AND item_id <> ?', [$k['collection_id'], $language, $a, $idp]) !== null);
        $row = ['collection_id' => $k['collection_id'], 'name' => $name, 'slug' => $seo, 'data' => (string) json_encode($data, JSON_UNESCAPED_UNICODE),
            'sort_order' => max(-9999, min(9999, $r->postInt('sort_order'))), 'language' => $language, 'updated_at' => date('Y-m-d H:i:s'),
            'valid_until' => \Kaleta\Core\Validity::date($r->post('valid_until')), 'review_by' => \Kaleta\Core\Validity::date($r->post('review_by'))] // 2.10
            + KolekceObsahu::pageFields($_POST, $r->postBool('visible'));
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
        }
        Notices::recordSave($this->app, $k, $previous, $row, $idp);
        \Kaleta\Front\Cache::clear();
        if ($errors !== []) {
            return $this->back(t('The item is saved, but these fields had an invalid value and were left empty: %s', implode(', ', $errors)), 'item', ['id' => $k['public_id'], 'item' => $this->publicId($idp, 'collection_items')], 'error');
        }

        return $this->back('The item was saved.', 'items', ['id' => $k['public_id']]);
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
        foreach (array_unique(array_map(intval(...), $this->request->postList('selected'))) as $idp) {
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
            \Kaleta\Admin\ChangeLog::write($this->app, 'collections', 'bulk ' . ['visible' => 'shown', 'hide' => 'hidden', 'language' => 'language ' . ($language ?: 'default'), 'trash' => 'moved to trash'][$action], mb_substr($k['slug'] . ': ' . $p['name'], 0, 80));
            $done++;
        }
        if ($done > 0) {
            \Kaleta\Front\Cache::clear();
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

        return $this->view('signature', t('E-mail signature: %s', $p['name']), ['k' => $k, 'p' => $p, 'signature' => \Kaleta\Builder\EmailSignature::forItem($this->app, $k, $p)]);
    }

    /** Copy of an item (hidden, with a free slug) – a quick start for a similar reference, team member, product. */
    protected function actionDuplicateItem(): Response
    {
        $idk = $this->idParam('collection_id');
        $p = $this->request->isPost() ? $this->db->one('SELECT * FROM {collection_items} WHERE item_id = ? AND collection_id = ? AND deleted_at IS NULL', [$this->idParam('item_id', 'collection_items'), $idk]) : null;
        if ($p === null) {
            return $this->back('', 'items', ['id' => $this->publicId($idk)]);
        }
        $seo = \Kaleta\Core\Slug::makeUnique($p['slug'] . '-kopie', fn (string $a): bool => $this->db->value('SELECT 1 FROM {collection_items} WHERE collection_id = ? AND language = ? AND slug = ?', [$idk, $p['language'], $a]) !== null);
        $copy = ['collection_id' => $idk, 'name' => mb_substr(t('%s (copy)', $p['name']), 0, 200), 'slug' => $seo, 'data' => $p['data'],
            'seo_title' => $p['seo_title'], 'description' => $p['description'], 'image' => $p['image'], 'noindex' => $p['noindex'],
            'sort_order' => $p['sort_order'], 'visible' => 0, 'language' => $p['language'], 'created_at' => date('Y-m-d H:i:s')];
        $id = $this->db->insert('collection_items', $copy);
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
        Notices::recordSave($this->app, (array) KolekceObsahu::byId($this->db, $idk), $item, $version, $idp);
        \Kaleta\Front\Cache::clear();

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
        if ($k === null || !Notices::isNotices($k)) {
            return $this->error('The collection is not an official notice board.', 404);
        }
        \Kaleta\Admin\ChangeLog::write($this->app, 'collections', 'notice log CSV', $k['slug']);

        return new Response(Notices::csv($this->db, $k), 200, ['Content-Type' => 'text/csv; charset=utf-8', 'Content-Disposition' => 'attachment; filename="' . $k['slug'] . '-log-' . date('Y-m-d') . '.csv"']);
    }

    /** Back from the trash – hidden, so it does not appear on the site before it is checked. */
    protected function actionRestoreItem(): Response
    {
        $idk = $this->idParam('collection_id');
        if ($this->request->isPost()) {
            self::restoreItem($this->db, $this->idParam('item_id', 'collection_items'), $idk);
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
    public static function trashItem(\Kaleta\Core\Db $db, int $idp, int $idk): bool
    {
        $moved = $db->run('UPDATE {collection_items} SET deleted_at = NOW(), visible = 0 WHERE item_id = ? AND collection_id = ? AND deleted_at IS NULL', [$idp, $idk])->rowCount() > 0;
        \Kaleta\Front\Cache::clear();

        return $moved;
    }

    public static function restoreItem(\Kaleta\Core\Db $db, int $idp, int $idk): bool
    {
        return $db->run('UPDATE {collection_items} SET deleted_at = NULL WHERE item_id = ? AND collection_id = ? AND deleted_at IS NOT NULL', [$idp, $idk])->rowCount() > 0;
    }

    /** Items longer than 30 days in the trash are deleted permanently (with pages and news, on an admin visit). */
    public static function emptyTrash(\Kaleta\Core\Db $db): void
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
        $seo = $this->db->value('SELECT slug FROM {collection_items} WHERE collection_id = ? AND language = ? AND visible = 1 AND deleted_at IS NULL ORDER BY sort_order, name LIMIT 1', [$k['collection_id'], $language]);
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
