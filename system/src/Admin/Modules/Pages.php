<?php

declare(strict_types=1);

namespace Talea\Admin\Modules;

use Talea\Admin\Module;
use Talea\Core\Response;
use Talea\Core\SearchInsights;
use Talea\Builder\Publisher;
use Talea\Builder\Build;

/**
 * Site pages: home, About us, Services, Contact, Privacy policy… The home page is set in Settings → General.
 * A page has the URL /<seo_link>. The content is either text from the editor, or a build from the builder (column build, the draft build_draft).
 */
final class Pages extends Module
{
    use \Talea\Admin\BuilderActions {
        actionBuilder as protected openBuilder;
    }

    public const string IDENT = 'pages';
    public const string TABLE = 'pages';
    public const string NAME = 'Pages';
    public const string GROUP = 'Website';
    public const string ICON = 'pages';

    /** Slugs that belong to the system and a page cannot have. */
    public const array RESERVED_SLUGS = ['news', 'search', 'mcp', 'api', 'admin', 'install', 'media', 'image', 'layout', 'system', 'storage', 'tools', 'docs', 'dist', 'rss', 'sitemap', 'robots', 'llms', 'feed', 'status', 'health', 'tasks', 'consent', 'form', 'popup', 'vitals', 'conversion', 'subscribe', 'download', 'screen', 'og', 'member'];

    /** Pages in the trash last this many days, then they are deleted permanently (like news). */
    public const int TRASH_DAYS = 30;

    protected function actionList(): Response
    {
        $status = $this->request->get('status');
        $status = in_array($status, ['published', 'hidden', 'changes', 'trash'], true) ? $status : '';
        $trash = $status === 'trash';
        $search = mb_substr(trim($this->request->get('search')), 0, 100);
        [$siteLanguages, $language, $column] = $this->readLanguageFilter();
        $where = [$trash ? 'deleted_at IS NOT NULL' : 'deleted_at IS NULL'];
        $params = [];
        if ($column !== null) {
            $where[] = 'language = ?';
            $params[] = $column;
        }
        // the status tabs count within the language filter, before the search (docs/specs/admin-lists.md)
        $counts = $this->db->one('SELECT COUNT(*) AS total, SUM(CASE WHEN visible = TRUE THEN 1 ELSE 0 END) AS published, SUM(CASE WHEN visible = FALSE THEN 1 ELSE 0 END) AS hidden,'
            . ' SUM(CASE WHEN build_draft IS NOT NULL THEN 1 ELSE 0 END) AS changes FROM {pages} WHERE deleted_at IS NULL' . ($column !== null ? ' AND language = ?' : ''), $column !== null ? [$column] : []) ?? [];
        $where[] = match ($status) {
            'published' => 'visible = TRUE',
            'hidden' => 'visible = FALSE',
            'changes' => 'build_draft IS NOT NULL',
            default => 'TRUE',
        };
        // the Page check columns and their filter (good from 80, mid from 50, low below; none = no focus keyword, hidden = noindex)
        $scored = $this->scored() && !$trash;
        $score = $scored ? $this->request->get('score') : '';
        $where[] = match ($score) {
            'good' => 'noindex = FALSE AND seo_score >= 80',
            'mid' => 'noindex = FALSE AND seo_score >= 50 AND seo_score < 80',
            'low' => 'noindex = FALSE AND seo_score < 50',
            'none' => "noindex = FALSE AND focus_keywords = ''",
            'hidden' => 'noindex = TRUE',
            default => 'TRUE',
        };
        $score = in_array($score, ['good', 'mid', 'low', 'none', 'hidden'], true) ? $score : '';
        if ($search !== '') {
            $where[] = '(' . $this->db->dialect()->likeInsensitive('title') . ' OR ' . $this->db->dialect()->likeInsensitive('slug') . ')';
            $pattern = '%' . addcslashes($search, '%_\\') . '%';
            array_push($params, $pattern, $pattern);
        }

        $pages = $this->db->all('SELECT * FROM {pages} WHERE ' . implode(' AND ', $where) . ' ORDER BY ' . ($trash ? 'deleted_at DESC' : 'language, sort_order, title'), $params);

        // column "V navigaci" (In navigation): with a custom menu by the menu items (the page or a link to its URL), otherwise the flag v_menu
        foreach ($pages as &$s) {
            $s['in_menu'] = \Talea\Core\Menu::hasPage($this->db, (int) $s['page_id'], (string) $s['language'], (string) $s['slug']) ?? (bool) $s['in_menu'];
        }
        unset($s);

        $pages = $status !== '' || $search !== '' || $score !== '' ? $pages : self::sortAsTree($pages);
        // "Edit titles in the list" works on one page of rows at a time, so its form stays under max_input_vars
        $seoEdit = !$trash && $this->request->get('seo_edit') === '1';
        $pageCount = $seoEdit ? max(1, (int) ceil(count($pages) / self::LIST_EDIT_ROWS)) : 1;
        $pageNumber = min($pageCount, max(1, $this->request->getInt('page', 1)));
        if ($seoEdit) {
            $pages = array_slice($pages, ($pageNumber - 1) * self::LIST_EDIT_ROWS, self::LIST_EDIT_ROWS);
        }

        return $this->view('list', 'Pages', [
            'pages' => $pages, 'pageCount' => $pageCount, 'pageNumber' => $pageNumber, 'inSearch' => $trash ? null : $this->searchColumn($pages),
            'trash' => $trash, 'status' => $status, 'search' => $search, 'siteLanguages' => $siteLanguages, 'language' => $language,
            'scored' => $scored, 'score' => $score, 'links' => $scored ? \Talea\Core\InternalLinks::pageCounts($this->app) : [],
            'orphans' => $scored ? array_flip(array_column(array_filter(\Talea\Core\InternalLinks::orphans($this->app), fn (array $o): bool => $o['kind'] === 'page'), 'id')) : [],
            'seoEdit' => $seoEdit,
            'comments' => $trash ? [] : \Talea\Core\DraftComments::unresolvedCounts($this->db), // unresolved comments from shared previews (2.15)
            'inTrash' => (int) $this->db->value('SELECT COUNT(*) FROM {pages} WHERE deleted_at IS NOT NULL'),
            'counts' => array_map('intval', $counts + ['total' => 0, 'published' => 0, 'hidden' => 0, 'changes' => 0]),
        ]);
    }

    /**
     * The list's "Clicks · place" column (both engines' snapshot), for whoever may open Search engines → Performance: per
     * page its numbers, false for a page the sitemap lists that has not appeared in search yet, null when nothing is known.
     * Null without the right or without a snapshot.
     *
     * @param list<array<string, mixed>> $pages
     * @return array<int, array{clicks: int, impressions: int, position: ?float}|false|null>|null
     */
    private function searchColumn(array $pages): ?array
    {
        if (!isset($this->kernel->modules()['search_performance']) || SearchInsights::snapshots($this->db, 'both') === []) {
            return null;
        }
        $root = SearchInsights::root($this->app);
        $seen = SearchInsights::byPath($this->db, 'both', $root);
        $notInSearch = array_flip(array_map(fn (string $a): string => SearchInsights::key($a, $root),
            SearchInsights::notInSearch($this->db, $root, array_map('strval', array_keys((new \Talea\Front\Seo($this->app))->sitemapPages()))) ?? []));
        $column = [];
        foreach (SearchInsights::pageKeys($this->app, $pages, $root) as $id => $key) {
            $column[$id] = $seen[$key] ?? (isset($notInSearch[$key]) ? false : null);
        }

        return $column;
    }

    /**
     * The SEO tab's "In search · 28 days": Google's numbers and queries for the page (SearchInsights::forPath), for whoever
     * may open Search engines → Performance; null without the right or without a Google snapshot.
     *
     * @param array<string, mixed> $page
     * @return array{totals: ?array{clicks: int, impressions: int, position: ?float}, queries: list<array{query: string, clicks: int, impressions: int, position: float}>}|null
     */
    private function searchFor(array $page): ?array
    {
        if (!isset($this->kernel->modules()['search_performance']) || SearchInsights::snapshots($this->db, \Talea\Connectors\Google::KEY) === []) {
            return null;
        }
        $root = SearchInsights::root($this->app);

        return SearchInsights::forPath($this->db, SearchInsights::pageKeys($this->app, [$page], $root)[(int) $page['page_id']], $root);
    }

