<?php

declare(strict_types=1);

namespace Talea\Builder;

/**
 * Sample content (#54): Talea never invents content. A starter section brings placeholders in square brackets
 * ("[Customer's name]", "[number]", "[price]") for what only the owner can supply; the pre-publish check (MCP, the
 * builder), the site audit (kind sample) and the page check report them until they are replaced. Releases before the
 * placeholders wrote realistic names, reviews and prices – DEMO lists those, so pages built then are reported too.
 * Nothing is stored in the build: rewrite the text and the finding is gone. It only ever warns, never blocks.
 */
final class SampleContent
{
    /** A placeholder: a short text in square brackets with a letter in it – [number], [Customer's name]. The builder repeats it (image/builder.js). */
    public const string PLACEHOLDER = '/\[[^\[\]{}<>\n]{0,60}\p{L}[^\[\]{}<>\n]{0,60}\]/u';

    /** The invented reviews, names, prices and figures of earlier starter sections – whole field values, compared case-insensitively. */
    private const array DEMO = [
        'Everything went exactly as agreed, on time and on budget. We will gladly come back.', 'David Clarke', 'Managing Director, Clarke Ltd',
        'We appreciate the fast communication and that they always recommended the best solution.', 'Emma Johnson', 'Operations Director',
        'The best company we have ever worked with. They kept every deadline and the result exceeded our expectations.', 'Sarah Miller', 'café owner',
        'Fast, clean and for the agreed price.', 'Helpful people, great communication.', 'I recommend them to all my friends.', 'Tom K.', 'Jane P.', 'Paul S.',
        'Working with them was quick and hassle-free. Recommended.', 'Jane Doe', 'out of 5 · 120 reviews',
        'John Smith', 'Emily Smith', 'Peter Brown', 'from £40', 'from £190', '£120', '£290',
    ];

    /**
     * @param array<string, mixed> $build a sanitized build
     * @return list<array{id: ?string, text: string}> the elements with sample content in page order; text = what was recognised
     */
    public static function find(array $build): array
    {
        $demo = array_map(self::normalize(...), self::DEMO);
        $found = [];
        $walk = static function (array $children) use (&$walk, &$found, $demo): void {
            foreach ($children as $p) {
                if (!is_array($p)) {
                    continue;
                }
                $hit = null;
                if (is_array($p['content'] ?? null)) {
                    array_walk_recursive($p['content'], static function (mixed $v) use (&$hit, $demo): void {
                        if ($hit !== null || !is_string($v)) {
                            return;
                        }
                        $plain = trim(html_entity_decode(strip_tags($v), ENT_QUOTES | ENT_HTML5));
                        if (preg_match(self::PLACEHOLDER, $plain, $m) === 1) {
                            $hit = $m[0];
                        } elseif ($plain !== '' && in_array(self::normalize($plain), $demo, true)) {
                            $hit = mb_strimwidth($plain, 0, 50, '…');
                        }
                    });
                }
                if ($hit !== null) {
                    $found[] = ['id' => isset($p['id']) ? (string) $p['id'] : null, 'text' => mb_strimwidth($hit, 0, 130, '…')];
                }
                if (is_array($p['children'] ?? null)) {
                    $walk($p['children']);
                }
            }
        };
        $walk(is_array($build['children'] ?? null) ? $build['children'] : []);

        return $found;
    }

    /** @return list<string> the placeholders in square brackets in a text or HTML (a text page, news, a collection item) */
    public static function inText(string $html): array
    {
        preg_match_all(self::PLACEHOLDER, html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5), $m);

        return array_values(array_unique($m[0]));
    }

    /** One line for a page: what it still shows and what to do. */
    public static function summary(array $found): string
    {
        $texts = array_slice(array_unique(array_column($found, 'text')), 0, 3);

        return t('Sample content from the starter site is still here (%d: %s) – replace it with your own or delete it before publishing. Invented reviews and numbers can mislead visitors.', count($found), implode(', ', $texts));
    }

    /**
     * One finding per element for the pre-publish check.
     *
     * @param array<string, mixed> $build a sanitized build
     * @return list<array{id: ?string, message: string}>
     */
    public static function findings(array $build): array
    {
        return array_map(static fn (array $f): array => ['id' => $f['id'], 'message' => t('Sample content “%s” – replace it with your own or delete it.', $f['text'])], self::find($build));
    }

    private static function normalize(string $text): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $text)));
    }
}
