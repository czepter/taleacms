<?php

declare(strict_types=1);

namespace Talea\Mcp\Handlers;

use Talea\Admin\Modules\Media;
use Talea\Admin\Modules\Categories;
use Talea\Admin\Modules\Pages;
use Talea\Core\App;
use Talea\Core\Language;
use Talea\Front\SiteIdentity;
use Talea\Builder\SiteParts;
use Talea\Builder\DesignSystem;
use Talea\Builder\Library;
use Talea\Builder\Collections;
use Talea\Builder\Publisher;
use Talea\Builder\Build;
use Talea\Builder\HtmlConverter;

/**
 * MCP tools: media (one method per tool, see Mcp\Catalog). Part of Mcp\Tools.
 *
 * @phpstan-ignore trait.unused
 */
trait MediaTools
{
    /** list_media */
    private function toolListMedia(string $name, array $a): mixed
    {
        $db = $this->app->db();

        $search = is_string($a['search'] ?? null) && trim($a['search']) !== '' ? '%' . addcslashes(trim($a['search']), '%_\\') . '%' : null;
        $where = ['1 = 1'];
        $params = [];
        if ($search !== null) {
            $where[] = '(' . $db->dialect()->likeInsensitive('name') . ' OR ' . $db->dialect()->likeInsensitive('image_path') . ')';
            array_push($params, $search, $search);
        }
        if ((int) ($a['folder'] ?? 0) > 0) {
            $where[] = 'folder_id = ?';
            $params[] = (int) $a['folder'];
        } elseif (($a['unsorted'] ?? false) === true) {
            $where[] = 'folder_id IS NULL';
        }
        $params[] = max(1, min(50, (int) ($a['limit'] ?? 20)));

        return array_map(fn (array $o): array => $this->medium($o), $db->all('SELECT * FROM {media} WHERE ' . implode(' AND ', $where) . ' ORDER BY media_id DESC LIMIT ?', $params));
    }

    /** list_media_folders: the same list as the folders in the admin Media */
    private function toolListMediaFolders(string $name, array $a): mixed
    {
        return array_map(fn (array $f): array => ['id' => (int) $f['folder_id'], 'name' => $f['name'], 'files' => (int) $f['count']],
            $this->app->db()->all('SELECT f.folder_id, f.name, (SELECT COUNT(*) FROM {media} m WHERE m.folder_id = f.folder_id) AS count FROM {media_folders} f ORDER BY f.name'));
    }

    /** save_media_folder: create, or rename with id (as Admin\Modules\Media::actionFolder) */
    private function toolSaveMediaFolder(string $name, array $a): mixed
    {
        $db = $this->app->db();
        $folderName = mb_substr(trim((string) ($a['name'] ?? '')), 0, 100);
        if ($folderName === '') {
            throw new \InvalidArgumentException('The folder needs a name.');
        }
        $id = (int) ($a['id'] ?? 0);
        if ($id > 0) {
            $db->update('media_folders', ['name' => $folderName], ['folder_id' => $id]);
        } else {
            $id = $db->insert('media_folders', ['name' => $folderName]);
        }

        return ['id' => $id, 'name' => $folderName];
    }

    /** delete_media_folder: the files stay, in no folder (the foreign key sets NULL) */
    private function toolDeleteMediaFolder(string $name, array $a): mixed
    {
        if (!$this->app->auth()->isAdmin()) {
            throw new \DomainException('A folder is deleted by an administrator.');
        }
        $id = (int) ($a['id'] ?? 0);
        $this->app->db()->delete('media_folders', ['folder_id' => $id]);

        return ['deleted' => $id];
    }

    /** move_media: into a folder or out of all; files the user may not change are reported, not moved */
    private function toolMoveMedia(string $name, array $a): mixed
    {
        $auth = $this->app->auth();
        $db = $this->app->db();
        $folder = (int) ($a['folder'] ?? 0) ?: null;
        $moved = [];
        $skipped = 0;
        foreach (array_unique(array_map('intval', (array) ($a['ids'] ?? []))) as $id) {
            $owner = $db->value('SELECT owner_id FROM {media} WHERE media_id = ?', [$id]);
            if ($owner === null || (!$auth->isAdmin() && (int) $owner !== $auth->id())) {
                $skipped++;
                continue;
            }
            $db->update('media', ['folder_id' => $folder], ['media_id' => $id]);
            $moved[] = $id;
        }

        return ['moved' => $moved, 'folder' => $folder] + ($skipped > 0 ? ['skipped' => $skipped, 'note' => 'Only the owner of a file or an administrator can move it.'] : []);
    }

