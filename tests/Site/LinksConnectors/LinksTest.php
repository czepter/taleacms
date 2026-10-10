<?php

declare(strict_types=1);

namespace Kaleta\Tests\Site\LinksConnectors;

use PHPUnit\Framework\Attributes\Group;

/** Links that look after themselves (was: section 80, 2.14): automatic redirects, orphan pages, broken links in builds. */
#[Group('site')]
final class LinksTest extends \Kaleta\Tests\Site\Support\SiteTestCase
{
    use FakeServices;

    private function pageId(string $slug): int
    {
        return (int) $this->site()->value('SELECT page_id FROM ka_pages WHERE slug = ?', [$slug]);
    }

    public function testTheNotFoundListSuggestsTheMovedPage(): void
    {
        $site = $this->site();
        $site->mcp('create_page', ['title' => 'Reference portfolio', 'slug' => 'reference-portfolio', 'visible' => true, 'in_menu' => false, 'content' => '<p>Our references.</p>']);
        $id = $this->pageId('reference-portfolio');
        $this->assertGreaterThan(0, $id);
        $site->exec("UPDATE ka_pages SET slug = 'services/reference-portfolio' WHERE page_id = ?", [$id]);
        $site->exec("DELETE FROM ka_redirects WHERE from_path LIKE '%reference-portfolio%'");
        $site->exec('DELETE FROM ka_not_found');
        $site->exec("DELETE FROM ka_events WHERE type = 'redirect.auto'");
        $site->clearPageCache();
        $visitor = $site->client('visitor');
        for ($i = 0; $i < 3; $i++) {
            foreach (['/reference-portfolio', '/reference-portfolio-2019', '/qzx-random-address'] as $path) {
                $visitor->get($path);
            }
        }

        $screen = $this->assertPage('/admin.php?module=redirects', 200, '/services/reference-portfolio', message: 'Redirects: the 404 list shows the page the visitor probably meant');
        $this->assertStringContainsString('name="redirect_auto"', $screen->body, 'the setting for redirects by themselves');
        $this->assertStringContainsString('Create redirect', $screen->body, 'one-click create');
        $this->assertStringContainsString('no similar page', $screen->body, 'no suggestion for a random address');

        $json = $this->mcpJson('list_redirects');
        $this->assertStringContainsString('"suggestion":"/services/reference-portfolio","score":90', $json, 'MCP: list_redirects carries the suggestion and its score (exact path)');
        $this->assertStringContainsString('"suggestion":"/services/reference-portfolio","score":85', $json, 'MCP: list_redirects carries the suggestion and its score (similar path)');
        $this->assertMatchesRegularExpression('/qzx-random-address[^}]*"suggestion":null/', $json, 'MCP: no suggestion for a random address');
    }

    public function testRedirectsByThemselvesAreOffByDefaultAndTheDailyJobCreatesThem(): void
    {
        $site = $this->site();
        $tasks = $this->runJob('redirects');
        $this->assertStringContainsString('redirects: off', $tasks, 'redirects by themselves are off by default (job output)');
        $this->assertSame('0', (string) $site->value('SELECT COUNT(*) FROM ka_redirects WHERE auto_score IS NOT NULL'), 'redirects by themselves are off by default (no redirect created)');

        $this->adminPost('/admin.php?module=redirects&action=settings', ['redirect_auto' => '1', 'redirect_auto_threshold' => '90'], '/admin.php?module=redirects');
        $this->assertSame('1,90', $site->value("SELECT GROUP_CONCAT(value ORDER BY name) FROM ka_settings WHERE name IN ('redirect_auto', 'redirect_auto_threshold')"), 'the setting is saved from the Redirects screen');

        $this->runJob('redirects');
        $this->assertSame('services/reference-portfolio|301|90|0', $site->value("SELECT CONCAT((SELECT CONCAT(to_path, '|', type, '|', auto_score) FROM ka_redirects WHERE from_path = 'reference-portfolio'), '|', (SELECT COUNT(*) FROM ka_redirects WHERE from_path IN ('reference-portfolio-2019', 'qzx-random-address')))"),
            'the daily job creates the redirect above the threshold, not below it');

        $old = $site->client('visitor')->get('/reference-portfolio');
        $this->assertSame(301, $old->status, 'the old address redirects (status)');
        $this->assertSame($site->base . '/services/reference-portfolio', $old->redirect, 'the old address redirects (target)');
        $this->assertSame('0', (string) $site->value("SELECT COUNT(*) FROM ka_not_found WHERE path = 'reference-portfolio'"), 'the address left the 404 log');

        $this->assertSame('1|1', $site->value("SELECT CONCAT((SELECT COUNT(*) FROM ka_events WHERE type = 'redirect.auto' AND data LIKE '%\"from\":\"/reference-portfolio\",\"to\":\"/services/reference-portfolio\",\"score\":90%'), '|', (SELECT COUNT(*) FROM ka_change_log WHERE module = 'redirects' AND action = 'auto' AND description LIKE '%reference-portfolio%'))"),
            'the automatic redirect is in the change log and the event redirect.auto');
        $this->assertPage('/admin.php?module=redirects', 200, 'automatic, score 90', message: 'the Redirects list marks it automatic with the score (undo = delete)');

        $this->adminPost('/admin.php?module=redirects&action=save', ['from_path' => '/reference-portfolio-2019', 'to_path' => '/services/reference-portfolio', 'type' => '301'], '/admin.php?module=redirects');
        $this->assertSame('services/reference-portfolio|1', $site->value("SELECT CONCAT(to_path, '|', auto_score IS NULL) FROM ka_redirects WHERE from_path = 'reference-portfolio-2019'"), 'one click creates the suggested redirect by hand – not marked automatic');

        $site->mcp('update_settings', ['settings' => ['redirect_auto' => false]]);
        $this->assertSame('0', $site->settingValue('redirect_auto'), 'MCP: update_settings switches the automatic redirects off');
    }

