<?php

declare(strict_types=1);

namespace Kaleta\Tests\Site\JobsFleetFacts;

use Kaleta\Tests\Site\Support\SiteTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Was: sections 51 "2.10 business facts", 52 "computed facts and sourced proof numbers", 53 "opening hours with exceptions" and
 * 54 "links between collections, people" of tools/test.sh. 52 builds on the facts and pages of 51, so they stay in one class.
 */
#[Group('site')]
final class FactsTest extends SiteTestCase
{
    use Helpers;

    private static int $teamIdk = 0;
    private static int $person = 0;
    private static string $team = '';

    private function page(string $path): string
    {
        $this->site()->clearPageCache();

        return $this->site()->client()->get($path)->body;
    }

    // ---- 51 business facts

    public function testClaudeCreatesAFactAndAWrongTypeIsRefused(): void
    {
        $this->assertStringContainsString('fact.projects', $this->mcpText('save_fact', ['key' => 'projects', 'label' => 'Projects', 'type' => 'number', 'value' => '1500']), 'facts: Claude creates a fact');
        $this->mcpText('save_fact', ['key' => 'founded', 'label' => 'Founded', 'type' => 'year', 'value' => '2004', 'schema_property' => 'foundingDate']);
        $this->assertStringContainsString('does not fit the type', $this->mcpText('save_fact', ['key' => 'founded', 'value' => 'long ago']), 'facts: a value that does not fit the type is refused');
    }

    public function testTokensAreFilledInOnThePageAndInStructuredData(): void
    {
        $this->mcpText('create_page', ['title' => 'Fakta test', 'slug' => 'fakta-test', 'visible' => true, 'text' => '<p>Máme za sebou {{fact.projects}} zakázek od roku {{ fact.founded }}.</p><p>Loni jsme dokončili 1500 zakázek.</p><p>{{fact.neexistuje}}</p>']);
        $body = $this->page('/fakta-test');
        $this->assertMatchesRegularExpression('/1.?500 zakázek od roku 2004/u', $body, 'facts: tokens filled in on the page (number with the thousands separator)');
        $this->assertStringNotContainsString('{{', $body, 'facts: no token is left unfilled');
        $this->assertStringContainsString('"foundingDate":"2004"', $body, 'facts: a fact with a schema property is in the structured data');

        $this->mcpText('create_page', ['title' => 'Fakta dokumentace', 'slug' => 'fakta-dokumentace', 'visible' => true, 'text' => '<p>Napište <code>{{fact.projects}}</code> do textu.</p>']);
        $this->assertStringContainsString('<code>{{fact.projects}}</code>', $this->page('/fakta-dokumentace'), 'facts: a token inside <code> stays as written (documentation)');
    }

    public function testATextFactWithAScriptSchemeIsNeverALink(): void
    {
        $site = $this->site();
        // 3.3.2 (N26) / 3.3.3 (N50): saving such a fact is refused; one stored before (here straight in the database) is still caught when filled
        $this->assertStringContainsString('cannot begin with an address scheme', $this->mcpText('save_fact', ['key' => 'promo_link', 'label' => 'Promo', 'type' => 'text', 'value' => 'javascript:alert(document.domain)']), 'facts: save_fact refuses a text fact "javascript:…"');
        $site->exec("INSERT INTO ka_facts (fact_key, language, label, type, value, updated_at) VALUES ('promo_link', '', 'Promo', 'text', 'javascript:alert(document.domain)', NOW())");
        $this->mcpText('create_page', ['title' => 'Fact link', 'slug' => 'fact-link', 'visible' => true, 'text' => '<p><a href="{{fact.promo_link}}">Promo</a></p>']);
        $body = $this->page('/fact-link');
        $this->assertStringNotContainsStringIgnoringCase('href="javascript:', $body, 'facts: no javascript: link');
        $this->assertStringContainsString('href="#">Promo</a>', $body, 'facts: a text fact "javascript:…" in a link becomes a link to #');
        $this->mcpText('trash_page', ['id' => (int) $site->value("SELECT page_id FROM ka_pages WHERE slug = 'fact-link'")]);
        $site->exec("DELETE FROM ka_facts WHERE fact_key = 'promo_link'");
    }

