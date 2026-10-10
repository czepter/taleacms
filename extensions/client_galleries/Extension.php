<?php

declare(strict_types=1);

namespace TaleaAddon\ClientGalleries;

use Talea\Core\Antispam;
use Talea\Core\App;
use Talea\Core\Members;
use Talea\Core\Request;
use Talea\Core\Response;
use Talea\Extension\Api;
use Talea\Extension\ExtensionInterface;
use Talea\Extension\LifecycleInterface;
use Talea\Front\MemberArea;

require_once __DIR__ . '/DemoImages.php';
require_once __DIR__ . '/Galleries.php';

/**
 * Client galleries (issue #34): private galleries for clients. A gallery starts closed; the owner shares it by a private address or with
 * a member group (Core\Members), the client marks favourites and – when the owner allows – downloads single images or a zip. Off by default.
 * The public side is one route, /gallery/<token>/… (Api::route): everything it answers is private, no-store and noindex, never in the page
 * cache, the sitemap, the search, llms.txt or the feeds (it is none of the pages those read). Images are only reachable through that route.
 * The administration page, the CSV of the favourites and the tools for Claude are below; Claude lists galleries and favourites, and gets an
 * address only from the tool "share", which the owner has to ask for.
 */
final class Extension implements ExtensionInterface, LifecycleInterface
{
    public function register(Api $api): void
    {
        $api->route('gallery', fn (Request $request, string $rest): array|Response|null => $this->serve($api, $request, $rest));
        $api->adminPage('galleries', 'Client galleries', fn (Request $request): string => $this->page($api, $request));

        $api->healthRows(function (App $app): array {
            $db = $app->db();
            $shared = (int) $db->value("SELECT COUNT(*) FROM {ext_cg_galleries} WHERE access <> 'closed'");

            return [['group' => 'Client galleries', 'name' => t('Client galleries'), 'status' => is_writable(TALEA_ROOT . '/storage') ? 'ok' : 'error',
                'info' => is_writable(TALEA_ROOT . '/storage') ? t('On: %d gallery(ies), %d shared.', (int) $db->value('SELECT COUNT(*) FROM {ext_cg_galleries}'), $shared) : t('The folder storage is not writable – images cannot be saved.')]];
        });

        $id = ['type' => 'string', 'description' => 'Public id of the gallery (from galleries)'];
        $api->mcpTool('galleries', 'Client galleries (read-only): every gallery with its public id, title, who may open it (closed, link or group), expiry, downloads allowed, and the number of images and favourites. Private addresses are never listed here.',
            ['properties' => []], 'read', fn (array $arguments): array => ['galleries' => array_map($this->summary(...), Galleries::all($api->app()->db()))], 'editor');
        $api->mcpTool('favourites', 'Client galleries (read-only): the images a client marked as favourites in one gallery, in gallery order, with who marked them (link or e-mail address of a member).',
            ['properties' => ['gallery' => $id], 'required' => ['gallery']], 'read', function (array $arguments) use ($api): array {
                $gallery = Galleries::byPublicId($api->app()->db(), (string) ($arguments['gallery'] ?? '')) ?? throw new \InvalidArgumentException('Unknown gallery.');

                return ['gallery' => $gallery['title'], 'favourites' => Galleries::favourites($api->app()->db(), (int) $gallery['id'])];
            }, 'editor');
        $api->mcpTool('create', 'Client galleries: creates a CLOSED gallery (nobody can open it) from the images of a Media folder (public id from list_media_folders, optional). It does not create an address – the owner shares it afterwards, or asks Claude to with "share".',
            ['properties' => ['title' => ['type' => 'string'], 'folder' => ['type' => 'string', 'description' => 'Public id of a Media folder whose images are copied in (optional)'],
                'expires' => ['type' => 'string', 'description' => 'Last day, YYYY-MM-DD (optional)'], 'allow_web' => ['type' => 'boolean', 'description' => 'Clients may download web size (default true)'],
                'allow_original' => ['type' => 'boolean', 'description' => 'Clients may download the originals (default false)'], 'allow_zip' => ['type' => 'boolean', 'description' => 'Clients may download everything as a zip (default false)']],
                'required' => ['title']],
            'write', function (array $arguments) use ($api): array {
                $db = $api->app()->db();
                $expires = Galleries::expiry((string) ($arguments['expires'] ?? '')) ?? throw new \InvalidArgumentException('Enter the last day as YYYY-MM-DD.');
                $gallery = Galleries::create($db, (string) ($arguments['title'] ?? ''), $expires, ($arguments['allow_web'] ?? true) !== false, ($arguments['allow_original'] ?? false) === true, ($arguments['allow_zip'] ?? false) === true);
                $added = ($arguments['folder'] ?? '') !== '' ? Galleries::importFolder($db, $gallery, (string) $arguments['folder']) : 0;

                return $this->summary(Galleries::all($db)[0] ?? $gallery) + ['added' => $added];
            }, 'editor');
        $api->mcpTool('share', 'Client galleries: opens a gallery to clients and returns its private address. Call it ONLY when the owner explicitly asks for a link or sharing – anyone with the address can see the photos. access: link (the private address), group (signed-in members of one member group; give the group) or closed (stops sharing).',
            ['properties' => ['gallery' => $id, 'access' => ['type' => 'string', 'enum' => Galleries::ACCESS], 'group' => ['type' => 'string', 'description' => 'Public id of a member group (access group)']],
                'required' => ['gallery', 'access']],
            'write', function (array $arguments) use ($api): array {
                $db = $api->app()->db();
                $gallery = Galleries::byPublicId($db, (string) ($arguments['gallery'] ?? '')) ?? throw new \InvalidArgumentException('Unknown gallery.');
                Galleries::share($db, $gallery, (string) ($arguments['access'] ?? ''), (string) ($arguments['group'] ?? ''));
                $gallery = Galleries::byPublicId($db, (string) $gallery['public_id']) ?? $gallery;

                return ['gallery' => $gallery['public_id'], 'access' => $gallery['access'], 'address' => $gallery['access'] === 'closed' ? null : $this->address($api->app(), $gallery)];
            }, 'editor');
    }