    /**
     * "Add as keyword" in the SEO tab's search block: a search that led to the page joins its focus keywords (up to five),
     * saved as the SEO tab saves them, so the score follows.
     */
    protected function actionSeoKeywordAdd(): Response
    {
        $page = $this->request->isPost() ? $this->loadPage($this->idParam()) : null;
        if ($page === null) {
            return $this->back('Page does not exist.', 'website', [], 'error');
        }
        $back = ['id' => (string) $page['public_id'], 'tab' => 'seo'];
        if (($refusal = $this->requirePublishPermission($page, $back)) !== null) {
            return $refusal;
        }
        $keyword = $this->request->post('keyword');
        $keywords = \Talea\Core\SeoFields::keywords((string) $page['focus_keywords']);
        if (!\Talea\Core\SeoFields::fitsAsKeyword($keyword)) {
            return $this->back('This search cannot be a focus keyword: a keyword has no comma and up to 100 characters.', 'website', $back, 'error');
        }
        if (in_array(mb_strtolower($keyword), array_map(mb_strtolower(...), $keywords), true)) {
            return $this->back('This is already a focus keyword of the page.', 'website', $back);
        }
        $new = \Talea\Core\SeoFields::keywords(implode("\n", [...$keywords, $keyword]));
        // five keywords at most, and together they fit the column (SeoFields drops what does not): refused instead of dropped
        if (count($keywords) >= \Talea\Core\SeoFields::MAX_KEYWORDS) {
            return $this->back('A page has up to five focus keywords. Remove one to add another.', 'website', $back, 'error');
        }
        if (!in_array($keyword, $new, true)) {
            return $this->back('The focus keywords of this page are too long together. Remove one to add another.', 'website', $back, 'error');
        }
        ['errors' => $errors] = $this->persist($page, ['focus_keywords' => implode("\n", $new)] + $this->formValues($page), true);
        if ($errors !== []) {
            return $this->back((string) reset($errors), 'website', $back, 'error');
        }
        \Talea\Admin\ChangeLog::write($this->app, 'pages', 'focus keyword added', mb_substr($page['title'], 0, 80));

        return $this->back('Saved.', 'website', $back);
    }

    /** The fields "Edit titles in the list" sends per row. */
    private const array LIST_FIELDS = ['seo_title', 'description', 'focus_keywords', 'key_page', 'noindex'];

    /** Rows per page of "Edit titles in the list": 50 rows of up to 7 fields stay well under PHP's default max_input_vars (1000). */
    private const int LIST_EDIT_ROWS = 50;

    /**
     * "Edit titles in the list" (?seo_edit=1, LIST_EDIT_ROWS rows per list page): rows[<public id>][seo_title|description|
     * focus_keywords|key_page|noindex], each over the page's stored values and saved as settings_save saves a tab
     * (SeoFields::fromInput, SeoScore::refresh). A row that changes nothing is left alone; nothing is published that was not
     * before. A form cut short by max_input_vars saves nothing; a row that fails is named in an error. The list filters
     * come back as hidden fields, so the redirect returns to the same edit page.
     */
    protected function actionSeoBulkSave(): Response
    {
        if (!$this->request->isPost()) {
            return $this->back();
        }
        $return = array_filter(['status' => $this->request->post('status'), 'language' => $this->request->post('language'), 'score' => $this->request->post('score'),
            'search' => $this->request->post('search'), 'page' => $this->request->post('page')]) + ['seo_edit' => '1'];
        // PHP drops every field past max_input_vars without an error: a form that reaches the limit may have lost rows
        if ($this->request->postFieldCount() >= (int) ini_get('max_input_vars')) {
            return $this->back(t('The form sent more fields than the server accepts, so nothing was saved. Edit fewer pages at once.'), '', $return, 'error');
        }
        $saved = 0;
        foreach ($this->request->postArray('rows') as $publicId => $row) {
            $page = is_array($row) ? $this->loadPage($this->db->internalId('pages', (string) $publicId)) : null;
            if ($page === null) {
                continue;
            }
            $in = array_filter(array_intersect_key($row, array_flip(self::LIST_FIELDS)), is_scalar(...)); // a field sent as an array keeps its value
            $clean = fn (string $key, mixed $value): string => match ($key) {
                'key_page', 'noindex' => !empty($value) ? '1' : '0',
                'focus_keywords' => (string) \Talea\Core\SeoFields::fromInput([$key => $value])[$key],
                default => trim(\Talea\Core\Robots::scalar($value)),
            };
            if (array_filter($in, fn (mixed $value, string $key): bool => $clean($key, $value) !== $clean($key, $page[$key]), ARRAY_FILTER_USE_BOTH) === []) {
                continue;
            }
            // the publish permission without requirePublishPermission(), which would flash once per row
            $errors = $this->app->auth()->canPublish() || !$page['visible'] ? $this->persist($page, $in + $this->formValues($page))['errors']
                : ['visible' => 'Only an editor or administrator edits, publishes and deletes published pages. You can prepare a new hidden page.'];
            if ($errors !== []) {
                $this->app->session->flash('error', t('Not saved – %s: %s', (string) $page['title'] !== '' ? $page['title'] : '/' . $page['slug'], implode(' ', array_map(t(...), $errors))));
                continue;
            }
            $saved++;
        }
        if ($saved > 0) {
            \Talea\Admin\ChangeLog::write($this->app, 'pages', 'search titles edited in the list', (string) $saved);
        }

        return $this->back(t('Pages saved: %d.', $saved), '', $return);
    }

