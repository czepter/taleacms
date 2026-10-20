<?php

declare(strict_types=1);

namespace Talea\Tests\Unit\Builder;

use PHPUnit\Framework\TestCase;

final class BuildTest extends TestCase
{
    /** builder: validator, style, design system, library */
    public function testSanitize(): void
    {
        [$buildS, $buildErrors] = \Talea\Builder\Build::sanitize(['children' => [
            ['type' => 'heading', 'id' => 'abc', 'content' => ['text' => '<script>x</script>Ahoj <b>světe</b>']], // check-english: allow
            ['type' => 'neznamy'],
            ['type' => 'custom_html', 'content' => ['code' => '<p>a</p>']],
            ['type' => 'button', 'content' => ['link' => 'javascript:alert(1)']],
            ['type' => 'heading', 'id' => 'abc', 'children' => [['type' => 'text']]],
        ]], false);
        $this->assertSame('Ahoj <b>světe</b>', $buildS['children'][0]['content']['text'], 'Build::sanitize: a script in a heading gone, bold stays'); // check-english: allow
        $this->assertSame(['heading', 'button', 'heading'], array_map(fn (array $p): string => $p['type'], $buildS['children']), 'Build::sanitize: an unknown type, foreign HTML and nested heading children drop out');
        $this->assertSame(['', true], [$buildS['children'][1]['content']['link'], isset($buildErrors['children[3].content.link'])], 'Build::sanitize: a javascript: link is dropped and reported');
        $this->assertTrue($buildS['children'][2]['id'] !== 'abc', 'Build::sanitize: a duplicate id gets a new one');
        $this->assertSame(['children[1]', 'children[2]', 'children[3].content.link', 'children[4].children'], array_keys($buildErrors), 'Build::sanitize: errors have a path');
        $buildHtml = ['children' => [['type' => 'custom_html', 'id' => 'h1x', 'content' => ['code' => '<p onclick="x()">a</p><script>1</script><a href="javascript:x">b</a>']]]];
        [$buildAdmin] = \Talea\Builder\Build::sanitize($buildHtml, true);
        $this->assertSame('<p>a</p><a>b</a>', $buildAdmin['children'][0]['content']['code'], 'Build::sanitize: the administrator\'s custom HTML without scripts and handlers');
        [$buildEditor] = \Talea\Builder\Build::sanitize(['children' => [['type' => 'custom_html', 'id' => 'h1x', 'content' => ['code' => '<p>podvrh</p>']]]], false, $buildAdmin);
        $this->assertSame('<p>a</p><a>b</a>', $buildEditor['children'][0]['content']['code'], 'Build::sanitize: the editor does not change the administrator\'s custom HTML, only keeps it');
        $buildDeep = ['type' => 'text'];
        for ($i = 0; $i < 20; $i++) {
            $buildDeep = ['type' => 'container', 'children' => [$buildDeep]];
        }
        [, $buildErrors] = \Talea\Builder\Build::sanitize(['children' => [$buildDeep]]);
        $this->assertSame(1, count($buildErrors), 'Build::sanitize: depth is limited');
        [$buildMany, $buildErrors] = \Talea\Builder\Build::sanitize(['children' => array_fill(0, 900, ['type' => 'divider'])]);
        $this->assertSame([\Talea\Builder\Build::MAX_ELEMENTS, 1], [count($buildMany['children']), count($buildErrors)], 'Build::sanitize: the number of elements is limited');
        $this->assertSame('media/2026/a.jpg', \Talea\Builder\Build::sanitize(['children' => [['type' => 'image', 'content' => ['src' => 'http://x.cz/a.jpg']], ['type' => 'image', 'content' => ['src' => 'media/2026/a.jpg']]]])[0]['children'][1]['content']['src'], 'Build::sanitize: an image only from Media or https');
    }

