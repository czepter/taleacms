<?php

declare(strict_types=1);

namespace Kaleta\Mcp\Handlers;

use Kaleta\Admin\Modules\Media;
use Kaleta\Admin\Modules\Categories;
use Kaleta\Admin\Modules\Pages;
use Kaleta\Core\App;
use Kaleta\Core\Language;
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
    /** list_collections (seznam_kolekci) */
    private function toolListCollections(string $name, array $a): mixed
    {
        $db = $this->app->db();

        return array_map(fn (array $k): array => ['kolekce' => $k['seo_link'], 'nazev' => $k['nazev'], 'detail' => (bool) $k['detail'], 'presmerovat_skryte' => (string) ($k['hidden_redirect'] ?? ''), 'pole' => $k['pole'],
            'polozek' => (int) $db->value('SELECT COUNT(*) FROM {kolekce_polozky} WHERE idk = ? AND smazano IS NULL', [$k['idk']])]
            + (($sd = \Kaleta\Builder\CollectionSchema::of($k)) !== null ? ['structured_data' => ['type' => $sd['typ'], 'fields' => $sd['pole'], 'currency' => $sd['mena']]] : []), Collections::all($db));
    }

    /** create_collection (vytvor_kolekci) */
    private function toolCreateCollection(string $name, array $a): mixed
    {
        $auth = $this->app->auth();
        $db = $this->app->db();
        $adminOnly = function () use ($auth): void {
            if (!$auth->isAdmin()) {
                throw new \DomainException('Tento nástroj smí použít jen správce webu.');
            }
        };

        $adminOnly();
        if (($a['preset'] ?? '') !== '') {
            // a ready-made collection (2.10): People
            $id = \Kaleta\Admin\Modules\Collections::createPreset($this->app, (string) $a['preset']);
            if ($id === null) {
                throw new \InvalidArgumentException('Unknown preset – use people.');
            }
            $created = (array) Collections::byId($db, $id);

            return ['kolekce' => $created['seo_link'], 'pole' => $created['pole'], 'presmerovat_skryte' => $created['hidden_redirect']];
        }
        $collectionName = mb_substr(trim((string) ($a['nazev'] ?? '')), 0, 100);
        if ($collectionName === '') {
            throw new \InvalidArgumentException('Chybí název kolekce.');
        }
        $seo = $this->availableCollectionSlug((string) ($a['adresa'] ?? '') !== '' ? (string) $a['adresa'] : $collectionName, 0);
        $field = Collections::sanitizeFields($a['pole'] ?? []);
        $redirect = Collections::cleanRedirect((string) ($a['presmerovat_skryte'] ?? ''));
        if ($redirect === null) {
            throw new \InvalidArgumentException('redirect_hidden_to must be an address on the site (/team) or https://…');
        }
        $db->insert('kolekce', ['nazev' => $collectionName, 'seo_link' => $seo, 'detail' => empty($a['detail']) ? 0 : 1, 'hidden_redirect' => $redirect, 'pole' => (string) json_encode($field, JSON_UNESCAPED_UNICODE), 'zmeneno' => date('Y-m-d H:i:s'),
            'schema_org' => self::collectionSchema($a['schema_org'] ?? null, $field)]);

        return ['kolekce' => $seo, 'pole' => $field];
    }

    /** update_collection (uprav_kolekci) */
    private function toolUpdateCollection(string $name, array $a): mixed
    {
        $auth = $this->app->auth();
        $db = $this->app->db();
        $adminOnly = function () use ($auth): void {
            if (!$auth->isAdmin()) {
                throw new \DomainException('Tento nástroj smí použít jen správce webu.');
            }
        };

        $adminOnly();
        $collection = $this->collection((string) ($a['kolekce'] ?? ''));
        $changes = ['zmeneno' => date('Y-m-d H:i:s')];
        if (isset($a['nazev']) && trim((string) $a['nazev']) !== '') {
            $changes['nazev'] = mb_substr(trim((string) $a['nazev']), 0, 100);
        }
        if (isset($a['adresa']) && trim((string) $a['adresa']) !== '') {
            $changes['seo_link'] = $this->availableCollectionSlug((string) $a['adresa'], (int) $collection['idk']);
        }
        if (array_key_exists('detail', $a)) {
            $changes['detail'] = empty($a['detail']) ? 0 : 1;
        }
        if (array_key_exists('presmerovat_skryte', $a)) {
            $changes['hidden_redirect'] = Collections::cleanRedirect((string) $a['presmerovat_skryte'])
                ?? throw new \InvalidArgumentException('redirect_hidden_to must be an address on the site (/team) or https://…');
        }
        if (is_array($a['pole'] ?? null)) {
            $changes['pole'] = (string) json_encode(Collections::sanitizeFields($a['pole']), JSON_UNESCAPED_UNICODE);
        }
        if (array_key_exists('schema_org', $a)) {
            $changes['schema_org'] = self::collectionSchema($a['schema_org'], json_decode($changes['pole'] ?? '', true) ?: $collection['pole']);
        }
        $db->update('kolekce', $changes, ['idk' => $collection['idk']]);
        \Kaleta\Front\Cache::clear();
        $newVersion = (array) $db->one('SELECT * FROM {kolekce} WHERE idk = ?', [$collection['idk']]);

        return ['kolekce' => $newVersion['seo_link'], 'nazev' => $newVersion['nazev'], 'detail' => (bool) $newVersion['detail'], 'presmerovat_skryte' => (string) $newVersion['hidden_redirect'], 'pole' => json_decode((string) $newVersion['pole'], true) ?: []];
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
        $db->delete('kolekce', ['idk' => $k['idk']]); // items and templates go with it (foreign keys)
        \Kaleta\Front\Cache::clear();

        return ['deleted' => $k['seo_link']];
    }

    /** list_collection_items (seznam_polozek_kolekce) */
    private function toolListCollectionItems(string $name, array $a): mixed
    {
        $auth = $this->app->auth();
        $db = $this->app->db();

        $collection = $this->collection((string) ($a['kolekce'] ?? ''));

        $whereParts = ['idk = ?', 'smazano IS NULL']; // the trash is in list_trash
        $args = [$collection['idk']];
        if (isset($a['jazyk']) && is_string($a['jazyk'])) {
            $whereParts[] = 'jazyk = ?';
            $args[] = $a['jazyk'];
        }
        if (!empty($a['jen_zobrazene']) || !$auth->hasModule('collections')) {
            $whereParts[] = 'zobrazit = 1'; // without the Collections section only published items
        }
        if (is_string($a['hledat'] ?? null) && trim($a['hledat']) !== '') {
            $whereParts[] = '(nazev LIKE ? OR data LIKE ?)';
            $pattern = '%' . addcslashes(mb_substr(trim($a['hledat']), 0, 100), '%_\\') . '%';
            array_push($args, $pattern, $pattern);
        }
        $rows = $db->all('SELECT * FROM {kolekce_polozky} WHERE ' . implode(' AND ', $whereParts) . ' ORDER BY poradi, nazev LIMIT 5000', $args);
        if (is_string($a['pole'] ?? null) && $a['pole'] !== '') {
            // exact match of a field value (JSON in the database – filtered here, without depending on the MySQL version)
            $rows = array_values(array_filter($rows, fn (array $r): bool => (string) ((json_decode((string) $r['data'], true) ?: [])[$a['pole']] ?? '') === (string) ($a['hodnota'] ?? '')));
        }
        $pageNumber = max(1, (int) ($a['strana'] ?? 1));

        return ['celkem' => count($rows), 'strana' => $pageNumber, 'stran' => max(1, (int) ceil(count($rows) / 50)), 'polozky' => array_map(fn (array $r): array => ['id' => (int) $r['idp'], 'nazev' => $r['nazev'], 'seo_link' => $r['seo_link'], 'poradi' => (int) $r['poradi'], 'zobrazit' => (bool) $r['zobrazit'],
            'jazyk' => $r['jazyk'], 'data' => json_decode((string) $r['data'], true) ?: new \stdClass()]
            + array_filter(['seo_titulek' => $r['seo_titulek'], 'popis' => $r['popis'], 'obrazek' => $r['obrazek'], 'noindex' => (bool) $r['noindex'], 'zverejnit_od' => $r['zverejnit_od']]) + self::validityOutput($r), array_slice($rows, ($pageNumber - 1) * 50, 50))];
    }

    /** save_collection_item (uloz_polozku_kolekce) */
    private function toolSaveCollectionItem(string $name, array $a): mixed
    {
        $auth = $this->app->auth();
        $db = $this->app->db();
        $siteSettings = $this->app->settings();

        if (!$auth->hasModule('collections')) {
            throw new \DomainException('Kolekce smí upravovat editor nebo správce.');
        }
        $collection = $this->collection((string) ($a['kolekce'] ?? ''));
        $previous = isset($a['id']) ? $db->one('SELECT * FROM {kolekce_polozky} WHERE idp = ? AND idk = ?', [(int) $a['id'], $collection['idk']]) : null;
        if (isset($a['id']) && $previous === null) {
            throw new \InvalidArgumentException('Položka v kolekci není. Použij seznam_polozek_kolekce.');
        }
        if ($previous !== null && $previous['smazano'] !== null) {
            // saving would put it back on the site while it still waits in the trash to be deleted
            throw new \InvalidArgumentException('The item is in the trash. Bring it back with restore_from_trash first.');
        }
        // on update the name is optional – the current one stays
        $itemName = mb_substr(trim((string) ($a['nazev'] ?? $previous['nazev'] ?? '')), 0, 200);
        if ($itemName === '') {
            throw new \InvalidArgumentException('Položka musí mít název.');
        }
        if (isset($a['data']) && !is_array($a['data'])) {
            throw new \InvalidArgumentException('Parametr data musí být objekt {"klic":"hodnota"} podle polí kolekce.');
        }
        $errors = [];
        $data = Collections::sanitizeData($collection['pole'], (is_array($a['data'] ?? null) ? $a['data'] : []) + (json_decode((string) ($previous['data'] ?? '{}'), true) ?: []), $errors);
        $url = trim((string) ($a['adresa'] ?? ''));
        $seo = $url !== '' ? slugify($url, 150) : ($previous['seo_link'] ?? slugify($itemName, 150));
        if ($seo === '' || $seo === '_ukazka') {
            throw new \InvalidArgumentException('Neplatná adresa položky.');
        }
        // the slug is unique within a language: an item's translation should have the same one (the language
        // switcher and hreflang find it by the slug)
        $itemLanguage = array_key_exists('jazyk', $a) ? Language::column($siteSettings, (string) $a['jazyk']) : (string) ($previous['jazyk'] ?? '');
        $seo = \Kaleta\Core\Slug::makeUnique($seo, fn (string $a): bool => $db->value('SELECT idp FROM {kolekce_polozky} WHERE idk = ? AND jazyk = ? AND seo_link = ? AND idp <> ?', [$collection['idk'], $itemLanguage, $a, (int) ($previous['idp'] ?? 0)]) !== null);
        $row = ['nazev' => $itemName, 'seo_link' => $seo, 'data' => (string) json_encode($data, JSON_UNESCAPED_UNICODE), 'zmeneno' => date('Y-m-d H:i:s')]
            + (array_key_exists('jazyk', $a) ? ['jazyk' => $itemLanguage] : [])
            + (array_key_exists('poradi', $a) ? ['poradi' => max(-9999, min(9999, (int) $a['poradi']))] : [])
            + (array_key_exists('zobrazit', $a) ? ['zobrazit' => (int) (bool) $a['zobrazit']] : []);
        // SEO and scheduled publishing (1.9): only what was sent changes, the rest stays
        $pageKeys = ['seo_titulek', 'popis', 'obrazek', 'noindex', 'zverejnit_od'];
        if (array_intersect($pageKeys, array_keys($a)) !== []) {
            $fields = Collections::pageFields(array_intersect_key($a, array_flip($pageKeys)) + ($previous ?? []), (bool) ($row['zobrazit'] ?? $previous['zobrazit'] ?? 0));
            $row = array_intersect_key($fields, array_flip(array_intersect(['seo_titulek', 'popis', 'obrazek', 'noindex'], array_keys($a)))) + $row;
            if (array_key_exists('zverejnit_od', $a)) {
                $row['zverejnit_od'] = $fields['zverejnit_od'];
                $row['zobrazit'] = $fields['zobrazit'];
            }
        }
        $row += self::validityDates($a); // true until and review by (2.10)
        if ($previous !== null) {
            Collections::saveVersion($this->app, $previous, $row);
            $db->update('kolekce_polozky', $row, ['idp' => $previous['idp']]);
            $idp = (int) $previous['idp'];
        } else {
            $idp = $db->insert('kolekce_polozky', $row + ['idk' => $collection['idk'], 'datum' => date('Y-m-d H:i:s'), 'zobrazit' => 0]);
        }
        \Kaleta\Front\Cache::clear(); // item pages, lists, the sitemap and llms.txt show the change at once (as after a save in the admin)

        // a key the collection does not have (a typo, „nazev“ in data instead of the parameter) would otherwise be silently dropped
        $unknownKeys = array_values(array_diff(array_keys(is_array($a['data'] ?? null) ? $a['data'] : []), array_column($collection['pole'], 'klic')));

        return ['id' => $idp, 'kolekce' => $collection['seo_link'], 'neplatna_pole' => array_keys($errors)] + ($unknownKeys !== [] ? ['nezname_klice' => $unknownKeys] : []) + self::validityOutput($row) + [
            'adresa' => $collection['detail'] ? $this->app->request->origin() . $this->app->url(($itemLanguage !== '' ? $itemLanguage . '/' : '') . $collection['seo_link'] . '/' . $seo) : null];
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
        if (!\Kaleta\Admin\Modules\Collections::trashItem($db, $id, (int) $collection()['idk'])) {
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
        $item = $db->one('SELECT * FROM {kolekce_polozky} WHERE idp = ? AND idk = ? AND smazano IS NULL', [$id, $k['idk']]) ?? throw new \InvalidArgumentException('The item is not in the collection. Use list_collection_items.');
        if ($name === 'list_item_versions') {
            return ['versions' => array_map(fn (array $v): array => ['id' => (int) $v['idr'], 'saved' => substr((string) $v['datum'], 0, 16), 'by' => (string) ($v['kdo'] ?? '')],
                \Kaleta\Builder\Publisher::listAll($db, ['cast' => 'polozka:' . $id]))];
        }
        $version = \Kaleta\Builder\Collections::loadVersion($db, $id, (int) ($a['version'] ?? 0)) ?? throw new \InvalidArgumentException('The version does not exist. Use list_item_versions.');
        if ($db->value('SELECT 1 FROM {kolekce_polozky} WHERE idk = ? AND jazyk = ? AND seo_link = ? AND idp <> ?', [$k['idk'], $item['jazyk'], $version['seo_link'] ?? '', $id]) !== null) {
            unset($version['seo_link']); // the address is taken by another item meanwhile
        }
        \Kaleta\Builder\Collections::saveVersion($this->app, $item, $version);
        $db->update('kolekce_polozky', $version + ['zmeneno' => date('Y-m-d H:i:s')], ['idp' => $id]);
        \Kaleta\Front\Cache::clear();

        return ['restored' => $id, 'name' => $version['nazev'] ?? $item['nazev']];
    }

    /** restore_item_version: the same as list_item_versions */
    private function toolRestoreItemVersion(string $name, array $a): mixed
    {
        return $this->toolListItemVersions($name, $a);
    }
}
