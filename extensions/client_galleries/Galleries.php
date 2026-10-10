<?php

declare(strict_types=1);

namespace TaleaAddon\ClientGalleries;

use Talea\Core\App;
use Talea\Core\Db;
use Talea\Core\Members;
use Talea\Core\Uuid;

/**
 * Client galleries (issue #34): the data and the files. Images live in storage/galleries/<gallery public id>/ – a folder the web server never
 * serves – so the only way to an image is Extension::serve(), which checks the address, the expiry and the member group on every request.
 * Per image three files: the original as uploaded (<id>.orig.<ext>), the web size (<id>.web.jpg, 2048 px) and the thumbnail (<id>.thumb.jpg).
 */
final class Galleries
{
    public const int MAX_BYTES = 25 * 1024 * 1024;

    public const int MAX_PIXELS = 50_000_000;

    public const int MAX_IMAGES = 1000;

    public const int WEB_SIDE = 2048;

    public const int THUMB_SIDE = 640;

    /** A zip is built in memory-sized pieces of the site, so it has a ceiling (ponytail: a streamed zip would lift it). */
    public const int ZIP_MAX_IMAGES = 500;

    public const int ZIP_MAX_BYTES = 100 * 1024 * 1024;

    /** Wrong addresses per visitor network per 15 minutes before the visitor is turned away. */
    public const int MISS_LIMIT = 30;

    public const array ACCESS = ['closed', 'link', 'group'];

