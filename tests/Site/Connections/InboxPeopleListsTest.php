<?php

declare(strict_types=1);

namespace Talea\Tests\Site\Connections;

use Talea\Tests\Site\Support\SiteTestCase;
use PHPUnit\Framework\Attributes\Group;

/** Enquiries, users, roles, subscribers and bookings in the shared list design (docs/specs/admin-lists.md). */
#[Group('site')]
final class InboxPeopleListsTest extends SiteTestCase
{
    public function testEnquiriesMarkUnreadAndResolveInOneClick(): void
    {
        $site = $this->site();
        $id = '6f1c2d3e-4b5a-4c6d-8e7f-001122334455';
        $site->exec("INSERT INTO tl_enquiries (public_id, created_at, form, email, data, status) VALUES (?, NOW(), 'Contact', 'anna.list@example.com', ?, 0)", [$id, json_encode([['Name', 'Anna'], ['Message', 'We marry in June']])]);
        $this->assertPage('/admin.php?module=enquiries', 200, [
            '<tr class="unread">', 'anna.list@example.com', 'We marry in June',
            'aria-label="Mark as resolved: anna.list@example.com"', '<input type="hidden" name="bulk" value="vyridit">', 'class="selection-bar"',
        ], message: 'enquiries list');
        $this->adminPost('/admin.php?module=enquiries&action=bulk', ['selected' => [$id], 'bulk' => 'vyridit'], '/admin.php?module=enquiries');
        $this->assertSame('2', (string) $site->value('SELECT status FROM tl_enquiries WHERE public_id = ?', [$id]), 'the one-click action resolves the enquiry');
    }

    public function testATopicLinksOnlyToAPageOfTheSite(): void
    {
        $site = $this->site();
        // imported enquiries (MCP migration) carry the page as given: only a path on this site becomes a link
        $site->exec("INSERT INTO tl_enquiries (public_id, created_at, form, page, topic, email, data, status) VALUES ('6f1c2d3e-4b5a-4c6d-8e7f-0011223344aa', NOW(), 'Import', 'javascript:alert(1)', 'Odd topic', 'odd@example.com', '[]', 1)");
        $list = $this->assertPage('/admin.php?module=enquiries', 200, 'Odd topic', message: 'the topic is shown');
        $this->assertStringNotContainsString('href="javascript:', $list->body, 'no script link from an imported page value');
    }

    public function testUsersAndRolesUseRowMenus(): void
    {
        $site = $this->site();
        $admin = (string) $site->value("SELECT public_id FROM tl_users ORDER BY user_id LIMIT 1");
        $this->assertPage('/admin.php?module=users', 200, ['class="cell-icon avatar"', 'aria-label="Edit: ', 'class="page-count"'], message: 'users list');
        $this->assertPageLacks('/admin.php?module=users', 'popovertarget="row-menu-' . $admin . '"', message: 'the signed-in user cannot delete themself, so their row has no menu');
    }

    public function testSubscribersShowAStatusDot(): void
    {
        $this->site()->exec("INSERT INTO tl_subscribers (public_id, email, status, token, created_at, source) VALUES ('7a1c2d3e-4b5a-4c6d-8e7f-001122334455', 'reader@example.com', 1, '0123456789abcdef0123456789abcdef', NOW(), 'footer')");
        $this->assertPage('/admin.php?module=subscribers', 200, ['reader@example.com', 'class="status status-live"'], message: 'subscribers list');
    }
}
