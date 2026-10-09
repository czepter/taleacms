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
        $text = $this->rawText('stavba_uloz', ['id' => $this->zPage(), 'publikovat' => true, 'build' => ['v' => 1, 'children' => [['type' => 'section', 'content' => ['background_video' => 'media/2026/01/pozadi.mp4'], 'children' => [
            ['type' => 'counter', 'content' => ['number' => 1200, 'suffix' => '+']],
            ['type' => 'progress_bars', 'content' => ['items' => [['name' => 'Termíny', 'value' => 96]]]],
            ['type' => 'rating', 'content' => ['value' => '4,5']],
            ['type' => 'countdown', 'content' => ['target' => '2099-01-01 09:00']],
            ['type' => 'social_links'], ['type' => 'search'], ['type' => 'back_to_top'], ['type' => 'newsletter_signup'],
            ['type' => 'heading', 'content' => ['text' => 'Jen pro redakci'], 'conditions' => ['signed_in' => 'yes']],
            ['type' => 'heading', 'content' => ['text' => 'Stará akce'], 'conditions' => ['to' => '2000-01-01']],
            ['type' => 'video', 'content' => ['url' => 'media/2026/01/film.mp4', 'poster' => 'media/2026/01/plakat.jpg']],
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
        $answer = $this->visitor()->post('/odber', ['email' => 'Odber@Example.cz', 'zpet' => '/z-html', 'anchor' => 'x', 'as_podpis' => $signature[1] ?? '', 'as_cas' => $time[1] ?? '', 'web_adresa' => '']);
        $this->assertSame(303, $answer->status, 'signing up for the newsletter redirects');
        $this->assertStringEndsWith('/z-html?subscription=ok#x', $answer->redirect, 'signing up for the newsletter');

        $token = (string) $site->value("SELECT token FROM ka_subscribers WHERE email = 'odber@example.cz' AND status = 0");
        $this->assertPage('/odber?confirm=' . $token, 200, 'Potvrdit odběr', message: 'the link from the e-mail only offers the confirmation');
        $this->assertSame('0', (string) $site->value("SELECT status FROM ka_subscribers WHERE email = 'odber@example.cz'"), 'opening the link (a mail scanner) does not confirm the subscription');
        $this->assertStringContainsString('Odběr je potvrzený', $this->visitor()->post('/odber?confirm=' . $token)->body, 'confirming with the button');
        $this->assertSame('1', (string) $site->value("SELECT status FROM ka_subscribers WHERE email = 'odber@example.cz'"), 'the subscriber is confirmed');

        $this->assertPage('/admin.php?module=subscribers', 200, 'odber@example.cz', message: 'subscribers in the administration');
        $csv = $site->admin()->get('/admin.php?module=subscribers&action=csv')->body;
        $this->assertMatchesRegularExpression('/odber@example\.cz;.*odber\?unsubscribe=' . $token . '/', $csv, 'subscriber export with the unsubscribe link');

        $site->exec("UPDATE ka_settings SET value = REPLACE(value, 'newsletter_signup,', '') WHERE name = 'extensions'");
        $this->assertPage('/odber?unsubscribe=' . $token, 200, 'Odhlásit odběr', message: 'unsubscribing works with the Newsletter feature off');
        $this->assertStringContainsString('Odhlášeno', $this->visitor()->post('/odber?unsubscribe=' . $token)->body, 'unsubscribing with the button');
        $site->exec("UPDATE ka_settings SET value = REPLACE(value, 'poptavky,', 'poptavky,newsletter_signup,') WHERE name = 'extensions'");
        $this->assertSame('0', (string) $site->value('SELECT COUNT(*) FROM ka_subscribers'), 'the unsubscribed person is deleted');
    }
}
