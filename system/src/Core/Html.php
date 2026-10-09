<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * Safe HTML from users without administrator permission (author and editor of news and pages, MCP with their token).
 * Unlike the conversion from WordPress (WpContent) it keeps the structure and the classes from the editor and removes only what can run code
 * or take over an account: scripts, frames, forms, event handlers, styles, the site's script hooks (data-…) and javascript:/data: URLs.
 * An administrator can insert custom HTML (the HTML element, layouts), so their text is not sanitized.
 */
final class Html
{
    private const string HTML_NAMESPACE = 'http://www.w3.org/1999/xhtml';

    /** Elements without an end tag. */
    private const array VOID = ['area', 'base', 'basefont', 'bgsound', 'br', 'col', 'embed', 'frame', 'hr', 'img', 'input', 'keygen', 'link', 'meta', 'param', 'source', 'track', 'wbr'];

    private const array DISCARD = ['script', 'style', 'iframe', 'frame', 'frameset', 'object', 'embed', 'applet', 'form', 'input', 'button', 'select', 'textarea',
        'template', 'noscript', 'noembed', 'noframes', 'xmp', 'plaintext', 'svg', 'math', 'link', 'meta', 'base', 'head', 'title', 'dialog', 'portal'];

    /** Allowed attributes (besides aria-* and a numeric data-id on an image); the others – mainly on…, style, data-…, formaction, srcdoc – are removed. */
    private const array ATTRIBUTES = ['href', 'src', 'alt', 'title', 'class', 'id', 'width', 'height', 'colspan', 'rowspan', 'scope', 'target', 'rel', 'lang', 'dir',
        'loading', 'decoding', 'srcset', 'sizes', 'start', 'reversed', 'type', 'cite', 'datetime', 'controls', 'poster', 'preload', 'playsinline', 'muted', 'loop'];

    public static function safe(string $html): string
    {
        if (trim($html) === '' || !preg_match('/<|&/', $html)) {
            return $html;
        }
        $doc = \Dom\HTMLDocument::createFromString('<!DOCTYPE html><html><body>' . $html . '</body></html>', LIBXML_NOERROR, 'UTF-8');
        $body = $doc->body;
        if ($body === null) {
            return '';
        }
        self::node($body);

        return self::inner($body);
    }

    /**
     * The children of a node as HTML. Like Dom\HTMLDocument::saveHtml(), but an attribute value never keeps a raw < or >
     * (the current HTML serialization rules): sanitized HTML then contains a < only where a tag really starts, so nothing
     * downstream – a regular expression, strip_tags, another parser – can read attribute text as markup.
     */
    public static function inner(\Dom\Node $parent): string
    {
        $output = '';
        foreach ($parent->childNodes as $n) {
            $output .= self::outer($n);
        }

        return $output;
    }

    /** One node as HTML, with the same escaping of attribute values as inner(). */
    public static function outer(\Dom\Node $node): string
    {
        if (!$node instanceof \Dom\Element) {
            $doc = $node->ownerDocument;

            return $doc instanceof \Dom\HTMLDocument ? $doc->saveHtml($node) : '';
        }
        $html = $node->namespaceURI === self::HTML_NAMESPACE;
        $name = $html ? $node->localName : $node->tagName;
        $output = '<' . $name;
        foreach ($node->attributes as $a) {
            $attribute = self::attributeName($a->namespaceURI, $a->nodeName);
            if ($attribute !== null && preg_match('/^[^\s"\'>\/=<\x00-\x1f\x7f]+$/', $attribute)) { // a name the parser would read differently is left out
                $output .= ' ' . $attribute . '="' . str_replace(['&', "\u{00A0}", '"', '<', '>'], ['&amp;', '&nbsp;', '&quot;', '&lt;', '&gt;'], $a->value) . '"';
            }
        }
        $output .= '>';
        if ($html && in_array($name, self::VOID, true)) {
            return $output;
        }
        // the inert content of a <template> as the parser keeps it (every sanitizer discards the element itself)
        $output .= $html && $name === 'template' ? $node->innerHTML : self::inner($node);

        return $output . '</' . $name . '>';
    }

