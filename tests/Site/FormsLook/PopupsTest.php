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
        $this->assertPage('/admin.php?module=popups&action=new', 200, 'name="vzor" value="newsletter"', message: 'a new window from a template');
    }

    #[Depends('testAdminOffersNoWindowsYetAndNewOnesFromATemplate')]
    public function testAWindowStartsSwitchedOffAndCannotBeSwitchedOnUnpublished(): void
    {
        $created = $this->call('uloz_popup', ['vzor' => 'prazdny', 'nazev' => 'Akce okno']);
        self::$popup = (int) $this->pick($created, 'id');
        $this->assertSame('akce-okno|||klik', implode('|', [$this->pick($created, 'adresa'), $this->pick($created, 'aktivni'), $this->pick($created, 'publikovano'), $this->pick($created, 'spoustec')]), 'MCP: the window is created off and unpublished');
        $this->assertStringContainsString('nejdřív publikuj', $this->raw('uloz_popup', ['id' => self::$popup, 'aktivni' => true]), 'MCP: an unpublished window cannot be switched on');
    }

    #[Depends('testAWindowStartsSwitchedOffAndCannotBeSwitchedOnUnpublished')]
    public function testPublishedWindowIsOnTheSiteWithTriggerAndBrowserRules(): void
    {
        $this->call('stavba_uloz', ['popup' => self::$popup, 'publikovat' => true, 'stavba' => ['v' => 1, 'deti' => [
            ['typ' => 'nadpis', 'znacka' => 'h2', 'obsah' => ['text' => 'Okno akce']],
            ['id' => 'ab12cd3', 'typ' => 'formular', 'obsah' => ['nazev' => 'Z okna', 'pole' => [['popisek' => 'E-mail', 'typ' => 'email', 'povinne' => true]]]],
        ]]]);
        $saved = $this->call('save_popup', ['id' => self::$popup, 'type' => 'slide_in', 'trigger' => 'time', 'value' => 3, 'frequency' => 'until_closed', 'rules' => ['device' => 'phone'], 'active' => true]);
        $this->assertSame('slide_in|time|until_closed|phone|1', implode('|', [$this->pick($saved, 'type'), $this->pick($saved, 'trigger'), $this->pick($saved, 'frequency'), $this->pick($saved, 'rules', 'device'), $this->pick($saved, 'active')]), 'MCP in English: type, trigger, frequency and rules');

        $this->site()->clearPageCache();
        $body = $this->site()->client()->get('/')->body;
        $id = self::$popup;
        $this->assertStringContainsString("data-popup=\"$id\"", $body, 'the switched-on window is on the site');
        $this->assertStringContainsString('class="ka-popup ka-popup--panel" popover="manual" role="region"', $body, 'panel window uses the Popover API');
        $this->assertStringContainsString('data-spoustec="cas" data-hodnota="3" data-cetnost="zavreni"', $body, 'trigger and frequency are in the markup');
        $this->assertStringContainsString('data-zarizeni="telefon"', $body, 'the device rule is in the markup');
        $this->assertStringContainsString('Okno akce', $body, 'the window content is in the page');
        $this->assertStringContainsString('image/web.js', $body, 'the script is loaded');
    }

    #[Depends('testPublishedWindowIsOnTheSiteWithTriggerAndBrowserRules')]
    public function testServerRulesPlacesAndPeriod(): void
    {
        $id = self::$popup;
        $about = (int) $this->sql("SELECT ids FROM ka_stranky WHERE seo_link = 'o-nas'");
        $this->call('uloz_popup', ['id' => $id, 'pravidla' => ['kde' => 'vybrane', 'stranky' => [$about]]]);
        $this->site()->clearPageCache();
        $visitor = $this->site()->client();
        $this->assertStringNotContainsString("data-popup=\"$id\"", $visitor->get('/')->body, 'the window is not on the home page');
        $this->assertStringContainsString("data-popup=\"$id\"", $visitor->get('/o-nas')->body, 'the window is on the selected page');

        $this->call('uloz_popup', ['id' => $id, 'pravidla' => ['od' => '2099-01-01']]);
        $this->site()->clearPageCache();
        $this->assertStringNotContainsString("data-popup=\"$id\"", $visitor->get('/o-nas')->body, 'a window out of its period is not put into the page');

        $saved = $this->call('uloz_popup', ['id' => $id, 'pravidla' => ['od' => '', 'kde' => 'vse']]);
        self::$previewUrl = $this->pick($saved, 'nahled');
        $this->site()->clearPageCache();
    }

    #[Depends('testServerRulesPlacesAndPeriod')]
    public function testCountersPreviewAndBuilderCanvas(): void
    {
        $id = self::$popup;
        $visitor = $this->site()->client();
        $visitor->post('/popup', ['id' => $id, 'udalost' => 'zobrazeni']);
        $visitor->post('/popup', ['id' => $id, 'udalost' => 'konverze']);
        $visitor->post('/popup', ['id' => $id, 'udalost' => 'nic']);
        $this->assertSame('1/0/1', $this->sql("SELECT CONCAT(zobrazeni, '/', zavreni, '/', konverze) FROM ka_popupy WHERE idpp = $id"), 'window counters without cookies');

        $this->assertSame(404, $visitor->get("/_popup/$id?build=koncept")->status, 'the window draft does not exist for a visitor');
        $preview = $visitor->get(self::$previewUrl)->body;
        $this->assertStringContainsString('data-otevrit="1"', $preview, 'the signed preview opens the window straight away');
        $this->assertStringContainsString('noindex', $preview, 'the preview is not indexed');

        $this->assertPage("/admin.php?module=popups&action=builder&id=$id", 200, 'id="stavitel-data"', message: 'window in the builder');
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
        $source = $this->formField($html, 'zdroj');
        $element = $this->formField($html, 'prvek');
        $time = $this->formField($html, 'as_cas');
        $this->assertSame("popup:$id|ab12cd3", "$source|$element", 'the form in the window has the window as its source');

        sleep(4); // the form asks for 4 seconds between loading and sending
        $location = $this->site()->client()->post('/formular', [
            'zdroj' => $source, 'prvek' => $element, 'zpet' => '/', 'as_cas' => $time, 'as_podpis' => $this->formField($html, 'as_podpis'), 'p0' => 'okno@example.cz',
        ])->redirect;
        $this->assertStringContainsString('result=ok', $location, 'the form in the window is submitted');
        $this->assertSame("Z okna|popup:$id", $this->sql("SELECT CONCAT(formular, '|', zdroj) FROM ka_poptavky WHERE email = 'okno@example.cz'"), 'the enquiry from the window is stored');

        $list = $this->call('seznam_popupu');
        $this->assertSame('Akce okno|1|1', $this->pick($list, 0, 'nazev') . '|' . $this->pick($list, 0, 'zobrazeni') . '|' . $this->pick($list, 0, 'konverze'), 'MCP: list of windows with counters');
        $this->call('uloz_popup', ['id' => $id, 'aktivni' => false]);
        $this->site()->clearPageCache();
    }

    public function testEditingRightOnTheSiteIsOnlyForSignedInUsers(): void
    {
        $this->assertPage('/novinky/vitejte-v-kalete', 200, 'ka-upravit-zde', message: 'edit in place: link');
        $this->assertPage('/novinky/vitejte-v-kalete?edit=text', 200, 'ka-upravit-text', message: 'edit in place: form');
        $this->assertPage('/o-nas?edit=text', 200, 'ka-upravit-text', message: 'edit a page in place');
        $this->assertStringNotContainsString('ka-upravit', $this->site()->client()->get('/novinky/vitejte-v-kalete?edit=text')->body, 'edit in place is not visible without signing in');
    }
}
