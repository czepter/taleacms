<?php

declare(strict_types=1);

namespace Talea\Tests\Unit\Builder;

use Dom\HTMLDocument;
use PHPUnit\Framework\TestCase;
use Talea\Builder\Build;
use Talea\Builder\HtmlConverter;
use Talea\Builder\HtmlWidgets;

/** The widgets of an old page are found by structure and come out as the elements of the builder (fixtures in tests/Fixtures/old-pages). */
final class HtmlWidgetsTest extends TestCase
{
    /** @return array{0: list<array{kind: string, html: string, host: string}>, 1: string, 2: array<string, mixed>} the widgets, the page with markers and the converted build */
    private function walk(string $fixture): array
    {
        $doc = HTMLDocument::createFromString((string) file_get_contents(dirname(__DIR__, 2) . '/Fixtures/old-pages/' . $fixture), LIBXML_NOERROR);
        $main = $doc->querySelector('main');
        $widgets = HtmlWidgets::extract($main);
        $html = trim($main->innerHTML);
        $result = HtmlConverter::convert($html, false, $widgets);
        $this->assertSame([], Build::sanitize($result['build'], false)[1], $fixture . ': the build passes the validator');

        return [$widgets, $html, $result['build']];
    }

    /** @return list<array<string, mixed>> */
    private static function elements(array $build, string $type): array
    {
        $found = [];
        $walk = function (array $children) use (&$walk, &$found, $type): void {
            foreach ($children as $el) {
                $el['type'] === $type && $found[] = $el;
                $walk($el['children'] ?? []);
            }
        };
        $walk($build['children']);

        return $found;
    }

    public function testAnAriaAccordionBecomesQuestionsAndAnswers(): void
    {
        [$widgets, $html, $build] = $this->walk('aria-accordion.html');
        $this->assertSame(['accordion'], array_column($widgets, 'kind'));
        $this->assertStringContainsString('<talea-widget title="0"></talea-widget>', $html);
        $this->assertStringNotContainsString('button', $html);
        $faq = self::elements($build, 'faq');
        $this->assertSame(['How long does it take?', 'Do you deliver?'], array_column($faq[0]['content']['items'], 'question'));
        $this->assertStringContainsString('About two weeks.', $faq[0]['content']['items'][0]['answer']);
    }

    public function testClassNamesGiveAnAccordionAwayToo(): void
    {
        [$widgets, , $build] = $this->walk('class-accordion.html');
        $this->assertSame(['accordion'], array_column($widgets, 'kind'));
        $this->assertCount(3, self::elements($build, 'faq')[0]['content']['items']);
    }

    public function testTabsOfAriaAndOfClassNames(): void
    {
        foreach (['aria-tabs.html' => ['Wood', 'Metal'], 'class-tabs.html' => ['Details', 'Delivery']] as $fixture => $names) {
            [$widgets, , $build] = $this->walk($fixture);
            $this->assertSame(['tabs'], array_column($widgets, 'kind'), $fixture);
            $tabs = self::elements($build, 'tabs');
            $this->assertSame($names, array_column($tabs[0]['content']['tabs'], 'name'), $fixture);
            $this->assertStringContainsString('<p>', $tabs[0]['content']['tabs'][0]['content']);
        }
    }

    public function testCountersNeedAHint(): void
    {
        [$widgets, , $build] = $this->walk('counters.html');
        $this->assertSame(['counter', 'counter'], array_column($widgets, 'kind'), 'data-target without a hint in its class is no counter');
        $counters = self::elements($build, 'counter');
        $this->assertSame(['250', '+', 'customers'], [$counters[0]['content']['number'], $counters[0]['content']['suffix'], $counters[0]['content']['caption']]);
        $this->assertSame(['12', 'years'], [$counters[1]['content']['number'], $counters[1]['content']['caption']]);
    }

    public function testFramesAreVideosMapsEmbedsOrUnknown(): void
    {
        [$widgets, , $build] = $this->walk('frames.html');
        $this->assertSame(['video', 'map', 'map', 'map', 'embed', 'frame'], array_column($widgets, 'kind'));
        $this->assertSame('widgets.example', $widgets[5]['host']);
        $this->assertCount(1, self::elements($build, 'video'));
        $this->assertCount(1, self::elements($build, 'embed'));
        $maps = self::elements($build, 'map');
        $this->assertSame(['Main Street 1, Springfield', '50.1, 14.4'], array_column(array_column($maps, 'content'), 'address'), 'the map that says nothing is left to a note');
    }

    public function testFormsSearchBoxesAndScriptForms(): void
    {
        [$widgets, $html, $build] = $this->walk('forms.html');
        $this->assertSame(['form', 'unreadable_form'], array_column($widgets, 'kind'), 'the search box is no widget');
        $this->assertStringContainsString('<form role="search"', $html, 'and stays for the noise filter');
        $this->assertSame(['Name', 'E-mail', 'message'], array_column(self::elements($build, 'form')[0]['content']['fields'], 'label'));
        $this->assertSame('', $widgets[1]['html']);
    }

    public function testAMarkerOfTheOldPageIsRemovedAndTheLimitHolds(): void
    {
        $doc = HTMLDocument::createFromString('<main><talea-widget title="0"></talea-widget><p><iframe src="https://youtu.be/dQw4w9WgXcQ"></iframe></p></main>', LIBXML_NOERROR);
        $widgets = HtmlWidgets::extract($doc->querySelector('main'));
        $this->assertCount(1, $widgets, 'a marker the old page brings itself is gone');
        $this->assertSame('<talea-widget title="0"></talea-widget>', preg_replace('#<p></p>#', '', trim($doc->querySelector('main')->innerHTML)), 'only the marker of the real widget is left');

        $many = str_repeat('<iframe src="https://youtu.be/dQw4w9WgXcQ"></iframe>', 5);
        $doc = HTMLDocument::createFromString('<main>' . $many . '</main>', LIBXML_NOERROR);
        $this->assertCount(3, HtmlWidgets::extract($doc->querySelector('main'), 3));
    }

    public function testGenericClassNamesAreContentNotWidgets(): void
    {
        [$widgets, $html] = $this->walk('elementor-like.html');
        $this->assertSame([], $widgets);
        $this->assertStringContainsString('Our story', $html);
    }
}
