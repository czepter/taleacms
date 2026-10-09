<?php

declare(strict_types=1);

namespace Kaleta\Builder;

/**
 * Pre-publish check of a build for Claude (MCP) – the same rules as in the builder (image/stavitel.js, check()):
 * buttons without a link, images without a file or description and, for pages, the heading outline. Text contrast is
 * missing here: it needs the rendered page, and the builder checks it in the browser.
 */
final class Check
{
    public const int MAX = 12;

    /**
     * @param array<string, mixed> $build sanitized build
     * @param bool $headings check the heading outline (a page should have one h1 and not skip levels)
     * @param int $max at most this many findings (the site audit wants them all)
     * @return list<array{id: ?string, zprava: string}>
     */
    public static function builds(array $build, bool $headings, int $max = self::MAX): array
    {
        $findings = [];
        $outline = [];
        $tags = static fn (mixed $x): bool => is_string($x) && str_contains($x, '{{');
        $walk = static function (array $children) use (&$walk, &$findings, &$outline, $tags): void {
            foreach ($children as $p) {
                if (!is_array($p)) {
                    continue;
                }
                $o = is_array($p['content'] ?? null) ? $p['content'] : [];
                $id = isset($p['id']) ? (string) $p['id'] : null;
                $type = $p['type'] ?? '';
                if ($type === 'button' && in_array($o['link'] ?? '', ['', '#'], true)) {
                    $findings[] = ['id' => $id, 'right' => t('The button “%s” leads nowhere – add a link.', self::text($o['text'] ?? ''))];
                }
                if ($type === 'image' && ($o['src'] ?? '') === '') {
                    $findings[] = ['id' => $id, 'right' => t('No image selected – it will not appear on the site.')];
                } elseif ($type === 'image' && ($o['alt'] ?? '') === '' && !$tags($o['src'])) {
                    $findings[] = ['id' => $id, 'right' => t('The image has no description for blind visitors (alt).')];
                }
                // a heading with the p tag (big number, label) does not belong in the outline
                if ($type === 'heading' && preg_match('/^h([1-6])$/', (string) ($p['tag'] ?? 'h2'), $m)) {
                    $outline[] = [$id, (int) $m[1], self::text($o['text'] ?? '')];
                }
                if (is_array($p['children'] ?? null)) {
                    $walk($p['children']);
                }
            }
        };
        $walk(is_array($build['children'] ?? null) ? $build['children'] : []);
        if ($headings) {
            $h1 = array_values(array_filter($outline, static fn (array $n): bool => $n[1] === 1));
            if ($h1 === []) {
                $findings[] = ['id' => $outline[0][0] ?? null, 'right' => t('The page has no main heading (h1) – search engines and screen readers use it to tell what the page is about.')];
            }
            if (count($h1) > 1) {
                $findings[] = ['id' => $h1[1][0], 'right' => t('The page has more than one main heading (h1) – keep just one.')];
            }
            foreach ($outline as $i => $n) {
                if ($i > 0 && $n[1] > $outline[$i - 1][1] + 1) {
                    $findings[] = ['id' => $n[0], 'right' => t('The heading “%s” skips a level (h%d → h%d).', mb_substr($n[2], 0, 40), $outline[$i - 1][1], $n[1])];
                }
            }
        }

        return array_slice($findings, 0, $max);
    }

    private static function text(mixed $html): string
    {
        return trim(html_entity_decode(strip_tags((string) $html), ENT_QUOTES | ENT_HTML5));
    }
}
