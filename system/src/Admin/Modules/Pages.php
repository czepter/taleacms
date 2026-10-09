<?php

declare(strict_types=1);

namespace Kaleta\Admin\Modules;

use Kaleta\Admin\Module;
use Kaleta\Core\Response;
use Kaleta\Builder\Publisher;
use Kaleta\Builder\Build;

/**
 * Site pages: home, About us, Services, Contact, Privacy policy… The home page is set in Nastavení → Základní (Settings → General).
 * A page has the URL /<seo_link>. The content is either text from the editor, or a build from the builder (column stavba, the draft stavba_koncept).
 */
final class Pages extends Module
{
    use \Kaleta\Admin\BuilderActions {
        actionBuilder as protected openBuilder;
    }

    public const string IDENT = 'pages';
    public const string NAME = 'Pages';
    public const string GROUP = 'Content';
    public const string ICON = 'pages';

    /** Slugs that belong to the system and a page cannot have. */
    public const array RESERVED_SLUGS = ['novinky', 'hledani', 'news', 'search', 'mcp', 'api', 'admin', 'install', 'media', 'image', 'layout', 'system', 'storage', 'tools', 'docs', 'dist', 'rss', 'sitemap', 'robots', 'llms', 'feed', 'status', 'ulohy', 'souhlas', 'form', 'popup', 'vitals', 'konverze', 'download', 'screen', 'og', '_report'];

    /** Pages in the trash last this many days, then they are deleted permanently (like news). */
    public const int TRASH_DAYS = 30;

    protected function actionList(): Response
    {
        $trash = $this->request->get('status') === 'kos';
        $search = mb_substr(trim($this->request->get('search')), 0, 100);
        [$siteLanguages, $language, $column] = $this->readLanguageFilter();
        $where = [$trash ? 'deleted_at IS NOT NULL' : 'deleted_at IS NULL'];
        $params = [];
        if ($column !== null) {
            $where[] = 'language = ?';
            $params[] = $column;
        }
        if ($search !== '') {
            $where[] = '(title LIKE ? OR slug LIKE ?)';
            $pattern = '%' . addcslashes($search, '%_\\') . '%';
            array_push($params, $pattern, $pattern);
        }

        $pages = $this->db->all('SELECT * FROM {pages} WHERE ' . implode(' AND ', $where) . ' ORDER BY ' . ($trash ? 'deleted_at DESC' : 'language, sort_order, title'), $params);

        // column "V navigaci" (In navigation): with a custom menu by the menu items (the page or a link to its URL), otherwise the flag v_menu
        foreach ($pages as &$s) {
            $s['in_menu'] = \Kaleta\Core\Menu::hasPage($this->db, (int) $s['page_id'], (string) $s['language'], (string) $s['slug']) ?? (bool) $s['in_menu'];
        }
        unset($s);

        return $this->view('list', 'Pages', [
            'pages' => $trash || $search !== '' ? $pages : self::sortAsTree($pages),
            'trash' => $trash, 'search' => $search, 'siteLanguages' => $siteLanguages, 'language' => $language,
            'comments' => $trash ? [] : \Kaleta\Core\DraftComments::unresolvedCounts($this->db), // unresolved comments from shared previews (2.15)
            'inTrash' => (int) $this->db->value('SELECT COUNT(*) FROM {pages} WHERE deleted_at IS NOT NULL'),
        ]);
    }

    /**
     * Pages sorted as a tree: a subpage right after its parent, with the depth (key "uroven") for indentation in the list.
     *
     * @param list<array<string, mixed>> $pages
     * @return list<array<string, mixed>>
     */
    private static function sortAsTree(array $pages): array
    {
        $byParent = [];
        foreach ($pages as $s) {
            $byParent[(int) ($s['parent_id'] ?? 0)][] = $s;
        }
        $ids = array_column($pages, 'page_id');
        $result = [];
        $add = function (int $parent, int $level) use (&$add, &$result, $byParent): void {
            foreach ($byParent[$parent] ?? [] as $s) {
                $result[] = $s + ['level' => $level];
                if ($level < 4) {
                    $add((int) $s['page_id'], $level + 1);
                }
            }
        };
        $add(0, 0);
        // pages whose parent is in the trash or does not exist are shown at the top level
        foreach ($byParent as $parent => $children) {
            if ($parent !== 0 && !in_array($parent, array_map('intval', $ids), true)) {
                foreach ($children as $s) {
                    $result[] = $s + ['level' => 0];
                }
            }
        }

        return $result;
    }

