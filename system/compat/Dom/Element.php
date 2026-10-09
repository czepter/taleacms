<?php

declare(strict_types=1);

namespace Dom;

use Kaleta\Compat\Html5Parser;
use Kaleta\Compat\Html5Serializer;
use Kaleta\Compat\Selector;

/**
 * PHP 8.3 only – see Node.php. An element; getAttribute() returns null for a missing attribute as in PHP 8.4.
 *
 * The attribute methods take the qualified name as written, as in PHP 8.4: "xml:lang" on an HTML element is the literal
 * attribute of that name (the parser never binds a prefix there), "xlink:href" in SVG the namespaced one. The legacy
 * methods would look a prefix up in the namespaces in scope instead (and "xml" is always bound).
 *
 * @property-read string $innerHTML
 */
class Element extends \DOMElement implements Node
{
    #[\ReturnTypeWillChange]
    public function getAttribute(string $qualifiedName): ?string
    {
        return $this->attributeNode($qualifiedName)?->value;
    }

    #[\ReturnTypeWillChange]
    public function hasAttribute(string $qualifiedName): bool
    {
        return $this->attributeNode($qualifiedName) !== null;
    }

    #[\ReturnTypeWillChange]
    public function removeAttribute(string $qualifiedName): bool
    {
        $attribute = $this->attributeNode($qualifiedName);
        if ($attribute !== null) {
            $this->removeAttributeNode($attribute);
        }

        return $attribute !== null;
    }

    #[\ReturnTypeWillChange]
    public function setAttribute(string $qualifiedName, string $value): \DOMAttr|bool
    {
        if ($this->namespaceURI === Html5Parser::NS_HTML) {
            $qualifiedName = strtolower($qualifiedName);
        }
        if ($qualifiedName !== 'xmlns' && !str_contains($qualifiedName, ':')) {
            return parent::setAttribute($qualifiedName, $value);
        }
        $this->removeAttribute($qualifiedName);

        return $this->setAttributeNode(new \DOMAttr($qualifiedName, $value)) ?? true; // literal, without a namespace
    }

    private function attributeNode(string $qualifiedName): ?\DOMAttr
    {
        if ($this->namespaceURI === Html5Parser::NS_HTML) {
            $qualifiedName = strtolower($qualifiedName);
        }
        if ($qualifiedName !== 'xmlns' && !str_contains($qualifiedName, ':')) {
            // no prefix: the legacy lookup finds exactly the attribute without a namespace of that name
            $attribute = parent::getAttributeNode($qualifiedName);

            return $attribute instanceof \DOMAttr ? $attribute : null;
        }
        foreach ($this->attributes as $attribute) {
            if ($attribute instanceof \DOMAttr && $attribute->nodeName === $qualifiedName) {
                return $attribute;
            }
        }

        return null;
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
