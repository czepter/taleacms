<?php

declare(strict_types=1);

namespace Kaleta\Compat;

/**
 * CSS selectors for querySelector(), querySelectorAll(), closest() and matches() of the Dom\ classes on PHP 8.3
 * (system/compat): type and universal selectors, #id, .class, [attribute] with = ~= |= ^= $= *= (and the "i" flag),
 * :not(…), and the descendant, child (>), next-sibling (+) and subsequent-sibling (~) combinators. Anything else is
 * a syntax error (a \DOMException, as in PHP 8.4), so a new selector in the code fails loudly in the tests on 8.3.
 * Like the browser, the content of a <template> is not searched.
 */
final class Selector
{
    /**
     * Attribute values HTML compares case-insensitively in selectors (the "case-sensitivity of selectors" list).
     */
    private const array CASE_INSENSITIVE = ['accept', 'accept-charset', 'align', 'alink', 'axis', 'bgcolor', 'charset', 'checked', 'clear', 'codetype', 'color',
        'compact', 'declare', 'defer', 'dir', 'direction', 'disabled', 'enctype', 'face', 'frame', 'hreflang', 'http-equiv', 'lang', 'language', 'link', 'media',
        'method', 'multiple', 'nohref', 'noresize', 'noshade', 'nowrap', 'readonly', 'rel', 'rev', 'rules', 'scope', 'scrolling', 'selected', 'shape', 'target',
        'text', 'type', 'valign', 'valuetype', 'vlink'];

    /** @var array<string, list<list<array{0: string, 1: array{tag: ?string, conditions: list<array<int, mixed>>}}>>> parsed selectors */
    private static array $cache = [];

    private string $source;
    private int $pos = 0;

    private function __construct(string $source)
    {
        $this->source = $source;
    }

    /** @return list<\DOMElement> the matching descendants of $scope in document order */
    public static function select(\DOMNode $scope, string $selectors, bool $first = false): array
    {
        $list = self::parse($selectors);
        $found = [];
        $stack = [];
        for ($node = $scope->firstChild; $node !== null || $stack !== [];) {
            if ($node === null) {
                $node = array_pop($stack);
                continue;
            }
            if ($node instanceof \DOMElement) {
                if (self::matchesList($node, $list)) {
                    $found[] = $node;
                    if ($first) {
                        return $found;
                    }
                }
                if ($node->firstChild !== null && !self::isTemplate($node)) {
                    $stack[] = $node->nextSibling;
                    $node = $node->firstChild;
                    continue;
                }
            }
            $node = $node->nextSibling;
        }

        return $found;
    }

    public static function matches(\DOMElement $element, string $selectors): bool
    {
        return self::matchesList($element, self::parse($selectors));
    }

    public static function closest(\DOMElement $element, string $selectors): ?\DOMElement
    {
        $list = self::parse($selectors);
        for ($node = $element; $node instanceof \DOMElement; $node = $node->parentNode) {
            if (self::matchesList($node, $list)) {
                return $node;
            }
        }

        return null;
    }

    /** @return list<list<array{0: string, 1: array{tag: ?string, conditions: list<array<int, mixed>>}}>> */
    private static function parse(string $selectors): array
    {
        if (!isset(self::$cache[$selectors])) {
            $parser = new self($selectors);
            $list = $parser->selectorList();
            if ($parser->pos < strlen($selectors)) {
                throw new \DOMException('Invalid selector: ' . $selectors, 12); // SYNTAX_ERR
            }
            self::$cache[$selectors] = $list;
        }

        return self::$cache[$selectors];
    }

    /** @return list<list<array{0: string, 1: array{tag: ?string, conditions: list<array<int, mixed>>}}>> */
    private function selectorList(): array
    {
        $list = [];
        do {
            $this->space();
            $list[] = $this->complex();
            $this->space();
        } while ($this->eat(','));

        return $list;
    }

