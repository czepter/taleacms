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
 * MCP tools: site and pages (one method per tool, see Mcp\Catalog). Part of Mcp\Tools.
 *
 * @phpstan-ignore trait.unused
 */
trait PageTools
{
    /** site_info (info_o_webu) */
    private function toolSiteInfo(string $name, array $a): mixed
    {
        $auth = $this->app->auth();
        $db = $this->app->db();
        $siteSettings = $this->app->settings();
        // the agent notebook (2.15, Core\Notebook): how many notes colleagues and earlier conversations left, and the pinned titles – read_notebook has them all
        $notebook = \Kaleta\Core\Notebook::summary($db);

        return [
            'web' => $siteSettings->get('site_name'), 'adresa' => $this->app->request->origin() . $this->app->url(''), 'popis' => $siteSettings->get('site_description'),
            'uvodni_stranka' => $siteSettings->int('home_page') ?: null, 'verze_kaleta' => KALETA_VERSION,
            'stranek' => (int) $db->value('SELECT COUNT(*) FROM {pages} WHERE deleted_at IS NULL'),
            'novinek_vydanych' => (int) $db->value('SELECT COUNT(*) FROM {news} WHERE visible = 1 AND published_at <= NOW() AND deleted_at IS NULL'),
            'uzivatel' => $auth->user()['username'], 'role' => \Kaleta\Core\Auth::TYPES[(int) $auth->user()['admin']], 'smi_vydavat' => $auth->canPublish(),
            'smi_upravovat_stranky' => $auth->hasModule('pages'),
            // what this connection may do (2.2): full, drafts (reads and drafts, never publishes) or read
            'connection' => $auth->connection() ?? ['name' => '', 'access' => 'full'],
            // what the site has switched on, so Claude does not guess (extension keys: novinky, poptavky, newsletter…)
            'extensions' => \Kaleta\Core\Extensions::enabled($siteSettings),
            // the whistleblowing channel (2.14): Claude learns only that it is on – no tool reads or lists its cases
            'whistleblowing' => \Kaleta\Core\Whistleblowing::isOn($siteSettings),
            'notebook_count' => $notebook['count'], 'notebook_pinned' => $notebook['pinned'],
            'languages' => ['german_address' => Language::visitorAddress($siteSettings), 'default' => Language::defaults($siteSettings),
                'additional' => array_map(fn (string $code): array => ['code' => $code, 'published' => in_array($code, Language::published($siteSettings, $db), true)], Language::additional($siteSettings))],
            'cron_last_run_minutes' => $siteSettings->int('tasks_last_run') > 0 ? (int) floor((time() - $siteSettings->int('tasks_last_run')) / 60) : null,
            'look_draft' => \Kaleta\Core\Look::summary($db, $siteSettings), // unpublished look changes (publish_look, discard_look)
        ] + (\Kaleta\Fleet\Link::isPaired($siteSettings) ? [
            // the shared design kit of the fleet console (2.16, Fleet\Kit): which version arrived here as drafts, and when
            'fleet_kit' => ['enabled' => $siteSettings->bool('fleet_kit'), 'version' => $siteSettings->int('fleet_kit_version') ?: null,
                'applied_at' => $siteSettings->int('fleet_kit_applied_at') > 0 ? date('Y-m-d H:i:s', $siteSettings->int('fleet_kit_applied_at')) : null],
        ] : []);
    }

    /** list_pages (seznam_stranek) */
    private function toolListPages(string $name, array $a): mixed
    {
        $auth = $this->app->auth();
        $db = $this->app->db();
        $siteSettings = $this->app->settings();

        $home = $siteSettings->int('home_page');

        // the URL of a language version has a prefix (/de/…); the translation of the home page is the root of its version (/de/)
        return array_map(fn (array $r): array => ['id' => (int) $r['ids'], 'title' => $r['title'], 'adresa' => $this->app->request->origin()
            . $this->app->url(($r['language'] !== '' ? $r['language'] . '/' : '') . ((int) $r['ids'] === $home || ($home > 0 && (int) $r['translation_of'] === $home) ? '' : $r['slug'])),
            'uvodni' => (int) $r['ids'] === $home, 'zobrazena' => (bool) $r['visible'], 'in_menu' => (bool) $r['in_menu'], 'language' => $r['language']],
            // without the Pages section (news author) only published pages – it can link to those, it does not see drafts
            $db->all('SELECT page_id, title, slug, visible, in_menu, language, translation_of FROM {pages} WHERE deleted_at IS NULL' . ($auth->hasModule('pages') ? '' : ' AND zobrazit = 1') . ' ORDER BY language, sort_order, title'));
    }

