<?php

declare(strict_types=1);

namespace Talea\Tests\Site\Members;

use PHPUnit\Framework\Attributes\Group;
use Talea\Tests\Site\Support\Http;
use Talea\Tests\Site\Support\SiteTestCase;

/**
 * Member login (HF-33): e-mailed sign-in links, groups, gated pages, news and collection items – the access matrix, the leaks, the
 * session and the limits. The mails are caught by tools/fake-smtp.php.
 */
#[Group('site')]
final class MemberLoginTest extends SiteTestCase
{
    /** @var resource|null */
    private static $smtp = null;
    private static string $mailDir = '';
    /** @var array<string, mixed> */
    private static array $s = [];

    public static function tearDownAfterClass(): void
    {
        if (self::$smtp !== null) {
            proc_terminate(self::$smtp);
            proc_close(self::$smtp);
            self::$smtp = null;
        }
        parent::tearDownAfterClass();
    }

    private function adminCall(string $action, array $fields = []): void
    {
        $this->adminPost('/admin.php?module=members&action=' . $action, $fields, '/admin.php?module=members');
    }

    /** The decoded text of every caught message, oldest first. @return list<string> */
    private function mails(): array
    {
        $out = [];
        $files = glob(self::$mailDir . '/*.eml') ?: [];
        sort($files);
        foreach ($files as $file) {
            [$headers, $body] = array_pad(explode("\r\n\r\n", (string) file_get_contents($file), 2), 2, '');
            $out[] = $headers . "\n" . (str_contains($headers, 'base64') ? base64_decode((string) preg_replace('/\s+/', '', $body)) : $body);
        }

        return $out;
    }

    /** The path and query of the sign-in link in the newest message to this address. */
    private function linkTo(string $email): string
    {
        for ($i = 0; $i < 60; $i++) {
            foreach (array_reverse($this->mails()) as $mail) {
                if (str_contains($mail, 'X-Rcpt-To: ' . $email) && preg_match('~(https?://[^\s]+)/member/verify\?([^\s]+)~', $mail, $m)) {
                    return '/member/verify?' . $m[2];
                }
            }
            usleep(100_000);
        }
        $this->fail('No sign-in link was mailed to ' . $email);
    }

    private function mailCountTo(string $email): int
    {
        return count(array_filter($this->mails(), fn (string $m): bool => str_contains($m, 'X-Rcpt-To: ' . $email)));
    }

    /** @var array<string, Http> browsers that keep their cookies between the tests */
    private static array $browsers = [];

    private function who(string $name): Http
    {
        return self::$browsers[$name] ??= $this->site()->client($name);
    }

    private function signIn(string $email, string $name = 'member'): Http
    {
        $client = $this->who($name);
        $client->post('/member', ['email' => $email, 'website' => '']);
        $link = $this->linkTo($email);
        $this->assertSame(200, $client->get($link)->status, 'the link only shows a button');
        $token = (string) preg_replace('/^.*token=([a-f0-9]{64}).*$/', '$1', $link);
        $answer = $client->post('/member/verify', ['token' => $token, 'return' => '']);
        $this->assertSame(303, $answer->status, 'the button signs the member in');

        return $client;
    }