    /**
     * Pages sorted as a tree: a subpage right after its parent, with the depth (key "uroven") for indentation in the list.
     *
     * @param list<array<string, mixed>> $pages
     * @return list<array<string, mixed>>
     */
    /**
     * Website mode: the sidebar holds the site's pages grouped by the menu (in it or not), the content is a framed preview of
     * the chosen page (its draft where there is one) with the settings sidebar beside it, one tab at a time (?tab=).
     */
    protected function actionWebsite(): Response
    {
        $home = $this->app->settings()->int('home_page');
        $pages = $this->db->all("SELECT page_id, public_id, title, slug, language, visible, in_menu, parent_id, seo_score, COALESCE(focus_keywords, '') <> '' AS has_keyword, build_draft IS NOT NULL AS has_draft FROM {pages} WHERE deleted_at IS NULL AND language = '' ORDER BY sort_order, title");
        $groups = ['menu' => [], 'other' => []];
        foreach (self::sortAsTree($pages) as $page) {
            $inMenu = \Talea\Core\Menu::hasPage($this->db, (int) $page['page_id'], '', (string) $page['slug']) ?? (bool) $page['in_menu'];
            $groups[$inMenu ? 'menu' : 'other'][] = $page + ['home' => (int) $page['page_id'] === $home];
        }
        $chosen = $this->idParam('id', null, true);
        // the full row: a language version opens here too (from the Page tab), it is only not in the tree
        $current = ($chosen > 0 ? $this->loadPage($chosen) : null) ?? $this->loadPage($home) ?? ($pages !== [] ? $this->loadPage((int) $pages[0]['page_id']) : null);
        $asked = $this->request->get('tab');
        $tab = isset(self::SETTINGS_TABS[$asked]) ? $asked : 'seo';
        $scored = $this->scored();
        $this->kernel->sidebar = $this->app->view->render('admin/pages/website_sidebar', [
            'app' => $this->app, 'module' => $this, 'groups' => $groups, 'current' => $current === null ? 0 : (int) $current['page_id'], 'modules' => $this->kernel->modules(),
            'inTrash' => (int) $this->db->value('SELECT COUNT(*) FROM {pages} WHERE deleted_at IS NOT NULL'), 'seo' => $asked === 'seo', 'tab' => isset(self::SETTINGS_TABS[$asked]) ? $asked : '', 'currentId' => (string) ($current['public_id'] ?? ''), 'scored' => $scored,
        ]);
        if ($current === null) {
            return $this->view('website', '', ['page' => null]);
        }
        $id = (int) $current['page_id'];
        $url = self::address($this->app, $current);
        $build = Build::fromJson($current['build_draft'] ?? $current['build']);
        $site = parse_url($this->app->settings()->get('site_url') ?: $this->app->request->origin()) ?: [];
        $language = (string) $current['language'];
        // "SEO on the page": the preview of a build page with the SEO check's marks on its elements (?marks=1)
        $marks = $build === null ? null : \Talea\Core\ContentCheck::marks($build, \Talea\Core\SeoFields::keywords((string) $current['focus_keywords']));
        $marksOn = $marks !== null && $this->request->get('marks') === '1';
        // the draft where there is one; a hidden page is seen only as a preview, and the marks only on one
        $preview = $current['build_draft'] !== null || !$current['visible'] || $marksOn ? $url . '?build=draft' : $url;

        return $this->view('website', '', [
            'page' => $current, 'url' => $url, 'tab' => $tab, 'home' => $id === $home, 'scored' => $scored,
            'preview' => $marksOn ? $preview . '&seo_marks=1' : $preview,
            'marks' => $marks, 'marksOn' => $marksOn,
            'host' => ($site['host'] ?? '') . (isset($site['port']) ? ':' . $site['port'] : ''),
            'comments' => count(\Talea\Core\DraftComments::list($this->db, 'page:' . $id)),
            'canPublish' => $this->app->auth()->canPublish(),
            'hasBuild' => $build !== null,
            'parents' => $tab !== 'page' ? [] : array_values(array_filter($this->db->all('SELECT page_id, public_id, title, slug FROM {pages} WHERE language = ? AND deleted_at IS NULL AND page_id <> ? ORDER BY slug', [$language, $id]),
                fn (array $s): bool => !str_starts_with($s['slug'] . '/', $current['slug'] . '/'))),
            'inMenu' => \Talea\Core\Menu::hasPage($this->db, $id, $language) ?? (bool) $current['in_menu'],
            // the language versions the page form offered: each additional language with this page's translation or a way to create it
            'translations' => $tab !== 'page' || $language !== '' ? [] : array_map(fn (string $code): array => ['code' => $code, 'name' => \Talea\Core\Language::AVAILABLE[$code][0] ?? $code,
                'page' => $this->db->one('SELECT public_id, visible FROM {pages} WHERE translation_of = ? AND language = ? AND deleted_at IS NULL', [$id, $code])], \Talea\Core\Language::additional($this->app->settings())),
            'versions' => $tab !== 'publishing' || $build !== null ? [] : $this->db->all('SELECT r.revision_id, r.created_at, r.title, CASE WHEN u.name = \'\' THEN u.username ELSE u.name END AS user_id FROM {page_revisions} r LEFT JOIN {users} u ON u.user_id = r.user_id WHERE r.page_id = ? ORDER BY r.revision_id DESC LIMIT 30', [$id]),
            // the draft the Advanced tab's "Add a type" builds on (BuilderActions' version check)
            'version' => $tab !== 'advanced' ? '' : self::computeBuildVersion($current['build_draft'] ?? $current['build']),
            'structured' => $tab !== 'advanced' || $build === null ? [] : array_values(array_filter(array_map(fn (array $element): string => (string) ($element['content']['data']['type'] ?? ''), Build::elementsOfType($build, fn (string $className): bool => $className === \Talea\Builder\Elements\StructuredData::class)))),
            'seo' => $tab === 'seo' ? $this->seoTab($current) : [],
            'inSearch' => $tab === 'seo' ? $this->searchFor($current) : null,
        ]);
    }

    /**
     * The live check of the SEO tab (admin.js, data-seo-live): the posted title, description and keywords over the page's
     * current content give the checks and the search result preview again, as an HTML fragment. Nothing is saved.
     */
    protected function actionSeoCheck(): Response
    {
        $page = $this->request->isPost() ? $this->loadPage($this->idParam()) : null;
        if ($page === null) {
            return Response::html('', 404);
        }
        $data = $this->seoTab(['seo_title' => $this->request->post('seo_title'), 'description' => $this->request->post('description'),
            'focus_keywords' => implode("\n", \Talea\Core\SeoFields::keywords($this->request->post('focus_keywords')))] + $page);

        return Response::html(($this->scored() ? $this->app->view->render('admin/seo/checks', ['app' => $this->app] + $data) : '')
            . $this->app->view->render('admin/seo/serp', ['app' => $this->app] + $data));
    }

    /**
     * What the SEO tab shows of a page row (the saved one, or the live check's fields over it): the scored analysis, open
     * placeholders, the marks a build would get on the preview and the search result as the site composes it.
     *
     * @param array<string, mixed> $page
     * @return array<string, mixed>
     */
    private function seoTab(array $page): array
    {
        $build = Build::fromJson($page['build_draft'] ?? $page['build']);
        $home = $this->app->settings()->int('home_page') === (int) $page['page_id'];
        $settings = $this->app->settings();
        $description = (string) $page['description'] !== '' ? (string) $page['description'] : ($home ? $settings->get('site_description') : '');
        $site = parse_url($settings->get('site_url') ?: $this->app->request->origin()) ?: [];

        // the analysis only with the Page check feature on; the search result preview always
        return ($this->scored() ? \Talea\Core\SeoScore::panel($this->app, 'page', $page) : ['analysis' => null, 'samples' => [], 'keywords' => []]) + [
            'marksUrl' => $build === null ? '' : $this->url('website', ['id' => (string) $page['public_id'], 'tab' => 'seo', 'marks' => '1']),
            // the title and description exactly as Front\Kernel hands them to the page head
            'serpTitle' => \Talea\Core\SeoTitle::compose($settings, $home ? 'home' : 'page', (string) $page['seo_title'] !== '' ? (string) $page['seo_title'] : ($home ? '' : (string) $page['title']),
                (string) $page['seo_title'] !== '', ['excerpt' => mb_substr($description, 0, 160)]),
            'serpDescription' => $description,
            'dir' => \Talea\Core\Language::dirAttribute(\Talea\Core\Language::ofContent($settings, (string) $page['language'])),
            'serpAddress' => ($site['host'] ?? '') . preg_replace('#^' . preg_quote($this->app->request->basePath(), '#') . '#', '', self::address($this->app, $page)),
        ];
    }

    /** The Page check feature is on: the score parts (tree dots, the sidebar pill) and the SEO tab's checks are computed and shown. */
    private function scored(): bool
    {
        return \Talea\Core\ContentCheck::enabled($this->app->settings());
    }

    /** The tabs of Website mode's settings sidebar and the fields each one saves (pages/settings_save). */
    private const array SETTINGS_TABS = [
        'seo' => ['seo_title', 'description', 'focus_keywords', 'key_page'],
        'page' => ['title', 'slug', 'parent_id', 'in_menu', 'sort_order'],
        'publishing' => ['visible', 'publish_at', 'valid_until', 'review_by', 'page_password', 'remove_password', 'member_groups_present', 'member_groups'],
        'sharing' => ['share_title', 'share_description', 'image'],
        'advanced' => ['noindex', 'robots', 'canonical_url', 'breadcrumb_title', 'head_code'],
    ];

