<?php

declare(strict_types=1);

namespace Talea\Tests\Site\Connections;

use Talea\Tests\Site\Support\SiteTestCase;
use PHPUnit\Framework\Attributes\Group;

/** With News and Forms & enquiries switched off the site, the admin, the builder schema and MCP lose them (was: section 47 of tools/test.sh). */
#[Group('site')]
final class DisabledExtensionsTest extends SiteTestCase
{
    use ConnectionHelpers;

    public function testNewsAndEnquiriesDisappearEverywhereWhenSwitchedOff(): void
    {
        $site = $this->site();
        $site->setting('extensions', 'stats,redirects,claude');
        $site->clearPageCache();
        foreach (glob($site->path('storage/cache/*.txt')) ?: [] as $file) {
            unlink($file);
        }
        $newsSlug = (string) $site->value('SELECT slug FROM tl_news ORDER BY news_id LIMIT 1');
        $visitor = $site->client();

        $this->assertPage('/news', 404, message: 'the news list is gone');
        $this->assertPage('/news/' . $newsSlug, 404, message: 'the news item is gone');
        $this->assertPage('/rss.xml', 404, message: 'RSS is gone');
        $this->assertStringNotContainsString('/news', $visitor->get('/sitemap.xml')->body, 'sitemap without news');
        $page = $visitor->get('/about-us')->body;
        $this->assertStringNotContainsString('rss.xml', $page, 'without news there is no RSS link either');
        $this->assertDoesNotMatchRegularExpression('#href="[^"]*/news"#', $page, 'menu without a link to news');
        $this->assertSame(404, $visitor->post('/form', ['x' => 1])->status, 'submitting a form does not work');

        $admin = $site->admin()->get('/admin.php')->body;
        $this->assertStringNotContainsString('module=news"', $admin, 'administration without news');
        $this->assertStringNotContainsString('module=enquiries"', $admin, 'administration without enquiries');

        $schema = $this->toolText('builder_schema');
        $tools = $this->answerRaw($site->mcpRaw('{"jsonrpc":"2.0","id":1,"method":"tools/list"}'));
        $this->assertStringNotContainsString('"form":', $schema, 'the builder does not offer the element of a disabled extension');
        $this->assertStringNotContainsString('list_news', $tools, 'MCP does not offer the tools of disabled extensions');

        $site->setting('extensions', 'news,enquiries,newsletter,stats,redirects,assistant,languages,claude');
        $site->clearPageCache();
    }
}
