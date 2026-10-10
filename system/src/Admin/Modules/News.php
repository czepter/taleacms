<?php

declare(strict_types=1);

namespace Talea\Admin\Modules;

use Talea\Admin\Module;
use Talea\Core\Response;

/**
 * News and the company blog (in the database table tl_news, categories = tl_categories).
 *
 * Rules:
 *  - an author sees and edits only their own news items and cannot publish,
 *  - an editor and an administrator see all news items and publish them,
 *  - a published news item can be changed only by someone who can publish.
 */
final class News extends Module
{
    public const string IDENT = 'news';
    public const string TABLE = 'news';
    public const string EXTENSION = 'news';
    public const string NAME = 'News';
    public const string GROUP = 'Content';
    public const string ICON = 'news';

    private const int PER_PAGE = 20;

    /**
     * A draft of a news author (level 0, does not publish themselves) waits until an editor or an administrator publishes it.
     * Talea has no other status "sent for approval" – the author can only save a draft and a message tells them an editor
     * will publish it.
     */
    public const string AWAITING_PUBLICATION = 'c.visible = FALSE AND c.author_id IN (SELECT user_id FROM {users} WHERE admin = 0)';

    /** How many news items from authors wait to be published (for editors and administrators; 0 for authors). */
    public static function countAwaitingPublication(\Talea\Core\App $app): int
    {
        return $app->auth()->canPublish() ? (int) $app->db()->value('SELECT COUNT(*) FROM {news} c WHERE c.deleted_at IS NULL AND ' . self::AWAITING_PUBLICATION) : 0;
    }

    protected function actionList(): Response
    {
        $auth = $this->app->auth();
        $where = ['1 = 1'];
        $params = [];
        if (($authors = $auth->managedAuthors()) !== null) {
            $where[] = 'c.author_id IN (' . implode(',', $authors) . ')';
        }
        if (($colorScheme = $this->idParam('category', 'categories')) > 0) {
            $where[] = 'c.category_id = ?';
            $params[] = $colorScheme;
        }
        // language version: the site's default language is stored in the column as ''
        $s = $this->app->settings();
        $siteLanguages = ($additional = \Talea\Core\Language::additional($s)) === [] ? [] : [\Talea\Core\Language::defaults($s), ...$additional];
        $language = in_array($this->request->get('language'), $siteLanguages, true) ? $this->request->get('language') : '';
        if ($language !== '') {
            $where[] = 'c.language = ?';
            $params[] = \Talea\Core\Language::column($s, $language);
        }
        if (($search = $this->request->get('search')) !== '') {
            $where[] = $this->db->dialect()->likeInsensitive('c.title');
            $params[] = '%' . addcslashes($search, '%_\\') . '%';
        }
        $state = $this->request->get('status');
        $inTrash = $state === 'trash';
        $where[] = $inTrash ? 'c.deleted_at IS NOT NULL' : 'c.deleted_at IS NULL';
        $statusConditions = [
            'published' => 'c.visible = TRUE AND c.published_at <= NOW()',
            'scheduled' => 'c.visible = TRUE AND c.published_at > NOW()',
            'drafts' => 'c.visible = FALSE',
            'awaiting_publication' => self::AWAITING_PUBLICATION,
        ];
        if (isset($statusConditions[$state])) {
            $where[] = $statusConditions[$state];
        }
        $cond = implode(' AND ', $where);

        $total = (int) $this->db->value("SELECT COUNT(*) FROM {news} c WHERE {$cond}", $params);
        $pageNumber = max(1, $this->request->getInt('page', 1));
        $news = $this->db->all(
            "SELECT c.news_id, c.public_id, c.slug, c.title, c.published_at, c.visible, c.visit, c.deleted_at, c.valid_until, c.review_by,
                    t.name AS category_name, u.name AS author_name, u.username AS author_login, u.admin AS author_level,
                    (SELECT COUNT(*) FROM {social_drafts} d WHERE d.news_id = c.news_id AND d.copied_at IS NULL) AS social_open
             FROM {news} c
             JOIN {categories} t ON t.category_id = c.category_id
             LEFT JOIN {users} u ON u.user_id = c.author_id
             WHERE {$cond}
             ORDER BY " . ($inTrash ? 'c.deleted_at DESC' : 'c.published_at DESC') . ", c.news_id DESC
             LIMIT ? OFFSET ?",
            [...$params, self::PER_PAGE, ($pageNumber - 1) * self::PER_PAGE],
        );

        return $this->view('list', 'News', [
            'news' => $news,
            'total' => $total,
            'pageNumber' => $pageNumber,
            'pageCount' => max(1, (int) ceil($total / self::PER_PAGE)),
            'category' => Categories::listAll($this->db),
            'filter' => ['category' => $colorScheme > 0 ? $this->request->get('category') : '', 'language' => $language, 'search' => $search, 'status' => isset($statusConditions[$state]) || $inTrash ? $state : ''],
            'inTrash' => (int) $this->db->value('SELECT COUNT(*) FROM {news} c WHERE c.deleted_at IS NOT NULL' . $auth->articleScope('c.')),
            'toPublish' => self::countAwaitingPublication($this->app),
            'siteLanguages' => $siteLanguages,
        ]);
    }

    protected function actionNew(): Response
    {
        // without a category the news item could not be saved: the default one is created and the editor opens right away (no dead end)
        if (Categories::createDefault($this->db, $this->app->settings()) !== null) {
            $this->app->session->flash('ok', t('News items need a category, so the category “%s” has been created. You can rename it or add more under News → Categories.', (string) (Categories::listAll($this->db)[0]['name'] ?? '')));
        }

        return $this->form($this->defaults());
    }

