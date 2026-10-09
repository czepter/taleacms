<?php

declare(strict_types=1);

namespace Kaleta\Compat;

/**
 * HTML serialization of a legacy DOM tree as PHP 8.4's Dom\HTMLDocument::saveHtml() and innerHTML write it (the HTML
 * fragment serialization algorithm): text escapes & < > and no-break spaces, attribute values & " and no-break spaces,
 * void elements have no end tag. Used by the Dom\ classes for PHP 8.3 (system/compat).
 */
final class Html5Serializer
{
    private const array VOID = ['area', 'base', 'basefont', 'bgsound', 'br', 'col', 'embed', 'frame', 'hr', 'img', 'input', 'keygen', 'link', 'meta', 'param', 'source', 'track', 'wbr'];

    /** The namespaced attributes of HTML parsing (the "adjust foreign attributes" table). */
    public const array NAMESPACED = ['xlink:actuate', 'xlink:arcrole', 'xlink:href', 'xlink:role', 'xlink:show', 'xlink:title', 'xlink:type',
        'xml:lang', 'xml:space', 'xmlns', 'xmlns:xlink'];

    /** Elements whose text is written as it is (noscript is not: the parser reads it with scripting off, as PHP 8.4 does). */
    private const array RAW_TEXT = ['style', 'script', 'xmp', 'iframe', 'noembed', 'noframes', 'plaintext'];

    /** The node itself; a document or a fragment as its children. */
    public static function outer(\DOMNode $node): string
    {
        if ($node instanceof \DOMElement) {
            return self::element($node);
        }
        if ($node instanceof \DOMDocument || $node instanceof \DOMDocumentFragment) {
            return self::inner($node);
        }
        if ($node instanceof \DOMText) {
            return self::text($node);
        }
        if ($node instanceof \DOMComment) {
            return '<!--' . $node->data . '-->';
        }
        if ($node instanceof \DOMProcessingInstruction) {
            return '<?' . $node->target . ' ' . $node->data . '>';
        }
        if ($node instanceof \DOMDocumentType) {
            return '<!DOCTYPE ' . $node->name . '>';
        }

        return '';
    }

    public static function inner(\DOMNode $parent): string
    {
        $output = '';
        foreach ($parent->childNodes as $child) {
            $output .= self::outer($child);
        }

        return $output;
    }

    private static function element(\DOMElement $element): string
    {
        $name = self::tagName($element);
        $output = '<' . $name;
        foreach ($element->attributes as $attribute) {
            $attributeName = self::attributeName($attribute);
            if ($attributeName !== null) {
                $output .= ' ' . $attributeName . '="' . str_replace(['&', "\u{00A0}", '"'], ['&amp;', '&nbsp;', '&quot;'], (string) $attribute->value) . '"';
            }
        }
        $output .= '>';
        if (self::isHtml($element) && in_array($element->localName, self::VOID, true)) {
            return $output;
        }

        return $output . self::inner($element) . '</' . $name . '>';
    }

    private static function text(\DOMText $text): string
    {
        $parent = $text->parentNode;
        if ($parent instanceof \DOMElement && self::isHtml($parent) && in_array($parent->localName, self::RAW_TEXT, true)
            && stripos($text->data, '</' . $parent->localName) === false) {
            return $text->data; // a text changed on the DOM that would close its element is escaped below instead
        }

        return str_replace(['&', "\u{00A0}", '<', '>'], ['&amp;', '&nbsp;', '&lt;', '&gt;'], $text->data);
    }

    private static function tagName(\DOMElement $element): string
    {
        return in_array($element->namespaceURI, [null, Html5Parser::NS_HTML, Html5Parser::NS_SVG, Html5Parser::NS_MATHML], true)
            ? (string) $element->localName : $element->tagName;
    }

    /**
     * The qualified name, as the sanitizers judge it. In a namespace only the attributes the parser adjusts in SVG and MathML
     * (xlink:href…, xml:lang, xml:space, xmlns) – any other namespaced attribute (one a DOM change made) is left out rather
     * than written under a name that would mean something else when parsed again.
     */
    private static function attributeName(\DOMAttr $attribute): ?string
    {
        if ($attribute->namespaceURI === null) {
            return $attribute->nodeName;
        }
        $name = match ($attribute->namespaceURI) {
            'http://www.w3.org/XML/1998/namespace' => 'xml:' . $attribute->localName,
            'http://www.w3.org/2000/xmlns/' => $attribute->localName === 'xmlns' ? 'xmlns' : 'xmlns:' . $attribute->localName,
            'http://www.w3.org/1999/xlink' => 'xlink:' . $attribute->localName,
            default => '',
        };

        return in_array($name, self::NAMESPACED, true) ? $name : null;
    }

    private static function isHtml(\DOMElement $element): bool
    {
        return $element->namespaceURI === Html5Parser::NS_HTML || $element->namespaceURI === null;
    }
}
