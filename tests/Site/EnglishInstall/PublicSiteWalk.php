<?php

declare(strict_types=1);

namespace Talea\Tests\Site\EnglishInstall;

/** Every visible page, news, search, 404 and the privacy policy of an English install, as a visitor sees them (was public_site in tools/test-english.sh). */
trait PublicSiteWalk
{
    /** Every extension (3.2: Bookings is a feature, off unless ticked). */
    private const array ALL = ['news', 'enquiries', 'newsletter_signup', 'bookings', 'stats', 'redirects', 'languages', 'api', 'assistant', 'claude'];

    private function walkPublicSite(string $starter): void
    {
        $site = $this->site();
        $visitor = $site->client('visitor');
        $homeId = $site->settingValue('home_page');
        $slugs = $site->rows('SELECT IF(page_id = ?, \'\', slug) AS s FROM tl_pages WHERE visible = 1 ORDER BY sort_order', [$homeId]);
        $this->assertNotEmpty($slugs, "$starter: has visible pages");
        foreach (array_column($slugs, 's') as $slug) {
            $response = $this->assertCzechFree('/' . $slug, 200, $visitor, label: "$starter: page");
            $this->assertHeadings($response->body, "$starter: page /$slug");
        }
        $this->assertSame(0, (int) $site->value('SELECT COUNT(*) FROM tl_pages WHERE build LIKE \'%"type":"image"%\' AND build NOT LIKE \'%"src":"media/%\''), "$starter: a starter page has an image slot without an image");
        if (in_array('news', explode(',', $site->settingValue('extensions')), true)) {
            $this->assertCzechFree('/news', 200, $visitor, label: "$starter: news");
            $slug = $site->value('SELECT slug FROM tl_news LIMIT 1');
            $this->assertCzechFree("/news/$slug", 200, $visitor, label: "$starter: news item");
            $this->assertCzechFree("/news/$slug", 200, $site->admin(), label: "$starter: news item, signed in");
            $this->assertCzechFree('/news/category/' . $site->value('SELECT slug FROM tl_categories LIMIT 1'), 200, $visitor, label: "$starter: news category");
        }
        $found = $this->assertCzechFree('/search?q=contact', 200, $visitor, label: "$starter: search with results");
        $this->assertStringContainsString('href="/contact"', $found->body, "$starter: search finds the Contact page");
        $this->assertCzechFree('/search?q=zzqqxx', 200, $visitor, label: "$starter: search without results");
        $this->assertCzechFree('/this-page-does-not-exist', 404, $visitor, label: "$starter: not found");
        $site->exec("UPDATE tl_pages SET visible = 1 WHERE slug = 'privacy-policy'");
        $site->clearPageCache();
        $this->assertCzechFree('/privacy-policy', 200, $visitor, label: "$starter: privacy policy (published)");
    }
}
