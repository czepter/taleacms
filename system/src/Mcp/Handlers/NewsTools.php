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
 * MCP tools: news (one method per tool, see Mcp\Catalog). Part of Mcp\Tools.
 *
 * @phpstan-ignore trait.unused
 */
trait NewsTools
{
    /** list_news (seznam_novinek) */
    private function toolListNews(string $name, array $a): mixed
    {
        $auth = $this->app->auth();
        $db = $this->app->db();

        $where = ['c.smazano IS NULL']; // the trash is neither listed nor edited via MCP
        $p = [];
        if (($authors = $auth->managedAuthors()) !== null) {
            $where[] = 'c.autor IN (' . implode(',', $authors) . ')';
        }
        $statuses = ['vydane' => 'c.visible = 1 AND c.datum <= NOW()', 'plan' => 'c.visible = 1 AND c.datum > NOW()', 'koncepty' => 'c.visible = 0'];
        if (isset($statuses[$a['stav'] ?? ''])) {
            $where[] = $statuses[$a['stav']];
        }
        if (!empty($a['kategorie'])) {
            $where[] = 'c.tema = ?';
            $p[] = $this->category((string) $a['kategorie']);
        }
        if (!empty($a['hledat'])) {
            $where[] = 'c.titulek LIKE ?';
            $p[] = '%' . addcslashes((string) $a['hledat'], '%_\\') . '%';
        }

        return $db->all(
            'SELECT c.idc AS id, c.titulek, c.seo_link, t.nazev AS kategorie, c.datum, c.visible AS vydana
             FROM {novinky} c JOIN {kategorie} t ON t.idt = c.tema WHERE ' . implode(' AND ', $where) . ' ORDER BY c.datum DESC LIMIT ?',
            [...$p, max(1, min(50, (int) ($a['limit'] ?? 20)))],
        );
    }

    /** get_news (nacti_novinku) */
    private function toolGetNews(string $name, array $a): mixed
    {
        $db = $this->app->db();

        $c = $this->newsItem((int) ($a['id'] ?? 0));

        return array_intersect_key($c, array_flip(['idc', 'titulek', 'seo_link', 'uvod', 'text', 'obrazek', 'obrazek_popis', 'datum', 'visible', 'faq', 'seo_titulek', 'seo_popis']))
            + self::validityOutput($c) + ['kategorie' => $db->value('SELECT nazev FROM {kategorie} WHERE idt = ?', [$c['tema']]),
                'stitky' => array_column($db->all('SELECT s.nazev FROM {stitky} s JOIN {novinky_stitky} cs ON cs.ids = s.ids WHERE cs.idc = ?', [$c['idc']]), 'nazev'),
                'adresa' => $this->app->request->origin() . $this->app->url('novinky/' . $c['seo_link'])];
    }

    /** create_news and update_news (vytvor_novinku, uprav_novinku) */
    private function toolCreateNews(string $name, array $a): mixed
    {
        $auth = $this->app->auth();

        if (!$auth->hasModule('news')) {
            throw new \DomainException('K novinkám nemáš přístup (role uživatele).');
        }

        return $this->saveNewsItem($name === 'uprav_novinku' ? $this->newsItem((int) ($a['id'] ?? 0)) : null, $a);
    }

    /** update_news: the same as create_news */
    private function toolUpdateNews(string $name, array $a): mixed
    {
        return $this->toolCreateNews($name, $a);
    }

    /** trash_news */
    private function toolTrashNews(string $name, array $a): mixed
    {
        $auth = $this->app->auth();
        $db = $this->app->db();
        $id = (int) ($a['id'] ?? 0);
        $need = function (bool $allowed, string $message): void {
            if (!$allowed) {
                throw new \DomainException($message);
            }
        };

        $need($auth->hasModule('news'), 'News items can be deleted only by users with the News section.');
        $item = $db->one('SELECT idc, visible, autor FROM {novinky} WHERE idc = ? AND smazano IS NULL', [$id]) ?? throw new \InvalidArgumentException('The news item does not exist. Use list_news.');
        $need($auth->canPublish() || (!$item['visible'] && (int) $item['autor'] === $auth->id()), 'A published news item or someone else’s can be deleted only with the publishing permission.');
        $db->run('UPDATE {novinky} SET smazano = NOW(), visible = 0 WHERE idc = ?', [$id]);
        \Kaleta\Front\Cache::clear();

        return ['trashed' => $id, 'restore' => 'restore_from_trash with type news within 30 days'];
    }