    /** Values of a new news item; they also fill the fields missing from the form after a failed validation. */
    private function defaults(): array
    {
        // from the translation overview (2.14): the original in the default language is filled in
        $original = ($originalId = $this->idParam('translation_of')) > 0 ? $this->db->value("SELECT news_id FROM {news} WHERE news_id = ? AND language = '' AND deleted_at IS NULL", [$originalId]) : null;

        return [
            'news_id' => 0, 'slug' => '', 'title' => '', 'intro' => '', 'text' => '', 'image' => '', 'image_caption' => '', 'image_author' => '',
            'category_id' => (int) (Categories::listAll($this->db)[0]['category_id'] ?? 0), 'author_id' => $this->app->auth()->id(), 'published_at' => date('Y-m-d H:i:s'),
            'visible' => 0, 'keywords' => '', 'seo_title' => '', 'seo_description' => '', 'noindex' => 0, 'translation_of' => $original === null ? null : (int) $original, 'faq' => '', 'language' => '',
            'valid_until' => null, 'review_by' => null,
        ];
    }

    /**
     * Actions the list does with ticked news items (2.14): publish, back to draft, category (the category sets the
     * language version), trash. The same rules as for one news item: an author only their own drafts, publishing only
     * with the permission to publish.
     */
    protected function actionBulk(): Response
    {
        if (!$this->request->isPost()) {
            return $this->back();
        }
        $action = $this->request->post('bulk');
        if (!in_array($action, ['publish', 'draft', 'category', 'trash'], true)) {
            return $this->back('Unknown action.', '', [], 'error');
        }
        $auth = $this->app->auth();
        $category = $action === 'category' ? $this->db->one('SELECT category_id, language FROM {categories} WHERE category_id = ?', [$this->idParam('category', 'categories')]) : null;
        if ($action === 'category' && $category === null) {
            return $this->back('Choose a category.', '', [], 'error');
        }
        $done = 0;
        $skipped = 0;
        // the list's checkboxes are the ones of "Delete selected" (smaz[]); oznacene[] is what the other lists send
        foreach (array_unique(array_map(fn (string $uuid): int => $this->db->internalId('news', $uuid), [...$this->request->postList('delete'), ...$this->request->postList('selected')])) as $id) {
            $newsItem = $this->load($id);
            if ($newsItem === null || (!$auth->canPublish() && ($newsItem['visible'] || $action === 'publish'))) {
                $skipped++;
                continue;
            }
            $now = date('Y-m-d H:i:s');
            match ($action) {
                'publish' => $this->db->update('news', ['visible' => 1, 'edited_at' => $now], ['news_id' => $id]),
                'draft' => $this->db->update('news', ['visible' => 0, 'edited_at' => $now], ['news_id' => $id]),
                // a translation stays linked to its original only in another language version
                'category' => $this->db->update('news', ['category_id' => (int) $category['category_id'], 'language' => $category['language'], 'translation_of' => $category['language'] === '' ? null : $newsItem['translation_of'], 'edited_at' => $now], ['news_id' => $id]),
                default => $this->db->update('news', ['deleted_at' => $now, 'visible' => 0], ['news_id' => $id]),
            };
            \Talea\Admin\ChangeLog::write($this->app, 'news', 'bulk ' . ['publish' => 'published', 'draft' => 'back to draft', 'category' => 'category', 'trash' => 'moved to trash'][$action], mb_substr($newsItem['title'], 0, 80));
            $done++;
        }
        if ($done > 0) {
            \Talea\Front\Cache::clear();
            if ($action === 'publish') {
                \Talea\Core\Notifications::process($this->app); // newly published news items are announced (webhook, IndexNow)
            }
        }
        $message = match ($action) {
            'publish' => t('News items published: %d.', $done), 'draft' => t('News items back as drafts: %d.', $done),
            'category' => t('News items moved to the category: %d.', $done), default => t('News items moved to the trash: %d. They can be restored for 30 days (News → Trash).', $done),
        };

        return $this->back($message . ($skipped > 0 ? ' ' . t('Skipped: %d (no permission).', $skipped) : ''), '', [], $done > 0 ? 'ok' : 'error');
    }

    /** Copy of a news item as a draft (tags included) – a quick start for a similar news item. */
    protected function actionDuplicate(): Response
    {
        $newsItem = $this->request->isPost() ? $this->load($this->idParam('news_id')) : null;
        if ($newsItem === null) {
            return $this->back();
        }
        $copy = array_intersect_key($newsItem, array_flip(['intro', 'text', 'image', 'image_caption', 'image_author', 'category_id', 'keywords', 'seo_description', 'noindex', 'faq', 'language']));
        $seo = \Talea\Core\Slug::makeUnique($newsItem['slug'] . '-kopie', fn (string $a): bool => $this->db->value('SELECT 1 FROM {news} WHERE slug = ?', [$a]) !== null);
        $id = $this->db->insert('news', $copy + ['title' => mb_substr(t('%s (copy)', $newsItem['title']), 0, 255), 'slug' => $seo, 'visible' => 0,
            'published_at' => date('Y-m-d H:i:s'), 'author_id' => $this->app->auth()->id(), 'edited_at' => date('Y-m-d H:i:s')]);
        $this->db->run('INSERT INTO {news_tags} (news_id, tag_id) SELECT ?, tag_id FROM {news_tags} WHERE news_id = ?', [$id, $newsItem['news_id']]);
        \Talea\Core\Search::index($this->db, $id);

        return $this->back('The copy of the news item is saved as a draft.', 'edit', ['id' => $this->publicId($id)]);
    }