    /** get_page (nacti_stranku) */
    private function toolGetPage(string $name, array $a): mixed
    {
        $auth = $this->app->auth();

        $page = $this->page((int) ($a['id'] ?? 0));
        if (!$page['visible'] && !$auth->hasModule('pages')) {
            throw new \InvalidArgumentException('Stránka neexistuje. Použij nástroj seznam_stranek.');
        }
        // whether visitors need a password (2.14, Core\PageLock) – never the password or its hash; it is set in the admin
        $page['password_protected'] = $this->app->db()->value('SELECT password_hash IS NOT NULL FROM {pages} WHERE page_id = ?', [(int) $page['ids']]) == 1;
        if ($page['image'] === '' && $this->app->settings()->get('share_image') === '') {
            // the picture the site draws for sharing (2.12) – the same title as on the page (the home page has none)
            $title = $page['seo_title'] !== '' ? $page['seo_title'] : ((int) $page['ids'] === $this->app->settings()->int('home_page') ? '' : $page['title']);
            $generated = \Kaleta\Front\ShareImage::url($this->app, \Kaleta\Core\Facts::fillText($title, $this->app));
            if ($generated !== null) {
                $page['share_image_generated'] = $generated;
            }
        }
        // content check of the saved version (2.14, Core\ContentCheck), read-only – the same list the editor shows
        $builds = $this->app->db()->one('SELECT build, build_draft FROM {pages} WHERE page_id = ?', [(int) $page['ids']]) ?? [];
        $page['content_check'] = Language::runWith('en', fn (): array => \Kaleta\Core\ContentCheck::forPage($page + $builds), 'admin-');

        return $page;
    }

    /** translation_status (2.14, Core\Translations) */
    private function toolTranslationStatus(string $name, array $a): mixed
    {
        $matrix = \Kaleta\Core\Translations::matrix($this->app);
        $status = in_array($a['status'] ?? '', ['missing', 'outdated', 'all'], true) ? $a['status'] : '';
        $type = in_array($a['type'] ?? '', ['page', 'news', 'collection_item'], true) ? $a['type'] : '';
        $counts = ['missing' => 0, 'outdated' => 0, 'present' => 0];
        $items = [];
        foreach ($matrix['rows'] as $row) {
            foreach ($row['translations'] as $cell) {
                $counts[$cell['status']]++;
            }
            $keep = $status === 'all' ? $row['translations']
                : array_filter($row['translations'], fn (array $c): bool => $status === '' ? $c['status'] !== \Kaleta\Core\Translations::PRESENT : $c['status'] === $status);
            if ($keep === [] || ($type !== '' && $row['type'] !== $type)) {
                continue;
            }
            $items[] = ['type' => $row['type'], 'id' => $row['id'], 'title' => $row['title'], 'changed' => $row['changed']]
                + (isset($row['collection']) ? ['collection' => $row['collection']['slug']] : []) + ['translations' => $keep];
        }

        return ['default_language' => Language::defaults($this->app->settings()), 'languages' => $matrix['languages'], 'summary' => $counts, 'items' => $items,
            'next' => $matrix['languages'] === [] ? 'The site has a single language; language versions are set with update_settings (additional_languages).'
                : 'Translate only on the user\'s instruction and as drafts: a page with create_page (language, translation_of, copy_build) and edit_build; a news item with create_news in a category of that language; a collection item with save_collection_item (language, the same slug). An outdated translation needs its texts compared with the original.'];
    }

    /** create_page and update_page (vytvor_stranku, uprav_stranku) */
    private function toolCreatePage(string $name, array $a): mixed
    {
        $auth = $this->app->auth();

        if (!$auth->hasModule('pages')) {
            throw new \DomainException('Stránky smí upravovat editor nebo správce.');
        }

        return $this->savePage($name === 'uprav_stranku' ? $this->page((int) ($a['id'] ?? 0)) : null, $a);
    }

    /** update_page: the same as create_page */
    private function toolUpdatePage(string $name, array $a): mixed
    {
        return $this->toolCreatePage($name, $a);
    }

    /** trash_page (smaz_stranku) */
    private function toolTrashPage(string $name, array $a): mixed
    {
        $auth = $this->app->auth();
        $db = $this->app->db();
        $siteSettings = $this->app->settings();

        if (!$auth->canPublish() || !$auth->hasModule('pages')) {
            throw new \DomainException('Stránku smí smazat editor nebo správce.');
        }
        $page = $this->page((int) ($a['id'] ?? 0));
        if ((int) $page['ids'] === $siteSettings->int('home_page')) {
            throw new \DomainException('Úvodní stránku smazat nejde – nejdřív nastav jinou (uprav_nastaveni → titulni_stranka).');
        }
        $db->run('UPDATE {pages} SET deleted_at = NOW(), visible = 0 WHERE page_id = ? AND deleted_at IS NULL', [(int) $page['ids']]);
        \Kaleta\Front\Cache::clear();

        return ['id' => (int) $page['ids'], 'status' => 'v koši – obnovit jde 30 dní v administraci (Stránky → Koš)'];
    }

