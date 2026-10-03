<?php

declare(strict_types=1);

namespace Kaleta\Import;

/**
 * The registry of structured importers (Import\Source). A new system is added here and nowhere else: the admin form,
 * the file list and the batch runner read this list.
 */
final class Sources
{
    /** @var list<class-string<Source>> in the order the admin offers them */
    public const array ALL = [Ghost::class, Blogger::class];

    /** @return array<string, class-string<Source>> key => class */
    public static function all(): array
    {
        $all = [];
        foreach (self::ALL as $class) {
            $all[$class::key()] = $class;
        }

        return $all;
    }

    /** @return class-string<Source>|null */
    public static function byKey(string $key): ?string
    {
        return self::all()[$key] ?? null;
    }

    /** @throws \InvalidArgumentException for an unknown system */
    public static function open(string $key, string $path, string $siteUrl = ''): Source
    {
        $class = self::byKey($key) ?? throw new \InvalidArgumentException('Unknown import source: ' . $key);

        return new $class($path, $siteUrl);
    }

    /** The system a file in storage/import/sources belongs to, from its name (<key>-<name>.<ext>); null = not ours. */
    public static function keyOfFile(string $file): ?string
    {
        $key = (string) strtok(basename($file), '-');
        $class = self::byKey($key);

        return $class !== null && in_array(strtolower(pathinfo($file, PATHINFO_EXTENSION)), $class::extensions(), true) ? $key : null;
    }
}