    public function testSetUpTheSiteWithGroupsAndRestrictedContent(): void
    {
        $site = $this->site();
        $port = $site->freePort();
        self::$mailDir = $site->workDir('smtp-members');
        self::$smtp = proc_open([PHP_BINARY, dirname(__DIR__, 3) . '/tools/fake-smtp.php', (string) $port, self::$mailDir], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        for ($i = 0; $i < 100 && @fsockopen('127.0.0.1', $port) === false; $i++) {
            usleep(50_000);
        }
        $site->exec("REPLACE INTO tl_settings (name, value) VALUES ('mail_mode', 'smtp'), ('smtp_host', '127.0.0.1'), ('smtp_port', ?), ('smtp_encryption', 'none'), ('smtp_user', ''), ('mail_from', 'web@example.org'), ('llms_txt', '1')", [(string) $port]);
        $site->setting('extensions', $site->settingValue('extensions') . ',members');

        $this->adminCall('group_save', ['name' => 'Clients']);
        $this->adminCall('group_save', ['name' => 'Staff']);
        $this->assertSame('2', (string) $site->value('SELECT COUNT(*) FROM tl_member_groups'), 'two groups');
        self::$s['clients'] = (string) $site->value("SELECT public_id FROM tl_member_groups WHERE name = 'Clients'");
        self::$s['staff'] = (string) $site->value("SELECT public_id FROM tl_member_groups WHERE name = 'Staff'");

        $page = $site->mcpResult('create_page', ['title' => 'Club prices', 'slug' => 'club-prices', 'content' => '<p>Secret club price 77</p>', 'visible' => true]);
        $staff = $site->mcpResult('create_page', ['title' => 'Staff handbook', 'slug' => 'staff-handbook', 'content' => '<p>Secret staff rota 88</p>', 'visible' => true]);
        $site->mcpResult('create_page', ['title' => 'Open page', 'slug' => 'open-page', 'content' => '<p>Everyone reads this open text</p>', 'visible' => true]);
        $site->mcpResult('set_content_groups', ['type' => 'page', 'id' => $page['id'], 'groups' => [self::$s['clients']]]);
        $site->mcpResult('set_content_groups', ['type' => 'page', 'id' => $staff['id'], 'groups' => [self::$s['staff']]]);

        $category = (string) $site->value('SELECT name FROM tl_categories ORDER BY category_id LIMIT 1');
        $news = $site->mcpResult('create_news', ['title' => 'Members news headline', 'category' => $category, 'content' => '<p>Secret news body 99</p>']);
        $newsId = $site->rowId($news['id']);
        $site->exec('UPDATE tl_news SET visible = 1, published_at = NOW() - INTERVAL 1 DAY WHERE news_id = ?', [$newsId]);
        self::$s['newsSlug'] = (string) $site->value('SELECT slug FROM tl_news WHERE news_id = ?', [$newsId]);
        $site->mcpResult('set_content_groups', ['type' => 'news', 'id' => $news['id'], 'groups' => [self::$s['clients']]]);
        $openNews = $site->mcpResult('create_news', ['title' => 'Open news headline', 'category' => $category, 'content' => '<p>Open news body</p>']);
        $site->exec('UPDATE tl_news SET visible = 1, published_at = NOW() - INTERVAL 1 DAY WHERE news_id = ?', [$site->rowId($openNews['id'])]);

        $site->mcpResult('create_collection', ['name' => 'Resources', 'slug' => 'resources', 'item_pages' => true, 'fields' => [['label' => 'Text', 'type' => 'text']]]);
        $item = $site->mcpResult('save_collection_item', ['collection' => 'resources', 'name' => 'Handbook item', 'values' => ['text' => 'Secret item text 55'], 'visible' => true]);
        $site->exec('UPDATE tl_collection_items SET visible = 1 WHERE public_id = ?', [$item['id']]);
        $site->mcpResult('set_content_groups', ['type' => 'item', 'id' => $item['id'], 'groups' => [self::$s['clients']]]);
        self::$s['itemSlug'] = (string) $site->value('SELECT slug FROM tl_collection_items WHERE public_id = ?', [$item['id']]);

        $this->assertSame('4', (string) $site->value('SELECT COUNT(*) FROM tl_content_groups'), 'two pages, a news item and a collection item are restricted');
    }

    public function testAnAnonymousVisitorGetsTheSignInPageAndNoCookie(): void
    {
        $visitor = $this->site()->client('anonymous');
        foreach (['/club-prices' => 'Secret club price', '/news/' . self::$s['newsSlug'] => 'Secret news body', '/resources/' . self::$s['itemSlug'] => 'Secret item text'] as $path => $secret) {
            $answer = $visitor->get($path);
            $this->assertSame(403, $answer->status, "$path: refused");
            $this->assertStringNotContainsString($secret, $answer->body, "$path: no content");
            $this->assertStringContainsString('tl-member-email', $answer->body, "$path: the sign-in form");
            $this->assertStringContainsString('private, no-store', $answer->headers['cache-control'] ?? '', "$path: not cacheable");
            $this->assertStringContainsString('noindex', $answer->headers['x-robots-tag'] ?? '', "$path: noindex header");
        }
        $this->assertSame(200, $visitor->get('/open-page')->status);
        $visitor->get('/');
        $this->assertSame([], $visitor->cookies(), 'no cookie and no session for an anonymous visitor');
    }

    public function testRestrictedContentIsNotInAnyOutputOrTheCache(): void
    {
        $this->site()->clearPageCache();
        $visitor = $this->site()->client('leaks');
        $visitor->get('/open-page');
        $outputs = ['/sitemap.xml', '/llms.txt', '/rss.xml', '/feed.json', '/news', '/search?q=secret', '/search?q=club', '/search?q=members', '/news/' . self::$s['newsSlug'] . '.md', '/resources/' . self::$s['itemSlug'] . '/latest', '/'];
        foreach ($outputs as $path) {
            $body = $visitor->get($path)->body;
            foreach (['club-prices', 'Secret club', 'Members news headline', 'Secret news body', 'Handbook item', 'Secret item', 'staff-handbook'] as $needle) {
                $this->assertStringNotContainsString($needle, $body, "$path leaks \"$needle\"");
            }
        }
        $this->assertStringContainsString('open-page', $visitor->get('/sitemap.xml')->body, 'the sitemap still lists public pages');
        foreach (glob($this->site()->path('storage/cache/pages/*')) ?: [] as $file) {
            $this->assertStringNotContainsString('Secret', (string) file_get_contents($file), 'the page cache has no restricted content (' . basename($file) . ')');
        }
    }

    public function testInvitedMemberSignsInWithAOneTimeLink(): void
    {
        $this->adminCall('invite', ['email' => 'Anna@Example.org', 'name' => 'Anna', 'groups' => [self::$s['clients']]]);
        $this->assertSame('1', (string) $this->site()->value("SELECT COUNT(*) FROM tl_members WHERE email = 'anna@example.org'"), 'the address is stored in lower case');
        $link = $this->linkTo('anna@example.org');

        $visitor = $this->who('anna');
        $this->assertSame(200, $visitor->get($link)->status, 'opening the link only shows a button');
        $this->assertSame([], $visitor->cookies(), 'opening the link signs nobody in (a mail scanner may open it)');
        $token = (string) preg_replace('/^.*token=([a-f0-9]{64}).*$/', '$1', $link);
        $this->assertSame('0', (string) $this->site()->value('SELECT COUNT(*) FROM tl_member_tokens WHERE token_hash = ?', [$token]), 'the token itself is not stored');
        $this->assertSame('1', (string) $this->site()->value('SELECT COUNT(*) FROM tl_member_tokens WHERE token_hash = ?', [hash('sha256', $token)]), 'only its hash is');

        $signedIn = $visitor->post('/member/verify', ['token' => $token, 'return' => '/club-prices']);
        $this->assertSame(303, $signedIn->status);
        $this->assertArrayHasKey('tl_member', $visitor->cookies(), 'a member cookie');
        $this->assertArrayNotHasKey('talea', $visitor->cookies(), 'never an administration session');
        $this->assertNotSame('', (string) $this->site()->value("SELECT confirmed_at FROM tl_members WHERE email = 'anna@example.org'"), 'the first link confirms the address');

        $read = $visitor->get('/club-prices');
        $this->assertSame(200, $read->status);
        $this->assertStringContainsString('Secret club price 77', $read->body, 'the member reads the page');
        $this->assertStringContainsString('private, no-store', $read->headers['cache-control'] ?? '', 'even a member gets it uncached');
        $this->assertStringContainsString('noindex', $read->headers['x-robots-tag'] ?? '');
        $this->assertStringContainsString('Secret news body 99', $visitor->get('/news/' . self::$s['newsSlug'])->body, 'the member reads the news item');
        $this->assertStringContainsString('Secret item text 55', $visitor->get('/resources/' . self::$s['itemSlug'])->body, 'and the collection item');
        foreach (glob($this->site()->path('storage/cache/pages/*')) ?: [] as $file) {
            $this->assertStringNotContainsString('Secret', (string) file_get_contents($file), 'never stored in the page cache');
        }

        $again = $this->site()->client('replay');
        $this->assertSame(400, $again->post('/member/verify', ['token' => $token])->status, 'the link works once');
        $this->assertSame(400, $again->get($link)->status, 'a used link is shown as expired');
        $this->assertSame([], $again->cookies());
    }

    public function testAMemberOfAnotherGroupIsRefused(): void
    {
        $anna = $this->who('anna');
        $answer = $anna->get('/staff-handbook');
        $this->assertSame(403, $answer->status, 'wrong group');
        $this->assertStringNotContainsString('Secret staff rota', $answer->body);
        $this->assertStringContainsString('No access', $answer->body);
    }

    public function testChangingGroupsAndRemovingAMemberTakeEffectAtOnce(): void
    {
        $anna = $this->who('anna');
        $memberId = (string) $this->site()->value("SELECT public_id FROM tl_members WHERE email = 'anna@example.org'");

        $this->adminCall('save_member', ['member_id' => $memberId, 'name' => 'Anna', 'groups' => [self::$s['staff']]]);
        $this->assertSame(403, $anna->get('/club-prices')->status, 'removed from the group: refused at once');
        $this->assertSame(200, $anna->get('/staff-handbook')->status, 'added to the other: allowed at once');

        $this->adminCall('save_member', ['member_id' => $memberId, 'name' => 'Anna', 'groups' => [self::$s['clients']]]);
        $this->assertSame(200, $anna->get('/club-prices')->status);

        $this->adminCall('remove', ['member_id' => $memberId]);
        $this->assertSame(403, $anna->get('/club-prices')->status, 'a removed member is signed out at once');
        $this->assertSame('0', (string) $this->site()->value('SELECT COUNT(*) FROM tl_member_sessions'), 'her sessions are gone');
    }

    public function testSignOutEndsTheSession(): void
    {
        $this->adminCall('invite', ['email' => 'ben@example.org', 'name' => 'Ben', 'groups' => [self::$s['clients']]]);
        $ben = $this->signIn('ben@example.org', 'ben');
        $this->assertSame(200, $ben->get('/club-prices')->status);
        $account = $ben->get('/member');
        $this->assertStringContainsString('ben@example.org', $account->body, 'the account page');
        $this->assertSame(303, $ben->post('/member/signout', ['_csrf' => 'wrong'])->status);
        $this->assertSame(200, $ben->get('/club-prices')->status, 'a wrong token does not sign out');
        $csrf = (string) preg_replace('/^.*name="_csrf" value="([a-f0-9]{64})".*$/s', '$1', $account->body);
        $ben->post('/member/signout', ['_csrf' => $csrf]);
        $this->assertSame(403, $ben->get('/club-prices')->status, 'signed out');
        $this->assertSame('0', (string) $this->site()->value("SELECT COUNT(*) FROM tl_member_sessions WHERE member_id = (SELECT member_id FROM tl_members WHERE email = 'ben@example.org')"));
    }

    public function testAnExpiredLinkDoesNotWork(): void
    {
        $this->adminCall('invite', ['email' => 'cara@example.org', 'name' => 'Cara', 'groups' => []]);
        $link = $this->linkTo('cara@example.org');
        $this->site()->exec("UPDATE tl_member_tokens SET expires_at = NOW() - INTERVAL 1 MINUTE WHERE member_id = (SELECT member_id FROM tl_members WHERE email = 'cara@example.org')");
        $cara = $this->site()->client('cara');
        $this->assertSame(400, $cara->get($link)->status, 'expired');
        $this->assertSame(400, $cara->post('/member/verify', ['token' => (string) preg_replace('/^.*token=([a-f0-9]{64}).*$/', '$1', $link)])->status);
        $this->assertSame([], $cara->cookies());
    }

    public function testRequestsAreAnsweredTheSameAndLimited(): void
    {
        @array_map('unlink', glob($this->site()->path('storage/cache/limits/*')) ?: []);
        $visitor = $this->site()->client('requester');
        $known = $visitor->post('/member', ['email' => 'ben@example.org', 'website' => '']);
        $unknown = $visitor->post('/member', ['email' => 'nobody@example.org', 'website' => '']);
        $this->assertSame($known->status, $unknown->status, 'the answer does not reveal who is a member');
        $this->assertStringContainsString('Check your e-mail', $unknown->body);
        sleep(1);
        $this->assertSame(0, $this->mailCountTo('nobody@example.org'), 'nothing is sent to a stranger when sign-up is by invitation');
        $this->assertSame('0', (string) $this->site()->value("SELECT COUNT(*) FROM tl_members WHERE email = 'nobody@example.org'"), 'and no account is made');

        $before = $this->mailCountTo('ben@example.org');
        for ($i = 0; $i < 4; $i++) {
            $visitor->post('/member', ['email' => 'ben@example.org', 'website' => '']);
        }
        sleep(1);
        $this->assertLessThanOrEqual(3, $this->mailCountTo('ben@example.org') - $before, 'at most three mails an hour to one address');

        $status = 0;
        for ($i = 0; $i < 12 && $status !== 429; $i++) {
            $status = $visitor->post('/member', ['email' => "spray$i@example.org", 'website' => ''])->status;
        }
        $this->assertSame(429, $status, 'link requests from one address are limited');
        $this->assertSame(200, $this->site()->client('other-visitor-after-limit')->get('/member')->status);
    }

    public function testOpenSignUpCreatesAnAccountThatTheLinkConfirms(): void
    {
        @array_map('unlink', glob($this->site()->path('storage/cache/limits/*')) ?: []);
        $this->site()->setting('member_signup', 'open');
        $visitor = $this->site()->client('newcomer');
        $visitor->post('/member', ['email' => 'dana@example.org', 'website' => '']);
        $this->assertSame('1', (string) $this->site()->value("SELECT COUNT(*) FROM tl_members WHERE email = 'dana@example.org' AND confirmed_at IS NULL"), 'an unconfirmed account');
        $link = $this->linkTo('dana@example.org');
        $this->assertSame([], $visitor->cookies());
        $token = (string) preg_replace('/^.*token=([a-f0-9]{64}).*$/', '$1', $link);
        $this->assertSame(303, $visitor->post('/member/verify', ['token' => $token])->status);
        $this->assertSame(200, $visitor->get('/member')->status);
        $this->assertSame('1', (string) $this->site()->value("SELECT COUNT(*) FROM tl_members WHERE email = 'dana@example.org' AND confirmed_at IS NOT NULL"), 'confirmed by the link');
        $this->assertSame(403, $visitor->get('/club-prices')->status, 'a new member belongs to no group');
        $this->site()->setting('member_signup', 'invited');
    }

    public function testClaudeManagesGroupsAndGatingButSeesNoAddresses(): void
    {
        $site = $this->site();
        $groups = $site->mcpResult('list_member_groups');
        $this->assertSame(['Clients', 'Staff'], array_column($groups['groups'], 'name'));
        $this->assertDoesNotMatchRegularExpression('/@example\.org/', (string) json_encode($groups), 'no member address');
        $page = $site->mcpResult('get_page', ['id' => $site->publicId('pages', (int) $site->value("SELECT page_id FROM tl_pages WHERE slug = 'club-prices'"))]);
        $this->assertSame(['Clients'], $page['member_groups']);

        $refused = $site->mcp('delete_member_group', ['id' => self::$s['clients']]);
        $this->assertStringContainsString('still restricts', (string) json_encode($refused), 'a group that gates content cannot be deleted – it would make the content public');

        $made = $site->mcpResult('save_member_group', ['name' => 'Temporary']);
        $this->assertSame('Temporary', $made['group']['name']);
        $site->mcpResult('delete_member_group', ['id' => $made['group']['id']]);
        $this->assertSame('2', (string) $site->value('SELECT COUNT(*) FROM tl_member_groups'));
    }

    public function testPersonalDataToolsFindExportAndEraseTheMember(): void
    {
        $find = $this->adminPost('/admin.php?module=enquiries&action=personal', ['email' => 'ben@example.org', 'bulk' => 'export'], formPage: '/admin.php?module=enquiries&action=personal');
        $json = $find->json();
        $this->assertSame('ben@example.org', $json['member']['email'] ?? '', 'the export has the member account');
        $this->assertSame(['Clients'], $json['member']['groups'] ?? [], 'with the groups');
        $this->adminPost('/admin.php?module=enquiries&action=personal', ['email' => 'ben@example.org', 'bulk' => 'erase', 'confirmed_at' => '1'], formPage: '/admin.php?module=enquiries&action=personal');
        $this->assertSame('0', (string) $this->site()->value("SELECT COUNT(*) FROM tl_members WHERE email = 'ben@example.org'"), 'erased');
    }

    public function testTheMemberMenuShowsTheStateOfTheVisitor(): void
    {
        $site = $this->site();
        $page = $site->mcpResult('create_page', ['title' => 'Menu test', 'slug' => 'menu-test', 'content' => '<p>x</p>', 'visible' => true]);
        $site->mcpResult('save_build', ['id' => $page['id'], 'publish' => true, 'build' => ['v' => 1, 'children' => [['type' => 'section', 'children' => [['type' => 'member_menu']]]]]]);
        $site->clearPageCache();
        $this->assertStringContainsString('class="tl-member-menu', $site->client('menu-anon')->get('/menu-test')->body, 'the menu is on the page');
        $this->assertStringContainsString('href="' . '/member"', $site->client('menu-anon')->get('/menu-test')->body, 'a visitor sees the sign-in link');
        $this->adminCall('invite', ['email' => 'eva@example.org', 'name' => 'Eva Member', 'groups' => []]);
        $eva = $this->signIn('eva@example.org', 'eva');
        $body = $eva->get('/menu-test')->body;
        $this->assertStringContainsString('Eva Member', $body, 'a member sees the name');
        $this->assertStringContainsString('/member/signout', $body, 'and the sign-out button');
    }
}