    /** 2.7: display conditions – language versions and a URL parameter */
    public function testDisplayConditions(): void
    {
        $conditionErrors = [];
        $this->assertSame([
            ['signed_in' => 'no', 'languages' => ['', 'de'], 'url_parameter' => ['name' => 'utm_campaign', 'value' => 'jaro']],
            ['url_parameter' => ['name' => 'variant']], [], [], [], [],
            ['p', 'p2', 'p3'],
        ], [
            \Talea\Builder\Build::sanitizeConditions(['signed_in' => 'no', 'languages' => ['', 'de', 'de', 'xx', 7], 'url_parameter' => ['name' => 'utm_campaign', 'value' => 'jaro']], 'p', $conditionErrors),
            \Talea\Builder\Build::sanitizeConditions(['url_parameter' => 'variant'], 'p', $conditionErrors),
            \Talea\Builder\Build::sanitizeConditions(['url_parameter' => ['name' => 'a b', 'value' => 'x']], 'p2', $conditionErrors),
            \Talea\Builder\Build::sanitizeConditions(['url_parameter' => ['name' => 'v', 'value' => 'x"y']], 'p3', $conditionErrors),
            \Talea\Builder\Build::sanitizeConditions(['url_parameter' => ['name' => '', 'value' => '']], 'p4', $conditionErrors),
            \Talea\Builder\Build::sanitizeConditions(['jazyky' => 'de'], 'p5', $conditionErrors),
            array_keys($conditionErrors),
        ], '2.7: conditions – languages and a URL parameter are kept, unknown and unsafe values dropped with a message');
        $this->assertSame(['signed_in' => 'yes', 'from' => '2026-01-01'], \Talea\Builder\Build::sanitize(['children' => [['type' => 'heading', 'id' => 'c1', 'conditions' => ['signed_in' => 'yes', 'from' => '2026-01-01']]]])[0]['children'][0]['conditions'], '2.7: old builds without the new conditions sanitize as before');
        $conditionApp = new \Talea\Core\App([], new \Talea\Core\Request(['utm_campaign' => 'jaro', 'variant' => ''], [], []));
        $conditionContext = new \Talea\Builder\Context($conditionApp);
        $conditionApp->languagePrefix = 'de';
        $this->assertSame([true, false, true], [
            \Talea\Builder\Build::meetsConditions(['languages' => ['de']], $conditionContext), \Talea\Builder\Build::meetsConditions(['languages' => ['', 'en']], $conditionContext),
            \Talea\Builder\Build::meetsConditions(['languages' => ['']], (function () use ($conditionApp) { $conditionApp->languagePrefix = ''; return new \Talea\Builder\Context($conditionApp); })()),
        ], '2.7: meetsConditions – language version of the visit');
        $this->assertSame([true, true, false, true, false, false], [
            \Talea\Builder\Build::meetsConditions(['url_parameter' => ['name' => 'utm_campaign']], $conditionContext),
            \Talea\Builder\Build::meetsConditions(['url_parameter' => ['name' => 'utm_campaign', 'value' => 'jaro']], $conditionContext),
            \Talea\Builder\Build::meetsConditions(['url_parameter' => ['name' => 'utm_campaign', 'value' => 'leto']], $conditionContext),
            \Talea\Builder\Build::meetsConditions(['url_parameter' => ['name' => 'variant']], $conditionContext), // ?variant without a value counts as present
            \Talea\Builder\Build::meetsConditions(['url_parameter' => ['name' => 'missing']], $conditionContext),
            \Talea\Builder\Build::meetsConditions(['url_parameter' => ['name' => 'utm_campaign'], 'languages' => ['de'], 'from' => '2000-01-01'], $conditionContext), // all must hold
        ], '2.7: meetsConditions – URL parameter present, with and without an exact value');
        $this->assertSame([false, true, true, true], [
            \Talea\Builder\Build::conditionsBypassCache(['languages' => ['de']]), \Talea\Builder\Build::conditionsBypassCache(['url_parameter' => ['name' => 'utm_campaign']]),
            \Talea\Builder\Build::conditionsBypassCache(['languages' => [''], 'from' => '2026-01-01']), \Talea\Builder\Build::conditionsBypassCache(['signed_in' => 'no']),
        ], '2.7: only a language condition keeps the page in the cache (language versions have their own addresses)');
    }

    public function testFromText(): void
    {
        $this->assertSame(['h1', 'div'], array_map(fn (array $p): string => $p['tag'], \Talea\Builder\Build::fromText('O nás', '<p>x</p>')['children'][0]['children']), 'Build::fromText: h1 heading and text'); // check-english: allow
    }

