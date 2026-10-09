<?php

declare(strict_types=1);

namespace Kaleta\Tests\Site\FormsLook;

use Kaleta\Tests\Site\Support\SiteTestCase;
use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\Attributes\Group;

/** Pop-up windows and editing right on the site (was: section 20 "pop-up okna" of tools/test.sh). */
#[Group('site')]
final class PopupsTest extends SiteTestCase
{
    use SiteHelpers;

    private static int $popup = 0;
    private static string $previewUrl = '';

    public function testAdminOffersNoWindowsYetAndNewOnesFromATemplate(): void
    {
        $this->assertPage('/admin.php?module=popups', 200, 'Zatím žádná pop-up okna', message: 'pop-ups in the admin');
        $this->assertPage('/admin.php?module=popups&action=new', 200, 'name="template" value="newsletter_signup"', message: 'a new window from a template');
    }

    #[Depends('testAdminOffersNoWindowsYetAndNewOnesFromATemplate')]
    public function testAWindowStartsSwitchedOffAndCannotBeSwitchedOnUnpublished(): void
    {
        $created = $this->call('uloz_popup', ['vzor' => 'blank', 'nazev' => 'Akce okno']);
        self::$popup = (int) $this->pick($created, 'id');
        $this->assertSame('akce-okno|||click', implode('|', [$this->pick($created, 'adresa'), $this->pick($created, 'active'), $this->pick($created, 'publikovano'), $this->pick($created, 'trigger_type')]), 'MCP: the window is created off and unpublished');
        $this->assertStringContainsString('nejdřív publikuj', $this->raw('uloz_popup', ['id' => self::$popup, 'active' => true]), 'MCP: an unpublished window cannot be switched on');
    }

    #[Depends('testAWindowStartsSwitchedOffAndCannotBeSwitchedOnUnpublished')]
    public function testPublishedWindowIsOnTheSiteWithTriggerAndBrowserRules(): void
    {
        $this->call('stavba_uloz', ['popup' => self::$popup, 'publikovat' => true, 'build' => ['v' => 1, 'children' => [
            ['type' => 'heading', 'tag' => 'h2', 'content' => ['text' => 'Okno akce']],
            ['id' => 'ab12cd3', 'type' => 'form', 'content' => ['name' => 'Z okna', 'fields' => [['label' => 'E-mail', 'type' => 'email', 'required' => true]]]],
        ]]]);
        $saved = $this->call('save_popup', ['id' => self::$popup, 'type' => 'slide_in', 'trigger' => 'time', 'value' => 3, 'frequency' => 'until_closed', 'rules' => ['device' => 'phone'], 'active' => true]);
        $this->assertSame('slide_in|time|until_closed|phone|1', implode('|', [$this->pick($saved, 'type'), $this->pick($saved, 'trigger'), $this->pick($saved, 'frequency'), $this->pick($saved, 'rules', 'device'), $this->pick($saved, 'active')]), 'MCP in English: type, trigger, frequency and rules');

        $this->site()->clearPageCache();
        $body = $this->site()->client()->get('/')->body;
        $id = self::$popup;
        $this->assertStringContainsString("data-popup=\"$id\"", $body, 'the switched-on window is on the site');
        $this->assertStringContainsString('class="ka-popup ka-popup--slide_in" popover="manual" role="region"', $body, 'panel window uses the Popover API');
        $this->assertStringContainsString('data-trigger="time" data-value="3" data-frequency="until_closed"', $body, 'trigger and frequency are in the markup');
        $this->assertStringContainsString('data-device="phone"', $body, 'the device rule is in the markup');
        $this->assertStringContainsString('Okno akce', $body, 'the window content is in the page');
        $this->assertStringContainsString('image/web.js', $body, 'the script is loaded');
    }

    #[Depends('testPublishedWindowIsOnTheSiteWithTriggerAndBrowserRules')]
    public function testServerRulesPlacesAndPeriod(): void
    {
        $id = self::$popup;
        $about = (int) $this->sql("SELECT page_id FROM ka_pages WHERE slug = 'o-nas'");
        $this->call('uloz_popup', ['id' => $id, 'rules' => ['where' => 'selected', 'pages' => [$about]]]);
        $this->site()->clearPageCache();
        $visitor = $this->site()->client();
        $this->assertStringNotContainsString("data-popup=\"$id\"", $visitor->get('/')->body, 'the window is not on the home page');
        $this->assertStringContainsString("data-popup=\"$id\"", $visitor->get('/o-nas')->body, 'the window is on the selected page');

        $this->call('uloz_popup', ['id' => $id, 'rules' => ['from' => '2099-01-01']]);
        $this->site()->clearPageCache();
        $this->assertStringNotContainsString("data-popup=\"$id\"", $visitor->get('/o-nas')->body, 'a window out of its period is not put into the page');

        $saved = $this->call('uloz_popup', ['id' => $id, 'rules' => ['from' => '', 'where' => 'all']]);
        self::$previewUrl = $this->pick($saved, 'nahled');
        $this->site()->clearPageCache();
    }