    /** get_menu and save_menu (nacti_menu, uloz_menu) */
    private function toolGetMenu(string $name, array $a): mixed
    {
        $auth = $this->app->auth();
        $db = $this->app->db();
        $siteSettings = $this->app->settings();

        $location = isset(\Kaleta\Core\Menu::LOCATIONS[$a['location'] ?? '']) ? $a['location'] : 'hlavni';
        $menuLanguage = in_array($a['language'] ?? '', Language::additional($siteSettings), true) ? $a['language'] : '';
        if ($name === 'uloz_menu') {
            if (!$auth->isAdmin()) {
                throw new \DomainException('Menu smí upravovat jen správce.');
            }
            // null restores the automatic menu – only when the caller really sent it, not when items are missing or cannot be read
            if (!array_key_exists('items', $a) || ($a['items'] !== null && !is_array($a['items']))) {
                throw new \InvalidArgumentException('Parametr polozky musí být seznam položek menu, nebo null pro automatické menu.');
            }
            \Kaleta\Core\Look::setMenu($siteSettings, $location, $menuLanguage, $a['items']); // to the draft look
        }
        [$inDraft, $saved] = \Kaleta\Core\Look::menuForEditing($db, $siteSettings, $location, $menuLanguage);

        return ['location' => $location, 'language' => $menuLanguage, 'automaticke' => $saved === null, 'items' => $saved ?? [],
            'look_draft' => $inDraft ? 'the items are in the draft look – visitors see them after publish_look' : null,
            'na_webu' => \Kaleta\Core\Menu::items($this->app, $location, $menuLanguage, $siteSettings->int('home_page')),
            'pages' => $db->all('SELECT page_id, title, visible FROM {pages} WHERE language = ? AND deleted_at IS NULL ORDER BY sort_order, title', [$menuLanguage])];
    }

    /** save_menu: the same as get_menu */
    private function toolSaveMenu(string $name, array $a): mixed
    {
        return $this->toolGetMenu($name, $a);
    }

    /** list_trash */
    private function toolListTrash(string $name, array $a): mixed
    {
        $auth = $this->app->auth();
        $db = $this->app->db();

        $out = [];
        if ($auth->hasModule('pages')) {
            $out['pages'] = array_map(fn (array $r): array => ['id' => (int) $r['ids'], 'title' => $r['title'], 'deleted_at' => substr((string) $r['deleted_at'], 0, 16)],
                $db->all('SELECT page_id, title, deleted_at FROM {pages} WHERE deleted_at IS NOT NULL ORDER BY deleted_at DESC LIMIT 100'));
        }
        if ($auth->hasModule('news') && \Kaleta\Core\Extensions::isEnabled($this->app->settings(), 'novinky')) {
            $out['news'] = array_map(fn (array $r): array => ['id' => (int) $r['idc'], 'title' => $r['title'], 'deleted_at' => substr((string) $r['deleted_at'], 0, 16)],
                $db->all('SELECT news_id, title, deleted_at FROM {news} WHERE deleted_at IS NOT NULL' . ($auth->canPublish() ? '' : ' AND autor = ' . (int) $auth->id()) . ' ORDER BY deleted_at DESC LIMIT 100'));
        }
        if ($auth->hasModule('collections')) {
            $out['collection_items'] = array_map(fn (array $r): array => ['id' => (int) $r['idp'], 'collection' => $r['kolekce'], 'name' => $r['nazev'], 'deleted_at' => substr((string) $r['deleted_at'], 0, 16)],
                $db->all('SELECT p.item_id, p.name, p.deleted_at, k.slug AS kolekce FROM {collection_items} p JOIN {collections} k ON k.collection_id = p.collection_id WHERE p.deleted_at IS NOT NULL ORDER BY p.deleted_at DESC LIMIT 100'));
        }

        return $out;
    }

    /** restore_from_trash */
    private function toolRestoreFromTrash(string $name, array $a): mixed
    {
        $auth = $this->app->auth();
        $db = $this->app->db();
        $id = (int) ($a['id'] ?? 0);
        $need = function (bool $allowed, string $message): void {
            if (!$allowed) {
                throw new \DomainException($message);
            }
        };

        $type = (string) ($a['type'] ?? '');
        if ($type === 'page') {
            $need($auth->hasModule('pages'), 'Pages can be restored by editors and administrators.');
            $ok = $db->run('UPDATE {pages} SET deleted_at = NULL WHERE page_id = ? AND deleted_at IS NOT NULL', [$id])->rowCount() > 0;
        } elseif ($type === 'news') {
            $need($auth->hasModule('news'), 'News items can be restored only by users with the News section.');
            $ok = $db->run('UPDATE {news} SET deleted_at = NULL WHERE news_id = ? AND deleted_at IS NOT NULL' . ($auth->canPublish() ? '' : ' AND autor = ' . (int) $auth->id()), [$id])->rowCount() > 0;
        } elseif ($type === 'collection_item') {
            $need($auth->hasModule('collections'), 'Collection items can be restored only by users with the Collections section.');
            $ok = $db->run('UPDATE {collection_items} SET deleted_at = NULL WHERE item_id = ? AND deleted_at IS NOT NULL', [$id])->rowCount() > 0;
        } else {
            throw new \InvalidArgumentException('type must be page, news or collection_item.');
        }
        if (!$ok) {
            throw new \InvalidArgumentException('It is not in the trash. Use list_trash.');
        }

        return ['restored' => $type, 'id' => $id, 'visible' => false];
    }
}
