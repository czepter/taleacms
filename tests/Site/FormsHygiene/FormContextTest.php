<?php

declare(strict_types=1);

namespace Kaleta\Tests\Site\FormsHygiene;

use Kaleta\Tests\Site\Support\SiteTestCase;
use PHPUnit\Framework\Attributes\Group;

/** Forms that know where they are, thank-you with next steps (was: section 72). The events calendar of section 57 is recreated here. */
#[Group('site')]
final class FormContextTest extends SiteTestCase
{
    use McpHelpers;

    private static int $eventsCollection = 0;
    private static int $page = 0;
    private static int $enquiry = 0;
    /** @var array<string, string> */
    private static array $eventForm = [];
    /** @var array<string, string> */
    private static array $pageForm = [];

    public function testARegistrationFromAnEventPageRecordsTheCalendarAndTheEvent(): void
    {
        // section 57: an events calendar with one event on which the registration form sits
        $this->mcpText('create_collection', ['name' => 'Akce test', 'preset' => 'events']);
        self::$eventsCollection = (int) $this->site()->value("SELECT idk FROM ka_kolekce WHERE seo_link = 'akce-test' AND preset = 'events'");
        $this->assertGreaterThan(0, self::$eventsCollection, 'the events preset creates the calendar');
        $tomorrow = date('Y-m-d', strtotime('+2 days'));
        $this->mcpText('save_collection_item', ['collection' => 'akce-test', 'name' => 'Jóga, pro začátečníky', 'slug' => 'joga',
            'values' => ['start' => "$tomorrow 18:00", 'end' => "$tomorrow 19:30", 'venue' => 'Sál', 'address' => 'Hlavní 1, Brno', 'capacity' => '1', 'summary' => 'Přineste si podložku.'], 'visible' => true]);

        // section 72: a form on an ordinary page with the next steps
        self::$page = $this->createPage(['title' => 'Koupelny F7', 'visible' => true]);
        $this->mcpText('stavba_uloz', ['id' => self::$page, 'publikovat' => true, 'stavba' => ['v' => 1, 'deti' => [['typ' => 'sekce', 'deti' => [['typ' => 'formular', 'obsah' => [
            'nazev' => 'Poptavka F7', 'pole' => [['popisek' => 'Email', 'typ' => 'email', 'povinne' => true]],
            'dalsi_kroky' => "Zavoláme vám\nPřijedeme na zaměření", 'odpovime_do' => 4, 'odpovida' => 'Jana z kanceláře']]]]]]]);
        $this->site()->exec("DELETE FROM ka_kontrola_ip WHERE typ = 'formular'");
        $this->site()->clearPageCache();

        $visitor = $this->site()->client();
        foreach (['eventForm' => '/akce-test/joga', 'pageForm' => '/koupelny-f7'] as $property => $path) {
            $form = $visitor->get($path);
            self::${$property} = ['zdroj' => $form->field('zdroj'), 'prvek' => $form->field('prvek'), 'as_cas' => $form->field('as_cas'), 'as_podpis' => $form->field('as_podpis')];
        }
        sleep(4); // the antispam does not accept a form sent sooner than four seconds

        $registration = $visitor->post('/formular', self::$eventForm + ['zpet' => '/akce-test/joga', 'p0' => 'Eva', 'p1' => 'eva@example.cz', 'p4' => '1']);
        $this->assertStringContainsString('result=ok', $registration->redirect, 'the registration was accepted');
        $this->assertSame('Akce test – Jóga, pro začátečníky', (string) $this->site()->value('SELECT tema FROM ka_poptavky WHERE zdroj = ? ORDER BY idp LIMIT 1', ['kolekce:' . self::$eventsCollection]),
            "topic: a registration from an event's page records the calendar and the event");
    }

    public function testTheFormKeepsTheNextStepsTheWorkingHoursAndWhoReplies(): void
    {
        $this->assertSame('1|1|1', (string) $this->site()->value("SELECT CONCAT(stavba LIKE '%\"dalsi_kroky\":\"Zavol%', '|', stavba LIKE '%\"odpovime_do\":4%', '|', stavba LIKE '%\"odpovida\":\"Jana z kancel%') FROM ka_stranky WHERE ids = ?", [self::$page]),
            'next steps: the form keeps the steps, the working hours and who replies');
    }

    public function testTheTopicOnAPageIsThePageTitleWhateverWasPosted(): void
    {
        $this->site()->exec("DELETE FROM ka_kontrola_ip WHERE typ = 'formular'");
        $result = $this->site()->client()->post('/formular', self::$pageForm + ['zpet' => '/koupelny-f7', 'p0' => 'f7@example.cz', 'tema' => 'Podvrh', 'about' => 'Podvrh']);
        $this->assertStringContainsString('result=ok', $result->redirect, 'topic: the form on the page was sent');

        self::$enquiry = (int) $this->site()->value("SELECT MAX(idp) FROM ka_poptavky WHERE zdroj = ?", ['stranka:' . self::$page]);
        $this->assertSame('Koupelny F7', (string) $this->site()->value('SELECT tema FROM ka_poptavky WHERE idp = ?', [self::$enquiry]), 'topic: on a page the topic is the page title – what was posted for it is ignored');
    }

    public function testTheTopicIsShownInTheEnquiriesAdminAndOverMcp(): void
    {
        $this->assertPage('/admin.php?module=enquiries', 200, 'Téma: <a href="/koupelny-f7"', message: 'topic: the Enquiries list shows it with a link to the page');
        $this->assertPage('/admin.php?module=enquiries&action=detail&id=' . self::$enquiry, 200, '<dt>Téma</dt><dd><a href="/koupelny-f7"', message: 'topic: the enquiry detail shows it');

        $list = $this->site()->mcpResult('list_enquiries', ['limit' => 1]);
        $this->assertSame('Koupelny F7', $list[0]['about'] ?? null, 'MCP: list_enquiries has about');
    }

    public function testTheThankYouListsTheStepsTheDeadlineAndWhoReplies(): void
    {
        $response = $this->assertPage('/koupelny-f7?form=' . self::$pageForm['prvek'] . '&result=ok', 200,
            '<ol class="ka-kroky"><li>Zavoláme vám</li><li>Přijedeme na zaměření</li></ol>', message: 'next steps: the thank-you lists the steps');

        $this->assertTrue($response->matches('#class="ka-kroky-termin">Odpovíme .* do [0-9]*:[0-9][0-9]\.</p>#'), 'next steps: the thank-you says by when');
        $this->assertTrue($response->contains('<p class="ka-kroky-kdo">Jana z kanceláře vám odpoví.</p>'), 'next steps: the thank-you says who replies');
    }
}
