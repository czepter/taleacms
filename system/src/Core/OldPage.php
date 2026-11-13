<?php

declare(strict_types=1);

namespace Talea\Core;

use Dom\Element;
use Dom\HTMLDocument;
use Talea\Builder\HtmlWidgets;

/**
 * One page of an old site, read to rebuild its layout here (MCP read_old_page): the page cut into sections – the header, each top-level
 * block of the main content, the footer – each with its HTML, background, text colour and fonts, plus the page's palette, fonts and CSS
 * custom properties and the widgets found. The stylesheets (a few, cut to a size) are read by a linear scanner with a time budget, not by
 * a browser: colours and fonts a section's own classes name are found, what only a script or a cascade decides is not.
 *
 * Everything on the page is someone else's text: data for the user, never instructions (Mcp\Untrusted).
 */
final class OldPage
{
    public const int MAX_SECTIONS = 40;
    public const int SECTION_BYTES = 12_000;
    public const int ONE_SECTION_BYTES = 60_000;
    public const int TOTAL_BYTES = 100_000;
    public const int STYLESHEETS = 6;
    public const int STYLESHEET_BYTES = 400 * 1024;
    public const int PAGE_BYTES = 5_000_000;
    public const float CSS_SECONDS = 3.0;
    /** The time of the whole reading (the page and its stylesheets); what is left unread by then counts as not read – the call stays well under the request limit. */
    public const float READ_SECONDS = 25.0;
    public const int MAX_RULES = 5000;

    private const string BLOCK_NAMES = 'main, [role="main"], #content, #main';

    /** Attributes of the old page's markup that stay in a section's HTML. */
    private const array ATTRIBUTES = ['class', 'id', 'href', 'src', 'alt', 'title', 'role', 'style', 'type', 'name', 'placeholder', 'for', 'width', 'height', 'datetime'];

    public function __construct(private readonly ImageDownloader $downloader)
    {
    }

    /**
     * Downloads the page and its first stylesheets and analyses them; stylesheets not started within $seconds of the start are left unread.
     *
     * @return array<string, mixed> see analyse()
     */
    public function read(string $url, ?int $section = null, string $format = 'sections', float $seconds = self::READ_SECONDS): array
    {
        $deadline = microtime(true) + $seconds;
        $html = $this->downloader->download($url, false);
        if ($html === '' || strlen($html) > self::PAGE_BYTES) {
            throw new \RuntimeException($html === '' ? 'The page is empty.' : 'The page is too large.');
        }
        $doc = HTMLDocument::createFromString($html, LIBXML_NOERROR);
        $stylesheets = [];
        $notRead = 0;
        $fonts = [];
        foreach ($doc->querySelectorAll('link[rel~="stylesheet"][href]') as $link) {
            $href = WebImport::absolute(trim((string) $link->getAttribute('href')), $url);
            if (!preg_match('#^https?://#i', $href)) {
                continue;
            }
            if (preg_match('#^https?://fonts\.googleapis\.com/#i', $href)) {
                preg_match_all('/family=([^&:;]+)/', rawurldecode($href), $m); // the names only: the fonts are self-hosted here, never loaded from Google
                array_push($fonts, ...array_map(fn (string $f): string => str_replace('+', ' ', $f), $m[1]));
                continue;
            }
            if (count($stylesheets) + $notRead >= self::STYLESHEETS) {
                break;
            }
            if (microtime(true) >= $deadline) {
                $notRead++;
                continue;
            }
            try {
                $stylesheets[] = substr($this->downloader->download($href, false), 0, self::STYLESHEET_BYTES);
            } catch (\RuntimeException) {
                $notRead++;
            }
        }
        $page = self::analyse($html, $url, $stylesheets, $notRead, $section, $format);
        $page['fonts'] = array_slice(array_values(array_unique([...$page['fonts'], ...$fonts])), 0, 6);

        return $page;
    }