    private const array TYPES = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];

    public const array MIME = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];

    public static function dir(string $galleryPublicId): string
    {
        return TALEA_ROOT . '/storage/galleries/' . $galleryPublicId;
    }

    /** The path of a file of an image: kind orig | web | thumb. */
    public static function file(array $gallery, array $image, string $kind): string
    {
        return self::dir((string) $gallery['public_id']) . '/' . $image['public_id'] . '.' . match ($kind) {
            'orig' => 'orig.' . $image['ext'],
            'web' => 'web.jpg',
            default => 'thumb.jpg',
        };
    }

    /** "YYYY-MM-DD" -> the end of that day; '' = no expiry; null = not a date. */
    public static function expiry(string $date): ?string
    {
        $date = trim($date);
        if ($date === '') {
            return '';
        }

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 && checkdate((int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4)) ? $date . ' 23:59:59' : null;
    }

    public static function expired(array $gallery): bool
    {
        return (string) $gallery['expires_at'] !== '' && (string) $gallery['expires_at'] < date('Y-m-d H:i:s');
    }

    /** The size of a thumbnail of an image of this size. @return array{0: int, 1: int} */
    public static function thumbSize(int $w, int $h): array
    {
        $scale = min(1.0, self::THUMB_SIDE / max(1, $w, $h));

        return [max(1, (int) round($w * $scale)), max(1, (int) round($h * $scale))];
    }

    /**
     * @return array<string, mixed> the new gallery; it starts closed – nobody can open it until the owner shares it
     */
    public static function create(Db $db, string $title, ?string $expires = '', bool $web = true, bool $original = false, bool $zip = false): array
    {
        $title = mb_substr(trim($title), 0, 150);
        if ($title === '') {
            throw new \InvalidArgumentException('Enter the gallery name.');
        }
        $publicId = Uuid::v4();
        $db->insert('ext_cg_galleries', ['public_id' => $publicId, 'title' => $title, 'token' => bin2hex(random_bytes(16)), 'access' => 'closed',
            'expires_at' => (string) $expires, 'allow_web' => $web, 'allow_original' => $original, 'allow_zip' => $zip, 'created_at' => date('Y-m-d H:i:s')]);

        return self::byPublicId($db, $publicId) ?? throw new \RuntimeException('The gallery could not be saved.');
    }

    /** @return array<string, mixed>|null */
    public static function byPublicId(Db $db, string $publicId): ?array
    {
        return Uuid::valid($publicId) ? $db->one('SELECT * FROM {ext_cg_galleries} WHERE public_id = ?', [$publicId]) : null;
    }

    /** @return array<string, mixed>|null */
    public static function byToken(Db $db, string $token): ?array
    {
        return preg_match('/^[a-f0-9]{32}$/', $token) === 1 ? $db->one('SELECT * FROM {ext_cg_galleries} WHERE token = ?', [$token]) : null;
    }

    /** @return list<array<string, mixed>> galleries with the number of images and favourites */
    public static function all(Db $db): array
    {
        return $db->all('SELECT g.*, (SELECT COUNT(*) FROM {ext_cg_images} i WHERE i.gallery_id = g.id) AS images, (SELECT COUNT(DISTINCT f.image_id) FROM {ext_cg_favourites} f WHERE f.gallery_id = g.id) AS favourites FROM {ext_cg_galleries} g ORDER BY g.created_at DESC, g.id DESC');
    }

    /** @return list<array<string, mixed>> */
    public static function images(Db $db, int $galleryId): array
    {
        return $db->all('SELECT * FROM {ext_cg_images} WHERE gallery_id = ? ORDER BY sort_order, id', [$galleryId]);
    }

    /** Sets who may open the gallery. $groupPublicId only for 'group'. @throws \InvalidArgumentException */
    public static function share(Db $db, array $gallery, string $access, string $groupPublicId = ''): void
    {
        if (!in_array($access, self::ACCESS, true)) {
            throw new \InvalidArgumentException('Unknown access.');
        }
        if ($access === 'group' && (!\Talea\Core\Uuid::valid($groupPublicId) || $db->value('SELECT 1 FROM {member_groups} WHERE public_id = ?', [$groupPublicId]) === null)) {
            throw new \InvalidArgumentException('Choose a member group.');
        }
        $db->update('ext_cg_galleries', ['access' => $access, 'group_public_id' => $access === 'group' ? $groupPublicId : ''], ['id' => (int) $gallery['id']]);
    }

    /** A new private address: the old one stops working at once. */
    public static function newLink(Db $db, array $gallery): void
    {
        $db->update('ext_cg_galleries', ['token' => bin2hex(random_bytes(16))], ['id' => (int) $gallery['id']]);
    }

    public static function delete(Db $db, array $gallery): void
    {
        $db->delete('ext_cg_favourites', ['gallery_id' => (int) $gallery['id']]);
        $db->delete('ext_cg_images', ['gallery_id' => (int) $gallery['id']]);
        $db->delete('ext_cg_galleries', ['id' => (int) $gallery['id']]);
        foreach (glob(self::dir((string) $gallery['public_id']) . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir(self::dir((string) $gallery['public_id']));
    }

    /**
     * Adds an image: validates it as a picture (JPEG, PNG or WebP), keeps the original untouched and makes the web size and the thumbnail.
     *
     * @return array<string, mixed> the image row
     * @throws \RuntimeException with an English message that the caller shows through t()
     */
    public static function addImage(Db $db, array $gallery, string $bytes, string $name): array
    {
        if (!extension_loaded('gd')) {
            throw new \RuntimeException('The GD extension for image processing is missing on the server.');
        }
        $info = @getimagesizefromstring($bytes);
        if ($info === false || !isset(self::TYPES[$info[2]])) {
            throw new \RuntimeException('Only JPG, PNG and WebP images are allowed.');
        }
        if (strlen($bytes) > self::MAX_BYTES || $info[0] * $info[1] > self::MAX_PIXELS) {
            throw new \RuntimeException('The image is too large (25 MB and 50 megapixels at most).');
        }
        $galleryId = (int) $gallery['id'];
        if ((int) $db->value('SELECT COUNT(*) FROM {ext_cg_images} WHERE gallery_id = ?', [$galleryId]) >= self::MAX_IMAGES) {
            throw new \RuntimeException('A gallery holds at most 1000 images.');
        }
        $source = @imagecreatefromstring($bytes);
        if ($source === false) {
            throw new \RuntimeException('The image is damaged and cannot be processed.');
        }
        $ext = self::TYPES[$info[2]];
        if ($ext === 'jpg') {
            $source = self::upright($source, $bytes);
        }
        $publicId = Uuid::v4();
        $dir = self::dir((string) $gallery['public_id']);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Cannot create the folder storage/galleries – check the write permissions.');
        }
        $row = ['public_id' => $publicId, 'ext' => $ext];
        $image = ['public_id' => $publicId, 'ext' => $ext];
        if (file_put_contents(self::file($gallery, $image, 'orig'), $bytes) === false
            || !self::writeJpeg(self::fitted($source, self::WEB_SIDE), self::file($gallery, $image, 'web'))
            || !self::writeJpeg(self::fitted($source, self::THUMB_SIDE), self::file($gallery, $image, 'thumb'))) {
            throw new \RuntimeException('The image could not be saved.');
        }
        $clean = mb_substr(trim(str_replace(['_', '-'], ' ', pathinfo($name, PATHINFO_FILENAME))), 0, 150);
        $db->insert('ext_cg_images', $row + ['gallery_id' => $galleryId, 'name' => $clean, 'width' => imagesx($source), 'height' => imagesy($source), 'bytes' => strlen($bytes),
            'sort_order' => (int) $db->value('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM {ext_cg_images} WHERE gallery_id = ?', [$galleryId]), 'created_at' => date('Y-m-d H:i:s')]);

        return $db->one('SELECT * FROM {ext_cg_images} WHERE public_id = ?', [$publicId]) ?? throw new \RuntimeException('The image could not be saved.');
    }

    /**
     * Copies the images of a Media folder into the gallery (the files of Media stay where they are – the gallery gets its own private copies).
     *
     * @return int how many images were added
     */
    public static function importFolder(Db $db, array $gallery, string $folderPublicId): int
    {
        $folder = $db->one('SELECT folder_id FROM {media_folders} WHERE public_id = ?', [$folderPublicId]);
        if ($folder === null) {
            throw new \InvalidArgumentException('Choose a Media folder.');
        }
        $added = 0;
        foreach ($db->all('SELECT name, image_path FROM {media} WHERE folder_id = ? ORDER BY created_at, media_id', [(int) $folder['folder_id']]) as $media) {
            $path = TALEA_ROOT . '/' . ltrim((string) $media['image_path'], '/');
            if (str_contains((string) $media['image_path'], '..') || !is_file($path)) {
                continue;
            }
            try {
                self::addImage($db, $gallery, (string) file_get_contents($path), (string) ($media['name'] !== '' ? $media['name'] : basename($path)));
                $added++;
            } catch (\RuntimeException) {
                // an image that cannot be copied (GIF, too large) is skipped
            }
        }

        return $added;
    }

    /** Twelve generated pictures (DemoImages) in a new closed gallery. @return array<string, mixed> */
    public static function demo(Db $db, string $title): array
    {
        $gallery = self::create($db, $title, '', true, true, true);
        for ($n = 0; $n < DemoImages::COUNT; $n++) {
            self::addImage($db, $gallery, DemoImages::jpeg($n), sprintf('Demo picture %02d', $n + 1));
        }

        return $gallery;
    }

    public static function removeImage(Db $db, array $gallery, array $image): void
    {
        $db->delete('ext_cg_favourites', ['image_id' => (int) $image['id']]);
        $db->delete('ext_cg_images', ['id' => (int) $image['id']]);
        foreach (['orig', 'web', 'thumb'] as $kind) {
            @unlink(self::file($gallery, $image, $kind));
        }
    }

    /* ---------- who is looking ---------- */

    /**
     * What this request may do with the gallery: 'closed' (nobody may – answered as if it did not exist), 'expired', 'login' (a member group, nobody signed in),
     * 'denied' (signed in, not in the group), or 'ok'. Second: who the favourites belong to – 'link' for the private address, 'm<id>' for a member,
     * '' for the administrator looking from the administration (may look, not mark).
     *
     * @return array{0: string, 1: string}
     */
    public static function visitor(App $app, array $gallery): array
    {
        $admin = $app->auth()->isAdmin();
        if ($gallery['access'] === 'closed' || !in_array($gallery['access'], self::ACCESS, true)) {
            return ['closed', ''];
        }
        if (self::expired($gallery) && !$admin) {
            return ['expired', ''];
        }
        if ($gallery['access'] === 'link') {
            return ['ok', $admin ? '' : 'link'];
        }
        $member = Members::enabled($app->settings()) ? Members::current($app) : null;
        if ($admin) {
            return ['ok', $member !== null ? 'm' . (int) $member['member_id'] : ''];
        }
        if ($member === null) {
            return ['login', ''];
        }
        $group = $app->db()->internalId('member_groups', (string) $gallery['group_public_id']);

        return $group > 0 && in_array($group, Members::groupIds($app->db(), (int) $member['member_id']), true) ? ['ok', 'm' . (int) $member['member_id']] : ['denied', ''];
    }

    /* ---------- favourites ---------- */

    public static function favourite(Db $db, array $gallery, array $image, string $who, bool $on): void
    {
        if ($who === '' || (int) $image['gallery_id'] !== (int) $gallery['id']) {
            return;
        }
        if ($on) {
            $db->insertIgnore('ext_cg_favourites', ['gallery_id' => (int) $gallery['id'], 'image_id' => (int) $image['id'], 'who' => $who, 'created_at' => date('Y-m-d H:i:s')]);
        } else {
            $db->delete('ext_cg_favourites', ['image_id' => (int) $image['id'], 'who' => $who]);
        }
    }

    /** @return array<int, true> the ids of the images this person has marked */
    public static function marked(Db $db, int $galleryId, string $who): array
    {
        $marked = [];
        foreach ($db->all('SELECT image_id FROM {ext_cg_favourites} WHERE gallery_id = ? AND who = ?', [$galleryId, $who]) as $row) {
            $marked[(int) $row['image_id']] = true;
        }

        return $marked;
    }

    /** @return list<array{image: string, image_id: string, by: string, at: string}> the favourites of a gallery in the order of the images; "by" is "link" or the member's e-mail address */
    public static function favourites(Db $db, int $galleryId): array
    {
        $rows = $db->all('SELECT i.name, i.public_id, f.who, f.created_at FROM {ext_cg_favourites} f JOIN {ext_cg_images} i ON i.id = f.image_id WHERE f.gallery_id = ? ORDER BY i.sort_order, i.id, f.created_at', [$galleryId]);
        $ids = array_values(array_unique(array_map(fn (array $r): int => (int) substr((string) $r['who'], 1), array_filter($rows, fn (array $r): bool => $r['who'] !== 'link'))));
        $emails = [];
        foreach ($ids === [] ? [] : $db->all('SELECT member_id, email FROM {members} WHERE member_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', $ids) as $m) {
            $emails['m' . $m['member_id']] = (string) $m['email'];
        }

        return array_map(fn (array $r): array => ['image' => (string) $r['name'], 'image_id' => (string) $r['public_id'], 'by' => $r['who'] === 'link' ? 'link' : ($emails[$r['who']] ?? (string) $r['who']), 'at' => (string) $r['created_at']], $rows);
    }

    /** The favourites as CSV (Excel-safe: a cell that starts like a formula gets a leading quote). */
    public static function csv(array $favourites): string
    {
        $cell = fn (string $v): string => '"' . str_replace('"', '""', preg_match('/^[=+\-@\t\r]/', $v) === 1 ? "'" . $v : $v) . '"';
        $out = "\xEF\xBB\xBF" . implode(',', array_map($cell, ['Image', 'Image id', 'Marked by', 'When'])) . "\r\n";
        foreach ($favourites as $f) {
            $out .= implode(',', array_map($cell, [$f['image'], $f['image_id'], $f['by'], $f['at']])) . "\r\n";
        }

        return $out;
    }

    /* ---------- images ---------- */

    /** Rotates a JPEG upright by its EXIF orientation (cameras store the turn in the file instead of the pixels). */
    private static function upright(\GdImage $im, string $bytes): \GdImage
    {
        if (!function_exists('exif_read_data')) {
            return $im;
        }
        $exif = @exif_read_data('data://image/jpeg;base64,' . base64_encode($bytes));
        $angle = [3 => 180, 6 => -90, 8 => 90][(int) ($exif['Orientation'] ?? 1)] ?? 0;
        $turned = $angle !== 0 ? imagerotate($im, $angle, 0) : false;

        return $turned !== false ? $turned : $im;
    }

    private static function fitted(\GdImage $im, int $side): \GdImage
    {
        [$w, $h] = [imagesx($im), imagesy($im)];
        if (max($w, $h) > $side) {
            $scale = $side / max($w, $h);
            $im = imagescale($im, max(1, (int) round($w * $scale)), max(1, (int) round($h * $scale))) ?: $im;
        }
        // a picture with transparency is flattened on white – JPEG has none
        $flat = imagecreatetruecolor(imagesx($im), imagesy($im));
        imagefill($flat, 0, 0, (int) imagecolorallocate($flat, 255, 255, 255));
        imagecopy($flat, $im, 0, 0, 0, 0, imagesx($im), imagesy($im));

        return $flat;
    }

    private static function writeJpeg(\GdImage $im, string $path): bool
    {
        return imagejpeg($im, $path, 85);
    }

    /**
     * The zip of a gallery, as bytes; null = over the limits (images or bytes), the visitor is told to download single images.
     *
     * @param list<array<string, mixed>> $images
     */
    public static function zip(array $gallery, array $images, string $size): ?string
    {
        $kind = $size === 'original' ? 'orig' : 'web';
        $total = 0;
        foreach ($images as $image) {
            $total += (int) @filesize(self::file($gallery, $image, $kind));
        }
        if (count($images) > self::ZIP_MAX_IMAGES || $total > self::ZIP_MAX_BYTES || !class_exists(\ZipArchive::class)) {
            return null;
        }
        $path = (string) tempnam(sys_get_temp_dir(), 'tlcg');
        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::OVERWRITE) !== true) {
            return null;
        }
        $used = [];
        foreach ($images as $image) {
            $base = trim((string) preg_replace('/[^A-Za-z0-9._ -]+/', '', (string) $image['name'])) ?: 'image';
            $ext = $kind === 'orig' ? (string) $image['ext'] : 'jpg';
            $name = $base . '.' . $ext;
            for ($n = 2; isset($used[$name]); $n++) {
                $name = $base . ' (' . $n . ').' . $ext;
            }
            $used[$name] = true;
            $zip->addFile(self::file($gallery, $image, $kind), $name);
            $zip->setCompressionName($name, \ZipArchive::CM_STORE); // pictures do not shrink
        }
        $zip->close();
        $bytes = (string) file_get_contents($path);
        @unlink($path);

        return $bytes;
    }
}
