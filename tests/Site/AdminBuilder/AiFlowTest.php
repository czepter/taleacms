<?php

declare(strict_types=1);

namespace Talea\Tests\Site\AdminBuilder;

use PHPUnit\Framework\Attributes\Group;
use Talea\Tests\Site\Support\Response;
use Talea\Tests\Site\Support\SiteTestCase;

/**
 * The in-admin AI flow (#31): the first-run wizard and the builder's Ask box, against the fake provider (tools/fake/ai.php, started with the
 * site: TALEA_AI_URL points to it). Drafts only, the model's reply is untrusted, guardrails and the journal apply, nothing is sent before a click.
 */
#[Group('site')]
final class AiFlowTest extends SiteTestCase
{
    private const string BUILD = '{"v":1,"children":[{"id":"sek1","type":"section","children":[{"id":"nad1","type":"heading","tag":"h1","content":{"text":"A very long hero title that should really be shorter"}}]}]}';

    private function pageId(): int
    {
        return (int) $this->site()->value("SELECT page_id FROM tl_pages WHERE slug = 'about-us'");
    }

    private function pagePublicId(): string
    {
        return $this->site()->publicId('pages', $this->pageId());
    }

    /** @param array<string, mixed> $fields */
    private function post(string $query, array $fields = []): Response
    {
        return $this->site()->admin()->post('/admin.php?' . $query, ['_csrf' => $this->site()->csrf()] + $fields);
    }

    /** The Ask box on the page ('about-us'): its draft is reset to BUILD first. */
    private function ask(string $request, bool $reset = true): Response
    {
        if ($reset) {
            $this->post('module=pages&action=build_save&id=' . $this->pagePublicId(), ['build' => self::BUILD]);
        }

        return $this->post('module=pages&action=build_ask&id=' . $this->pagePublicId(), ['request' => $request]);
    }

    private function aiRequests(): int
    {
        return substr_count($this->site()->fakeLog('ai'), "\n");
    }

    public function testNothingIsSentToTheProviderBeforeAClick(): void
    {
        $site = $this->site();
        $site->setting('ai_key', 'test-key-for-the-fake-provider');
        $site->setting('ai_provider', 'anthropic');
        $this->assertSame(0, $this->aiRequests(), 'privacy: no request at the start');

        $this->assertPage('/admin.php?module=wizard', 200, ['name="name"', 'are sent to Anthropic (Claude) when you click'], message: 'wizard: the start screen opens and says what is sent');
        $this->assertPage('/admin.php?module=pages&action=builder&id=' . $this->pagePublicId(), 200, ['build_ask', '"ai":true'], message: 'builder opens');
        $this->assertSame(0, $this->aiRequests(), 'privacy: opening the screens sends nothing');
    }

    public function testAskChangesTheDraftOnlyAndIsLoggedAndUndoable(): void
    {
        $site = $this->site();
        $published = $site->value('SELECT build FROM tl_pages WHERE page_id = ?', [$this->pageId()]);
        $before = $this->aiRequests();
        $response = $this->ask('make the hero shorter');
        $this->assertSame(200, $response->status, 'ask: ok');
        $json = $response->json();
        $this->assertTrue($json['ok'], 'ask: ok flag');
        $this->assertSame($before + 1, $this->aiRequests(), 'ask: exactly one request, on the click');
        $this->assertStringContainsString('Shorter title', (string) $site->value('SELECT build_draft FROM tl_pages WHERE page_id = ?', [$this->pageId()]), 'ask: the draft changed');
        $this->assertSame($published, $site->value('SELECT build FROM tl_pages WHERE page_id = ?', [$this->pageId()]), 'ask: nothing is published');
        $this->assertStringContainsString('Changed the page as asked.', $response->body);
        $sent = $site->fakeLog('ai');
        $this->assertStringContainsString('very long hero title', $sent, 'ask: the page text goes to the provider');
        $this->assertStringNotContainsString('test-key-for-the-fake-provider', $sent, 'ask: the key is never logged');

        $log = $site->rows("SELECT module, action, reason, description FROM tl_change_log WHERE module = 'assistant' AND action = 'ask' ORDER BY log_id DESC LIMIT 1")[0] ?? [];
        $this->assertSame('make the hero shorter', $log['reason'] ?? null, 'ask: logged as the assistant, with the request as the reason');

        $session = $site->rows("SELECT id, connection FROM tl_agent_sessions WHERE connection LIKE 'Assistant:%' ORDER BY id DESC LIMIT 1")[0] ?? [];
        $this->assertNotEmpty($session, 'ask: its own journal session');
        $this->assertSame((int) $json['session'], (int) $session['id'], 'ask: the answer names the session');

        $this->post('module=changelog&action=undo', ['id' => $session['id']]);
        $this->assertStringContainsString('very long hero title', (string) $site->value('SELECT build_draft FROM tl_pages WHERE page_id = ?', [$this->pageId()]), 'ask: undo restores the draft');
        $this->assertNotNull($site->value('SELECT undone_at FROM tl_agent_sessions WHERE id = ?', [$session['id']]), 'ask: the session is marked undone');
    }

