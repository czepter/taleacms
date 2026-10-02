<?php

declare(strict_types=1);

namespace Kaleta\Core;

use Kaleta\Admin\Modules\Media;

/**
 * Media clean-up (2.14): files nothing on the site points at, images too big for the web, the same file uploaded twice
 * and images without a description for blind visitors. The site finds them and proposes; a person (or Claude, as a
 * reviewable batch) decides – nothing is deleted or rewritten on its own.
 *
 * The pure helpers (paths, unused, duplicates, isOversized) work on arrays, so tools/unit-tests.php can check them
 * without a database; report() puts them together over the media table.
 */
final class MediaHygiene
{
    /** An image over this many bytes or wider than this is "oversized": uploads are shrunk to Images::MAX_SIDE, imported and older files were not. */
    public const int OVERSIZED_BYTES = 2 * 1024 * 1024;
    public const int OVERSIZED_WIDTH = 2560;

    /** Files over this size are not hashed for duplicates – reading them would slow the screen down for a rare case. */
    private const int MAX_HASHED_BYTES = 64 * 1024 * 1024;

    /**
     * Media paths referenced in stored content – HTML, JSON with escaped slashes, with or without the site URL.
     * Variants made by Images (-1200, -nahled, .webp, .avif) count as references to the original file.
     *
     * @return list<string> e.g. media/2026/09/foto-ab12cd.jpg
     */
    public static function paths(string $content): array
    {
        preg_match_all('#media(?:\\\\?/)\d{4}(?:\\\\?/)\d{2}(?:\\\\?/)[A-Za-z0-9._-]+#', $content, $m);
        $paths = [];
        foreach ($m[0] as $path) {
            $path = str_replace('\\/', '/', $path);
            $path = (string) preg_replace('/(\.(?:jpe?g|png|gif|webp))\.(?:webp|avif)$/i', '$1', $path);
            $paths[(string) preg_replace('/-(?:1200|nahled)(\.[A-Za-z0-9]+)$/', '$1', $path)] = true;
        }

        return array_keys($paths);
    }

    /**
     * Which media rows no content refers to: neither by their path (or thumbnail path) nor through the news usage table.
     *
     * @param list<array<string, mixed>> $rows media rows with ido, obr_poloha, nahl_poloha
     * @param list<string> $referencedPaths output of paths() over all stored content
     * @param list<int> $usedIds ids used by news items (ka_media_pouziti)
     * @return list<array<string, mixed>> the unused rows, in the given order
     */
    public static function unused(array $rows, array $referencedPaths, array $usedIds = []): array
    {
        $referenced = array_fill_keys($referencedPaths, true);
        $used = array_fill_keys($usedIds, true);

        return array_values(array_filter($rows, fn (array $o): bool => !isset($used[(int) $o['ido']])
            && !isset($referenced[(string) $o['obr_poloha']]) && ((string) $o['nahl_poloha'] === '' || !isset($referenced[(string) $o['nahl_poloha']]))));
    }

    /**
     * Groups of rows with the same content (sha1), two or more in each; rows without a hash are left out.
     *
     * @param list<array<string, mixed>> $rows media rows with ido and sha1
     * @return list<list<array<string, mixed>>> groups ordered by their first (oldest) file, each oldest first
     */
    public static function duplicates(array $rows): array
    {
        $byHash = [];
        foreach ($rows as $o) {
            if (($o['sha1'] ?? '') !== '') {
                $byHash[$o['sha1']][] = $o;
            }
        }
        $groups = array_values(array_filter($byHash, fn (array $g): bool => count($g) > 1));
        foreach ($groups as &$group) {
            usort($group, fn (array $a, array $b): int => (int) $a['ido'] <=> (int) $b['ido']);
        }
        unset($group);
        usort($groups, fn (array $a, array $b): int => (int) $a[0]['ido'] <=> (int) $b[0]['ido']);

        return $groups;
    }

    /** A raster image over the size or width limit (SVG and attachments never are). */
    public static function isOversized(array $o): bool
    {
        return (string) $o['nahl_poloha'] !== '' && !str_ends_with((string) $o['obr_poloha'], '.svg')
            && ((int) $o['obr_vel'] > self::OVERSIZED_BYTES || (int) $o['obr_width'] > self::OVERSIZED_WIDTH);
    }

    /**
     * The whole clean-up over the media table. Where a file is used comes from Media::findUsagesElsewhere (builds, pages,
     * items, settings, pop-ups, newsletters…) and the news usage table.
     *
     * @return array{unused: list<array<string, mixed>>, oversized: list<array<string, mixed>>, duplicates: list<list<array<string, mixed>>>, without_alt: list<array<string, mixed>>, total: int}
     */
    public static function report(Db $db): array
    {
        $rows = $db->all('SELECT o.*, (SELECT COUNT(*) FROM {media_pouziti} p WHERE p.ido = o.ido) AS v_novinkach FROM {media} o ORDER BY o.ido');
        $elsewhere = Media::findUsagesElsewhere($db);
        foreach ($rows as &$o) {
            $o['kde'] = $elsewhere[(int) $o['ido']] ?? [];
            $o['pouzito'] = (int) $o['v_novinkach'] + count($o['kde']);
        }
        unset($o);
        $unused = array_values(array_filter($rows, fn (array $o): bool => $o['pouzito'] === 0));

        // duplicates: only files of a size that occurs more than once are read and hashed
        $bySize = [];
        foreach ($rows as $o) {
            $bySize[(int) $o['obr_vel']][] = $o;
        }
        $hashed = [];
        foreach ($bySize as $size => $same) {
            if (count($same) < 2 || $size > self::MAX_HASHED_BYTES) {
                continue;
            }
            foreach ($same as $o) {
                $file = KALETA_ROOT . '/' . $o['obr_poloha'];
                $hashed[] = $o + ['sha1' => is_file($file) ? (string) sha1_file($file) : ''];
            }
        }

        return [
            'unused' => $unused,
            'oversized' => array_values(array_filter($rows, self::isOversized(...))),
            'duplicates' => self::duplicates($hashed),
            'without_alt' => array_values(array_filter($rows, fn (array $o): bool => (string) $o['nahl_poloha'] !== '' && trim((string) $o['nazev']) === '')),
            'total' => count($rows),
        ];
    }
}
