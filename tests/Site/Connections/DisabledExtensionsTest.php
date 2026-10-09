<?php

declare(strict_types=1);

namespace Kaleta\Tests\Site\Connections;

use Kaleta\Tests\Site\Support\SiteTestCase;
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
        $visitor = $site->client();

        $this->assertPage('/news', 404, message: 'výpis novinek je pryč');
        $this->assertPage('/news/vitejte-v-kalete', 404, message: 'novinka je pryč');
        $this->assertPage('/rss.xml', 404, message: 'RSS je pryč');
        $this->assertStringNotContainsString('/news', $visitor->get('/sitemap.xml')->body, 'mapa webu bez novinek');
        $page = $visitor->get('/o-nas')->body;
        $this->assertStringNotContainsString('rss.xml', $page, 'bez novinek ani odkaz na RSS');
        $this->assertDoesNotMatchRegularExpression('#href="[^"]*/news"#', $page, 'menu bez odkazu na novinky');
        $this->assertSame(404, $visitor->post('/form', ['x' => 1])->status, 'odeslání formuláře nejde');

        $admin = $site->admin()->get('/admin.php')->body;
        $this->assertStringNotContainsString('module=news"', $admin, 'administrace bez novinek');
        $this->assertStringNotContainsString('module=enquiries"', $admin, 'administrace bez poptávek');

        $schema = $this->toolText('stavba_schema');
        $tools = $this->answerRaw($site->mcpRaw('{"jsonrpc":"2.0","id":1,"method":"tools/list"}'));
        $this->assertStringNotContainsString('"form":', $schema, 'builder nenabízí prvek vypnutého rozšíření');
        $this->assertStringNotContainsString('seznam_novinek', $tools, 'MCP nenabízí nástroje vypnutých rozšíření');

        $site->setting('extensions', 'news,enquiries,newsletter,stats,redirects,assistant,languages,claude');
        $site->clearPageCache();
    }
}
