<?php

declare(strict_types=1);

namespace Talea\Tests\Site\ImportersBooking;

use Talea\Tests\Site\Support\SiteTestCase;
use PHPUnit\Framework\Attributes\Group;

/** "At a glance" on Home (Kernel::glance, Core\Report::glance): visits, enquiries, bookings and pages of a period against the one before. */
#[Group('site')]
final class HomeGlanceTest extends SiteTestCase
{
    public function testTheFiguresOfThePeriodAndTheChange(): void
    {
        $site = $this->site();
        $site->setting('extensions', 'news,enquiries,newsletter_signup,stats,redirects,bookings');
        // this week 40 visits, the week before 20; two unread enquiries now, a handled one twenty days ago; one booking ahead
        $site->exec('INSERT INTO tl_stats_days (day, visits, views) VALUES (CURRENT_DATE, 40, 90), (CURRENT_DATE - INTERVAL 9 DAY, 20, 50)');
        $site->exec("INSERT INTO tl_enquiries (created_at, form, email, data, status) VALUES (NOW(), 'Contact', 'a@example.com', '[]', 0), (NOW(), 'Contact', 'b@example.com', '[]', 0),
            (NOW() - INTERVAL 20 DAY, 'Contact', 'c@example.com', '[]', 2)");
        $site->exec("INSERT INTO tl_bookings (service_id, staff_id, starts_at, ends_at, status, token_hash, created_at) VALUES (1, 1, NOW() + INTERVAL 2 DAY, NOW() + INTERVAL 2 DAY + INTERVAL 1 HOUR, 'confirmed', ?, NOW())",
            [hash('sha256', 'glance')]);

        $home = $this->assertPage('/admin.php?days=7', 200, 'class="home-glance"', message: 'Home shows At a glance');
        $this->assertMatchesRegularExpression('#href="[^"]*admin\.php\?days=7" class="active" aria-current="page"#', $home->body, 'the chosen period is marked');
        $this->assertStringContainsString('<strong>40</strong>', $home->body, 'the visits of the last 7 days');
        $this->assertStringContainsString('+100 %', $home->body, 'visits: the change against the week before in per cent');
        $this->assertStringContainsString('2 unread', $home->body, 'enquiries: the unread ones of all time');
        $this->assertStringContainsString('+2</span>', $home->body, 'enquiries: two this week, none the week before');
        $this->assertStringContainsString('Next: ', $home->body, 'bookings: the next appointment');
        $this->assertStringContainsString('<polyline points=', $home->body, 'visits per day as a line');

        $month = $this->assertPage('/admin.php?days=30', 200, 'class="home-glance"', message: '30 days');
        $this->assertStringContainsString('<strong>60</strong>', $month->body, 'the 30 days hold both weeks');
        $this->assertStringNotContainsString(' %</span> <span class="small-text">', $month->body, 'no per cent change without visits in the period before');
    }
}
