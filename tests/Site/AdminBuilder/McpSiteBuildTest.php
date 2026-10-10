<?php

declare(strict_types=1);

namespace Kaleta\Tests\Site\AdminBuilder;

use Kaleta\Tests\Site\Support\SiteTestCase;
use PHPUnit\Framework\Attributes\Group;

/** Claude over MCP builds the site without the administration (was: section 10). */
#[Group('site')]
final class McpSiteBuildTest extends SiteTestCase
{
    /** @return array<string, mixed> the decoded text of the tool answer */
    private function call(string $tool, array $args): array
    {
        $result = $this->site()->mcpResult($tool, $args);

        return is_array($result) ? $result : ['_text' => (string) $result];
    }

    private function raw(string $tool, array $args): string
    {
        return (string) ($this->site()->mcp($tool, $args)['result']['content'][0]['text'] ?? json_encode($this->site()->mcp($tool, $args)));
    }

    private function isError(string $tool, array $args): bool
    {
        return ($this->site()->mcp($tool, $args)['result']['isError'] ?? false) === true;
    }

    private function pageId(string $seo): int
    {
        return (int) $this->site()->value('SELECT page_id FROM ka_pages WHERE slug = ?', [$seo]);
    }

    /** What section 9 left: a visible page "z-html" (the old section needed it for the preview-key check). */
    private function ensureZHtml(): void
    {
        if ($this->pageId('z-html') > 0) {
            return;
        }
        $this->site()->mcp('build_from_html', ['title' => 'Z HTML', 'html' => '<section><h1>Stránka od Clauda</h1></section>']);
        $id = $this->pageId('z-html');
        $this->site()->mcp('publish_build', ['id' => $id]);
        $this->site()->exec('UPDATE ka_pages SET visible = 1 WHERE page_id = ?', [$id]);
        $this->site()->clearPageCache();
    }

    public function testHtmlConvertedWithMediaAndHoverStatesAndPartialEdits(): void
    {
        $this->ensureZHtml();
        $this->site()->mcp('build_from_html', ['title' => 'Mrizka', 'html' => '<style>.mriz-t { display: grid; grid-template-columns: repeat(3, 1fr); gap: var(--ka-space-l) } .kar-t:hover { box-shadow: var(--ka-shadow-m) } @media (max-width: 767px) { .mriz-t { grid-template-columns: 1fr } }</style><section><div class="mriz-t"><div class="kar-t"><h3>Jedna</h3></div><div class="kar-t"><h3>Dva</h3></div></div></section>']);
        $id = $this->pageId('mrizka');

        $this->assertSame('{"mobile":{"columns":"1"}}{"hover":{"shadow":"m"}}', (string) $this->site()->value("SELECT CONCAT((SELECT style FROM ka_classes WHERE name = 'mriz-t'), (SELECT style FROM ka_classes WHERE name = 'kar-t'))"), 'MCP: @media and :hover from <style> become states of the class');

        $loaded = $this->raw('get_build', ['id' => $id]);
        $this->assertStringContainsString('mriz-t', $loaded);
        $this->assertStringNotContainsString('display', $loaded, 'get_build without default values');
        $this->assertStringNotContainsString('"link":""', $loaded, 'an element with a class has no default style');
        $heading = json_decode($loaded, true)['build']['children'][0]['children'][0]['children'][0]['children'][0]['id'];

        $edited = $this->raw('edit_build', ['id' => $id, 'operations' => [['op' => 'update', 'id' => $heading, 'content' => ['text' => 'Opraveno']], ['op' => 'delete', 'id' => 'neni']]]);
        $this->assertStringContainsString('operation_errors":{"op[1', $edited, 'a bad operation is reported');
        $this->assertSame('1', (string) $this->site()->value('SELECT build_draft LIKE ? FROM ka_pages WHERE page_id = ?', ['%Opraveno%', $id]), 'MCP: partial edit of an element by id');

        $result = json_decode($edited, true);
        $this->assertStringContainsString('(h1)', implode('|', array_column($result['check'] ?? [], 'message')), 'MCP: a write returns the pre-publish check (page without h1)');

        $preview = (string) $result['preview'];
        $visitor = $this->site()->client();
        $shown = $visitor->get($preview);
        $this->assertSame(200, $shown->status, 'signed preview of the draft of a hidden page');
        $this->assertStringContainsString('Opraveno', $shown->body);
        $this->assertStringContainsString('noindex', $shown->body);
        $this->assertSame(404, $visitor->get(substr($preview, 0, -1) . 'x')->status, 'a foreign or altered key does not open the preview');

        $other = $visitor->get('/z-html?build=draft&preview_key=' . substr($preview, strrpos($preview, 'preview_key=') + 12));
        $this->assertSame(200, $other->status, 'the key of one page does not open another (it shows the public page)');
        $this->assertStringNotContainsString('Opraveno', $other->body, 'and not the draft of the first');

        $this->assertStringContainsString('part=footer&build=draft&preview_key=', $this->raw('preview_link', ['part' => 'footer']), 'MCP: link to the preview of a site part');

        $this->site()->mcp('trash_page', ['id' => $id]);
        $this->assertSame('1', (string) $this->site()->value('SELECT deleted_at IS NOT NULL FROM ka_pages WHERE page_id = ?', [$id]), 'MCP: page to the trash');
        $this->assertTrue($this->isError('trash_page', ['id' => (int) $this->site()->settingValue('home_page')]), 'MCP: the home page cannot be deleted');
    }

