<?php

declare(strict_types=1);

namespace Talea\Tests\Site\AdminBuilder;

use Talea\Tests\Site\Support\Response;
use Talea\Tests\Site\Support\SiteTestCase;
use PHPUnit\Framework\Attributes\Group;

/** Site parts (header, footer, content wrappers) in the builder (was: section 11 "site parts in the builder"). */
#[Group('site')]
final class SiteSectionsBuilderTest extends SiteTestCase
{
    use AuthorSession;

    /** The old part_action(). @param array<string, string> $fields */
    private function partAction(string $action, string $type, array $fields = []): Response
    {
        return $this->site()->admin()->post('/admin.php?module=parts&action=' . $action . '&type=' . $type . '&language=', ['_csrf' => $this->site()->csrf()] + $fields);
    }

    private function visit(string $path): Response
    {
        $this->site()->clearPageCache();

        return $this->site()->client()->get($path);
    }

    public function testHeaderIsBuiltFromTheTemplateUntilPublished(): void
    {
        $this->assertPage('/admin.php?module=parts', 200, 'Header', message: 'site parts');
        $this->assertPage('/admin.php?module=parts&action=builder&type=header&language=', 200, 'id="builder-data"', message: 'the header opens in the builder with a draft from the template');

        $body = $this->visit('/about-us')->body;
        $this->assertStringContainsString('header class="header"', $body, 'the unpublished header is drawn by the template');
        $this->assertStringNotContainsString('tl-nav', $body, 'no builder navigation yet');

        $this->assertPage('/about-us?part=header&build=draft&editor=1', 200, 'data-tl-type="navigation"', message: 'header draft preview for the editor');
        $this->assertStringNotContainsString('data-tl-type', $this->site()->client()->get('/about-us?part=header&build=draft&editor=1')->body, 'the visitor does not see the part preview');
    }

    public function testPublishedHeaderAndWrapper(): void
    {
        $this->assertSame(200, $this->partAction('build_publish', 'header')->status, 'publishing the header');
        $body = $this->visit('/about-us')->body;
        $this->assertStringContainsString('class="tl-nav"', $body, 'the header from the builder is on the web');
        $this->assertStringNotContainsString('header class="header"', $body, 'instead of the template one');
        $this->assertStringContainsString('href="/about-us" aria-current="page"', $body, 'with the active menu item');
        $this->assertSame(1, substr_count($body, '<style>'), 'the page and the site parts share one stylesheet');
        $this->assertSame(1, substr_count($body, '@layer builder {'), 'one builder layer');

        $wrapper = '{"v":1,"children":[{"id":"obs1","type":"page_content"},{"id":"sek9","type":"section","children":[{"id":"nad9","type":"heading","content":{"text":"Below the article"}}]}]}';
        $this->assertPage('/admin.php?module=parts&action=builder&type=news_item&language=', 200, 'id="builder-data"', message: 'news wrapper in the builder');
        $this->partAction('build_save', 'news_item', ['build' => $wrapper]);
        $this->partAction('build_publish', 'news_item');
        $news = $this->visit('/news/our-new-website-is-live')->body;
        foreach (['Below the article', '<main id="main" class="build">', 'class="wrap content"', 'Welcome'] as $needle) {
            $this->assertStringContainsString($needle, $news, "news wrapper: $needle");
        }
    }

    public function testVersionsAndReturnToTheTemplate(): void
    {
        $this->partAction('build_save', 'header', ['build' => '{"v":1,"children":[{"type":"section","tag":"header","children":[{"type":"logo"}]}]}']);
        $this->partAction('build_publish', 'header');
        $this->assertSame('1', (string) $this->site()->value("SELECT COUNT(*) FROM tl_build_revisions WHERE part = 'header:'"), 'the previous header is in the versions');

        $this->partAction('template', 'header');
        $this->assertStringContainsString('header class="header"', $this->visit('/about-us')->body, 'the header goes back to the template');
    }

    public function testFooterFromMcpAndAuthorAccess(): void
    {
        $result = $this->site()->mcp('save_build', ['part' => 'footer', 'build' => ['v' => 1, 'children' => [['type' => 'section', 'tag' => 'footer', 'children' => [['type' => 'company_details', 'content' => ['detail' => 'copyright']]]]]], 'publish' => true]);
        $this->assertStringContainsString('published', (string) json_encode($result, JSON_UNESCAPED_UNICODE), 'MCP: footer from a build');

        $body = $this->visit('/about-us')->body;
        $this->assertStringContainsString('<p class="tl-detail">&copy; ' . date('Y') . ' Test Company</p>', $body, 'the footer from MCP is on the web');
        $this->assertStringNotContainsString('footer class="footer"', $body, 'instead of the template one');

        $this->assertSame(403, $this->authorClient()->get('/admin.php?module=parts')->status, 'a news author may not use site parts');
    }
}