    /** @return array<string, mixed> what Claude may see of a gallery: never the private address */
    private function summary(array $g): array
    {
        return ['id' => $g['public_id'], 'title' => $g['title'], 'access' => $g['access'], 'group' => $g['group_public_id'] !== '' ? $g['group_public_id'] : null,
            'expires' => $g['expires_at'] !== '' ? substr((string) $g['expires_at'], 0, 10) : null, 'expired' => Galleries::expired($g),
            'downloads' => ['web' => (bool) $g['allow_web'], 'original' => (bool) $g['allow_original'], 'zip' => (bool) $g['allow_zip']],
            'images' => (int) ($g['images'] ?? 0), 'favourites' => (int) ($g['favourites'] ?? 0)];
    }

    private function address(App $app, array $gallery): string
    {
        return rtrim($app->settings()->get('site_url') ?: $app->request->origin(), '/') . $app->url('gallery/' . $gallery['token']);
    }

    /* ---------- the public side ---------- */

    /** @return array{0: string, 1: string, 2: int}|Response|null */
    private function serve(Api $api, Request $request, string $rest): array|Response|null
    {
        $app = $api->app();
        $db = $app->db();
        $parts = $rest === '' ? [] : explode('/', $rest);
        if ($parts === []) {
            return null;
        }
        if ($parts[0] === 'admin') {
            return $this->administration($app, $parts);
        }
        $gallery = Galleries::byToken($db, $parts[0]);
        if ($gallery === null) {
            // a guessed address is counted; whoever tries too often is turned away for a while
            return preg_match('/^[a-f0-9]{32}$/', $parts[0]) === 1 && Antispam::tally(Members::visitorKey($app), 'gallery-miss', 900) > Galleries::MISS_LIMIT
                ? new Response('Too many attempts.', 429, ['Content-Type' => 'text/plain; charset=utf-8', 'Retry-After' => '900']) : null;
        }
        [$state, $who] = Galleries::visitor($app, $gallery);
        if ($state === 'closed') {
            return null;
        }
        if ($state === 'expired') {
            return [t('This gallery has expired'), '<div class="tl-system-page"><h1>' . e(t('This gallery has expired')) . '</h1><p>' . e(t('The link to “%s” does not work any more. Ask the photographer for a new one.', (string) $gallery['title'])) . '</p></div>', 410];
        }
        if ($state !== 'ok') {
            return (new MemberArea($app))->gatePage($state, (string) $gallery['title']);
        }
        $images = Galleries::images($db, (int) $gallery['id']);
        $url = $app->url('gallery/' . $gallery['token']);
        if (count($parts) === 1) {
            return $request->isPost() ? $this->mark($app, $request, $gallery, $images, $who, $url) : $this->gallery($app, $gallery, $images, $who);
        }
        if ($parts[1] === 'zip' && count($parts) === 2) {
            return $this->zip($gallery, $images, $request->get('size'));
        }
        $image = count($parts) === 3 ? $this->imageOf($images, $parts[1]) : null;
        if ($image === null) {
            return null;
        }

        return match ($parts[2]) {
            'thumb' => $this->image($gallery, $image, 'thumb'),
            'view' => $this->image($gallery, $image, 'web'),
            'download' => $this->download($gallery, $image, $request->get('size')),
            default => null,
        };
    }

