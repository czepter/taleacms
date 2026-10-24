<?php

declare(strict_types=1);

namespace Talea\Tests\Site\McpBuilder;

use Talea\Tests\Site\Support\SiteTestCase;
use PHPUnit\Framework\Attributes\Group;

/** Components: properties, builder, placing them on a page, forms inside, saving an element as a component (was: section 14). */
#[Group('site')]
final class ComponentsTest extends SiteTestCase
{
    use McpBuilderHelpers;

    private static int $idm = 0;

    private function componentAction(string $action, array $fields = []): \Talea\Tests\Site\Support\Response
    {
        return $this->adminPost('/admin.php?module=components&action=' . $action . '&id=' . $this->site()->publicId('components', self::$idm), $fields);
    }

    public function testComponentIsCreatedAndGuideLinksAreThere(): void
    {
        $this->zPage();
        $this->assertPage('/admin.php?module=components', 200, 'Components', message: 'components');
        $this->adminPost('/admin.php?module=components&action=save', [
            'component_id' => 0, 'name' => 'Service card',
            'properties' => [['label' => 'Heading', 'type' => 'text', 'default' => 'Default heading'], ['label' => 'Target', 'type' => 'link', 'default' => '/contact']],
        ]);
        self::$idm = (int) $this->site()->value('SELECT component_id FROM tl_components ORDER BY component_id DESC LIMIT 1');

        $this->assertPage('/admin.php?module=components&action=builder&id=' . $this->site()->publicId('components', self::$idm), 200, 'id="builder-data"', message: 'component in the builder');
        $this->assertPage('/admin.php?module=components&action=builder&id=' . $this->site()->publicId('components', self::$idm), 200, '"guide":"https:', message: '2.4: builder links to its guide article');
        $this->assertMatchesRegularExpression('/"guide":"https:[^"]*guide[^"]*components"/', $this->site()->admin()->get('/admin.php?module=components&action=builder&id=' . $this->site()->publicId('components', self::$idm))->body, '2.4: the guide is the components article');
        $this->assertMatchesRegularExpression('/class="guide-link" href="https:\/\/taleacms.com\/[a-z\/]*guide\/backups-updates"/', $this->site()->admin()->get('/admin.php?module=settings&tab=backups')->body, '2.4: settings tab links to its guide article');
        $this->assertPage('/admin.php', 200, 'guide/first-steps#the-dashboard', message: '2.4: dashboard links to the guide');
    }

    public function testComponentIsPublishedAndPreviewed(): void
    {
        $build = ['v' => 1, 'children' => [['id' => 'kse1', 'type' => 'section', 'children' => [
            ['id' => 'kna1', 'type' => 'heading', 'tag' => 'h3', 'content' => ['text' => '{{heading}}'], 'style' => ['base' => ['color' => 'primary']]],
            ['type' => 'button', 'content' => ['text' => 'More', 'link' => '{{target}}']],
            ['type' => 'component', 'content' => ['component' => $this->site()->publicId('components', self::$idm)]],
        ]]]];
        $this->componentAction('build_save', ['build' => json_encode($build, JSON_UNESCAPED_UNICODE)]);

        $this->assertSame(200, $this->componentAction('build_publish')->status, 'publishing the component');
        $this->assertPage('/_component/' . $this->site()->publicId('components', (int) $this->site()->value('SELECT component_id FROM tl_components ORDER BY component_id DESC LIMIT 1')) . '?build=draft&editor=1', 200, 'Default heading', message: 'component preview for the editor');
    }

    public function testComponentOnAPage(): void
    {
        $idm = $this->site()->publicId('components', self::$idm);
        $this->site()->mcp('save_build', ['id' => $this->site()->publicId('pages', $this->zPage()), 'publish' => true, 'build' => ['v' => 1, 'children' => [
            ['type' => 'component', 'content' => ['component' => $idm, 'values' => ['heading' => 'First <b>card</b>', 'target' => 'javascript:alert(1)']]],
            ['type' => 'component', 'content' => ['component' => $idm]],
        ]]]);
        $this->site()->clearPageCache();

        $body = $this->visit('/z-html');

        $this->assertStringContainsString('<h3 class="s-kna1">First card</h3>', $body, 'own value, without tags');
        $this->assertStringContainsString('<h3 class="s-kna1">Default heading</h3>', $body, 'default value');
        $this->assertGreaterThanOrEqual(1, substr_count($body, 'href="/contact"'), 'the default link');
        $this->assertStringNotContainsString('javascript:', $body, 'the dangerous link is gone');
        $this->assertStringNotContainsString('id="s-kna1"', $body, 'no duplicate id');
        $this->assertStringNotContainsString('data-tl-id', $body, 'no editor markers');
        $this->assertSame(1, substr_count($body, '.s-kna1 {'), 'the style is there once for two uses');

        $list = $this->assertPage('/admin.php?module=components', 200, '1×', message: 'components show the number of uses');
        $this->assertStringContainsString('data-confirm="The component “Service card” is used on: page “', $list->body, 'deleting asks and names where it is used');
    }