    /**
     * The sections and the look of a page (pure: no network).
     *
     * @param list<string> $stylesheets the CSS of the page's stylesheets
     * @return array<string, mixed> url, title, description, language, share_image, sections, more_sections, colors, fonts, css_variables, widgets, stylesheets
     */
    public static function analyse(string $html, string $url, array $stylesheets = [], int $notRead = 0, ?int $only = null, string $format = 'sections'): array
    {
        $doc = HTMLDocument::createFromString($html, LIBXML_NOERROR);
        $css = implode("\n", $stylesheets);
        foreach ($doc->querySelectorAll('style') as $style) {
            $css .= "\n" . $style->textContent;
        }
        $rules = self::rules($css, microtime(true) + self::CSS_SECONDS);
        $variables = self::variables($rules);
        $meta = fn (string $selector): string => trim((string) $doc->querySelector($selector)?->getAttribute('content'));
        $sections = [];
        $kinds = [];
        $total = 0;
        $blocks = self::blocks($doc);
        foreach (array_slice($blocks, 0, self::MAX_SECTIONS) as $index => [$role, $el]) {
            $widgets = self::widgetKinds($el);
            foreach ($widgets as $kind => $n) {
                $kinds[$kind] = ($kinds[$kind] ?? 0) + $n;
            }
            if ($only !== null && $only !== $index) {
                continue;
            }
            $look = self::look($el, $rules, $variables);
            $limit = $only !== null ? self::ONE_SECTION_BYTES : self::SECTION_BYTES;
            $cleaned = $format === 'semantic' ? SemanticHtml::section($el, $url, $role) : self::cleaned($el, $url);
            $truncated = strlen($cleaned) > $limit || $total + min(strlen($cleaned), $limit) > self::TOTAL_BYTES;
            $cleaned = $total >= self::TOTAL_BYTES ? '' : self::cut($cleaned, min($limit, self::TOTAL_BYTES - $total));
            $total += strlen($cleaned);
            $sections[] = ['index' => $index, 'role' => $role, 'tag' => strtolower($el->localName), 'heading' => mb_substr(trim((string) preg_replace('/\s+/u', ' ', (string) $el->querySelector('h1, h2, h3')?->textContent)), 0, 160),
                'html' => $cleaned, 'truncated' => $truncated] + $look + ['widgets' => array_keys($widgets)];
        }
        [$colors, $fonts] = self::palette($rules, $variables);

        return [
            'url' => $url, 'title' => trim((string) preg_replace('/\s+/u', ' ', (string) $doc->querySelector('title')?->textContent)), 'description' => $meta('meta[name="description"]'),
            'language' => trim((string) $doc->documentElement?->getAttribute('lang')), 'share_image' => ($og = $meta('meta[property="og:image"]')) === '' ? '' : WebImport::absolute($og, $url),
            'sections' => $sections, 'more_sections' => max(0, count($blocks) - self::MAX_SECTIONS), 'colors' => $colors, 'fonts' => $fonts,
            'css_variables' => array_slice($variables, 0, 40, true), 'widgets' => $kinds, 'stylesheets' => ['read' => count($stylesheets), 'not_read' => $notRead],
        ];
    }

    /**
     * The sections: the header, each top-level block of the main content, the footer.
     *
     * @return list<array{0: string, 1: Element}>
     */
    private static function blocks(HTMLDocument $doc): array
    {
        $main = $doc->querySelector(self::BLOCK_NAMES) ?? $doc->body;
        if ($main === null) {
            return [];
        }
        $blocks = [];
        foreach ($doc->querySelectorAll('header, [role="banner"]') as $header) {
            if (!$main->contains($header) && $header !== $main) {
                $blocks[] = ['header', $header];
                break;
            }
        }
        // a wrapper with one element child is descended (a #page > .wrap > …), at most 12 levels
        for ($i = 0; $i < 12 && $main->children->length === 1 && !in_array(strtolower($main->children->item(0)->localName), ['section', 'article'], true); $i++) {
            $main = $main->children->item(0);
        }
        foreach ($main->children as $child) {
            $tag = strtolower($child->localName);
            if (in_array($tag, ['script', 'style', 'noscript', 'template', 'link', 'meta', 'header', 'footer'], true) && $main->localName !== 'body') {
                continue;
            }
            if (in_array($tag, ['script', 'style', 'noscript', 'template', 'link', 'meta'], true) || (trim($child->textContent) === '' && $child->querySelector('img, iframe, video, form, svg, picture') === null)) {
                continue;
            }
            if ($main->localName === 'body' && ($tag === 'header' || $tag === 'footer')) {
                continue; // taken as the header and the footer
            }
            $blocks[] = ['section', $child];
        }
        $footers = iterator_to_array($doc->querySelectorAll('footer, [role="contentinfo"]'));
        foreach (array_reverse($footers) as $footer) {
            if (!$main->contains($footer)) {
                $blocks[] = ['footer', $footer];
                break;
            }
        }

        return $blocks;
    }