    /** list_categories (seznam_kategorii) */
    private function toolListCategories(string $name, array $a): mixed
    {
        $db = $this->app->db();

        return array_map(fn (array $r): array => ['id' => (int) $r['idt'], 'nazev' => $r['nazev'], 'adresa' => $r['seo_link'], 'jazyk' => $r['jazyk'], 'novinek' => (int) $r['pocet_clanku']], Categories::listAll($db));
    }

    /** create_category (vytvor_kategorii) */
    private function toolCreateCategory(string $name, array $a): mixed
    {
        $auth = $this->app->auth();
        $db = $this->app->db();

        if (!$auth->canPublish() || !$auth->hasModule('categories')) {
            throw new \DomainException('Kategorie smí zakládat editor nebo správce.');
        }
        $displayName = mb_substr(trim((string) ($a['nazev'] ?? '')), 0, 100);
        if ($displayName === '') {
            throw new \InvalidArgumentException('Chybí název kategorie.');
        }
        $seo = $this->availableSlug('kategorie', 'idt', slugify($displayName, 110));

        return ['id' => $db->insert('kategorie', ['nazev' => $displayName, 'seo_link' => $seo, 'popis' => \Kaleta\Core\Html::safe((string) ($a['popis'] ?? ''))]), 'adresa' => $seo];
    }

    /** update_category */
    private function toolUpdateCategory(string $name, array $a): mixed
    {
        $auth = $this->app->auth();
        $db = $this->app->db();
        $id = (int) ($a['id'] ?? 0);
        $need = function (bool $allowed, string $message): void {
            if (!$allowed) {
                throw new \DomainException($message);
            }
        };

        $need($auth->canPublish() && $auth->hasModule('categories'), 'Categories can be changed by editors and administrators.');
        $c = $db->one('SELECT * FROM {kategorie} WHERE idt = ?', [$id]) ?? throw new \InvalidArgumentException('The category does not exist. Use list_categories.');
        $changes = [];
        if (trim((string) ($a['name'] ?? '')) !== '') {
            $changes['nazev'] = mb_substr(trim((string) $a['name']), 0, 255);
        }
        if (isset($a['description'])) {
            $changes['popis'] = \Kaleta\Core\Html::forUser((string) $a['description'], $auth);
        }
        if (isset($a['order'])) {
            $changes['hodnost'] = max(0, min(65535, (int) $a['order']));
        }
        if (trim((string) ($a['slug'] ?? '')) !== '') {
            $changes['seo_link'] = \Kaleta\Core\Slug::makeUnique(slugify((string) $a['slug'], 110), fn (string $x): bool => $db->value('SELECT idt FROM {kategorie} WHERE seo_link = ? AND idt <> ?', [$x, $id]) !== null, 120);
        }
        if ($changes !== []) {
            $db->update('kategorie', $changes, ['idt' => $id]);
            if (isset($changes['seo_link']) && $changes['seo_link'] !== $c['seo_link']) {
                \Kaleta\Admin\Modules\Redirects::add($db, 'novinky/kategorie/' . $c['seo_link'], 'novinky/kategorie/' . $changes['seo_link']);
            }
            \Kaleta\Front\Cache::clear();
        }
        $c = (array) $db->one('SELECT * FROM {kategorie} WHERE idt = ?', [$id]);

        return ['id' => $id, 'name' => $c['nazev'], 'slug' => $c['seo_link'], 'order' => (int) $c['hodnost']];
    }

    /** delete_category */
    private function toolDeleteCategory(string $name, array $a): mixed
    {
        $auth = $this->app->auth();
        $db = $this->app->db();
        $id = (int) ($a['id'] ?? 0);
        $need = function (bool $allowed, string $message): void {
            if (!$allowed) {
                throw new \DomainException($message);
            }
        };

        $need($auth->canPublish() && $auth->hasModule('categories'), 'Categories can be deleted by editors and administrators.');
        if ($db->value('SELECT idt FROM {kategorie} WHERE idt = ?', [$id]) === null) {
            throw new \InvalidArgumentException('The category does not exist. Use list_categories.');
        }
        if ((int) $db->value('SELECT COUNT(*) FROM {novinky} WHERE tema = ?', [$id]) > 0) {
            throw new \DomainException('The category still has news items (including those in the trash) – move them to another category first.');
        }
        $db->delete('kategorie', ['idt' => $id]);

        return ['deleted' => $id];
    }
}
