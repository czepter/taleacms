<?php

declare(strict_types=1);

namespace Talea\Tests\Site\McpBuilder;

use Talea\Tests\Site\Support\SiteTestCase;
use PHPUnit\Framework\Attributes\Group;

/** Counter, progress, rating, countdown, social links, search, to-top, newsletter, display conditions (was: section 32). */
#[Group('site')]
final class MoreElementsTest extends SiteTestCase
{
    use McpBuilderHelpers;

    public function testElementsDisplayConditionsAndCaching(): void
    {
        $site = $this->site();
        $site->setting('social_instagram', 'https://instagram.com/company');
        $text = $this->rawText('save_build', ['id' => $this->site()->publicId('pages', $this->zPage()), 'publish' => true, 'build' => ['v' => 1, 'children' => [['type' => 'section', 'content' => ['background_video' => 'media/2026/01/background.mp4'], 'children' => [
            ['type' => 'counter', 'content' => ['number' => 1200, 'suffix' => '+']],
            ['type' => 'progress_bars', 'content' => ['items' => [['name' => 'Deadlines', 'value' => 96]]]],
            ['type' => 'rating', 'content' => ['value' => '4.5']],
            ['type' => 'countdown', 'content' => ['target' => '2099-01-01 09:00']],
            ['type' => 'social_links'], ['type' => 'search'], ['type' => 'back_to_top'], ['type' => 'newsletter_signup'],
            ['type' => 'heading', 'content' => ['text' => 'Editors only'], 'conditions' => ['signed_in' => 'yes']],
            ['type' => 'heading', 'content' => ['text' => 'Old event'], 'conditions' => ['to' => '2000-01-01']],
            ['type' => 'video', 'content' => ['url' => 'media/2026/01/film.mp4', 'poster' => 'media/2026/01/poster.jpg']],
        ]]]]]);
        $this->assertStringContainsString('"errors":[]', $text, 'the further elements pass the validator');
        $site->clearPageCache();

        $body = $this->visit('/z-html');
        foreach (['data-counter="1200">1', '<meter min="0" max="100"', 'aria-label="Rated 4.5 out of 5', 'data-countdown="2099-01-01T09:00', 'class="tl-social"', 'aria-label="Instagram"', 'role="search"', 'class="tl-back-to-top"', 'class="tl-newsletter"', 'name="as_signature"', 'class="tl-video-background"', 'poster="/media/2026/01/poster.jpg"', 'image/web.js'] as $pattern) {
            $this->assertStringContainsString($pattern, $body, "further element on the site: $pattern");
        }
        $this->assertStringNotContainsString('Editors only', $body, 'a login condition hides the element from a visitor');
        $this->assertStringNotContainsString('Old event', $body, 'a date condition hides the element after the date');

        $this->visit('/z-html');
        $this->assertSame([], glob($site->path('storage/cache/pages/*.html')) ?: [], 'a page with a display condition is not cached');
        $this->assertStringContainsString('Editors only', $site->admin()->get('/z-html')->body, 'the signed-in person sees the editors-only element');
    }

    public function testNewsletterSignUpConfirmationAndUnsubscribe(): void
    {
        $site = $this->site();
        $page = preg_replace('/\s+/', ' ', $this->visit('/z-html'));
        preg_match('/class="tl-newsletter".*/', $page, $form);
        $form = preg_replace('#</form>.*#', '', $form[0] ?? '');
        preg_match('/name="as_signature" value="([^"]*)"/', $form, $signature);
        preg_match('/name="as_time" value="([^"]*)"/', $form, $time);

        sleep(5); // the form refuses a submit faster than its minimum time (data-wait="4")
        $answer = $this->visitor()->post('/subscribe', ['email' => 'Subscriber@Example.com', 'back' => '/z-html', 'anchor' => 'x', 'as_signature' => $signature[1] ?? '', 'as_time' => $time[1] ?? '', 'website' => '']);
        $this->assertSame(303, $answer->status, 'signing up for the newsletter redirects');
        $this->assertStringEndsWith('/z-html?subscription=ok#x', $answer->redirect, 'signing up for the newsletter');

        $token = (string) $site->value("SELECT token FROM tl_subscribers WHERE email = 'subscriber@example.com' AND status = 0");
        $this->assertPage('/subscribe?confirm=' . $token, 200, 'Confirm subscription', message: 'the link from the e-mail only offers the confirmation');
        $this->assertSame('0', (string) $site->value("SELECT status FROM tl_subscribers WHERE email = 'subscriber@example.com'"), 'opening the link (a mail scanner) does not confirm the subscription');
        $this->assertStringContainsString('Subscription confirmed', $this->visitor()->post('/subscribe?confirm=' . $token)->body, 'confirming with the button');
        $this->assertSame('1', (string) $site->value("SELECT status FROM tl_subscribers WHERE email = 'subscriber@example.com'"), 'the subscriber is confirmed');

        $this->assertPage('/admin.php?module=subscribers', 200, 'subscriber@example.com', message: 'subscribers in the administration');
        $csv = $site->admin()->get('/admin.php?module=subscribers&action=csv')->body;
        $this->assertMatchesRegularExpression('/subscriber@example\.com;.*subscribe\?unsubscribe=' . $token . '/', $csv, 'subscriber export with the unsubscribe link');

        $site->exec("UPDATE tl_settings SET value = REPLACE(value, 'newsletter_signup,', '') WHERE name = 'extensions'");
        $this->assertPage('/subscribe?unsubscribe=' . $token, 200, 'Unsubscribe', message: 'unsubscribing works with the Newsletter feature off');
        $this->assertStringContainsString('Unsubscribed', $this->visitor()->post('/subscribe?unsubscribe=' . $token)->body, 'unsubscribing with the button');
        $site->exec("UPDATE tl_settings SET value = REPLACE(value, 'enquiries,', 'enquiries,newsletter_signup,') WHERE name = 'extensions'");
        $this->assertSame('0', (string) $site->value('SELECT COUNT(*) FROM tl_subscribers'), 'the unsubscribed person is deleted');
    }
}