    /**
     * The widgets a block holds, by kind (found on a copy – the page itself is not changed).
     *
     * @return array<string, int>
     */
    private static function widgetKinds(Element $el): array
    {
        $copy = $el->cloneNode(true);
        $kinds = [];
        foreach (HtmlWidgets::extract($copy) as $widget) {
            $kinds[$widget['kind']] = ($kinds[$widget['kind']] ?? 0) + 1;
        }

        return $kinds;
    }

    /**
     * The block's HTML as the old page wrote it, without scripts, styles and comments, with the attributes that carry meaning and
     * absolute addresses.
     */
    private static function cleaned(Element $el, string $url): string
    {
        $copy = $el->cloneNode(true);
        foreach (iterator_to_array($copy->querySelectorAll('script, style, noscript, template, link, meta')) as $drop) {
            $drop->remove();
        }
        foreach (iterator_to_array($copy->querySelectorAll('*')) as $node) {
            foreach (iterator_to_array($node->attributes) as $attribute) {
                $name = strtolower($attribute->name);
                if (!in_array($name, self::ATTRIBUTES, true) && !str_starts_with($name, 'aria-')) {
                    $node->removeAttribute($attribute->name);
                } elseif (in_array($name, ['href', 'src'], true)) {
                    $node->setAttribute($name, WebImport::absolute(trim($attribute->value), $url) ?: $attribute->value);
                }
            }
        }
        $html = $copy instanceof Element ? Html::outer($copy) : '';

        return trim((string) preg_replace(['/<!--.*?-->/s', '/\s+/u'], ['', ' '], $html));
    }

    /** HTML cut to a size at the end of a tag. */
    private static function cut(string $html, int $bytes): string
    {
        if (strlen($html) <= $bytes) {
            return $html;
        }
        $part = substr($html, 0, max(0, $bytes));

        return substr($part, 0, (int) strrpos($part, '>') + 1);
    }

    /**
     * What a block looks like by its inline style and the rules of its own classes and id: background, text colour, fonts.
     *
     * @param list<array{0: string, 1: string, 2: bool}> $rules
     * @param array<string, string> $variables
     * @return array{background: string, color: string, fonts: list<string>}
     */
    private static function look(Element $el, array $rules, array $variables): array
    {
        $own = [];
        foreach (preg_split('/\s+/', trim((string) $el->getAttribute('class'))) ?: [] as $class) {
            $class !== '' && $own['.' . $class] = true;
        }
        if (($id = (string) $el->getAttribute('id')) !== '') {
            $own['#' . $id] = true;
        }
        $declarations = [];
        foreach ($rules as [$selector, $body, $conditional]) {
            if ($conditional) {
                continue; // a rule for some screens only
            }
            foreach (explode(',', $selector) as $one) {
                $last = preg_split('/[\s>+~]+/', trim($one)) ?: [];
                preg_match_all('/[.#][A-Za-z0-9_-]+/', (string) end($last), $m);
                if (array_intersect($m[0], array_keys($own)) !== []) {
                    $declarations[] = $body;
                    break;
                }
            }
        }
        $declarations[] = (string) $el->getAttribute('style'); // the inline style wins
        $look = ['background' => '', 'color' => '', 'fonts' => []];
        foreach ($declarations as $body) {
            foreach (self::declarations($body) as [$property, $value]) {
                if (in_array($property, ['background', 'background-color'], true) && ($c = self::colors($value, $variables)) !== []) {
                    $look['background'] = $c[0];
                } elseif ($property === 'color' && ($c = self::colors($value, $variables)) !== []) {
                    $look['color'] = $c[0];
                } elseif ($property === 'font-family' && ($f = self::family($value)) !== '') {
                    $look['fonts'][] = $f;
                }
            }
        }
        $look['fonts'] = array_values(array_unique($look['fonts']));

        return $look;
    }