    public function testLlmsTxtListsTheFactsAndAChangeFindsTheOldSentences(): void
    {
        $this->assertMatchesRegularExpression('/^- Projects: 1.?500$/mu', $this->site()->client()->get('/llms.txt')->body, 'facts: llms.txt lists the facts');
        $this->assertStringContainsString('Loni jsme dokon', $this->mcpText('save_fact', ['key' => 'projects', 'value' => '1600']), 'facts: a change lists the sentences that still state the old value');
        $this->assertMatchesRegularExpression('/1.?600 zakázek/u', $this->page('/fakta-test'), 'facts: the page says the new value at once (the cache is cleared)');
    }

    public function testClaimsAuditAndFactList(): void
    {
        $this->assertStringContainsString('Loni jsme dokon', $this->mcpText('find_claims'), 'claims inventory: sentences with numbers written as plain text');
        $this->assertStringContainsString('fact.neexistuje', $this->mcpText('site_audit', ['kind' => 'fact']), 'site audit: a token of a fact that does not exist');
        $list = $this->mcpText('list_facts');
        $this->assertStringContainsString('company_phone', $list, 'MCP list_facts: built-in facts');
        $this->assertStringContainsString('used_in', $list, 'MCP list_facts: their use');
    }

    public function testFactsInTheAdministration(): void
    {
        $admin = $this->site()->admin();
        $list = $this->assertPage('/admin.php?module=facts', 200, message: 'facts: the admin list');
        $this->assertStringContainsString('fact.projects', $list->body, 'facts: the list shows the token');
        $this->assertStringContainsString('Fakta test', $admin->get('/admin.php?module=facts&action=edit&key=projects')->body, 'facts: the fact shows where it is used');
        $this->adminPost('/admin.php?module=facts&action=save', ['key' => 'projects', 'label' => 'Projects', 'type' => 'number', 'value' => '1700'], '/admin.php?module=facts');
        $this->assertStringContainsString('starou hodnotu', $admin->get('/admin.php?module=facts&action=edit&key=projects')->body, 'facts: after a change in the admin it says whether the old value is still stated');
        $this->assertStringContainsString('Loni jsme dokon', $admin->get('/admin.php?module=facts&action=claims')->body, 'facts: the claims inventory in the admin');
    }

    // ---- 52 computed facts and sourced proof numbers

    public function testComputedFactsOnAPage(): void
    {
        $site = $this->site();
        // the old run had the team collection from an earlier section
        $this->assertStringContainsString('redirect_hidden_to', $this->mcpText('create_collection', ['name' => 'Tým', 'preset' => 'people']), 'the team collection exists');
        $years = (int) date('Y') - 2004;
        $team = $site->value("SELECT COUNT(*) FROM ka_collection_items p JOIN ka_collections k ON k.collection_id = p.collection_id WHERE k.slug = 'tym' AND p.visible = 1 AND p.deleted_at IS NULL AND p.language = ''");
        $this->mcpText('create_page', ['title' => 'Pocitane test', 'slug' => 'pocitane-test', 'visible' => true, 'text' => '<p>Roky: {{years_since:2004}} / {{ years_since:fact.founded }}. Tým: {{count:tym}}. Novinky: {{count:news}}. Vadné: {{count:neexistuje}}|{{years_since:brzy}}.</p>']);
        $body = $this->site()->client()->get('/pocitane-test')->body;

        $this->assertStringContainsString("Roky: $years / $years. Tým: $team.", $body, 'computed facts: years since a year and a fact, the count of a collection');
        $this->assertMatchesRegularExpression('/Novinky: [0-9]+\./', $body, 'computed facts: the count of news');
        $this->assertStringContainsString('Vadné: |.', $body, 'computed facts: a bad token is empty');
        $this->assertStringNotContainsString('{{', $body, 'computed facts: no token is left');

        $audit = $this->mcpText('site_audit', ['kind' => 'fact']);
        $this->assertStringContainsString('count:neexistuje', $audit, 'site audit: a computed token that cannot be computed (count)');
        $this->assertStringContainsString('years_since:brzy', $audit, 'site audit: a computed token that cannot be computed (years)');
        $this->assertStringContainsString('cannot be computed', $audit, 'site audit: the reason');
        $this->assertStringNotContainsString('Roky:', $this->mcpText('find_claims'), 'claims inventory: a sentence with a computed token is not a claim');
    }

