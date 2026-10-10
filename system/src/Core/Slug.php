<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * A free slug in the URL (seo_link of a page, news item, category, collection item, popup slug): when the base is taken,
 * it gets a sequence number (o-nas, o-nas-2, o-nas-3…). The base is shortened so that it fits in the column with the number.
 */
final class Slug
{
    /** @param callable(string): bool $isTaken */
    public static function makeUnique(string $base, callable $isTaken, int $max = 160): string
    {
        $url = mb_substr($base, 0, $max);
        for ($i = 2; $isTaken($url); $i++) {
            if ($i > 10000) {
                throw new \RuntimeException('No free address was found.');
            }
            $extension = '-' . $i;
            $url = rtrim(mb_substr($base, 0, $max - strlen($extension)), '-') . $extension;
        }

        return $url;
    }
}
