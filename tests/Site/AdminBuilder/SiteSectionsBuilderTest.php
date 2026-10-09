<?php

declare(strict_types=1);

namespace Kaleta\Tests\Site\AdminBuilder;

use Kaleta\Tests\Site\Support\Response;
use Kaleta\Tests\Site\Support\SiteTestCase;
use PHPUnit\Framework\Attributes\Group;

/** Site parts (header, footer, content wrappers) in the builder (was: section 11 "části webu v builderu"). */
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
        $this->assertPage('/admin.php?module=parts', 200, 'Záhlaví', message: 'site parts');
        $this->assertPage('/admin.php?module=parts&action=builder&type=hlavicka&language=', 200, 'id="stavitel-data"', message: 'the header opens in the builder with a draft from the template');

        $body = $this->visit('/o-nas')->body;
        $this->assertStringContainsString('header class="hlavicka"', $body, 'the unpublished header is drawn by the template');
        $this->assertStringNotContainsString('ka-nav', $body, 'no builder navigation yet');

        $this->assertPage('/o-nas?part=hlavicka&build=koncept&editor=1', 200, 'data-ka-typ="navigace"', message: 'header draft preview for the editor');
        $this->assertStringNotContainsString('data-ka-typ', $this->site()->client()->get('/o-nas?part=hlavicka&build=koncept&editor=1')->body, 'the visitor does not see the part preview');
    }

    public function testPublishedHeaderAndWrapper(): void
    {
        $this->assertSame(200, $this->partAction('build_publish', 'hlavicka')->status, 'publishing the header');
        $body = $this->visit('/o-nas')->body;
        $this->assertStringContainsString('class="ka-nav"', $body, 'the header from the builder is on the web');
        $this->assertStringNotContainsString('header class="hlavicka"', $body, 'instead of the template one');
        $this->assertStringContainsString('href="/o-nas" aria-current="page"', $body, 'with the active menu item');
        $this->assertSame(1, substr_count($body, '<style>'), 'the page and the site parts share one stylesheet');
        $this->assertSame(1, substr_count($body, '@layer stavitel {'), 'one builder layer');

        $wrapper = '{"v":1,"deti":[{"id":"obs1","typ":"obsah"},{"id":"sek9","typ":"sekce","deti":[{"id":"nad9","typ":"nadpis","obsah":{"text":"Pod článkem"}}]}]}';
        $this->assertPage('/admin.php?module=parts&action=builder&type=novinka&language=', 200, 'id="stavitel-data"', message: 'news wrapper in the builder');
        $this->partAction('build_save', 'novinka', ['build' => $wrapper]);
        $this->partAction('build_publish', 'novinka');
        $news = $this->visit('/novinky/vitejte-v-kalete')->body;
        foreach (['Pod článkem', '<main id="obsah" class="stavba">', 'class="obal obsah"', 'Vítejte'] as $needle) {
            $this->assertStringContainsString($needle, $news, "news wrapper: $needle");
        }
    }

    public function testVersionsAndReturnToTheTemplate(): void
    {
        $this->partAction('build_save', 'hlavicka', ['build' => '{"v":1,"deti":[{"typ":"sekce","znacka":"header","deti":[{"typ":"logo"}]}]}']);
        $this->partAction('build_publish', 'hlavicka');
        $this->assertSame('1', (string) $this->site()->value("SELECT COUNT(*) FROM ka_build_revisions WHERE part = 'hlavicka:'"), 'the previous header is in the versions');

        $this->partAction('template', 'hlavicka');
        $this->assertStringContainsString('header class="hlavicka"', $this->visit('/o-nas')->body, 'the header goes back to the template');
    }

    public function testFooterFromMcpAndAuthorAccess(): void
    {
        $result = $this->site()->mcp('stavba_uloz', ['part' => 'paticka', 'build' => ['v' => 1, 'deti' => [['type' => 'sekce', 'znacka' => 'footer', 'deti' => [['type' => 'udaje', 'obsah' => ['udaj' => 'copyright']]]]]], 'publikovat' => true]);
        $this->assertStringContainsString('publikováno', (string) json_encode($result, JSON_UNESCAPED_UNICODE), 'MCP: footer from a build');

        $body = $this->visit('/o-nas')->body;
        $this->assertStringContainsString('<p class="ka-udaj">&copy; ' . date('Y') . ' Testovací firma</p>', $body, 'the footer from MCP is on the web');
        $this->assertStringNotContainsString('footer class="paticka"', $body, 'instead of the template one');

        $this->assertSame(403, $this->authorClient()->get('/admin.php?module=parts')->status, 'a news author may not use site parts');
    }
}