    /**
     * Saves one tab of the settings sidebar: its fields over the page's stored values, so a field the tab does not show is
     * never cleared. Visibility, dates and the password, like a changed address, need the permission to publish.
     */
    protected function actionSettingsSave(): Response
    {
        $page = $this->request->isPost() ? $this->loadPage($this->idParam()) : null;
        $tab = $this->request->post('tab');
        if ($page === null || !isset(self::SETTINGS_TABS[$tab])) {
            return $this->back('Page does not exist.', 'website', [], 'error');
        }
        $in = array_intersect_key($_POST, array_flip(self::SETTINGS_TABS[$tab])) + $this->formValues($page);
        // only the Page tab places the page; a new address there (the slug or the parent persist() will store differs from
        // the stored one) needs the permission to publish
        $keepPlace = $tab !== 'page';
        $place = $keepPlace ? null : $this->placement((int) $page['page_id'], $in);
        $moved = $place !== null && ($place['slug'] !== (string) $page['slug'] || (int) ($place['parent']['page_id'] ?? 0) !== (int) $page['parent_id']);
        $back = ['id' => (string) $page['public_id'], 'tab' => $tab];
        if (($refusal = $this->requirePublishPermission($page, $back)) !== null || (($tab === 'publishing' || $moved) && ($refusal = $this->requirePublishPermission(null, $back)) !== null)) {
            return $refusal;
        }
        if ($tab === 'advanced') {
            // the page's own address is no override: stored empty, so the page keeps following its address
            $site = parse_url($this->app->settings()->get('site_url') ?: $this->app->request->origin()) ?: [];
            $own = ($site['scheme'] ?? 'http') . '://' . ($site['host'] ?? '') . (isset($site['port']) ? ':' . $site['port'] : '') . self::address($this->app, $page);
            if (\Talea\Core\Robots::sameAddress(\Talea\Core\Robots::scalar($in['canonical_url']), $own)) {
                $in['canonical_url'] = '';
            }
        }
        ['errors' => $errors, 'data' => $data] = $this->persist($page, $in, $keepPlace);
        if ($errors !== []) {
            return $this->back((string) reset($errors), 'website', $back, 'error');
        }
        \Talea\Core\Menu::setPage($this->db, (int) $page['page_id'], $data['language'], (bool) $data['in_menu']);
        \Talea\Admin\ChangeLog::write($this->app, 'pages', 'settings edited (' . $tab . ')', mb_substr($page['title'], 0, 80));

        return $this->back('Saved.', 'website', $back);
    }

    /** The page's stored values as its form posts them: what settings_save lays one tab's fields over. @return array<string, mixed> */
    private function formValues(array $page): array
    {
        return array_intersect_key($page, array_flip(['title', 'slug', 'description', 'seo_title', 'image', 'noindex', 'visible', 'sort_order', 'language', 'valid_until', 'review_by', 'head_code', ...\Talea\Core\SeoFields::COLUMNS])) + [
            'parent_id' => $this->publicId((int) $page['parent_id']),
            'translation_of' => $this->publicId((int) $page['translation_of']),
            'in_menu' => \Talea\Core\Menu::hasPage($this->db, (int) $page['page_id'], (string) $page['language']) ?? (bool) $page['in_menu'],
        ];
    }

    /**
     * The Advanced tab's "Add a type": a structured-data element of a schema.org type at the end of the draft, then the builder
     * with it selected to fill in. A text page has no build to add it to. The draft is written as the builder writes it: the
     * form carries the version it was rendered from, so a draft changed since (an open editor's autosave, Claude) is not
     * overwritten, and that editor's next autosave meets the new version as a conflict.
     */
    protected function actionSeoAddType(): Response
    {
        $page = $this->request->isPost() ? $this->loadPage($this->idParam()) : null;
        if ($page === null) {
            return $this->back('Page does not exist.', 'website', [], 'error');
        }
        $back = ['id' => (string) $page['public_id'], 'tab' => 'advanced'];
        $target = ['row' => $page, 'build' => $page['build'], 'draft' => $page['build_draft']];
        $previous = Build::fromJson($page['build_draft'] ?? $page['build']);
        $type = $this->request->post('type');
        if ($previous === null) {
            return $this->back('Open the page in the builder to add structured data.', 'website', $back, 'error');
        }
        if (!in_array($type, \Talea\Builder\StructuredData::types(), true)) {
            return $this->back('Choose a type of structured data.', 'website', $back, 'error');
        }
        if ($this->checkVersionConflict($target) !== null) {
            return $this->back('Someone else has edited this page in the meantime (or you opened it in another window).', 'website', $back, 'error');
        }
        $element = Build::fresh('structured_data', ['data' => ['type' => $type, 'fields' => []]]);
        [$build] = Build::sanitize(['children' => [...$previous['children'], $element]] + $previous, $this->app->auth()->isAdmin(), $previous);
        $this->saveDraft($target, Build::toJson($build));
        $this->db->update('pages', ['updated_at' => date('Y-m-d H:i:s')], ['page_id' => $page['page_id']]);
        \Talea\Admin\ChangeLog::write($this->app, 'pages', 'structured data added', mb_substr($page['title'], 0, 80) . ' (' . $type . ')');

        return \Talea\Core\Response::redirect($this->url('builder', ['id' => (string) $page['public_id'], 'select' => $element['id']]));
    }

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
        $language = \Talea\Core\Language::column($this->app->settings(), $this->request->get('language'));
        $original = $language !== '' ? $this->db->one("SELECT page_id, title, description, text, build, build_draft FROM {pages} WHERE page_id = ? AND language = '' AND deleted_at IS NULL", [$this->idParam('translation_of')]) : null;
        // a translation of a builder page starts as a copy of the original's build (the draft, otherwise the published one - as MCP
        // create_page with copy_build), a text page as a copy of its text
        $copyBuild = $original !== null && ($original['build_draft'] ?? $original['build']) !== null;