    protected function actionNew(): Response
    {
        // from the translation overview (2.14): the language version and the original are filled in
        $language = \Kaleta\Core\Language::column($this->app->settings(), $this->request->get('language'));
        $original = $language !== '' ? $this->db->one("SELECT page_id, title, description FROM {pages} WHERE page_id = ? AND language = '' AND deleted_at IS NULL", [$this->request->getInt('translation_of')]) : null;

        return $this->form(['page_id' => 0, 'slug' => '', 'title' => $original['title'] ?? '', 'description' => $original['description'] ?? '', 'seo_title' => '', 'image' => '', 'noindex' => 0, 'text' => '', 'visible' => 1, 'in_menu' => 1, 'sort_order' => 100, 'build' => null, 'build_draft' => null,
            'parent_id' => $this->request->getInt('parent') ?: null, 'publish_at' => null, 'valid_until' => null, 'review_by' => null, 'language' => $language, 'translation_of' => $original['page_id'] ?? null]);
    }

    /** Translation overview (2.14, Core\Translations): what is missing or older than the original in each language version. */
    protected function actionTranslations(): Response
    {
        $auth = $this->app->auth();

        return $this->view('translations', 'Translations', \Kaleta\Core\Translations::matrix($this->app) + [
            'assistant' => (new \Kaleta\Core\Assistant($this->app->settings()))->isReady(),
            'news' => $auth->hasModule('news'), 'collections' => $auth->hasModule('collections'),
        ]);
    }

    /** Actions the list does with ticked pages (2.14): show, hide, language version, trash. Each page is checked as if edited alone. */
    protected function actionBulk(): Response
    {
        if (!$this->request->isPost()) {
            return $this->back();
        }
        $action = $this->request->post('provest');
        if (!in_array($action, ['visible', 'skryt', 'language', 'kos'], true)) {
            return $this->back('Unknown action.', '', [], 'error');
        }
        $auth = $this->app->auth();
        $home = $this->app->settings()->int('home_page');
        $language = \Kaleta\Core\Language::column($this->app->settings(), $this->request->post('language'));
        $done = 0;
        $skipped = 0;
        foreach (array_unique(array_map(intval(...), $this->request->postList('oznacene'))) as $id) {
            $page = $this->loadPage($id);
            // authors may only touch hidden pages and never publish; the home page stays visible and out of the trash
            if ($page === null || (!$auth->canPublish() && ($page['visible'] || $action === 'visible')) || ($id === $home && in_array($action, ['skryt', 'kos'], true))) {
                $skipped++;
                continue;
            }
            match ($action) {
                'visible' => $this->db->update('pages', ['visible' => 1, 'publish_at' => null, 'updated_at' => date('Y-m-d H:i:s')], ['page_id' => $id]),
                'skryt' => $this->db->update('pages', ['visible' => 0, 'updated_at' => date('Y-m-d H:i:s')], ['page_id' => $id]),
                'language' => $this->db->update('pages', ['language' => $language, 'translation_of' => $language === '' ? null : $page['translation_of'], 'updated_at' => date('Y-m-d H:i:s')], ['page_id' => $id]),
                default => $this->db->run('UPDATE {pages} SET deleted_at = NOW(), visible = 0 WHERE page_id = ?', [$id]),
            };
            \Kaleta\Admin\ChangeLog::write($this->app, 'pages', 'bulk ' . ['visible' => 'shown', 'skryt' => 'hidden', 'language' => 'language ' . ($language ?: 'default'), 'kos' => 'moved to trash'][$action], mb_substr($page['title'], 0, 80));
            $done++;
        }
        if ($done > 0) {
            \Kaleta\Front\Cache::clear();
        }
        $message = match ($action) {
            'visible' => t('Pages published: %d.', $done), 'skryt' => t('Pages hidden: %d.', $done),
            'language' => t('Pages moved to the language version: %d.', $done), default => t('Pages moved to the trash: %d.', $done),
        };

        return $this->back($message . ($skipped > 0 ? ' ' . t('Skipped: %d (no permission, or the home page).', $skipped) : ''), '', [], $done > 0 ? 'ok' : 'error');
    }