    /**
     * The colours and fonts of a page, the most used first.
     *
     * @param list<array{0: string, 1: string, 2: bool}> $rules
     * @param array<string, string> $variables
     * @return array{0: list<string>, 1: list<string>}
     */
    private static function palette(array $rules, array $variables): array
    {
        $colors = [];
        $fonts = [];
        foreach ($rules as [, $body]) {
            foreach (self::declarations($body) as [$property, $value]) {
                if (preg_match('/^(color|background(-color)?|border(-[a-z]+)*(-color)?|fill|outline-color)$/', $property) === 1) {
                    foreach (self::colors($value, $variables) as $c) {
                        $colors[$c] = ($colors[$c] ?? 0) + 1;
                    }
                } elseif ($property === 'font-family' && ($f = self::family($value)) !== '') {
                    $fonts[$f] = ($fonts[$f] ?? 0) + 1;
                }
            }
        }
        arsort($colors);
        arsort($fonts);

        return [array_slice(array_keys($colors), 0, 12), array_slice(array_keys($fonts), 0, 6)];
    }

    /** The first family of a font-family value that is a name (not serif, inherit, a variable). */
    private static function family(string $value): string
    {
        foreach (explode(',', $value) as $family) {
            $family = trim($family, " \t\"'");
            if ($family !== '' && !preg_match('/^(serif|sans-serif|monospace|cursive|fantasy|system-ui|ui-[a-z-]+|inherit|initial|unset|-apple-system|blinkmacsystemfont)$/i', $family) && !str_contains($family, 'var(')) {
                return mb_substr($family, 0, 60);
            }
        }

        return '';
    }

    /**
     * The custom properties of :root, html and body.
     *
     * @param list<array{0: string, 1: string, 2: bool}> $rules
     * @return array<string, string>
     */
    private static function variables(array $rules): array
    {
        $variables = [];
        foreach ($rules as [$selector, $body]) {
            if (preg_match('/^\s*(:root|html|body)\s*$/i', $selector) !== 1) {
                continue;
            }
            foreach (self::declarations($body) as [$property, $value]) {
                if (str_starts_with($property, '--') && count($variables) < 200) {
                    $variables[mb_substr($property, 0, 60)] = mb_substr($value, 0, 100);
                }
            }
        }

        return $variables;
    }

    /**
     * The declarations of a rule body: [property (lower case), value].
     *
     * @return list<array{0: string, 1: string}>
     */
    private static function declarations(string $body): array
    {
        $out = [];
        foreach (preg_split('/;(?![^(]*\))/', $body) ?: [] as $declaration) {
            if (str_contains($declaration, ':')) {
                [$property, $value] = array_map('trim', explode(':', $declaration, 2));
                $out[] = [str_starts_with($property, '--') ? $property : strtolower($property), trim((string) preg_replace('/\s*!important$/i', '', $value))];
            }
        }

        return $out;
    }

    /**
     * The colours in a value as #rrggbb (a variable is resolved one level).
     *
     * @param array<string, string> $variables
     * @return list<string>
     */
    private static function colors(string $value, array $variables): array
    {
        $value = (string) preg_replace_callback('/var\(\s*(--[\w-]+)[^)]*\)/', fn (array $m): string => $variables[$m[1]] ?? '', $value);
        $found = [];
        preg_match_all('/#[0-9a-f]{8}\b|#[0-9a-f]{6}\b|#[0-9a-f]{3,4}\b|rgba?\([^)]*\)|hsla?\([^)]*\)/i', $value, $m);
        foreach ($m[0] as $color) {
            if (($hex = self::hex($color)) !== null) {
                $found[] = $hex;
            }
        }

        return $found;
    }

