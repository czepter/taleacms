<?php

declare(strict_types=1);

namespace Kaleta\Tests\Site\LinksConnectors;

use Kaleta\Tests\Site\Support\SiteTestCase;
use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\Attributes\Group;

/** Google Business Profile sync and customer reviews from Google (was: section 84, 2.13). */
#[Group('site')]
final class BusinessProfileTest extends SiteTestCase
{
    use FakeServices;

    private const string CONNECTORS = '/admin.php?module=connectors';

    /** The last logged request of a kind (patch | post) as JSON, for the checks of what went to Google. */
    private function gbpSent(string $key): string
    {
        $lines = file($this->fakeLog('google-business.log'), FILE_IGNORE_NEW_LINES) ?: [];
        foreach (array_reverse($lines) as $line) {
            $entry = json_decode($line, true);
            if (is_array($entry) && isset($entry[$key])) {
                return json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
        }

        return '';
    }

    private function resetLog(): void
    {
        @unlink($this->fakeLog('google-business.log'));
    }

    private function companyHours(string $hours): void
    {
        $this->adminPost('/admin.php?module=settings&action=save', ['tab' => 'company', 'company_type' => 'LocalBusiness', 'company_hours' => $hours], '/admin.php?module=business');
    }

    public function testTheChosenLocationAndTheNewsOptInAreStored(): void
    {
        $site = $this->site();
        $this->resetLog();
        @unlink($this->fakeLog('google-fewer'));
        // the old script had announced the starter news long before (earlier jobs); here it would become a second post
        $site->exec('UPDATE ka_news SET announced_at = NOW() WHERE announced_at IS NULL');
        $this->connectFake('google');
        $screen = $this->assertPage(self::CONNECTORS, 200, 'name="config[location]"', message: 'GBP: the Connections screen has the Business Profile section with the location select');
        $this->assertStringContainsString('name="config[post_news]"', $screen->body, 'GBP: the news opt-in');
        $this->assertStringContainsString('action=gbp_locations', $screen->body, 'GBP: Load my locations is offered');
        $this->assertStringNotContainsString('action=gbp_sync', $screen->body, 'GBP: Sync now only once a location is chosen');

        $this->adminPost('/admin.php?module=connectors&action=gbp_locations', [], self::CONNECTORS);
        $locations = $this->assertPage(self::CONNECTORS, 200, '<option value="accounts/100/locations/2001">Test Company – Prague</option>', message: 'GBP: Load my locations lists the account\'s locations from Google');
        $this->assertStringContainsString('Test Company – Brno', $locations->body, 'GBP: both locations');
        $this->assertStringContainsString('"readMask":"name,title"', $this->fakeLogContents('google-business.log'), 'GBP: asked for with a read mask');

        $this->adminPost('/admin.php?module=connectors&action=save', ['service' => 'google', 'client_id' => 'test-client', 'secret' => '', 'config' => ['location' => 'accounts/100/locations/2001', 'post_news' => '1']], self::CONNECTORS);
        $this->assertSame('accounts/100/locations/2001|1|1', $site->value("SELECT CONCAT(JSON_UNQUOTE(JSON_EXTRACT(config, '$.location')), '|', JSON_UNQUOTE(JSON_EXTRACT(config, '$.post_news')), '|', secret IS NOT NULL) FROM ka_connectors WHERE service = 'google'"), 'GBP: the chosen location and the opt-in are stored in the connection\'s config, the secret stays');
        $this->assertPage(self::CONNECTORS, 200, '<option value="accounts/100/locations/2001" selected>', message: 'GBP: the chosen location is selected and Sync now is offered');
    }

    #[Depends('testTheChosenLocationAndTheNewsOptInAreStored')]
    public function testTheHoursAreSentAndTheReviewsFetchedByTheDailyJob(): void
    {
        $site = $this->site();
        $tomorrow = $this->siteDate('+1 day');
        $site->exec('DELETE FROM ka_connector_queue');
        $this->companyHours('Mon-Fri 8:00-17:00');
        $site->mcp('save_hours_exception', ['from' => $tomorrow, 'note' => 'Stocktaking GBP', 'notice_days' => 0]);
        $this->assertSame('1|gbp.hours', $site->value('SELECT CONCAT(COUNT(*), \'|\', MIN(action)) FROM ka_connector_queue WHERE next_attempt IS NOT NULL'), 'GBP: saving the company hours and an exception queue one gbp.hours delivery');

        // a background run of the scheduler may already have delivered the first queued hours; count only what the job below delivers
        $site->exec("DELETE FROM ka_connector_queue WHERE delivered_at IS NOT NULL");
        $site->exec("DELETE FROM ka_connector_log WHERE action = 'gbp.hours'");
        $this->resetLog();
        $tasks = $this->runJob('gbp');
        $patch = $this->gbpSent('patch');
        $date = json_encode(['year' => (int) date('Y', strtotime($tomorrow)), 'month' => (int) date('n', strtotime($tomorrow)), 'day' => (int) date('j', strtotime($tomorrow))]);
        $this->assertStringContainsString('"updateMask":"regularHours,specialHours"', $patch, 'GBP: the job PATCHes the location with the update mask');
        preg_match_all('/"openDay":"[A-Z]*"/', $patch, $days);
        $days = $days[0];
        sort($days);
        $this->assertSame(['"openDay":"FRIDAY"', '"openDay":"MONDAY"', '"openDay":"THURSDAY"', '"openDay":"TUESDAY"', '"openDay":"WEDNESDAY"'], $days, 'GBP: Mo–Fr as regularHours');
        $this->assertStringContainsString('"openTime":{"hours":8,"minutes":0},"closeDay":"MONDAY","closeTime":{"hours":17,"minutes":0}', $patch, 'GBP: 8–17');
        $this->assertStringContainsString('{"startDate":' . $date . ',"endDate":' . $date . ',"closed":true}', $patch, 'GBP: tomorrow closed as specialHours');
        $this->assertSame('1|1', $site->value("SELECT CONCAT((SELECT COUNT(*) FROM ka_connector_queue WHERE action = 'gbp.hours' AND delivered_at IS NOT NULL), '|', (SELECT COUNT(*) FROM ka_connector_log WHERE action = 'gbp.hours' AND ok = 1))"), 'GBP: the delivery is done and logged as gbp.hours, never with its content');

        $this->assertSame('3|Petr N.|Thank you, Alena!|4.3|27', $site->value("SELECT CONCAT((SELECT COUNT(*) FROM ka_google_reviews), '|', (SELECT author FROM ka_google_reviews WHERE review_id = 'rev-b'), '|', (SELECT reply FROM ka_google_reviews WHERE review_id = 'rev-a'), '|', (SELECT value FROM ka_settings WHERE name = 'google_rating'), '|', (SELECT value FROM ka_settings WHERE name = 'google_reviews'))"),
            'GBP: the daily job stores the fake reviews with the rating and the count of the whole profile');
        $this->assertStringContainsString('gbp: hours queued, reviews 3', $tasks, 'GBP: the scheduler reports the job');
        $this->assertPage(self::CONNECTORS, 200, 'Google rating 4.3 out of 5 from 27 reviews', message: 'GBP: the Connections screen shows the fetched rating');
    }

    #[Depends('testTheHoursAreSentAndTheReviewsFetchedByTheDailyJob')]
    public function testAPublishedNewsItemBecomesAPostWithALearnMoreButton(): void
    {
        $site = $this->site();
        $site->mcp('create_news', ['title' => 'New hall GBP', 'category' => $this->newsCategory(), 'publish' => true, 'intro' => '<p>We opened a <b>new</b> hall.</p>', 'image' => 'media/hala.jpg']);
        $this->assertGreaterThan(0, (int) $site->value("SELECT news_id FROM ka_news WHERE title = 'New hall GBP'"));
        $site->runTasks();
        $site->runTasks();
        $post = $this->gbpSent('post');
        $this->assertStringContainsString('"summary":"New hall GBP\n\nWe opened a new hall."', $post, 'GBP: the post summary is the plain title and lead');
        $this->assertStringContainsString('"topicType":"STANDARD"', $post, 'GBP: a STANDARD post');
        $this->assertStringContainsString('"callToAction":{"actionType":"LEARN_MORE","url":"' . $site->base . '/news/new-hall-gbp"}', $post, 'GBP: a Learn more button to the news address');
        $this->assertStringContainsString('"media":[{"mediaFormat":"PHOTO","sourceUrl":"' . $site->base . '/media/hala.jpg"}]', $post, 'GBP: the image');
        $this->assertSame('1', (string) $site->value("SELECT COUNT(*) FROM ka_connector_queue WHERE action = 'gbp.post' AND delivered_at IS NOT NULL"), 'GBP: the post was delivered through the queue once');
    }

    #[Depends('testAPublishedNewsItemBecomesAPostWithALearnMoreButton')]
    public function testTheGoogleReviewsElementShowsTheReviewsAndStructuredData(): void
    {
        $site = $this->site();
        $schema = $site->mcpResult('builder_schema')['elements']['google_reviews'] ?? '';
        $schema = is_string($schema) ? $schema : json_encode($schema, JSON_UNESCAPED_UNICODE);
        $this->assertMatchesRegularExpression('/Google reviews.*count:number=3; min_stars:number=4; summary:boolean/', $schema, 'MCP: builder_schema lists google_reviews with its English fields');

        $site->mcp('create_page', ['title' => 'Reviews GBP', 'slug' => 'reviews-gbp', 'visible' => true]);
        $page = (int) $site->value("SELECT page_id FROM ka_pages WHERE slug = 'reviews-gbp'");
        $site->mcp('save_build', ['id' => $page, 'publish' => true, 'build' => ['v' => 1, 'children' => [['type' => 'section', 'children' => [
            ['type' => 'heading', 'tag' => 'h1', 'content' => ['text' => 'Reviews']],
            ['type' => 'google_reviews', 'content' => ['count' => 5, 'min_stars' => 4, 'summary' => true, 'link' => 'https://maps.google.com/?cid=1']],
            ['type' => 'text', 'content' => ['html' => '<p>Rating {{fact.google_rating}} of {{fact.google_reviews}}</p>']],
        ]]]]]);
        $this->assertSame('google_reviews', $site->value("SELECT JSON_UNQUOTE(JSON_EXTRACT(build, '$.children[0].children[1].type')) FROM ka_pages WHERE page_id = ?", [$page]), 'GBP: the build is stored with the English element type');

        $site->clearPageCache();
        $response = $this->assertPage('/reviews-gbp', 200, '<li class="ka-reviews"><header><strong>Alena K.</strong>', message: 'GBP: the page shows the reviews');
        $body = $response->body;
        $this->assertStringContainsString('<strong>Petr N.</strong>', $body, 'GBP: the second review with 4+ stars');
        $this->assertStringNotContainsString('Nobody answered', $body, 'GBP: the 2-star review is left out');
        $this->assertStringContainsString('<p>Fast and friendly.<br />', $body, 'GBP: the review text');
        $this->assertStringContainsString('<p class="ka-reviews-reply"><strong>Reply from the business:</strong> Thank you, Alena!</p>', $body, 'GBP: the reply');
        $this->assertStringContainsString('aria-label="Rated 4.3 out of 5 · 27 reviews on Google"', $body, 'GBP: the summary with stars');
        $this->assertStringContainsString('href="https://maps.google.com/?cid=1" target="_blank" rel="noopener">All reviews on Google</a>', $body, 'GBP: the link to all reviews');
        $this->assertStringContainsString('"aggregateRating":{"@type":"AggregateRating","ratingValue":4.3,"reviewCount":27,"bestRating":5,"worstRating":1}', $body, 'GBP: AggregateRating from Google\'s data');
        $this->assertStringContainsString('"review":[{"@type":"Review","author":{"@type":"Person","name":"Alena K."},"datePublished":"2026-09-20","reviewRating":{"@type":"Rating","ratingValue":5', $body, 'GBP: the shown reviews in the structured data');
        $this->assertStringContainsString('"@id":"' . $site->base . '/#firma"', $body, 'GBP: on the company node');
        $this->assertStringContainsString('<p>Rating 4.3 of 27</p>', $body, 'GBP: {{fact.google_rating}} and {{fact.google_reviews}} are built-in facts');
    }

    #[Depends('testTheGoogleReviewsElementShowsTheReviewsAndStructuredData')]
    public function testAReviewDeletedOnGoogleDisappearsAndDisconnectingForgetsEverything(): void
    {
        $site = $this->site();
        touch($this->fakeLog('google-fewer'));
        $tasks = $this->runJob('gbp');
        $this->assertSame('rev-a,rev-c', $site->value('SELECT GROUP_CONCAT(review_id ORDER BY review_id) FROM ka_google_reviews'), 'GBP: a review gone from Google is gone from the site (' . $tasks . ')');
        $site->runTasks(); // the daily job queued the hours again – delivered now
        $page = $site->client('visitor')->get('/reviews-gbp')->body;
        $this->assertStringNotContainsString('Petr N.', $page, 'GBP: the page no longer shows the deleted review (the cache was cleared)');
        $this->assertStringContainsString('Alena K.', $page, 'GBP: the page still shows the others');

        $this->adminPost('/admin.php?module=connectors&action=disconnect', ['service' => 'google'], self::CONNECTORS);
        $this->assertSame('0||', $site->value("SELECT CONCAT((SELECT COUNT(*) FROM ka_google_reviews), '|', (SELECT value FROM ka_settings WHERE name = 'google_rating'), '|', (SELECT value FROM ka_settings WHERE name = 'google_locations'))"), 'GBP: disconnecting Google deletes the reviews, the rating and the loaded locations');
        $page = $site->client('visitor')->get('/reviews-gbp')->body;
        $this->assertStringNotContainsString('class="ka-reviews', $page, 'GBP: without the connection the element renders nothing');
        $this->assertStringNotContainsString('AggregateRating', $page, 'GBP: no AggregateRating without the connection');
        $this->assertStringContainsString('<p>Rating  of </p>', $page, 'GBP: the facts are empty');

        $this->companyHours('Mon-Fri 9:00-16:00');
        $this->assertSame('0', (string) $site->value('SELECT COUNT(*) FROM ka_connector_queue WHERE next_attempt IS NOT NULL'), 'GBP: without the connection a change of the hours queues nothing');
        @unlink($this->fakeLog('google-fewer'));
    }
}