    public function testCounterTakesAFactAndTheAuditFlagsTypedDigits(): void
    {
        $site = $this->site();
        $years = (int) date('Y') - 2004;
        $page = (int) $site->value("SELECT page_id FROM ka_pages WHERE slug = 'pocitane-test'");
        $this->mcpText('save_build', ['id' => $page, 'publish' => true, 'build' => ['v' => 1, 'children' => [['type' => 'section', 'children' => [
            ['id' => 'cnt1', 'type' => 'counter', 'content' => ['number' => '1500', 'suffix' => '+', 'caption' => 'zakázek']],
            ['id' => 'cnt2', 'type' => 'counter', 'content' => ['number' => '{{fact.projects}}', 'suffix' => '', 'caption' => 'zakázek z faktu']],
            ['id' => 'cnt3', 'type' => 'counter', 'content' => ['number' => '{{years_since:fact.founded}}', 'suffix' => ' let', 'caption' => 'na trhu']],
        ]]]]]);
        $body = $this->page('/pocitane-test');
        $this->assertMatchesRegularExpression('/data-pocitadlo="1700">1.?700</u', $body, 'counter: a fact as the number');
        $this->assertStringContainsString("data-pocitadlo=\"$years\">$years<", $body, 'counter: a computed token as the number');
        $this->assertStringContainsString('data-pocitadlo="1500"', $body, 'counter: the typed number stays');
        $this->assertStringNotContainsString('{{', $body, 'counter: filled in for visitors with the count-up');

        $audit = $this->mcpText('site_audit', ['kind' => 'fact']);
        $this->assertStringContainsString('The number 1500 is typed in', $audit, 'site audit: a proof number typed in as digits is reported');
        $this->assertStringContainsString('cnt1', $audit, 'site audit: with its element');
        $this->assertStringNotContainsString('cnt2', $audit, 'site audit: a fact token is not reported');
        $this->assertStringNotContainsString('cnt3', $audit, 'site audit: a computed token is not reported');
    }

    public function testComputedTokensAreListedAndDeletingAFactShowsWhereItWasUsed(): void
    {
        $site = $this->site();
        $first = (string) $site->value('SELECT slug FROM ka_collections ORDER BY collection_id LIMIT 1');
        $count = $site->value("SELECT COUNT(*) FROM ka_collection_items p JOIN ka_collections k ON k.collection_id = p.collection_id WHERE k.slug = ? AND p.visible = 1 AND p.deleted_at IS NULL AND p.language = ''", [$first]);
        $list = $this->mcpText('list_facts');
        $this->assertStringContainsString('years_since:fact.founded', $list, 'MCP list_facts: the computed tokens');
        $this->assertStringContainsString("count:$first", $list, 'MCP list_facts: the computed tokens with their values');

        $admin = $site->admin()->get('/admin.php?module=facts')->body;
        $this->assertStringContainsString('years_since:fact.founded', $admin, 'facts: the admin list explains the computed tokens');
        $this->assertStringContainsString("count:$first}}</code></td><td>$count<", $admin, 'facts: the admin list shows their current values');

        $this->mcpText('trash_page', ['id' => (int) $site->value("SELECT page_id FROM ka_pages WHERE slug = 'pocitane-test'")]);
        $this->assertStringContainsString('Fakta test', $this->mcpText('delete_fact', ['key' => 'founded']), 'deleting a fact lists where it was still used');
    }

    // ---- 53 opening hours with exceptions