    protected function actionEdit(): Response
    {
        $page = $this->db->one('SELECT * FROM {pages} WHERE page_id = ? AND deleted_at IS NULL', [$this->request->getInt('id')]);

        return $page === null ? $this->error('Page does not exist.', 404) : $this->form($page);
    }

    /** Saving from editing "directly on the site" (views/front/upravit.php): only the page's name and text. */
    protected function actionSaveText(): Response
    {
        $r = $this->request;
        $page = $r->isPost() ? $this->db->one('SELECT * FROM {pages} WHERE page_id = ? AND deleted_at IS NULL', [$r->postInt('id')]) : null;
        if ($page === null || (!$this->app->auth()->canPublish() && $page['visible'])) {
            return $this->redirectToSite($r->post('zpet'));
        }
        $title = mb_substr($r->post('title'), 0, 200);
        if ($title === '') {
            return $this->redirectToSite($r->post('zpet'), '?edit=text&error=1');
        }
        $text = \Kaleta\Core\Html::forUser($r->post('text'), $this->app->auth());
        if ($page['title'] !== $title || (string) $page['text'] !== $text) {
            $this->saveVersion((int) $page['page_id'], $page['title'], (string) $page['text']); // an edit directly on the site goes to the history as in the admin
        }
        $this->db->update('pages', ['title' => $title, 'text' => $text, 'updated_at' => date('Y-m-d H:i:s')], ['page_id' => $page['page_id']]);
        \Kaleta\Admin\ChangeLog::write($this->app, 'pages', 'edited directly on the site', mb_substr($title, 0, 80));

        return $this->redirectToSite($r->post('zpet'));
    }

    /**
     * Roles at the author level (custom roles without the permission to publish too) can prepare pages, but not publish them,
     * change published ones or delete them. Returns a response with the refusal, or null when allowed.
     */
    private function requirePublishPermission(?array $page = null): ?Response
    {
        if ($this->app->auth()->canPublish() || ($page !== null && !$page['visible'])) {
            return null;
        }

        return $this->back('Only an editor or administrator edits, publishes and deletes published pages. You can prepare a new hidden page.', '', [], 'error');
    }