    /** @return list<array{0: string, 1: array{tag: ?string, conditions: list<array<int, mixed>>}}> [combinator, compound] from left to right */
    private function complex(): array
    {
        $parts = [['', $this->compound()]];
        while (true) {
            $space = $this->space();
            $c = $this->source[$this->pos] ?? '';
            if ($c === '>' || $c === '+' || $c === '~') {
                $this->pos++;
                $this->space();
                $parts[] = [$c, $this->compound()];
            } elseif ($space && $c !== '' && $c !== ',' && $c !== ')') {
                $parts[] = [' ', $this->compound()];
            } else {
                return $parts;
            }
        }
    }

    /** @return array{tag: ?string, conditions: list<array<int, mixed>>} */
    private function compound(): array
    {
        $tag = null;
        $conditions = [];
        if ($this->eat('*')) {
            $tag = '*';
        } elseif (($name = $this->identifier()) !== '') {
            $tag = strtolower($name);
        }
        while (($c = $this->source[$this->pos] ?? '') !== '') {
            if ($c === '#' || $c === '.') {
                $this->pos++;
                $name = $this->identifier();
                if ($name === '') {
                    $this->fail();
                }
                $conditions[] = [$c === '#' ? 'id' : 'class', $name];
            } elseif ($c === '[') {
                $this->pos++;
                $conditions[] = $this->attribute();
            } elseif ($c === ':') {
                $this->pos++;
                if (strtolower($this->identifier()) !== 'not' || !$this->eat('(')) {
                    $this->fail();
                }
                $conditions[] = ['not', $this->selectorList()];
                if (!$this->eat(')')) {
                    $this->fail();
                }
            } else {
                break;
            }
        }
        if ($tag === null && $conditions === []) {
            $this->fail();
        }

        return ['tag' => $tag === '*' ? null : $tag, 'conditions' => $conditions];
    }

    /** @return array<int, mixed> ['attr', name, operator, value, case-insensitive] */
    private function attribute(): array
    {
        $this->space();
        $name = strtolower($this->identifier());
        if ($name === '') {
            $this->fail();
        }
        $this->space();
        $operator = '';
        $value = '';
        $insensitive = false;
        foreach (['~=', '|=', '^=', '$=', '*=', '='] as $candidate) {
            if (substr($this->source, $this->pos, strlen($candidate)) === $candidate) {
                $operator = $candidate;
                $this->pos += strlen($candidate);
                break;
            }
        }
        if ($operator !== '') {
            $this->space();
            $quote = $this->source[$this->pos] ?? '';
            $value = $quote === '"' || $quote === "'" ? $this->string($quote) : $this->identifier();
            $this->space();
            if (in_array($this->source[$this->pos] ?? '', ['i', 'I', 's', 'S'], true)) {
                $insensitive = strtolower($this->source[$this->pos]) === 'i';
                $this->pos++;
                $this->space();
            }
        }
        if (!$this->eat(']')) {
            $this->fail();
        }

        return ['attr', $name, $operator, $value, $insensitive || in_array($name, self::CASE_INSENSITIVE, true)];
    }

    private function identifier(): string
    {
        $name = '';
        while (($c = $this->source[$this->pos] ?? '') !== '') {
            if ($c === '\\' && $this->pos + 1 < strlen($this->source)) {
                $name .= $this->source[$this->pos + 1];
                $this->pos += 2;
            } elseif (ctype_alnum($c) || $c === '-' || $c === '_' || ord($c) >= 0x80) {
                $name .= $c;
                $this->pos++;
            } else {
                break;
            }
        }

        return $name;
    }

    private function string(string $quote): string
    {
        $this->pos++;
        $value = '';
        while (($c = $this->source[$this->pos] ?? '') !== $quote) {
            if ($c === '') {
                $this->fail();
            }
            if ($c === '\\' && $this->pos + 1 < strlen($this->source)) {
                $c = $this->source[++$this->pos];
            }
            $value .= $c;
            $this->pos++;
        }
        $this->pos++;

        return $value;
    }

