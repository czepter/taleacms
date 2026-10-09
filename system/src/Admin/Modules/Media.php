<?php

declare(strict_types=1);

namespace Kaleta\Admin\Modules;

use Kaleta\Admin\Module;
use Kaleta\Core\Images;
use Kaleta\Core\Response;

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

    /** Everyone who writes news must be able to upload; but only the administrator changes and deletes other people's images. */
    public const bool FOR_ALL_USERS = true;

    private const int PER_PAGE = 40;

    protected function actionList(): Response
    {
        $pageNumber = max(1, $this->request->getInt('page', 1));
        [$where, $params, $filter] = $this->filter();
        $total = (int) $this->db->value("SELECT COUNT(*) FROM {media} o WHERE {$where}", $params);

        return $this->view('list', 'Media', [
            'images' => $this->load($where, $params, $pageNumber, self::PER_PAGE),
            'pageNumber' => $pageNumber,
            'pageCount' => max(1, (int) ceil($total / self::PER_PAGE)),
            'total' => $total,
            'limit' => \Kaleta\Core\Files::limitText(),
            'filter' => $filter,
            'folders' => $this->folders(),
            'newsItem' => $filter['article'] > 0 ? $this->db->value('SELECT title FROM {news} WHERE news_id = ?', [$filter['article']]) : null,
        ]);
    }

    /** JSON list for the image picker dialog in the editor; the same filters as in the list. */
    protected function actionListing(): Response
    {
        [$where, $params] = $this->filter();

        return Response::json([
            'images' => array_map($this->toJson(...), $this->load($where, $params, max(1, $this->request->getInt('page', 1)), 60)),
            'slozky' => array_map(fn (array $s): array => ['id' => (int) $s['folder_id'], 'nazev' => $s['name']], $this->folders()),
        ]);
    }

    /** Creating or renaming a folder. */
    protected function actionFolder(): Response
    {
        $name = mb_substr($this->request->post('name'), 0, 100);
        if (!$this->request->isPost() || $name === '') {
            return $this->back();
        }
        $ids = $this->request->postInt('folder_id');
        if ($ids > 0) {
            $this->db->update('media_folders', ['name' => $name], ['folder_id' => $ids]);
        } else {
            $ids = $this->db->insert('media_folders', ['name' => $name]);
        }

        return $this->back('Folder saved.', '', ['section' => $ids]);
    }

    /** Deleting a folder; the images stay and move to the unsorted ones. */
    protected function actionFolderDelete(): Response
    {
        if ($this->request->isPost() && $this->app->auth()->isAdmin()) {
            $this->db->delete('media_folders', ['folder_id' => $this->request->postInt('folder_id')]);
        }

        return $this->back('Folder deleted, its images are now uncategorized.');
    }

    /**
     * Recounts which images a news item uses: the main image, images inserted by the editor
     * (data-id, file URL). Called when a news item is saved.
     */
    public static function recordUsage(\Kaleta\Core\Db $db, int $idc, string ...$html): void
    {
        $all = implode(' ', $html);
        preg_match_all('/data-id="(\d+)"/', $all, $m);
        $ids = array_map(intval(...), $m[1]);
        preg_match_all('#media/\d{4}/\d{2}/[A-Za-z0-9._-]+\.[a-z0-9]{2,5}#', $all, $paths); // images and attachments (PDF, documents…)
        foreach (array_unique($paths[0]) as $path) {
            $ido = $db->value('SELECT media_id FROM {media} WHERE image_path = ? OR thumb_path = ?', [$path, $path]);
            if ($ido !== null) {
                $ids[] = (int) $ido;
            }
        }
        $db->delete('media_usage', ['news_id' => $idc]);
        foreach (array_unique($ids) as $ido) {
            $db->run('INSERT IGNORE INTO {media_usage} (media_id, news_id) SELECT media_id, ? FROM {media} WHERE media_id = ?', [$idc, $ido]);
        }
    }

    /**
     * Media used outside the news usage table: builds of pages, site parts, collection item templates and components (drafts
     * too), text pages, collection items, classes (background image) and settings (logo, icon, sharing image).
     * Computed when shown – so the overview is always up to date without tracking on every save.
     *
     * @return array<int, list<string>> media_id => descriptions of the places
     */
    public static function findUsagesElsewhere(\Kaleta\Core\Db $db): array
    {
        $sources = [
            [t('page'), 'SELECT title AS kde, CONCAT_WS(\' \', text, build, build_draft, image) AS obsah FROM {pages}'],
            [t('tag'), 'SELECT name AS kde, CONCAT_WS(\' \', description, image) AS obsah FROM {tags}'],
            [t('category'), 'SELECT name AS kde, description AS obsah FROM {categories}'],
            [t('my section'), 'SELECT name AS kde, element AS obsah FROM {sections}'],
            [t('username'), 'SELECT username AS kde, photo AS obsah FROM {users}'],
            [t('site part'), 'SELECT CONCAT(type, IF(name = \'\', \'\', CONCAT(\' – \', name))) AS kde, CONCAT_WS(\' \', build, build_draft) AS obsah FROM {site_parts}'],
            [t('collection'), 'SELECT name AS kde, CONCAT_WS(\' \', build, build_draft) AS obsah FROM {collections}'],
            [t('collection item'), "SELECT name AS kde, CONCAT(data, ' ', image) AS obsah FROM {collection_items}"],
            [t('component'), 'SELECT name AS kde, CONCAT_WS(\' \', build, build_draft) AS obsah FROM {components}'],
            [t('class'), 'SELECT name AS kde, CONCAT_WS(\' \', style, css) AS obsah FROM {classes}'],
            [t('settings'), 'SELECT name AS kde, value AS obsah FROM {settings} WHERE value LIKE \'%media%\''],
            // 2.14: pop-up builds and newsletters (the rendered e-mail is kept from the start of sending) point at media too
            [t('pop-up'), 'SELECT name AS kde, CONCAT_WS(\' \', build, build_draft) AS obsah FROM {popups}'],
            [t('newsletter'), 'SELECT subject AS kde, CONCAT_WS(\' \', intro, button_url, html) AS obsah FROM {newsletters}'],
        ];
        $usages = [];
        foreach ($sources as [$kind, $sql]) {
            foreach ($db->all($sql) as $r) {
                // paths also in JSON (media\/2026\/…), with and without the site URL; a variant (-1200, .webp) counts as the original
                foreach (\Kaleta\Core\MediaHygiene::paths((string) $r['obsah']) as $path) {
                    $usages[$path][$kind . ' ' . $r['kde']] = true;
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
        $section = $this->db->value('SELECT folder_id FROM {media_folders} WHERE folder_id = ?', [$this->request->postInt('folder_id')]);
        $section = $section === null ? null : (int) $section;
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
            return Response::json(['images' => $uploaded, 'chyby' => $errors], $uploaded === [] ? 400 : 200);
        }
        foreach ($errors as $error) {
            $this->app->session->flash('error', $error);
        }

        $message = $uploaded === [] ? '' : t('Files uploaded: %d.', count($uploaded)) . ($imageCount > 0 ? ' ' . t('Add a description for blind visitors (alt text) to the images: what the image shows.') : '');

        return $this->back($message, '', $section !== null ? ['section' => $section] : []);
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
    public static function store(\Kaleta\Core\App $app, array $file, ?int $section = null): array
    {
        $attachment = \Kaleta\Core\Files::isAttachment((string) ($file['name'] ?? ''));
        $data = match (true) {
            strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION)) === 'svg' => self::saveSvg($file),
            $attachment => \Kaleta\Core\Files::save($file),
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
     * SVG from text (upload and MCP): cleaned of scripts and outbound links (Core\Svg) and saved under a new name.
     *
     * @return array<string, mixed> row for the media table
     */
    public static function saveSvgContent(string $content, string $displayName): array
    {
        $svg = strlen($content) < 2_000_000 ? \Kaleta\Core\Svg::sanitize($content) : null;
        if ($svg === null) {
            throw new \RuntimeException('The SVG file could not be read (max. 2 MB, valid SVG).');
        }
        $folder = 'media/' . date('Y/m');
        if (!is_dir(KALETA_ROOT . '/' . $folder)) {
            mkdir(KALETA_ROOT . '/' . $folder, 0775, true);
        }
        $name = pathinfo($displayName, PATHINFO_FILENAME);
        $path = $folder . '/' . slugify($name, 60) . '-' . bin2hex(random_bytes(3)) . '.svg';
        file_put_contents(KALETA_ROOT . '/' . $path, $svg);
        [$w, $h] = \Kaleta\Core\Svg::dimensions($svg);

        return ['image_path' => $path, 'image_width' => min(65535, $w), 'image_height' => min(65535, $h), 'image_size' => strlen($svg),
            'thumb_path' => $path, 'thumb_width' => min(65535, $w), 'thumb_height' => min(65535, $h), 'name' => mb_substr(str_replace(['_', '-'], ' ', $name), 0, 150)];
    }

    /** A new file in place of the old one with the same URL: links on the site stay and show the new version. */
    protected function actionReplace(): Response
    {
        $ido = $this->request->postInt('media_id');
        $image = $this->request->isPost() && $this->canEdit($ido) ? $this->db->one('SELECT * FROM {media} WHERE media_id = ?', [$ido]) : null;
        $file = $_FILES['file'] ?? null;
        if ($image === null || !is_array($file)) {
            return $this->back();
        }
        try {
            $new = Images::replace($image['image_path'], $file);
        } catch (\RuntimeException $e) {
            return $this->back(t($e->getMessage()), 'list', ['edit' => $ido], 'error');
        }
        $this->db->update('media', $new + ['color' => ''], ['media_id' => $ido]);
        \Kaleta\Front\Cache::clear();

        return $this->back('The file has been replaced – the new version is shown everywhere it is used.', 'list', ['edit' => $ido]);
    }

    protected function actionSave(): Response
    {
        if ($this->request->isPost() && $this->canEdit($this->request->postInt('media_id'))) {
            $x = max(0, min(100, $this->request->postInt('ohnisko_x', 50)));
            $y = max(0, min(100, $this->request->postInt('ohnisko_y', 50)));
            $this->db->update('media', [
                'name' => mb_substr($this->request->post('name'), 0, 150),
                'description' => mb_substr($this->request->post('description'), 0, 500),
                'author' => mb_substr(trim($this->request->post('author')), 0, 120),
                'focal_point' => $x === 50 && $y === 50 ? '' : $x . '% ' . $y . '%',
            ], ['media_id' => $this->request->postInt('media_id')]);
            \Kaleta\Front\Cache::clear();
        }

        return $this->back('Image description saved.');
    }

    /** Description for the blind (alt = field "nazev", as in the detail and in the editor) directly from the grid – without reloading (image/admin.js). */
    protected function actionSaveCaption(): Response
    {
        $ido = $this->request->postInt('media_id');
        if (!$this->request->isPost() || !$this->canEdit($ido)) {
            return Response::json(['ok' => false, 'error' => t('You cannot edit this image.')], 403);
        }
        $this->db->update('media', ['name' => mb_substr(trim($this->request->post('name')), 0, 150)], ['media_id' => $ido]);
        \Kaleta\Front\Cache::clear();

        return Response::json(['ok' => true]);
    }

    /**
     * Clean-up (2.14, Core\MediaHygiene): unused files, oversized images, duplicates and images without a description – the
     * site proposes, the user deletes, shrinks or describes. Media has no trash, so deleting asks for a confirmation.
     */
    protected function actionCleanup(): Response
    {
        $report = \Kaleta\Core\MediaHygiene::report($this->db);
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
        foreach (is_array($_POST['alt'] ?? null) ? $_POST['alt'] : [] as $ido => $alt) {
            $alt = mb_substr(trim((string) $alt), 0, 150);
            if ($alt === '' || !$this->canEdit((int) $ido)) {
                continue;
            }
            $saved += $this->db->update('media', ['name' => $alt], ['media_id' => (int) $ido, 'name' => '']);
        }
        if ($saved > 0) {
            \Kaleta\Front\Cache::clear();
            \Kaleta\Admin\ChangeLog::write($this->app, 'media', 'alt texts in bulk', (string) $saved);
        }

        return $this->back(t('Image descriptions saved: %d.', $saved), 'cleanup');
    }

    /** An oversized image re-encoded to the usual size in place (Core\Images::shrinkFile) – its address and every use stay. */
    protected function actionShrink(): Response
    {
        $ido = $this->request->postInt('media_id');
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
        \Kaleta\Front\Cache::clear();
        \Kaleta\Admin\ChangeLog::write($this->app, 'media', 'made smaller', $image['image_path']);

        return $this->back(t('The image is now %s px wide and takes %s.', (string) $new['image_width'], \Kaleta\Core\Files::size($new['image_size'])), 'cleanup');
    }

    /** Bulk action on the selected images: delete, or move to a folder. From the clean-up (zpet=cleanup) it returns there. */
    protected function actionBulk(): Response
    {
        if (!$this->request->isPost()) {
            return $this->back();
        }
        $backTo = $this->request->post('zpet') === 'cleanup' ? 'cleanup' : '';
        $move = $this->request->post('provest') === 'presun';
        $target = $this->request->postInt('do_sekce') ?: null;
        $count = 0;
        $skipped = 0;
        $elsewhere = $move ? [] : self::findUsagesElsewhere($this->db);
        foreach ($this->request->postList('oznacene') as $id) {
            $image = $this->db->one('SELECT * FROM {media} WHERE media_id = ?', [(int) $id]);
            if ($image === null || !$this->canEdit((int) $image['media_id'])) {
                continue;
            }
            if ($move) {
                $count += $this->db->update('media', ['folder_id' => $target], ['media_id' => $image['media_id']]) >= 0 ? 1 : 0;
            } elseif (isset($elsewhere[(int) $image['media_id']]) || $this->db->value('SELECT 1 FROM {media_usage} WHERE media_id = ? LIMIT 1', [$image['media_id']]) !== null) {
                $skipped++; // a used file would disappear from the site – it is deleted once it is not used anywhere
            } else {
                Images::delete($image['image_path'], $image['thumb_path']);
                \Kaleta\Core\Files::delete($image['image_path']);
                $count += $this->db->delete('media', ['media_id' => $image['media_id']]);
            }
        }

        if ($skipped > 0) {
            $this->app->session->flash('error', t('%d files in use were not deleted – remove them from the site first (the list shows where they are used).', $skipped));
        }
        if (!$move && $count > 0) {
            \Kaleta\Admin\ChangeLog::write($this->app, 'media', 'deleted', (string) $count);
        }

        return $this->back($move ? t('Images moved: %d.', $count) : t('Images deleted: %d.', $count), $backTo, $move && $target ? ['section' => $target] : []);
    }

    private function canEdit(int $ido): bool
    {
        $owner = $this->db->value('SELECT owner_id FROM {media} WHERE media_id = ?', [$ido]);

        return $this->app->auth()->isAdmin() || (int) $owner === $this->app->auth()->id();
    }

    /**
     * List filter from the URL: section (folder number, 0 = unsorted), article (idc), unused=1, search (name, label or file name).
     *
     * @return array{0: string, 1: list<int|string>, 2: array{sekce: ?int, clanek: int, nepouzite: bool, hledat: string, razeni: string}}
     */
    private function filter(): array
    {
        $where = ['1 = 1'];
        $params = [];
        $section = $this->request->get('section') === '' ? null : $this->request->getInt('section');
        if ($section !== null) {
            $where[] = $section > 0 ? 'o.folder_id = ?' : 'o.folder_id IS NULL';
            if ($section > 0) {
                $params[] = $section;
            }
        }
        $newsItem = $this->request->getInt('article');
        if ($newsItem > 0) {
            $where[] = 'EXISTS (SELECT 1 FROM {media_usage} p WHERE p.media_id = o.media_id AND p.news_id = ?)';
            $params[] = $newsItem;
        }
        $search = mb_substr(trim($this->request->get('search')), 0, 100);
        if ($search !== '') {
            $where[] = '(o.name LIKE ? OR o.description LIKE ? OR o.image_path LIKE ?)';
            $pattern = '%' . addcslashes($search, '%_\\') . '%';
            array_push($params, $pattern, $pattern, $pattern);
        }
        $unused = $this->request->get('unused') === '1';
        if ($unused) {
            $where[] = 'NOT EXISTS (SELECT 1 FROM {media_usage} p WHERE p.media_id = o.media_id)';
            $elsewhere = array_keys(self::findUsagesElsewhere($this->db));
            if ($elsewhere !== []) {
                $where[] = 'o.media_id NOT IN (' . implode(',', array_map(intval(...), $elsewhere)) . ')';
            }
        }

        $sort = isset(self::SORT_ORDERS[$this->request->get('sort')]) ? $this->request->get('sort') : 'nove';

        return [implode(' AND ', $where), $params, ['section' => $section, 'article' => $newsItem, 'unused' => $unused, 'search' => $search, 'sort' => $sort]];
    }

    /** @return list<array<string, mixed>> folders with the number of images */
    private function folders(): array
    {
        return $this->db->all('SELECT s.*, (SELECT COUNT(*) FROM {media} o WHERE o.folder_id = s.folder_id) AS pocet FROM {media_folders} s ORDER BY s.name');
    }

    /** @return list<array<string, mixed>> */
    /** List sort orders: key from the URL => [label, ORDER BY]. */
    public const array SORT_ORDERS = [
        'nove' => ['nejnovější', 'o.media_id DESC'], 'stare' => ['nejstarší', 'o.media_id ASC'], 'nazev' => ['by name', 'o.name ASC, o.media_id DESC'],
        'velikost' => ['largest files', 'o.image_size DESC'], 'nepouzite' => ['least used', 'used_at ASC, o.media_id DESC'],
    ];

    private function load(string $where, array $params, int $pageNumber, int $count): array
    {
        $order = self::SORT_ORDERS[$this->request->get('sort')][1] ?? self::SORT_ORDERS['nove'][1];
        $elsewhere = self::findUsagesElsewhere($this->db);

        return array_map(function (array $o) use ($elsewhere): array {
            // where: news by the usage table + places outside news
            $o['kde'] = $elsewhere[(int) $o['media_id']] ?? [];
            $o['used_at'] = (int) $o['used_at'] + count($o['kde']);

            return $o;
        }, $this->db->all(
            "SELECT o.*, (SELECT COUNT(*) FROM {media_usage} p WHERE p.media_id = o.media_id) AS used_at
             FROM {media} o WHERE {$where} ORDER BY {$order} LIMIT ? OFFSET ?",
            [...$params, $count, ($pageNumber - 1) * $count],
        ));
    }

    /** @param array<string, mixed> $o */
    private function toJson(array $o): array
    {
        return [
            'id' => (int) $o['media_id'], 'nazev' => $o['name'], 'popis' => $o['description'] ?? '',
            'url' => $this->app->url($o['image_path']), 'nahled' => $o['thumb_path'] === '' ? '' : $this->app->url($o['thumb_path']),
            'width' => (int) $o['image_width'], 'height' => (int) $o['image_height'],
            // attachment for download (PDF, document, audio…): without a thumbnail, inserted into the text as a link
            'file' => $o['thumb_path'] === '', 'pripona' => strtoupper(pathinfo($o['image_path'], PATHINFO_EXTENSION)), 'velikost' => \Kaleta\Core\Files::size((int) ($o['image_size'] ?? 0)),
        ];
    }

    /**
     * A file input of $_FILES (multiple too) converted to a list of individual files, at most $max of them.
     *
     * @return list<array<string, mixed>>
     */
    public static function uploadedFiles(string $field = 'soubory', int $max = 30): array
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