    protected function actionEdit(): Response
    {
        $newsItem = $this->load($this->idParam());

        return $newsItem === null ? $this->error('The news item does not exist or you do not have access to it.', 404) : $this->form($newsItem);
    }

    protected function actionSave(): Response
    {
        if (!$this->request->isPost()) {
            return $this->back();
        }
        $auth = $this->app->auth();
        $r = $this->request;
        if (($refusal = $this->refuseUnknownId('news_id', 'The news item does not exist or you do not have access to it.')) !== null) {
            return $refusal;
        }
        $id = $this->idParam('news_id');

        $previous = null;
        if ($id > 0) {
            $previous = $this->load($id);
            if ($previous === null) {
                return $this->error('The news item does not exist or you do not have access to it.', 404);
            }
            if ($previous['visible'] && !$auth->canPublish()) {
                return $this->error('Only an editor or administrator can edit a published news item.', 403);
            }
        }

        $data = [
            'title' => $r->post('title'),
            'slug' => slugify($r->post('slug') !== '' ? $r->post('slug') : $r->post('title'), 150),
            'intro' => \Talea\Core\Html::forUser($r->post('intro'), $this->app->auth()),
            'text' => \Talea\Core\Html::forUser($r->post('text'), $this->app->auth()),
            'image' => $r->post('image'),
            'image_caption' => mb_substr(trim($r->post('image_caption')), 0, 300),
            'image_author' => mb_substr(trim($r->post('image_author')), 0, 120),
            'category_id' => $this->idParam('category_id', 'categories'),
            'author_id' => $this->idParam('author_id', 'users'),
            'published_at' => self::parseFormDate($r->post('published_at')) ?? date('Y-m-d H:i:s'),
            'visible' => (int) ($r->post('status') === 'published' && $auth->canPublish()),
            'keywords' => $r->post('keywords'),
            'seo_title' => mb_substr($r->post('seo_title'), 0, 255),
            'seo_description' => mb_substr($r->post('seo_description'), 0, 320),
            'noindex' => (int) $r->postBool('noindex'),
            'faq' => $r->post('faq'),
            'edited_at' => date('Y-m-d H:i:s'),
            // true until and review by (2.10, Core\Validity): empty or not a date = none
            'valid_until' => \Talea\Core\Validity::date($r->post('valid_until')),
            'review_by' => \Talea\Core\Validity::date($r->post('review_by')),
        ];

        $errors = [];
        if ($data['title'] === '') {
            $errors['title'] = 'Enter a title.';
        }
        if ($this->db->value('SELECT category_id FROM {categories} WHERE category_id = ?', [$data['category_id']]) === null) {
            $errors['category_id'] = 'Choose a category.';
        }
        $allowedAuthors = $auth->managedAuthors();
        if ($allowedAuthors !== null && !in_array($data['author_id'], $allowedAuthors, true)) {
            $data['author_id'] = $auth->id();
        }
        if ($this->db->value('SELECT user_id FROM {users} WHERE user_id = ?', [$data['author_id']]) === null) {
            $errors['author_id'] = 'Select an author.';
        }
        if ($errors !== []) {
            return $this->form(['news_id' => $id] + $data + ($previous ?? $this->defaults()), $errors);
        }
        // the language version is taken from the category; a translation is linked to the news item in the default language (slug or number)
        $data['language'] = (string) $this->db->value('SELECT language FROM {categories} WHERE category_id = ?', [$data['category_id']]);
        $original = trim($r->post('translation_of'));
        $data['translation_of'] = $original === '' || $data['language'] === '' ? null
            : ($this->db->value("SELECT news_id FROM {news} WHERE (public_id = ? OR slug = ?) AND language = '' AND news_id <> ?", [$original, basename((string) parse_url($original, PHP_URL_PATH)), $id]) ?: null);

        if ($r->postBool('mark_updated') && $data['visible']) {
            $data['updated_at'] = date('Y-m-d H:i:s');
        }
        $data['slug'] = $this->findFreeSlug($data['slug'], $id);
        if ($id > 0) {
            if ([$previous['title'], $previous['intro'], $previous['text']] !== [$data['title'], $data['intro'], $data['text']]) {
                self::version($this->db, $previous, $this->app->auth()->id());
            }
            $this->db->update('news', $data, ['news_id' => $id]);
            if ($previous['slug'] !== $data['slug'] && $previous['visible']) {
                // a published news item changed its slug: the old one is redirected so that links and search engines do not lose the page
                Redirects::add($this->db, 'news/' . $previous['slug'], 'news/' . $data['slug']);
            }
        } else {
            $id = $this->db->insert('news', $data);
        }

        Media::recordUsage($this->db, $id, $data['image'], $data['intro'], $data['text']);
        \Talea\Core\Search::index($this->db, $id);
        // a saved news item clears the unsaved state on the server (for a new one it is kept under the number 0)
        $this->db->run('DELETE FROM {news_drafts} WHERE user_id = ? AND news_id IN (0, ?)', [$auth->id(), $id]);
        self::tags($this->db, $id, $r->post('tags'));
        // a newly published news item is announced (webhook, IndexNow); a scheduled one waits for its time - see Core\Notifications
        \Talea\Core\Notifications::process($this->app);
        if ($data['visible'] && !empty($previous['visible']) && !$data['noindex'] && strtotime($data['published_at']) <= time()) {
            (new \Talea\Front\Seo($this->app))->indexNow($this->app->newsItemUrl($data['slug'], $data['language']));
        }

        $message = $auth->canPublish() ? 'News item saved.' : 'News item saved. It will appear on the site once an editor publishes it.';

        return $r->post('after_save') === 'stay' ? $this->back($message, 'edit', ['id' => $this->publicId($id)]) : $this->back($message);
    }

