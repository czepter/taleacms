<?php

declare(strict_types=1);

namespace Kaleta\Tests\Site\CollectionsB;

use Kaleta\Tests\Site\Support\SiteTestCase;
use PHPUnit\Framework\Attributes\Group;

/** Was: section 70 (2.12 calls and e-mail clicks counted as conversions, Core\Conversions). */
#[Group('site')]
final class ContactClicksTest extends SiteTestCase
{
    use Helpers;

    /** The beacon: once per visitor (IP and browser), type and page a day. Returns the status code. */
    private function beacon(string $type, string $path, string $browser = 'Mozilla/5.0 test'): int
    {
        return $this->site()->client('beacon')->post('/konverze', ['type' => $type, 'path' => $path], [], $browser)->status;
    }

    /** The statistics feature switch (old stats_feature 0|1); cached pages go with it. */
    private function statsFeature(bool $on): void
    {
        $extensions = array_values(array_filter(explode(',', $this->site()->settingValue('extensions')), fn ($e) => $e !== 'statistika' && $e !== ''));
        if ($on) {
            $extensions[] = 'statistika';
        }
        $this->site()->setting('extensions', implode(',', $extensions));
        $this->site()->clearPageCache();
    }

    public function testClicksAreCountedOncePerVisitorTypeAndPageADay(): void
    {
        $site = $this->site();
        // a page with nothing but a phone number keeps image/web.js while the statistics are on; never for signed-in users
        $site->mcp('vytvor_stranku', ['titulek' => 'Volejte 212', 'zobrazit' => true, 'text' => '<p>Zavolejte: <a href="tel:+420777000212">+420 777 000 212</a></p>']);
        $site->clearPageCache();

        $page = $site->client()->get('/volejte-212');
        $this->assertMatchesRegularExpression('#image/web\.js\?v=[^"]*" defer blocking="render"[^>]* data-konverze="/konverze"></script>#', $page->body, '2.12: a page with only a tel: link keeps web.js with the /konverze endpoint when the statistics are on');
        $this->assertStringNotContainsString('data-konverze', $site->admin()->get('/volejte-212')->body, '2.12: no click counter for signed-in users');

        $this->assertSame(204, $this->beacon('tel', '/volejte-212'), '2.12: a click beacon answers 204');
        $this->beacon('tel', '/volejte-212');                                    // the same visitor again – one call, not two
        $this->beacon('tel', '/volejte-212?utm_source=x#telefon');               // the same page with a query string and a fragment
        $this->beacon('mailto', '/volejte-212');                                 // another type counts on its own
        $this->beacon('tel', '/volejte-212', 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Mobile/15E148'); // another visitor
        $this->beacon('fax', '/volejte-212');                                    // an unknown type
        $this->beacon('tel', '/volejte-212', 'curl/8.0');                        // a bot
        $this->beacon('tel', '/neexistuje-212');                                 // a page the statistics never saw
        $this->beacon('tel', 'volejte-212');                                     // not a path
        $site->admin()->post('/konverze', ['type' => 'whatsapp', 'path' => '/volejte-212']); // signed in – never counted
        $this->assertSame('/volejte-212:mailto:1,/volejte-212:tel:2', $site->value("SELECT GROUP_CONCAT(CONCAT(cesta, ':', typ, ':', pocet) ORDER BY typ) FROM ka_stat_konverze"),
            '2.12: once per visitor, type and page a day; unknown types, bots, made-up pages and signed-in users are not counted');

        $stats = $this->assertPage('/admin.php?module=stats&days=7');
        $this->assertStringContainsString('href="/volejte-212"', $stats->body, '2.12: Statistics list the page');
        $this->assertStringContainsString('<td class="cislo">2 / 1 / 0</td>', $stats->body, '2.12: Statistics show calls, e-mails and WhatsApp per page');
        $this->assertStringContainsString('Kontaktní kliknutí (hovory, e-maily, WhatsApp)', $stats->body, '2.12: Statistics show the contact clicks in total');

        $text = $this->mcpText('get_stats', ['days' => 7]);
        $this->assertStringContainsString('"contact_clicks":{"calls":2,"emails":1,"whatsapp":0,"by_page":[{"path":"/volejte-212","calls":2,"emails":1,"whatsapp":0}]}', $text, '2.12: get_stats carries contact_clicks');
        $this->assertMatchesRegularExpression('#"path":"/volejte-212","views":[0-9]*,"enquiries":0,"signups":0,"calls":2,"emails":1,"whatsapp":0#', $text, '2.12: get_stats carries the clicks of every page');

        // the monthly report mentions calls and e-mails when the month had any
        $month = (new \DateTimeImmutable('first day of last month'))->format('Y-m-d');
        $site->exec("INSERT INTO ka_stat_konverze (den, cesta, typ, pocet) VALUES (?, '/volejte-212', 'tel', 4), (?, '/volejte-212', 'mailto', 2)", [$month, $month]);
        $report = $this->assertPage('/admin.php?module=settings&action=report_preview', 200, 'Hovory – kliknutí na telefonní číslo', message: '2.12: the monthly report mentions the calls and e-mails of the month');
        $this->assertStringContainsString('E-maily – kliknutí na e-mailovou adresu', $report->body, '2.12: the report lists e-mails');
        $this->assertStringNotContainsString('WhatsApp – kliknutí', $report->body, '2.12: the report lists only the kinds of clicks there were');

        // statistics off: no endpoint on the page and no counting
        $this->statsFeature(false);
        $this->assertStringNotContainsString('data-konverze', $site->client()->get('/volejte-212')->body, '2.12: statistics off – the page carries no click endpoint');
        $this->beacon('tel', '/volejte-212', 'Mozilla/5.0 (X11; Linux x86_64) third');
        $today = trim($site->php('echo date("Y-m-d");'));
        $this->assertSame('3', (string) $site->value('SELECT SUM(pocet) FROM ka_stat_konverze WHERE den = ?', [$today]), '2.12: statistics off – a click is not counted');
        $this->statsFeature(true);
    }
}
