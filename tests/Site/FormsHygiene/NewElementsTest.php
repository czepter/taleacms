<?php

declare(strict_types=1);

namespace Talea\Tests\Site\FormsHygiene;

use Talea\Tests\Site\Support\SiteTestCase;
use PHPUnit\Framework\Attributes\Group;

/** Pricing table, before and after, hotspots, timeline (was: section 73,). */
#[Group('site')]
final class NewElementsTest extends SiteTestCase
{
    use McpHelpers;

    private static int $page = 0;

    private function schemaLine(mixed $element): string
    {
        return is_string($element) ? $element : (string) json_encode($element, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function testBuilderSchemaListsTheFourElementsWithEnglishNamesAndFields(): void
    {
        $elements = $this->site()->mcpResult('builder_schema')['elements'] ?? [];

        $this->assertMatchesRegularExpression('/Pricing table.*plans:items\[name:text; price:text; period:text; description:text; features:lines; button_text:text; link:link; highlighted:boolean; badge:text\]/', $this->schemaLine($elements['pricing_table'] ?? ''));
        $this->assertMatchesRegularExpression('/Before and after.*before_image:image; before_alt:text; before_label:text; after_image:image; after_alt:text; after_label:text; divider_position:number=50/', $this->schemaLine($elements['before_after'] ?? ''));
        $this->assertMatchesRegularExpression('/Hotspots.*points:items\[x:number; y:number; name:text; description:lines\]/', $this->schemaLine($elements['hotspots'] ?? ''));
        $this->assertMatchesRegularExpression('/Timeline.*milestones:items\[date:text; name:text; content:html; src:image; alt:text\]/', $this->schemaLine($elements['timeline'] ?? ''));
    }

    public function testSaveBuildReportsARejectedPlanLinkInsideAnItem(): void
    {
        self::$page = $this->createPage(['title' => 'Elements 2.12', 'slug' => 'elements-2-12', 'visible' => true]);
        $answer = $this->mcpRawAnswer('save_build', ['id' => $this->site()->publicId('pages', self::$page), 'publish' => true, 'build' => ['v' => 1, 'children' => [['type' => 'section', 'children' => [
            ['type' => 'heading', 'tag' => 'h1', 'content' => ['text' => 'Elements']],
            ['type' => 'pricing_table', 'content' => ['plans' => [
                ['name' => 'Basic', 'price' => '9', 'period' => '/ month', 'features' => "One\n- Two", 'button_text' => 'Choose', 'link' => '/contact'],
                ['name' => 'Pro', 'price' => '29', 'features' => "One\nTwo", 'button_text' => 'Choose', 'link' => 'javascript:alert(1)', 'highlighted' => true, 'badge' => 'Most popular'],
            ]]],
            ['type' => 'before_after', 'content' => ['before_image' => 'media/before.jpg', 'after_image' => 'media/after.jpg', 'before_alt' => 'Before', 'after_alt' => 'After', 'divider_position' => 40]],
            ['type' => 'hotspots', 'content' => ['src' => 'media/plan.jpg', 'alt' => 'Plan', 'points' => [
                ['x' => 20, 'y' => 30, 'name' => 'Entrance', 'description' => 'Main door'], ['x' => 80, 'y' => 70, 'name' => 'Workshop', 'description' => ''],
            ]]],
            ['type' => 'timeline', 'content' => ['milestones' => [
                ['date' => '2020', 'name' => 'Founded', 'content' => '<p>Start</p>'], ['date' => '2024', 'name' => 'New hall', 'content' => '<p>Growth</p>'],
            ]]],
        ]]]]]);

        $this->assertStringContainsString('content.plans.link', $answer, 'MCP: save_build reports the rejected plan link (inside an item)');
    }

    public function testTheBuildIsStoredInEnglishKeys(): void
    {
        $row = $this->site()->rows("SELECT JSON_UNQUOTE(JSON_EXTRACT(build, '$.children[0].children[1].type')) AS a, JSON_UNQUOTE(JSON_EXTRACT(build, '$.children[0].children[1].content.plans[1].highlighted')) AS b,
            JSON_UNQUOTE(JSON_EXTRACT(build, '$.children[0].children[1].content.plans[1].link')) AS c, JSON_UNQUOTE(JSON_EXTRACT(build, '$.children[0].children[3].content.points[0].x')) AS d,
            JSON_UNQUOTE(JSON_EXTRACT(build, '$.children[0].children[4].content.milestones[1].date')) AS e FROM tl_pages WHERE page_id = ?", [self::$page])[0];

        $this->assertSame(['pricing_table', 'true', '', '20', '2024'], array_values($row), '2.12: the build is stored in English keys, the item fields too');
    }

    public function testThePageRendersTheFourElements(): void
    {
        $this->site()->clearPageCache();
        $response = $this->assertPage('/elements-2-12', 200, 'tl-pricing-plan--highlighted', message: '2.12: the page renders the four elements');

        $this->assertTrue($response->contains('<p class="tl-pricing-badge">Most popular</p>'), 'the highlighted plan has its label');
        $this->assertTrue($response->contains('<li class="tl-pricing-no"><span class="tl-pricing-sr">Not included: </span>Two</li>'), 'an excluded feature has a text for screen readers');
        $this->assertTrue($response->contains('<a class="tl-button tl-button--primary" href="#">Choose</a>'), 'the rejected link leaves the button without a target');
        $this->assertTrue($response->contains('<input type="range" class="tl-before-after-handle" min="0" max="100" value="40" aria-label="Compare before and after">'), 'the range control');
        $this->assertTrue($response->contains('<figcaption>Before</figcaption>'), 'before/after caption');
        $this->assertTrue($response->matches('#<details class="tl-hotspots-point tl-hotspots-point--left tl-hotspots-point--up" name="hs-[a-z0-9]*" style="--x:80%;--y:70%"><summary><span aria-hidden="true">2</span><span class="tl-hotspots-sr">Workshop</span></summary>#'), 'hotspot popovers');
        $this->assertTrue($response->contains('<ol class="tl-hotspots-list"><li><strong>Entrance</strong> – Main door</li>'), 'the hotspot list');
        $this->assertTrue($response->contains('<ol class="tl-timeline"><li class="tl-timeline-item"><div class="tl-timeline-card"><span class="tl-timeline-date">2020</span><h3>Founded</h3><p>Start</p>'), 'the timeline list');

        $this->assertTrue($response->contains('image/web.js'), 'web.js stays on the page for the slider');
        $this->assertTrue($response->contains('.tl-before-after[data-enabled] .tl-before-after-after { clip-path'), 'the element CSS of the slider is there');
        $this->assertTrue($response->contains('.tl-button--primary {'), 'the button CSS of the plans is there');
        $this->assertTrue($response->contains('prefers-reduced-motion: no-preference) {'), 'the hotspot pulse respects reduced motion');
    }

    public function testThePublishedTextHasThePlansPointsAndMilestonesForSearch(): void
    {
        $this->assertSame('1111', (string) $this->site()->value("SELECT CONCAT(text LIKE '%<h3>Pro</h3><p>29</p><ul><li>One</li><li>Two</li></ul>%', text LIKE '%<li>Two (not included)</li>%', text LIKE '%<li>Entrance – Main door</li>%', text LIKE '%<h3>2024 – New hall</h3><p>Growth</p>%') FROM tl_pages WHERE page_id = ?", [self::$page]),
            '2.12: the published text has the plans, points and milestones for search');
    }

    public function testGetBuildAnswersWithEnglishElementAndItemNames(): void
    {
        $build = $this->site()->mcpResult('get_build', ['id' => $this->site()->publicId('pages', self::$page)])['build']['children'][0]['children'] ?? [];

        $this->assertSame('pricing_table|Most popular|Workshop|2020', ($build[1]['type'] ?? '') . '|' . ($build[1]['content']['plans'][1]['badge'] ?? '') . '|' . ($build[3]['content']['points'][1]['name'] ?? '') . '|' . ($build[4]['content']['milestones'][0]['date'] ?? ''),
            'MCP: get_build answers with the English element and item names');
        $this->mcpText('trash_page', ['id' => $this->site()->publicId('pages', self::$page)]);
    }
}
