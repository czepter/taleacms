<?php

declare(strict_types=1);

namespace Talea\Tests\Unit\Builder;

use PHPUnit\Framework\TestCase;
use Talea\Builder\Build;
use Talea\Builder\Context;
use Talea\Builder\Renderer;
use Talea\Core\App;
use Talea\Core\Request;
use Talea\Core\Settings;

final class FormRenderTest extends TestCase
{
    /**
     * Sanitizes a build with one form and renders it for a visitor (no database: the settings are an empty list).
     *
     * @param list<string> $keys keys put on the fields in order
     */
    private function renderForm(array $content, array $keys = []): string
    {
        foreach ($keys as $n => $key) {
            $content['fields'][$n]['key'] = $key;
        }
        [$build] = Build::sanitize(['v' => 1, 'children' => [['type' => 'form', 'id' => 'f1', 'content' => $content]]]);
        $app = new App(['db' => ['driver' => 'mysql', 'host' => 'x', 'name' => 'x']], new Request([], [], ['REQUEST_URI' => '/quiz', 'HTTP_HOST' => 'localhost']));
        $settings = (new \ReflectionClass(Settings::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(Settings::class, 'values'))->setValue($settings, ['secret_key' => str_repeat('a', 64)]);
        (new \ReflectionProperty(App::class, 'settings'))->setValue($app, $settings);

        return Renderer::html($build, new Context($app));
    }

    /** The logic the page carries, decoded from the form's data-logic attribute. */
    private function logic(string $html): array
    {
        $this->assertSame(1, preg_match('/data-logic="([^"]*)"/', $html, $m), 'data-logic is on the form');

        return json_decode(html_entity_decode($m[1]), true, 512, JSON_THROW_ON_ERROR);
    }

    public function testAfterContactPageCarriesNoAmounts(): void
    {
        $html = $this->renderForm(['calculation' => 'after_contact', 'fields' => [
            ['label' => 'Where', 'type' => 'radio', 'display' => 'image_tiles', 'choices' => "Barn | 100\nCastle | 200", 'option_media' => "media/a.webp\nmedia/b.webp"],
            ['label' => 'Guests', 'type' => 'number', 'display' => 'slider', 'min' => '10', 'max' => '250', 'unit_price' => '12'],
            ['label' => 'Estimate', 'type' => 'estimate', 'base_price' => '900'],
        ], 'logic' => ['rules' => [
            ['match' => 'all', 'when' => [['field' => 'kaaaa', 'op' => 'is', 'value' => 'Barn']], 'then' => [['do' => 'add', 'amount' => 777], ['do' => 'multiply', 'by' => 1.3]]],
            ['match' => 'all', 'when' => [['field' => 'kaaaa', 'op' => 'is', 'value' => 'Barn']], 'then' => [['do' => 'skip', 'field' => 'kbbbb']]],
        ], 'results' => []]], ['kaaaa', 'kbbbb', 'kcccc']);
        $this->assertStringContainsString('<div class="tl-condition" data-field="1">', $html);
        foreach (['data-base', 'data-price', 'data-price-per', 'data-estimate', '| 100'] as $leak) {
            $this->assertStringNotContainsString($leak, $html, $leak);
        }
        $this->assertStringContainsString('tl-estimate-later', $html);
        $this->assertStringContainsString('type="range" min="10" max="250"', $html);
        $this->assertStringContainsString('<img', $html);
        $logic = $this->logic($html);
        $this->assertArrayNotHasKey('estimate', $logic);
        $json = (string) json_encode($logic);
        foreach (['777', '900', 'amount', '"by"', '"base"', '"add"', '"multiply"', '"result"', '"quantity"'] as $leak) {
            $this->assertStringNotContainsString($leak, $json, $leak);
        }
        $this->assertSame([['skip']], array_map(static fn (array $r): array => array_column($r['then'], 'do'), $logic['rules']));
    }

    public function testLiveFormShipsAddAndQuantityButNeverMultiplyOrResult(): void
    {
        $html = $this->renderForm(['fields' => [
            ['label' => 'Where', 'type' => 'radio', 'choices' => "Barn\nCastle"],
            ['label' => 'Guests', 'type' => 'number'],
            ['label' => 'Estimate', 'type' => 'estimate', 'base_price' => '900', 'unit_suffix' => 'EUR'],
        ], 'logic' => ['rules' => [['match' => 'all', 'when' => [['field' => 'kaaaa', 'op' => 'is', 'value' => 'Barn']], 'then' => [
            ['do' => 'add', 'amount' => 777], ['do' => 'quantity', 'field' => 'kbbbb', 'amount' => 5], ['do' => 'multiply', 'by' => 1.3],
        ]]], 'results' => []]], ['kaaaa', 'kbbbb', 'kcccc']);
        $logic = $this->logic($html);
        $this->assertSame([['add', 'quantity']], array_map(static fn (array $r): array => array_column($r['then'], 'do'), $logic['rules']));
        $this->assertSame(900.0, (float) $logic['estimate']['base']);
        $this->assertSame('EUR', $logic['estimate']['suffix']);
        $this->assertStringContainsString('data-estimate', $html);
    }

    public function testTilesAreRealRadioButtons(): void
    {
        $html = $this->renderForm(['fields' => [['label' => 'Roof', 'type' => 'radio', 'display' => 'icon_tiles', 'choices' => "Gable\nFlat", 'option_media' => "home\nstar"]]]);
        $this->assertSame(2, substr_count($html, 'type="radio"'));
        $this->assertSame(2, substr_count($html, '<svg'));
        $this->assertStringContainsString('tl-tiles', $html);
    }

    public function testTileMediaFollowsTheOptionLine(): void
    {
        // a repeated label is one option; the next option still gets the picture on its own line
        $html = $this->renderForm(['fields' => [['label' => 'Where', 'type' => 'radio', 'display' => 'image_tiles', 'choices' => "Barn | 100\nBarn | 100\nCastle | 200", 'option_media' => "media/a.webp\nmedia/b.webp\nmedia/c.webp"]]]);
        $this->assertSame(2, substr_count($html, 'type="radio"'));
        $this->assertMatchesRegularExpression('/value="Barn"[^>]*><span class="tl-tile-media"><img src="[^"]*a\.webp"/', $html);
        $this->assertMatchesRegularExpression('/value="Castle"[^>]*><span class="tl-tile-media"><img src="[^"]*c\.webp"/', $html);
        $this->assertStringNotContainsString('b.webp', $html);
    }

    public function testTimeRangeHasTwoTimeInputs(): void
    {
        $html = $this->renderForm(['fields' => [['label' => 'When', 'type' => 'timerange', 'required' => true]]]);
        $this->assertSame(2, substr_count($html, 'type="time" name="p0[]"'));
    }

    public function testLiveFormCarriesLogicForTheScript(): void
    {
        $html = $this->renderForm(['fields' => [['label' => 'A', 'type' => 'radio', 'choices' => "Yes\nNo"], ['label' => 'B', 'type' => 'text']],
            'logic' => ['rules' => [['match' => 'all', 'when' => [['field' => 'kaaaa', 'op' => 'is', 'value' => 'Yes']], 'then' => [['do' => 'skip', 'field' => 'kbbbb']]]], 'results' => []]], ['kaaaa', 'kbbbb']);
        $this->assertStringContainsString('<div class="tl-condition" data-field="1">', $html);
        $this->assertStringContainsString('data-logic=', $html);
        $this->assertStringNotContainsString('data-when', $html);
    }
}
