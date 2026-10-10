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

        return array_map(fn (array $o): array => $this->medium($o),
            $db->all('SELECT * FROM {media}' . ($search !== null ? ' WHERE ' . $db->dialect()->likeInsensitive('name') . ' OR ' . $db->dialect()->likeInsensitive('image_path') : '') . ' ORDER BY media_id DESC LIMIT ?',
                [...($search !== null ? [$search, $search] : []), max(1, min(50, (int) ($a['limit'] ?? 20)))]));
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