    protected function actionSave(): Response
    {
        if (!$this->request->isPost()) {
            return $this->back();
        }
        $r = $this->request;
        $id = $r->postInt('page_id');
        if ($id > 0 && ($refusal = $this->requirePublishPermission($this->db->one('SELECT visible FROM {pages} WHERE page_id = ?', [$id]) ?? ['visible' => 1])) !== null) {
            return $refusal;
        }
        $language = \Kaleta\Core\Language::column($this->app->settings(), $r->post('language'));
        // parent page: the same language, not the page itself nor its subpage (otherwise a cycle would form)
        $custom = $id > 0 ? (string) $this->db->value('SELECT slug FROM {pages} WHERE page_id = ?', [$id]) : '';
        $parent = $r->postInt('parent_id') > 0 ? $this->db->one('SELECT page_id, slug FROM {pages} WHERE page_id = ? AND page_id <> ? AND language = ? AND deleted_at IS NULL', [$r->postInt('parent_id'), $id, $language]) : null;
        if ($parent !== null && $custom !== '' && str_starts_with($parent['slug'] . '/', $custom . '/')) {
            $parent = null;
        }
        $prefix = $parent !== null ? $parent['slug'] . '/' : '';
        $slug = slugify($r->post('slug') !== '' ? basename(str_replace('\\', '/', $r->post('slug'))) : $r->post('title'), max(20, 118 - strlen($prefix)));
        $data = [
            'title' => mb_substr($r->post('title'), 0, 200),
            'slug' => $prefix . $slug,
            'parent_id' => $parent !== null ? (int) $parent['page_id'] : null,
            'description' => mb_substr($r->post('description'), 0, 300),
            'seo_title' => mb_substr(trim($r->post('seo_title')), 0, 200),
            'image' => mb_substr(trim($r->post('image')), 0, 255),
            'noindex' => (int) $r->postBool('noindex'),
            'text' => \Kaleta\Core\Html::forUser($r->post('text'), $this->app->auth()),
            'visible' => (int) $r->postBool('visible'),
            'in_menu' => (int) $r->postBool('in_menu'),
            'sort_order' => max(0, min(65535, $r->postInt('sort_order', 100))),
            'updated_at' => date('Y-m-d H:i:s'),
            'language' => $language,
            // true until and review by (2.10, Core\Validity): empty or not a date = none
            'valid_until' => \Kaleta\Core\Validity::date($r->post('valid_until')),
            'review_by' => \Kaleta\Core\Validity::date($r->post('review_by')),
        ];
        if ($this->app->auth()->isAdmin() && !\Kaleta\Core\Demo::active()) {
            // code in <head> of this page only (2.3); never in the public demo – administrators, like the code for the whole site
            $data['head_code'] = trim((string) ($_POST['head_code'] ?? '')) !== '' ? mb_substr((string) $_POST['head_code'], 0, 20000) : null;
        }
        // scheduled publishing: only for a hidden page with a future time; a past time publishes the page right away
        $from = strtotime(str_replace('T', ' ', $r->post('publish_at'))) ?: null;
        $data['publish_at'] = !$data['visible'] && $from !== null && $from > time() ? date('Y-m-d H:i:s', $from) : null;
        if (!$data['visible'] && $from !== null && $from <= time()) {
            $data['visible'] = 1;
        }
        if (!$this->app->auth()->canPublish()) {
            $data['visible'] = 0; // without the permission to publish the page stays hidden, an editor publishes it
            $data['publish_at'] = null;
        }
        $data['translation_of'] = $data['language'] === '' ? null : ($this->db->value("SELECT page_id FROM {pages} WHERE page_id = ? AND language = '' AND page_id <> ?", [$r->postInt('translation_of'), $id]) ?: null);
        $errors = [];
        if ($data['title'] === '') {
            $errors['title'] = 'Enter the page title.';
        }
        if ($r->post('slug') === '') {
            // slug from the name: a taken one gets a number (o-nas-2), as with news
            $data['slug'] = $this->availableSlug($data['slug'], $id);
        }
        if ($parent === null && (in_array($data['slug'], self::RESERVED_SLUGS, true) || isset(\Kaleta\Core\Language::AVAILABLE[$data['slug']]) || \Kaleta\Core\Routes::isNewsSlug($data['slug'], $this->db))) {
            $errors['slug'] = 'This URL is used by the system, choose another one.';
        } elseif (($other = $this->db->one('SELECT page_id, deleted_at FROM {pages} WHERE slug = ? AND page_id <> ?', [$data['slug'], $id])) !== null) {
            $errors['slug'] = $other['deleted_at'] !== null ? 'A page in the trash uses this address – restore it or delete it permanently.' : 'A page with this URL already exists.';
        }
        // the page password (2.14, Core\PageLock): empty = unchanged, a tick removes it; only its hash is stored
        [$passwordHash, $passwordError] = \Kaleta\Core\PageLock::fromForm($r->post('heslo_stranky'), $r->postBool('heslo_zrusit'));
        if ($passwordError !== '') {
            $errors['heslo_stranky'] = $passwordError;
        } elseif ($passwordHash !== null) {
            $data['password_hash'] = $passwordHash === '' ? null : $passwordHash;
        }
        if ($id > 0 && $id === $this->app->settings()->int('home_page') && !$data['visible']) {
            $errors['visible'] = 'The home page cannot be hidden. First choose another home page in Settings → General.';
        }
        if ($errors !== []) {
            $previous = $id > 0 ? $this->db->one('SELECT build, build_draft, password_hash FROM {pages} WHERE page_id = ?', [$id]) : null;

            return $this->form(['page_id' => $id] + $data + ($previous ?? ['build' => null, 'build_draft' => null]), $errors);
        }
        if ($id > 0) {
            $previous = $this->db->one('SELECT slug, visible, title, text FROM {pages} WHERE page_id = ?', [$id]);
            if ($previous !== null && ($previous['text'] !== $data['text'] || $previous['title'] !== $data['title'])) {
                $this->saveVersion($id, $previous['title'], (string) $previous['text']);
            }
            $this->db->update('pages', $data, ['page_id' => $id]);
            if ($previous !== null && $previous['slug'] !== $data['slug']) {
                $this->moveSubpages($previous['slug'], $data['slug'], (bool) $previous['visible']);
            }
        } else {
            $id = $this->db->insert('pages', $data);
            $template = \Kaleta\Builder\Library::PAGE_TEMPLATES[$r->post('sablona')] ?? null;
            if ($template !== null && $template[1] !== []) {
                // new page from a template: sections from the library as a draft and straight into the builder
                $build = \Kaleta\Builder\Library::page($this->db, $template[1], $data['title'], $this->contentLanguage($language));
                $this->db->update('pages', ['build_draft' => Build::toJson($build)], ['page_id' => $id]);
                \Kaleta\Core\Menu::setPage($this->db, $id, $language, (bool) $data['in_menu']);

                return \Kaleta\Core\Response::redirect($this->url('builder', ['id' => $id]));
            }
            if ($template !== null && $data['text'] === '') {
                // in the page's language, from what the site has switched on (1.9)
                $text = \Kaleta\Core\Language::runWith($this->contentLanguage($language), fn (): string => \Kaleta\Builder\Library::privacyPolicyText($this->app->settings()));
                $this->db->update('pages', ['text' => $text], ['page_id' => $id]);

                return $this->back('The page has been created with a privacy policy outline – fill in the details in square brackets.', 'edit', ['id' => $id]);
            }
        }
        // assembled menu (Vzhled → Menu, Appearance → Menu): the checkbox "v navigaci" (in navigation) adds the page to the menu or removes it
        \Kaleta\Core\Menu::setPage($this->db, $id, $data['language'], (bool) $data['in_menu']);
        if ($r->post('po_ulozeni') === 'stavitel') {
            return \Kaleta\Core\Response::redirect($this->url('builder', ['id' => $id]));
        }

        return $this->back('Page saved.');
    }

