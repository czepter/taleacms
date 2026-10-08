<?php
/**
 * PHPStan only (phpstan.neon.dist, scanFiles) – never loaded. Kaleta runs on PHP 8.3, which lacks the HTML5 DOM of 8.4
 * (Dom\HTMLDocument…); system/compat/Dom provides it there. With phpVersion 80300 PHPStan does not know the 8.4 classes,
 * so this file declares them with their PHP 8.4 types – but only the members the compat classes implement. Code that
 * uses another member of Dom\ fails static analysis: add it to system/compat/Dom (and a test) first, then here.
 * Document, CharacterData, Attr and NamedNodeMap exist here only as types of properties: code names
 * only the Dom\ classes that have a file in system/compat/Dom (tools/unit-tests.php checks it).
 */

namespace Dom;

const HTML_NO_DEFAULT_NS = 2147483648;

abstract class Node
{
    public int $nodeType;
    public string $nodeName;
    public ?Node $parentNode;
    public ?Element $parentElement;
    /** @var NodeList<Node> */
    public NodeList $childNodes;
    public ?Node $firstChild;
    public ?Node $lastChild;
    public ?Node $previousSibling;
    public ?Node $nextSibling;
    public ?Document $ownerDocument;
    public string $textContent;

    public function appendChild(Node $node): Node {}

    public function insertBefore(Node $node, ?Node $child): Node {}

    public function replaceChild(Node $node, Node $child): Node {}

    public function removeChild(Node $child): Node {}

    public function cloneNode(bool $deep = false): static {}

    public function contains(?Node $other): bool {}

    public function hasChildNodes(): bool {}
}

final class Attr extends Node
{
    public string $name;
    public string $value;
    public string $localName;
    public ?string $namespaceURI;
}

/** @implements \IteratorAggregate<string, Attr> */
final class NamedNodeMap implements \IteratorAggregate, \Countable
{
    public int $length;

    public function item(int $index): ?Attr {}

    public function getNamedItem(string $qualifiedName): ?Attr {}

    public function count(): int {}

    /** @return \Iterator<string, Attr> */
    public function getIterator(): \Iterator {}
}

/**
 * @template-covariant TNode of Node
 * @implements \IteratorAggregate<int, TNode>
 */
class NodeList implements \IteratorAggregate, \Countable
{
    public int $length;

    /** @return TNode|null */
    public function item(int $index): ?Node {}

    public function count(): int {}

    /** @return \Iterator<int, TNode> */
    public function getIterator(): \Iterator {}
}

class Element extends Node
{
    public ?string $namespaceURI;
    public ?string $prefix;
    public string $localName;
    public string $tagName;
    public NamedNodeMap $attributes;
    public ?Element $firstElementChild;
    public ?Element $lastElementChild;
    public ?Element $previousElementSibling;
    public ?Element $nextElementSibling;
    public string $innerHTML;

    public function getAttribute(string $qualifiedName): ?string {}

    public function setAttribute(string $qualifiedName, string $value): void {}

    public function hasAttribute(string $qualifiedName): bool {}

    public function removeAttribute(string $qualifiedName): void {}

    /** @return list<string> */
    public function getAttributeNames(): array {}

    public function querySelector(string $selectors): ?Element {}

    /** @return NodeList<Element> */
    public function querySelectorAll(string $selectors): NodeList {}

    public function closest(string $selectors): ?Element {}

    public function matches(string $selectors): bool {}

    public function remove(): void {}

    public function replaceWith(Node|string ...$nodes): void {}

    public function before(Node|string ...$nodes): void {}

    public function after(Node|string ...$nodes): void {}

    public function append(Node|string ...$nodes): void {}

    public function prepend(Node|string ...$nodes): void {}
}

class HTMLElement extends Element
{
}

abstract class CharacterData extends Node
{
    public string $data;
    public int $length;

    public function remove(): void {}

    public function replaceWith(Node|string ...$nodes): void {}
}

class Text extends CharacterData
{
}

class Comment extends CharacterData
{
}

abstract class Document extends Node
{
    public ?Element $documentElement;
    public ?HTMLElement $body;
    public ?HTMLElement $head;

    public function createElement(string $localName): Element {}

    public function createComment(string $data): Comment {}

    public function createTextNode(string $data): Text {}

    public function querySelector(string $selectors): ?Element {}

    /** @return NodeList<Element> */
    public function querySelectorAll(string $selectors): NodeList {}
}

final class HTMLDocument extends Document
{
    public static function createFromString(string $source, int $options = 0, ?string $overrideEncoding = null): HTMLDocument {}

    public function createElement(string $localName): HTMLElement {}

    public function saveHtml(?Node $node = null): string {}
}
