<?php

declare(strict_types=1);

namespace Talea\Core;

use Talea\Builder\Build;
use Talea\Builder\SampleContent;

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

    /** @var array<string, string> group key => heading of the scored analysis; the renderer translates the headings with t() */
    public const array GROUPS = ['basics' => 'Basics', 'more' => 'More checks', 'title' => 'Title', 'readability' => 'Readability'];

    /** @var array<string, int> check => weight in the score */
    public const array WEIGHTS = [
        'kw_title' => 8, 'kw_description' => 6, 'kw_address' => 5, 'kw_start' => 6, 'kw_text' => 6, 'word_count' => 8,
        'title_length' => 5, 'description_length' => 5, 'single_h1' => 4,
        'kw_subheading' => 4, 'kw_alt' => 3, 'kw_density' => 4, 'address_length' => 2, 'links_out' => 3, 'links_out_follow' => 2,
        'links_in_text' => 4, 'kw_unique' => 4, 'heading_order' => 2, 'images_alt' => 3,
        'kw_title_start' => 3, 'title_number' => 1,
        'has_outline' => 2, 'short_paragraphs' => 3, 'media' => 3,
    ];

    private const array CHECK_GROUP = [
        'kw_title' => 'basics', 'kw_description' => 'basics', 'kw_address' => 'basics', 'kw_start' => 'basics', 'kw_text' => 'basics',
        'word_count' => 'basics', 'title_length' => 'basics', 'description_length' => 'basics', 'single_h1' => 'basics',
        'kw_subheading' => 'more', 'kw_alt' => 'more', 'kw_density' => 'more', 'address_length' => 'more', 'links_out' => 'more',
        'links_out_follow' => 'more', 'links_in_text' => 'more', 'kw_unique' => 'more', 'heading_order' => 'more', 'images_alt' => 'more',
        'kw_title_start' => 'title', 'title_number' => 'title',
        'has_outline' => 'readability', 'short_paragraphs' => 'readability', 'media' => 'readability',
    ];

    /** A word: letters, combining marks (vowelled Arabic, decomposed accents), digits, apostrophes and hyphens. */
    private const string WORD = '/\p{L}[\p{L}\p{M}\p{N}\'’-]*/u';

    private const int WORDS_MIN = 600;
    private const int ADDRESS_MAX = 75;
    private const int PARAGRAPH_MAX = 120;
    private const float DENSITY_MIN = 1.0;
    private const float DENSITY_MAX = 2.5;

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

        $samples = SampleContent::inText($html);
        $add('sample_content', $samples === [], $samples === [] ? t('No placeholders in square brackets left.')
            : t('Placeholders in square brackets are still in the text (%s) – fill them in or delete them before publishing.', implode(', ', array_slice($samples, 0, 3))));

        return $results;
    }

    /**
     * The scored analysis of the Search engines panel: run()'s checks plus the focus keyword checks, link, title and
     * readability checks, grouped by GROUPS. Pure – the caller (SeoScore::input) reads the content and the database.
     * The score is the share of the weights of the checks that pass; null without a focus keyword.
     *
     * @param array{title: string, description: string, html: string, title_is_h1?: bool, keywords?: list<string>, slug?: string, site_host?: string, keyword_taken?: bool, has_outline?: ?bool, videos?: list<string>} $input
     *        has_outline null = not applicable; videos = the addresses of video elements, which asText() prints as links
     * @return array{score: ?int, groups: array<string, list<array{check: string, ok: bool, message: string, why?: string}>>}
     */
    public static function analyse(array $input): array
    {
        $title = trim($input['title']);
        $description = trim($input['description']);
        $html = $input['html'];
        $slug = (string) ($input['slug'] ?? '');
        $siteHost = strtolower((string) ($input['site_host'] ?? ''));
        $keyword = trim((string) (($input['keywords'] ?? [])[0] ?? ''));
        $found = [];
        $add = function (string $check, bool $ok, string $message, ?string $why = null) use (&$found): void {
            $found[$check] = ['check' => $check, 'ok' => $ok, 'message' => $message] + ($why !== null ? ['why' => $why] : []);
        };

        foreach (self::run($input) as $row) {
            if (isset(self::CHECK_GROUP[$row['check']])) {
                $found[$row['check']] = $row;
            }
        }

        $plain = html_entity_decode((string) preg_replace('/<[^>]*>/', ' ', $html), ENT_QUOTES | ENT_HTML5);
        preg_match_all(self::WORD, $plain, $m);
        $words = $m[0];
        $wordCount = count($words);
        $add('word_count', $wordCount >= self::WORDS_MIN, t('The text has %d words.', $wordCount), t('Pages that rank well usually have %d or more.', self::WORDS_MIN));

        $slugLength = mb_strlen($slug);
        $add('address_length', $slugLength <= self::ADDRESS_MAX, t('The address has %d characters.', $slugLength), t('Short addresses are easier to read and share – up to %d characters.', self::ADDRESS_MAX));

        preg_match_all('#<a\b([^>]*)>#i', $html, $anchors);
        $external = $externalFollow = $internal = 0;
        $videos = $input['videos'] ?? [];
        foreach ($anchors[1] as $attributes) {
            if (!preg_match('/\bhref\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/i', $attributes, $h)) {
                continue;
            }
            $href = trim(html_entity_decode($h[1] !== '' ? $h[1] : ($h[2] ?? ''), ENT_QUOTES | ENT_HTML5));
            if (in_array($href, $videos, true)) {
                continue; // a video element: media, not a link
            }
            $host = strtolower((string) parse_url($href, PHP_URL_HOST));
            if ($host === '') { // a relative address is internal, an anchor or another scheme (mailto:, tel:) is no link
                $internal += $href !== '' && $href[0] !== '#' && !preg_match('/^[a-z][a-z0-9+.-]*:/i', $href) ? 1 : 0;
            } elseif ($host === $siteHost) {
                $internal++;
            } else {
                $external++;
                $externalFollow += preg_match('/\brel\s*=\s*["\'][^"\']*\bnofollow\b/i', $attributes) ? 0 : 1;
            }
        }
        $add('links_out', $external > 0, $external > 0 ? t('The text links to other websites.') : t('The text links to no other website.'), t('A link to a trustworthy source helps readers and search engines.'));
        if ($external > 0) { // without an outgoing link there is nothing to follow: not applicable
            $add('links_out_follow', $externalFollow > 0, $externalFollow > 0 ? t('At least one outgoing link is followed by search engines.') : t('No outgoing link is followed by search engines.'), t('Links marked nofollow do not pass on trust.'));
        }
        $add('links_in_text', $internal > 0, $internal > 0 ? t('The text links to other pages of this site.') : t('The text links to no other page of this site.'), t('Internal links help visitors and search engines find related pages.'));

        $add('title_number', (bool) preg_match('/\d/', $title), preg_match('/\d/', $title) ? t('The title contains a number.') : t('The title contains no number.'), t('Titles with a number often get more clicks.'));

        if (($input['has_outline'] ?? null) !== null) { // null: the content has no build to hold an outline element, not applicable
            $add('has_outline', $input['has_outline'], $input['has_outline'] ? t('The page has an outline.') : t('The page has no outline.'), t('Headings let readers jump to the part they need.'));
        }

        preg_match_all('#<p\b[^>]*>(.*?)</p>#is', $html, $paragraphs);
        $long = count(array_filter($paragraphs[1], fn (string $p): bool => preg_match_all(self::WORD, strip_tags($p)) > self::PARAGRAPH_MAX));
        $add('short_paragraphs', $long === 0, $long === 0 ? t('The paragraphs are short.') : t('%d paragraphs have more than %d words.', $long, self::PARAGRAPH_MAX), t('Short paragraphs are easier to read on a phone.'));

        $hasMedia = $videos !== [] || preg_match('#<(?:img|video|iframe)\b#i', $html);
        $add('media', $hasMedia, $hasMedia ? t('The page has an image or a video.') : t('The page has no image or video.'), t('Pictures and videos break up the text.'));

        if ($keyword !== '') {
            $has = fn (bool $ok, string $yes, string $no): string => $ok ? $yes : $no;
            $ok = self::contains($title, $keyword);
            $add('kw_title', $ok, $has($ok, t('The focus keyword is in the search title.'), t('The focus keyword is not in the search title.')));
            $ok = self::contains($description, $keyword);
            $add('kw_description', $ok, $has($ok, t('The focus keyword is in the description.'), t('The focus keyword is not in the description.')));
            $slugKeyword = slugify($keyword);
            // a subpage's address carries its parent's: every part of the path counts (services/wedding-photographer)
            $ok = $slugKeyword !== '' && str_contains('-' . strtr($slug, '/', '-') . '-', '-' . $slugKeyword . '-');
            $add('kw_address', $ok, $has($ok, t('The focus keyword is in the address.'), t('The focus keyword is not in the address.')));
            $start = implode(' ', array_slice($words, 0, max(1, (int) ceil($wordCount / 10))));
            $ok = $wordCount > 0 && self::contains($start, $keyword);
            $add('kw_start', $ok, $has($ok, t('The focus keyword is at the start of the text.'), t('The focus keyword is not at the start of the text.')), t('Mention it within the first tenth of the text.'));
            $ok = self::contains($html, $keyword);
            $add('kw_text', $ok, $has($ok, t('The focus keyword is in the text.'), t('The focus keyword is not in the text.')));

            preg_match_all('#<h[2-6]\b[^>]*>(.*?)</h[2-6]>#is', $html, $headings);
            $ok = self::contains(implode(' ', $headings[1]), $keyword);
            $add('kw_subheading', $ok, $has($ok, t('The focus keyword is in a subheading.'), t('The focus keyword is in no subheading.')));
            preg_match_all('#<img\b[^>]*\balt\s*=\s*(?:"([^"]*)"|\'([^\']*)\')#i', $html, $alts);
            $ok = self::contains(implode(' ', array_merge($alts[1], $alts[2])), $keyword);
            $add('kw_alt', $ok, $has($ok, t('The focus keyword is in an image description (alt).'), t('The focus keyword is in no image description (alt).')));

            $normal = self::normalize($keyword);
            $keywordWords = max(1, preg_match_all(self::WORD, $keyword));
            $hits = $normal === '' ? 0 : preg_match_all('/(?<![\p{L}\p{M}\p{N}\'’-])' . preg_quote($normal, '/') . '(?![\p{L}\p{M}\p{N}\'’-])/u', self::normalize($plain));
            $density = round($wordCount > 0 ? $hits * $keywordWords / $wordCount * 100 : 0.0, 1);
            $add('kw_density', $density >= self::DENSITY_MIN && $density <= self::DENSITY_MAX, t('Keyword density is %s%%.', number_format($density, 1, '.', '')), t('Between %s%% and %s%% reads naturally.', '1.0', '2.5'));

            $ok = str_starts_with(self::normalize($title), self::normalize($keyword));
            $add('kw_title_start', $ok, $has($ok, t('The search title starts with the focus keyword.'), t('The search title does not start with the focus keyword.')));
            $add('kw_unique', !($input['keyword_taken'] ?? false), ($input['keyword_taken'] ?? false) ? t('Another page already uses this focus keyword.') : t('No other page uses this focus keyword.'), t('Two pages for the same keyword compete with each other.'));
        }

        $groups = array_fill_keys(array_keys(self::GROUPS), []);
        $passed = $applicable = 0;
        foreach (self::CHECK_GROUP as $check => $group) {
            if (!isset($found[$check])) {
                continue;
            }
            $groups[$group][] = $found[$check];
            $applicable += self::WEIGHTS[$check];
            $passed += $found[$check]['ok'] ? self::WEIGHTS[$check] : 0;
        }

        return ['score' => $keyword === '' || $applicable === 0 ? null : (int) round(100 * $passed / $applicable), 'groups' => $groups];
    }

    /**
     * What Website mode marks on the preview of a build ("SEO on the page"): images without alt (one without a file shows
     * nothing, so it is no mark), the first H1 heading when it lacks the first focus keyword, every further H1. Pure; a broken
     * build has no marks.
     *
     * @param list<string> $keywords
     * @return array<string, string> element id => message
     */
    public static function marks(array $build, array $keywords): array
    {
        $keyword = trim((string) ($keywords[0] ?? ''));
        $marks = [];
        $h1 = 0;
        $walk = function (mixed $elements) use (&$walk, &$marks, &$h1, $keyword): void {
            foreach (is_array($elements) ? $elements : [] as $p) {
                if (!is_array($p) || !is_string($p['id'] ?? null)) {
                    continue;
                }
                $content = is_array($p['content'] ?? null) ? $p['content'] : [];
                if (($p['type'] ?? '') === 'image' && trim((string) ($content['src'] ?? '')) !== '' && trim((string) ($content['alt'] ?? '')) === '') {
                    $marks[$p['id']] = t('Image without description (alt)');
                } elseif (($p['type'] ?? '') === 'heading' && ($p['tag'] ?? '') === 'h1') {
                    if ($h1++ > 0) {
                        $marks[$p['id']] = t('A second main heading');
                    } elseif ($keyword !== '' && !self::contains((string) ($content['text'] ?? ''), $keyword)) {
                        $marks[$p['id']] = t('Main heading without the focus keyword “%s”', $keyword);
                    }
                }
                $walk($p['children'] ?? []);
            }
        };
        $walk($build['children'] ?? []);

        return $marks;
    }

    /**
     * The Page check feature (extension page_check) is on: the one condition for computing and showing the score, the
     * checks and the score filters of pages, news and collection items.
     */
    public static function enabled(Settings $settings): bool
    {
        return Extensions::isEnabled($settings, 'page_check');
    }

    /** The band of a score for its colour and label: good from 80, mid from 50, low below, none without a focus keyword. */
    public static function band(?int $score): string
    {
        return match (true) { $score === null => 'none', $score >= 80 => 'good', $score >= 50 => 'mid', default => 'low' };
    }

    /**
     * The check of a page row (tl_pages): a build page is judged by the content of its draft (otherwise the published
     * build), a text page by its text with the title as the H1 the site prints above it.
     *
     * @param array<string, mixed> $page
     * @return list<array{check: string, ok: bool, message: string}>
     */
    public static function forPage(array $page): array
    {
        return self::run(self::pageInput($page));
    }

    /**
     * run()'s input for a page row: the draft build, else the published build, else the text.
     *
     * @param array<string, mixed> $page
     * @return array{title: string, description: string, html: string, title_is_h1: bool, videos: list<string>}
     */
    public static function pageInput(array $page): array
    {
        $build = Build::fromJson($page['build_draft'] ?? $page['build'] ?? null);

        return [
            'title' => (string) ($page['seo_title'] !== '' ? $page['seo_title'] : $page['title']),
            'description' => (string) ($page['description'] ?? ''),
            'html' => $build !== null ? Build::asText($build) : (string) ($page['text'] ?? ''),
            'title_is_h1' => $build === null,
            'videos' => $build === null ? [] : array_values(array_map(fn (array $p): string => trim((string) ($p['content']['url'] ?? '')),
                Build::elementsOfType($build, fn (string $class): bool => $class === \Talea\Builder\Elements\Video::class))),
        ];
    }

    /**
     * The check of a news item (tl_news): the title is the H1, the lead and the text are the content; without its own
     * description the site uses the beginning of the lead.
     *
     * @param array<string, mixed> $newsItem
     * @return list<array{check: string, ok: bool, message: string}>
     */
    public static function forNews(array $newsItem): array
    {
        return self::run(self::newsInput($newsItem));
    }

    /**
     * run()'s input for a news row.
     *
     * @param array<string, mixed> $newsItem
     * @return array{title: string, description: string, html: string, title_is_h1: bool}
     */
    public static function newsInput(array $newsItem): array
    {
        $lead = (string) ($newsItem['intro'] ?? '');

        return [
            'title' => (string) ($newsItem['seo_title'] !== '' ? $newsItem['seo_title'] : $newsItem['title']),
            'description' => (string) ($newsItem['seo_description'] !== '' ? $newsItem['seo_description'] : Html::plain($lead, 300)),
            'html' => $lead . "\n" . (string) ($newsItem['text'] ?? ''),
            'title_is_h1' => true,
        ];
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
     * letters, with the short words between them kept ("Kitchens made to measure"), so the phrase can be found as written.
     */
    private static function keyword(string $given, string $title): string
    {
        if (trim($given) !== '') {
            return trim($given);
        }
        preg_match_all(self::WORD, $title, $m);
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
        return str_contains(self::normalize($text), self::normalize($keyword));
    }

    private static function normalize(string $text): string
    {
        return trim(preg_replace('/\s+/u', ' ', mb_strtolower(remove_diacritics(html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5)))) ?? '');
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
