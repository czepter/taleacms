<?php

declare(strict_types=1);

namespace Kaleta\Tests\Site\McpBuilder;

use Kaleta\Tests\Site\Support\SiteTestCase;
use PHPUnit\Framework\Attributes\Group;

/** Claude (MCP) builds a page: English tool interface, HTML to build, publishing, design system, dark mode (was: section 9 "Claude (MCP): builder"). */
#[Group('site')]
final class McpBuilderTest extends SiteTestCase
{
    use McpBuilderHelpers;

    public function testToolsAreListedWithEnglishNamesAndParameters(): void
    {
        $answer = json_encode($this->site()->mcpRaw('{"jsonrpc":"2.0","id":1,"method":"tools/list"}'), JSON_UNESCAPED_UNICODE);

        $this->assertStringContainsString('"name":"create_page"', $answer, 'English tool name');
        $this->assertStringNotContainsString('"name":"vytvor_stranku"', $answer, 'the Czech name is only a hidden alias');
        $this->assertStringContainsString('"title":{', $answer, 'English parameter names');
    }

    public function testEnglishToolReturnsEnglishKeysAndTheCzechAliasStillWorks(): void
    {
        $english = $this->rawText('list_pages');
        $this->assertStringContainsString('"title":', $english, 'English keys');
        $this->assertStringContainsString('"in_menu":', $english, 'English keys');
        $this->assertStringContainsString('"adresa":', $this->rawText('seznam_stranek'), 'the Czech name keeps working as a hidden alias');
        $this->assertStringContainsString('The page does not exist. Use list_pages.', $this->mcpText('get_page', ['id' => 99999]), 'the error of an English tool is English');
    }

    public function testServerInstructionsUseEnglishNames(): void
    {
        $answer = json_encode($this->site()->mcpRaw('{"jsonrpc":"2.0","id":1,"method":"initialize","params":{}}'));

        $this->assertStringContainsString('build_from_html', $answer, 'instructions name the English tools');
    }

    public function testBuilderSchema(): void
    {
        $text = $this->rawText('stavba_schema');

        $this->assertStringContainsString('library', $text, 'the schema lists the section library');
        $this->assertStringContainsString('ka-space', $text, 'the schema lists the spacing tokens');
    }

    public function testHtmlBecomesADraftBuildWithAReport(): void
    {
        $text = $this->rawText('stavba_z_html', ['title' => 'Z HTML', 'html' => self::Z_HTML]);

        $this->assertStringContainsString('koncept', $text, 'saved as a draft');
        $this->assertStringContainsString('Formul', $text, 'the form that cannot be converted is reported');
        $this->assertMatchesRegularExpression('/vynech.*btn/', $text, 'the dropped class btn is reported');

        $id = (int) $this->site()->value("SELECT page_id FROM ka_pages WHERE slug = 'z-html'");
        $this->assertGreaterThan(0, $id, 'the page exists');
        self::$zPage = $id;
    }

    public function testNewPageStaysHiddenAndTheClassFromStyleIsSaved(): void
    {
        $site = $this->site();
        $this->assertSame('0/1/1', (string) $site->value("SELECT CONCAT(visible, '/', build IS NULL, '/', build_draft LIKE '%od Clauda%') FROM ka_pages WHERE page_id = ?", [self::$zPage]), 'hidden, without a published build, draft has the text');
        $this->assertSame('padding-block: var(--ka-space-2xl);', (string) $site->value("SELECT css FROM ka_classes WHERE name = 'uvod-x'"), 'the class from <style> was saved');
    }

    public function testPublishedPageIsOnTheSite(): void
    {
        $site = $this->site();
        $site->mcp('vloz_sekci', ['id' => self::$zPage, 'sekce' => 'faq']);
        $site->mcp('publikuj_stavbu', ['id' => self::$zPage]);
        $site->exec('UPDATE ka_pages SET visible = 1 WHERE page_id = ?', [self::$zPage]);
        $site->clearPageCache();

        $body = $this->visit('/z-html');

        $this->assertStringContainsString('<h1>Stránka od Clauda</h1>', $body, 'heading');
        $this->assertStringContainsString('class="uvod-x"', $body, 'class');
        $this->assertStringNotContainsString('container', $body, 'the unknown wrapper class is gone');
        $this->assertStringContainsString('"FAQPage"', $body, 'the inserted FAQ section is there');
    }

    public function testDesignSystemEditedThroughMcp(): void
    {
        $text = $this->rawText('uprav_design_system', ['ds' => ['colors' => ['primary' => '#0f766e'], 'radius' => 'l']]);
        $this->site()->mcp('publish_look');
        $this->site()->clearPageCache();

        $this->assertStringContainsString('citelnost', $text, 'the answer reports readability');
        $this->assertPage('/', 200, 'ka-color-primary: #0f766e', message: 'design system from MCP is on the site');
        $this->assertPage('/', 200, 'ka-color-surface: #f5f6f8', message: 'design system from MCP kept the other colours');
    }

    public function testDarkModeAndThemeSwitcherThroughMcp(): void
    {
        $this->site()->mcp('uprav_nastaveni', ['settings' => ['dark_mode' => 'dark', 'theme_switcher' => '1']]);
        $this->site()->clearPageCache();

        $body = $this->visit('/');

        $this->assertStringContainsString('data-dark data-theme="dark"', $body, 'always dark');
        $this->assertStringContainsString('data-theme-option="light"', $body, 'switcher for visitors');
        $this->assertMatchesRegularExpression('/localStorage\.getItem\(.ka-theme.\)/', $body, 'the switcher remembers the choice');
        $this->assertStringContainsString('data-theme="dark"]', $body, 'CSS for the forced dark theme');

        $this->site()->mcp('uprav_nastaveni', ['settings' => ['dark_mode' => 'off', 'theme_switcher' => '0']]);
        $this->site()->clearPageCache();
    }
}