    #[Depends('testServerRulesPlacesAndPeriod')]
    public function testCountersPreviewAndBuilderCanvas(): void
    {
        $id = self::$popup;
        $visitor = $this->site()->client();
        $visitor->post('/popup', ['id' => $id, 'event' => 'view']);
        $visitor->post('/popup', ['id' => $id, 'event' => 'conversion']);
        $visitor->post('/popup', ['id' => $id, 'event' => 'nothing']);
        $this->assertSame('1/0/1', $this->sql("SELECT CONCAT(impressions, '/', closes, '/', conversions) FROM ka_popups WHERE popup_id = $id"), 'window counters without cookies');

        $this->assertSame(404, $visitor->get("/_popup/$id?build=koncept")->status, 'the window draft does not exist for a visitor');
        $preview = $visitor->get(self::$previewUrl)->body;
        $this->assertStringContainsString('data-open="1"', $preview, 'the signed preview opens the window straight away');
        $this->assertStringContainsString('noindex', $preview, 'the preview is not indexed');

        $this->assertPage("/admin.php?module=popups&action=builder&id=$id", 200, 'id="builder-data"', message: 'window in the builder');
        $this->assertPage("/_popup/$id?build=koncept&editor=1", 200, 'ka-popup--editor', message: 'window canvas in the builder');
        $this->assertStringContainsString("data-popup=\"$id\"", $this->site()->admin()->get('/o-nas')->body, 'the site shows the window to the administrator');
        $this->assertStringNotContainsString('data-popup=', $this->site()->admin()->get('/o-nas?build=koncept&editor=1')->body, 'the page builder canvas is without the site pop-ups');
    }

    #[Depends('testCountersPreviewAndBuilderCanvas')]
    public function testAFormInsideTheWindowSubmitsWithTheWindowAsSource(): void
    {
        $id = self::$popup;
        $html = $this->site()->client()->get('/')->body;
        $html = substr($html, (int) strpos($html, 'data-popup=')); // only the window: the page may have a form of its own
        $source = $this->formField($html, 'source');
        $element = $this->formField($html, 'element');
        $time = $this->formField($html, 'as_time');
        $this->assertSame("popup:$id|ab12cd3", "$source|$element", 'the form in the window has the window as its source');

        sleep(4); // the form asks for 4 seconds between loading and sending
        $location = $this->site()->client()->post('/form', [
            'source' => $source, 'element' => $element, 'back' => '/', 'as_time' => $time, 'as_signature' => $this->formField($html, 'as_signature'), 'p0' => 'okno@example.cz',
        ])->redirect;
        $this->assertStringContainsString('result=ok', $location, 'the form in the window is submitted');
        $this->assertSame("Z okna|popup:$id", $this->sql("SELECT CONCAT(form, '|', source) FROM ka_enquiries WHERE email = 'okno@example.cz'"), 'the enquiry from the window is stored');

        $list = $this->call('seznam_popupu');
        $this->assertSame('Akce okno|1|1', $this->pick($list, 0, 'nazev') . '|' . $this->pick($list, 0, 'zobrazeni') . '|' . $this->pick($list, 0, 'konverze'), 'MCP: list of windows with counters');
        $this->call('uloz_popup', ['id' => $id, 'active' => false]);
        $this->site()->clearPageCache();
    }

    public function testEditingRightOnTheSiteIsOnlyForSignedInUsers(): void
    {
        $this->assertPage('/news/vitejte-v-kalete', 200, 'ka-edit-here', message: 'edit in place: link');
        $this->assertPage('/news/vitejte-v-kalete?edit=text', 200, 'ka-edit-text', message: 'edit in place: form');
        $this->assertPage('/o-nas?edit=text', 200, 'ka-edit-text', message: 'edit a page in place');
        $this->assertStringNotContainsString('ka-edit', $this->site()->client()->get('/news/vitejte-v-kalete?edit=text')->body, 'edit in place is not visible without signing in');
    }
}
