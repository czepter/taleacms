<?php

declare(strict_types=1);

namespace Talea\Builder;

use Talea\Core\WpContent;

/**
 * The filter of the Code element's custom HTML and CSS (an administrator-only element): embedded maps and service forms stay,
 * whatever could run code goes. Build::sanitize() runs it on save, the element again when rendering.
 */
final class CodeFilter
{
    /** Elements the administrator's custom HTML never keeps: they run code, change the page's base or reload it, or hide markup from the parser. */
    private const array CODE_DISCARD = ['script', 'object', 'embed', 'applet', 'base', 'meta', 'link', 'frame', 'frameset', 'portal', 'template', 'noscript',
        'noembed', 'noframes', 'xmp', 'plaintext', 'math', 'animate', 'animatemotion', 'animatetransform', 'set', 'handler', 'foreignobject',
        // parsers disagree on what a <select> may hold (the relaxed content model of newer ones): markup the filter saw as inert
        // could be read as live elsewhere, so none of it is kept
        'select', 'option', 'optgroup', 'datalist', 'selectedcontent'];

    /** Elements whose text the parser does not read as markup: a < inside them is never kept (another parser or context could). */
    private const array CODE_RAWTEXT = ['style', 'iframe', 'script', 'xmp', 'noembed', 'noframes', 'plaintext', 'noscript'];

    /** Attributes holding an address: only http(s), mailto, tel or a relative one (checked after the parser decoded entities). */
    private const array CODE_URLS = ['href', 'src', 'action', 'formaction', 'poster', 'cite', 'data', 'background', 'lowsrc', 'ping', 'longdesc', 'codebase', 'manifest', 'xlink:href'];

    /**
     * The administrator's custom HTML (an administrator-only element). Embedded maps and service forms are <iframe>s and <form>s, those
     * stay; scripts, event handlers, srcdoc, script-like URLs (also entity-encoded or unquoted) and SVG animation of attributes are removed.
     * A DOM pass, not a regular expression – regular expressions over HTML can be bypassed. It repeats until the output parses back to
     * itself, so markup that a browser would read differently after serialization (mutation XSS) is cleaned in the form the browser sees.
     * Runs on save and again when rendering.
     */
    public static function code(string $html): string
    {
        for ($i = 0; $i < 4; $i++) {
            $clean = self::codePass($html);
            if ($clean === $html) {
                return $clean;
            }
            $html = $clean;
        }

        return ''; // never settles – such markup is not an embed code
    }

    private static function codePass(string $html): string
    {
        if (trim($html) === '' || !preg_match('/<|&/', $html)) {
            return $html;
        }
        $doc = \Dom\HTMLDocument::createFromString('<!DOCTYPE html><html><body>' . $html . '</body></html>', LIBXML_NOERROR, 'UTF-8');
        $body = $doc->body;
        if ($body === null) {
            return '';
        }
        self::codeNode($body);

        return \Talea\Core\Html::inner($body); // attribute values without a raw < or >
    }

    /** CSS longer than this is not read for the checks: the <style> element or style attribute goes (fail closed). */
    private const int CSS_CHECK_LIMIT = 500_000;

