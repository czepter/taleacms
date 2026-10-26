<?php

declare(strict_types=1);

namespace Talea\Core;

/** Title templates of the "Search engines" section: "%title% %sep% %sitename%" becomes the text of <title>. */
final class SeoTitle
{
    public const array VARIABLES = ['title', 'sep', 'sitename', 'tagline', 'category', 'page', 'year', 'excerpt'];

    /** @param array<string, string> $vars */
    public static function render(string $template, array $vars, bool $capitalize = false): string
    {
        $text = (string) preg_replace_callback('/%([a-z]+)%/', static fn (array $m): string => in_array($m[1], self::VARIABLES, true) ? trim((string) ($vars[$m[1]] ?? '')) : '', $template);
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        $sep = trim((string) ($vars['sep'] ?? ''));
        if ($sep !== '') {
            // separators doubled because the text between them is empty, and those left at the start or end
            $q = preg_quote($sep, '/');
            $text = (string) preg_replace('/(?:' . $q . ' )+(?=' . $q . ')/u', '', $text);
            $text = trim((string) preg_replace(['/^(?:' . $q . ' ?)+/u', '/(?: ?' . $q . ')+$/u'], '', $text));
        }
        if ($capitalize) {
            $text = mb_convert_case($text, MB_CASE_TITLE);
        }
        $text = mb_substr($text, 0, 200);

        return $text !== '' ? $text : (string) ($vars['title'] ?? '');
    }

    /**
     * The text of <title>. A row's own SEO title (or a page of kind 'other') is shown as written, with the site name added
     * unless it is in it; every other kind uses its template (setting seo_title_<kind>). Never empty: the site name is the fallback.
     *
     * @param array<string, string> $vars extra template variables of the page (category, excerpt, page)
     */
    public static function compose(Settings $s, string $kind, string $title, bool $ownTitle, array $vars = []): string
    {
        $siteName = $s->get('site_name');
        $vars += ['sep' => $s->get('seo_separator'), 'sitename' => $siteName, 'tagline' => trim((string) preg_replace('/\s+/u', ' ', $s->get('site_description'))), 'year' => date('Y')];
        if ($kind === 'other' || $ownTitle) {
            if (str_contains($title, '%')) { // variables the SEO tab inserts into an own title (%sitename%, %sep%, %year%)
                $title = self::render($title, ['title' => ''] + $vars);
            }
            $text = $title === '' ? $siteName : (str_contains(mb_strtolower($title), mb_strtolower($siteName)) ? $title : $title . ' – ' . $siteName);
        } else {
            $text = self::render($s->get('seo_title_' . $kind), ['title' => $title] + $vars, $s->bool('seo_capitalize'));
        }

        return $text !== '' ? $text : $siteName;
    }
}