    public function testOpeningHoursExceptions(): void
    {
        $site = $this->site();
        $site->exec("REPLACE INTO ka_settings VALUES ('company_hours', 'Po-Pá 8:00-17:00'), ('company_type', 'LocalBusiness')");
        $tomorrow = date('Y-m-d', strtotime('+1 day'));
        $this->assertStringContainsString('Inventura', $this->mcpText('save_hours_exception', ['from' => $tomorrow, 'note' => 'Inventura', 'notice_days' => 7]), 'hours: Claude adds an exception (closed tomorrow)');
        $this->assertStringContainsString('YYYY-MM-DD', $this->mcpText('save_hours_exception', ['from' => '2026-13-01']), 'hours: a wrong date is refused');

        $home = $this->page('/');
        $this->assertTrue(str_contains($home, 'class="ka-oznameni-hodiny"') && str_contains($home, 'Inventura'), 'hours: the notice bar on the site');
        $this->assertStringContainsString('"specialOpeningHoursSpecification"', $home, 'hours: the exception in the structured data');

        $this->mcpText('create_page', ['title' => 'Hodiny test', 'slug' => 'hodiny-test', 'visible' => true, 'text' => '<p>Dnes: {{hours.today}}. {{hours.status}}</p>']);
        $body = $site->client()->get('/hodiny-test')->body;
        $this->assertStringNotContainsString('{{hours', $body, 'hours: the tokens are filled in');
        $this->assertMatchesRegularExpression('/Dnes: ([0-9]|zavřeno)/u', $body, 'hours: {{hours.today}} and {{hours.status}} filled in');

        $list = $this->mcpText('list_hours');
        $this->assertStringContainsString('Monday', $list, 'MCP list_hours: the week');
        $this->assertStringContainsString('Inventura', $list, 'MCP list_hours: the exceptions');

        $business = $site->admin()->get('/admin.php?module=business')->body;
        $this->assertTrue(str_contains($business, 'Inventura') && str_contains($business, 'name="exception_from"'), 'hours: the exceptions in Settings → Company');
        $exception = (int) $site->value('SELECT id FROM ka_hours_exceptions LIMIT 1');
        $this->assertStringContainsString("action=hours_sign&amp;exception=$exception", $business, 'hours: every exception has a Door sign link');

        $sign = $this->assertPage("/admin.php?module=settings&action=hours_sign&exception=$exception", 200, 'Inventura', message: 'hours: the door sign is a printable page with the note');
        $this->assertStringContainsString('<svg class="qr"', $sign->body, 'hours: the sign carries the QR code');
        $this->assertStringContainsString(substr($site->base, 7), $sign->body, 'hours: the QR code holds the site address');
        $this->assertStringContainsString('@page { size: A4', $sign->body, 'hours: A4 print CSS');
        $this->assertStringContainsString('data-tisk', $sign->body, 'hours: the Print button');
        $this->assertStringNotContainsString('admin.css', $sign->body, 'hours: outside the admin layout');
        $this->assertPage("/admin.php?module=settings&action=hours_sign&exception=$exception&format=a5", 200, '@page { size: A5', message: 'hours: the A5 sign');
        $this->assertPage('/admin.php?module=settings&action=hours_sign&exception=999999', 404, message: 'hours: a sign for an unknown exception is a 404');

        $this->adminPost('/admin.php?module=settings&action=hours_delete', ['exception' => $exception], '/admin.php?module=business');
        $this->sameValue('0', $site->value('SELECT COUNT(*) FROM ka_hours_exceptions'), 'hours: an exception is deleted in the admin');
        $this->assertStringNotContainsString('ka-oznameni-hodiny', $this->page('/'), 'hours: without an exception there is no notice bar');
    }

    // ---- 54 links between collections, people

    public function testCollectionsLinkToEachOtherAndHiddenPeopleRedirect(): void
    {
        $site = $this->site();
        $this->mcpText('create_collection', ['name' => 'Pobočky test', 'slug' => 'pobocky-test', 'item_pages' => true, 'fields' => [['label' => 'Město', 'type' => 'text']]]);
        $this->mcpText('save_collection_item', ['collection' => 'pobocky-test', 'name' => 'Praha centrum', 'slug' => 'praha-centrum', 'values' => ['mesto' => 'Praha'], 'visible' => true]);
        $this->assertStringContainsString('redirect_hidden_to', $this->mcpText('create_collection', ['name' => 'Lidé test', 'slug' => 'lide-test', 'item_pages' => true, 'redirect_hidden_to' => '/pobocky-test', 'fields' => [['label' => 'Pobočka', 'type' => 'item', 'collection' => 'pobocky-test']]]), 'collections: Claude links a field to another collection');
        $this->sameValue('pobocky-test', $site->value("SELECT JSON_UNQUOTE(JSON_EXTRACT(fields, '\$[0].kolekce')) FROM ka_collections WHERE slug = 'lide-test'"), 'collections: the link remembers the collection');

        $this->mcpText('save_collection_item', ['collection' => 'lide-test', 'name' => 'Jana Nová', 'slug' => 'jana-nova', 'values' => ['pobocka' => 'praha-centrum'], 'visible' => true]);
        $this->assertStringContainsString('Praha centrum', $site->client()->get('/lide-test/jana-nova')->body, 'collections: the item page shows the linked item by its name');

        $this->mcpText('save_collection_item', ['collection' => 'lide-test', 'id' => (int) $site->value("SELECT item_id FROM ka_collection_items WHERE slug = 'jana-nova'"), 'visible' => false]);
        $hidden = $site->client()->get('/lide-test/jana-nova');
        $this->assertSame('301 ' . $site->base . '/pobocky-test', $hidden->status . ' ' . $hidden->redirect, 'people: the page of a hidden person leads to the chosen page (301)');
        $this->assertSame(404, $site->client()->get('/lide-test/nikdo-takovy')->status, 'people: an address that never existed is still not found');
    }

