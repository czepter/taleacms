<?php

declare(strict_types=1);

namespace Dom;

use Kaleta\Compat\Html5Parser;
use Kaleta\Compat\Html5Serializer;
use Kaleta\Compat\Selector;

/**
 * PHP 8.3 only – see Node.php. An element; getAttribute() returns null for a missing attribute as in PHP 8.4.
 *
 * @property-read string $innerHTML
 */
class Element extends \DOMElement implements Node
{
    #[\ReturnTypeWillChange]
    public function getAttribute(string $qualifiedName): ?string
    {
        if ($this->namespaceURI === Html5Parser::NS_HTML) {
            $qualifiedName = strtolower($qualifiedName);
        }

        return $this->hasAttribute($qualifiedName) ? parent::getAttribute($qualifiedName) : null;
    }

    public function __get(string $name): mixed
    {
        if ($name === 'innerHTML') {
            return Html5Serializer::inner($this);
        }
        trigger_error('Undefined property: ' . static::class . '::$' . $name, E_USER_WARNING);

        return null;
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

    public function closest(string $selectors): ?Element
    {
        $found = Selector::closest($this, $selectors);

        return $found instanceof Element ? $found : null;
    }

    public function matches(string $selectors): bool
    {
        return Selector::matches($this, $selectors);
    }
}
