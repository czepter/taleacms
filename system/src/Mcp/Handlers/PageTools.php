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
    /** site_info */
    private function toolSiteInfo(string $name, array $a): mixed
    {
        $auth = $this->app->auth();
        $db = $this->app->db();
        $siteSettings = $this->app->settings();
        // the agent notebook (2.15, Core\Notebook): how many notes colleagues and earlier conversations left, and the pinned titles – read_notebook has them all
        $notebook = \Kaleta\Core\Notebook::summary($db);

        return [
            'site' => $siteSettings->get('site_name'), 'url' => $this->app->request->origin() . $this->app->url(''), 'description' => $siteSettings->get('site_description'),
            'home_page' => $siteSettings->int('home_page') ?: null, 'kaleta_version' => KALETA_VERSION,
            'pages' => (int) $db->value('SELECT COUNT(*) FROM {pages} WHERE deleted_at IS NULL'),
            'published_news' => (int) $db->value('SELECT COUNT(*) FROM {news} WHERE visible = 1 AND published_at <= NOW() AND deleted_at IS NULL'),
            'username' => $auth->user()['username'], 'role' => \Kaleta\Core\Auth::TYPES[(int) $auth->user()['admin']], 'can_publish' => $auth->canPublish(),
            'can_edit_pages' => $auth->hasModule('pages'),
            // what this connection may do (2.2): full, drafts (reads and drafts, never publishes) or read
            'connection' => $auth->connection() ?? ['name' => '', 'access' => 'full'],
            // what the site has switched on, so Claude does not guess (extension keys: news, enquiries, newsletter_signup…)
            'extensions' => \Kaleta\Core\Extensions::enabled($siteSettings),
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

    /** list_pages */
    private function toolListPages(string $name, array $a): mixed
    {
        $auth = $this->app->auth();
        $db = $this->app->db();
        $siteSettings = $this->app->settings();

        $home = $siteSettings->int('home_page');

        // the URL of a language version has a prefix (/de/…); the translation of the home page is the root of its version (/de/)
        return array_map(fn (array $r): array => ['id' => (int) $r['page_id'], 'title' => $r['title'], 'url' => $this->app->request->origin()
            . $this->app->url(($r['language'] !== '' ? $r['language'] . '/' : '') . ((int) $r['page_id'] === $home || ($home > 0 && (int) $r['translation_of'] === $home) ? '' : $r['slug'])),
            'home' => (int) $r['page_id'] === $home, 'visible' => (bool) $r['visible'], 'in_menu' => (bool) $r['in_menu'], 'language' => $r['language']],
            // without the Pages section (news author) only published pages – it can link to those, it does not see drafts
            $db->all('SELECT page_id, title, slug, visible, in_menu, language, translation_of FROM {pages} WHERE deleted_at IS NULL' . ($auth->hasModule('pages') ? '' : ' AND visible = 1') . ' ORDER BY language, sort_order, title'));
    }

    /** get_page */
    private function toolGetPage(string $name, array $a): mixed
    {
        $auth = $this->app->auth();

        $page = $this->page((int) ($a['id'] ?? 0));
        if (!$page['visible'] && !$auth->hasModule('pages')) {
            throw new \InvalidArgumentException('The page does not exist. Use list_pages.');
        }
        // whether visitors need a password (2.14, Core\PageLock) – never the password or its hash; it is set in the admin
        $page['password_protected'] = $this->app->db()->value('SELECT password_hash IS NOT NULL FROM {pages} WHERE page_id = ?', [(int) $page['page_id']]) == 1;
        if ($page['image'] === '' && $this->app->settings()->get('share_image') === '') {
            // the picture the site draws for sharing (2.12) – the same title as on the page (the home page has none)
            $title = $page['seo_title'] !== '' ? $page['seo_title'] : ((int) $page['page_id'] === $this->app->settings()->int('home_page') ? '' : $page['title']);
            $generated = \Kaleta\Front\ShareImage::url($this->app, \Kaleta\Core\Facts::fillText($title, $this->app));
            if ($generated !== null) {
                $page['share_image_generated'] = $generated;
            }
        }
        // content check of the saved version (2.14, Core\ContentCheck), read-only – the same list the editor shows
        $builds = $this->app->db()->one('SELECT build, build_draft FROM {pages} WHERE page_id = ?', [(int) $page['page_id']]) ?? [];
        $page['content_check'] = Language::runWith('en', fn (): array => \Kaleta\Core\ContentCheck::forPage($page + $builds), 'admin-');

        return ['id' => $page['page_id'], 'visible' => $page['visible'], 'order' => $page['sort_order'], 'parent' => $page['parent_id']]
            + array_diff_key($page, ['page_id' => 1, 'visible' => 1, 'sort_order' => 1, 'parent_id' => 1, 'text' => 1]) + ['content' => $page['text']];
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

    /** create_page and update_page */
    private function toolCreatePage(string $name, array $a): mixed
    {
        $auth = $this->app->auth();

        if (!$auth->hasModule('pages')) {
            throw new \DomainException('Only editors and administrators can change pages.');
        }

        return $this->savePage($name === 'update_page' ? $this->page((int) ($a['id'] ?? 0)) : null, $a);
    }

    /** update_page: the same as create_page */
    private function toolUpdatePage(string $name, array $a): mixed
    {
        return $this->toolCreatePage($name, $a);
    }

    /** trash_page */
    private function toolTrashPage(string $name, array $a): mixed
    {
        $auth = $this->app->auth();
        $db = $this->app->db();
        $siteSettings = $this->app->settings();

        if (!$auth->canPublish() || !$auth->hasModule('pages')) {
            throw new \DomainException('Only editors and administrators can delete pages.');
        }
        $page = $this->page((int) ($a['id'] ?? 0));
        if ((int) $page['page_id'] === $siteSettings->int('home_page')) {
            throw new \DomainException('The home page cannot be deleted – set another one first (update_settings → home_page).');
        }
        $db->run('UPDATE {pages} SET deleted_at = NOW(), visible = 0 WHERE page_id = ? AND deleted_at IS NULL', [(int) $page['page_id']]);
        \Kaleta\Front\Cache::clear();

        return ['id' => (int) $page['page_id'], 'status' => 'in the trash – it can be restored for 30 days in the admin (Pages → Trash)'];
    }

    /** get_menu and save_menu */
    private function toolGetMenu(string $name, array $a): mixed
    {
        $auth = $this->app->auth();
        $db = $this->app->db();
        $siteSettings = $this->app->settings();

        $location = isset(\Kaleta\Core\Menu::LOCATIONS[$a['location'] ?? '']) ? $a['location'] : 'main';
        $menuLanguage = in_array($a['language'] ?? '', Language::additional($siteSettings), true) ? $a['language'] : '';
        if ($name === 'save_menu') {
            if (!$auth->isAdmin()) {
                throw new \DomainException('Only administrators can change the menu.');
            }
            // null restores the automatic menu – only when the caller really sent it, not when items are missing or cannot be read
            if (!array_key_exists('items', $a) || ($a['items'] !== null && !is_array($a['items']))) {
                throw new \InvalidArgumentException('The items parameter must be a list of menu items, or null for the automatic menu.');
            }
            \Kaleta\Core\Look::setMenu($siteSettings, $location, $menuLanguage, $a['items']); // to the draft look
        }
        [$inDraft, $saved] = \Kaleta\Core\Look::menuForEditing($db, $siteSettings, $location, $menuLanguage);

        return ['location' => $location, 'language' => $menuLanguage, 'automatic' => $saved === null, 'items' => $saved ?? [],
            'look_draft' => $inDraft ? 'the items are in the draft look – visitors see them after publish_look' : null,
            'on_site' => \Kaleta\Core\Menu::items($this->app, $location, $menuLanguage, $siteSettings->int('home_page')),
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
            $out['pages'] = array_map(fn (array $r): array => ['id' => (int) $r['page_id'], 'title' => $r['title'], 'deleted_at' => substr((string) $r['deleted_at'], 0, 16)],
                $db->all('SELECT page_id, title, deleted_at FROM {pages} WHERE deleted_at IS NOT NULL ORDER BY deleted_at DESC LIMIT 100'));
        }
        if ($auth->hasModule('news') && \Kaleta\Core\Extensions::isEnabled($this->app->settings(), 'news')) {
            $out['news'] = array_map(fn (array $r): array => ['id' => (int) $r['news_id'], 'title' => $r['title'], 'deleted_at' => substr((string) $r['deleted_at'], 0, 16)],
                $db->all('SELECT news_id, title, deleted_at FROM {news} WHERE deleted_at IS NOT NULL' . ($auth->canPublish() ? '' : ' AND author_id = ' . (int) $auth->id()) . ' ORDER BY deleted_at DESC LIMIT 100'));
        }
        if ($auth->hasModule('collections')) {
            $out['collection_items'] = array_map(fn (array $r): array => ['id' => (int) $r['item_id'], 'collection' => $r['collection'], 'name' => $r['name'], 'deleted_at' => substr((string) $r['deleted_at'], 0, 16)],
                $db->all('SELECT p.item_id, p.name, p.deleted_at, k.slug AS collection FROM {collection_items} p JOIN {collections} k ON k.collection_id = p.collection_id WHERE p.deleted_at IS NOT NULL ORDER BY p.deleted_at DESC LIMIT 100'));
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
            $ok = $db->run('UPDATE {news} SET deleted_at = NULL WHERE news_id = ? AND deleted_at IS NOT NULL' . ($auth->canPublish() ? '' : ' AND author_id = ' . (int) $auth->id()), [$id])->rowCount() > 0;
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