    /** import_website (2.6): one batch of Core\WebImport per call */
    private function toolImportWebsite(string $name, array $a): mixed
    {
        if (!$this->app->auth()->isAdmin()) {
            throw new \DomainException('A site is imported by an administrator.');
        }
        $id = trim((string) ($a['import_id'] ?? ''));
        if ($id === '') {
            $url = trim((string) ($a['url'] ?? ''));
            $url = preg_match('#^https?://#i', $url) ? $url : 'https://' . $url;
            if (!\Talea\Core\WebImport::validUrl($url)) {
                throw new \InvalidArgumentException('Give the address of the site, e.g. https://www.example.com.');
            }
            $language = (string) ($a['language'] ?? '');
            $state = \Talea\Core\WebImport::newState($url, [
                'language' => in_array($language, \Talea\Core\Language::additional($this->app->settings()), true) ? $language : '',
                'images' => ($a['images'] ?? true) !== false, 'redirects' => ($a['redirects'] ?? true) !== false, 'news' => ($a['news'] ?? true) !== false,
            ]);
        } else {
            $state = \Talea\Core\WebImport::load($id) ?? throw new \InvalidArgumentException('The import does not exist; start a new one with the url.');
        }
        if (($a['confirm'] ?? false) === true && $state['phase'] === 'preview') {
            $state['phase'] = 'import';
            $state['position'] = 0;
        }
        if (in_array($state['phase'], ['finding', 'import'], true)) {
            (new \Talea\Core\WebImport($this->app->db(), $this->app->settings(), $this->app->auth()->id(), new \Talea\Core\ImageDownloader($state['web'], true)))->step($state);
        }
        \Talea\Core\WebImport::save($state);
        $urls = array_keys($state['urls']);

        $r = $state['result'];

        return ['import_id' => $state['id'], 'site' => $state['web'], 'phase' => ['finding' => 'finding', 'preview' => 'preview', 'import' => 'importing', 'done' => 'done'][$state['phase']] ?? $state['phase'],
            'found' => count($urls), 'processed' => (int) $state['position'],
            'result' => ['new_pages' => $r['pages'], 'new_news' => $r['articles'], 'images' => $r['images'], 'redirects' => $r['redirects'], 'skipped' => $r['skipped'], 'failed' => $r['failed']],
            'failures' => $state['errors'],
            'addresses' => $state['phase'] === 'preview' ? array_map(fn (string $u): string => '/' . \Talea\Core\WebImport::path($u), array_slice($urls, 0, 50)) : [],
            'next' => match ($state['phase']) {
                'finding', 'import' => 'Call again with the same import id.',
                'preview' => 'Show the user what was found; on their instruction call again with confirm: true.',
                default => 'Done: the pages are hidden – check them with list_pages, put the ones to keep in the menu and publish them on the user\'s instruction.',
            }];
    }

    /** upload_file */
    private function toolUploadFile(string $name, array $a): mixed
    {
        return $this->uploadFile($a);
    }

    /**
     * upload_files: several files in one call (a whole folder from the user's computer), each as upload_file; folder_name
     * finds or creates the folder. One file that fails is reported and the rest are still uploaded.
     */
    private function toolUploadFiles(string $name, array $a): mixed
    {
        $files = array_values(array_filter((array) ($a['files'] ?? []), 'is_array'));
        if ($files === [] || count($files) > 20) {
            throw new \InvalidArgumentException('Give 1 to 20 files; send more in further calls.');
        }
        $folder = $this->uploadFolder($a);
        $uploaded = [];
        $failed = [];
        foreach ($files as $file) {
            try {
                $uploaded[] = $this->uploadFile(['folder' => $folder] + $file);
            } catch (\InvalidArgumentException $e) {
                $failed[] = ['filename' => (string) ($file['filename'] ?? ''), 'error' => $e->getMessage()];
            }
        }

        return ['folder' => $folder ?: null, 'files' => $uploaded] + ($failed !== [] ? ['failed' => $failed] : []);
    }

