<?php

declare(strict_types=1);

namespace Talea\Admin\Modules;

use Talea\Admin\Module;
use Talea\Core\Images;
use Talea\Core\Response;
use Talea\Core\Sites;

/**
 * Media: uploading, also by drag and drop and directly from the editor, folders,
 * labels, deleting and an overview of where an image is used (news, builds, collections, logo…).
 * An image is inserted into the text from the editor.
 */
final class Media extends Module
{
    public const string IDENT = 'media';
    public const string NAME = 'Media';
    public const string GROUP = 'Content';
    public const string ICON = 'media';
    public const string TABLE = 'media';

    /** Everyone who writes news must be able to upload; but only the administrator changes and deletes other people's images. */
    public const bool FOR_ALL_USERS = true;

    /** The files shown at first and added by every "Show more" (parameter `show`, at most MAX_SHOW). */
    public const int SHOW_STEP = 40;
    public const int MAX_SHOW = 1000;
    /** Days that count as "Recently uploaded". */
    public const int RECENT_DAYS = 7;
    /** The type chips: key from the URL => label (translated on output). */
    public const array TYPES = ['image' => 'Images', 'document' => 'Documents', 'video' => 'Video', 'audio' => 'Audio'];

    /** @var array<int, list<string>>|null the places outside the news usage table, computed once per request (it reads the whole site) */
    private ?array $elsewhere = null;

    /** @return array<int, list<string>> */
    private function elsewhere(): array
    {
        return $this->elsewhere ??= self::findUsagesElsewhere($this->db);
    }

    protected function actionList(): Response
    {
        [$where, $params, $filter] = $this->filter(true);
        $total = (int) $this->db->value("SELECT COUNT(*) FROM {media} o WHERE {$where}", $params);
        $show = max(self::SHOW_STEP, min(self::MAX_SHOW, $this->request->getInt('show', self::SHOW_STEP)));
        $all = \Talea\Core\MediaFolders::all($this->db);
        $current = null;
        foreach ($all as $folder) {
            $current = $folder['folder_id'] === $filter['folder'] && $filter['level'] ? $folder : $current;
        }
        $cards = $filter['level'] ? \Talea\Core\MediaFolders::children($all, $current['folder_id'] ?? null) : [];
        $item = $this->idParam('item') ?: null;

        return $this->view('list', 'Media', [
            'images' => $this->load($where, $params, 0, $show),
            'total' => $total,
            'show' => $show,
            'limit' => \Talea\Core\Files::limitText(),
            'filter' => $filter,
            'folders' => $all,
            'current' => $current,
            'path' => $current === null ? [] : \Talea\Core\MediaFolders::path($all, $current['folder_id']),
            'cards' => $cards,
            'subfolders' => array_count_values(array_filter(array_column($all, 'parent_id'), fn ($v): bool => $v !== null)),
            'thumbnails' => \Talea\Core\MediaFolders::thumbnails($this->db, array_column($cards, 'folder_id')),
            'counts' => $this->counts(),
            'newsItem' => $filter['article'] > 0 ? $this->db->value('SELECT title FROM {news} WHERE news_id = ?', [$filter['article']]) : null,
            'item' => $item === null ? null : $this->db->one('SELECT o.* FROM {media} o WHERE o.media_id = ?', [$item]),
            'panel' => $item === null ? null : $this->panel($item, $where, $params),
        ]);
    }

    /**
     * What the details panel needs besides the row: the neighbours of the file in the list (by the same filter and order, in one
     * query that returns only the two ids), the places that use it and whether the person may change or delete it.
     *
     * @param list<int|string> $params
     * @return array{prev: ?string, next: ?string, used: list<string>, canEdit: bool}
     */
    private function panel(int $id, string $where, array $params): array
    {
        $order = str_replace('used_at', '(SELECT COUNT(*) FROM {media_usage} p2 WHERE p2.media_id = o.media_id)', self::SORT_ORDERS[$this->request->get('sort')][1] ?? self::SORT_ORDERS['newest'][1]);
        $near = $this->db->one(
            "SELECT prev_id, next_id FROM (SELECT o.media_id AS id, LAG(o.public_id) OVER (ORDER BY {$order}) AS prev_id, LEAD(o.public_id) OVER (ORDER BY {$order}) AS next_id
                 FROM {media} o WHERE {$where}) neighbours WHERE id = ?",
            [...$params, $id],
        );
        $news = $this->db->all('SELECT n.title FROM {media_usage} u JOIN {news} n ON n.news_id = u.news_id WHERE u.media_id = ? ORDER BY n.title', [$id]);

