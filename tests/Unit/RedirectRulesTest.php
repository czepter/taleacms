<?php

declare(strict_types=1);

namespace Talea\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Talea\Core\RedirectRules;

final class RedirectRulesTest extends TestCase
{
    private static function rule(string $match, string $from, string $to): array
    {
        return ['match_type' => $match, 'from_path' => $from, 'to_path' => $to];
    }

    public function testTargets(): void
    {
        $this->assertSame('news/a/b', RedirectRules::target(self::rule('starts', 'blog/', 'news/'), 'blog/a/b'));
        $this->assertSame('projects/12', RedirectRules::target(self::rule('regex', '^gallery/(\d+)$', 'projects/$1'), 'gallery/12'));
        $this->assertSame('/', RedirectRules::target(self::rule('ends', '.php', '/'), 'x/index.php'));
        $this->assertSame('/new', RedirectRules::target(self::rule('contains', 'old', '/new'), 'OLD-prices'));
        $this->assertNull(RedirectRules::target(self::rule('starts', 'blog/', 'news/'), 'shop/blog/a'));
        $this->assertSame('x', RedirectRules::target(self::rule('exact', 'a', 'x'), 'A'));
    }

    public function testValidate(): void
    {
        $this->assertNotNull(RedirectRules::validate('(', 'x', 'regex', 301));
        $this->assertNotNull(RedirectRules::validate('^a', 'a/b', 'regex', 301), 'loop');
        $this->assertNull(RedirectRules::validate('a', '', 'exact', 410));
        $this->assertNotNull(RedirectRules::validate('a', '', 'exact', 301));
        $this->assertNotNull(RedirectRules::validate('a', '/a/', 'exact', 301));
        $this->assertNotNull(RedirectRules::validate(str_repeat('a', 256), 'x', 'contains', 301));
        $this->assertNotNull(RedirectRules::validate('a', 'x', 'fuzzy', 301));
        $this->assertNotNull(RedirectRules::validate('a', 'x', 'exact', 303));
        $this->assertNull(RedirectRules::validate('blog/', 'news/', 'starts', 301));
    }

    public function testBacktrackingReturnsNull(): void
    {
        $old = ini_set('pcre.backtrack_limit', '1000');
        $oldJit = ini_set('pcre.jit', '0');
        try {
            $this->assertNull(RedirectRules::target(self::rule('regex', '^(a+)+$', 'x'), str_repeat('a', 30) . '!'));
        } finally {
            ini_set('pcre.backtrack_limit', (string) $old);
            ini_set('pcre.jit', (string) $oldJit);
        }
    }

    public function testSubstitutionStaysOnTheSite(): void
    {
        $rule = self::rule('regex', '^go/(.*)$', '$1');
        $this->assertNull(RedirectRules::target($rule, 'go/https://evil.example'), 'a scheme from a capture');
        $this->assertNull(RedirectRules::target($rule, 'go///evil.example'), 'protocol-relative');
        $this->assertNull(RedirectRules::target($rule, "go/\\evil.example"), 'backslash');
        $this->assertNull(RedirectRules::target($rule, "go/a\r\nSet-Cookie: x"), 'control characters');
        $this->assertSame('projects/12', RedirectRules::target(self::rule('regex', '^gallery/(\d+)$', 'projects/$1'), 'gallery/12'));
        $this->assertSame('https://new.example/12', RedirectRules::target(self::rule('regex', '^g/(\d+)$', 'https://new.example/$1'), 'g/12'), 'an external template may leave the site');
        $this->assertNull(RedirectRules::target(self::rule('regex', '^g/(.*)$', 'https://new.example/$1'), "g/a\x01b"));
        $this->assertNull(RedirectRules::target(self::rule('starts', 'go/', '/'), 'go//evil.example'), 'the rest of a starts rule is checked too');
    }

    public function testNoCatchAllRules(): void
    {
        foreach (['contains', 'starts', 'ends'] as $match) {
            $this->assertNotNull(RedirectRules::validate('/', 'x', $match, 301), $match);
            $this->assertNotNull(RedirectRules::validate('//', '', $match, 410), $match);
        }
        $this->assertNotNull(RedirectRules::validate('.*', '', 'regex', 410), 'matches the empty string');
        $this->assertNotNull(RedirectRules::validate('^(a|b)?', '', 'regex', 451));
        $this->assertNotNull(RedirectRules::validate('.+', 'x', 'regex', 301), 'matches every address');
        $this->assertNull(RedirectRules::validate('^old/', '', 'regex', 410));
    }

    public function testBacktrackingWithJitReturnsNull(): void
    {
        $old = ini_set('pcre.backtrack_limit', '1000');
        try {
            $this->assertNull(RedirectRules::target(self::rule('regex', '^(a+)+$', 'x'), str_repeat('a', 40) . '!'));
        } finally {
            ini_set('pcre.backtrack_limit', (string) $old);
        }
    }
}
