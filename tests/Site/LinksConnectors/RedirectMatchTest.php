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

    public function testOtherKindsOnlyAnswerAMissingAddress(): void
    {
        $site = $this->site();
        $site->exec('DELETE FROM tl_redirects');
        $site->mcp('create_page', ['title' => 'Live page', 'slug' => 'live-page', 'visible' => true, 'in_menu' => false, 'content' => '<p>Live.</p>']);
        $this->rule('live', 'elsewhere', 'starts', 301);
        $this->rule('^live-(.*)$', 'other/$1', 'regex', 301);
        $site->clearPageCache();
        $visitor = $site->client('visitor');

        $this->assertSame(200, $visitor->get('/live-page')->status, 'a live page is never shadowed by a non-exact rule');
        $slash = $visitor->get('/live-page/');
        $this->assertSame(301, $slash->status, 'the slash redirect still runs with non-exact rules present');
        $this->assertStringEndsWith('/live-page', (string) $slash->redirect);
        $this->assertSame('0', (string) $site->value('SELECT SUM(hits) FROM tl_redirects'), 'no rule was used for the live page');
        $this->assertStringEndsWith('/elsewhere', (string) $visitor->get('/live-gone')->redirect, 'a missing address still meets them');
    }

    public function testLegacyQueryRulesAndSlugChanges(): void
    {
        $site = $this->site();
        $this->rule('?p=7', 'news', 'exact', 301, false);
        $this->rule('?p=8', '', 'exact', 410);
        $this->rule('?p=9', 'news', 'exact', 302);
        $site->clearPageCache();
        $visitor = $site->client('visitor');
        $this->assertSame(200, $visitor->get('/?p=7')->status, 'a switched-off ?p= rule does not apply');
        $this->assertSame(410, $visitor->get('/?p=8')->status, 'a ?p= rule may say gone');
        $this->assertSame(302, $visitor->get('/?p=9')->status, 'and keeps its type');

        // a slug change over an address marked gone makes it a plain 301 again
        $this->rule('summer', '', 'exact', 410);
        $site->mcp('create_page', ['title' => 'Summer', 'slug' => 'summer', 'visible' => true, 'in_menu' => false, 'content' => '<p>Sun.</p>']);
        $site->mcp('update_page', ['id' => (string) $site->value("SELECT public_id FROM tl_pages WHERE slug = 'summer'"), 'slug' => 'summer-two']);
        $this->assertSame('summer-two|301|1|exact', $site->value("SELECT CONCAT(to_path, '|', type, '|', active, '|', match_type) FROM tl_redirects WHERE from_path = 'summer'"));
        $site->clearPageCache();
        $this->assertStringEndsWith('/summer-two', (string) $visitor->get('/summer')->redirect);
    }
}