    /** @param list<array<string, mixed>> $images */
    private function imageOf(array $images, string $publicId): ?array
    {
        foreach ($images as $image) {
            if ($image['public_id'] === $publicId) {
                return $image;
            }
        }

        return null;
    }

    private function image(array $gallery, array $image, string $kind): ?Response
    {
        $path = Galleries::file($gallery, $image, $kind);

        return is_file($path) ? new Response((string) file_get_contents($path), 200, ['Content-Type' => 'image/jpeg', 'Cache-Control' => 'private, max-age=3600']) : null;
    }

    private function download(array $gallery, array $image, string $size): ?Response
    {
        $original = $size === 'original';
        if (!($original ? $gallery['allow_original'] : $gallery['allow_web'])) {
            return null;
        }
        $path = Galleries::file($gallery, $image, $original ? 'orig' : 'web');
        if (!is_file($path)) {
            return null;
        }
        $ext = $original ? (string) $image['ext'] : 'jpg';
        $name = trim((string) preg_replace('/[^A-Za-z0-9._ -]+/', '', (string) $image['name'])) ?: 'image';

        return new Response((string) file_get_contents($path), 200, ['Content-Type' => $original ? Galleries::MIME[$ext] : 'image/jpeg', 'Content-Disposition' => 'attachment; filename="' . $name . '.' . $ext . '"', 'Cache-Control' => 'private, no-store']);
    }

    /** @param list<array<string, mixed>> $images @return array{0: string, 1: string, 2: int}|Response|null */
    private function zip(array $gallery, array $images, string $size): array|Response|null
    {
        $original = $size === 'original';
        if (!$gallery['allow_zip'] || !($original ? $gallery['allow_original'] : $gallery['allow_web'])) {
            return null;
        }
        $bytes = Galleries::zip($gallery, $images, $original ? 'original' : 'web');
        if ($bytes === null) {
            return [t('Too large for one download'), '<div class="tl-system-page"><h1>' . e(t('Too large for one download')) . '</h1><p>' . e(t('This gallery is too large to download in one piece. Download the images one by one.')) . '</p></div>', 413];
        }
        $name = trim((string) preg_replace('/[^A-Za-z0-9._ -]+/', '', (string) $gallery['title'])) ?: 'gallery';

        return new Response($bytes, 200, ['Content-Type' => 'application/zip', 'Content-Disposition' => 'attachment; filename="' . $name . '.zip"', 'Cache-Control' => 'private, no-store']);
    }