        return [
            'prev' => $near['prev_id'] ?? null, 'next' => $near['next_id'] ?? null, 'canEdit' => $this->canEdit($id),
            'used' => [...array_map(fn (array $n): string => t('news item') . ' ' . $n['title'], $news), ...($this->elsewhere()[$id] ?? [])],
        ];
    }

    /**
     * Where a save returns to: the state of the list the panel was opened from (the hidden field `list`, a query string of the
     * known list parameters) with the file still open.
     *
     * @return array<string, string>
     */
    private function listReturn(string $itemId): array
    {
        parse_str($this->request->post('list'), $list);
        $known = array_intersect_key($list, array_flip(['folder', 'type', 'recent', 'missing', 'unused', 'waiting', 'article', 'search', 'sort', 'view', 'show']));

        return array_filter(array_map(fn ($v): string => is_string($v) ? $v : '', $known), fn (string $v): bool => $v !== '') + ['item' => $itemId];
    }

    /** The level a folder card's Rename or Move came from (hidden field `level`; empty = the top level), or null when the form does not say. */
    private function levelReturn(): ?array
    {
        if (!$this->request->hasPost('level')) {
            return null;
        }

        return \Talea\Core\Uuid::valid($this->request->post('level')) ? ['folder' => $this->request->post('level')] : [];
    }

    /**
     * The folder a form field names: null = no folder (the field is empty: the top level), false = it names a folder that does not
     * exist (a stale page, a mistyped id) - which must not pass for the top level.
     */
    private function targetFolder(string $name): int|null|false
    {
        $id = $this->idParam($name, 'media_folders');
        if ($id > 0) {
            return $id;
        }
        $given = $this->request->post($name) !== '' ? $this->request->post($name) : $this->request->get($name);

        return $given === '' || $given === '0' ? null : false;
    }

    /** @return array{total: int, recent: int, missing: int, waiting: int, unused: int} the numbers of the left menu */
    private function counts(): array
    {
        $row = $this->db->one(
            'SELECT COUNT(*) AS total, SUM(CASE WHEN created_at >= NOW() - INTERVAL ' . self::RECENT_DAYS . ' DAY THEN 1 ELSE 0 END) AS recent,
                    SUM(CASE WHEN thumb_path <> \'\' AND name = \'\' THEN 1 ELSE 0 END) AS missing, SUM(CASE WHEN staged_at IS NOT NULL THEN 1 ELSE 0 END) AS waiting
             FROM {media}',
        ) ?? [];

        return [
            'total' => (int) ($row['total'] ?? 0), 'recent' => (int) ($row['recent'] ?? 0), 'missing' => (int) ($row['missing'] ?? 0), 'waiting' => (int) ($row['waiting'] ?? 0),
            'unused' => (int) $this->db->value('SELECT COUNT(*) FROM {media} o WHERE ' . $this->unusedCondition()),
        ];
    }

    /** Not used by a news item and not by any place the usage finder knows. */
    private function unusedCondition(): string
    {
        $elsewhere = array_keys($this->elsewhere());

        return 'NOT EXISTS (SELECT 1 FROM {media_usage} p WHERE p.media_id = o.media_id)'
            . ($elsewhere === [] ? '' : ' AND o.media_id NOT IN (' . implode(',', array_map(intval(...), $elsewhere)) . ')');
    }

    /**
     * Waiting for approval → Publish file (#150, Core\MediaStaging): a file a Claude connection limited to drafts uploaded moves to
     * media/ and is public from now on. Only whoever may publish.
     */
    protected function actionApprove(): Response
    {
        $file = $this->request->isPost() && $this->app->auth()->canPublish()
            ? $this->db->one('SELECT * FROM {media} WHERE media_id = ? AND staged_at IS NOT NULL', [$this->idParam('media_id')]) : null;
        if ($file === null) {
            return $this->back('', '', ['waiting' => 1]);
        }
        if (!\Talea\Core\MediaStaging::promote($this->db, $file)) {
            return $this->back(t('The file could not be moved to the media folder – check the write permissions of media/.'), '', ['waiting' => 1], 'error');
        }

        return $this->back(t('The file is public: %s', $file['image_path']), '', ['waiting' => 1]);
    }

    /** JSON list for the image picker dialog in the editor; the same filters as in the list. */
    protected function actionListing(): Response
    {
        [$where, $params] = $this->filter(false);

        return Response::json([
            'images' => array_map($this->toJson(...), $this->load($where, $params, (max(1, $this->request->getInt('page', 1)) - 1) * 60, 60)),
            'folders' => array_map(fn (array $s): array => ['id' => $s['public_id'], 'name' => $s['name']], \Talea\Core\MediaFolders::all($this->db)),
        ]);
    }

    /** Creating a folder (optionally inside the folder `parent`) or renaming one; a rename leaves its place in the tree. */
    protected function actionFolder(): Response
    {
        $name = mb_substr($this->request->post('name'), 0, 100);
        if (!$this->request->isPost() || $name === '') {
            return $this->back();
        }
        $ids = $this->idParam('folder_id', 'media_folders');
        if ($ids > 0) {
            $this->db->update('media_folders', ['name' => $name], ['folder_id' => $ids]);
        } else {
            $ids = $this->db->insert('media_folders', ['name' => $name, 'parent_id' => $this->idParam('parent', 'media_folders') ?: null]);
        }

        return $this->back('Folder saved.', '', $this->levelReturn() ?? ['folder' => $this->publicId($ids, 'media_folders')]);
    }

    /** Moving a folder into another one (`parent`; empty = top level). Editors and administrators; refuses a cycle. */
    protected function actionFolderMove(): Response
    {
        if (!$this->request->isPost()) {
            return $this->back();
        }
        if (!$this->app->auth()->canPublish()) { // a folder moves with everything in it: editors and administrators
            return $this->back(t('Moving a folder is for editors and administrators.'), '', [], 'error');
        }
        $id = $this->idParam('folder_id', 'media_folders');
        try {
            \Talea\Core\MediaFolders::move($this->db, $id, $this->idParam('parent', 'media_folders') ?: null);
        } catch (\DomainException $e) {
            return $this->back(t('The folder cannot be moved there.'), '', [], 'error');
        }

        return $this->back('Folder moved.', '', $this->levelReturn() ?? ['folder' => $this->publicId($id, 'media_folders')]);
    }

    /** Deleting a folder; its files and subfolders move up one level. */
    protected function actionFolderDelete(): Response
    {
        if ($this->request->isPost() && $this->app->auth()->isAdmin()) {
            \Talea\Core\MediaFolders::delete($this->db, $this->idParam('folder_id', 'media_folders'));
        }

        return $this->back('Folder deleted, its files and subfolders moved up one level.');
    }

    /**
     * Recounts which images a news item uses: the main image, images inserted by the editor
     * (data-id, file URL). Called when a news item is saved.
     */
    public static function recordUsage(\Talea\Core\Db $db, int $idc, string ...$html): void
    {
        $all = implode(' ', $html);
        preg_match_all('/data-id="(\d+|[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})"/', $all, $m);
        // the editor puts the public id of the image there; the number is what older texts carry
        $ids = array_map(fn (string $id): int => ctype_digit($id) ? (int) $id : $db->internalId('media', $id), $m[1]);
        preg_match_all('#media/' . \Talea\Core\MediaFolder::OWNER . '\d{4}/\d{2}/[A-Za-z0-9._-]+\.[a-z0-9]{2,5}#', $all, $paths); // images and attachments (PDF, documents…)
        foreach (array_unique($paths[0]) as $path) {
            $ido = $db->value('SELECT media_id FROM {media} WHERE image_path = ? OR thumb_path = ?', [$path, $path]);
            if ($ido !== null) {
                $ids[] = (int) $ido;
            }
        }
        $db->delete('media_usage', ['news_id' => $idc]);
        foreach (array_unique($ids) as $ido) {
            $db->insertIgnoreSelect('media_usage', ['media_id', 'news_id'], 'SELECT media_id, ? FROM {media} WHERE media_id = ?', [$idc, $ido]);
        }
    }

    /** @return list<array{string, string}> kind and SQL (used_in, content) of every place outside the news usage table that can hold a media path; $news adds the news items (another site's usage table) */
    private static function usageSources(\Talea\Core\Db $db, bool $news): array
    {
        $sources = [
            [t('page'), 'SELECT title AS used_in, CONCAT_WS(\' \', text, build, build_draft, image) AS content FROM {pages}'],
            [t('tag'), 'SELECT name AS used_in, CONCAT_WS(\' \', description, image) AS content FROM {tags}'],
            [t('category'), 'SELECT name AS used_in, description AS content FROM {categories}'],
            [t('my section'), 'SELECT name AS used_in, element AS content FROM {sections}'],
            [t('username'), 'SELECT username AS used_in, photo AS content FROM {users}'],
            [t('site part'), 'SELECT CONCAT(type, CASE WHEN name = \'\' THEN \'\' ELSE CONCAT(\' – \', name) END) AS used_in, CONCAT_WS(\' \', build, build_draft) AS content FROM {site_parts}'],
            [t('collection'), 'SELECT name AS used_in, CONCAT_WS(\' \', build, build_draft) AS content FROM {collections}'],
            [t('collection item'), "SELECT name AS used_in, CONCAT(data, ' ', image) AS content FROM {collection_items}"],
            [t('component'), 'SELECT name AS used_in, CONCAT_WS(\' \', build, build_draft) AS content FROM {components} WHERE ' . Sites::visible($db)],
            [t('class'), 'SELECT name AS used_in, CONCAT_WS(\' \', style, css) AS content FROM {classes}'],
            [t('settings'), 'SELECT name AS used_in, value AS content FROM {settings} WHERE value LIKE \'%media%\''],
            // 2.14: pop-up builds and newsletters (the rendered e-mail is kept from the start of sending) point at media too
            [t('pop-up'), 'SELECT name AS used_in, CONCAT_WS(\' \', build, build_draft) AS content FROM {popups}'],
            [t('newsletter'), 'SELECT subject AS used_in, CONCAT_WS(\' \', intro, button_url, html) AS content FROM {newsletters}'],
            [t('menu'), 'SELECT location AS used_in, items AS content FROM {menus}'], // an SVG from Media as a menu icon
        ];
        if ($news) {
            $sources[] = [t('news item'), 'SELECT n.title AS used_in, o.image_path AS content FROM {media_usage} u JOIN {news} n ON n.news_id = u.news_id JOIN {media} o ON o.media_id = u.media_id'];
        }

        return $sources;
    }

    /**
     * Media used outside the news usage table: builds of pages, site parts, collection item templates and components (drafts
     * too), text pages, collection items, classes (background image) and settings (logo, icon, sharing image).
     * Computed when shown – so the overview is always up to date without tracking on every save.
     *
     * @return array<int, list<string>> media_id => descriptions of the places
     */
    public static function findUsagesElsewhere(\Talea\Core\Db $db): array
    {
        // the media are shared by every site of the owner: a file used on any ready site is in use (the news usage of the others too)
        $dbs = [['', $db]];
        foreach (\Talea\Core\Sites::otherReady($db) as ['name' => $name, 'db' => $other]) {
            $dbs[] = [' (' . $name . ')', $other];
        }
        $usages = [];
        foreach ($dbs as [$suffix, $d]) {
            foreach (self::usageSources($d, $suffix !== '') as [$kind, $sql]) {
                foreach ($d->all($sql) as $r) {
                    // paths also in JSON (media\/2026\/…), with and without the site URL; a variant (-1200, .webp) counts as the original
                    foreach (\Talea\Core\MediaHygiene::paths((string) $r['content']) as $path) {
                        $usages[$path][$kind . ' ' . $r['used_in'] . $suffix] = true;
                    }
                }
            }
        }
        if ($usages === []) {
            return [];
        }
        $used = [];
        foreach ($db->all('SELECT media_id, image_path, thumb_path FROM {media}') as $o) {
            foreach ([$o['image_path'], $o['thumb_path']] as $path) {
                if ($path !== '' && isset($usages[$path])) {
                    $used[(int) $o['media_id']] = array_keys(($used[(int) $o['media_id']] ?? []) + $usages[$path]);
                }
            }
        }

        return $used;
    }

    /** Upload of one or more files; with the parameter format=json it answers the editor with JSON. */
    protected function actionUpload(): Response
    {
        $json = $this->request->get('format') === 'json';
        $section = $this->idParam('folder_id', 'media_folders') ?: null;
        $uploaded = [];
        $errors = [];
        $imageCount = 0;
        foreach (self::uploadedFiles() as $file) {
            try {
                $data = self::store($this->app, $file, $section);
                $imageCount += $data['thumb_path'] !== '' ? 1 : 0;
                $uploaded[] = $this->toJson($data + ['description' => '']);
            } catch (\RuntimeException $e) {
                $errors[] = ($file['name'] ?? t('file')) . ': ' . t($e->getMessage());
            }
        }
        if ($uploaded === [] && $errors === []) {
            $errors[] = t('No file was selected.');
        }
        if ($json) {
            return Response::json(['images' => $uploaded, 'errors' => $errors], $uploaded === [] ? 400 : 200);
        }
        foreach ($errors as $error) {
            $this->app->session->flash('error', $error);
        }

        $message = $uploaded === [] ? '' : t('Files uploaded: %d.', count($uploaded)) . ($imageCount > 0 ? ' ' . t('Add a description for blind visitors (alt text) to the images: what the image shows.') : '');

        return $this->back($message, '', $section !== null ? ['folder' => $this->publicId($section, 'media_folders')] : []);
    }

    /**
     * One uploaded file into Media – the same path for every upload in the administration (the Media screen, the editor,
     * the attachments of a request to Claude, 2.15): an image resized with variants, an SVG cleaned, an attachment (PDF,
     * documents…) as it is. Returns the media row with its new id.
     *
     * @param array<string, mixed> $file item of $_FILES (uploadedFiles)
     * @return array<string, mixed>
     * @throws \RuntimeException with the reason (an admin text to translate with t())
     */
    public static function store(\Talea\Core\App $app, array $file, ?int $section = null): array
    {
        $attachment = \Talea\Core\Files::isAttachment((string) ($file['name'] ?? ''));
        $data = match (true) {
            strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION)) === 'svg' => self::saveSvg($file),
            $attachment => \Talea\Core\Files::save($file),
            default => Images::save($file),
        };
        if (!$attachment) {
            // the image name is also the description for the blind (alt): a file name ("IMG 2041", "foto dilna") does not describe the image
            // and the checks would take it as filled in – it stays empty and the list and the pre-publish check ask for it
            $data['name'] = '';
        }
        $data['media_id'] = $app->db()->insert('media', $data + ['owner_id' => $app->auth()->id(), 'folder_id' => $section, 'created_at' => date('Y-m-d H:i:s')]);

        return $data;
    }

    /**
     * SVG (logo, icon): sanitized to allowed tags and attributes; without a thumbnail and variants, the browser scales it itself.
     *
     * @param array<string, mixed> $file
     * @return array<string, mixed>
     */
    private static function saveSvg(array $file): array
    {
        $tmp = (string) ($file['tmp_name'] ?? '');
        if (!is_uploaded_file($tmp)) {
            throw new \RuntimeException('The SVG file could not be read (max. 2 MB, valid SVG).');
        }

        return self::saveSvgContent((string) file_get_contents($tmp), (string) ($file['name'] ?? 'image'));
    }

    /**
     * SVG from text (upload and MCP): cleaned of scripts and outbound links (Core\Svg) and saved under a new name. $staged: it
     * waits for approval outside the web root (Core\MediaStaging); the path returned is the public one.
     *
     * @return array<string, mixed> row for the media table
     */
    public static function saveSvgContent(string $content, string $displayName, bool $staged = false): array
    {
        $svg = strlen($content) < 2_000_000 ? \Talea\Core\Svg::sanitize($content) : null;
        if ($svg === null) {
            throw new \RuntimeException('The SVG file could not be read (max. 2 MB, valid SVG).');
        }
        $root = \Talea\Core\MediaStaging::root($staged);
        $folder = \Talea\Core\MediaFolder::dir() . '/' . date('Y/m');
        if (!is_dir($root . '/' . $folder)) {
            mkdir($root . '/' . $folder, 0775, true);
        }
        $name = pathinfo($displayName, PATHINFO_FILENAME);
        $path = $folder . '/' . slugify($name, 60) . '-' . bin2hex(random_bytes(3)) . '.svg';
        file_put_contents($root . '/' . $path, $svg);
        [$w, $h] = \Talea\Core\Svg::dimensions($svg);

        return ['image_path' => $path, 'image_width' => min(65535, $w), 'image_height' => min(65535, $h), 'image_size' => strlen($svg),
            'thumb_path' => $path, 'thumb_width' => min(65535, $w), 'thumb_height' => min(65535, $h), 'name' => mb_substr(str_replace(['_', '-'], ' ', $name), 0, 150)];
    }

    /** A new file in place of the old one with the same URL: links on the site stay and show the new version. */
    protected function actionReplace(): Response
    {
        $ido = $this->idParam('media_id');
        // a file waiting for approval is not replaced: the new one would land in media/ before anyone approved it (#150)
        $image = $this->request->isPost() && $this->canEdit($ido) ? $this->db->one('SELECT * FROM {media} WHERE media_id = ? AND staged_at IS NULL', [$ido]) : null;
        $file = $_FILES['file'] ?? null;
        if ($image === null || !is_array($file)) {
            return $this->back();
        }
        try {
            $new = Images::replace($image['image_path'], $file);
        } catch (\RuntimeException $e) {
            return $this->back(t($e->getMessage()), 'list', $this->listReturn($image['public_id']), 'error');
        }
        $this->db->update('media', $new + ['color' => ''], ['media_id' => $ido]);
        \Talea\Front\Cache::clear();

        return $this->back('The file has been replaced – the new version is shown everywhere it is used.', 'list', $this->listReturn($image['public_id']));
    }

    protected function actionSave(): Response
    {
        $id = $this->idParam('media_id');
        if ($this->request->isPost() && $this->canEdit($id)) {
            $folder = $this->request->hasPost('folder_id') ? $this->targetFolder('folder_id') : null;
            if ($folder === false) {
                return $this->back(t('The target folder does not exist.'), '', $this->listReturn($this->publicId($id)), 'error');
            }
            $x = max(0, min(100, $this->request->postInt('focus_x', 50)));
            $y = max(0, min(100, $this->request->postInt('focus_y', 50)));
            $this->db->update('media', [
                'name' => mb_substr($this->request->post('name'), 0, 150),
                'description' => mb_substr($this->request->post('description'), 0, 500),
                'author' => mb_substr(trim($this->request->post('author')), 0, 120),
                'focal_point' => $x === 50 && $y === 50 ? '' : $x . '% ' . $y . '%',
            ] + ($this->request->hasPost('folder_id') ? ['folder_id' => $folder] : []), ['media_id' => $id]);
            \Talea\Front\Cache::clear();
        }

        return $this->back('Image description saved.', '', $id > 0 ? $this->listReturn($this->publicId($id)) : []);
    }

    /** Description for the blind (alt = field "nazev", as in the detail and in the editor) directly from the grid – without reloading (image/admin.js). */
    protected function actionSaveCaption(): Response
    {
        $ido = $this->idParam('media_id');
        if (!$this->request->isPost() || !$this->canEdit($ido)) {
            return Response::json(['ok' => false, 'error' => t('You cannot edit this image.')], 403);
        }
        $this->db->update('media', ['name' => mb_substr(trim($this->request->post('name')), 0, 150)], ['media_id' => $ido]);
        \Talea\Front\Cache::clear();

        return Response::json(['ok' => true]);
    }

    /**
     * Clean-up (2.14, Core\MediaHygiene): unused files, oversized images, duplicates and images without a description – the
     * site proposes, the user deletes, shrinks or describes. Media has no trash, so deleting asks for a confirmation.
     */
    protected function actionCleanup(): Response
    {
        $report = \Talea\Core\MediaHygiene::report($this->db);
        $canEdit = fn (array $o): bool => $this->app->auth()->isAdmin() || (int) $o['owner_id'] === $this->app->auth()->id();

        return $this->view('cleanup', 'Media clean-up', $report + [
            'withoutAlt' => array_slice(array_values(array_filter($report['without_alt'], $canEdit)), 0, self::ALT_BATCH),
            'withoutAltTotal' => count($report['without_alt']),
            'canShrink' => extension_loaded('gd'),
            'canEdit' => $canEdit,
        ]);
    }

    /** How many images without alt the clean-up form shows at once. */
    public const int ALT_BATCH = 100;

    /** Descriptions for blind visitors (alt) of several images saved together (Media → Clean-up). */
    protected function actionSaveAlts(): Response
    {
        if (!$this->request->isPost()) {
            return $this->back('', 'cleanup');
        }
        $saved = 0;
        foreach (is_array($_POST['alt'] ?? null) ? $_POST['alt'] : [] as $uuid => $alt) {
            $ido = $this->db->internalId('media', $uuid);
            $alt = mb_substr(trim((string) $alt), 0, 150);
            if ($alt === '' || !$this->canEdit($ido)) {
                continue;
            }
            $saved += $this->db->update('media', ['name' => $alt], ['media_id' => $ido, 'name' => '']);
        }
        if ($saved > 0) {
            \Talea\Front\Cache::clear();
            \Talea\Admin\ChangeLog::write($this->app, 'media', 'alt texts in bulk', (string) $saved);
        }

        return $this->back(t('Image descriptions saved: %d.', $saved), 'cleanup');
    }

    /** An oversized image re-encoded to the usual size in place (Core\Images::shrinkFile) – its address and every use stay. */
    protected function actionShrink(): Response
    {
        $ido = $this->idParam('media_id');
        $image = $this->request->isPost() && $this->canEdit($ido) ? $this->db->one('SELECT * FROM {media} WHERE media_id = ?', [$ido]) : null;
        if ($image === null) {
            return $this->back('', 'cleanup');
        }
        try {
            $new = Images::shrinkFile($image['image_path']);
        } catch (\RuntimeException $e) {
            return $this->back(t($e->getMessage()), 'cleanup', [], 'error');
        }
        $this->db->update('media', $new + ['color' => ''], ['media_id' => $ido]);
        \Talea\Front\Cache::clear();
        \Talea\Admin\ChangeLog::write($this->app, 'media', 'made smaller', $image['image_path']);

        return $this->back(t('The image is now %s px wide and takes %s.', (string) $new['image_width'], \Talea\Core\Files::size($new['image_size'])), 'cleanup');
    }

    /** Bulk action on the selected images: delete, or move to a folder. From the clean-up (zpet=cleanup) it returns there. */
    protected function actionBulk(): Response
    {
        if (!$this->request->isPost()) {
            return $this->back();
        }
        $backTo = $this->request->post('back') === 'cleanup' ? 'cleanup' : '';
        $action = $this->request->post('bulk');
        if ($action !== 'move' && $action !== 'delete') {
            return $this->back(); // an unknown action must never fall through to deleting
        }
        $move = $action === 'move';
        $target = $this->idParam('to_folder', 'media_folders') ?: null;
        $count = 0;
        $skipped = 0;
        $elsewhere = $move ? [] : self::findUsagesElsewhere($this->db);
        foreach ($this->request->postList('selected') as $id) {
            $image = $this->db->one('SELECT * FROM {media} WHERE media_id = ?', [$this->db->internalId('media', $id)]);
            if ($image === null || !$this->canEdit((int) $image['media_id'])) {
                continue;
            }
            if ($move) {
                $count += $this->db->update('media', ['folder_id' => $target], ['media_id' => $image['media_id']]) >= 0 ? 1 : 0;
            } elseif (isset($elsewhere[(int) $image['media_id']]) || $this->db->value('SELECT 1 FROM {media_usage} WHERE media_id = ? LIMIT 1', [$image['media_id']]) !== null) {
                $skipped++; // a used file would disappear from the site – it is deleted once it is not used anywhere
            } else {
                Images::delete($image['image_path'], $image['thumb_path']);
                \Talea\Core\Files::delete($image['image_path']);
                $count += $this->db->delete('media', ['media_id' => $image['media_id']]);
            }
        }

        if ($skipped > 0) {
            $this->app->session->flash('error', t('%d files in use were not deleted – remove them from the site first (the list shows where they are used).', $skipped));
        }
        if (!$move && $count > 0) {
            \Talea\Admin\ChangeLog::write($this->app, 'media', 'deleted', (string) $count);
        }

        return $this->back($move ? t('Images moved: %d.', $count) : t('Images deleted: %d.', $count), $backTo, $move && $target ? ['folder' => $this->publicId($target, 'media_folders')] : []);
    }

    private function canEdit(int $ido): bool
    {
        $owner = $this->db->value('SELECT owner_id FROM {media} WHERE media_id = ?', [$ido]);

        return $this->app->auth()->isAdmin() || (int) $owner === $this->app->auth()->id();
    }

    /**
     * List filter from the URL: folder (public id, 0 = not in a folder), type (image | document | video | audio), recent=1, missing=1
     * (images without a description), unused=1, waiting=1 (waiting for approval, Core\MediaStaging), article, search (name,
     * description or file name), sort.
     * $levels: the Media screen shows one level of the library (no folder = the top level) unless one of the views that cut across
     * folders (recent, missing, unused, waiting, article, search) is on; the picker's JSON list shows everything unless a folder is named.
     *
     * @return array{0: string, 1: list<int|string>, 2: array{folder: ?int, folder_public: ?string, level: bool, article: int, article_public: ?string, unused: bool, waiting: bool, recent: bool, missing: bool, type: string, search: string, sort: string, view: string}}
     */
    private function filter(bool $levels): array
    {
        $where = ['1 = 1'];
        $params = [];
        $folderParam = $this->request->get('folder');
        $folder = $folderParam === '0' ? 0 : ($this->idParam('folder', 'media_folders') ?: null);
        $newsItem = $this->idParam('article', 'news');
        $search = mb_substr(trim($this->request->get('search')), 0, 100);
        $unused = $this->request->get('unused') === '1';
        $waiting = $this->request->get('waiting') === '1';
        $recent = $this->request->get('recent') === '1';
        $missing = $this->request->get('missing') === '1';
        $across = $newsItem > 0 || $search !== '' || $unused || $waiting || $recent || $missing;
        $level = $levels ? !$across : $folder !== null;
        if ($level) {
            $where[] = ($folder ?? 0) > 0 ? 'o.folder_id = ?' : 'o.folder_id IS NULL';
            if (($folder ?? 0) > 0) {
                $params[] = $folder;
            }
        }
        if ($newsItem > 0) {
            $where[] = 'EXISTS (SELECT 1 FROM {media_usage} p WHERE p.media_id = o.media_id AND p.news_id = ?)';
            $params[] = $newsItem;
        }
        if ($search !== '') {
            $where[] = '(' . implode(' OR ', array_map(fn (string $c): string => $this->db->dialect()->likeInsensitive($c), ['o.name', 'o.description', 'o.image_path'])) . ')';
            $pattern = '%' . addcslashes($search, '%_\\') . '%';
            array_push($params, $pattern, $pattern, $pattern);
        }
        if ($unused) {
            $where[] = $this->unusedCondition();
        }
        if ($waiting) {
            $where[] = 'o.staged_at IS NOT NULL';
        }
        if ($recent) {
            $where[] = 'o.created_at >= NOW() - INTERVAL ' . self::RECENT_DAYS . ' DAY';
        }
        if ($missing) {
            $where[] = "o.thumb_path <> '' AND o.name = ''";
        }
        $type = isset(self::TYPES[$this->request->get('type')]) ? $this->request->get('type') : '';
        if ($type !== '') {
            $where[] = $this->typeCondition($type, $params);
        }
        $sort = isset(self::SORT_ORDERS[$this->request->get('sort')]) ? $this->request->get('sort') : 'newest';
        // "Missing description" is a to-do list: it opens as a table with the description fields
        $view = in_array($this->request->get('view'), ['grid', 'table'], true) ? $this->request->get('view') : ($missing ? 'table' : 'grid');

        return [implode(' AND ', $where), $params, ['folder' => $folder, 'folder_public' => $folder === null ? null : ($folder === 0 ? '0' : $folderParam), 'level' => $level, 'article' => $newsItem,
            'article_public' => $newsItem > 0 ? $this->publicId($newsItem, 'news') : null, 'unused' => $unused, 'waiting' => $waiting, 'recent' => $recent, 'missing' => $missing,
            'type' => $type, 'search' => $search, 'sort' => $sort, 'view' => $view]];
    }

    /**
     * The SQL of a type chip: images have a thumbnail, the other kinds are attachments told apart by their extension.
     *
     * @param list<int|string> $params
     */
    private function typeCondition(string $type, array &$params): string
    {
        $like = function (array $extensions) use (&$params): string {
            return '(' . implode(' OR ', array_map(function (string $extension) use (&$params): string {
                $params[] = '%.' . $extension;

                return $this->db->dialect()->likeInsensitive('o.image_path');
            }, $extensions)) . ')';
        };
        $sound = [...\Talea\Core\Files::VIDEO_EXTENSIONS, ...\Talea\Core\Files::AUDIO_EXTENSIONS];

        return match ($type) {
            'image' => "o.thumb_path <> ''",
            'video' => "o.thumb_path = '' AND " . $like(\Talea\Core\Files::VIDEO_EXTENSIONS),
            'audio' => "o.thumb_path = '' AND " . $like(\Talea\Core\Files::AUDIO_EXTENSIONS),
            default => "o.thumb_path = '' AND NOT " . $like($sound),
        };
    }

    /** List sort orders: key from the URL => [label, ORDER BY]; each ends in the unique media_id so that the details panel's neighbours (LAG/LEAD) match the list. */
    public const array SORT_ORDERS = [
        'newest' => ['Newest first', 'o.media_id DESC'], 'oldest' => ['Oldest first', 'o.media_id ASC'], 'name' => ['By name', 'o.name ASC, o.media_id DESC'],
        'size' => ['Largest files first', 'o.image_size DESC, o.media_id DESC'], 'unused' => ['Least used first', 'used_at ASC, o.media_id DESC'],
    ];

    /** @return list<array<string, mixed>> */
    private function load(string $where, array $params, int $offset, int $count): array
    {
        $order = self::SORT_ORDERS[$this->request->get('sort')][1] ?? self::SORT_ORDERS['newest'][1];
        $elsewhere = $this->elsewhere();

        return array_map(function (array $o) use ($elsewhere): array {
            // where: news by the usage table + places outside news
            $o['used_in'] = $elsewhere[(int) $o['media_id']] ?? [];
            $o['used_at'] = (int) $o['used_at'] + count($o['used_in']);

            return $o;
        }, $this->db->all(
            "SELECT o.*, (SELECT COUNT(*) FROM {media_usage} p WHERE p.media_id = o.media_id) AS used_at
             FROM {media} o WHERE {$where} ORDER BY {$order} LIMIT ? OFFSET ?",
            [...$params, $count, $offset],
        ));
    }

    /** @param array<string, mixed> $o */
    private function toJson(array $o): array
    {
        return [
            'id' => $o['public_id'] ?? $this->publicId((int) $o['media_id']), 'name' => $o['name'], 'description' => $o['description'] ?? '',
            'url' => $this->app->url($o['image_path']), 'thumbnail' => $o['thumb_path'] === '' ? '' : $this->app->url($o['thumb_path']),
            'width' => (int) $o['image_width'], 'height' => (int) $o['image_height'],
            // attachment for download (PDF, document, audio…): without a thumbnail, inserted into the text as a link
            'file' => $o['thumb_path'] === '', 'extension' => strtoupper(pathinfo($o['image_path'], PATHINFO_EXTENSION)), 'size' => \Talea\Core\Files::size((int) ($o['image_size'] ?? 0)),
        ];
    }

    /**
     * A file input of $_FILES (multiple too) converted to a list of individual files, at most $max of them.
     *
     * @return list<array<string, mixed>>
     */
    public static function uploadedFiles(string $field = 'files', int $max = 30): array
    {
        $f = $_FILES[$field] ?? null;
        if (!is_array($f)) {
            return [];
        }
        if (!is_array($f['name'])) {
            return $f['error'] === UPLOAD_ERR_NO_FILE ? [] : [$f];
        }
        $files = [];
        foreach (array_keys($f['name']) as $i) {
            if ($f['error'][$i] !== UPLOAD_ERR_NO_FILE) {
                $files[] = ['name' => $f['name'][$i], 'tmp_name' => $f['tmp_name'][$i], 'error' => $f['error'][$i], 'size' => $f['size'][$i]];
            }
        }

        return array_slice($files, 0, $max);
    }
}
