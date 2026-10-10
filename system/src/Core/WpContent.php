<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * Converting the content of a WordPress post into HTML such as Kaleta's news editor writes. It only converts text – it reads and writes nothing.
 *
 * Order of steps: Gutenberg blocks → shortcodes in square brackets ([caption], [gallery]…) → embedded videos → paragraphs
 * (the old "classic" editor separates them only by an empty line) → letting through only allowed tags and attributes.
 * The export content is treated as untrusted: what is not on the ALLOWED list does not get into the article (scripts, frames, styles, onclick…).
 */
final class WpContent
{
    /** The same HTML subset the article editor lets through on paste (image/editor.js, ALLOWED) – only without IFRAME. */
    private const array ALLOWED = [
        'p' => [], 'h2' => [], 'h3' => [], 'h4' => [], 'strong' => [], 'em' => [], 'b' => [], 'i' => [], 'u' => [], 's' => [], 'sub' => [], 'sup' => [], 'mark' => [], 'br' => [], 'hr' => [],
        'a' => ['href', 'title', 'target'], 'ul' => [], 'ol' => [], 'li' => [], 'blockquote' => [], 'code' => [], 'pre' => [],
        'figure' => ['class'], 'figcaption' => [], 'img' => ['src', 'alt', 'width', 'height', 'data-id'],
        'table' => [], 'thead' => [], 'tbody' => [], 'tr' => [], 'th' => ['colspan', 'rowspan'], 'td' => ['colspan', 'rowspan'],
    ];

    /** Tags the editor does not know but that have a close replacement. */
    private const array TAG_REPLACEMENTS = ['h1' => 'h2', 'h5' => 'h4', 'h6' => 'h4'];

    /** These tags disappear together with their content (for other disallowed ones at least their text stays). */
    private const array DISCARD = ['script', 'style', 'iframe', 'object', 'embed', 'applet', 'form', 'noscript', 'svg', 'math', 'template', 'head', 'title', 'meta', 'link', 'base',
        'input', 'button', 'select', 'textarea', 'video', 'audio', 'canvas', 'frame', 'frameset'];

    /** Tags that form their own paragraph – no <p> is made around them. */
    private const string BLOCK_TAGS = 'table|thead|tfoot|tbody|tr|td|th|caption|div|dl|dd|dt|ul|ol|li|pre|blockquote|figure|figcaption|h[1-6]|hr|p|address|section|article|aside|header|footer|nav|details|summary';

    /** Shortcodes we convert ourselves, so the preview does not warn about them. */
    private const array KNOWN_SHORTCODES = ['caption', 'wp_caption', 'gallery', 'embed'];

    /** WordPress shortcodes without attributes and paired tags that would otherwise look like ordinary text in brackets. */
    private const array ALWAYS_SHORTCODES = ['audio', 'video', 'playlist', 'more', 'toc', 'contact-form-7', 'contact-form', 'sitemap', 'products', 'recent_posts'];

    /**
     * @param array<int|string, string> $attachments WordPress attachment numbers => file URL (for [gallery ids="…"])
     */
    public static function sanitize(string $content, array $attachments = []): string
    {
        $html = str_replace(["\r\n", "\r"], "\n", $content);
        $blockEditor = str_contains($html, '<!-- wp:'); // Gutenberg has the paragraphs ready, the classic editor does not
        $html = self::blocks($html);
        $html = self::outsideCode($html, fn (string $part): string => self::shortcodesToHtml($part, $attachments));
        $html = self::embeddedVideos($html);
        if (!$blockEditor) {
            $html = self::paragraphs($html);
        }

        return self::allowedHtml($html, false); // data-id of the old site's images means another site's numbers
    }

    /** Text for the builder (Text element, FAQ answers…): the same tag allowlist as the editor, without the WordPress conversions. */
    public static function safeHtml(string $html): string
    {
        return self::allowedHtml($html);
    }

