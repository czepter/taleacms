<?php

declare(strict_types=1);

namespace Dom;

/**
 * PHP 8.3 only – see Node.php. The result of querySelectorAll(): a fixed list (changing the
 * document does not change it, as with querySelectorAll() in PHP 8.4).
 *
 * @implements \IteratorAggregate<int, Element>
 */
final class NodeList implements \IteratorAggregate, \Countable
{
    public readonly int $length;

    /** @var list<Element> */
    private readonly array $nodes;

    /** @param list<\DOMElement> $nodes */
    public function __construct(array $nodes)
    {
        $this->nodes = array_values(array_filter($nodes, fn (\DOMElement $node): bool => $node instanceof Element));
        $this->length = count($this->nodes);
    }

    public function item(int $index): ?Element
    {
        return $this->nodes[$index] ?? null;
    }

    public function count(): int
    {
        return $this->length;
    }

    /** @return \ArrayIterator<int, Element> */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->nodes);
    }
}