    /* ---------- builder (actions in Admin\BuilderActions) ---------- */

    /** Editor; a text page is converted to a build on first opening (a narrow section with a heading and the text, the text stays). */
    protected function actionBuilder(): Response
    {
        $page = $this->loadPage($this->request->getInt('id'));
        if ($page !== null && $page['build'] === null && $page['build_draft'] === null) {
            $this->db->update('pages', ['build_draft' => Build::toJson(Build::fromText($page['title'], (string) $page['text']))], ['page_id' => $page['page_id']]);
        }

        return $this->openBuilder();
    }

    protected function loadBuildTarget(): ?array
    {
        $page = $this->loadPage($this->request->getInt('id'));

        return $page === null ? null : [
            'radek' => $page, 'build' => $page['build'], 'koncept' => $page['build_draft'], 'language' => $this->contentLanguage($page['language']),
            'title' => $page['title'], 'revize' => ['page_id' => (int) $page['page_id']], 'parametry' => ['id' => (int) $page['page_id']],
        ];
    }

    protected function saveDraft(array $target, ?string $draft): void
    {
        $this->db->update('pages', ['build_draft' => $draft], ['page_id' => $target['radek']['page_id']]);
    }

    protected function publishTarget(array $target): void
    {
        Publisher::page($this->app, $target['radek']);
    }

    protected function describeTarget(array $target): array
    {
        $page = $target['radek'];
        $home = $this->app->settings()->int('home_page') === (int) $page['page_id'];
        $url = $this->app->url(($page['language'] !== '' ? $page['language'] . '/' : '') . ($home ? '' : $page['slug']));

        return [
            'adresa' => $url, 'nahled' => $url . '?build=koncept&editor=1', 'zobrazena' => (bool) $page['visible'], 'casti' => false, 'nadpisy' => true,
            'zpet' => ['adresa' => $this->url(), 'text' => t('Pages')], 'nastaveni' => $this->url('edit', ['id' => (int) $page['page_id']]),
            'podpis' => 'stranka:' . (int) $page['page_id'],
        ];
    }

