<?php

declare(strict_types=1);

namespace Talea\Tests\Site\McpBuilder;

use Talea\Tests\Site\Support\SiteTestCase;
use PHPUnit\Framework\Attributes\Group;

/** Claude (MCP) builds a page: English tool interface, HTML to build, publishing, design system, dark mode (was: section 9). */
#[Group('site')]
final class McpBuilderTest extends SiteTestCase
{
    use McpBuilderHelpers;

    public function testToolsAreListedWithEnglishNamesAndParameters(): void
    {
        $answer = json_encode($this->site()->mcpRaw('{"jsonrpc":"2.0","id":1,"method":"tools/list"}'), JSON_UNESCAPED_UNICODE);

        $this->assertStringContainsString('"name":"create_page"', $answer, 'English tool name');
        $this->assertStringNotContainsString('vytvor_stranku', $answer, 'there are no Czech tool names');
        $this->assertStringContainsString('"title":{', $answer, 'English parameter names');
    }

    public function testEnglishToolReturnsEnglishKeysAndTheCzechNamesAreGone(): void
    {
        $english = $this->rawText('list_pages');
        $this->assertStringContainsString('"title":', $english, 'English keys');
        $this->assertStringContainsString('"in_menu":', $english, 'English keys');
        $this->assertStringContainsString('"url":', $english, 'English keys');
        $this->assertStringContainsString('Unknown tool: seznam_stranek', $this->mcpText('seznam_stranek'), 'a Czech tool name is an unknown tool');
        $this->assertStringContainsString('is not valid', $this->mcpText('get_page', ['id' => '3f1c2b4a-0000-4000-8000-000000000000']), 'an unknown id is refused in English');
        $this->assertStringContainsString('is not valid', $this->mcpText('get_page', ['id' => 99999]), 'a number is not an id');
    }

    public function testServerInstructionsUseEnglishNames(): void
    {
        $answer = json_encode($this->site()->mcpRaw('{"jsonrpc":"2.0","id":1,"method":"initialize","params":{}}'));

        $this->assertStringContainsString('build_from_html', $answer, 'instructions name the English tools');
    }

    public function testBuilderSchema(): void
    {
        $text = $this->rawText('builder_schema');

        $this->assertStringContainsString('library', $text, 'the schema lists the section library');
        $this->assertStringContainsString('tl-space', $text, 'the schema lists the spacing tokens');
    }

    public function testHtmlBecomesADraftBuildWithAReport(): void
    {
        $text = $this->rawText('build_from_html', ['title' => 'Z HTML', 'html' => self::Z_HTML]);

        $this->assertStringContainsString('draft', $text, 'saved as a draft');
        $this->assertStringContainsString('Form element', $text, 'the form that cannot be converted is reported');
        $this->assertMatchesRegularExpression('/left out.*btn/', $text, 'the dropped class btn is reported');

        $id = (int) $this->site()->value("SELECT page_id FROM tl_pages WHERE slug = 'z-html'");
        $this->assertGreaterThan(0, $id, 'the page exists');
        self::$zPage = $id;
    }

    public function testNewPageStaysHiddenAndTheClassFromStyleIsSaved(): void
    {
        $site = $this->site();
        $this->assertSame('0/1/1', (string) $site->value("SELECT CONCAT(visible, '/', build IS NULL, '/', build_draft LIKE '%by Claude%') FROM tl_pages WHERE page_id = ?", [self::$zPage]), 'hidden, without a published build, draft has the text');
        $this->assertSame('padding-block: var(--tl-space-2xl);', (string) $site->value("SELECT css FROM tl_classes WHERE name = 'intro-x'"), 'the class from <style> was saved');
    }

    public function testPublishedPageIsOnTheSite(): void
    {
        $site = $this->site();
        $site->mcp('insert_section', ['id' => $site->publicId('pages', self::$zPage), 'section' => 'faq']);
        $site->mcp('publish_build', ['id' => $site->publicId('pages', self::$zPage)]);
        $site->exec('UPDATE tl_pages SET visible = 1 WHERE page_id = ?', [self::$zPage]);
        $site->clearPageCache();

        $body = $this->visit('/z-html');

        $this->assertStringContainsString('<h1>Page by Claude</h1>', $body, 'heading');
        $this->assertStringContainsString('class="intro-x"', $body, 'class');
        $this->assertStringNotContainsString('container', $body, 'the unknown wrapper class is gone');
        $this->assertStringContainsString('"FAQPage"', $body, 'the inserted FAQ section is there');
    }

    public function testDesignSystemEditedThroughMcp(): void
    {
        $text = $this->rawText('update_design_system', ['design' => ['colors' => ['primary' => '#0f766e'], 'radius' => 'l']]);
        $this->site()->mcp('publish_look');
        $this->site()->clearPageCache();

        $this->assertStringContainsString('readability', $text, 'the answer reports readability');
        $this->assertPage('/', 200, 'tl-color-primary: #0f766e', message: 'design system from MCP is on the site');
        $this->assertPage('/', 200, 'tl-color-surface: #f5f6f8', message: 'design system from MCP kept the other colours');
    }

    public function testDarkModeAndThemeSwitcherThroughMcp(): void
    {
        $this->site()->mcp('update_settings', ['settings' => ['dark_mode' => 'dark', 'theme_switcher' => '1']]);
        $this->site()->clearPageCache();

        $body = $this->visit('/');

        $this->assertStringContainsString('data-dark data-theme="dark"', $body, 'always dark');
        $this->assertStringContainsString('data-theme-option="light"', $body, 'switcher for visitors');
        $this->assertMatchesRegularExpression('/localStorage\.getItem\(.tl-theme.\)/', $body, 'the switcher remembers the choice');
        $this->assertStringContainsString('data-theme="dark"]', $body, 'CSS for the forced dark theme');

        $this->site()->mcp('update_settings', ['settings' => ['dark_mode' => 'off', 'theme_switcher' => '0']]);
        $this->site()->clearPageCache();
    }
}