    /** "Edit here" on a page's canvas: the component builder shows the page around the chosen use, "Open" the component alone */
    public function testComponentIsEditedOnItsPage(): void
    {
        $component = $this->site()->publicId('components', self::$idm);
        $page = $this->site()->publicId('pages', $this->zPage());
        $this->site()->mcp('save_build', ['id' => $page, 'build' => ['v' => 1, 'children' => [
            ['id' => 'txt1', 'type' => 'text', 'content' => ['text' => '<p>Around the component</p>']],
            ['id' => 'use1', 'type' => 'component', 'content' => ['component' => $component]],
            ['id' => 'use2', 'type' => 'component', 'content' => ['component' => $component]],
        ]]]);
        $this->componentAction('build_save', ['build' => json_encode(['v' => 1, 'children' => [['id' => 'kse1', 'type' => 'section', 'children' => [
            ['id' => 'kna1', 'type' => 'heading', 'tag' => 'h3', 'content' => ['text' => 'Draft {{heading}}']]]]]], JSON_UNESCAPED_UNICODE)]);
        $back = rawurlencode('/admin/pages/builder/' . $page);

        $here = $this->assertPage("/admin.php?module=components&action=builder&id=$component&page=$page&use=use1&back=$back", 200, 'Back to the page', message: 'Edit here leads back to the page');
        $this->assertStringContainsString('/z-html?build=draft\u0026editor=1\u0026component=' . $component . '\u0026use=use1"', $here->body, 'the canvas is the page around the chosen use');
        $this->assertStringContainsString('"places":["page “Z HTML”"]', $here->body, 'the bar names where the changes apply');
        $open = $this->assertPage("/admin.php?module=components&action=builder&id=$component&back=$back", 200, 'Back to the page', message: 'Open leads back to the page too');
        $this->assertStringContainsString('/_component/' . $component . '?build=draft\u0026editor=1"', $open->body, 'Open shows the component alone');
        $this->assertStringNotContainsString('"places"', $this->site()->admin()->get("/admin.php?module=components&action=builder&id=$component")->body, 'opened from the list: no page context');

        $canvas = $this->site()->admin()->get("/z-html?build=draft&editor=1&component=$component&use=use1")->body;
        $this->assertSame(1, substr_count($canvas, 'data-tl-focus'), 'only the chosen use is edited');
        $this->assertStringContainsString('data-tl-id="kna1"', $canvas, 'the component draft carries the editor markers');
        $this->assertStringContainsString('Draft Default heading', $canvas, 'the chosen use shows the draft');
        $this->assertStringContainsString('>Default heading<', $canvas, 'the other use shows the published component');
        $this->assertStringNotContainsString('data-tl-id="txt1"', $canvas, 'the page around it cannot be selected');
        $this->assertStringNotContainsString('<!--tl-part', $canvas, 'no "Edit site header" while a component is edited');
        $this->assertStringNotContainsString('data-tl-focus', $this->site()->client('focus-visitor')->get("/z-html?build=draft&editor=1&component=$component&use=use1")->body, 'a visitor never gets the draft');
    }

    public function testFormInsideAComponentCanBeSubmitted(): void
    {
        $this->componentAction('build_save', ['build' => json_encode(['v' => 1, 'children' => [['id' => 'kse1', 'type' => 'section', 'children' => [['id' => 'kfo1', 'type' => 'form', 'content' => ['name' => 'Enquiry from a component']]]]]], JSON_UNESCAPED_UNICODE)]);
        $this->componentAction('build_publish');
        $this->site()->clearPageCache();

        $page = $this->visitor()->get('/z-html');
        $location = $this->visitor()->post('/form', ['source' => $page->field('source'), 'element' => 'kfo1', 'back' => '/z-html', 'as_time' => $page->field('as_time'), 'as_signature' => $page->field('as_signature')])->redirect;

        $this->assertStringContainsString('form=kfo1', $location, 'the form in a component is submitted (found in the component, not only in the page build)');
    }

    public function testSavingAnElementAsAComponentAndSectionPreview(): void
    {
        $answer = $this->adminPost('/admin.php?module=components&action=from_element', [
            'name' => 'Call to action', 'element' => '{"type":"section","children":[{"type":"heading","content":{"text":"Call us"}}]}',
        ]);

        $this->assertSame(200, $answer->status, 'saving an element as a component');
        $this->assertStringContainsString('"ok":true', $answer->body, 'saving an element as a component: ok');

        $this->assertPage('/_section/pricing', 200, 'Choose a package', message: 'preview of a ready-made section for the builder panel');
        $this->assertSame(404, $this->visitor()->get('/_section/pricing')->status, 'the section preview is for signed-in people only');
    }
}