    /**
     * Saving from editing "directly on the site" (views/front/edit.php): only the title, intro and text. The same rules
     * apply as for a regular save - permissions via load(), a published news item only with the permission to publish,
     * versions, search, image usage.
     */
    protected function actionSaveText(): Response
    {
        $r = $this->request;
        $newsItem = $r->isPost() ? $this->load($this->idParam()) : null;
        if ($newsItem === null || ($newsItem['visible'] && !$this->app->auth()->canPublish())) {
            return $this->redirectToSite($r->post('back'));
        }
        $data = ['title' => mb_substr($r->post('title'), 0, 255), 'intro' => \Talea\Core\Html::forUser($r->post('intro'), $this->app->auth()), 'text' => \Talea\Core\Html::forUser($r->post('text'), $this->app->auth())];
        // an unpublished news item is visible on the site only in the preview
        $preview = $newsItem['visible'] && strtotime((string) $newsItem['published_at']) <= time() ? '' : 'preview=1';
        if ($data['title'] === '') {
            return $this->redirectToSite($r->post('back'), '?' . ($preview !== '' ? $preview . '&' : '') . 'edit=text&error=1');
        }
        if ([$newsItem['title'], $newsItem['intro'], $newsItem['text']] !== array_values($data)) {
            self::version($this->db, $newsItem, $this->app->auth()->id());
        }
        $this->db->update('news', $data + ['edited_at' => date('Y-m-d H:i:s')], ['news_id' => $newsItem['news_id']]);
        Media::recordUsage($this->db, (int) $newsItem['news_id'], (string) $newsItem['image'], $data['intro'], $data['text']);
        \Talea\Core\Search::index($this->db, (int) $newsItem['news_id']);
        \Talea\Admin\ChangeLog::write($this->app, 'news', 'edited directly on the site', mb_substr($data['title'], 0, 80));
        if ($newsItem['visible'] && !$newsItem['noindex'] && strtotime((string) $newsItem['published_at']) <= time()) {
            (new \Talea\Front\Seo($this->app))->indexNow($this->app->newsItemUrl($newsItem['slug'], $newsItem['language']));
        }

        return $this->redirectToSite($r->post('back'), $preview !== '' ? '?' . $preview : '');
    }

    /**
     * Autosaving an unsaved news item to the server (image/editor.js). It does not save the news item - only the form state
     * of the signed-in user, so that they can continue writing elsewhere. A POST without the field "fields" deletes the unsaved state.
     */
    protected function actionDraft(): Response
    {
        if (!$this->request->isPost()) {
            return Response::json(['ok' => false], 405);
        }
        $idc = $this->idParam('news_id');
        if ($this->request->post('news_id') !== '' && $this->load($idc) === null) {
            return Response::json(['ok' => false], 404);
        }
        $me = $this->app->auth()->id();
        $data = (string) ($_POST['fields'] ?? '');
        if ($data === '' || strlen($data) > 3_000_000 || !is_array(json_decode($data, true))) {
            $this->db->delete('news_drafts', ['user_id' => $me, 'news_id' => $idc]);

            return Response::json(['ok' => true, 'deleted_at' => true]);
        }
        $this->db->upsert('news_drafts', ['user_id' => $me, 'news_id' => $idc, 'saved_at' => date('Y-m-d H:i:s'), 'data' => $data], ['user_id', 'news_id']);
        if (random_int(1, 40) === 1) {
            $this->db->run('DELETE FROM {news_drafts} WHERE saved_at < NOW() - INTERVAL 30 DAY');
        }

        return Response::json(['ok' => true]);
    }

    /** Searching news by title for the link dialog in the editor and for the command palette (?edit=1). */
    protected function actionSearchJson(): Response
    {
        $q = mb_substr(trim($this->request->get('q')), 0, 80);
        if (mb_strlen($q) < 2) {
            return Response::json(['articles' => []]);
        }
        $editMode = $this->request->get('edit') === '1';
        $news = $this->db->all(
            'SELECT public_id, title, slug, language, visible AND published_at <= NOW() AS published FROM {news} WHERE deleted_at IS NULL AND ' . $this->db->dialect()->likeInsensitive('title')
                . ($editMode ? $this->app->auth()->articleScope() : '') . ' ORDER BY published_at DESC LIMIT 8',
            ['%' . addcslashes($q, '%_\\') . '%'],
        );

        return Response::json(['articles' => array_map(fn (array $c): array => [
            'title' => $c['title'], 'published' => (bool) $c['published'],
            'url' => $editMode ? $this->url('edit', ['id' => $c['public_id']]) : $this->app->url(($c['language'] !== '' ? $c['language'] . '/' : '') . 'news/' . $c['slug']),
        ], $news)]);
    }