    public function testAskInsertsAnElementAndAnUnreadableOrHostileReplyChangesNothingUnsafe(): void
    {
        $site = $this->site();
        $this->assertStringContainsString('Pricing', (string) $this->ask('add a pricing section')->json()['build']['children'][1]['content']['text'], 'ask: an element is inserted');

        $before = $this->aiRequests();
        $garbage = $this->ask('GARBAGE please');
        $this->assertSame(502, $garbage->status, 'ask: a reply that is not JSON is an error');
        $this->assertStringNotContainsString('Shorter', (string) $site->value('SELECT build_draft FROM tl_pages WHERE page_id = ?', [$this->pageId()]), 'ask: the draft is untouched after a bad reply');

        $site->setting('claude_destructive', '0');
        $hostile = $this->ask('HOSTILE reply please');
        $site->setting('claude_destructive', '1');
        $draft = (string) $site->value('SELECT build_draft FROM tl_pages WHERE page_id = ?', [$this->pageId()]);
        $this->assertSame($before + 2, $this->aiRequests());
        foreach (['<script', 'onerror', 'javascript:', '"type":"html"', 'nonsense', 'no_such_property'] as $bad) {
            $this->assertStringNotContainsString($bad, $draft, 'hostile reply: ' . $bad . ' never reaches the draft');
        }
        $this->assertStringContainsString('very long hero title', $draft, 'hostile reply: no delete operation without claude_destructive');
        $this->assertNotNull(\json_decode($draft, true), 'hostile reply: the draft is valid JSON');
        $this->assertNotSame($draft, $site->value('SELECT build FROM tl_pages WHERE page_id = ?', [$this->pageId()]), 'hostile reply: not published');
        $this->assertContains($hostile->status, [200, 422], 'hostile reply: handled');
    }

    public function testAskKeepsToTheGuardrails(): void
    {
        $site = $this->site();
        $site->setting('claude_protected_pages', (string) $this->pageId());
        $before = $this->aiRequests();
        $refused = $this->ask('make the hero shorter');
        $this->assertSame(403, $refused->status, 'guardrails: a protected page is refused');
        $this->assertSame($before, $this->aiRequests(), 'guardrails: and nothing is sent');
        $site->setting('claude_protected_pages', '');

        $site->exec("DELETE FROM tl_change_log WHERE module = 'assistant'");
        $site->setting('claude_change_limit', '1');
        $this->assertSame(200, $this->ask('make the hero shorter')->status, 'guardrails: the first change is allowed');
        $this->assertSame(403, $this->ask('make the hero shorter', false)->status, 'guardrails: the hourly limit stops the second');
        $site->setting('claude_change_limit', '0');
    }

    public function testWizardMakesOnlyDraftsAndTheRunCanBeUndone(): void
    {
        $site = $this->site();
        $site->exec("DELETE FROM tl_change_log WHERE module = 'assistant'");
        $pages = (int) $site->value('SELECT COUNT(*) FROM tl_pages');
        $lastPage = (int) $site->value('SELECT MAX(page_id) FROM tl_pages');
        $before = $this->aiRequests();

        $answers = ['name' => 'Timber & Co', 'blueprint' => '', 'type' => 'carpenter', 'services' => 'Kitchens and stairs made to measure', 'tone' => 'friendly', 'language' => 'en',
            'company_email' => 'hello@timber.example', 'company_phone' => '+49 30 123456', 'company_street' => 'Oak Street 1', 'company_postcode' => '10115', 'company_city' => 'Berlin'];
        $plan = $this->post('module=wizard&action=plan', $answers);
        $this->assertSame(200, $plan->status);
        $this->assertSame($before + 1, $this->aiRequests(), 'wizard: the plan is one request, on the click');
        $this->assertStringContainsString('About us', $plan->body, 'wizard: the review lists the pages');
        $this->assertStringContainsString('Create the drafts', $plan->body);
        $this->assertSame($pages, (int) $site->value('SELECT COUNT(*) FROM tl_pages'), 'wizard: the plan makes nothing');

        $apply = $this->post('module=wizard&action=apply', $answers + ['plan_blueprint' => 'craftsman', 'plan_look' => 'atelier', 'pages' => [
            ['use' => '1', 'title' => 'Home', 'brief' => 'A welcome.'], ['use' => '1', 'title' => 'About us', 'brief' => 'Who we are.'], ['title' => 'Contact', 'brief' => 'Not chosen.'],
        ]]);
        $this->assertSame(200, $apply->status, 'wizard: applied');
        $this->assertSame(2, (int) $site->value("SELECT COUNT(*) FROM tl_pages WHERE page_id > ? AND build_draft LIKE '%Welcome%'", [$lastPage]), 'wizard: the chosen pages only');
        $this->assertSame(0, (int) $site->value("SELECT COUNT(*) FROM tl_pages WHERE title IN ('Home', 'About us') AND (visible = TRUE OR build IS NOT NULL OR build_draft IS NULL) AND page_id > ?", [$lastPage]), 'wizard: hidden pages with a build draft, nothing published');
        $this->assertNotSame('', $site->settingValue('look_draft'), 'wizard: the look is in the draft look');
        $this->assertNotNull($site->value("SELECT 1 FROM tl_blueprints WHERE bkey = 'craftsman'"), 'wizard: the blueprint is applied');
        $this->assertSame('hello@timber.example', $site->settingValue('company_email'), 'wizard: the contact answers fill the company details');
        $this->assertStringContainsString('shop_license', $apply->body, 'wizard: a fact the site does not know is listed for the owner');
        $this->assertStringContainsString('placeholders', $apply->body, 'wizard: placeholders are counted');
        $draft = (string) $site->value("SELECT build_draft FROM tl_pages WHERE title = 'About us' AND page_id > ?", [$lastPage]);
        $this->assertStringContainsString('{{fact.company_phone}}', $draft, 'wizard: a known fact stays a token');

        $session = $site->rows("SELECT id FROM tl_agent_sessions WHERE connection LIKE 'Assistant:%setup wizard' ORDER BY id DESC LIMIT 1")[0]['id'] ?? 0;
        $this->assertNotSame(0, $session, 'wizard: one journal session');
        $this->assertNotNull($site->value("SELECT 1 FROM tl_change_log WHERE module = 'assistant' AND action = 'wizard'"), 'wizard: logged');
        $this->post('module=changelog&action=undo', ['id' => $session]);
        $this->assertSame($pages, (int) $site->value('SELECT COUNT(*) FROM tl_pages'), 'wizard: undo takes the pages back');
        $this->assertNull($site->value("SELECT 1 FROM tl_blueprints WHERE bkey = 'craftsman'"), 'wizard: and the blueprint');
    }

