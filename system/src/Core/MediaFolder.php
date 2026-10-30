<?php

declare(strict_types=1);

namespace Talea\Core;

/**
 * Where the uploads of the owner being served are kept (docs/specs/multisite.md, "Several owners"): owner 1 keeps `media/Y/m/…` as ever,
 * every further owner `media/<its public id>/Y/m/…`, so two owners never write the same file name and an owner's folder can be
 * measured, copied or removed on its own. The path stored in the `media` row and in content includes the folder, so a page never needs to
 * know it; only code that builds a new path (Images, Files, the SVG upload, the site icons) and code that recognises one (the patterns
 * below) does. The owner is set once per process by App::boot (a request or a cron child serves one owner).
 */
final class MediaFolder
{
    /** The optional owner folder inside a pattern, written after `media/` (a public id is a lower-case UUID). */
    public const string OWNER = '(?:[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/)?';

    private static string $owner = '';

    /** Sets the public id of the further owner this process serves ('' = owner 1, whose files sit directly in media/). */
    public static function useOwner(string $publicId): void
    {
        self::$owner = preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $publicId) === 1 ? $publicId : '';
    }

    /** The folder new uploads go to, relative to the web root: "media" or "media/<owner public id>". */
    public static function dir(): string
    {
        return self::dirOf(self::$owner);
    }

    /** The folder of the owner with this public id ('' = owner 1). */
    public static function dirOf(string $ownerPublicId): string
    {
        return $ownerPublicId === '' ? 'media' : 'media/' . $ownerPublicId;
    }

    /** A site icon (favicon sizes) of the owner being served. */
    public static function icon(int $size): string
    {
        return self::dir() . '/icon-' . $size . '.png';
    }

    /** Is this the name of a further owner's folder inside media/ (so that owner 1's archive and size leave it out)? */
    public static function isOwnerDir(string $name): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D', $name) === 1;
    }

    /** Bytes the files under a media folder take; owner 1's "media" leaves the folders of the further owners out. */
    public static function bytes(string $dir): int
    {
        if (!is_dir(TALEA_ROOT . '/' . $dir)) {
            return 0;
        }
        $bytes = 0;
        $skip = $dir === 'media';
        $files = new \RecursiveIteratorIterator(new \RecursiveCallbackFilterIterator(
            new \RecursiveDirectoryIterator(TALEA_ROOT . '/' . $dir, \FilesystemIterator::SKIP_DOTS),
            static fn (\SplFileInfo $f): bool => !($skip && $f->isDir() && self::isOwnerDir($f->getFilename())),
        ));
        foreach ($files as $file) {
            $bytes += $file->getSize();
        }

        return $bytes;
    }
}
