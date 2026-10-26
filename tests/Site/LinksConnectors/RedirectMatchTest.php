<?php

declare(strict_types=1);

namespace Talea\Tests\Site\LinksConnectors;

use PHPUnit\Framework\Attributes\Group;

/** Redirect match types, 410 and switching a rule off. */
#[Group('site')]
final class RedirectMatchTest extends \Talea\Tests\Site\Support\SiteTestCase
{
    private function rule(string $from, string $to, string $match, int $type, bool $active = true): void
    {
        $this->site()->exec('INSERT INTO tl_redirects (public_id, from_path, to_path, match_type, type, active, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [bin2hex(random_bytes(16)), $from, $to, $match, $type, $active ? 1 : 0, date('Y-m-d H:i:s')]);
    }

    public function testMatchTypesGoneAndInactive(): void
    {
        $site = $this->site();
        $this->rule('blog/', 'news/', 'starts', 301);
        $this->rule('retired', '', 'exact', 410);
        $this->rule('off/', 'news/', 'starts', 301, false);
        $site->clearPageCache();
        $visitor = $site->client('visitor');

        $moved = $visitor->get('/blog/x');
        $this->assertSame(301, $moved->status);
        $this->assertSame($site->base . '/news/x', $moved->redirect);
        $this->assertNotNull($site->value("SELECT last_hit_at FROM tl_redirects WHERE from_path = 'blog/'"), 'last_hit_at is set');
        $this->assertSame('1', (string) $site->value("SELECT hits FROM tl_redirects WHERE from_path = 'blog/'"));

        $gone = $visitor->get('/retired');
        $this->assertSame(410, $gone->status);
        $this->assertSame($visitor->get('/qzx-no-such-page')->text() !== '', $gone->text() !== '');
        $this->assertStringContainsString('Page not found', $gone->text());

        $this->assertSame(404, $visitor->get('/off/x')->status, 'an inactive rule does not apply');
    }
}
