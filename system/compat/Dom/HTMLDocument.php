<?php

declare(strict_types=1);

namespace Dom;

use Kaleta\Compat\Html5Parser;
use Kaleta\Compat\Html5Serializer;
use Kaleta\Compat\Selector;

/** Elements without a namespace (for XPath), the value PHP 8.4 gives the constant. */
const HTML_NO_DEFAULT_NS = 2147483648;

/**
 * PHP 8.3 only – see Node.php. An HTML document parsed by Kaleta\Compat\Html5Parser.
 *
 * @property-read HTMLElement|null $body
 * @property-read HTMLElement|null $head
 */
final class HTMLDocument extends \DOMDocument implements Node
{
    /** Parsed with Dom\HTML_NO_DEFAULT_NS: new elements get no namespace either. */
    private bool $noDefaultNamespace = false;

    public static function createFromString(string $source, int $options = 0, ?string $overrideEncoding = null): self
    {
        $document = new self('1.0', 'UTF-8');
        $document->noDefaultNamespace = ($options & HTML_NO_DEFAULT_NS) !== 0;
        $document->registerNodeClass(\DOMElement::class, HTMLElement::class);
        $document->registerNodeClass(\DOMText::class, Text::class);
        $document->registerNodeClass(\DOMComment::class, Comment::class);
        Html5Parser::parse($document, Html5Parser::utf8($source, $overrideEncoding), ($options & HTML_NO_DEFAULT_NS) === 0);

        return $document;
    }

    public function __get(string $name): mixed
    {
        if ($name === 'body' || $name === 'head') {
            foreach ($this->documentElement?->childNodes ?? [] as $child) {
                if ($child instanceof HTMLElement && $child->localName === $name) {
                    return $child;
                }
            }

            return null;
        }
        trigger_error('Undefined property: ' . self::class . '::$' . $name, E_USER_WARNING);

        return null;
    }

    public function createElement(string $localName, string $value = ''): HTMLElement
    {
        // a name with a colon (Word's <o:p>) keeps it in the local name, as in PHP 8.4 – the legacy DOM would split it into a prefix
        $element = $this->noDefaultNamespace || str_contains($localName, ':')
            ? parent::createElement(strtolower($localName), $value)
            : $this->createElementNS(Html5Parser::NS_HTML, strtolower($localName), $value);
        if (!$element instanceof HTMLElement) {
            throw new \DOMException('Invalid element name: ' . $localName, 5); // INVALID_CHARACTER_ERR
        }

        return $element;
    }

    public function saveHtml(?\DOMNode $node = null): string
    {
        return $node === null ? Html5Serializer::inner($this) : Html5Serializer::outer($node);
    }

    public function querySelector(string $selectors): ?Element
    {
        $found = Selector::select($this, $selectors, true)[0] ?? null;

        return $found instanceof Element ? $found : null;
    }

    public function querySelectorAll(string $selectors): NodeList
    {
        return new NodeList(Selector::select($this, $selectors));
    }
}