    /** POST: mark or unmark a favourite, then back to the gallery at the same image. @param list<array<string, mixed>> $images */
    private function mark(App $app, Request $request, array $gallery, array $images, string $who, string $url): Response
    {
        $image = $request->post('op') === 'favourite' ? $this->imageOf($images, $request->post('image')) : null;
        if ($image !== null) {
            Galleries::favourite($app->db(), $gallery, $image, $who, $request->post('on') === '1');
        }

        return Response::redirect($url . ($image !== null ? '#i-' . substr((string) $image['public_id'], 0, 8) : ''), 303);
    }

    /** @param list<array<string, mixed>> $images @return array{0: string, 1: string, 2: int} */
    private function gallery(App $app, array $gallery, array $images, string $who): array
    {
        $url = $app->url('gallery/' . $gallery['token']);
        $marked = $who !== '' ? Galleries::marked($app->db(), (int) $gallery['id'], $who) : [];
        $sizes = array_filter(['web' => $gallery['allow_web'], 'original' => $gallery['allow_original']]);
        $html = '<link rel="stylesheet" href="' . e($app->url('extensions/client_galleries/public/gallery.css')) . '"><div class="cg-gallery"><h1>' . e((string) $gallery['title']) . '</h1>'
            . ($gallery['expires_at'] !== '' ? '<p>' . e(t('Available until %s.', format_date((string) $gallery['expires_at']))) . '</p>' : '');
        if ($images === []) {
            return [(string) $gallery['title'], $html . '<p>' . e(t('There are no images in this gallery yet.')) . '</p></div>', 200];
        }
        $html .= '<div class="cg-bar"><span>' . e($who !== '' ? t('Mark the images you like with the heart – %d marked.', count($marked)) : t('Preview: favourites can be marked by the client only.')) . '</span>';
        if ($gallery['allow_zip']) {
            foreach (array_keys($sizes) as $size) {
                $html .= '<a href="' . e($url . '/zip?size=' . $size) . '">' . e($size === 'original' ? t('Download all (originals, zip)') : t('Download all (web size, zip)')) . '</a>';
            }
        }
        $html .= '</div><ul class="cg-grid">';
        foreach ($images as $image) {
            [$tw, $th] = Galleries::thumbSize((int) $image['width'], (int) $image['height']);
            $base = $url . '/' . $image['public_id'];
            $on = isset($marked[(int) $image['id']]);
            $html .= '<li><figure class="cg-item" id="i-' . e(substr((string) $image['public_id'], 0, 8)) . '"><a href="' . e($base . '/view') . '" target="_blank" rel="noopener">'
                . '<img src="' . e($base . '/thumb') . '" width="' . $tw . '" height="' . $th . '" loading="lazy" decoding="async" alt="' . e((string) $image['name']) . '"></a><figcaption>'
                . '<form class="cg-fav" method="post" action="' . e($url) . '"><input type="hidden" name="op" value="favourite"><input type="hidden" name="image" value="' . e((string) $image['public_id']) . '"><input type="hidden" name="on" value="' . ($on ? '0' : '1') . '">'
                . '<button type="submit"' . ($who === '' ? ' disabled' : '') . ' aria-pressed="' . ($on ? 'true' : 'false') . '">♥ ' . e($on ? t('Favourite') : t('Mark as favourite')) . '</button></form>';
            foreach (array_keys($sizes) as $size) {
                $html .= '<a href="' . e($base . '/download?size=' . $size) . '" download>' . e($size === 'original' ? t('Original') : t('Download')) . '</a>';
            }
            $html .= '</figcaption></figure></li>';
        }

        return [(string) $gallery['title'], $html . '</ul></div>', 200];
    }

