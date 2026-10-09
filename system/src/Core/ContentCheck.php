<?php

declare(strict_types=1);

namespace Kaleta\Core;

use Kaleta\Builder\Build;

/**
 * Content check of one page or news item (2.14): a short checklist computed on the server – the title and description
 * lengths search engines show in full, exactly one H1, headings that do not skip a level, the focus keyword in the
 * title, the description and the first paragraph, and images without alt. Shown in the editor side panel and returned
 * by MCP get_page (content_check). Read-only: it never changes the content.
 *
 * The focus keyword has no field of its own – the first words of the title stand in for it, so the check tells the
 * writer whether the description and the opening paragraph talk about what the title promises.
 */
final class ContentCheck
{
    public const int TITLE_MIN = 30;
    public const int TITLE_MAX = 60;
    public const int DESCRIPTION_MIN = 70;
    public const int DESCRIPTION_MAX = 160;

    /** How many words of the title make the focus keyword when none is given. */
    private const int KEYWORD_WORDS = 2;

    /**
     * @param array{title: string, description: string, html: string, title_is_h1?: bool, keyword?: string} $input
     *        title_is_h1 = the site prints the title as the H1 above the content (text pages, news items)
     * @return list<array{check: string, ok: bool, message: string}>
     */
    public static function run(array $input): array
    {
        $title = trim($input['title']);
        $description = trim($input['description']);
        $html = $input['html'];
        $titleIsH1 = $input['title_is_h1'] ?? false;
        $results = [];
        $add = function (string $check, bool $ok, string $message) use (&$results): void {
            $results[] = ['check' => $check, 'ok' => $ok, 'message' => $message];
        };

        $length = mb_strlen($title);
        $add('title_length', $length >= self::TITLE_MIN && $length <= self::TITLE_MAX, match (true) {
            $length === 0 => t('The page has no title.'),
            $length < self::TITLE_MIN => t('The title has %d characters – %d to %d is best for search results.', $length, self::TITLE_MIN, self::TITLE_MAX),
            $length > self::TITLE_MAX => t('The title has %d characters – search engines cut it after about %d.', $length, self::TITLE_MAX),
            default => t('The title has %d characters.', $length),
        });

        $length = mb_strlen($description);
        $add('description_length', $length >= self::DESCRIPTION_MIN && $length <= self::DESCRIPTION_MAX, match (true) {
            $length === 0 => t('No description for search engines – they make one up from the text.'),
            $length < self::DESCRIPTION_MIN => t('The description has %d characters – %d to %d is best.', $length, self::DESCRIPTION_MIN, self::DESCRIPTION_MAX),
            $length > self::DESCRIPTION_MAX => t('The description has %d characters – search engines cut it after about %d.', $length, self::DESCRIPTION_MAX),
            default => t('The description has %d characters.', $length),
        });

        $levels = self::headingLevels($html, $titleIsH1);
        $h1 = count(array_keys($levels, 1, true));
        $add('single_h1', $h1 === 1, match (true) {
            $h1 === 0 => t('The page has no H1 heading – the main heading tells readers and search engines what the page is about.'),
            $h1 === 1 => t('One H1 heading.'),
            default => t('The page has %d H1 headings – only the main one should be an H1, the others H2.', $h1),
        });

        $skip = null;
        foreach ($levels as $i => $level) {
            if ($i > 0 && $level > $levels[$i - 1] + 1) {
                $skip = 'H' . $levels[$i - 1] . ' → H' . $level;
                break;
            }
        }
        $add('heading_order', $skip === null, $skip === null ? t('Headings do not skip a level.') : t('Headings skip a level (%s) – screen readers and search engines read the outline.', $skip));

        $keyword = self::keyword((string) ($input['keyword'] ?? ''), $title);
        if ($keyword !== '') {
            $missing = [];
            if (!self::contains($title, $keyword)) {
                $missing[] = t('the title');
            }
            if (!self::contains($description, $keyword)) {
                $missing[] = t('the description');
            }
            if (!self::contains(self::firstParagraph($html), $keyword)) {
                $missing[] = t('the first paragraph');
            }
            $add('keyword', $missing === [], $missing === [] ? t('The focus keyword “%s” is in the title, the description and the first paragraph.', $keyword)
                : t('The focus keyword “%s” is missing from %s.', $keyword, implode(', ', $missing)));
        }

        preg_match_all('#<img\b[^>]*>#i', $html, $images);
        $withoutAlt = count(array_filter($images[0], fn (string $tag): bool => !preg_match('/\balt\s*=\s*("[^"]*[^\s"][^"]*"|\'[^\']*[^\s\'][^\']*\')/i', $tag)));
        $add('images_alt', $withoutAlt === 0, $withoutAlt === 0 ? t('Every image has a description (alt).') : t('%d images have no description for blind visitors (alt).', $withoutAlt));

        return $results;
    }