    /**
     * The qualified name of an attribute (xlink:href, never just href) – the name every sanitizer judges and the one that
     * is written. A namespaced attribute is written only when it is one HTML parsing creates (xlink:href and its siblings
     * in SVG, xml:lang, xmlns): an attribute in any other namespace is left out, never written under its local name
     * (on PHP 8.3 an "xml:onerror" became a live "onerror" that way before 3.7.0).
     */
    private static function attributeName(?string $namespace, string $qualifiedName): ?string
    {
        if ($namespace === null) {
            return $qualifiedName;
        }
        $prefix = match ($namespace) {
            'http://www.w3.org/1999/xlink' => 'xlink:',
            'http://www.w3.org/XML/1998/namespace' => 'xml:',
            'http://www.w3.org/2000/xmlns/' => 'xmlns',
            default => null,
        };

        return $prefix !== null && str_starts_with($qualifiedName, $prefix) && in_array($qualifiedName, \Kaleta\Compat\Html5Serializer::NAMESPACED, true)
            ? $qualifiedName : null;
    }

    /**
     * Parses an HTML fragment, lets $change edit it on the DOM and writes it back with inner(). Changes of sanitized HTML
     * (rewriting images, dropping attributes) go through here, never through regular expressions over the markup.
     *
     * @param callable(\Dom\HTMLElement, \Dom\HTMLDocument): void $change gets the <body> holding the fragment
     */
    public static function transform(string $html, callable $change): string
    {
        $doc = \Dom\HTMLDocument::createFromString('<!DOCTYPE html><html><body>' . $html . '</body></html>', LIBXML_NOERROR, 'UTF-8');
        $body = $doc->body;
        if (!$body instanceof \Dom\HTMLElement) {
            return '';
        }
        $change($body, $doc);

        return self::inner($body);
    }

    /**
     * Rewrites the <img> elements of (sanitized) HTML on the DOM – the importers point images at Media this way.
     * $image gets the image's src and alt (entities decoded) and returns null to keep the image as it is, false to remove it,
     * or the new attributes (all others are dropped). When nothing changes, the HTML comes back untouched.
     *
     * @param callable(string, string): (array<string, string|int>|false|null) $image
     */
    public static function rewriteImages(string $html, callable $image): string
    {
        if (stripos($html, '<img') === false) {
            return $html;
        }
        $changed = false;
        $output = self::transform($html, function (\Dom\HTMLElement $body) use ($image, &$changed): void {
            foreach (iterator_to_array($body->querySelectorAll('img')) as $img) {
                $result = $image((string) $img->getAttribute('src'), (string) $img->getAttribute('alt'));
                if ($result === null) {
                    continue;
                }
                $changed = true;
                if ($result === false) {
                    $img->remove();
                    continue;
                }
                foreach (iterator_to_array($img->attributes, false) as $a) {
                    $img->removeAttributeNode($a);
                }
                foreach ($result as $name => $value) {
                    $img->setAttribute($name, (string) $value);
                }
            }
        });

        return $changed ? $output : $html;
    }

    /** Sanitizes only for users without administrator permission (and for an administrator's Claude connection without full access). */
    public static function forUser(string $html, Auth $auth): string
    {
        return $auth->canWriteCode() ? $html : self::safe($html);
    }

    private static function node(\Dom\Node $node): void
    {
        foreach (iterator_to_array($node->childNodes) as $n) {
            if ($n instanceof \Dom\Comment) {
                $n->remove();
                continue;
            }
            if (!$n instanceof \Dom\Element) {
                continue;
            }
            if (in_array(strtolower($n->localName), self::DISCARD, true)) {
                $n->remove();
                continue;
            }
            foreach (iterator_to_array($n->attributes, false) as $a) { // a list: the keys are local names, and xlink:href would hide href (or onload x:onload)
                $name = strtolower($a->nodeName); // the qualified name: xml:onerror is not onerror
                $ok = (in_array($name, self::ATTRIBUTES, true) || preg_match('/^aria-[a-z]{2,20}$/', $name)
                        || ($name === 'data-id' && strtolower($n->localName) === 'img' && ctype_digit($a->value))) // the Media number the editor puts on an image
                    && (!in_array($name, ['href', 'src', 'poster', 'cite'], true) || WpContent::isSafeUrl($a->value))
                    && ($name !== 'srcset' || !preg_match('/(javascript|data|vbscript):/i', $a->value))
                    && ($name !== 'target' || $a->value === '_blank');
                if (!$ok) {
                    $n->removeAttributeNode($a);
                }
            }
            if (strtolower($n->localName) === 'a' && $n->getAttribute('target') === '_blank') {
                $n->setAttribute('rel', 'noopener');
            }
            self::node($n);
        }
    }
}