    /**
     * Does CSS match a pattern of codeNode() in any way it can be read (cssViews())? CSS that cannot be read (invalid UTF-8,
     * too long, a regular expression error) counts as a match, so it is removed.
     */
    private static function cssMatches(string $pattern, string $css): bool
    {
        foreach (self::cssViews($css) ?? [null] as $view) {
            if ($view === null || preg_match($pattern, $view) !== 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * The ways a browser could read CSS, for the checks only (the code stays as typed): as typed; with escapes decoded
     * (\75 rl( is url(, @\69mport is @import, java\script is javascript) and comments kept, as inside an unquoted url(…);
     * decoded with comments dropped outside strings; decoded with every comment dropped, strings or not. Matching any of
     * them removes the CSS, so no single reading has to be perfect. Null = the CSS cannot be read.
     *
     * @return list<?string>|null
     */
    private static function cssViews(string $css): ?array
    {
        if (strlen($css) > self::CSS_CHECK_LIMIT || !mb_check_encoding($css, 'UTF-8')) {
            return null;
        }
        $css = str_replace(["\r\n", "\r", "\f"], "\n", $css); // CSS reads every line break as \n (it ends a string)

        return [$css, self::cssPlain($css, false, false), self::cssPlain($css, true, true), self::cssPlain($css, false, true)];
    }

    /**
     * One reading of CSS, left to right: escapes decoded; comments dropped when $comments (outside quoted strings when
     * $strings, everywhere otherwise). Null = a regular expression error.
     */
    private static function cssPlain(string $css, bool $strings, bool $comments): ?string
    {
        $plain = '';
        $quote = '';
        for ($i = 0, $length = strlen($css); $i < $length; $i++) {
            $character = $css[$i];
            if ($character === '\\') {
                $hex = preg_match('/[0-9a-fA-F]{1,6}[ \t\n]?/A', $css, $m, 0, $i + 1);
                if ($hex === false) {
                    return null;
                }
                if ($hex === 1) {
                    $decoded = (string) mb_chr((int) hexdec($m[0]), 'UTF-8');
                    $plain .= $decoded === '' || $decoded === "\0" ? "\u{FFFD}" : $decoded; // as CSS reads 0, a surrogate or past U+10FFFF
                    $i += strlen($m[0]);
                } elseif ($i + 1 < $length) {
                    $plain .= $css[++$i]; // any other escaped character stands for itself
                }
                continue;
            }
            if ($comments && $quote === '' && $character === '/' && ($css[$i + 1] ?? '') === '*') {
                $end = strpos($css, '*/', $i + 2);
                if ($end === false) {
                    break; // an unclosed comment runs to the end
                }
                $i = $end + 1;
                continue;
            }
            if ($strings && $quote === '' && ($character === '"' || $character === "'")) {
                $quote = $character;
            } elseif ($quote !== '' && ($character === $quote || $character === "\n")) {
                $quote = ''; // the closing quote, or a line break that ends an unclosed string
            }
            $plain .= $character;
        }

        return $plain;
    }

    private static function codeNode(\Dom\Node $node): void
    {
        foreach (iterator_to_array($node->childNodes) as $n) {
            if ($n instanceof \Dom\Comment || ($n instanceof \Dom\Element && (in_array(strtolower($n->localName), self::CODE_DISCARD, true)
                || !preg_match('/^[a-z][a-z0-9-]*$/i', $n->localName)))) {
                $n->remove();
                continue;
            }
            if (!$n instanceof \Dom\Element) {
                continue;
            }
            if (strtolower($n->localName) === 'style' && self::cssMatches('/javascript:|expression\s*\(|@import|behavior\s*:|-moz-binding/i', (string) $n->textContent)) {
                $n->remove();
                continue;
            }
            foreach (iterator_to_array($n->attributes) as $a) {
                $name = strtolower($a->name);
                $ok = !str_starts_with($name, 'on') && $name !== 'srcdoc' && $name !== 'http-equiv'
                    && (!in_array($name, self::CODE_URLS, true) && !str_ends_with($name, ':href') || WpContent::isSafeUrl($a->value) || trim($a->value) === '')
                    && ($name !== 'srcset' || !preg_match('/(javascript|data|vbscript):/i', $a->value))
                    && ($name !== 'style' || !self::cssMatches('/javascript:|expression\s*\(|behavior\s*:|-moz-binding|url\s*\(\s*["\']?\s*(javascript|data|vbscript):/i', $a->value))
                    && ($name !== 'attributename' && $name !== 'formtarget');
                if (!$ok) {
                    $n->removeAttribute($a->name);
                }
            }
            // only HTML's own elements are raw text – an SVG <style> holds parsed markup and is cleaned like any other element
            if ($n->namespaceURI === 'http://www.w3.org/1999/xhtml' && in_array(strtolower($n->localName), self::CODE_RAWTEXT, true) && str_contains((string) $n->textContent, '<')) {
                // CSS keeps its meaning with the escape \3c; the text of a frame is never shown, so it goes
                $n->textContent = strtolower($n->localName) === 'style' ? str_replace('<', '\3c ', (string) $n->textContent) : '';
            }
            self::codeNode($n);
        }
    }
}