    /**
     * The check of a page row (ka_stranky): a build page is judged by the content of its draft (otherwise the published
     * build), a text page by its text with the title as the H1 the site prints above it.
     *
     * @param array<string, mixed> $page
     * @return list<array{check: string, ok: bool, message: string}>
     */
    public static function forPage(array $page): array
    {
        $build = Build::fromJson($page['build_draft'] ?? $page['build'] ?? null);

        return self::run([
            'title' => (string) ($page['seo_title'] !== '' ? $page['seo_title'] : $page['title']),
            'description' => (string) ($page['description'] ?? ''),
            'html' => $build !== null ? Build::asText($build) : (string) ($page['text'] ?? ''),
            'title_is_h1' => $build === null,
        ]);
    }

    /**
     * The check of a news item (ka_novinky): the title is the H1, the lead and the text are the content; without its own
     * description the site uses the beginning of the lead.
     *
     * @param array<string, mixed> $newsItem
     * @return list<array{check: string, ok: bool, message: string}>
     */
    public static function forNews(array $newsItem): array
    {
        $lead = (string) ($newsItem['intro'] ?? '');

        return self::run([
            'title' => (string) ($newsItem['seo_title'] !== '' ? $newsItem['seo_title'] : $newsItem['title']),
            'description' => (string) ($newsItem['seo_description'] !== '' ? $newsItem['seo_description'] : mb_strimwidth(trim(strip_tags($lead)), 0, 300, '…')),
            'html' => $lead . "\n" . (string) ($newsItem['text'] ?? ''),
            'title_is_h1' => true,
        ]);
    }

    /** @return list<int> heading levels in document order; the title first when the site prints it as the H1 */
    private static function headingLevels(string $html, bool $titleIsH1): array
    {
        preg_match_all('#<h([1-6])\b#i', $html, $m);
        $levels = array_map(intval(...), $m[1]);

        return $titleIsH1 ? [1, ...$levels] : $levels;
    }

    /**
     * The given keyword, otherwise the opening phrase of the title: up to the first KEYWORD_WORDS words of three or more
     * letters, with the short words between them kept ("Kuchyně na míru"), so the phrase can be found as written.
     */
    private static function keyword(string $given, string $title): string
    {
        if (trim($given) !== '') {
            return trim($given);
        }
        preg_match_all('/\p{L}[\p{L}\p{N}\'’-]*/u', $title, $m);
        $phrase = [];
        $significant = 0;
        foreach ($m[0] as $word) {
            $phrase[] = $word;
            $significant += mb_strlen($word) >= 3 ? 1 : 0;
            if ($significant >= self::KEYWORD_WORDS) {
                break;
            }
        }

        return $significant > 0 ? implode(' ', $phrase) : '';
    }

    /** Case- and diacritics-insensitive search for the keyword in a text (tags stripped). */
    private static function contains(string $text, string $keyword): bool
    {
        $normalize = fn (string $s): string => preg_replace('/\s+/u', ' ', mb_strtolower(remove_diacritics(html_entity_decode(strip_tags($s), ENT_QUOTES | ENT_HTML5)))) ?? '';

        return str_contains($normalize($text), $normalize($keyword));
    }

    /** The first paragraph of the content – a <p> with text, otherwise the first text run before a heading or block. */
    private static function firstParagraph(string $html): string
    {
        preg_match_all('#<p\b[^>]*>(.*?)</p>#is', $html, $m);
        foreach ($m[1] as $paragraph) {
            if (trim(strip_tags($paragraph)) !== '') {
                return $paragraph;
            }
        }
        $parts = preg_split('#<(?:h[1-6]|ul|ol|table|figure|blockquote|section|div)\b#i', $html, 2) ?: [$html];

        return trim(strip_tags($parts[0])) !== '' ? $parts[0] : (string) ($parts[1] ?? '');
    }
}
