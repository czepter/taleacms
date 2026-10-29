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
