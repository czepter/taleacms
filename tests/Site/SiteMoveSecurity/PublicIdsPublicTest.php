<?php

declare(strict_types=1);

namespace Talea\Tests\Site\SiteMoveSecurity;

use Talea\Core\Uuid;
use Talea\Tests\Site\Support\SiteTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * HF-16: nothing the public site sends out carries the integer key of a row of a public-id table – no route, form source,
 * data attribute, feed id or webhook field. The pages are scanned for the shapes an integer key would have.
 */
#[Group('site')]
final class PublicIdsPublicTest extends SiteTestCase
{
    /** What must not appear anywhere in public output: a pop-up or component route, a source or an id attribute with a number. */
    private const string UUID = '[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}';

    private const array INTEGER_SHAPES = ['#/_(?:popup|component|section)/\d+(?:[?"\'\s<]|$)#', '#name="source" value="[a-z]+:\d+"#', '#data-(?:popup|[a-z-]*-id)="\d+"#', '#"(?:page|news|tag|popup|component|item|collection|media|folder|category)_id":\s*\d#',
        '#(?:news|page|item|popup)-\d+</guid>#', '#"id":\s*"news-\d+"#', '#[?&](?:popup|id|staff|service)=\d+\b#'];

    private function assertNoIntegerKeys(string $label, string $body): void
    {
        foreach (self::INTEGER_SHAPES as $pattern) {
            $this->assertDoesNotMatchRegularExpression($pattern, $body, "$label: no integer key ($pattern)");
        }
    }

    public function testPublicPagesFeedsAndFormsCarryNoIntegerKeys(): void
    {
        $site = $this->site();
        $form = $site->php('echo Talea\Builder\Build::toJson(Talea\Builder\Build::sanitize(["v" => 1, "children" => [["type" => "form", "content" => ["name" => "Ids popup form", "fields" => [["label" => "E-mail", "type" => "email", "required" => true]]]]]], true)[0]);');
        $site->exec("INSERT INTO tl_popups (name, slug, rules, build, build_draft, active, updated_at) VALUES ('Ids popup', 'ids-popup', '{}', ?, NULL, 1, NOW())", [$form]);
        $site->exec("UPDATE tl_popups SET rules = '{\"where\":\"all\"}' WHERE slug = 'ids-popup'");
        $site->clearPageCache();
        $visitor = $site->client();

        $home = $visitor->get('/');
        $this->assertSame(200, $home->status);
        $popup = (string) $site->value("SELECT public_id FROM tl_popups WHERE slug = 'ids-popup'");
        $this->assertStringContainsString('data-popup="' . $popup . '"', $home->body, 'the pop-up carries its public id');
        $this->assertMatchesRegularExpression('#name="source" value="popup:' . $popup . '"#', $home->body, 'the form in the pop-up has the pop-up public id as its source');
        $this->assertNoIntegerKeys('home', $home->body);

        $news = (string) $site->value("SELECT slug FROM tl_news WHERE visible = 1 AND deleted_at IS NULL ORDER BY news_id LIMIT 1");
        $contact = $visitor->get('/contact');
        $this->assertMatchesRegularExpression('#name="source" value="page:' . self::UUID . '"#', $contact->body, 'a page form has the page public id as its source');
        foreach (['/contact' => $contact->body, '/news/' . $news => $visitor->get('/news/' . $news)->body, '/news' => $visitor->get('/news')->body, '/sitemap.xml' => $visitor->get('/sitemap.xml')->body,
            '/rss.xml' => $visitor->get('/rss.xml')->body, '/feed.json' => $visitor->get('/feed.json')->body, '/llms.txt' => $visitor->get('/llms.txt')->body] as $path => $body) {
            $this->assertNotSame('', $body, "$path answers");
            $this->assertNoIntegerKeys($path, $body);
        }
        $this->assertMatchesRegularExpression('#<guid isPermaLink="false">news-' . self::UUID . '</guid>#', $visitor->get('/rss.xml')->body, 'the feed id of a news item is its public id');
        $this->assertStringContainsString('"id":"news-' . $site->publicId('news', (int) $site->value('SELECT MAX(news_id) FROM tl_news WHERE visible = 1')) . '"', $visitor->get('/feed.json')->body, 'the JSON feed id is the public id');
    }