    public function testSharedClassFilesAndFonts(): void
    {
        $this->site()->mcp('save_classes', ['css' => '.stitek-t { padding: var(--ka-space-2xs) var(--ka-space-s); border-radius: var(--ka-radius) } @media (max-width: 1023px) { .stitek-t { font-size: var(--ka-step--1) } }']);
        $this->site()->mcp('save_classes', ['css' => '.stitek-t:hover { background-color: #ffe3dc }']);
        $classes = $this->raw('list_classes', ['name' => 'stitek-t']);
        foreach (['font_size":"-1', 'hover', 'border-radius'] as $needle) {
            $this->assertStringContainsString($needle, $classes, "MCP: shared class from CSS with the tablet state ($needle)");
        }

        $image = imagecreatetruecolor(40, 30);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, 255, 79, 46));
        ob_start();
        imagepng($image);
        $png = base64_encode((string) ob_get_clean());
        $uploaded = $this->call('upload_file', ['filename' => 'tym-foto.png', 'data' => $png, 'alt' => 'Tym v dilne']);
        $address = (string) ($uploaded['path'] ?? '');
        $this->assertNotSame('', $address);
        $this->assertFileExists($this->site()->path($address), 'the uploaded image is on disk');
        $this->assertSame('Tym v dilne', $this->site()->value('SELECT name FROM ka_media WHERE image_path = ?', [$address]), 'MCP: an image uploaded in base64 is in Media');

        $font = base64_encode((string) file_get_contents($this->site()->path('image/fonts/bricolage-grotesque-latin.woff2')));
        $text = $this->raw('upload_file', ['filename' => 'pismo.woff2', 'data' => $font]);
        $this->assertStringContainsString('custom_fonts', $text, 'MCP: WOFF2 font with a hint for the design system');
        $this->assertMatchesRegularExpression('/pismo-[a-f0-9]*\.woff2/', $text);

        $this->assertTrue($this->isError('upload_file', ['filename' => 'skript.php', 'data' => 'PD9waHAgZWNobyAxOw==']), 'MCP: PHP cannot be uploaded');
        $this->assertSame([], glob($this->site()->path('media') . '/*/*/skript*') ?: [], 'and no such file was written');
    }

    public function testSettingsRedirectsAndTemplates(): void
    {
        $this->site()->mcp('update_settings', ['settings' => ['footer_text' => 'Paticka od Clauda', 'site_email' => 'utocnik@example.com', 'company_id' => 'abc']]);
        $this->assertSame('Paticka od Clauda|1|1', $this->site()->settingValue('footer_text') . '|' . (int) ($this->site()->settingValue('site_email') !== 'utocnik@example.com') . '|' . (int) ($this->site()->settingValue('company_id') !== 'abc'),
            'MCP: allowed settings are saved, the e-mail and an invalid company id are not');

        $this->site()->setting('site_email', 'spravce@example.cz');
        $this->site()->clearPageCache();
        $this->assertStringNotContainsString('spravce@example.cz', $this->site()->client()->get('/')->body, 'the site e-mail (enquiries, notices) is not shown on the web');

        $this->site()->mcp('update_settings', ['settings' => ['company_email' => 'info@example.cz']]);
        $this->site()->clearPageCache();
        $this->assertPage('/', 200, 'info@example.cz', message: 'public company e-mail in the footer');

        $this->assertPage('/.well-known/security.txt', 404, message: 'security.txt: without a contact there is none');
        $this->site()->mcp('update_settings', ['settings' => ['security_contact' => 'security@example.com']]);
        $this->assertPage('/.well-known/security.txt', 200, 'Contact: mailto:security@example.com', message: 'security.txt from the security contact (RFC 9116)');

        $this->site()->mcp('update_settings', ['settings' => ['logo' => 'image/kaleta-logo.svg', 'favicon' => '../config.php']]);
        $this->assertSame('image/kaleta-logo.svg|', $this->site()->settingValue('logo') . '|' . (string) $this->site()->value("SELECT COALESCE((SELECT value FROM ka_settings WHERE name = 'favicon'), '')"),
            'MCP: the logo from system files, a path outside media/ and image/ does not pass');

        $this->ensureZHtml();
        $this->site()->mcp('save_redirect', ['from' => '/stary-web/sluzby', 'to' => '/z-html']);
        $redirect = $this->site()->client()->get('/stary-web/sluzby');
        $this->assertSame(301, $redirect->status);
        $this->assertSame($this->site()->base . '/z-html', $redirect->redirect, 'MCP: redirect of an old address');

        $this->assertTrue($this->isError('copy_theme', ['name' => 'test-kopie']), 'MCP: no custom template');
        $this->assertDirectoryDoesNotExist($this->site()->path('layout/test-kopie'));
    }

    public function testCategoryDescriptionIsSanitized(): void
    {
        $this->site()->mcp('create_category', ['name' => 'Kategorie XSS', 'description' => '<p>Úvod</p><script>alert(1)</script><img src=x onerror=alert(2)>']);
        $page = $this->assertPage('/news/category/kategorie-xss', 200, 'Úvod', message: 'MCP: category description is cleaned');
        $this->assertDoesNotMatchRegularExpression('/<script>alert|onerror/', $page->body, 'no script left in the category description');

        $this->site()->exec("UPDATE ka_categories SET description = '<p>Stary popis</p><script>alert(3)</script>' WHERE slug = 'kategorie-xss'");
        $old = $this->assertPage('/news/category/kategorie-xss', 200, 'Stary popis', message: 'a stored old category description');
        $this->assertStringNotContainsString('<script>alert(3)', $old->body, 'the listing cleans an earlier stored description too');
    }
}
