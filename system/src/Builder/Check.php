<?php

declare(strict_types=1);

namespace Talea\Builder;

/**
 * Pre-publish check of a build for Claude (MCP) – the same rules as in the builder (image/builder.js, check()):
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
     * @param array<int, array<string, mixed>> $components published builds of the components by id (Components::builds), for their headings
     * @return list<array{id: ?string, message: string}>
     */
    public static function builds(array $build, bool $headings, int $max = self::MAX, array $components = []): array
    {
        $findings = [];
        $tags = static fn (mixed $x): bool => is_string($x) && str_contains($x, '{{');
        $walk = static function (array $children) use (&$walk, &$findings, $tags): void {
            foreach ($children as $p) {
                if (!is_array($p)) {
                    continue;
                }
                $o = is_array($p['content'] ?? null) ? $p['content'] : [];
                $id = isset($p['id']) ? (string) $p['id'] : null;
                $type = $p['type'] ?? '';
                if ($type === 'button' && in_array($o['link'] ?? '', ['', '#'], true)) {
                    $findings[] = ['id' => $id, 'message' => t('The button “%s” leads nowhere – add a link.', self::text($o['text'] ?? ''))];
                }
                if ($type === 'image' && ($o['src'] ?? '') === '') {
                    $findings[] = ['id' => $id, 'message' => t('No image selected – it will not appear on the site.')];
                } elseif ($type === 'image' && ($o['alt'] ?? '') === '' && !$tags($o['src'])) {
                    $findings[] = ['id' => $id, 'message' => t('The image has no description for blind visitors (alt).')];
                }
                if ($type === 'gallery') {
                    $photos = array_filter(is_array($o['photos'] ?? null) ? $o['photos'] : [], static fn (mixed $f): bool => is_array($f) && ($f['src'] ?? '') !== '');
                    if (($o['label'] ?? '') === '' && ($photos !== [] || ($o['folder'] ?? '') !== '')) {
                        $findings[] = ['id' => $id, 'message' => t('The gallery has no name for screen readers.')];
                    }
                    if (array_filter($photos, static fn (array $f): bool => ($f['alt'] ?? '') === '' && !$tags($f['src'])) !== []) {
                        $findings[] = ['id' => $id, 'message' => t('The gallery has photos without a description for blind visitors (alt).')];
                    }
                }
                if ($type === 'structured_data') {
                    // typed schema.org data (HF-11): a node without its required properties earns no rich result
                    [$node] = StructuredData::sanitize($o['data'] ?? null);
                    if ($node === null) {
                        $findings[] = ['id' => $id, 'message' => t('The structured data has no valid type – choose one or remove the element.')];
                    } elseif (($missing = StructuredData::missing($node)['required']) !== []) {
                        $findings[] = ['id' => $id, 'message' => t('The structured data “%s” is missing required properties: %s.', $node['type'], implode(', ', $missing))];
                    }
                }
                if (is_array($p['children'] ?? null)) {
                    $walk($p['children']);
                }
            }
        };
        $walk(is_array($build['children'] ?? null) ? $build['children'] : []);
        if ($headings) {
            $outline = self::outline($build, $components);
            $h1 = array_values(array_filter($outline, static fn (array $n): bool => $n[1] === 1));
            if ($h1 === []) {
                $findings[] = ['id' => $outline[0][0] ?? null, 'message' => t('The page has no main heading (h1) – search engines and screen readers use it to tell what the page is about.')];
            }
            if (count($h1) > 1) {
                $findings[] = ['id' => $h1[1][0], 'message' => t('The page has more than one main heading (h1) – keep just one.')];
            }
            foreach ($outline as $i => $n) {
                if ($i > 0 && $n[1] > $outline[$i - 1][1] + 1) {
                    $findings[] = ['id' => $n[0], 'message' => t('The heading “%s” skips a level (h%d → h%d).', mb_substr($n[2], 0, 40), $outline[$i - 1][1], $n[1])];
                }
            }
        }

        return array_slice($findings, 0, $max);
    }

    /**
     * Headings of a build in page order as [element id, level, text]. A component use adds the headings of its component under
     * its own id (a hero component usually carries the page's h1); a heading with the p tag (big number, label) is not in the outline.
     *
     * @param array<int, array<string, mixed>> $components published builds of the components by id
     * @param list<int> $nesting components already being walked (a component inside itself)
     * @return list<array{0: ?string, 1: int, 2: string}>
     */
    public static function outline(array $build, array $components, ?string $use = null, array $nesting = []): array
    {
        $outline = [];
        $walk = static function (array $children) use (&$walk, &$outline, $components, $use, $nesting): void {
            foreach ($children as $p) {
                if (!is_array($p)) {
                    continue;
                }
                $id = $use ?? (isset($p['id']) ? (string) $p['id'] : null);
                $type = $p['type'] ?? '';
                if ($type === 'heading' && preg_match('/^h([1-6])$/', (string) ($p['tag'] ?? 'h2'), $m)) {
                    $outline[] = [$id, (int) $m[1], self::text($p['content']['text'] ?? '')];
                }
                $c = $type === 'component' ? (int) ($p['content']['component'] ?? 0) : 0;
                if (isset($components[$c]) && !in_array($c, $nesting, true) && count($nesting) < Components::MAX_NESTING) {
                    array_push($outline, ...self::outline($components[$c], $components, $id, [...$nesting, $c]));
                }
                if (is_array($p['children'] ?? null)) {
                    $walk($p['children']);
                }
            }
        };
        $walk(is_array($build['children'] ?? null) ? $build['children'] : []);

        return $outline;
    }

    private static function text(mixed $html): string
    {
        return trim(html_entity_decode(strip_tags((string) $html), ENT_QUOTES | ENT_HTML5));
    }
}
