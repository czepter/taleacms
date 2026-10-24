<?php

declare(strict_types=1);

namespace Talea\Tests\Site\Appearance;

use Talea\Tests\Site\FormsLook\SiteHelpers;
use Talea\Tests\Site\Support\SiteTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * #46: onboarding without AI – topic, features, how to start, the looks gallery re-ranked by the topic, a look tried on with a
 * palette and font pair (never stored), then used: draft look, the features' extensions on, missing starter pages as hidden drafts.
 */
#[Group('site')]
final class OnboardingTest extends SiteTestCase
{
    use SiteHelpers;

    private const string DS = "SELECT value FROM tl_settings WHERE name = 'design_system'";

    public function testTheThreeQuestionsCarryTheAnswers(): void
    {
        $this->site()->exec("DELETE FROM tl_settings WHERE name = 'ai_key'");
        $this->assertPage('/admin.php?module=wizard', 200, ['What is your site about?', '<datalist id="o-topics">', '<option value="Restaurant and café">', 'value="features"'], message: 'step 1: the topic');
        $features = $this->assertPage('/admin.php?module=wizard&action=features&topic=Restaurant+and+caf%C3%A9', 200, ['name="features[]" value="bookings"', 'value="start"'], message: 'step 2: the features');
        $this->assertSame('restaurant', $this->formField($features->body, 'topic'), 'the topic name becomes its key');
        $start = $this->assertPage('/admin.php?module=wizard&action=start&topic=restaurant&features%5B%5D=bookings&features%5B%5D=nothing', 200, 'Choose a look', message: 'step 3: how to start');
        $this->assertStringContainsString('/admin/wizard/looks?topic=restaurant&amp;features%5B0%5D=bookings"', $start->body, 'the answers go on to the gallery, unknown features are dropped');
        $this->assertStringNotContainsString('Let AI draft it', $start->body, 'without a key the AI wizard is not offered');
        $this->assertPage('/admin.php?module=wizard&action=start&features%5B0%5D%5B%5D=x', 200, message: 'a nested features list is ignored, not an error');
        $ai = $this->assertPage('/admin.php?module=wizard&action=ai&topic=restaurant&features%5B%5D=bookings', 200, 'name="features[]" value="bookings"', message: 'the AI wizard keeps the features');
        $this->assertStringContainsString('value="Restaurant and café"', $ai->body, 'the topic fills the kind of business in own words');
    }

    public function testTheTopicReranksTheGalleryAndALookIsTriedOnWithoutStoringIt(): void
    {
        $gallery = $this->assertPage('/admin.php?module=wizard&action=looks&topic=restaurant', 200, 'Brasserie', message: 'the gallery');
        $this->assertSame(14, substr_count($gallery->body, 'class="look-card"'), 'every look is shown, none filtered out');
        $this->assertLessThan(strpos($gallery->body, '>Atelier<'), strpos($gallery->body, '>Brasserie<'), 'the best fit of the topic comes first');

        $published = $this->sql(self::DS);
        $draft = $this->sql("SELECT value FROM tl_settings WHERE name = 'look_draft'");
        $look = $this->assertPage('/admin.php?module=wizard&action=look&key=atelier&palette=1&fonts=2&topic=photography', 200, ['<iframe src=', 'look=atelier&amp;palette=1&amp;fonts=2', 'Use this look'], message: 'the look detail');
        $this->assertStringContainsString('name="palette" value="1" checked', $look->body);
        $this->assertPage('/admin.php?module=wizard&action=look&key=nothing', 302, message: 'an unknown look goes back to the gallery');

        $this->site()->clearPageCache();
        $tried = $this->site()->admin()->get('/?look=atelier&palette=1&fonts=2');
        $this->assertStringContainsString('tl-color-primary: #2f3e46', $tried->body, 'the administrator sees the palette on the site');
        $this->assertStringContainsString('dm-sans', $tried->body, 'and the font pair');
        $this->assertStringNotContainsString('tl-color-primary: #2f3e46', $this->site()->client()->get('/?look=atelier&palette=1')->body, 'a visitor never does');
        $this->assertSame($published . '|' . $draft, $this->sql(self::DS) . '|' . $this->sql("SELECT value FROM tl_settings WHERE name = 'look_draft'"), 'trying on stores nothing');
    }

    public function testUsingALookMakesDraftsSwitchesOnTheFeaturesAndAddsMissingPages(): void
    {
        $this->call('discard_look');
        $published = $this->sql(self::DS);
        $pages = (int) $this->sql('SELECT COUNT(*) FROM tl_pages');
        $response = $this->adminPost('/admin.php?module=wizard&action=use', ['key' => 'atelier', 'palette' => '2', 'fonts' => '1', 'topic' => 'photography', 'features' => ['bookings', 'contact', 'work']],
            '/admin.php?module=wizard&action=look&key=atelier');
        $this->assertSame(200, $response->status, 'used');
        $this->assertStringContainsString('Bookings', $response->body, 'the result names the new pages');

        $draft = $this->sql("SELECT value FROM tl_settings WHERE name = 'look_draft'");
        $this->assertSame('1|1', (int) str_contains($draft, '#3d405b') . '|' . (int) str_contains($draft, 'lib:playfair-display'), 'the palette and the font pair are in the draft look');
        $this->assertSame($published, $this->sql(self::DS), 'the public design system did not change');
        $this->assertStringContainsString('bookings', $this->sql("SELECT value FROM tl_settings WHERE name = 'extensions'"), 'the bookings feature is switched on');
        $this->assertSame('1', $this->sql("SELECT COUNT(*) FROM tl_pages WHERE slug = 'about-us'"), 'a page the site has is not added again');
        $this->assertSame('0|1', $this->sqlRow("SELECT visible, build_draft LIKE '%\"booking\"%' FROM tl_pages WHERE slug = 'bookings'"), 'the bookings page is a hidden draft with the booking form');
        $this->assertSame($pages + 2, (int) $this->sql('SELECT COUNT(*) FROM tl_pages'), 'only the missing pages are added (bookings, our work)');
        $this->assertSame('1', $this->sql("SELECT COUNT(*) FROM tl_change_log WHERE module = 'wizard' AND action = 'onboarding'"), 'logged');
        $this->assertStringContainsString('no such palette', $this->raw('apply_look', ['look' => 'atelier', 'palette' => 9]), 'MCP: an unknown palette is refused');

        // leave the site as it was
        $this->call('discard_look');
        $this->site()->exec("DELETE FROM tl_pages WHERE slug IN ('bookings', 'our-work')");
        $this->site()->setting('extensions', 'news,enquiries,newsletter_signup,stats,redirects,assistant,languages,claude');
        $this->site()->clearPageCache();
    }

    public function testListLooksRanksByTopicOverMcp(): void
    {
        $looks = $this->call('list_looks', ['topic' => 'real-estate']);
        $this->assertSame('terrace', $this->pick($looks, 'looks', 0, 'key'), 'MCP: the best fit first');
        $this->assertCount(14, $looks['looks'], 'MCP: none left out');
        $this->assertGreaterThanOrEqual(3, count($looks['looks'][0]['palettes']), 'MCP: the palettes are listed');
    }
}
