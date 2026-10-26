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
}