    /** A colour written as hex, rgb() or hsl() as #rrggbb; null for a transparent or unreadable one. */
    private static function hex(string $color): ?string
    {
        $color = strtolower(trim($color));
        if ($color[0] === '#') {
            $h = substr($color, 1);
            if (strlen($h) === 3 || strlen($h) === 4) {
                $h = $h[0] . $h[0] . $h[1] . $h[1] . $h[2] . $h[2];
            }

            return strlen($h) >= 6 && ctype_xdigit($h) ? '#' . substr($h, 0, 6) : null;
        }
        preg_match_all('/-?\d+(?:\.\d+)?%?/', $color, $m);
        $n = array_map(fn (string $v): float => str_ends_with($v, '%') ? (float) $v / 100 : (float) $v, $m[0]);
        if (count($n) < 3 || (count($n) >= 4 && $n[3] <= 0.05)) {
            return null;
        }
        if (str_starts_with($color, 'hsl')) {
            [$h, $s, $l] = [fmod(((float) $n[0] % 360 + 360), 360) / 360, min(1, $n[1] > 1 ? $n[1] / 100 : $n[1]), min(1, $n[2] > 1 ? $n[2] / 100 : $n[2])];
            $q = $l < 0.5 ? $l * (1 + $s) : $l + $s - $l * $s;
            $p = 2 * $l - $q;
            $channel = function (float $t) use ($p, $q): float {
                $t = $t < 0 ? $t + 1 : ($t > 1 ? $t - 1 : $t);

                return match (true) {
                    $t < 1 / 6 => $p + ($q - $p) * 6 * $t,
                    $t < 1 / 2 => $q,
                    $t < 2 / 3 => $p + ($q - $p) * (2 / 3 - $t) * 6,
                    default => $p,
                };
            };
            $rgb = [$channel($h + 1 / 3) * 255, $channel($h) * 255, $channel($h - 1 / 3) * 255];
        } else {
            $rgb = [$n[0], $n[1], $n[2]];
        }

        return sprintf('#%02x%02x%02x', ...array_map(fn (float $v): int => (int) max(0, min(255, round($v))), $rgb));
    }

    /**
     * The rules of a stylesheet in one pass over the text: [selector, declarations]. Comments are cut first, the rules inside @media,
     * @supports and @layer are read, @font-face and @keyframes bodies are skipped; it stops at MAX_RULES or the deadline.
     *
     * @return list<array{0: string, 1: string, 2: bool}> the third is true for a rule inside a conditional group (@media …)
     */
    public static function rules(string $css, float $deadline): array
    {
        $css = self::withoutComments($css);
        $length = strlen($css);
        $rules = [];
        $i = 0;
        $groups = 0; // open @media … groups: their rules are for some screens only
        while ($i < $length && count($rules) < self::MAX_RULES && microtime(true) < $deadline) {
            if ($css[$i] === '}' || $css[$i] === ';' || ctype_space($css[$i])) {
                $groups = $css[$i] === '}' ? max(0, $groups - 1) : $groups;
                $i++;
                continue;
            }
            $j = $i + strcspn($css, '{;}', $i);
            $prelude = trim(substr($css, $i, $j - $i));
            $stop = $css[$j] ?? '';
            if ($stop !== '{') {
                $i = $j + 1; // @import …; or a stray piece
                continue;
            }
            if ($prelude !== '' && $prelude[0] === '@') {
                if (preg_match('/^@(media|supports|layer|container|document)\b/i', $prelude) === 1) {
                    $groups++;
                    $i = $j + 1;
                    continue;
                }
                $i = self::blockEnd($css, $j, $length) + 1;
                continue;
            }
            $end = self::blockEnd($css, $j, $length);
            $rules[] = [$prelude, substr($css, $j + 1, $end - $j - 1), $groups > 0];
            $i = $end + 1;
        }

        return $rules;
    }

    /** The CSS without /* comments *​/, found with strpos. */
    private static function withoutComments(string $css): string
    {
        $out = '';
        $i = 0;
        while (($start = strpos($css, '/*', $i)) !== false) {
            $out .= substr($css, $i, $start - $i);
            $end = strpos($css, '*/', $start + 2);
            if ($end === false) {
                return $out;
            }
            $i = $end + 2;
        }

        return $out . substr($css, $i);
    }

    /** The index of the } that closes the { at $open (the text length when it is never closed). */
    private static function blockEnd(string $css, int $open, int $length): int
    {
        $depth = 1;
        $i = $open + 1;
        while ($depth > 0 && $i < $length) {
            $i += strcspn($css, '{}', $i);
            if ($i >= $length) {
                break;
            }
            $depth += $css[$i] === '{' ? 1 : -1;
            if ($depth > 0) {
                $i++;
            }
        }

        return min($i, $length);
    }
}