    /**
     * Intro and text of an article. The intro is the manual excerpt from WordPress; when it is missing, the part before the
     * "Read more" tag (<!--more-->) is taken, and when that is missing too, the first paragraph of the text – which is then not
     * repeated in the text.
     *
     * @param array<int|string, string> $attachments
     * @return array{0:string, 1:string} [lead, text]
     */
    public static function introAndText(string $intro, string $content, array $attachments = []): array
    {
        $intro = trim(html_entity_decode(strip_tags($intro), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($intro !== '') {
            return ['<p>' . nl2br(e($intro), false) . '</p>', self::sanitize($content, $attachments)];
        }
        $parts = preg_split('/<!--\s*more\b.*?-->/s', $content, 2) ?: [$content];
        if (count($parts) === 2 && trim(strip_tags($parts[0])) !== '') {
            return [self::sanitize($parts[0], $attachments), self::sanitize($parts[1], $attachments)];
        }
        $text = self::sanitize($content, $attachments);
        // the first paragraph that contains real text
        if (preg_match('#<p>((?:(?!</?p>).)*?\p{L}(?:(?!</?p>).)*?)</p>\s*#su', $text, $m, PREG_OFFSET_CAPTURE)) {
            return ['<p>' . $m[1][0] . '</p>', trim(substr($text, 0, $m[0][1]) . substr($text, $m[0][1] + strlen($m[0][0])))];
        }

        return ['', $text];
    }

    /**
     * Shortcodes of add-ons (plugins) that are in the content and that the import cannot convert – for a warning in the preview.
     *
     * @return list<string> shortcode names
     */
    public static function unknownShortcodes(string $content): array
    {
        $found = [];
        $content = (string) preg_replace('#<pre\b.*?</pre>|<code\b.*?</code>#is', '', $content); // in code samples brackets are ordinary text
        preg_match_all('#\[/([a-zA-Z][\w-]*)\]#', $content, $paired);
        if (preg_match_all('#(?<!\[)\[([a-zA-Z][\w-]*)((?:\s[^\]]*)?)/?\]#', $content, $m, PREG_SET_ORDER)) {
            foreach ($m as $z) {
                if (!in_array(strtolower($z[1]), self::KNOWN_SHORTCODES, true) && self::isShortcode($z[1], $z[2], $paired[1])) {
                    $found[strtolower($z[1])] = true;
                }
            }
        }

        return array_keys($found);
    }

    /* ---------- Gutenberg blocks ---------- */

    private static function blocks(string $html): string
    {
        // photo gallery: only the images remain from the block, put together into our gallery
        $html = preg_replace_callback('#<!--\s*wp:gallery\b.*?-->(.*?)<!--\s*/wp:gallery\s*-->#s', function (array $m): string {
            preg_match_all('#<img\b[^>]*>#i', $m[1], $images);

            return self::gallery($images[0]);
        }, $html) ?? $html;
        // an embedded video or post: the URL is in the block settings; for us the URL on its own line is enough (Front\NewsText)
        $html = preg_replace_callback('#<!--\s*wp:(?:core-embed/[\w-]+|embed)\s+(\{.*?\})\s*-->.*?<!--\s*/wp:(?:core-embed/[\w-]+|embed)\s*-->#s', function (array $m): string {
            $url = (string) (json_decode($m[1], true)['url'] ?? '');

            return preg_match('#^https?://[^\s<>"]+$#i', $url) ? "\n<p>" . e($url) . "</p>\n" : '';
        }, $html) ?? $html;

        // other comments (block boundaries, <!--more-->, <!--nextpage-->) no longer mean anything
        return preg_replace('/<!--.*?-->/s', '', $html) ?? $html;
    }

    /** @param list<string> $imgTags finished <img …> tags */
    private static function gallery(array $imgTags): string
    {
        return $imgTags === [] ? '' : "\n" . '<figure class="gallery">' . implode('', $imgTags) . '</figure>' . "\n";
    }

    /* ---------- shortcodes in square brackets ---------- */

    /** @param array<int|string, string> $attachments */
    private static function shortcodesToHtml(string $html, array $attachments): string
    {
        // [caption]<img> Caption[/caption] → image with a caption
        $html = preg_replace_callback('#\[(?:wp_)?caption\b([^\]]*)\](.*?)\[/(?:wp_)?caption\]#s', function (array $m): string {
            if (!preg_match('#<img\b[^>]*>#i', $m[2], $img)) {
                return $m[2];
            }
            $labelText = preg_match('#\bcaption="([^"]*)"#', $m[1], $a) ? $a[1] : trim((string) preg_replace('#<a\b[^>]*>\s*</a>#i', '', str_replace($img[0], '', $m[2])));

            return "\n\n<figure>" . $img[0] . ($labelText !== '' ? '<figcaption>' . $labelText . '</figcaption>' : '') . "</figure>\n\n";
        }, $html) ?? $html;

        // [gallery ids="1,2,3"] → our gallery; the images are looked up among the export's attachments
        $html = preg_replace_callback('#\[gallery\b([^\]]*)\]#', function (array $m) use ($attachments): string {
            $images = [];
            foreach (preg_match('#\bids="([\d,\s]+)"#', $m[1], $a) ? explode(',', $a[1]) : [] as $id) {
                $url = $attachments[(int) $id] ?? '';
                if (preg_match('#\.(jpe?g|png|gif|webp)$#i', (string) parse_url($url, PHP_URL_PATH))) {
                    $images[] = '<img src="' . e($url) . '" alt="">';
                }
            }

            return "\n\n" . self::gallery($images) . "\n\n";
        }, $html) ?? $html;

        // [embed]url[/embed] → the URL on its own line
        $html = preg_replace('#\[embed[^\]]*\]\s*(https?://[^\s\[]+)\s*\[/embed\]#i', "\n\n$1\n\n", $html) ?? $html;

        // remaining add-on shortcodes: the tag disappears, the text inside stays
        preg_match_all('#\[/([a-zA-Z][\w-]*)\]#', $html, $paired);

        return preg_replace_callback('#(?<!\[)\[(/?)([a-zA-Z][\w-]*)((?:\s[^\]]*)?)/?\]#', function (array $z) use ($paired): string {
            return $z[1] === '/' || self::isShortcode($z[2], $z[3], $paired[1]) ? '' : $z[0];
        }, $html) ?? $html;
    }

    /**
     * Tells a shortcode from ordinary text in brackets ([sic], [1]): a shortcode has attributes, a closing tag, an underscore or
     * a dash in its name, or is on the list of known ones.
     *
     * @param list<string> $paired names for which a closing [/name] exists in the text
     */
    private static function isShortcode(string $name, string $attributes, array $paired): bool
    {
        return str_contains($attributes, '=') || in_array($name, $paired, true) || preg_match('/[_-]/', $name) === 1
            || in_array(strtolower($name), self::ALWAYS_SHORTCODES, true) || in_array(strtolower($name), self::KNOWN_SHORTCODES, true);
    }

    /** Players embedded as an <iframe> turn into the video URL on its own line; other frames disappear later (also used by Core\SiteImport). */
    public static function embeddedVideos(string $html): string
    {
        return preg_replace_callback('#<iframe\b[^>]*\bsrc=["\']([^"\']+)["\'][^>]*>.*?</iframe>#is', function (array $m): string {
            $url = match (true) {
                (bool) preg_match('#youtube(?:-nocookie)?\.com/embed/([A-Za-z0-9_-]{11})#', $m[1], $v) => 'https://www.youtube.com/watch?v=' . $v[1],
                (bool) preg_match('#player\.vimeo\.com/video/(\d+)#', $m[1], $v) => 'https://vimeo.com/' . $v[1],
                (bool) preg_match('#open\.spotify\.com/embed/(episode|show|track)/([A-Za-z0-9]+)#', $m[1], $v) => 'https://open.spotify.com/' . $v[1] . '/' . $v[2],
                default => '',
            };

            return $url === '' ? '' : "\n\n<p>" . $url . "</p>\n\n";
        }, $html) ?? $html;
    }

    /* ---------- paragraphs of the classic editor ---------- */

    /**
     * WordPress's classic editor stores paragraphs only as text separated by an empty line; it adds the <p> tags only on display.
     * Here they are added permanently: an empty line = a new paragraph, a single line break = <br>. Block tags are not wrapped.
     */
    public static function paragraphs(string $text): string
    {
        if (trim($text) === '') {
            return '';
        }
        // nothing may touch <pre> – it is hidden and put back at the end
        $hidden = [];
        $text = preg_replace_callback('#<pre\b.*?</pre>#is', function (array $m) use (&$hidden): string {
            $key = "\x02PRE" . count($hidden) . "\x03";
            $hidden[$key] = $m[0];

            return "\n\n" . $key . "\n\n";
        }, $text) ?? $text;
        // a URL alone on a line (a YouTube video…) should be its own paragraph, so the site recognizes it
        $text = preg_replace('#^[ \t]*(https?://[^\s<>"]+)[ \t]*$#m', "\n\n$1\n\n", $text) ?? $text;
        $text = preg_replace('#<br\s*/?>\s*<br\s*/?>#i', "\n\n", $text) ?? $text;
        $text = preg_replace('#(<(?:' . self::BLOCK_TAGS . ')(?:\s[^>]*)?/?>)#i', "\n\n$1", $text) ?? $text;
        $text = preg_replace('#(</(?:' . self::BLOCK_TAGS . ')>)#i', "$1\n\n", $text) ?? $text;

        $html = '';
        foreach (preg_split('/\n\s*\n/', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $part) {
            $part = trim($part);
            if ($part === '' || isset($hidden[$part]) || preg_match('#^</?(?:' . self::BLOCK_TAGS . ')\b#i', $part)) {
                $html .= $part . "\n";
            } elseif (preg_match('#^(.*?)((?:</(?:' . self::BLOCK_TAGS . ')>\s*)+)$#is', $part, $m)) {
                $html .= '<p>' . self::lineBreaks($m[1]) . '</p>' . $m[2] . "\n"; // text right before the end of a block: "…text</div>"
            } else {
                $html .= '<p>' . self::lineBreaks($part) . "</p>\n";
            }
        }

        return strtr($html, $hidden);
    }

    private static function lineBreaks(string $text): string
    {
        return (string) preg_replace('#(?<!<br>)\n#', "<br>\n", trim((string) preg_replace('#<br\s*/?>[ \t]*\n?#i', "<br>\n", $text)));
    }

    /* ---------- allowed tags only ---------- */

    /** Lets through only tags and attributes from the ALLOWED list; the HTML is read by a real HTML5 parser, not regular expressions. */
    private static function allowedHtml(string $html, bool $mediaIds = true): string
    {
        if (trim($html) === '') {
            return '';
        }
        $doc = \Dom\HTMLDocument::createFromString('<!DOCTYPE html><html><body>' . $html . '</body></html>', LIBXML_NOERROR, 'UTF-8');
        $body = $doc->body;
        if ($body === null) {
            return '';
        }
        self::sanitizeNode($doc, $body, $mediaIds);
        self::normalizeRoot($doc, $body);
        $output = '';
        foreach ($body->childNodes as $n) {
            $output .= Html::outer($n) . ($n instanceof \Dom\Element ? "\n" : '');
        }
        $output = str_replace(['&nbsp;', "\u{00A0}"], ' ', $output);

        // cleanup outside code samples: line breaks at the edge of a paragraph and empty lines mean nothing
        return trim(self::outsideCode($output, fn (string $part): string => (string) preg_replace(['#<p>(?:\s*<br>)+\s*#', '#(?:\s*<br>)+\s*</p>#', "/\n{2,}/"], ['<p>', '</p>', "\n"], $part)));
    }

    private static function sanitizeNode(\Dom\HTMLDocument $doc, \Dom\Node $node, bool $mediaIds): void
    {
        foreach (iterator_to_array($node->childNodes) as $n) {
            if (!$n instanceof \Dom\Element) {
                // comments and processing instructions go away; so do empty lines between an image and its caption
                if (!$n instanceof \Dom\Text || ($node instanceof \Dom\Element && $node->localName === 'figure' && trim($n->data) === '')) {
                    $n->parentNode?->removeChild($n);
                }
                continue;
            }
            $tag = strtolower($n->localName);
            if (in_array($tag, self::DISCARD, true)) {
                $n->remove();
                continue;
            }
            self::sanitizeNode($doc, $n, $mediaIds);
            if ($tag === 'a' && self::isLinkToOwnImage($n)) {
                self::extract($n); // a thumbnail linking to the large image: the site has its own photo viewer
                continue;
            }
            if ($tag === 'div' || isset(self::TAG_REPLACEMENTS[$tag])) {
                // a <div> with blocks inside just disappears, a <div> with text is a paragraph
                $new = $tag === 'div' ? (self::hasBlockChild($n) ? null : 'p') : self::TAG_REPLACEMENTS[$tag];
                if ($new === null) {
                    self::extract($n);
                    continue;
                }
                $n = self::rename($doc, $n, $new);
                $tag = $new;
            }
            if (!isset(self::ALLOWED[$tag])) {
                self::extract($n);
                continue;
            }
            self::sanitizeAttributes($n, $tag, $mediaIds);
            if ($tag === 'a' && !$n->hasAttribute('href')) {
                self::extract($n); // a link left without a safe URL is just text
            }
        }
    }

    private static function sanitizeAttributes(\Dom\Element $n, string $tag, bool $mediaIds): void
    {
        if ($tag === 'img') {
            // lazy-loading add-ons put the real URL into data-src
            $url = $n->getAttribute('data-src') ?? $n->getAttribute('data-lazy-src') ?? $n->getAttribute('src') ?? '';
            if (!self::isSafeUrl($url) || str_starts_with(strtolower(trim($url)), 'data:')) {
                $n->remove();

                return;
            }
            $n->setAttribute('src', trim($url));
        }
        foreach (iterator_to_array($n->attributes) as $a) {
            $name = strtolower($a->name);
            $ok = in_array($name, self::ALLOWED[$tag], true) && match ($name) {
                'href', 'src' => self::isSafeUrl($a->value),
                'width', 'height', 'colspan', 'rowspan' => ctype_digit($a->value),
                'data-id' => $mediaIds && (ctype_digit($a->value) || Uuid::valid($a->value)), // the Media public id (older texts: number) of an image the editor or an import put there
                'target' => $a->value === '_blank',
                'class' => preg_match('/(^|\s)gallery(\s|$)/', $a->value) === 1,
                default => true,
            };
            if (!$ok) {
                $n->removeAttribute($a->name);
            }
        }
        if ($tag === 'figure' && $n->hasAttribute('class')) {
            $n->setAttribute('class', 'gallery');
        }
        if ($tag === 'a' && $n->hasAttribute('target')) {
            $n->setAttribute('rel', 'noopener');
        }
        if ($tag === 'img') {
            $n->setAttribute('alt', (string) $n->getAttribute('alt'));
            $n->setAttribute('loading', 'lazy');
        }
    }

    /** A URL may be http(s), mailto, tel or local; javascript:, data:, vbscript: and the like may not (not even with inserted spaces and tabs). */
    public static function isSafeUrl(string $url): bool
    {
        $url = (string) preg_replace('/[\x00-\x20]+/', '', $url);

        return $url !== '' && (!preg_match('#^[a-z][a-z0-9+.-]*:#i', $url) || preg_match('#^(https?|mailto|tel):#i', $url) === 1);
    }

    /** After cleaning, neither loose text nor a bare image may remain at the top level: text belongs in <p>, an image in <figure>. */
    private static function normalizeRoot(\Dom\HTMLDocument $doc, \Dom\Element $body): void
    {
        $paragraph = null;
        foreach (iterator_to_array($body->childNodes) as $n) {
            $isInline = $n instanceof \Dom\Text || ($n instanceof \Dom\Element && in_array(strtolower($n->localName), ['a', 'strong', 'em', 'b', 'i', 'u', 's', 'sub', 'sup', 'br', 'code'], true));
            if ($n instanceof \Dom\Element && strtolower($n->localName) === 'img') {
                $wrapper = $doc->createElement('figure');
                $body->replaceChild($wrapper, $n);
                $wrapper->appendChild($n);
                $paragraph = null;
            } elseif ($isInline) {
                if ($paragraph === null) {
                    if ($n instanceof \Dom\Text && trim($n->data) === '') {
                        $n->remove();
                        continue;
                    }
                    $paragraph = $doc->createElement('p');
                    $body->insertBefore($paragraph, $n);
                }
                $paragraph->appendChild($n);
            } else {
                $paragraph = null;
            }
        }
        foreach (iterator_to_array($body->childNodes) as $n) {
            if (!$n instanceof \Dom\Element || strtolower($n->localName) !== 'p') {
                continue;
            }
            $images = [];
            foreach ($n->childNodes as $child) {
                if ($child instanceof \Dom\Element && strtolower($child->localName) === 'img') {
                    $images[] = $child;
                }
            }
            $text = trim(str_replace("\u{00A0}", ' ', (string) $n->textContent));
            if ($text === '' && $images === []) {
                $n->remove(); // empty paragraph
            } elseif ($text === '' && count($images) === 1) {
                $wrapper = $doc->createElement('figure'); // a paragraph with only an image = an image, as the editor inserts it
                $wrapper->appendChild($images[0]);
                $body->replaceChild($wrapper, $n);
            }
        }
    }

    private static function isLinkToOwnImage(\Dom\Element $a): bool
    {
        $children = array_values(array_filter(iterator_to_array($a->childNodes), fn (\Dom\Node $n): bool => !($n instanceof \Dom\Text && trim($n->data) === '')));

        return count($children) === 1 && $children[0] instanceof \Dom\Element && strtolower($children[0]->localName) === 'img'
            && preg_match('#\.(jpe?g|png|gif|webp)$#i', (string) parse_url((string) $a->getAttribute('href'), PHP_URL_PATH)) === 1;
    }

    private static function hasBlockChild(\Dom\Element $n): bool
    {
        foreach ($n->childNodes as $child) {
            if ($child instanceof \Dom\Element && preg_match('#^(?:' . self::BLOCK_TAGS . ')$#i', $child->localName)) {
                return true;
            }
        }

        return false;
    }

    /** Removes a tag, leaving its content in place. */
    private static function extract(\Dom\Element $n): void
    {
        while ($n->firstChild !== null) {
            $n->parentNode?->insertBefore($n->firstChild, $n);
        }
        $n->remove();
    }

    private static function rename(\Dom\HTMLDocument $doc, \Dom\Element $n, string $tag): \Dom\Element
    {
        $new = $doc->createElement($tag);
        while ($n->firstChild !== null) {
            $new->appendChild($n->firstChild);
        }
        $n->parentNode?->replaceChild($new, $n);

        return $new;
    }

    /**
     * Calls the function only on the parts of the HTML outside <pre> and <code> – in code samples square brackets are ordinary text.
     *
     * @param callable(string): string $callback
     */
    private static function outsideCode(string $html, callable $callback): string
    {
        $parts = preg_split('#(<pre\b.*?</pre>|<code\b.*?</code>)#is', $html, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$html];
        foreach ($parts as $i => $part) {
            if ($i % 2 === 0) {
                $parts[$i] = $callback($part);
            }
        }

        return implode('', $parts);
    }
}