    /** What only the administrator may fetch through the route: thumbnails for the administration page and the CSV of the favourites. @param list<string> $parts */
    private function administration(App $app, array $parts): ?Response
    {
        if (!$app->auth()->isAdmin() || count($parts) !== 3) {
            return null;
        }
        $db = $app->db();
        if ($parts[1] === 'thumb') {
            $image = \Talea\Core\Uuid::valid($parts[2]) ? $db->one('SELECT * FROM {ext_cg_images} WHERE public_id = ?', [$parts[2]]) : null;
            $gallery = $image !== null ? $db->one('SELECT * FROM {ext_cg_galleries} WHERE id = ?', [(int) $image['gallery_id']]) : null;

            return $gallery !== null ? $this->image($gallery, $image, 'thumb') : null;
        }
        $gallery = $parts[1] === 'export' ? Galleries::byPublicId($db, (string) preg_replace('/\.csv$/', '', $parts[2])) : null;

        return $gallery === null ? null : new Response(Galleries::csv(Galleries::favourites($db, (int) $gallery['id'])), 200,
            ['Content-Type' => 'text/csv; charset=utf-8', 'Content-Disposition' => 'attachment; filename="favourites.csv"', 'Cache-Control' => 'private, no-store']);
    }

    /* ---------- the administration ---------- */

    private function url(string $gallery = ''): string
    {
        return 'admin.php?module=addons&action=page&p=client_galleries.galleries' . ($gallery !== '' ? '&g=' . rawurlencode($gallery) : '');
    }

    private function page(Api $api, Request $request): string
    {
        $app = $api->app();
        $db = $app->db();
        $notice = '';
        $selected = $request->get('g') !== '' ? $request->get('g') : $request->post('g');
        if ($request->isPost()) {
            try {
                [$notice, $selected] = $this->post($api, $request, $selected);
            } catch (\InvalidArgumentException | \RuntimeException $e) {
                $notice = '<p class="notice notice-error">' . e(t($e->getMessage())) . '</p>';
            }
        }
        $gallery = Galleries::byPublicId($db, $selected);

        return $notice . ($gallery !== null ? $this->detail($api, $gallery) : $this->list($api));
    }

    /** @return array{0: string, 1: string} the notice and the gallery to show next */
    private function post(Api $api, Request $request, string $selected): array
    {
        $app = $api->app();
        $db = $app->db();
        $ok = fn (string $text): string => '<p class="notice notice-ok">' . e($text) . '</p>';
        $gallery = Galleries::byPublicId($db, $selected);
        switch ($request->post('op')) {
            case 'create':
                $expires = Galleries::expiry($request->post('expires')) ?? throw new \InvalidArgumentException('Enter the last day as a date.');
                $created = Galleries::create($db, $request->post('title'), $expires, $request->postBool('allow_web'), $request->postBool('allow_original'), $request->postBool('allow_zip'));

                return [$ok(t('The gallery was created. It is closed: nobody can open it until you share it.')), (string) $created['public_id']];
            case 'demo':
                $created = Galleries::demo($db, t('Demo gallery'));

                return [$ok(t('A demo gallery with %d generated pictures was created.', DemoImages::COUNT)), (string) $created['public_id']];
        }
        if ($gallery === null) {
            return ['', ''];
        }
        switch ($request->post('op')) {
            case 'save':
                $expires = Galleries::expiry($request->post('expires')) ?? throw new \InvalidArgumentException('Enter the last day as a date.');
                $title = mb_substr(trim($request->post('title')), 0, 150);
                if ($title === '') {
                    throw new \InvalidArgumentException('Enter the gallery name.');
                }
                $db->update('ext_cg_galleries', ['title' => $title, 'expires_at' => $expires, 'allow_web' => $request->postBool('allow_web'),
                    'allow_original' => $request->postBool('allow_original'), 'allow_zip' => $request->postBool('allow_zip')], ['id' => (int) $gallery['id']]);

                return [$ok(t('Saved.')), $selected];
            case 'share':
                Galleries::share($db, $gallery, $request->post('access'), $request->post('group'));

                return [$ok(t('Saved.')), $selected];
            case 'new_link':
                Galleries::newLink($db, $gallery);

                return [$ok(t('There is a new private address. The old one does not work any more.')), $selected];
            case 'upload':
                return [$this->upload($db, $gallery), $selected];
            case 'import':
                $added = Galleries::importFolder($db, $gallery, $request->post('folder'));

                return [$ok(t('%d image(s) were copied from the Media folder.', $added)), $selected];
            case 'remove_image':
                $image = \Talea\Core\Uuid::valid($request->post('image')) ? $db->one('SELECT * FROM {ext_cg_images} WHERE public_id = ? AND gallery_id = ?', [$request->post('image'), (int) $gallery['id']]) : null;
                if ($image !== null) {
                    Galleries::removeImage($db, $gallery, $image);
                }

                return [$ok(t('The image was removed.')), $selected];
            case 'delete':
                Galleries::delete($db, $gallery);

                return [$ok(t('The gallery and its images were deleted.')), ''];
        }

        return ['', $selected];
    }

