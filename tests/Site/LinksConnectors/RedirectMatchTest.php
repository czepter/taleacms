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

        $plain = $visitor->get('/qzx-no-such-page');
        $this->assertSame(404, $plain->status);
        $gone = $visitor->get('/retired');
        $this->assertSame(410, $gone->status);
        $this->assertStringContainsString('Page not found', $gone->text());
        $this->assertSame(substr($plain->text(), 0, 200), substr($gone->text(), 0, 200), 'a 410 shows the site\'s 404 page');

        $this->assertSame(404, $visitor->get('/off/x')->status, 'an inactive rule does not apply');
    }

    public function testRegexExactBeforeOthersAndOtherStatuses(): void
    {
        $site = $this->site();
        $this->rule('^gallery/(\d+)$', 'projects/$1', 'regex', 301);
        $this->rule('handbook/', 'manual/', 'starts', 301);
        $this->rule('handbook/start', 'start-page', 'exact', 301);
        $this->rule('temp/', 'elsewhere', 'starts', 307);
        $this->rule('legal/', '', 'starts', 451);
        $this->rule('^go/(.*)$', '$1', 'regex', 301);
        $site->clearPageCache();
        $visitor = $site->client('visitor');

        $r = $visitor->get('/gallery/12');
        $this->assertSame([301, $site->base . '/projects/12'], [$r->status, $r->redirect], 'a regex rule with a capture');
        $r = $visitor->get('/handbook/start');
        $this->assertSame($site->base . '/start-page', $r->redirect, 'the exact rule wins over a starts rule');
        $r = $visitor->get('/handbook/other');
        $this->assertSame($site->base . '/manual/other', $r->redirect);
        $r = $visitor->get('/temp/a');
        $this->assertSame([307, $site->base . '/elsewhere'], [$r->status, $r->redirect], '307 is kept');
        $this->assertSame(451, $visitor->get('/legal/x')->status);
        $this->assertSame(404, $visitor->get('/go/' . rawurlencode('//evil.example'))->status, 'a capture cannot make a protocol-relative target');
    }
}