    private function space(): bool
    {
        $n = strspn($this->source, " \t\n\r\f", $this->pos);
        $this->pos += $n;

        return $n > 0;
    }

    private function eat(string $c): bool
    {
        if (($this->source[$this->pos] ?? '') === $c) {
            $this->pos++;

            return true;
        }

        return false;
    }

    private function fail(): never
    {
        throw new \DOMException('Invalid selector: ' . $this->source, 12);
    }

    /** @param list<list<array{0: string, 1: array{tag: ?string, conditions: list<array<int, mixed>>}}>> $list */
    private static function matchesList(\DOMElement $element, array $list): bool
    {
        foreach ($list as $complex) {
            if (self::matchesComplex($element, $complex, count($complex) - 1)) {
                return true;
            }
        }

        return false;
    }

    /** @param list<array{0: string, 1: array{tag: ?string, conditions: list<array<int, mixed>>}}> $parts */
    private static function matchesComplex(\DOMElement $element, array $parts, int $index): bool
    {
        if (!self::matchesCompound($element, $parts[$index][1])) {
            return false;
        }
        if ($index === 0) {
            return true;
        }
        $combinator = $parts[$index][0];
        if ($combinator === '>' || $combinator === ' ') {
            for ($node = $element->parentNode; $node instanceof \DOMElement; $node = $node->parentNode) {
                if (self::matchesComplex($node, $parts, $index - 1)) {
                    return true;
                }
                if ($combinator === '>') {
                    return false;
                }
            }

            return false;
        }
        for ($node = $element->previousElementSibling; $node !== null; $node = $node->previousElementSibling) {
            if (self::matchesComplex($node, $parts, $index - 1)) {
                return true;
            }
            if ($combinator === '+') {
                return false;
            }
        }

        return false;
    }

    /** @param array{tag: ?string, conditions: list<array<int, mixed>>} $compound */
    private static function matchesCompound(\DOMElement $element, array $compound): bool
    {
        if ($compound['tag'] !== null && strtolower((string) $element->localName) !== $compound['tag']) {
            return false;
        }
        foreach ($compound['conditions'] as $condition) {
            $ok = match ($condition[0]) {
                'id' => $element->hasAttribute('id') && $element->getAttribute('id') === $condition[1],
                'class' => in_array($condition[1], preg_split('/[ \t\n\f\r]+/', (string) $element->getAttribute('class'), -1, PREG_SPLIT_NO_EMPTY) ?: [], true),
                'not' => is_array($condition[1]) && !self::matchesList($element, $condition[1]),
                default => self::matchesAttribute($element, $condition),
            };
            if (!$ok) {
                return false;
            }
        }

        return true;
    }

    /** @param array<int, mixed> $condition */
    private static function matchesAttribute(\DOMElement $element, array $condition): bool
    {
        [, $name, $operator, $expected, $insensitive] = $condition;
        $name = (string) $name;
        if (!$element->hasAttribute($name)) {
            return false;
        }
        if ($operator === '') {
            return true;
        }
        $value = (string) $element->getAttribute($name); // the Dom\ classes of PHP 8.3 return null for a missing attribute
        $expected = (string) $expected;
        if ($insensitive) {
            $value = strtolower($value);
            $expected = strtolower($expected);
        }

        return match ($operator) {
            '=' => $value === $expected,
            '~=' => $expected !== '' && in_array($expected, preg_split('/[ \t\n\f\r]+/', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [], true),
            '|=' => $value === $expected || str_starts_with($value, $expected . '-'),
            '^=' => $expected !== '' && str_starts_with($value, $expected),
            '$=' => $expected !== '' && str_ends_with($value, $expected),
            default => $expected !== '' && str_contains($value, $expected), // *=
        };
    }

    private static function isTemplate(\DOMElement $element): bool
    {
        return $element->localName === 'template' && ($element->namespaceURI === Html5Parser::NS_HTML || $element->namespaceURI === null);
    }
}
