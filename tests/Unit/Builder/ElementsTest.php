<?php

declare(strict_types=1);

namespace Talea\Tests\Unit\Builder;

use PHPUnit\Framework\TestCase;

final class ElementsTest extends TestCase
{
    public function testCustomHtmlValuesPhoneMenuAndPopupPatterns(): void
    {
        $navCss = \Talea\Builder\Elements\Navigation::baseCss();
        $this->assertSame(['<div title="&lt;img src=x onerror=alert(1)&gt;&quot;">&lt;img src=x onerror=alert(1)&gt;&quot;</div>', '<div><p>Ahoj</p><img src="x"></div>'], [
            \Talea\Builder\Collections::fill('<div title="{{name}}">{{name}}</div>', 'code', ['name' => ['<img src=x onerror=alert(1)>"', 'text']]),
            \Talea\Builder\Collections::fill('<div>{{body}}</div>', 'code', ['body' => ['<p>Ahoj</p><img src=x onerror=alert(1)>', 'html']]),
        ], 'Collection: a value in Custom HTML is escaped, formatted text is sanitized');
        $this->assertTrue((bool) preg_match('/@media \\(max-width: 767px\\).*?\\.tl-nav-menu\\[popover\\] \\{[^}]*max-height:[^}]*overflow-y: auto/s', $navCss), 'Navigation: the phone menu can scroll (a long menu with groups)');
        $this->assertSame(array_fill(0, count(\Talea\Builder\Popups::LIBRARY), true), array_map(fn (string $k): bool => count(\Talea\Builder\Popups::libraryBuild($k, 'en')['children']) === 1, array_keys(\Talea\Builder\Popups::LIBRARY)), 'Pop-up: every library pattern builds');
    }

    public function testContainerAsLink(): void
    {
        $this->assertSame('<a class="tl-card-link" href="/k"><p>x</p><span class="tl-button">B</span><abbr>z</abbr></a>', \Talea\Builder\Elements\Container::render(['tag' => 'div', 'content' => ['link' => '/k']], '', '<p>x</p><a class="tl-button" href="/y" target="_blank">B</a><abbr>z</abbr>', new \Talea\Builder\Context((new \ReflectionClass(\Talea\Core\App::class))->newInstanceWithoutConstructor())), 'Container as a link: links inside become a span');
    }