    /** The folder of upload_files and request_upload: folder (an id), or folder_name found or created; 0 = none. */
    private function uploadFolder(array $a): int
    {
        $folder = (int) ($a['folder'] ?? 0);
        $folderName = mb_substr(trim((string) ($a['folder_name'] ?? '')), 0, 100);
        if ($folder === 0 && $folderName !== '') {
            $db = $this->app->db();
            $folder = (int) $db->value('SELECT folder_id FROM {media_folders} WHERE name = ?', [$folderName]);
            if ($folder === 0 && $this->app->auth()->draftsOnly()) {
                throw new \DomainException('A connection limited to drafts does not create folders; use an existing one (list_media_folders) or leave folder_name out.');
            }
            $folder = $folder ?: $db->insert('media_folders', ['name' => $folderName]);
        }

        return $folder;
    }

    /**
     * request_upload: one upload link per file (Mcp\UploadLinks) – the client sends the real file there with a PUT, no base64.
     * Only through a Claude connection: the link acts as that connection.
     */
    private function toolRequestUpload(string $name, array $a): mixed
    {
        $tokenId = (int) ($this->app->auth()->user()['token_id'] ?? 0);
        if ($tokenId === 0) {
            throw new \DomainException('Upload links are given to Claude connections only.');
        }
        $files = array_values(array_filter((array) ($a['files'] ?? []), 'is_array'));
        if ($files === [] || count($files) > 50) {
            throw new \InvalidArgumentException('Give 1 to 50 files; ask for more in further calls.');
        }
        $files = array_map(fn (array $file): array => ['filename' => basename(str_replace('\\', '/', trim((string) ($file['filename'] ?? '')))), 'alt' => trim((string) ($file['alt'] ?? ''))], $files);
        foreach ($files as $file) {
            if (pathinfo($file['filename'], PATHINFO_EXTENSION) === '') {
                throw new \InvalidArgumentException('Every file needs a name with an extension (e.g. photo.jpg): “' . $file['filename'] . '”.');
            }
        }
        $folder = $this->uploadFolder($a);
        $base = $this->app->request->origin() . $this->app->request->basePath() . '/mcp/upload/';
        $links = array_map(fn (array $file): array => ['filename' => $file['filename'],
            'upload_url' => $base . \Talea\Mcp\UploadLinks::create($this->app->db(), $tokenId, $file['filename'], $file['alt'], $folder ?: null)], $files);

        return ['folder' => $folder ?: null, 'expires_in_minutes' => \Talea\Mcp\UploadLinks::MINUTES, 'uploads' => $links,
            'how' => 'Send each file as the body of a PUT to its upload_url, e.g. curl -sS -T "photo.jpg" "<upload_url>" – one file per link, once. The answer is the file as upload_file returns it (id, path, url). Without a shell, use upload_file or upload_files with url or data instead.'];
    }

    /**
     * replace_media: a new image behind the same address (as Admin\Modules\Media::actionReplace) – every page that shows the
     * file shows the new one at once. Not for a file waiting for approval: the new one would be public before anyone approved it.
     */
    private function toolReplaceMedia(string $name, array $a): mixed
    {
        $auth = $this->app->auth();
        $db = $this->app->db();
        $id = (int) ($a['id'] ?? 0);
        $file = $db->one('SELECT * FROM {media} WHERE media_id = ?', [$id]) ?? throw new \InvalidArgumentException('The file does not exist. Use list_media.');
        if (!$auth->isAdmin() && (int) $file['owner_id'] !== $auth->id()) {
            throw new \DomainException('Only the owner of the file or an administrator can replace it.');
        }
        if ($file['staged_at'] !== null) {
            throw new \DomainException('The file waits for approval; upload the new one with upload_file instead.');
        }
        $temporary = tempnam(sys_get_temp_dir(), 'talea-mcp-');
        file_put_contents($temporary, $this->fileContent($a));
        try {
            $new = \Talea\Core\Images::replace($file['image_path'], $temporary, (string) $file['name']);
        } catch (\RuntimeException $e) {
            throw new \InvalidArgumentException($e->getMessage());
        } finally {
            @unlink($temporary);
        }
        $db->update('media', $new + ['color' => ''], ['media_id' => $id]);
        \Talea\Front\Cache::clear();

        return $this->medium($new + $file);
    }

