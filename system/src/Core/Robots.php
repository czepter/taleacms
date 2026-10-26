<?php

declare(strict_types=1);

namespace Talea\Core;

/**
 * Robots directives of a page, news item or collection item (the "Search engines" section). The stored value is a
 * normalised comma list in a fixed order; normalize() is the only writer of it. meta() builds the content of
 * <meta name="robots"> from the item's own value and the site-wide one, canonical() accepts only an absolute http(s) address.
 */
final class Robots
{
    public const array FLAGS = ['nofollow', 'noarchive', 'noimageindex', 'nosnippet'];

    public const array IMAGE_PREVIEW = ['large', 'standard', 'none'];

    /** Directives with a number (an integer of at least -1); max-image-preview takes one of IMAGE_PREVIEW. */
    private const array LIMITS = ['max-snippet', 'max-video-preview', 'max-image-preview'];

    /** Drops unknown and duplicate tokens and puts the rest in the canonical order. A string is split at commas and spaces. */
    public static function normalize(string|array $input): string
    {
        [$flags, $limits] = self::parse($input);
        return implode(',', self::list($flags, $limits));
    }

    /** The content of <meta name="robots">, or '' when nothing differs from the default (indexed, no limits). */
    public static function meta(bool $noindex, string $pageRobots, string $siteRobots): string
    {
        [$pageFlags, $pageLimits] = self::parse($pageRobots);
        [$siteFlags, $siteLimits] = self::parse($siteRobots);
        $parts = self::list(array_merge($pageFlags, $siteFlags), $pageLimits + $siteLimits);
        if ($parts === [] && !$noindex) {
            return '';
        }
        return implode(', ', [$noindex ? 'noindex' : 'index', ...$parts]);
    }

    /** The address when it is absolute http(s) and at most 500 characters, otherwise ''. */
    public static function canonical(string $url): string
    {
        $url = trim($url);
        if ($url === '' || strlen($url) > 500 || !preg_match('~^https?://[^\s/?#]+~i', $url) || preg_match('~\s~', $url)) {
            return '';
        }
        return $url;
    }

    /** @return array{0: list<string>, 1: array<string, string>} flags and limit values (the first of a kind wins) */
    private static function parse(string|array $input): array
    {
        $tokens = is_array($input) ? $input : preg_split('~[,\s]+~', $input, -1, PREG_SPLIT_NO_EMPTY);
        $flags = [];
        $limits = [];
        foreach ($tokens as $token) {
            $token = strtolower(trim((string) $token));
            if (in_array($token, self::FLAGS, true)) {
                $flags[] = $token;
                continue;
            }
            [$name, $value] = array_pad(explode(':', $token, 2), 2, '');
            if (isset($limits[$name]) || !in_array($name, self::LIMITS, true)) {
                continue;
            }
            $valid = $name === 'max-image-preview'
                ? in_array($value, self::IMAGE_PREVIEW, true)
                : preg_match('~^-?\d{1,9}$~', $value) === 1 && (int) $value >= -1;
            if ($valid) {
                $limits[$name] = (string) ($name === 'max-image-preview' ? $value : (int) $value);
            }
        }
        return [$flags, $limits];
    }

    /** @param list<string> $flags @param array<string, string> $limits */
    private static function list(array $flags, array $limits): array
    {
        $parts = array_values(array_filter(self::FLAGS, static fn(string $flag): bool => in_array($flag, $flags, true)));
        foreach (self::LIMITS as $name) {
            if (isset($limits[$name])) {
                $parts[] = $name . ':' . $limits[$name];
            }
        }
        return $parts;
    }
}