    /** 2.12: pricing table, before and after, hotspots, timeline */
    public function testPricingBeforeAfterHotspotsAndTimeline(): void
    {
        $f9App = (new \ReflectionClass(\Talea\Core\App::class))->newInstanceWithoutConstructor();
        $f9Context = new \Talea\Builder\Context($f9App);
        $f9Editor = new \Talea\Builder\Context($f9App, true);
        [$f9Build, $f9Errors] = \Talea\Builder\Build::sanitize(['children' => [
            ['type' => 'pricing_table', 'id' => 'cen1', 'content' => ['plans' => [
                ['name' => 'Basic', 'price' => '9', 'period' => '/ month', 'description' => 'Start', 'features' => "One\n- Two\n  \n-", 'button_text' => 'Choose', 'link' => 'javascript:alert(1)', 'highlighted' => false, 'badge' => 'Most popular'],
                ['name' => 'Pro', 'price' => '29', 'features' => 'One', 'button_text' => 'Choose', 'link' => '/order', 'highlighted' => true, 'badge' => 'Most popular'],
                ['name' => '', 'price' => '0'],
            ]]],
            ['type' => 'hotspots', 'id' => 'hs1', 'content' => ['src' => '/media/2026/plan.jpg', 'alt' => 'Plan', 'points' => [['x' => 250, 'y' => -5, 'name' => 'Entrance', 'description' => "Line 1\nLine & 2"], ['x' => 20, 'y' => 30, 'name' => '', 'description' => 'hidden']]]],
            ['type' => 'before_after', 'id' => 'pp1', 'content' => ['before_image' => '/media/2026/a.jpg', 'after_image' => 'http://x.cz/b.jpg', 'divider_position' => 130]],
            ['type' => 'timeline', 'id' => 'to1', 'content' => ['milestones' => [['date' => '2020', 'name' => 'Start', 'content' => '<p onclick="x">Text</p>'], ['date' => '', 'name' => '', 'content' => '<p>skip</p>'], ['date' => 'Today', 'name' => '', 'content' => '']]]],
        ]]);
        [$f9Pricing, $f9Hotspots, $f9BeforeAfter, $f9Timeline] = $f9Build['children'];
        $this->assertSame(['', '/order', '', ['children[0].content.plans.link', 'children[2].content.after_image'], 100, 0, 100, '', '<p>Text</p>', ''], [
            $f9Pricing['content']['plans'][0]['link'], $f9Pricing['content']['plans'][1]['link'], $f9Pricing['content']['plans'][1]['period'], array_keys($f9Errors),
            $f9Hotspots['content']['points'][0]['x'], $f9Hotspots['content']['points'][0]['y'], $f9BeforeAfter['content']['divider_position'], $f9BeforeAfter['content']['after_image'], $f9Timeline['content']['milestones'][0]['content'], $f9Timeline['content']['milestones'][0]['src']], '2.12 sanitize: a plan link is checked like a button, the hotspot position is clamped to 0–100, the divider too, timeline HTML is cleaned, item defaults filled in');
        $this->assertSame([[true, 'One'], [false, 'Two'], [false, 'Three']], \Talea\Builder\Elements\PricingTable::features("One\n- Two\n  \n-\n -Three "), '2.12 PricingTable::features – a line starting with "-" is not included, blank lines and a bare dash are skipped');
        $f9Html = \Talea\Builder\Elements\PricingTable::render($f9Pricing, '', '', $f9Context);
        $this->assertSame([2, 1, 1, true, true, true, true, true, true, true, true], [
            substr_count($f9Html, '<article class="tl-pricing-plan'), substr_count($f9Html, 'tl-pricing-plan--highlighted'), substr_count($f9Html, '<p class="tl-pricing-badge">Most popular</p>'),
            str_contains($f9Html, '<li class="tl-pricing-no"><span class="tl-pricing-sr">' . t('Not included:') . ' </span>Two</li>'), str_contains($f9Html, '<li><span class="tl-pricing-sr">' . t('Included:') . ' </span>One</li>'),
            str_contains($f9Html, '<a class="tl-button tl-button--outline" href="#">Choose</a>'), str_contains($f9Html, '<a class="tl-button tl-button--primary" href="/order">Choose</a>'),
            str_contains($f9Html, '<p class="tl-pricing-price"><strong>9</strong> <span>/ month</span></p>'), str_contains($f9Html, '<p class="tl-pricing-price"><strong>29</strong></p>'), isset($f9Context->types['button']), str_starts_with($f9Html, '<div class="tl-pricing">')], '2.12 pricing table: two cards (an unnamed plan is left out), the highlighted one with its class, label and primary button, the other with an outline button, an excluded feature crossed out with a text for screen readers, a discarded link falls back to #');
        $f9Html = \Talea\Builder\Elements\BeforeAfter::render($f9BeforeAfter, '', '', $f9Context);
        $this->assertSame(['', true], [$f9Html, str_contains(\Talea\Builder\Elements\BeforeAfter::render($f9BeforeAfter, '', '', $f9Editor), t('Choose the before and after images in the Content panel.'))], '2.12 before and after: without the after image nothing for visitors, a notice in the editor');
        $f9BeforeAfter['content']['after_image'] = '/media/2026/b.jpg';
        $f9Html = \Talea\Builder\Elements\BeforeAfter::render($f9BeforeAfter, '', '', $f9Context);
        $this->assertSame([2, 2, true, true, true, true], [
            substr_count($f9Html, '<figure class="tl-before-after-'), substr_count($f9Html, '<figcaption>'), str_contains($f9Html, '<figure class="tl-before-after-before"><img src="/media/2026/a.jpg" alt="" loading="lazy"><figcaption>' . t('Before') . '</figcaption></figure>'),
            str_contains($f9Html, '<input type="range" class="tl-before-after-handle" min="0" max="100" value="100" aria-label="' . t('Compare before and after') . '">'),
            str_starts_with($f9Html, '<div class="tl-before-after" data-before-after style="--tl-split:100%">'), str_contains(\Talea\Builder\Elements\BeforeAfter::render($f9BeforeAfter, '', '', $f9Editor), ' data-before-after data-enabled ')], '2.12 before and after: two figures with their labels, the range control with the divider position, the data hook for web.js, enabled only in the editor');
        $f9Html = \Talea\Builder\Elements\Hotspots::render($f9Hotspots, '', '', $f9Context);
        $this->assertSame([1, true, true, true, true, true], [
            substr_count($f9Html, '<details'), str_contains($f9Html, '<details class="tl-hotspots-point tl-hotspots-point--left" name="hs-hs1" style="--x:100%;--y:0%">'),
            str_contains($f9Html, '<summary><span aria-hidden="true">1</span><span class="tl-hotspots-sr">Entrance</span></summary>'),
            str_contains($f9Html, '<div class="tl-hotspots-description"><strong>Entrance</strong><p>Line 1<br />' . "\n" . 'Line &amp; 2</p></div>'),
            str_contains($f9Html, '<ol class="tl-hotspots-list"><li><strong>Entrance</strong> – Line 1<br />'), str_contains($f9Html, '<img src="/media/2026/plan.jpg" alt="Plan" loading="lazy">')], '2.12 hotspots: one point (the unnamed one is left out) as a details popover placed at the clamped position, opening to the left, the number hidden from screen readers and the label read instead, the text with line breaks escaped, and the list under the image');
        $f9Hotspots['content']['points'][0] = ['x' => 10, 'y' => 90, 'name' => 'Low', 'description' => ''];
        $this->assertSame([true, ''], [
            str_contains(\Talea\Builder\Elements\Hotspots::render($f9Hotspots, '', '', $f9Context), '<details class="tl-hotspots-point tl-hotspots-point--up" name="hs-hs1" style="--x:10%;--y:90%"><summary><span aria-hidden="true">1</span><span class="tl-hotspots-sr">Low</span></summary><div class="tl-hotspots-description"><strong>Low</strong></div></details>'),
            \Talea\Builder\Elements\Hotspots::render(['id' => 'hs2', 'content' => ['src' => '', 'alt' => '', 'points' => []]], '', '', $f9Context)], '2.12 hotspots: a point low down opens upwards, a point on the left opens to the right; without a text only the label');
        $f9Html = \Talea\Builder\Elements\Timeline::render($f9Timeline, '', '', $f9Context);
        $this->assertSame([true, 2, true, true, ''], [
            str_starts_with($f9Html, '<ol class="tl-timeline">'), substr_count($f9Html, '<li class="tl-timeline-item">'),
            str_contains($f9Html, '<li class="tl-timeline-item"><div class="tl-timeline-card"><span class="tl-timeline-date">2020</span><h3>Start</h3><p>Text</p></div></li>'),
            str_contains($f9Html, '<span class="tl-timeline-date">Today</span></div></li></ol>'), \Talea\Builder\Elements\Timeline::render(['id' => 'to2', 'content' => ['milestones' => []]], '', '', $f9Context)], '2.12 timeline: an ordered list, an item without a date and title is left out, a date alone stays, the date, heading and cleaned text in the card');
        $f9Text = \Talea\Builder\Build::asText($f9Build);
        $this->assertSame([true, true, true], [
            str_contains($f9Text, "<h3>Basic</h3><p>9 / month</p><p>Start</p><ul><li>One</li><li>Two (" . t('not included') . ")</li></ul>\n<h3>Pro</h3><p>29</p><ul><li>One</li></ul>"),
            str_contains($f9Text, '<ol><li>Entrance – Line 1' . "\n" . 'Line &amp; 2</li></ol>'), str_contains($f9Text, "<h3>2020 – Start</h3><p>Text</p>\n<h3>Today</h3>")], '2.12 Build::asText – plans with prices and features, points and milestones are page content for search and the .md version');
        $f9Schema = array_column(\Talea\Builder\Build::schema(true, 'en')['elements'], null, 'type');
        $this->assertSame([['Content', 'Content', 'Content', 'Content'], ['Basic', 'Standard', 'Premium'], true, 'Before', 2, 'Today', 0, 1], [
            array_map(fn (string $t): string => $f9Schema[$t]['group'], ['pricing_table', 'before_after', 'hotspots', 'timeline']), array_column($f9Schema['pricing_table']['properties']['plans']['default'], 'name'), $f9Schema['pricing_table']['properties']['plans']['default'][1]['highlighted'],
            $f9Schema['before_after']['properties']['before_label']['default'], count($f9Schema['hotspots']['properties']['points']['default']), $f9Schema['timeline']['properties']['milestones']['default'][2]['date'],
            preg_match(\Talea\Builder\Build::ATTRIBUTE_PATTERN, 'data-before-after'), preg_match('/data-\(insert\|[^)]*before-after/', (string) file_get_contents(TALEA_SYSTEM . '/src/Front/Kernel.php'))], '2.12 schema: the four elements are content blocks with a sensible default in the page language, the before-and-after hook cannot be a custom attribute');
    }

    /** the form element's inspector shows each field as a row: the summary names real fields, the groups are the ones builder.js folds */
    public function testFormFieldRows(): void
    {
        $fields = \Talea\Builder\Elements\Form::properties()['fields'];
        $this->assertSame([], array_diff($fields['summary'], array_keys($fields['fields'])), 'Form: the row summary names fields of the item');
        $groups = array_values(array_unique(array_filter(array_map(fn (array $f): string => $f['group'] ?? '', $fields['fields']))));
        sort($groups);
        $this->assertSame(['condition', 'estimate', 'options'], $groups, 'Form: the field settings are grouped into options, the estimate and the condition');
    }
}