        return $this->form(['page_id' => 0, 'slug' => '', 'title' => $original['title'] ?? '', 'description' => $original['description'] ?? '', 'seo_title' => '', 'image' => '', 'noindex' => 0, 'text' => $original !== null && !$copyBuild ? (string) $original['text'] : '', 'visible' => 1, 'in_menu' => 1, 'sort_order' => 100, 'build' => null, 'build_draft' => null,
            'parent_id' => $this->idParam('parent') ?: null, 'publish_at' => null, 'valid_until' => null, 'review_by' => null, 'language' => $language, 'translation_of' => $original['page_id'] ?? null,
            'copy_build' => $copyBuild], translationOf: $original === null ? null : (string) $original['title']);
    }

    /** Translation overview (2.14, Core\Translations): what is missing or older than the original in each language version. */
    protected function actionTranslations(): Response
    {
        $auth = $this->app->auth();

        return $this->view('translations', 'Translations', \Talea\Core\Translations::matrix($this->app) + [
            'assistant' => (new \Talea\Core\Assistant($this->app->settings()))->isReady(),
            'news' => $auth->hasModule('news'), 'collections' => $auth->hasModule('collections'),
        ]);
    }

    /** Actions the list does with ticked pages (2.14): show, hide, language version, trash. Each page is checked as if edited alone. */
    protected function actionBulk(): Response
    {
        if (!$this->request->isPost()) {
            return $this->back();
        }
        $action = $this->request->post('bulk');
        if (!in_array($action, ['visible', 'hide', 'language', 'trash'], true)) {
            return $this->back('Unknown action.', '', [], 'error');
        }
        $auth = $this->app->auth();
        $home = $this->app->settings()->int('home_page');
        $language = \Talea\Core\Language::column($this->app->settings(), $this->request->post('language'));
        $done = 0;
        $skipped = 0;
        $clashes = 0; // with slugs per language a page cannot move where another page of that version has its slug
        foreach (array_unique(array_map(fn (string $uuid): int => $this->db->internalId('pages', $uuid), $this->request->postList('selected'))) as $id) {
            $page = $this->loadPage($id);
            // authors may only touch hidden pages and never publish; the home page stays visible and out of the trash
            if ($page === null || (!$auth->canPublish() && ($page['visible'] || $action === 'visible')) || ($id === $home && in_array($action, ['hide', 'trash'], true))) {
                $skipped++;
                continue;
            }
            if ($action === 'language' && $language !== $page['language'] && \Talea\Core\Slug::taken($this->db, 'pages', (string) $page['slug'], $language, $id)) {
                $clashes++;
                continue;
            }
            match ($action) {
                'visible' => $this->db->update('pages', ['visible' => 1, 'publish_at' => null, 'updated_at' => date('Y-m-d H:i:s')], ['page_id' => $id]),
                'hide' => $this->db->update('pages', ['visible' => 0, 'updated_at' => date('Y-m-d H:i:s')], ['page_id' => $id]),
                'language' => $this->db->update('pages', ['language' => $language, 'translation_of' => $language === '' ? null : $page['translation_of'], 'updated_at' => date('Y-m-d H:i:s')], ['page_id' => $id]),
                default => $this->db->run('UPDATE {pages} SET deleted_at = NOW(), visible = FALSE WHERE page_id = ?', [$id]),
            };
            \Talea\Admin\ChangeLog::write($this->app, 'pages', 'bulk ' . ['visible' => 'shown', 'hide' => 'hidden', 'language' => 'language ' . ($language ?: 'default'), 'trash' => 'moved to trash'][$action], mb_substr($page['title'], 0, 80));
            $done++;
        }
        if ($done > 0) {
            \Talea\Front\Cache::clear();
        }
        $message = match ($action) {
            'visible' => t('Pages published: %d.', $done), 'hide' => t('Pages hidden: %d.', $done),
            'language' => t('Pages moved to the language version: %d.', $done), default => t('Pages moved to the trash: %d.', $done),
        };

        return $this->back($message . ($skipped > 0 ? ' ' . t('Skipped: %d (no permission, or the home page).', $skipped) : '')
            . ($clashes > 0 ? ' ' . t('Skipped: %d (that language version already has the address).', $clashes) : ''), '', [], $done > 0 ? 'ok' : 'error');
    }

    /** A builder page's settings live in Website mode's sidebar; a text page keeps its form (the text is edited there). */
    protected function actionEdit(): Response
    {
        $page = $this->loadPage($this->idParam());
        if ($page === null) {
            return $this->error('Page does not exist.', 404);
        }

        return $page['build'] !== null || $page['build_draft'] !== null ? \Talea\Core\Response::redirect($this->url('website', ['id' => (string) $page['public_id'], 'tab' => 'page'])) : $this->form($page);
    }

    /** Saving from editing "directly on the site" (views/front/edit.php): only the page's name and text. */
    protected function actionSaveText(): Response
    {
        $r = $this->request;
        $page = $r->isPost() ? $this->db->one('SELECT * FROM {pages} WHERE page_id = ? AND deleted_at IS NULL', [$this->idParam()]) : null;
        if ($page === null || (!$this->app->auth()->canPublish() && $page['visible'])) {
            return $this->redirectToSite($r->post('back'));
        }
        $title = mb_substr($r->post('title'), 0, 200);
        if ($title === '') {
            return $this->redirectToSite($r->post('back'), '?edit=text&error=1');
        }
        $text = \Talea\Core\Html::forUser($r->post('text'), $this->app->auth());
        if ($page['title'] !== $title || (string) $page['text'] !== $text) {
            $this->saveVersion((int) $page['page_id'], $page['title'], (string) $page['text']); // an edit directly on the site goes to the history as in the admin
        }
        $this->db->update('pages', ['title' => $title, 'text' => $text, 'updated_at' => date('Y-m-d H:i:s')], ['page_id' => $page['page_id']]);
        \Talea\Core\SeoScore::refresh($this->app, 'page', (int) $page['page_id']);
        \Talea\Admin\ChangeLog::write($this->app, 'pages', 'edited directly on the site', mb_substr($title, 0, 80));

        return $this->redirectToSite($r->post('back'));
    }

    /**
     * Roles at the author level (custom roles without the permission to publish too) can prepare pages, but not publish them,
     * change published ones or delete them. Returns a response with the refusal (back to the list, or with $website to that
     * page in Website mode), or null when allowed.
     *
     * @param array<string, string>|null $website the Website mode parameters (id, tab) to go back to
     */
    private function requirePublishPermission(?array $page = null, ?array $website = null): ?Response
    {
        if ($this->app->auth()->canPublish() || ($page !== null && !$page['visible'])) {
            return null;
        }

        return $this->back('Only an editor or administrator edits, publishes and deletes published pages. You can prepare a new hidden page.', $website !== null ? 'website' : '', $website ?? [], 'error');
    }

    protected function actionSave(): Response
    {
        if (!$this->request->isPost()) {
            return $this->back();
        }
        $r = $this->request;
        if (($refusal = $this->refuseUnknownId('page_id', 'Page does not exist.')) !== null) {
            return $refusal;
        }
        $id = $this->idParam('page_id');
        if ($id > 0 && ($refusal = $this->requirePublishPermission($this->db->one('SELECT visible FROM {pages} WHERE page_id = ?', [$id]) ?? ['visible' => 1])) !== null) {
            return $refusal;
        }
        $new = $id === 0;
        ['id' => $id, 'errors' => $errors, 'data' => $data] = $this->persist($new ? null : ['page_id' => $id], $_POST);
        if ($errors !== []) {
            $previous = $id > 0 ? $this->db->one('SELECT build, build_draft, password_hash FROM {pages} WHERE page_id = ?', [$id]) : null;

            return $this->form(['page_id' => $id] + $data + ['copy_build' => $r->postBool('copy_build')] + ($previous ?? ['build' => null, 'build_draft' => null]), $errors);
        }
        if ($new) { // a translation of a builder page or a template opens in the builder
            $original = $data['translation_of'] !== null && $r->postBool('copy_build') ? $this->db->one('SELECT build, build_draft FROM {pages} WHERE page_id = ?', [$data['translation_of']]) : null;
            if ($original !== null && ($original['build_draft'] ?? $original['build']) !== null) {
                // the translation of a builder page: the draft is a copy of the original's build and opens in the builder
                $this->db->update('pages', ['build_draft' => $original['build_draft'] ?? $original['build']], ['page_id' => $id]);
                \Talea\Core\SeoScore::refresh($this->app, 'page', $id);
                \Talea\Core\Menu::setPage($this->db, $id, $data['language'], (bool) $data['in_menu']);
                $this->app->session->flash('ok', t('The translation starts as a copy of the original – replace the texts, then publish.'));

                return \Talea\Core\Response::redirect($this->url('builder', ['id' => $this->publicId($id)]));
            }
            $template = \Talea\Builder\Library::PAGE_TEMPLATES[$r->post('template')] ?? null;
            if ($template !== null && $template[1] !== []) {
                // new page from a template: sections from the library as a draft and straight into the builder
                $build = \Talea\Builder\Library::page($this->db, $template[1], $data['title'], $this->contentLanguage($data['language']));
                $this->db->update('pages', ['build_draft' => Build::toJson($build)], ['page_id' => $id]);
                \Talea\Core\SeoScore::refresh($this->app, 'page', $id);
                \Talea\Core\Menu::setPage($this->db, $id, $data['language'], (bool) $data['in_menu']);

                return \Talea\Core\Response::redirect($this->url('builder', ['id' => $this->publicId($id)]));
            }
            if ($template !== null && $data['text'] === '') { // the privacy policy outline stays out of a built menu, as it always did
                // in the page's language, from what the site has switched on (1.9)
                $text = \Talea\Core\Language::runWith($this->contentLanguage($data['language']), fn (): string => \Talea\Builder\Library::privacyPolicyText($this->app->settings()));
                $this->db->update('pages', ['text' => $text], ['page_id' => $id]);
                \Talea\Core\SeoScore::refresh($this->app, 'page', $id);

                return $this->back('The page has been created with a privacy policy outline – fill in the details in square brackets.', 'edit', ['id' => $this->publicId($id)]);
            }
        }
        // assembled menu (Appearance → Menu): the checkbox "in navigation" adds the page to the menu or removes it
        \Talea\Core\Menu::setPage($this->db, $id, $data['language'], (bool) $data['in_menu']);
        if ($r->post('after_save') === 'builder') {
            return \Talea\Core\Response::redirect($this->url('builder', ['id' => $this->publicId($id)]));
        }

        return $this->back('Page saved.');
    }

    /**
     * Validates and stores a page from form fields: actionSave posts the whole form, settings_save the page's stored values
     * with the fields of one tab over them. Checks permissions only as far as the fields go (visibility, head code); the
     * caller decides who may edit the page at all. Leaves the text alone when $in has none for an existing page.
     *
     * @param array<string, mixed>|null $page the page (its page_id; with $keepPlace the full row), null = a new one
     * @param array<string, mixed> $in fields as a form posts them (ids are public ids)
     * @param bool $keepPlace the stored language, parent and slug stay, whatever $in says (a settings tab other than Page)
     * @return array{id: int, errors: array<string, string>, data: array<string, mixed>} the page's id (0 when a new page failed), the errors by field, the cleaned values
     */
    private function persist(?array $page, array $in, bool $keepPlace = false): array
    {
        $post = fn (string $key): string => is_string($in[$key] ?? null) ? trim($in[$key]) : '';
        $bool = fn (string $key): bool => !empty($in[$key]);
        $id = (int) ($page['page_id'] ?? 0);
        ['language' => $language, 'parent' => $parent, 'slug' => $slug] = $keepPlace && $page !== null
            ? ['language' => (string) $page['language'], 'parent' => (int) $page['parent_id'] > 0 ? ['page_id' => (int) $page['parent_id']] : null, 'slug' => (string) $page['slug']]
            : $this->placement($id, $in);
        $sortOrder = is_int($in['sort_order'] ?? null) || preg_match('/^-?\d{1,18}$/', $post('sort_order')) ? (int) $in['sort_order'] : 100;
        $data = [
            'title' => mb_substr($post('title'), 0, 200),
            'slug' => $slug,
            'parent_id' => $parent !== null ? (int) $parent['page_id'] : null,
            'description' => mb_substr($post('description'), 0, 300),
            'seo_title' => mb_substr($post('seo_title'), 0, 200),
            'image' => mb_substr($post('image'), 0, 255),
            'noindex' => (int) $bool('noindex'),
            'visible' => (int) $bool('visible'),
            'in_menu' => (int) $bool('in_menu'),
            'sort_order' => max(0, min(65535, $sortOrder)),
            'updated_at' => date('Y-m-d H:i:s'),
            'language' => $language,
            // true until and review by (2.10, Core\Validity): empty or not a date = none
            'valid_until' => \Talea\Core\Validity::date($post('valid_until')),
            'review_by' => \Talea\Core\Validity::date($post('review_by')),
        ] + \Talea\Core\SeoFields::fromInput($in);
        if ($id === 0 || array_key_exists('text', $in)) {
            // settings_save sends no text: cleaning it again for someone who may not write code would change it
            $data['text'] = \Talea\Core\Html::forUser($post('text'), $this->app->auth());
        }
        if ($this->app->auth()->isAdmin() && !\Talea\Core\Demo::active()) {
            // code in <head> of this page only (2.3); never in the public demo – administrators, like the code for the whole site
            $data['head_code'] = trim((string) ($in['head_code'] ?? '')) !== '' ? mb_substr((string) $in['head_code'], 0, 20000) : null;
        }
        // scheduled publishing: only for a hidden page with a future time; a past time publishes the page right away.
        // Input without the field (a settings tab other than Publishing) keeps the stored schedule – for whoever may publish.
        $schedule = array_key_exists('publish_at', $in);
        if ($schedule) {
            $from = strtotime(str_replace('T', ' ', $post('publish_at'))) ?: null;
            $data['publish_at'] = !$data['visible'] && $from !== null && $from > time() ? date('Y-m-d H:i:s', $from) : null;
            if (!$data['visible'] && $from !== null && $from <= time()) {
                $data['visible'] = 1;
            }
        }
        if (!$this->app->auth()->canPublish()) {
            // without the permission to publish the page stays hidden, an editor publishes it; any save clears a schedule, so
            // changes nobody reviewed never go live on their own
            $data['visible'] = 0;
            $data['publish_at'] = null;
        }
        $data['translation_of'] = $data['language'] === '' ? null : ($this->db->value("SELECT page_id FROM {pages} WHERE page_id = ? AND language = '' AND page_id <> ?", [$this->db->internalId('pages', $post('translation_of')), $id]) ?: null);
        $errors = [];
        if ($data['title'] === '') {
            $errors['title'] = 'Enter the page title.';
        }
        $scope = \Talea\Core\Slug::scope($this->db, $data['language']);
        // a kept address is not checked again: the save does not change it
        if (!$keepPlace && $parent === null && (in_array($data['slug'], self::RESERVED_SLUGS, true) || isset(\Talea\Core\Language::AVAILABLE[$data['slug']]) || \Talea\Core\Routes::isNewsSlug($data['slug'], $this->db))) {
            $errors['slug'] = 'This URL is used by the system, choose another one.';
        } elseif (!$keepPlace && ($other = $this->db->one('SELECT page_id, deleted_at FROM {pages} WHERE slug = ? AND page_id <> ?' . $scope[0], [$data['slug'], $id, ...$scope[1]])) !== null) {
            $errors['slug'] = $other['deleted_at'] !== null ? 'A page in the trash uses this address – restore it or delete it permanently.' : 'A page with this URL already exists.';
        }
        // the page password (2.14, Core\PageLock): empty = unchanged, a tick removes it; only its hash is stored
        [$passwordHash, $passwordError] = \Talea\Core\PageLock::fromForm($post('page_password'), $bool('remove_password'));
        if ($passwordError !== '') {
            $errors['page_password'] = $passwordError;
        } elseif ($passwordHash !== null) {
            $data['password_hash'] = $passwordHash === '' ? null : $passwordHash;
        }
        if ($id > 0 && $id === $this->app->settings()->int('home_page') && !$data['visible']) {
            $errors['visible'] = 'The home page cannot be hidden. First choose another home page in Settings → General.';
        }
        if ($errors !== []) {
            return ['id' => $id, 'errors' => $errors, 'data' => $data];
        }
        if ($id > 0) {
            $previous = $this->db->one('SELECT slug, visible, title, text, language FROM {pages} WHERE page_id = ?', [$id]);
            if ($previous !== null && ($previous['text'] !== ($data['text'] ?? $previous['text']) || $previous['title'] !== $data['title'])) {
                $this->saveVersion($id, $previous['title'], (string) $previous['text']);
            }
            $this->db->update('pages', $data, ['page_id' => $id]);
            if ($previous !== null && $previous['slug'] !== $data['slug']) {
                self::move($this->db, $previous['slug'], $data['slug'], (bool) $previous['visible'], (string) $previous['language']);
            }
        } else {
            $id = $this->db->insert('pages', $data);
            \Talea\Core\Slug::fromId($this->db, 'pages', $id); // a title with nothing to transliterate: page-<id>, not n-a
        }
        if (($in['member_groups_present'] ?? '') === '1') {
            // as Members::saveFromForm, from $in: a settings tab cannot carry groups it does not own
            \Talea\Core\Members::setContentGroups($this->db, 'page', $id, is_array($in['member_groups'] ?? null) ? array_values(array_filter($in['member_groups'], is_string(...))) : []);
        }
        \Talea\Core\SeoScore::refresh($this->app, 'page', $id);

        return ['id' => $id, 'errors' => [], 'data' => $data];
    }

    /**
     * Where a page goes from form fields: its language, the parent (the same language, not the page itself nor its subpage,
     * otherwise a cycle would form) and the whole slug with the parent's prefix, cut to the length limit; an empty slug is
     * made from the title, and a taken one gets a number (o-nas-2), as with news. persist() stores it, settings_save compares
     * it with the stored address.
     *
     * @param array<string, mixed> $in fields as a form posts them
     * @return array{language: string, parent: ?array<string, mixed>, slug: string}
     */
    private function placement(int $id, array $in): array
    {
        $post = fn (string $key): string => is_string($in[$key] ?? null) ? trim($in[$key]) : '';
        $language = \Talea\Core\Language::column($this->app->settings(), $post('language'));
        $custom = $id > 0 ? (string) $this->db->value('SELECT slug FROM {pages} WHERE page_id = ?', [$id]) : '';
        $parent = ($parentId = $this->db->internalId('pages', $post('parent_id'))) > 0 ? $this->db->one('SELECT page_id, slug FROM {pages} WHERE page_id = ? AND page_id <> ? AND language = ? AND deleted_at IS NULL', [$parentId, $id, $language]) : null;
        if ($parent !== null && $custom !== '' && str_starts_with($parent['slug'] . '/', $custom . '/')) {
            $parent = null;
        }
        $prefix = $parent !== null ? $parent['slug'] . '/' : '';
        $slug = $prefix . slugify($post('slug') !== '' ? basename(str_replace('\\', '/', $post('slug'))) : $post('title'), max(20, 118 - strlen($prefix)));

        return ['language' => $language, 'parent' => $parent, 'slug' => $post('slug') === '' ? $this->availableSlug($slug, $id, $language) : $slug];
    }

    /* ---------- builder (actions in Admin\BuilderActions) ---------- */

    /** Editor; a text page is converted to a build on first opening (a narrow section with a heading and the text, the text stays). */
    protected function actionBuilder(): Response
    {
        $page = $this->loadPage($this->idParam('id', null, true));
        if ($page !== null && $page['build'] === null && $page['build_draft'] === null) {
            $this->db->update('pages', ['build_draft' => Build::toJson(Build::fromText($page['title'], (string) $page['text']))], ['page_id' => $page['page_id']]);
        }

        return $this->openBuilder();
    }

    protected function loadBuildTarget(): ?array
    {
        $page = $this->loadPage($this->idParam('id', null, true));

        return $page === null ? null : [
            'row' => $page, 'build' => $page['build'], 'draft' => $page['build_draft'], 'language' => $this->contentLanguage($page['language']),
            'title' => $page['title'], 'revisions' => ['page_id' => (int) $page['page_id']], 'params' => ['id' => (string) $page['public_id']],
        ];
    }

    protected function saveDraft(array $target, ?string $draft): void
    {
        $this->db->update('pages', ['build_draft' => $draft], ['page_id' => $target['row']['page_id']]);
    }

    protected function publishTarget(array $target): void
    {
        Publisher::page($this->app, $target['row']);
    }

    protected function describeTarget(array $target): array
    {
        $page = $target['row'];
        $url = self::address($this->app, $page);

        return [
            'id' => (string) $page['public_id'], 'url' => $url, 'preview' => $url . '?build=draft&editor=1', 'visible' => (bool) $page['visible'], 'parts' => false, 'headings' => true, 'description' => (string) $page['description'], 'image' => (string) $page['image'],
            'back' => ['url' => $this->url('website', ['id' => (string) $page['public_id']]), 'text' => t('Website')], 'settings' => $this->url('website', ['id' => (string) $page['public_id'], 'tab' => 'page']),
            'signature' => 'page:' . (int) $page['page_id'],
        ];
    }

    /** The page's address on the site (the home page is the root, a language version has its prefix). */
    public static function address(\Talea\Core\App $app, array $page): string
    {
        $home = $app->settings()->int('home_page') === (int) $page['page_id'];

        return $app->url(($page['language'] !== '' ? $page['language'] . '/' : '') . ($home ? '' : $page['slug']));
    }

    /** Publishes the page draft (from MCP too). */
    public static function publish(\Talea\Core\App $app, array $page): void
    {
        Publisher::page($app, $page);
    }

    /** The page returns to the text from the editor (the build stays in the versions). */
    protected function actionBuildText(): Response
    {
        $page = $this->request->isPost() ? $this->loadPage($this->idParam('page_id')) : null;
        if ($page !== null && $page['build'] !== null) {
            $this->db->insert('build_revisions', ['page_id' => $page['page_id'], 'created_at' => date('Y-m-d H:i:s'), 'user_id' => $this->app->auth()->id(), 'build' => $page['build']]);
            $this->db->update('pages', ['build' => null, 'build_draft' => null], ['page_id' => $page['page_id']]);
            \Talea\Core\SeoScore::refresh($this->app, 'page', (int) $page['page_id']);
        }

        return $this->back('The page shows the text from the editor (the build\'s content without the layout). You will find the build in versions when you open the builder.', 'edit', ['id' => (string) ($page['public_id'] ?? '')]);
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
    public static function version(\Talea\Core\Db $db, int $ids, int $who, string $title, string $text): void
    {
        $db->insert('page_revisions', ['page_id' => $ids, 'created_at' => date('Y-m-d H:i:s'), 'user_id' => $who, 'title' => $title, 'text' => $text]);
        $db->run('DELETE FROM {page_revisions} WHERE page_id = ? AND revision_id NOT IN (SELECT revision_id FROM (SELECT revision_id FROM {page_revisions} WHERE page_id = ? ORDER BY revision_id DESC LIMIT 30) t)', [$ids, $ids]);
    }

    /**
     * The page changed its slug: subpages move with it and the old URLs of visible pages are redirected. With slugs per
     * language (Core\Slug) only the subpages of its own language version move - /en/services/web is not a subpage of
     * /services - and the redirects carry the language prefix (Slug::redirectPath).
     */
    public static function move(\Talea\Core\Db $db, string $old, string $newVersion, bool $visible, string $language = ''): void
    {
        $redirect = fn (string $path): string => \Talea\Core\Slug::redirectPath($db, $path, $language);
        if ($visible) {
            Redirects::add($db, $redirect($old), $redirect($newVersion));
        }
        [$sameLanguage, $languageParams] = \Talea\Core\Slug::scope($db, $language);
        foreach ($db->all('SELECT page_id, slug, visible FROM {pages} WHERE slug LIKE ?' . $sameLanguage, [addcslashes($old, '%_\\') . '/%', ...$languageParams]) as $p) {
            $target = $newVersion . substr($p['slug'], strlen($old));
            $db->update('pages', ['slug' => $target], ['page_id' => $p['page_id']]);
            if ($p['visible']) {
                Redirects::add($db, $redirect($p['slug']), $redirect($target));
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
        \Talea\Core\SeoScore::refresh($this->app, 'page', (int) $page['page_id']);

        return $this->back(t('Version from %s restored.', format_date($version['created_at'], true)), 'edit', ['id' => (string) $page['public_id']]);
    }

    /** What changed since a saved version: comparison of an older version of the text with the current one. */
    protected function actionCompare(): Response
    {
        $page = $this->loadPage($this->idParam());
        $version = $page === null ? null : $this->db->one(
            "SELECT r.*, CASE WHEN u.name = '' OR u.name IS NULL THEN u.username ELSE u.name END AS user_name FROM {page_revisions} r LEFT JOIN {users} u ON u.user_id = r.user_id WHERE r.revision_id = ? AND r.page_id = ?",
            [$this->request->getInt('revision'), $page['page_id']],
        );
        if ($version === null) {
            return $this->error('This version of the page does not exist.', 404);
        }

        return $this->view('compare', 'Compare versions', [
            'page' => $page,
            'version' => $version,
            'title' => \Talea\Core\Diff::html((string) $version['title'], (string) $page['title']),
            'text' => \Talea\Core\Diff::html((string) $version['text'], (string) $page['text']),
        ]);
    }

    /**
     * The page as a JSON file – for transfer to another site running Talea. Version 2 (1.8) also carries the shared
     * classes and the components the build uses (Builder\PagePackage), so the page looks the same there.
     */
    protected function actionExport(): Response
    {
        $s = $this->loadPage($this->idParam());
        if ($s === null) {
            return $this->error('Page does not exist.', 404);
        }
        $build = Build::fromJson($s['build_draft'] ?? $s['build']);
        $json = (string) json_encode(['format' => 'talea-page', 'version' => 2, 'title' => $s['title'], 'description' => $s['description'], 'text' => $s['text'],
            'build' => $build === null ? null : \Talea\Builder\PagePackage::toPublic($this->db, $build)] + \Talea\Builder\PagePackage::collect($this->db, $build ?? []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

        return new Response($json, 200, ['Content-Type' => 'application/json; charset=utf-8', 'Content-Disposition' => 'attachment; filename="page-' . basename(str_replace('/', '-', $s['slug'])) . '.json"']);
    }

    /** Import of a page from a JSON export: a hidden page is created, the build goes through the validator like any other. */
    protected function actionImport(): Response
    {
        $file = $_FILES['file']['tmp_name'] ?? '';
        $data = $this->request->isPost() && is_uploaded_file($file) && filesize($file) < 5_000_000 ? json_decode((string) file_get_contents($file), true) : null;
        if (!is_array($data) || ($data['format'] ?? '') !== 'talea-page' || trim((string) ($data['title'] ?? '')) === '') {
            return $this->back('The file is not a page export.', '', [], 'error');
        }
        $title = mb_substr(trim((string) $data['title']), 0, 200);
        $record = ['title' => $title, 'slug' => $this->availableSlug(slugify($title, 110), 0, ''), 'description' => mb_substr((string) ($data['description'] ?? ''), 0, 300),
            'text' => \Talea\Core\WpContent::safeHtml((string) ($data['text'] ?? '')), 'visible' => 0, 'in_menu' => 0, 'updated_at' => date('Y-m-d H:i:s')];
        $created = ['classes' => 0, 'components' => 0];
        if (is_array($data['build'] ?? null)) {
            // the classes and components that came with it first: the build then points at this site's components
            [$pageBuild, $created] = \Talea\Builder\PagePackage::import($this->app->settings(), $data, $data['build'], $this->app->auth()->isAdmin());
            [$build, $errors] = Build::sanitize($pageBuild, $this->app->auth()->isAdmin());
            $record['build_draft'] = Build::toJson($build);
        }
        $id = $this->db->insert('pages', $record);
        $extra = ($data['classes'] ?? []) !== [] || ($data['components'] ?? []) !== [];

        return $this->back(match (true) {
            $extra && !$this->app->auth()->isAdmin() => t('The page has been imported as hidden – check it and publish it.') . ' ' . t('Its classes and components were not imported – only an administrator can add them.'),
            $extra => t('The page has been imported as hidden – check it and publish it.') . ' ' . t('New classes: %d, new components: %d (those the site already had were kept).', $created['classes'], $created['components']),
            default => 'The page has been imported as hidden – check it and publish it.',
        }, 'edit', ['id' => $this->publicId($id)]);
    }

    /** A free slug derived from $base: o-nas, o-nas-2, o-nas-3… */
    private function availableSlug(string $base, int $id, string $language): string
    {
        return \Talea\Core\Slug::makeUnique($base, fn (string $a): bool => \Talea\Core\Slug::taken($this->db, 'pages', $a, $language, $id), 120);
    }

    /** Deleting = moving to the trash: the page disappears from the site, the slug stays reserved and the page can be restored. */
    protected function actionDelete(): Response
    {
        $ids = $this->idParam('page_id');
        if (!$this->request->isPost()) {
            return $this->back();
        }
        if (($refusal = $this->requirePublishPermission()) !== null) {
            return $refusal;
        }
        if ($ids === $this->app->settings()->int('home_page')) {
            return $this->back('The home page cannot be deleted. First choose another home page in Settings → General.', '', [], 'error');
        }
        $this->db->run('UPDATE {pages} SET deleted_at = NOW(), visible = FALSE WHERE page_id = ? AND deleted_at IS NULL', [$ids]);

        return $this->back(t('The page is in the trash. You can restore it for %d days.', self::TRASH_DAYS));
    }

    /** Restore from the trash: the page returns hidden, only the user publishes it. */
    protected function actionRestore(): Response
    {
        if ($this->request->isPost()) {
            $this->db->run('UPDATE {pages} SET deleted_at = NULL WHERE page_id = ?', [$this->idParam('page_id')]);
            \Talea\Core\SeoScore::refresh($this->app, 'page', $this->idParam('page_id'));
        }

        return $this->back('The page has been restored as hidden – publish it in its settings.');
    }

    protected function actionDeletePermanently(): Response
    {
        if (($refusal = $this->requirePublishPermission()) !== null) {
            return $refusal;
        }
        if ($this->request->isPost()) {
            $this->db->run('DELETE FROM {pages} WHERE page_id = ? AND deleted_at IS NOT NULL', [$this->idParam('page_id')]);
        }

        return $this->back('The page has been permanently deleted.', '', ['status' => 'trash']);
    }

    /** Pages in the trash longer than TRASH_DAYS are deleted permanently (called by Admin\Kernel). */
    public static function emptyTrash(\Talea\Core\Db $db): int
    {
        return $db->run('DELETE FROM {pages} WHERE deleted_at < NOW() - INTERVAL ' . self::TRASH_DAYS . ' DAY')->rowCount();
    }

    /** Copy of the page including the build and the work-in-progress draft – hidden, with a free slug. */
    protected function actionDuplicate(): Response
    {
        $page = $this->request->isPost() ? $this->loadPage($this->idParam('page_id')) : null;
        if ($page === null) {
            return $this->back();
        }
        $copy = array_diff_key($page, ['page_id' => 0, 'public_id' => 0, 'deleted_at' => 0]);
        $copy['title'] = mb_substr(t('%s (copy)', $page['title']), 0, 200);
        $copy['slug'] = $this->availableSlug(mb_substr($page['slug'] . '-copy', 0, 110), 0, (string) $page['language']);
        $copy['visible'] = 0;
        $copy['in_menu'] = 0; // the copy does not get into the navigation until someone adds it there
        $copy['translation_of'] = null;
        $copy['updated_at'] = date('Y-m-d H:i:s');
        $id = $this->db->insert('pages', $copy);
        \Talea\Core\Members::copyGroups($this->db, 'page', (int) $page['page_id'], $id);
        // the copy carries the focus keyword: both now share it (kw_unique)
        \Talea\Core\SeoScore::refresh($this->app, 'page', $id);
        \Talea\Core\SeoScore::refresh($this->app, 'page', (int) $page['page_id']);

        return $this->back('The copy of the page is hidden – edit it and publish it.', 'edit', ['id' => $this->publicId($id)]);
    }

    /**
     * @param array<string, mixed> $page
     * @param array<string, string> $errors
     */
    private function form(array $page, array $errors = [], ?string $translationOf = null): Response
    {
        $page['public_id'] ??= $this->publicId((int) $page['page_id']);
        $language = (string) ($page['language'] ?? '');
        $custom = (string) ($page['slug'] ?? '');

        $languageName = \Talea\Core\Language::AVAILABLE[$language][0] ?? $language;

        // a new translation says so in its heading: "Translation: Garden design → Português"
        return $this->view('form', $page['page_id'] ? 'Edit page' : ($translationOf !== null ? t('Translation: %s → %s', $translationOf, $languageName) : 'New page'), [
            'page' => $page, 'errors' => $errors, 'translationOf' => $translationOf, 'languageName' => $languageName, 'parentPublicId' => $this->publicId((int) ($page['parent_id'] ?? 0)),
            // possible parent pages: the same language, not the page itself nor its subpages
            'parents' => array_values(array_filter($this->db->all('SELECT page_id, public_id, title, slug FROM {pages} WHERE language = ? AND deleted_at IS NULL AND page_id <> ? ORDER BY slug', [$language, (int) $page['page_id']]),
                fn (array $s): bool => $custom === '' || !str_starts_with($s['slug'] . '/', $custom . '/'))),
            'versions' => $page['page_id'] ? $this->db->all('SELECT r.revision_id, r.created_at, r.title, CASE WHEN u.name = \'\' THEN u.username ELSE u.name END AS user_id FROM {page_revisions} r LEFT JOIN {users} u ON u.user_id = r.user_id WHERE r.page_id = ? ORDER BY r.revision_id DESC LIMIT 30', [(int) $page['page_id']]) : [],
            'home' => $page['page_id'] > 0 && (int) $page['page_id'] === $this->app->settings()->int('home_page'),
            'inMenu' => $page['page_id'] > 0 ? \Talea\Core\Menu::hasPage($this->db, (int) $page['page_id'], (string) ($page['language'] ?? '')) : null,
            'customMenu' => \Talea\Core\Menu::load($this->db, 'main', (string) ($page['language'] ?? '')) !== null,
            // content check of the saved version (2.14, Core\ContentCheck); a page not saved yet has nothing to check
            'contentCheck' => $page['page_id'] > 0 && !$this->request->isPost() ? \Talea\Core\ContentCheck::forPage($page) : [],
        ]);
    }
}