    /**
     * AI assistant: a suggestion for the news item being written (titles, intro, SEO description, tags, proofreading, image description).
     * Works with the text from the form, saves nothing - a human decides whether to use the suggestion.
     */
    protected function actionAssistant(): Response
    {
        $assistant = new \Talea\Core\Assistant($this->app->settings());
        if (!$this->request->isPost() || !$assistant->isReady()) {
            return Response::json(['error' => t('The writing assistant is not enabled or the key is missing (Features).')], 400);
        }
        // safeguard against unwanted spending: at most 60 requests per hour per user
        if ($this->hasTooManyRequests()) {
            return Response::json(['error' => t('You have used the assistant 60 times in the last hour. Please try again later.')], 429);
        }
        $task = $this->request->post('task');
        $image = null;
        if ($task === 'alt') {
            // only files from media/: the path is assembled from verified parts of the URL
            $image = preg_match('#media/(\d{4}/\d{2}/[A-Za-z0-9._-]+\.(?:jpe?g|png|webp|gif))$#', (string) parse_url($this->request->post('image'), PHP_URL_PATH), $m) ? TALEA_ROOT . '/media/' . $m[1] : null;
            $smaller = $image === null ? null : preg_replace('/\.(\w+)$/', '-1200.$1', $image);
            $image = $smaller !== null && is_file($smaller) ? $smaller : $image;
        }
        try {
            $result = $assistant->suggest($task, [
                'title' => $this->request->post('title'),
                'intro' => $this->request->post('intro'),
                'text' => $this->request->post('text'),
                'site_tags' => $task === 'tags' ? array_column($this->db->all('SELECT name FROM {tags} ORDER BY name LIMIT 300'), 'name') : [],
            ], $image);
        } catch (\RuntimeException $e) {
            return Response::json(['error' => t($e->getMessage())], 502);
        }
        \Talea\Admin\ChangeLog::write($this->app, 'assistant', $task, mb_substr($this->request->post('title'), 0, 80));

        return Response::json($result);
    }

    /** "Social posts" panel (2.13, Core\SocialDrafts): a person edited a draft before copying it. */
    protected function actionSocialSave(): Response
    {
        $draft = $this->socialDraft();
        if ($draft === null) {
            return $this->back();
        }
        $error = \Talea\Core\SocialDrafts::update($this->db, $draft['id'], $this->request->post('text'));
        if ($error !== null) {
            return $this->backToSocial((int) $draft['news_id'], $error, 'error');
        }
        \Talea\Admin\ChangeLog::write($this->app, 'news', 'social draft', $draft['network'] . ': ' . mb_substr((string) $this->db->value('SELECT title FROM {news} WHERE news_id = ?', [(int) $draft['news_id']]), 0, 80));

        return $this->backToSocial((int) $draft['news_id'], 'The post draft is saved.');
    }

    /** "Mark as posted" (and back) on a social post draft. */
    protected function actionSocialPosted(): Response
    {
        $draft = $this->socialDraft();
        if ($draft === null) {
            return $this->back();
        }
        \Talea\Core\SocialDrafts::markPosted($this->db, $draft['id'], $this->request->postBool('posted'));

        return $this->backToSocial((int) $draft['news_id'], $this->request->postBool('posted') ? 'Marked as posted.' : 'Marked as not posted yet.');
    }

    /** "Suggest with the assistant": the AI assistant rewrites the drafts of the news item – only on this click. */
    protected function actionSocialSuggest(): Response
    {
        $newsItem = $this->request->isPost() ? $this->load($this->idParam('news_id')) : null;
        if ($newsItem === null) {
            return $this->back();
        }
        if ($this->hasTooManyRequests()) {
            return $this->backToSocial((int) $newsItem['news_id'], 'You have used the assistant 60 times in the last hour. Please try again later.', 'error');
        }
        $error = \Talea\Core\SocialDrafts::suggest($this->app, (int) $newsItem['news_id']);
        if ($error !== null) {
            return $this->backToSocial((int) $newsItem['news_id'], $error, 'error');
        }
        \Talea\Admin\ChangeLog::write($this->app, 'assistant', 'posts', mb_substr((string) $newsItem['title'], 0, 80));

        return $this->backToSocial((int) $newsItem['news_id'], 'The assistant rewrote the drafts – read them before posting.');
    }

    /** The draft from the POST, only when the news item is within the signed-in user's scope. */
    private function socialDraft(): ?array
    {
        $draft = $this->request->isPost() ? \Talea\Core\SocialDrafts::find($this->db, $this->request->postInt('id')) : null;

        return $draft !== null && $this->load($draft['news_id']) !== null ? $draft : null;
    }

    /** Back to the editor, to the "Social posts" panel. */
    private function backToSocial(int $idc, string $message, string $type = 'ok'): Response
    {
        $this->app->session->flash($type, t($message));

        return Response::redirect($this->url('edit', ['id' => $this->publicId($idc)]) . '#social-posts');
    }