    public function testTheRoutesAcceptOnlyPublicIds(): void
    {
        $site = $this->site();
        $site->exec("INSERT INTO tl_popups (name, slug, rules, build, active, updated_at) VALUES ('Ids route', 'ids-route', '{}', ?, 1, NOW())", [json_encode(['v' => 1, 'children' => []])]);
        $id = (int) $site->value("SELECT popup_id FROM tl_popups WHERE slug = 'ids-route'");
        $admin = $site->admin();
        $this->assertSame(404, $admin->get("/_popup/$id?build=draft")->status, 'an integer key is not a pop-up route');
        $this->assertSame(200, $admin->get('/_popup/' . $site->publicId('popups', $id) . '?build=draft')->status, 'the public id is');
        $before = (int) $site->value('SELECT impressions FROM tl_popups WHERE popup_id = ?', [$id]);
        $site->client()->post('/popup', ['popup' => (string) $id, 'event' => 'view']);
        $this->assertSame($before, (int) $site->value('SELECT impressions FROM tl_popups WHERE popup_id = ?', [$id]), 'the counter ignores an integer key');
        $site->client()->post('/popup', ['popup' => $site->publicId('popups', $id), 'event' => 'view']);
        $this->assertSame($before + 1, (int) $site->value('SELECT impressions FROM tl_popups WHERE popup_id = ?', [$id]), 'and counts a public id');
    }

    public function testTheCommentWidgetOfADraftNamesThePageByItsPublicId(): void
    {
        $site = $this->site();
        $site->exec("INSERT INTO tl_pages (slug, title, text, visible, build, updated_at) VALUES ('ids-draft', 'Ids draft', '', 0, ?, NOW())",
            [json_encode(['v' => 1, 'children' => [['id' => 'idd1', 'type' => 'heading', 'tag' => 'h2', 'content' => ['text' => 'Draft heading']]]])]);
        $id = (int) $site->value("SELECT page_id FROM tl_pages WHERE slug = 'ids-draft'");
        $public = $site->publicId('pages', $id);
        $until = time() + 3600;
        // the signed key covers the internal target (it is a hash, nothing of it is readable); the page and the comment form carry the public id
        $key = $until . 'k.' . hash_hmac('sha256', "preview|page:$id|$until|comments", $site->settingValue('secret_key'));
        $visitor = $site->client('commenter');
        $page = $visitor->get('/ids-draft?build=draft&preview_key=' . $key);
        $this->assertSame(200, $page->status, 'the draft shows with the signed key');
        $this->assertMatchesRegularExpression('#name="target" value="page:' . $public . '"#', $page->body, 'the comment form carries the public id of the page');
        $this->assertNoIntegerKeys('draft page', $page->body);

        $post = fn (string $target) => $visitor->post('/_comment', ['target' => $target, 'key' => $key, 'back' => '/ids-draft?build=draft&preview_key=' . $key, 'name' => 'Client', 'text' => 'Please change this.']);
        $this->assertSame(403, $post("page:$id")->status, 'an integer key is not a target');
        $this->assertSame(303, $post("page:$public")->status, 'the public id is');
        $this->assertSame("page:$id", (string) $site->value('SELECT target FROM tl_draft_comments ORDER BY id DESC LIMIT 1'), 'the comment is stored against the internal key');
    }

    public function testAWebhookPayloadHasPublicIdsOnly(): void
    {
        $site = $this->site();
        $site->setting('webhook_enquiries', 'https://hooks.example.com/crm');
        $site->setting('webhook_test_url', 'http://127.0.0.1:' . $site->freePort()); // nothing listens: the call fails and its body stays in the log
        $html = $site->client()->get('/contact')->body;
        $field = fn (string $name): string => preg_match('/name="' . $name . '" value="([^"]*)"/', $html, $m) === 1 ? html_entity_decode($m[1]) : '';
        sleep(2); // the form asks for a moment between loading and sending
        $site->client()->post('/form', ['source' => $field('source'), 'element' => $field('element'), 'back' => '/contact', 'as_time' => $field('as_time'), 'as_signature' => $field('as_signature'),
            'p0' => 'Jane', 'p1' => 'jane@example.org', 'p2' => '', 'p3' => 'Hello', 'p4' => '1']);
        $body = (string) $site->value("SELECT body FROM tl_webhook_deliveries WHERE event = 'enquiry_received' ORDER BY id DESC LIMIT 1");
        $this->assertNotSame('', $body, 'the enquiry call is in the delivery log');
        $payload = json_decode($body, true);
        $this->assertTrue(Uuid::valid($payload['id'] ?? null), 'the enquiry in the payload is its public id');
        $this->assertSame((string) $site->value('SELECT public_id FROM tl_enquiries ORDER BY enquiry_id DESC LIMIT 1'), $payload['id'], 'the public id of the stored enquiry');
        $this->assertDoesNotMatchRegularExpression('/"[a-z_]*id":\s*\d/', $body, 'no integer id anywhere in the payload');
    }
}
