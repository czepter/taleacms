<?php

declare(strict_types=1);

namespace Kaleta\Tests\Site\McpBuilder;

use Kaleta\Tests\Site\Support\SiteTestCase;
use PHPUnit\Framework\Attributes\Group;

/** Components: properties, builder, placing them on a page, forms inside, saving an element as a component (was: section 14 "komponenty"). */
#[Group('site')]
final class ComponentsTest extends SiteTestCase
{
    use McpBuilderHelpers;

    private static int $idm = 0;

    private function componentAction(string $action, array $fields = []): \Kaleta\Tests\Site\Support\Response
    {
        return $this->adminPost('/admin.php?module=components&action=' . $action . '&id=' . self::$idm, $fields);
    }

    public function testComponentIsCreatedAndGuideLinksAreThere(): void
    {
        $this->zPage();
        $this->assertPage('/admin.php?module=components', 200, 'Komponenty', message: 'components');
        $this->adminPost('/admin.php?module=components&action=save', [
            'component_id' => 0, 'name' => 'Karta služby',
            'properties' => [['label' => 'Nadpis', 'type' => 'text', 'default' => 'Výchozí nadpis'], ['label' => 'Odkaz', 'type' => 'link', 'default' => '/kontakt']],
        ]);
        self::$idm = (int) $this->site()->value('SELECT component_id FROM ka_components ORDER BY component_id DESC LIMIT 1');

        $this->assertPage('/admin.php?module=components&action=builder&id=' . self::$idm, 200, 'id="builder-data"', message: 'component in the builder');
        $this->assertPage('/admin.php?module=components&action=builder&id=' . self::$idm, 200, '"guide":"https:', message: '2.4: builder links to its guide article');
        $this->assertMatchesRegularExpression('/"guide":"https:[^"]*guide[^"]*components"/', $this->site()->admin()->get('/admin.php?module=components&action=builder&id=' . self::$idm)->body, '2.4: the guide is the components article');
        $this->assertMatchesRegularExpression('/class="guide-link" href="https:\/\/kaletacms.com\/[a-z\/]*guide\/backups-updates"/', $this->site()->admin()->get('/admin.php?module=settings&tab=backups')->body, '2.4: settings tab links to its guide article');
        $this->assertPage('/admin.php', 200, 'guide/first-steps#the-dashboard', message: '2.4: dashboard links to the guide');
    }

    public function testComponentIsPublishedAndPreviewed(): void
    {
        $build = ['v' => 1, 'children' => [['id' => 'kse1', 'type' => 'section', 'children' => [
            ['id' => 'kna1', 'type' => 'heading', 'tag' => 'h3', 'content' => ['text' => '{{nadpis}}'], 'style' => ['base' => ['color' => 'primary']]],
            ['type' => 'button', 'content' => ['text' => 'Více', 'link' => '{{odkaz}}']],
            ['type' => 'component', 'content' => ['component' => (string) self::$idm]],
        ]]]];
        $this->componentAction('build_save', ['build' => json_encode($build, JSON_UNESCAPED_UNICODE)]);

        $this->assertSame(200, $this->componentAction('build_publish')->status, 'publishing the component');
        $this->assertPage('/_component/' . self::$idm . '?build=koncept&editor=1', 200, 'Výchozí nadpis', message: 'component preview for the editor');
    }

    public function testComponentOnAPage(): void
    {
        $idm = (string) self::$idm;
        $this->site()->mcp('stavba_uloz', ['id' => $this->zPage(), 'publikovat' => true, 'build' => ['v' => 1, 'children' => [
            ['type' => 'component', 'content' => ['component' => $idm, 'values' => ['heading' => 'První <b>karta</b>', 'link' => 'javascript:alert(1)']]],
            ['type' => 'component', 'content' => ['component' => $idm]],
        ]]]);
        $this->site()->clearPageCache();

        $body = $this->visit('/z-html');

        $this->assertStringContainsString('<h3 class="s-kna1">První karta</h3>', $body, 'own value, without tags');
        $this->assertStringContainsString('<h3 class="s-kna1">Výchozí nadpis</h3>', $body, 'default value');
        $this->assertGreaterThanOrEqual(1, substr_count($body, 'href="/kontakt"'), 'the default link');
        $this->assertStringNotContainsString('javascript:', $body, 'the dangerous link is gone');
        $this->assertStringNotContainsString('id="s-kna1"', $body, 'no duplicate id');
        $this->assertStringNotContainsString('data-ka-id', $body, 'no editor markers');
        $this->assertSame(1, substr_count($body, '.s-kna1 {'), 'the style is there once for two uses');

        $list = $this->assertPage('/admin.php?module=components', 200, '1×', message: 'components show the number of uses');
        $this->assertStringContainsString('data-confirm="Komponentu „Karta služby“ používá: stránka „', $list->body, 'deleting asks and names where it is used');
    }

    public function testFormInsideAComponentCanBeSubmitted(): void
    {
        $this->componentAction('build_save', ['build' => json_encode(['v' => 1, 'children' => [['id' => 'kse1', 'type' => 'section', 'children' => [['id' => 'kfo1', 'type' => 'form', 'content' => ['name' => 'Poptávka z komponenty']]]]]], JSON_UNESCAPED_UNICODE)]);
        $this->componentAction('build_publish');
        $this->site()->clearPageCache();

        $page = $this->visitor()->get('/z-html');
        $location = $this->visitor()->post('/form', ['source' => $page->field('source'), 'element' => 'kfo1', 'back' => '/z-html', 'as_time' => $page->field('as_time'), 'as_signature' => $page->field('as_signature')])->redirect;

        $this->assertStringContainsString('form=kfo1', $location, 'the form in a component is submitted (found in the component, not only in the page build)');
    }

    public function testSavingAnElementAsAComponentAndSectionPreview(): void
    {
        $answer = $this->adminPost('/admin.php?module=components&action=from_element', [
            'name' => 'Výzva', 'element' => '{"type":"section","children":[{"type":"heading","content":{"text":"Zavolejte nám"}}]}',
        ]);

        $this->assertSame(200, $answer->status, 'saving an element as a component');
        $this->assertStringContainsString('"ok":true', $answer->body, 'saving an element as a component: ok');

        $this->assertPage('/_section/pricing', 200, 'Vyberte si balíček', message: 'preview of a ready-made section for the builder panel');
        $this->assertSame(404, $this->visitor()->get('/_section/pricing')->status, 'the section preview is for signed-in people only');
    }
}