    private function upload(\Talea\Core\Db $db, array $gallery): string
    {
        $files = $_FILES['images'] ?? null;
        $added = 0;
        $problems = [];
        foreach (is_array($files) && is_array($files['tmp_name'] ?? null) ? array_keys($files['tmp_name']) : [] as $i) {
            if (($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $name = (string) $files['name'][$i];
            try {
                if ($files['error'][$i] !== UPLOAD_ERR_OK || !is_uploaded_file((string) $files['tmp_name'][$i])) {
                    throw new \RuntimeException('The file could not be uploaded.');
                }
                Galleries::addImage($db, $gallery, (string) file_get_contents((string) $files['tmp_name'][$i]), $name);
                $added++;
            } catch (\RuntimeException $e) {
                $problems[] = $name . ': ' . t($e->getMessage());
            }
        }

        return ($added > 0 ? '<p class="notice notice-ok">' . e(t('%d image(s) were added.', $added)) . '</p>' : '')
            . ($problems !== [] ? '<p class="notice notice-error">' . e(implode(' ', $problems)) . '</p>' : '')
            . ($added === 0 && $problems === [] ? '<p class="notice notice-error">' . e(t('Choose at least one image.')) . '</p>' : '');
    }

    private function list(Api $api): string
    {
        $app = $api->app();
        $csrf = $app->session->csrfField();
        $access = ['closed' => t('Closed'), 'link' => t('Private link'), 'group' => t('Member group')];
        $html = '<p class="small-text">' . e(t('Private galleries for your clients: share a gallery by a private link or with a member group; clients mark favourites and – if you allow it – download their photos. Galleries are never cached, indexed or searchable.')) . '</p>';
        $galleries = Galleries::all($app->db());
        if ($galleries === []) {
            $html .= '<p>' . e(t('No galleries yet.')) . '</p>';
        } else {
            $html .= '<div class="tab-wrap"><table class="listing"><thead><tr><th scope="col">' . e(t('Gallery')) . '</th><th scope="col">' . e(t('Images')) . '</th><th scope="col">' . e(t('Favourites')) . '</th><th scope="col">' . e(t('Access')) . '</th><th scope="col">' . e(t('Available until')) . '</th></tr></thead><tbody>';
            foreach ($galleries as $g) {
                $html .= '<tr><td><a href="' . e($this->url((string) $g['public_id'])) . '">' . e((string) $g['title']) . '</a></td><td class="number">' . (int) $g['images'] . '</td><td class="number">' . (int) $g['favourites']
                    . '</td><td>' . e($access[$g['access']] ?? $g['access']) . '</td><td>' . ($g['expires_at'] !== '' ? e(format_date((string) $g['expires_at'])) . (Galleries::expired($g) ? ' (' . e(t('expired')) . ')' : '') : '–') . '</td></tr>';
            }
            $html .= '</tbody></table></div>';
        }

        return $html . '<h2>' . e(t('New gallery')) . '</h2><form method="post">' . $csrf . '<input type="hidden" name="op" value="create">'
            . '<p><label>' . e(t('Name')) . '<br><input type="text" name="title" maxlength="150" size="40" required></label></p>' . $this->options(null)
            . '<p><input class="btn" type="submit" value="' . e(t('Create gallery')) . '"></p></form>'
            . '<form method="post">' . $csrf . '<input type="hidden" name="op" value="demo"><p><button class="navigation" type="submit">' . e(t('Create a demo gallery with generated pictures')) . '</button></p></form>';
    }

    /** The expiry and download options, shared by the create and the edit form. */
    private function options(?array $g): string
    {
        $box = fn (string $name, string $label, bool $on): string => '<label><input type="checkbox" name="' . $name . '" value="1"' . ($on ? ' checked' : '') . '> ' . e($label) . '</label><br>';

        return '<p><label>' . e(t('Available until (empty = no end)')) . '<br><input type="date" name="expires" value="' . e($g !== null && $g['expires_at'] !== '' ? substr((string) $g['expires_at'], 0, 10) : '') . '"></label></p>'
            . '<p>' . e(t('Clients may download:')) . '<br>' . $box('allow_web', t('single images in web size'), $g === null || (bool) $g['allow_web'])
            . $box('allow_original', t('single images as originals'), $g !== null && (bool) $g['allow_original']) . $box('allow_zip', t('everything as a zip (in the sizes allowed above)'), $g !== null && (bool) $g['allow_zip']) . '</p>';
    }

    private function detail(Api $api, array $g): string
    {
        $app = $api->app();
        $db = $app->db();
        $csrf = $app->session->csrfField() . '<input type="hidden" name="g" value="' . e((string) $g['public_id']) . '">';
        $images = Galleries::images($db, (int) $g['id']);
        $favourites = Galleries::favourites($db, (int) $g['id']);
        $html = '<p><a href="' . e($this->url()) . '">← ' . e(t('All galleries')) . '</a></p><h2>' . e((string) $g['title']) . '</h2>';

        $html .= '<form method="post">' . $csrf . '<input type="hidden" name="op" value="save"><p><label>' . e(t('Name')) . '<br><input type="text" name="title" value="' . e((string) $g['title']) . '" maxlength="150" size="40" required></label></p>'
            . $this->options($g) . '<p><input class="btn" type="submit" value="' . e(t('Save')) . '"></p></form>';

        // sharing
        $groups = Members::enabled($app->settings()) ? $db->all('SELECT public_id, name FROM {member_groups} ORDER BY name') : [];
        $html .= '<h3>' . e(t('Sharing')) . '</h3><form method="post">' . $csrf . '<input type="hidden" name="op" value="share">';
        foreach (['closed' => t('Closed – nobody can open it'), 'link' => t('Anyone with the private link'), 'group' => t('Signed-in members of one member group')] as $value => $label) {
            $html .= '<label><input type="radio" name="access" value="' . $value . '"' . ($g['access'] === $value ? ' checked' : '') . '> ' . e($label) . '</label><br>';
        }
        $html .= '<p><label>' . e(t('Member group')) . ' <select name="group"><option value="">–</option>';
        foreach ($groups as $group) {
            $html .= '<option value="' . e((string) $group['public_id']) . '"' . ($g['group_public_id'] === $group['public_id'] ? ' selected' : '') . '>' . e((string) $group['name']) . '</option>';
        }
        $html .= '</select></label>' . ($groups === [] ? ' <span class="small-text">' . e(t('Member groups need the Members feature and at least one group (Features → Members).')) . '</span>' : '') . '</p>'
            . '<p><input class="btn" type="submit" value="' . e(t('Save')) . '"></p></form>';
        if ($g['access'] !== 'closed') {
            $html .= '<p>' . e(t('Address for the client:')) . ' <input type="text" readonly size="70" value="' . e($this->address($app, $g)) . '" aria-label="' . e(t('Address for the client:')) . '"></p>'
                . '<form method="post" data-confirm="' . e(t('Make a new address? The old one stops working at once.')) . '">' . $csrf . '<input type="hidden" name="op" value="new_link"><p><button class="navigation" type="submit">' . e(t('Make a new private address')) . '</button></p></form>';
        }

        // images
        $html .= '<h3>' . e(t('Images')) . ' (' . count($images) . ')</h3><form method="post" enctype="multipart/form-data">' . $csrf . '<input type="hidden" name="op" value="upload">'
            . '<p><label>' . e(t('Add images (JPG, PNG, WebP)')) . '<br><input type="file" name="images[]" accept="image/jpeg,image/png,image/webp" multiple></label> <input class="btn" type="submit" value="' . e(t('Upload')) . '"></p></form>';
        $folders = $db->all('SELECT public_id, name FROM {media_folders} ORDER BY name');
        if ($folders !== []) {
            $html .= '<form method="post">' . $csrf . '<input type="hidden" name="op" value="import"><p><label>' . e(t('Copy the images of a Media folder')) . ' <select name="folder">';
            foreach ($folders as $folder) {
                $html .= '<option value="' . e((string) $folder['public_id']) . '">' . e((string) $folder['name']) . '</option>';
            }
            $html .= '</select></label> <button class="navigation" type="submit">' . e(t('Copy images')) . '</button></p></form>';
        }
        $count = [];
        foreach ($favourites as $f) {
            $count[$f['image_id']] = ($count[$f['image_id']] ?? 0) + 1;
        }
        if ($images !== []) {
            $html .= '<div class="tab-wrap"><table class="listing"><thead><tr><th scope="col"></th><th scope="col">' . e(t('Image')) . '</th><th scope="col">' . e(t('Favourites')) . '</th><th scope="col"></th></tr></thead><tbody>';
            foreach ($images as $image) {
                [$tw, $th] = Galleries::thumbSize((int) $image['width'], (int) $image['height']);
                $html .= '<tr><td><img src="' . e($app->url('gallery/admin/thumb/' . $image['public_id'])) . '" width="' . min(96, $tw) . '" alt="" loading="lazy"></td><td>' . e((string) $image['name']) . '</td><td class="number">' . (int) ($count[$image['public_id']] ?? 0) . '</td>'
                    . '<td class="actions"><form class="inline" method="post">' . $csrf . '<input type="hidden" name="op" value="remove_image"><input type="hidden" name="image" value="' . e((string) $image['public_id']) . '"><button class="navigation danger" type="submit">' . e(t('Remove')) . '</button></form></td></tr>';
            }
            $html .= '</tbody></table></div>';
        }

        // favourites
        $html .= '<h3>' . e(t('Favourites')) . '</h3>';
        if ($favourites === []) {
            $html .= '<p>' . e(t('Nothing marked yet.')) . '</p>';
        } else {
            $html .= '<p><a href="' . e($app->url('gallery/admin/export/' . $g['public_id'] . '.csv')) . '">' . e(t('Download the favourites as a CSV file')) . '</a></p><ul>';
            foreach (array_unique(array_column($favourites, 'image')) as $name) {
                $html .= '<li>' . e((string) $name) . '</li>';
            }
            $html .= '</ul>';
        }

        return $html . '<form method="post" data-confirm="' . e(t('Delete this gallery with all its images and favourites? This cannot be undone.')) . '">' . $csrf . '<input type="hidden" name="op" value="delete"><p><button class="navigation danger" type="submit">' . e(t('Delete the gallery')) . '</button></p></form>';
    }

    public function onEnable(Api $api): void
    {
        // the tables come from migrations/; the images go to storage/galleries/ when the first one is added
    }

    public function onUninstall(Api $api, bool $deleteData): void
    {
        if (!$deleteData) {
            return;
        }
        foreach (glob(TALEA_ROOT . '/storage/galleries/*/*') ?: [] as $file) {
            @unlink($file);
        }
        foreach (glob(TALEA_ROOT . '/storage/galleries/*', GLOB_ONLYDIR) ?: [] as $dir) {
            @rmdir($dir);
        }
    }
}
