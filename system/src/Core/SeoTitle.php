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
}
