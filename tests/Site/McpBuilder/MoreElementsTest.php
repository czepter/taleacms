<?php

declare(strict_types=1);

namespace Kaleta\Tests\Site\McpBuilder;

use Kaleta\Tests\Site\Support\SiteTestCase;
use PHPUnit\Framework\Attributes\Group;

/** Counter, progress, rating, countdown, social links, search, to-top, newsletter, display conditions (was: section 32). */
#[Group('site')]
final class MoreElementsTest extends SiteTestCase
{
    use McpBuilderHelpers;

    public function testElementsDisplayConditionsAndCaching(): void
    {
        $site = $this->site();
        $site->setting('social_instagram', 'https://instagram.com/firma');
        $text = $this->rawText('stavba_uloz', ['id' => $this->zPage(), 'publikovat' => true, 'stavba' => ['v' => 1, 'deti' => [['typ' => 'sekce', 'obsah' => ['video' => 'media/2026/01/pozadi.mp4'], 'deti' => [
            ['typ' => 'pocitadlo', 'obsah' => ['cislo' => 1200, 'za' => '+']],
            ['typ' => 'prubeh', 'obsah' => ['polozky' => [['nazev' => 'Termíny', 'hodnota' => 96]]]],
            ['typ' => 'hodnoceni', 'obsah' => ['hodnota' => '4,5']],
            ['typ' => 'odpocet', 'obsah' => ['cil' => '2099-01-01 09:00']],
            ['typ' => 'socialni'], ['typ' => 'hledani'], ['typ' => 'nahoru'], ['typ' => 'newsletter'],
            ['typ' => 'nadpis', 'obsah' => ['text' => 'Jen pro redakci'], 'podminky' => ['prihlaseni' => 'ano']],
            ['typ' => 'nadpis', 'obsah' => ['text' => 'Stará akce'], 'podminky' => ['do' => '2000-01-01']],
            ['typ' => 'video', 'obsah' => ['url' => 'media/2026/01/film.mp4', 'plakat' => 'media/2026/01/plakat.jpg']],
        ]]]]]);
        $this->assertStringContainsString('"chyby":[]', $text, 'the further elements pass the validator');
        $site->clearPageCache();

        $body = $this->visit('/z-html');
        foreach (['data-pocitadlo="1200">1', '<meter min="0" max="100"', 'aria-label="Hodnocení 4,5 z 5', 'data-odpocet="2099-01-01T09:00', 'class="ka-socialni"', 'aria-label="Instagram"', 'role="search"', 'class="ka-nahoru"', 'class="ka-newsletter"', 'name="as_podpis"', 'class="ka-video-pozadi"', 'poster="/media/2026/01/plakat.jpg"', 'image/web.js'] as $pattern) {
            $this->assertStringContainsString($pattern, $body, "further element on the site: $pattern");
        }
        $this->assertStringNotContainsString('Jen pro redakci', $body, 'a login condition hides the element from a visitor');
        $this->assertStringNotContainsString('Stará akce', $body, 'a date condition hides the element after the date');

        $this->visit('/z-html');
        $this->assertSame([], glob($site->path('storage/cache/stranky/*.html')) ?: [], 'a page with a display condition is not cached');
        $this->assertStringContainsString('Jen pro redakci', $site->admin()->get('/z-html')->body, 'the signed-in person sees the editors-only element');
    }

    public function testNewsletterSignUpConfirmationAndUnsubscribe(): void
    {
        $site = $this->site();
        $page = preg_replace('/\s+/', ' ', $this->visit('/z-html'));
        preg_match('/class="ka-newsletter".*/', $page, $form);
        $form = preg_replace('#</form>.*#', '', $form[0] ?? '');
        preg_match('/name="as_podpis" value="([^"]*)"/', $form, $signature);
        preg_match('/name="as_cas" value="([^"]*)"/', $form, $time);

        sleep(5); // the form refuses a submit faster than its minimum time (data-cekat="4")
        $answer = $this->visitor()->post('/odber', ['email' => 'Odber@Example.cz', 'zpet' => '/z-html', 'kotva' => 'x', 'as_podpis' => $signature[1] ?? '', 'as_cas' => $time[1] ?? '', 'web_adresa' => '']);
        $this->assertSame(303, $answer->status, 'signing up for the newsletter redirects');
        $this->assertStringEndsWith('/z-html?subscription=ok#x', $answer->redirect, 'signing up for the newsletter');

        $token = (string) $site->value("SELECT token FROM ka_odberatele WHERE email = 'odber@example.cz' AND stav = 0");
        $this->assertPage('/odber?confirm=' . $token, 200, 'Potvrdit odběr', message: 'the link from the e-mail only offers the confirmation');
        $this->assertSame('0', (string) $site->value("SELECT stav FROM ka_odberatele WHERE email = 'odber@example.cz'"), 'opening the link (a mail scanner) does not confirm the subscription');
        $this->assertStringContainsString('Odběr je potvrzený', $this->visitor()->post('/odber?confirm=' . $token)->body, 'confirming with the button');
        $this->assertSame('1', (string) $site->value("SELECT stav FROM ka_odberatele WHERE email = 'odber@example.cz'"), 'the subscriber is confirmed');

        $this->assertPage('/admin.php?module=subscribers', 200, 'odber@example.cz', message: 'subscribers in the administration');
        $csv = $site->admin()->get('/admin.php?module=subscribers&action=csv')->body;
        $this->assertMatchesRegularExpression('/odber@example\.cz;.*odber\?unsubscribe=' . $token . '/', $csv, 'subscriber export with the unsubscribe link');

        $site->exec("UPDATE ka_nastaveni SET hodnota = REPLACE(hodnota, 'newsletter,', '') WHERE promenna = 'extensions'");
        $this->assertPage('/odber?unsubscribe=' . $token, 200, 'Odhlásit odběr', message: 'unsubscribing works with the Newsletter feature off');
        $this->assertStringContainsString('Odhlášeno', $this->visitor()->post('/odber?unsubscribe=' . $token)->body, 'unsubscribing with the button');
        $site->exec("UPDATE ka_nastaveni SET hodnota = REPLACE(hodnota, 'poptavky,', 'poptavky,newsletter,') WHERE promenna = 'extensions'");
        $this->assertSame('0', (string) $site->value('SELECT COUNT(*) FROM ka_odberatele'), 'the unsubscribed person is deleted');
    }
}