    public function testHtmlSafeAndAttributeHooks(): void
    {
        $this->assertSame('<p class="x">A <a>b</a><img src="x"></p><a href="/k" target="_blank" rel="noopener">k</a>', \Talea\Core\Html::safe('<p class="x" onclick="a()">A <a href="javascript:alert(1)">b</a><img src="x" onerror="alert(1)"><script>alert(1)</script></p><iframe src="https://x"></iframe><a href="/k" target="_blank" data-insert="javascript:x">k</a>'), 'Html::safe: without scripts, event handlers and javascript:, with structure and classes');
        $this->assertSame([0, 0, 1], [preg_match(\Talea\Builder\Build::ATTRIBUTE_PATTERN, 'data-insert'), preg_match(\Talea\Builder\Build::ATTRIBUTE_PATTERN, 'data-self'), preg_match(\Talea\Builder\Build::ATTRIBUTE_PATTERN, 'data-track')], 'Build: site script hooks cannot be inserted as a custom attribute');
    }

    public function testCompactButtonsAndOverview(): void
    {
        [$compactBuild] = \Talea\Builder\Build::sanitize(['v' => 1, 'children' => [['type' => 'section', 'id' => 'abc', 'children' => [['type' => 'button', 'id' => 'def', 'content' => ['text' => 'Jdi']], ['type' => 'container', 'id' => 'ghi', 'style' => ['base' => ['display' => 'flex', 'direction' => 'column', 'gap' => 'm']]]]]]]);
        $compact = \Talea\Builder\Build::compact($compactBuild);
        $this->assertSame(['v' => 1, 'children' => [['id' => 'abc', 'type' => 'section', 'children' => [['id' => 'def', 'type' => 'button', 'content' => ['text' => 'Jdi']], ['id' => 'ghi', 'type' => 'container', 'style' => ['base' => ['display' => 'flex', 'direction' => 'column', 'gap' => 'm']]]]]]], $compact, 'Build::compact: without default values, the style stays');
        $this->assertSame('Publish<mark>.</mark>', \Talea\Builder\Build::sanitize(['v' => 1, 'children' => [['type' => 'heading', 'id' => 'mk1', 'content' => ['text' => 'Publish<mark class="x" style="color:red">.</mark>']]]])[0]['children'][0]['content']['text'], 'Build: the <mark> highlight in a heading stays, classes and styles do not');
        $this->assertSame([['margin_bottom' => '24px'], ['align_items' => 'start']], [\Talea\Builder\Style::fromCss('margin-bottom', '24px'), \Talea\Builder\Style::fromCss('align-items', 'flex-start')], 'Styl::zCss: aliasy margin-top a flex-start');
        $this->assertSame([['text_align' => 'start'], ['text_align' => 'end']], [\Talea\Builder\Style::fromCss('text-align', 'left'), \Talea\Builder\Style::fromCss('text-align', 'right')], 'Styl::zCss: text-align left/right');
        $this->assertTrue(str_contains(\Talea\Builder\Icons::svg('github'), 'M9 19c-4'), 'Icons: GitHub is in the set');
        $this->assertSame([true, true, true], [
            (bool) preg_match('#>Start<svg#', \Talea\Builder\Elements\Button::render(['content' => ['text' => 'Start', 'link' => '/x', 'variant' => 'primary', 'new_window' => false, 'icon' => 'arrow', 'icon_left' => false]], '', '', (new \ReflectionClass(\Talea\Builder\Context::class))->newInstanceWithoutConstructor())),
            (bool) preg_match('#</svg>GitHub</a>#', \Talea\Builder\Elements\Button::render(['content' => ['text' => 'GitHub', 'link' => '/x', 'variant' => 'outline', 'new_window' => false, 'icon' => 'github', 'icon_left' => true]], '', '', (new \ReflectionClass(\Talea\Builder\Context::class))->newInstanceWithoutConstructor())),
            \Talea\Builder\Elements\Button::render(['content' => ['text' => 'Bez', 'link' => '/x', 'variant' => 'primary', 'new_window' => false]], '', '', (new \ReflectionClass(\Talea\Builder\Context::class))->newInstanceWithoutConstructor()) === '<a class="tl-button tl-button--primary" href="/x">Bez</a>',
        ], 'Button: icon after the text and to the left of the text');
        $this->assertSame($compactBuild, \Talea\Builder\Build::sanitize($compact)[0], 'Build::compact: the same build after sanitizing');
        $overview = \Talea\Builder\Build::overview(\Talea\Builder\Build::schema());
        $this->assertSame([true, true], [str_contains($overview['elements']['button'], 'variant:choice(primary*|'), strlen((string) json_encode($overview)) < strlen((string) json_encode(\Talea\Builder\Build::schema())) / 2], 'Build::overview: one element per line, the default option with an asterisk, a much smaller schema');
    }
}