    public function testWizardTreatsTheModelsPlanAndPagesAsUntrusted(): void
    {
        $site = $this->site();
        $answers = ['name' => 'Evil Co', 'blueprint' => '', 'type' => 'HOSTILE shop', 'services' => 'HOSTILE', 'tone' => 'friendly', 'language' => 'en'];
        $plan = $this->post('module=wizard&action=plan', $answers);
        $this->assertSame(200, $plan->status);
        $this->assertStringNotContainsString('<script>alert', $plan->body, 'hostile plan: titles are plain text');
        $this->assertStringNotContainsString('passwd', $plan->body, 'hostile plan: an unknown blueprint key is not taken over');

        $this->post('module=wizard&action=apply', $answers + ['plan_blueprint' => '../../etc/passwd', 'plan_look' => 'nonexistent', 'pages' => [['use' => '1', 'title' => 'Evil page', 'brief' => 'HOSTILE']]]);
        $this->assertSame(1, (int) $site->value("SELECT COUNT(*) FROM tl_pages WHERE title = 'Evil page'"));
        $draft = (string) $site->value("SELECT build_draft FROM tl_pages WHERE title = 'Evil page'");
        foreach (['<script', 'onclick', 'javascript:', 'alert(1)'] as $bad) {
            $this->assertStringNotContainsString($bad, $draft, 'hostile page: ' . $bad . ' never reaches the build');
        }
        $this->assertSame(0, (int) $site->value("SELECT visible FROM tl_pages WHERE title = 'Evil page'"), 'hostile page: still hidden');
    }

    public function testWizardWithoutAKeyAppliesTheBlueprintAndTheLookButWritesNothing(): void
    {
        $site = $this->site();
        $site->exec("DELETE FROM tl_settings WHERE name = 'ai_key'");
        $site->exec("DELETE FROM tl_blueprints WHERE bkey = 'craftsman'");
        $lastPage = (int) $site->value('SELECT MAX(page_id) FROM tl_pages');
        $before = $this->aiRequests();
        $start = $this->assertPage('/admin.php?module=wizard', 200, 'No AI key is set', message: 'no key: the start screen says how to add one');
        $this->assertStringContainsString('module=extensions', $start->body);

        $answers = ['name' => 'No Key Ltd', 'blueprint' => 'craftsman', 'type' => '', 'services' => 'Anything', 'tone' => 'friendly', 'language' => 'en'];
        $plan = $this->post('module=wizard&action=plan', $answers);
        $this->assertStringContainsString('no pages are made', $plan->body, 'no key: the plan says there will be no pages');
        $apply = $this->post('module=wizard&action=apply', $answers + ['plan_blueprint' => 'craftsman', 'plan_look' => 'atelier']);
        $this->assertSame(200, $apply->status);
        $this->assertSame(0, (int) $site->value("SELECT COUNT(*) FROM tl_pages WHERE page_id > ? AND build_draft LIKE '%Welcome%'", [$lastPage]), 'no key: no generated pages (the blueprint only adds its hidden collection pages)');
        $this->assertNotNull($site->value("SELECT 1 FROM tl_blueprints WHERE bkey = 'craftsman'"), 'no key: the blueprint is applied');
        $this->assertNotSame('', $site->settingValue('look_draft'), 'no key: the look is applied');
        $this->assertSame($before, $this->aiRequests(), 'no key: nothing is sent');
        $this->assertStringContainsString('No text was written', $apply->body);
    }
}