    /**
     * "Translate with the assistant": from the saved version of the news item in the default language
     * it creates a draft in the category of the target language, linked to the original. The translation always waits to be
     * read by a human – it is never published by itself.
     */
    protected function actionTranslate(): Response
    {
        $newsItem = $this->request->isPost() ? $this->load($this->idParam('news_id')) : null;
        if ($newsItem === null) {
            return $this->back('Save the news item first, then it can be translated.', type: 'error');
        }
        $backToNewsItem = fn (string $message): Response => $this->back($message, 'edit', ['id' => $newsItem['public_id']], type: 'error');
        $language = $this->request->post('translate_to');
        $assistant = new \Talea\Core\Assistant($this->app->settings());
        if (!$assistant->isReady()) {
            return $backToNewsItem('The writing assistant is not enabled or the key is missing (Features).');
        }
        if ($newsItem['language'] !== '' || !in_array($language, \Talea\Core\Language::additional($this->app->settings()), true)) {
            return $backToNewsItem('Only a news item in the default language can be translated, and only into one of the other language versions of the site.');
        }
        if (($existing = $this->db->value('SELECT news_id FROM {news} WHERE translation_of = ? AND language = ?', [$newsItem['news_id'], $language])) !== null) {
            return $this->back('A translation into this language already exists – here it is.', 'edit', ['id' => $this->publicId((int) $existing)]);
        }
        // target category: the counterpart of the original's category, otherwise the first category of the given language
        $category = $this->db->value('SELECT category_id FROM {categories} WHERE language = ? ORDER BY CASE WHEN translation_of = ? THEN 1 ELSE 0 END DESC, weight DESC, category_id LIMIT 1', [$language, $newsItem['category_id']]);
        if ($category === null) {
            return $backToNewsItem('There is no category in the target language yet. Create one in News → Categories (the Language version field).');
        }
        if ($this->hasTooManyRequests()) {
            return $backToNewsItem('You have used the assistant 60 times in the last hour. Please try again later.');
        }

        set_time_limit(600); // a long text is translated in batches
        $plainFields = ['title', 'seo_title', 'seo_description', 'faq', 'keywords'];
        try {
            $translation = $assistant->translate(array_map(strval(...), array_intersect_key($newsItem, array_flip([...$plainFields, 'intro', 'text']))), $language, $plainFields);
        } catch (\RuntimeException $e) {
            return $backToNewsItem(t($e->getMessage()));
        }
        \Talea\Admin\ChangeLog::write($this->app, 'assistant', 'preklad-' . $language, mb_substr($newsItem['title'], 0, 80));

        // the draft takes everything that is not translated from the original (image, author…); not the counters
        $data = array_intersect_key($newsItem, array_flip(['image', 'author_id', 'noindex'])) + [
            'category_id' => (int) $category, 'language' => $language, 'translation_of' => $newsItem['news_id'], 'visible' => 0, 'published_at' => date('Y-m-d H:i:s'),
        ];
        foreach ($translation as $field => $value) {
            $data[$field] = $field === 'title' ? mb_substr($value, 0, 255) : $value;
        }
        $data['slug'] = $this->findFreeSlug(slugify($data['title'], 100), 0);
        $id = $this->db->insert('news', $data);
        Media::recordUsage($this->db, $id, (string) $data['image'], $data['intro'], $data['text']);
        $this->db->run('INSERT INTO {news_tags} (news_id, tag_id) SELECT ?, tag_id FROM {news_tags} WHERE news_id = ?', [$id, $newsItem['news_id']]);
        \Talea\Core\Search::index($this->db, $id);

        return $this->back('The translation has been created as a draft. Read it before publishing – the assistant can make mistakes in names, numbers and technical terms.', 'edit', ['id' => $this->publicId($id)]);
    }

    private function hasTooManyRequests(): bool
    {
        return (int) $this->db->value("SELECT COUNT(*) FROM {change_log} WHERE user_id = ? AND module = 'assistant' AND created_at > NOW() - INTERVAL 1 HOUR", [$this->app->auth()->id()]) >= 60;
    }

    /** Loads an older version of the news item into the editor; it is saved only when the form is submitted. */
    protected function actionVersions(): Response
    {
        $newsItem = $this->load($this->idParam());
        $version = $newsItem === null ? null : $this->db->one('SELECT * FROM {news_revisions} WHERE revision_id = ? AND news_id = ?', [$this->request->getInt('revision'), $newsItem['news_id']]);
        if ($version === null) {
            return $this->error('This version of the news item does not exist.', 404);
        }
        $this->app->session->flash('info', t('The editor now contains the version from %s. It takes effect once you save the news item.', format_date($version['created_at'], true)));

        return $this->form(['title' => $version['title'], 'intro' => $version['intro'], 'text' => $version['text']] + $newsItem);
    }

    /** What changed since the saved version: comparison of an older version with the current wording. */
    protected function actionCompare(): Response
    {
        $newsItem = $this->load($this->idParam());
        $version = $newsItem === null ? null : $this->db->one(
            "SELECT r.*, CASE WHEN u.name = '' OR u.name IS NULL THEN u.username ELSE u.name END AS user_name FROM {news_revisions} r LEFT JOIN {users} u ON u.user_id = r.user_id WHERE r.revision_id = ? AND r.news_id = ?",
            [$this->request->getInt('revision'), $newsItem['news_id'] ?? 0],
        );
        if ($version === null) {
            return $this->error('This version of the news item does not exist.', 404);
        }

        return $this->view('compare', 'Compare versions', [
            'newsItem' => $newsItem,
            'versions' => $version,
            'title' => \Talea\Core\Diff::html((string) $version['title'], (string) $newsItem['title']),
            'home' => \Talea\Core\Diff::html((string) $version['intro'], (string) $newsItem['intro']),
            'text' => \Talea\Core\Diff::html((string) $version['text'], (string) $newsItem['text']),
        ]);
    }