    /** update_media and delete_media */
    private function toolUpdateMedia(string $name, array $a): mixed
    {
        $auth = $this->app->auth();
        $db = $this->app->db();
        $id = (int) ($a['id'] ?? 0);
        $need = function (bool $allowed, string $message): void {
            if (!$allowed) {
                throw new \DomainException($message);
            }
        };

        $file = $db->one('SELECT * FROM {media} WHERE media_id = ?', [$id]) ?? throw new \InvalidArgumentException('The file does not exist. Use list_media.');
        $need($auth->isAdmin() || (int) $file['owner_id'] === $auth->id(), 'Only the owner of the file or an administrator can change it.');
        if ($name === 'update_media') {
            $changes = array_filter(['name' => isset($a['alt']) ? mb_substr(trim((string) $a['alt']), 0, 150) : null, 'description' => isset($a['caption']) ? mb_substr(trim((string) $a['caption']), 0, 500) : null,
                'author' => isset($a['author']) ? mb_substr(trim((string) $a['author']), 0, 120) : null], fn (?string $v): bool => $v !== null);
            if (isset($a['focal_point'])) {
                $point = trim((string) $a['focal_point']);
                if ($point !== '' && \Talea\Builder\Style::focal($point) === null) {
                    throw new \InvalidArgumentException('focal_point is “x% y%” with whole numbers 0–100, for example “62% 38%”, or empty for the centre.');
                }
                $changes['focal_point'] = $point === '50% 50%' ? '' : $point; // the centre is stored as empty, as in Media → edit
            }
            if ($changes !== []) {
                $db->update('media', $changes, ['media_id' => $id]);
                \Talea\Front\Cache::clear();
            }

            return ['id' => $id, 'path' => $file['image_path'], 'changed' => array_keys($changes)];
        }
        $usedAt = array_keys(\Talea\Admin\Modules\Media::findUsagesElsewhere($db)[$id] ?? []);
        if ($usedAt !== [] || $db->value('SELECT 1 FROM {media_usage} WHERE media_id = ? LIMIT 1', [$id]) !== null) {
            throw new \DomainException('The file is still used on the site' . ($usedAt !== [] ? ': ' . implode(', ', array_slice($usedAt, 0, 5)) : ' (in a news item)') . ' – remove it from there first.');
        }
        \Talea\Core\Images::delete($file['image_path'], $file['thumb_path']);
        \Talea\Core\Files::delete($file['image_path']);
        $db->delete('media', ['media_id' => $id]);

        return ['deleted' => $id, 'path' => $file['image_path']];
    }

    /** delete_media: the same as update_media */
    private function toolDeleteMedia(string $name, array $a): mixed
    {
        return $this->toolUpdateMedia($name, $a);
    }

    /** list_media_without_alt (2.14, Core\MediaHygiene): images whose description is empty, with where they are used */
    private function toolListMediaWithoutAlt(string $name, array $a): mixed
    {
        $limit = max(1, min(200, (int) ($a['limit'] ?? 50)));
        $report = Language::runWith('en', fn (): array => \Talea\Core\MediaHygiene::report($this->app->db()), 'admin-');
        $base = $this->app->request->origin();

        return [
            'total' => count($report['without_alt']),
            'images' => array_map(fn (array $o): array => ['id' => (int) $o['media_id'], 'path' => $o['image_path'], 'url' => $base . $this->app->url($o['image_path']),
                'width' => (int) $o['image_width'], 'height' => (int) $o['image_height'], 'caption' => $o['description'], 'used_in' => $o['used_in'], 'used_in_news' => (int) $o['news_usage']],
                array_slice($report['without_alt'], 0, $limit)),
            'next' => count($report['without_alt']) === 0 ? 'Every image has a description.'
                : 'For each image call update_media with alt: what the image shows in a few words, in the site language (never "image" or the file name). Decorative images get a short neutral description too, since the site reads alt as the image name.',
        ];
    }
}