    public function testPeoplePresetAndEmailSignature(): void
    {
        $site = $this->site();
        $preset = $this->mcpText('create_collection', ['name' => 'Tým', 'preset' => 'people']);
        $this->assertStringContainsString('redirect_hidden_to', $preset, 'people: the ready-made team collection (redirect)');
        $this->assertStringContainsString('image', $preset, 'people: the ready-made team collection (photo)');

        // field keys by the preset's order (system/presets/people.php: photo, role, languages, phone, email, on_leave, about)
        self::$team = (string) $site->value("SELECT slug FROM ka_collections WHERE schema_org LIKE '%Person%' ORDER BY collection_id DESC LIMIT 1");
        self::$teamIdk = (int) $site->value('SELECT collection_id FROM ka_collections WHERE slug = ?', [self::$team]);
        $key = fn (int $i): string => (string) $site->value("SELECT JSON_UNQUOTE(JSON_EXTRACT(fields, ?)) FROM ka_collections WHERE slug = ?", ["\$[$i].klic", self::$team]);
        $saved = $this->mcpText('save_collection_item', ['collection' => self::$team, 'name' => 'Petr Podpis', 'slug' => 'petr-podpis', 'visible' => true, 'values' => [
            $key(1) => 'Obchodní ředitel', $key(3) => '+420 777 123 456', $key(4) => 'petr@example.cz', $key(5) => 'Dovolená do pátku',
        ]]);
        self::$person = (int) $site->value('SELECT item_id FROM ka_collection_items WHERE collection_id = ? AND slug = ?', [self::$teamIdk, 'petr-podpis']);
        $this->assertGreaterThan(0, self::$person, 'people: a person with a role, a phone and an e-mail ' . substr($saved, 0, 300));

        $signature = $this->mcpText('get_email_signature', ['collection' => self::$team, 'id' => self::$person]);
        foreach (['Petr Podpis', '777 123 456', 'tel:+420777123456', 'max-width:600px'] as $needle) {
            $this->assertStringContainsString($needle, $signature, "people: get_email_signature has $needle");
        }
        $this->assertStringNotContainsString('Dovolen', $signature, 'people: the signature never carries the absence');

        $bySlug = $this->mcpText('get_email_signature', ['collection' => self::$team, 'slug' => 'petr-podpis']);
        $this->assertStringContainsString('mailto:petr@example.cz', $bySlug, 'people: the signature by the person\'s address');
        $this->assertStringContainsString('"people_collection":true', $bySlug, 'people: it knows the collection holds people');
    }

    public function testSignatureScreensAndCollectionForms(): void
    {
        $site = $this->site();
        $idk = self::$teamIdk;
        $person = self::$person;
        $this->assertPage("/admin.php?module=collections&action=item&id=$idk&item=$person", 200, 'action=signature', message: 'people: the item form offers the e-mail signature');
        $preview = $this->assertPage("/admin.php?module=collections&action=signature&id=$idk&item=$person", 200, 'data-kopirovat-podpis', message: 'people: the admin signature page shows the preview with the copy button');
        foreach (['Petr Podpis', 'Obchodní ředitel', 'href="tel:+420777123456"'] as $needle) {
            $this->assertStringContainsString($needle, $preview->body, "people: the preview has $needle");
        }
        $this->assertStringNotContainsString('Dovolen', $preview->body, 'people: the preview never shows the absence');

        $linked = (int) $site->value("SELECT collection_id FROM ka_collections WHERE slug = 'lide-test'");
        $form = $site->admin()->get("/admin.php?module=collections&action=edit&id=$linked")->body;
        $this->assertStringContainsString('name="hidden_redirect"', $form, 'collections: the form offers the redirect');
        $this->assertStringContainsString('name="fields[0][kolekce]"', $form, 'collections: the form offers links');
        $item = (int) $site->value("SELECT item_id FROM ka_collection_items WHERE slug = 'jana-nova'");
        $this->assertStringContainsString('<option value="praha-centrum" selected>Praha centrum</option>', $site->admin()->get("/admin.php?module=collections&action=item&id=$linked&item=$item")->body, 'collections: the item form chooses the linked item');
    }
}