    /** Broken links found by the background check (Core\Links) – since 2.14 across news items, page builds and collection items. */
    protected function actionLinks(): Response
    {
        if ($this->request->isPost()) {
            // "check again": the record is put at the front of the queue
            $kind = $this->request->post('kind') ?: 'news';
            \Talea\Core\Links::recheck($this->app, $kind, $this->db->internalId(\Talea\Core\Links::KINDS[$kind][0] ?? '', $this->request->post('id')));

            return $this->back('It will be checked again within a few minutes.', 'links');
        }
        $pages = $this->app->auth()->hasModule('pages');

        return $this->view('links', 'Broken links', [
            'links' => array_values(array_filter(\Talea\Core\Links::broken($this->app, 300, $this->app->auth()->articleScope('c.')), fn (array $l): bool => $l['kind'] === 'news' || $pages)),
            'checked' => (int) $this->db->value('SELECT (SELECT COUNT(*) FROM {news} WHERE links_checked_at IS NOT NULL) + (SELECT COUNT(*) FROM {pages} WHERE links_checked IS NOT NULL) + (SELECT COUNT(*) FROM {collection_items} WHERE links_checked IS NOT NULL)'),
            'total' => (int) $this->db->value('SELECT (SELECT COUNT(*) FROM {news} WHERE visible = TRUE AND published_at <= NOW() AND deleted_at IS NULL) + (SELECT COUNT(*) FROM {pages} WHERE visible = TRUE AND deleted_at IS NULL) + (SELECT COUNT(*) FROM {collection_items} WHERE visible = TRUE AND deleted_at IS NULL)'),
            'isEnabled' => $this->app->settings()->bool('link_check'),
        ]);
    }

    protected function actionDelete(): Response
    {
        if (!$this->request->isPost()) {
            return $this->back();
        }
        $moved = 0;
        foreach ($this->request->postList('delete') as $id) {
            $newsItem = $this->load($this->db->internalId('news', $id));
            if ($newsItem === null || ($newsItem['visible'] && !$this->app->auth()->canPublish())) {
                continue;
            }
            // trash: the news item disappears from the site and from lists, but can be restored for 30 days; it returns as a draft, never published by itself
            $moved += $this->db->update('news', ['deleted_at' => date('Y-m-d H:i:s'), 'visible' => 0], ['news_id' => $newsItem['news_id']]);
            \Talea\Admin\ChangeLog::write($this->app, 'news', 'moved to trash', mb_substr($newsItem['title'], 0, 80));
        }

        return $this->back(t('News items moved to the trash: %d. They can be restored for 30 days (News → Trash).', $moved), type: $moved > 0 ? 'ok' : 'error');
    }

    /** Restore from the trash: the news item returns as a draft (not published). */
    protected function actionRestore(): Response
    {
        if (!$this->request->isPost()) {
            return $this->back();
        }
        $restored = 0;
        foreach ($this->request->postList('delete') as $id) {
            $newsItem = $this->load($this->db->internalId('news', $id), true);
            if ($newsItem === null) {
                continue;
            }
            $restored += $this->db->update('news', ['deleted_at' => null], ['news_id' => $newsItem['news_id']]);
            \Talea\Admin\ChangeLog::write($this->app, 'news', 'restored from trash', mb_substr($newsItem['title'], 0, 80));
        }

        return $this->back(t('News items restored: %d. They are back as drafts.', $restored), '', ['status' => 'trash'], $restored > 0 ? 'ok' : 'error');
    }

    /** Permanent deletion from the trash (only someone who can publish). */
    protected function actionDeletePermanently(): Response
    {
        if (!$this->request->isPost() || !$this->app->auth()->canPublish()) {
            return $this->back();
        }
        $deleted = 0;
        foreach ($this->request->postList('delete') as $id) {
            $newsItem = $this->load($this->db->internalId('news', $id), true);
            if ($newsItem !== null) {
                $deleted += $this->db->delete('news', ['news_id' => $newsItem['news_id']]);
                \Talea\Admin\ChangeLog::write($this->app, 'news', 'deleted permanently', mb_substr($newsItem['title'], 0, 80));
            }
        }

        return $this->back(t('News items permanently deleted: %d.', $deleted), '', ['status' => 'trash'], $deleted > 0 ? 'ok' : 'error');
    }

    /** The trash empties itself: news items older than 30 days are deleted permanently (called by Admin\Kernel on entering the admin). */
    public static function emptyTrash(\Talea\Core\Db $db): int
    {
        return $db->run('DELETE FROM {news} WHERE deleted_at < NOW() - INTERVAL 30 DAY')->rowCount();
    }

