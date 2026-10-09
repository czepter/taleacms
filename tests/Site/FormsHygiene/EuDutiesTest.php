<?php

declare(strict_types=1);

namespace Kaleta\Tests\Site\FormsHygiene;

use Kaleta\Tests\Site\Support\SiteTestCase;
use PHPUnit\Framework\Attributes\Group;

/** EU duties as templates – cookie scanner, anonymise, record of processing, accessibility statement, toolbar (was: section 79, "2.14"). */
#[Group('site')]
final class EuDutiesTest extends SiteTestCase
{
    use McpHelpers;

    private static int $page = 0;
    private static int $old = 0;
    private static int $new = 0;

    public function testTheCookieTableListsTheConsentStorageAndTheYouTubeCookies(): void
    {
        self::$page = $this->createPage(['title' => 'Video 2.14', 'slug' => 'video-2-14', 'visible' => true]);
        $this->mcpText('save_build', ['id' => self::$page, 'publish' => true, 'build' => ['v' => 1, 'children' => [['type' => 'section', 'children' => [
            ['type' => 'heading', 'tag' => 'h1', 'content' => ['text' => 'Video']],
            ['type' => 'video', 'content' => ['url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'title' => 'Clip']],
            ['type' => 'form', 'content' => ['name' => 'Servis 2.14', 'fields' => [
                ['label' => 'Jméno a příjmení', 'type' => 'text', 'required' => true], ['label' => 'E-mail', 'type' => 'email', 'required' => true], ['label' => 'Rozsah opravy', 'type' => 'textarea'],
            ]]],
        ]]]]]);
        $this->site()->setting('cookies_mode', 'vestavena');
        $this->adminPost('/admin.php?module=settings&action=cookie_scan', ['tab' => 'cookies'], formPage: '/admin.php?module=settings&tab=cookies');

        $response = $this->assertPage('/admin.php?module=settings&tab=cookies', 200, '<code>kaleta_souhlas</code>', message: '2.14: Settings → Privacy lists the consent storage and the YouTube cookies');

        $this->assertTrue($response->contains('<code>VISITOR_INFO1_LIVE</code></td><td>YouTube</td>'), '2.14: the YouTube embed maps to its cookies');
        $this->assertTrue($response->contains('Skenovat web teď'), '2.14: the scan button is there');
        $this->assertFalse($response->contains('<code>ka-pristupnost</code>'), '2.14: the toolbar storage is listed only when the toolbar is on');
        $this->assertTrue($response->contains('Poslední sken vlastních stránek webu'), "2.14: the scan of the site's own pages ran and is dated");
    }

    public function testTheCookieTablePlaceholderBecomesTheTableOnTheCookiePolicyPage(): void
    {
        $this->createPage(['title' => 'Cookies 2.14', 'slug' => 'cookies-2-14', 'content' => '<p>Co používáme:</p><p>{{cookie_table}}</p>', 'visible' => true]);

        $response = $this->assertPage('/cookies-2-14', 200, '<table class="ka-cookies-tabulka"><thead><tr><th>Název</th><th>Poskytovatel</th><th>Účel</th><th>Doba</th><th>Kategorie</th></tr></thead>',
            message: '2.14: {{cookie_table}} on the cookie policy page becomes the table in the site language');

        $this->assertTrue($response->contains('<td><code>kaleta_souhlas</code></td><td>Kaleta</td>'), "2.14: the visitors' table has Kaleta's consent cookie");
        $this->assertTrue($response->contains('<td><code>YSC</code></td><td>YouTube</td><td>Přehrávač videa: zhlédnutí v rámci relace</td><td>relace</td><td>Marketing</td>'), '2.14: and the YouTube rows translated');
    }

    public function testRetentionWithAnonymiseKeepsTheRowAndBlanksThePerson(): void
    {
        // the choice first – opening Enquiries runs the retention, which would delete the old row under the default
        $this->adminPost('/admin.php?module=enquiries&action=settings', ['mesice' => 24, 'mesice_uchazeci' => 0, 'po_uplynuti' => 'anonymise'], formPage: '/admin.php?module=enquiries');
        $this->site()->exec("INSERT INTO ka_poptavky (datum, formular, stranka, tema, email, data, stav, kategorie) VALUES (NOW() - INTERVAL 30 MONTH, 'Servis 2.14', '/video-2-14', 'Video', 'stary@example.com', ?, 2, 'sales')",
            ['[["Jméno","Starý Zákazník"],["E-mail","stary@example.com"],["Zpráva","Opravte kotel"]]']);
        self::$old = (int) $this->site()->pdo->lastInsertId();

        $response = $this->assertPage('/admin.php?module=enquiries');

        $this->assertSame('Servis 2.14|/video-2-14|Video|sales||[["Jméno",""],["E-mail",""],["Zpráva",""]]|1',
            (string) $this->site()->value("SELECT CONCAT(formular, '|', stranka, '|', tema, '|', kategorie, '|', email, '|', data, '|', anonymizovano IS NOT NULL) FROM ka_poptavky WHERE idp = ?", [self::$old]),
            '2.14: retention with anonymise keeps the row – date, form, page, topic and kind stay, the person is blank');
        $this->assertTrue($response->contains('name="po_uplynuti" value="anonymise" checked'), '2.14: the enquiry settings remember anonymise');
    }

    public function testAnEnquiryCanBeAnonymisedOnItsOwn(): void
    {
        $this->site()->exec("INSERT INTO ka_poptavky (datum, formular, stranka, email, data, stav) VALUES (NOW(), 'Servis 2.14', '/video-2-14', 'novy@example.com', ?, 0)", ['[["Jméno","Nový Zákazník"],["E-mail","novy@example.com"]]']);
        self::$new = (int) $this->site()->pdo->lastInsertId();
        $detail = '/admin.php?module=enquiries&action=detail&id=' . self::$new;

        $this->assertPage($detail, 200, 'action=anonymise', message: '2.14: the enquiry detail offers Anonymise');

        $this->adminPost('/admin.php?module=enquiries&action=anonymise', ['idp' => self::$new], formPage: $detail);
        $this->assertSame('|[["Jméno",""],["E-mail",""]]|1', (string) $this->site()->value("SELECT CONCAT(email, '|', data, '|', anonymizovano IS NOT NULL) FROM ka_poptavky WHERE idp = ?", [self::$new]),
            '2.14: a per-enquiry Anonymise blanks the person and keeps the row');

        $after = $this->assertPage($detail, 200, 'Anonymizováno', message: '2.14: the detail of an anonymised enquiry says so');
        $this->assertFalse($after->contains('action=anonymise'), '2.14: an anonymised enquiry is not anonymised again');
    }

    public function testTheRecordOfProcessingComesFromTheConfiguration(): void
    {
        $text = $this->mcpText('processing_record');
        foreach (['Servis 2.14', 'Jméno a příjmení (text), E-mail (e-mail), Rozsah opravy', '24 m', 'anonymi', 'VISITOR_INFO1_LIVE', 'not legal advice'] as $needle) {
            $this->assertStringContainsString($needle, $text, "MCP: processing_record has «{$needle}»");
        }

        $page = $this->assertPage('/admin.php?module=settings&action=processing_record', 200, 'Servis 2.14', message: '2.14: Settings → Privacy → Record of processing is a page with the sections');
        $this->assertTrue($page->contains('není právní radou'), '2.14: the record says it is a template, not legal advice');
    }

    public function testTheAccessibilityStatementIsGeneratedFromTheAudit(): void
    {
        $statement = $this->site()->mcpResult('accessibility_statement');
        $this->assertSame('EN 301 549 / WCAG 2.1 AA', $statement['standard'] ?? null, 'MCP: accessibility_statement knows the standard');
        $this->assertNull($statement['page'] ?? null, 'MCP: and that no page exists yet');

        $this->adminPost('/admin.php?module=settings&action=accessibility_statement', ['tab' => 'cookies'], formPage: '/admin.php?module=settings&tab=cookies');
        $row = $this->site()->rows("SELECT seo_link, zobrazit, text LIKE '%EN 301 549%' AS standard, text LIKE '%Stav souladu%' AS status FROM ka_stranky WHERE titulek = 'Prohlášení o přístupnosti'");
        $this->assertCount(1, $row, 'one statement page exists');
        $this->assertSame('prohlaseni-o-pristupnosti|0|1|1', implode('|', $row[0]), '2.14: the statement is a hidden draft page in the site language with the standard and the status');

        $settings = $this->site()->admin()->get('/admin.php?module=settings&tab=cookies');
        $this->assertTrue($settings->contains('skrytý koncept') && $settings->contains('Znovu vytvořit koncept z auditu'), '2.14: Settings → Privacy shows the draft and offers to regenerate it');

        $this->adminPost('/admin.php?module=settings&action=accessibility_statement', ['tab' => 'cookies'], formPage: '/admin.php?module=settings&tab=cookies');
        $this->assertSame('1', (string) $this->site()->value("SELECT COUNT(*) FROM ka_stranky WHERE titulek = 'Prohlášení o přístupnosti' AND smazano IS NULL"), '2.14: regenerating updates the same draft page');

        $again = $this->site()->mcpResult('accessibility_statement');
        $this->assertSame('prohlaseni-o-pristupnosti', $again['page']['slug'] ?? null, 'MCP: accessibility_statement sees the hidden page');
        $this->assertFalse($again['page']['published'] ?? null, 'MCP: and that it is not published');
    }

    public function testTheAccessibilityToolbarIsThereOnlyWhenSwitchedOn(): void
    {
        $this->site()->clearPageCache();
        $off = $this->assertPage('/video-2-14', 200, 'Video', message: '2.14: without the setting the site has no accessibility toolbar');
        $this->assertFalse($off->contains('data-pristupnost'), '2.14: the toolbar markup is absent while off');

        $this->site()->setting('accessibility_toolbar', '1');
        $this->site()->clearPageCache();
        $on = $this->assertPage('/video-2-14', 200, 'data-pristupnost-volba="kontrast" aria-pressed="false">Vysoký kontrast</button>', message: '2.14: with the setting on, the toolbar is on the page with translated options');
        $this->assertTrue($on->contains('aria-label="Možnosti přístupnosti"'), '2.14: the toolbar is labelled for screen readers');
        $this->assertTrue($on->contains('localStorage.getItem(KEY)'), '2.14: and remembers the choice in localStorage');

        $this->site()->setting('accessibility_toolbar', '0');
        $this->site()->clearPageCache();
        $this->mcpText('trash_page', ['id' => self::$page]);
    }
}
