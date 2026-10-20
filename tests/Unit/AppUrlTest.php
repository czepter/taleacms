<?php

declare(strict_types=1);

namespace Talea\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class AppUrlTest extends TestCase
{
    public function testLanguagePrefixIsNotDoubled(): void
    {
        // 2.3.1: in a language version, a path that already names its language keeps it (a menu link /cs/funkce, not /cs/cs/funkce)
        $urlApp = new \Talea\Core\App([]);
        $urlApp->languagePrefix = 'cs';
        $this->assertSame(array_map(fn (string $p): string => $urlApp->request->basePath() . $p, ['/cs/funkce', '/cs/funkce', '/cs/funkce', '/cs', '/image/x.svg', '/cs/css-tricks']), [$urlApp->url('cs/funkce'), $urlApp->url('/cs/funkce'), $urlApp->url('funkce'), $urlApp->url('cs'), $urlApp->url('image/x.svg'), $urlApp->url('css-tricks')], '2.3.1: App::url does not double the language prefix');
    }
}