    /**
     * @param array<string, mixed> $newsItem
     * @param array<string, string> $errors
     */
    private function form(array $newsItem, array $errors = []): Response
    {
        $newsItem['public_id'] ??= $this->publicId((int) $newsItem['news_id']);
        $auth = $this->app->auth();
        $allowedIds = $auth->managedAuthors();
        $authors = $allowedIds === null
            ? $this->db->pairs("SELECT public_id, CASE WHEN name = '' THEN username ELSE name END FROM {users} WHERE blocked = FALSE ORDER BY 2")
            : $this->db->pairs("SELECT public_id, CASE WHEN name = '' THEN username ELSE name END FROM {users} WHERE user_id IN (" . implode(',', $allowedIds) . ') ORDER BY 2');
        // social post drafts (2.13): only a published news item has them; a news item published through Claude gets them here at the latest
        $published = $newsItem['news_id'] && $newsItem['visible'] && strtotime((string) $newsItem['published_at']) <= time() && empty($newsItem['deleted_at']);
        if ($published) {
            \Talea\Core\SocialDrafts::prepare($this->app, (int) $newsItem['news_id']);
        }

        return $this->view('form', $newsItem['news_id'] ? 'Edit news item' : 'New news item', [
            'socialDrafts' => $published ? \Talea\Core\SocialDrafts::forNews($this->db, (int) $newsItem['news_id']) : null,
            'newsItem' => $newsItem, 'categoryPublicId' => $this->publicId((int) $newsItem['category_id'], 'categories'), 'authorPublicId' => $this->publicId((int) $newsItem['author_id'], 'users'),
            'errors' => $errors,
            'category' => Categories::listAll($this->db),
            'authors' => $authors,
            'canPublish' => $auth->canPublish(),
            'draftOnServer' => $this->request->isPost() ? null : $this->db->one('SELECT saved_at, data FROM {news_drafts} WHERE user_id = ? AND news_id = ?', [$auth->id(), (int) $newsItem['news_id']]),
            'siteLanguages' => \Talea\Core\Language::additional($this->app->settings()) !== [],
            // for a news item in the default language: which languages it can be translated into and which translations already exist (language => number)
            'translationLanguages' => $newsItem['news_id'] && ($newsItem['language'] ?? '') === '' ? \Talea\Core\Language::additional($this->app->settings()) : [],
            'translations' => $newsItem['news_id'] ? $this->db->pairs("SELECT language, public_id FROM {news} WHERE translation_of = ? AND language <> ''", [(int) $newsItem['news_id']]) : [],
            'original' => empty($newsItem['translation_of']) ? '' : (string) $this->db->value('SELECT slug FROM {news} WHERE news_id = ?', [$newsItem['translation_of']]),
            'assistant' => (new \Talea\Core\Assistant($this->app->settings()))->isReady(),
            // content check of the saved version (2.14, Core\ContentCheck); a news item not saved yet has nothing to check
            'contentCheck' => $newsItem['news_id'] && !$this->request->isPost() ? \Talea\Core\ContentCheck::forNews($newsItem) : [],
            'tags' => $this->request->isPost() ? $this->request->post('tags') : implode(', ', array_column(
                $this->db->all('SELECT s.name FROM {tags} s JOIN {news_tags} cs ON cs.tag_id = s.tag_id WHERE cs.news_id = ? ORDER BY s.name', [(int) $newsItem['news_id']]),
                'name',
            )),
            'allTags' => array_column($this->db->all('SELECT name FROM {tags} ORDER BY name LIMIT 500'), 'name'),
            'versions' => $this->db->all(
                "SELECT r.revision_id, r.created_at, r.title, CASE WHEN u.name = '' OR u.name IS NULL THEN u.username ELSE u.name END AS user_name
                 FROM {news_revisions} r LEFT JOIN {users} u ON u.user_id = r.user_id WHERE r.news_id = ? ORDER BY r.revision_id DESC",
                [(int) $newsItem['news_id']],
            ),
        ]);
    }

    /** Saves the previous form of the news item; the last 20 versions are kept. */
    /** The previous form of the news item to the history (the last 20 versions) – admin and MCP. */
    public static function version(\Talea\Core\Db $db, array $previous, ?int $who): void
    {
        $db->insert('news_revisions', [
            'news_id' => $previous['news_id'], 'created_at' => $previous['edited_at'] ?? $previous['published_at'], 'user_id' => $who,
            'title' => $previous['title'], 'intro' => $previous['intro'], 'text' => $previous['text'],
        ]);
        $boundary = $db->value('SELECT revision_id FROM {news_revisions} WHERE news_id = ? ORDER BY revision_id DESC LIMIT 1 OFFSET 20', [$previous['news_id']]);
        if ($boundary !== null) {
            $db->run('DELETE FROM {news_revisions} WHERE news_id = ? AND revision_id <= ?', [$previous['news_id'], $boundary]);
        }
    }

    /** Tags written with commas (at most 20); unknown ones are created – admin and MCP. */
    public static function tags(\Talea\Core\Db $db, int $idc, string $input): void
    {
        $db->delete('news_tags', ['news_id' => $idc]);
        $names = array_unique(array_filter(array_map(fn (string $n): string => mb_substr(trim($n), 0, 80), explode(',', $input))));
        foreach (array_slice($names, 0, 20) as $name) {
            $seo = slugify($name, 90);
            $ids = $db->value('SELECT tag_id FROM {tags} WHERE slug = ?', [$seo]);
            $ids = $ids !== null ? (int) $ids : $db->insert('tags', ['name' => $name, 'slug' => $seo]);
            $db->insertIgnore('news_tags', ['news_id' => $idc, 'tag_id' => $ids]);
        }
    }

    /** Loads a news item only if the signed-in user can manage it. A news item in the trash only with $fromTrash (restore, permanent deletion). */
    private function load(int $id, bool $fromTrash = false): ?array
    {
        $newsItem = $this->db->one('SELECT * FROM {news} WHERE news_id = ? AND deleted_at IS ' . ($fromTrash ? 'NOT NULL' : 'NULL'), [$id]);
        $authors = $this->app->auth()->managedAuthors();

        return $newsItem === null || ($authors !== null && !in_array((int) $newsItem['author_id'], $authors, true)) ? null : $newsItem;
    }

    private function findFreeSlug(string $seo, int $idc): string
    {
        return \Talea\Core\Slug::makeUnique($seo, fn (string $a): bool => $this->db->value('SELECT news_id FROM {news} WHERE slug = ? AND news_id <> ?', [$a, $idc]) !== null);
    }

    /** Value from <input type="datetime-local"> -> DATETIME; empty or invalid = null. */
    private static function parseFormDate(string $value): ?string
    {
        if ($value === '') {
            return null;
        }
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i', substr($value, 0, 16));

        return $dt === false ? null : $dt->format('Y-m-d H:i:00');
    }
}