    public function testOrphanPagesAndInternalLinkSuggestions(): void
    {
        $site = $this->site();
        $site->mcp('create_page', ['title' => 'Reference portfolio detail', 'slug' => 'portfolio-orphan', 'visible' => true, 'in_menu' => false, 'content' => '<p>Detail.</p>']);
        $site->clearPageCache();
        $audit = $this->assertPage('/admin.php?module=audit', 200, 'Pages nobody links to', message: 'Site audit lists the orphan page');
        $this->assertStringContainsString('Page “Reference portfolio detail”', $audit->body, 'the orphan is named with where to fix it');

        $json = $this->mcpJson('suggest_internal_links', ['limit' => 100]);
        $this->assertStringContainsString('"page":"/portfolio-orphan"', $json, 'MCP: suggest_internal_links returns the orphan');
        $this->assertStringContainsString('"page":"/services/reference-portfolio","shared_words":["reference","portfolio"]', $json, 'MCP: a candidate page shares title words');

        $site->mcp('update_page', ['id' => $site->publicId('pages', $this->pageId('services/reference-portfolio')), 'content' => '<p>Our references – <a href="/portfolio-orphan">detail</a>.</p>']);
        $site->clearPageCache();
        $this->assertStringNotContainsString('"page":"/portfolio-orphan","target"', $this->mcpJson('suggest_internal_links', ['limit' => 100]), 'a page linked from a text is no orphan any more');
    }

    public function testBrokenExternalLinksInAPageBuildAreFoundWithTheirElement(): void
    {
        $site = $this->site();
        $site->mcp('create_page', ['title' => 'Links test', 'slug' => 'links-test', 'visible' => true]);
        $id = $this->pageId('links-test');
        $site->mcp('save_build', ['id' => $site->publicId('pages', $id), 'build' => ['v' => 1, 'children' => [['type' => 'section', 'children' => [['type' => 'button', 'content' => ['text' => 'Old partner', 'link' => 'http://127.0.0.1:1/partner']]]]]]]);
        $site->mcp('publish_build', ['id' => $site->publicId('pages', $id)]);
        $element = (string) $site->value("SELECT JSON_UNQUOTE(JSON_EXTRACT(build, '$.children[0].children[0].id')) FROM ka_pages WHERE page_id = ?", [$id]);
        $this->assertNotSame('', $element, 'the published build has the button element');

        $site->exec('UPDATE ka_news SET links_checked_at = NOW()');
        $site->exec('UPDATE ka_pages SET links_checked = NOW() WHERE page_id <> ?', [$id]);
        $site->exec('UPDATE ka_collection_items SET links_checked = NOW()');
        $site->exec("UPDATE ka_settings SET value = '0' WHERE name = 'link_check_time'");
        $site->exec('DELETE FROM ka_broken_links');
        $site->runTasks();
        $this->assertSame("page|$id|$element|0", $site->value("SELECT CONCAT(kind, '|', target_id, '|', element, '|', status) FROM ka_broken_links WHERE url = 'http://127.0.0.1:1/partner'"), 'the link check finds the dead link in the page build with its element');

        $json = $this->mcpJson('list_broken_links');
        $this->assertStringContainsString('"kind":"page","id":"' . $site->publicId('pages', $id) . '","title":"Links test"', $json, 'MCP: list_broken_links says where the link is');
        $this->assertStringContainsString('"element":"' . $element . '"', $json, 'MCP: list_broken_links names the element');
        $this->assertStringContainsString('web.archive.org/web/2020/http://127.0.0.1:1/partner', $json, 'MCP: list_broken_links carries the archive hint');

        $list = $this->assertPage('/admin.php?module=news&action=links', 200, 'Links test', message: 'News → Broken links is a site-wide list');
        $this->assertStringContainsString('element ' . $element, $list->body, 'the list names the element');

        $audit = $this->mcpJson('site_audit', ['kind' => 'link']);
        $this->assertStringContainsString('127.0.0.1:1/partner', $audit, 'site_audit reports the broken build link');
        $this->assertStringContainsString('"element":"' . $element . '"', $audit, 'site_audit reports the element');

        $this->adminPost('/admin.php?module=news&action=links', ['kind' => 'page', 'id' => $site->publicId('pages', (int) $id)], '/admin.php?module=news&action=links');
        $this->assertSame('1|0', $site->value("SELECT CONCAT((SELECT links_checked IS NULL FROM ka_pages WHERE page_id = $id), '|', (SELECT COUNT(*) FROM ka_broken_links WHERE kind = 'page' AND target_id = $id))"), 'Check again puts the page at the front of the queue');
    }
}