    /** Publishes the page draft (from MCP too). */
    public static function publish(\Kaleta\Core\App $app, array $page): void
    {
        Publisher::page($app, $page);
    }

    /** The page returns to the text from the editor (the build stays in the versions). */
    protected function actionBuildText(): Response
    {
        $page = $this->request->isPost() ? $this->loadPage($this->request->postInt('page_id')) : null;
        if ($page !== null && $page['build'] !== null) {
            $this->db->insert('build_revisions', ['page_id' => $page['page_id'], 'created_at' => date('Y-m-d H:i:s'), 'user_id' => $this->app->auth()->id(), 'build' => $page['build']]);
            $this->db->update('pages', ['build' => null, 'build_draft' => null], ['page_id' => $page['page_id']]);
        }

        return $this->back('The page shows the text from the editor (the build\'s content without the layout). You will find the build in versions when you open the builder.', 'edit', ['id' => (int) ($page['page_id'] ?? 0)]);
    }

    /** @return array<string, mixed>|null */
    private function loadPage(int $id): ?array
    {
        return $this->db->one('SELECT * FROM {pages} WHERE page_id = ? AND deleted_at IS NULL', [$id]);
    }

    /** The previous form of the page text to the history (the last 30 versions). */
    private function saveVersion(int $ids, string $title, string $text): void
    {
        self::version($this->db, $ids, $this->app->auth()->id(), $title, $text);
    }

    /** The same for MCP and other inputs outside the module. */
    public static function version(\Kaleta\Core\Db $db, int $ids, int $who, string $title, string $text): void
    {
        $db->insert('page_revisions', ['page_id' => $ids, 'created_at' => date('Y-m-d H:i:s'), 'user_id' => $who, 'title' => $title, 'text' => $text]);
        $db->run('DELETE FROM {page_revisions} WHERE page_id = ? AND revision_id NOT IN (SELECT revision_id FROM (SELECT revision_id FROM {page_revisions} WHERE page_id = ? ORDER BY revision_id DESC LIMIT 30) t)', [$ids, $ids]);
    }

    /** The page changed its slug: subpages move with it and the old URLs of visible pages are redirected. */
    private function moveSubpages(string $old, string $newVersion, bool $visible): void
    {
        self::move($this->db, $old, $newVersion, $visible);
    }

    public static function move(\Kaleta\Core\Db $db, string $old, string $newVersion, bool $visible): void
    {
        if ($visible) {
            Redirects::add($db, $old, $newVersion);
        }
        foreach ($db->all('SELECT page_id, slug, visible FROM {pages} WHERE slug LIKE ?', [addcslashes($old, '%_\\') . '/%']) as $p) {
            $target = $newVersion . substr($p['slug'], strlen($old));
            $db->update('pages', ['slug' => $target], ['page_id' => $p['page_id']]);
            if ($p['visible']) {
                Redirects::add($db, $p['slug'], $target);
            }
        }
    }

    /** Restoring an older version of the page text (the current form goes to the history). */
    protected function actionRestoreVersion(): Response
    {
        $version = $this->request->isPost() ? $this->db->one('SELECT * FROM {page_revisions} WHERE revision_id = ?', [$this->request->postInt('revision_id')]) : null;
        $page = $version !== null ? $this->loadPage((int) $version['page_id']) : null;
        if ($page === null) {
            return $this->back();
        }
        if (($refusal = $this->requirePublishPermission($page)) !== null) {
            return $refusal;
        }
        $this->saveVersion((int) $page['page_id'], $page['title'], (string) $page['text']);
        $this->db->update('pages', ['title' => $version['title'], 'text' => $version['text'], 'updated_at' => date('Y-m-d H:i:s')], ['page_id' => $page['page_id']]);

        return $this->back(t('Version from %s restored.', format_date($version['created_at'], true)), 'edit', ['id' => (int) $page['page_id']]);
    }

