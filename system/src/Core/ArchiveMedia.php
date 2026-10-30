<?php

declare(strict_types=1);

namespace Talea\Core;

/**
 * The media files of a Talea archive (Core\SiteImport): where each one goes in media/, the content renamed after it, and
 * whether a file is what its extension says.
 */
final class ArchiveMedia
{
    /** Files that may come from the archive into media/ (images and the attachments Media accepts). */
    public const array EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'svg', 'ico'];

    /**
     * Where a file from the archive goes; null = it does not belong in media/ or its type is not allowed. The extensions are
     * written in lower case, as Media writes them (photo.JPG → photo.jpg): the header rules of the server then always match.
     */
    public static function path(string $name): ?string
    {
        if (!preg_match('#^media/(?:[A-Za-z0-9_-]+/)*[A-Za-z0-9_][A-Za-z0-9_.-]*$#', $name) || str_contains($name, '..')) {
            return null;
        }
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $base = basename($name);
        $dot = (int) strpos($base, '.');
        // photo.php.gif: only the last extension is allow-listed, so a script-like inner one is refused (a server that maps .php anywhere in the name would run it)
        foreach (array_slice(explode('.', $base), 1, -1) as $inner) {
            if (preg_match('/^(?:php\d*|phps|phtml|phar|pht|pl|py|cgi|sh|aspx?|jsp|html?|shtml?|shtm|stm|js)$/i', $inner)) {
                return null;
            }
        }

        return in_array($extension, [...self::EXTENSIONS, ...Files::FILE_EXTENSIONS], true) ? substr($name, 0, strlen($name) - strlen($base) + $dot) . strtolower(substr($base, $dot)) : null;
    }

    /** path() for an entry of this archive; a name whose lower-case twin is in the archive too is left out (the twin is the file). */
    public static function target(\ZipArchive $zip, string $name): ?string
    {
        $target = self::path($name);

        return $target === null || ($target !== $name && $zip->locateName($target) !== false) ? null : $target;
    }

    /**
     * The content points at the media under their names in the archive; a file saved under another name (path()) is
     * renamed in every text of the row, as a plain path and in its JSON-escaped form (media\/…) inside a build.
     *
     * @param array<mixed> $row
     * @param array<string, string> $renamed archive name => saved name
     * @return array<mixed>
     */
    public static function rename(array $row, array $renamed): array
    {
        if ($renamed === []) {
            return $row;
        }
        $pairs = [];
        foreach ($renamed as $from => $to) {
            $pairs[$from] = $to;
            $pairs[str_replace('/', '\\/', $from)] = str_replace('/', '\\/', $to);
        }
        array_walk_recursive($row, function (mixed &$value) use ($pairs): void {
            if (is_string($value) && str_contains($value, 'media')) {
                $value = strtr($value, $pairs);
            }
        });

        return $row;
    }

    /**
     * Where the files of an archive land in a media folder that already holds other files (a new site of an owner, Core\SiteImport):
     * in $dir, with the archive's own owner folder, if it had one, left out. A file whose name is taken (on disk or in the media table, $taken)
     * keeps its folder but gets $suffix after its stem, with all its variants (a-1.jpg, a-1-1200.jpg, a-1.webp), so they stay together and
     * the content can follow by name. Never overwrites, never reuses.
     *
     * @param list<string> $targets paths as path() gives them
     * @param callable(string): bool $taken is a file or media row at this path?
     * @return array<string, string> archive path => path in $dir
     */
    public static function intoFolder(array $targets, string $dir, callable $taken, string $suffix): array
    {
        $relative = fn (string $path): string => (string) preg_replace('#^media/' . MediaFolder::OWNER . '#', '', $path);
        $groups = [];
        foreach ($targets as $path) {
            $groups[self::stem($relative($path))][] = $path;
        }
        $map = [];
        foreach ($groups as $stem => $paths) {
            $clash = false;
            foreach ($paths as $path) {
                $clash = $clash || $taken($dir . '/' . $relative($path));
            }
            foreach ($paths as $path) {
                $rest = $relative($path);
                $map[$path] = $dir . '/' . ($clash ? $stem . '-' . $suffix . substr($rest, strlen($stem)) : $rest);
            }
        }

        return $map;
    }

    /** "2026/10/a-ab12cd-1200.jpg" => "2026/10/a-ab12cd": the folder and the name without variant and extensions. */
    private static function stem(string $relative): string
    {
        $base = basename($relative);
        $dot = strpos($base, '.');
        $name = (string) preg_replace('/-(?:1200|nahled)$/D', '', $dot === false ? $base : substr($base, 0, $dot));

        return (dirname($relative) === '.' ? '' : dirname($relative) . '/') . $name;
    }

    /**
     * Is the file what its extension says? An image must be a raster image (JPG, PNG, GIF, WebP, AVIF or ICO), any other
     * file must not be HTML, PHP, a script, SVG or a program (Files::forbiddenContent, as for an upload). SVG is cleaned
     * by Svg::sanitize instead.
     */
    public static function contentMatches(string $path, string $target): bool
    {
        if (in_array(strtolower(pathinfo($target, PATHINFO_EXTENSION)), self::EXTENSIONS, true)) {
            $info = @getimagesize($path);

            return $info !== false && in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF, IMAGETYPE_WEBP, IMAGETYPE_AVIF, IMAGETYPE_ICO], true);
        }

        return !Files::forbiddenContent($path);
    }
}