    /**
     * The page as a JSON file – for transfer to another site running Kaleta. Version 2 (1.8) also carries the shared
     * classes and the components the build uses (Builder\PagePackage), so the page looks the same there.
     */
    protected function actionExport(): Response
    {
        $s = $this->loadPage($this->request->getInt('id'));
        if ($s === null) {
            return $this->error('Page does not exist.', 404);
        }
        $build = Build::fromJson($s['build_draft'] ?? $s['build']);
        $json = (string) json_encode(['format' => 'kaleta-stranka', 'verze' => 2, 'title' => $s['title'], 'popis' => $s['description'], 'text' => $s['text'],
            'build' => $build] + \Kaleta\Builder\PagePackage::collect($this->db, $build ?? []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

        return new Response($json, 200, ['Content-Type' => 'application/json; charset=utf-8', 'Content-Disposition' => 'attachment; filename="stranka-' . basename(str_replace('/', '-', $s['slug'])) . '.json"']);
    }

    /** Import of a page from a JSON export: a hidden page is created, the build goes through the validator like any other. */
    protected function actionImport(): Response
    {
        $file = $_FILES['soubor']['tmp_name'] ?? '';
        $data = $this->request->isPost() && is_uploaded_file($file) && filesize($file) < 5_000_000 ? json_decode((string) file_get_contents($file), true) : null;
        if (!is_array($data) || ($data['format'] ?? '') !== 'kaleta-stranka' || trim((string) ($data['title'] ?? '')) === '') {
            return $this->back('The file is not a page export.', '', [], 'error');
        }
        $title = mb_substr(trim((string) $data['title']), 0, 200);
        $record = ['title' => $title, 'slug' => $this->availableSlug(slugify($title, 110), 0), 'description' => mb_substr((string) ($data['popis'] ?? ''), 0, 300),
            'text' => \Kaleta\Core\WpContent::safeHtml((string) ($data['text'] ?? '')), 'visible' => 0, 'in_menu' => 0, 'updated_at' => date('Y-m-d H:i:s')];
        $created = ['tridy' => 0, 'komponenty' => 0];
        if (is_array($data['build'] ?? null)) {
            // the classes and components that came with it first: the build then points at this site's components
            [$pageBuild, $created] = \Kaleta\Builder\PagePackage::import($this->app->settings(), $data, $data['build'], $this->app->auth()->isAdmin());
            [$build, $errors] = Build::sanitize($pageBuild, $this->app->auth()->isAdmin());
            $record['build_draft'] = Build::toJson($build);
        }
        $id = $this->db->insert('pages', $record);
        $extra = ($data['tridy'] ?? []) !== [] || ($data['komponenty'] ?? []) !== [];

        return $this->back(match (true) {
            $extra && !$this->app->auth()->isAdmin() => t('The page has been imported as hidden – check it and publish it.') . ' ' . t('Its classes and components were not imported – only an administrator can add them.'),
            $extra => t('The page has been imported as hidden – check it and publish it.') . ' ' . t('New classes: %d, new components: %d (those the site already had were kept).', $created['tridy'], $created['komponenty']),
            default => 'The page has been imported as hidden – check it and publish it.',
        }, 'edit', ['id' => $id]);
    }

    /** A free slug derived from $base: o-nas, o-nas-2, o-nas-3… */
    private function availableSlug(string $base, int $id): string
    {
        return \Kaleta\Core\Slug::makeUnique($base, fn (string $a): bool => $this->db->value('SELECT 1 FROM {pages} WHERE slug = ? AND page_id <> ?', [$a, $id]) !== null, 120);
    }

    /** Deleting = moving to the trash: the page disappears from the site, the slug stays reserved and the page can be restored. */
    protected function actionDelete(): Response
    {
        $ids = $this->request->postInt('page_id');
        if (!$this->request->isPost()) {
            return $this->back();
        }
        if (($refusal = $this->requirePublishPermission()) !== null) {
            return $refusal;
        }
        if ($ids === $this->app->settings()->int('home_page')) {
            return $this->back('The home page cannot be deleted. First choose another home page in Settings → General.', '', [], 'error');
        }
        $this->db->run('UPDATE {pages} SET deleted_at = NOW(), visible = 0 WHERE page_id = ? AND deleted_at IS NULL', [$ids]);

        return $this->back(t('The page is in the trash. You can restore it for %d days.', self::TRASH_DAYS));
    }

    /** Restore from the trash: the page returns hidden, only the user publishes it. */
    protected function actionRestore(): Response
    {
        if ($this->request->isPost()) {
            $this->db->run('UPDATE {pages} SET deleted_at = NULL WHERE page_id = ?', [$this->request->postInt('page_id')]);
        }

        return $this->back('The page has been restored as hidden – publish it in its settings.');
    }

    protected function actionDeletePermanently(): Response
    {
        if (($refusal = $this->requirePublishPermission()) !== null) {
            return $refusal;
        }
        if ($this->request->isPost()) {
            $this->db->run('DELETE FROM {pages} WHERE page_id = ? AND deleted_at IS NOT NULL', [$this->request->postInt('page_id')]);
        }

        return $this->back('The page has been permanently deleted.', '', ['status' => 'kos']);
    }

    /** Pages in the trash longer than TRASH_DAYS are deleted permanently (called by Admin\Kernel). */
    public static function emptyTrash(\Kaleta\Core\Db $db): int
    {
        return $db->run('DELETE FROM {pages} WHERE deleted_at < NOW() - INTERVAL ' . self::TRASH_DAYS . ' DAY')->rowCount();
    }

    /** Copy of the page including the build and the work-in-progress draft – hidden, with a free slug. */
    protected function actionDuplicate(): Response
    {
        $page = $this->request->isPost() ? $this->loadPage($this->request->postInt('page_id')) : null;
        if ($page === null) {
            return $this->back();
        }
        $copy = array_diff_key($page, ['page_id' => 0, 'deleted_at' => 0]);
        $copy['title'] = mb_substr(t('%s (copy)', $page['title']), 0, 200);
        $copy['slug'] = $this->availableSlug(mb_substr($page['slug'] . '-kopie', 0, 110), 0);
        $copy['visible'] = 0;
        $copy['in_menu'] = 0; // the copy does not get into the navigation until someone adds it there
        $copy['translation_of'] = null;
        $copy['updated_at'] = date('Y-m-d H:i:s');
        $id = $this->db->insert('pages', $copy);

        return $this->back('The copy of the page is hidden – edit it and publish it.', 'edit', ['id' => $id]);
    }

    /**
     * @param array<string, mixed> $page
     * @param array<string, string> $errors
     */
    private function form(array $page, array $errors = []): Response
    {
        $language = (string) ($page['language'] ?? '');
        $custom = (string) ($page['slug'] ?? '');

        return $this->view('form', $page['page_id'] ? 'Edit page' : 'New page', [
            'page' => $page, 'errors' => $errors,
            // possible parent pages: the same language, not the page itself nor its subpages
            'parents' => array_values(array_filter($this->db->all('SELECT page_id, title, slug FROM {pages} WHERE language = ? AND deleted_at IS NULL AND page_id <> ? ORDER BY slug', [$language, (int) $page['page_id']]),
                fn (array $s): bool => $custom === '' || !str_starts_with($s['slug'] . '/', $custom . '/'))),
            'versions' => $page['page_id'] ? $this->db->all('SELECT r.revision_id, r.created_at, r.title, IF(u.name = \'\', u.username, u.name) AS user_id FROM {page_revisions} r LEFT JOIN {users} u ON u.user_id = r.user_id WHERE r.page_id = ? ORDER BY r.revision_id DESC LIMIT 30', [(int) $page['page_id']]) : [],
            'home' => $page['page_id'] > 0 && (int) $page['page_id'] === $this->app->settings()->int('home_page'),
            'inMenu' => $page['page_id'] > 0 ? \Kaleta\Core\Menu::hasPage($this->db, (int) $page['page_id'], (string) ($page['language'] ?? '')) : null,
            'customMenu' => \Kaleta\Core\Menu::load($this->db, 'hlavni', (string) ($page['language'] ?? '')) !== null,
            // content check of the saved version (2.14, Core\ContentCheck); a page not saved yet has nothing to check
            'contentCheck' => $page['page_id'] > 0 && !$this->request->isPost() ? \Kaleta\Core\ContentCheck::forPage($page) : [],
        ]);
    }
}
