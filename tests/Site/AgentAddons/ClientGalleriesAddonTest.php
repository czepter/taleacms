<?php

declare(strict_types=1);

namespace Talea\Tests\Site\AgentAddons;

use PHPUnit\Framework\Attributes\Group;
use Talea\Tests\Site\Support\Http;
use Talea\Tests\Site\Support\SiteTestCase;

/**
 * Client galleries as a bundled add-on (issue #34): off by default; switched on – a gallery starts closed, is shared by a private address or
 * a member group, the client marks favourites and downloads what the owner allows; expiry, wrong addresses, leaks (cache, sitemap, search,
 * llms.txt, other galleries), the CSV and the tools for Claude. The tests of the class run in order.
 */
#[Group('site')]
final class ClientGalleriesAddonTest extends SiteTestCase
{
    use AgentHelpers;

    private const string PAGE = '/admin.php?module=addons&action=page&p=client_galleries.galleries';

    /** @var array<string, string> */
    private static array $s = [];

    private function toggle(int $on): void
    {
        $this->adminPost('/admin.php?module=addons&action=toggle', ['slug' => 'client_galleries', 'on' => $on] + ($on === 1 ? ['trust' => 1] : []), '/admin.php?module=addons');
    }

    /** @param array<string, mixed> $fields */
    private function admin(array $fields): string
    {
        return $this->adminPost(self::PAGE . '&g=' . (self::$s['gallery'] ?? ''), $fields, self::PAGE)->body;
    }

    private function url(string $rest = ''): string
    {
        return '/gallery/' . self::$s['token'] . ($rest !== '' ? '/' . $rest : '');
    }

    private function refresh(): void
    {
        self::$s['token'] = (string) $this->site()->value('SELECT token FROM tl_ext_cg_galleries WHERE public_id = ?', [self::$s['gallery']]);
    }

    /** A member with a session, made in the database: the cookie to send. */
    private function member(string $email, ?string $groupPublicId): string
    {
        $site = $this->site();
        $site->exec('INSERT INTO tl_members (public_id, email, name, created_at, confirmed_at) VALUES (?, ?, ?, NOW(), NOW())', [$this->uuid(), $email, '']);
        $memberId = (int) $site->value('SELECT member_id FROM tl_members WHERE email = ?', [$email]);
        if ($groupPublicId !== null) {
            $site->exec('INSERT INTO tl_member_group_links (member_id, group_id) VALUES (?, ?)', [$memberId, $site->internalId('member_groups', $groupPublicId)]);
        }
        $cookie = bin2hex(random_bytes(32));
        $site->exec('INSERT INTO tl_member_sessions (member_id, token_hash, created_at, expires_at) VALUES (?, ?, NOW(), NOW() + INTERVAL 1 DAY)', [$memberId, hash('sha256', $cookie)]);

        return 'Cookie: tl_member=' . $cookie;
    }

    private function uuid(): string
    {
        return \Talea\Core\Uuid::v4();
    }

    public function testNothingOfItExistsWhileItIsOff(): void
    {
        $this->assertPage('/admin.php?module=addons', 200, ['Client galleries', 'Official add-on, shipped with Talea'], message: 'the bundled add-on is listed');
        $this->assertSame('0', $this->sq("SELECT COUNT(*) FROM information_schema.tables WHERE table_name = 'tl_ext_cg_galleries'"), 'no tables');
        $this->assertSame(404, $this->site()->client()->get('/gallery/' . str_repeat('a', 32))->status, 'no address');
        $this->assertSame(404, $this->site()->admin()->get(self::PAGE)->status, 'no administration page');
    }

    public function testASwitchedOnGalleryStartsClosedAndTheDemoCreatesPictures(): void
    {
        $this->toggle(1);
        $this->assertSame('1', $this->sq("SELECT COUNT(*) FROM information_schema.tables WHERE table_name = 'tl_ext_cg_galleries'"), 'the migration created the tables');
        $this->assertPage(self::PAGE, 200, ['No galleries yet.', 'Create a demo gallery with generated pictures'], message: 'the administration page');

        $this->adminPost(self::PAGE, ['op' => 'demo'], self::PAGE);
        self::$s['gallery'] = (string) $this->site()->value('SELECT public_id FROM tl_ext_cg_galleries ORDER BY id LIMIT 1');
        $this->refresh();
        $this->assertSame('12', $this->sq('SELECT COUNT(*) FROM tl_ext_cg_images'), 'twelve generated pictures');
        $this->assertSame('closed', $this->sq('SELECT access FROM tl_ext_cg_galleries'), 'a new gallery is closed');
        $this->assertSame(404, $this->site()->client()->get($this->url())->status, 'a closed gallery does not exist for visitors – even with the right address');

        $first = (string) $this->site()->value('SELECT public_id FROM tl_ext_cg_images ORDER BY sort_order LIMIT 1');
        self::$s['image'] = $first;
        $dir = $this->site()->path('storage/galleries/' . self::$s['gallery']);
        foreach (['.orig.jpg', '.web.jpg', '.thumb.jpg'] as $suffix) {
            $this->assertFileExists($dir . '/' . $first . $suffix, "the file $suffix is in storage/");
        }
    }

    public function testSharedByLinkItShowsTheImagesAndIsNeverCachedOrIndexed(): void
    {
        $this->admin(['op' => 'share', 'access' => 'link']);
        $visitor = $this->site()->client('client');
        $page = $visitor->get($this->url());
        $this->assertSame(200, $page->status);
        $this->assertStringContainsString('Demo gallery', $page->body);
        $this->assertSame(12, substr_count($page->body, 'class="cg-item"'), 'twelve images');
        $this->assertStringContainsString('private, no-store', $page->headers['cache-control'] ?? '', 'not cacheable');
        $this->assertStringContainsString('noindex', $page->headers['x-robots-tag'] ?? '', 'noindex');
        $this->assertStringContainsString('noindex', $page->body, 'noindex in the page too');
        $this->assertSame([], $visitor->cookies(), 'no cookie for a link visitor');
        $thumb = $visitor->get($this->url(self::$s['image'] . '/thumb'));
        $this->assertSame(200, $thumb->status);
        $this->assertStringStartsWith("\xFF\xD8", $thumb->body, 'a JPEG');

        $this->site()->clearPageCache();
        $visitor->get('/');
        foreach (['/sitemap.xml', '/llms.txt', '/rss.xml', '/search?q=demo', '/search?q=gallery', '/'] as $path) {
            $body = $visitor->get($path)->body;
            $this->assertStringNotContainsString(self::$s['token'], $body, "$path does not leak the address");
            $this->assertStringNotContainsString('Demo gallery', $body, "$path does not list the gallery");
        }
        foreach (glob($this->site()->path('storage/cache/pages/*')) ?: [] as $file) {
            $this->assertStringNotContainsString('cg-item', (string) file_get_contents($file), 'never in the page cache');
        }
    }

    public function testWrongAddressesAndImagesOfOtherGalleries(): void
    {
        $visitor = $this->site()->client('guesser');
        $this->assertSame(404, $visitor->get('/gallery/' . str_repeat('0', 32))->status, 'an unknown address');
        $this->assertSame(404, $visitor->get('/gallery/not-a-token')->status);
        $this->assertSame(404, $visitor->get($this->url('00000000-0000-4000-8000-000000000000/thumb'))->status, 'an unknown image');
        $this->assertSame(404, $visitor->get($this->url(self::$s['image'] . '/secret'))->status, 'an unknown size');
        $this->assertSame(404, $visitor->get('/gallery/admin/thumb/' . self::$s['image'])->status, 'the administration route is for the administrator only');

        $this->site()->exec("INSERT INTO tl_ext_cg_galleries (public_id, title, token, access, allow_web, allow_original, allow_zip, created_at) VALUES (?, 'Other', ?, 'link', TRUE, FALSE, FALSE, NOW())", [$this->uuid(), str_repeat('b', 32)]);
        $this->assertSame(404, $visitor->get('/gallery/' . str_repeat('b', 32) . '/' . self::$s['image'] . '/thumb')->status, 'an image of another gallery is not reachable by this address');
    }

    public function testFavouritesAreMarkedPerLinkAndExported(): void
    {
        $visitor = $this->site()->client('client2');
        $answer = $visitor->post($this->url(), ['op' => 'favourite', 'image' => self::$s['image'], 'on' => '1']);
        $this->assertSame(303, $answer->status);
        $visitor->post($this->url(), ['op' => 'favourite', 'image' => self::$s['image'], 'on' => '1']); // twice: one row
        $this->assertSame('1', $this->sq('SELECT COUNT(*) FROM tl_ext_cg_favourites'), 'one favourite, however often it is sent');
        $this->assertStringContainsString('aria-pressed="true"', $visitor->get($this->url())->body, 'shown as marked');

        $this->assertSame(404, $this->site()->client()->get('/gallery/admin/export/' . self::$s['gallery'] . '.csv')->status, 'the CSV is for the administrator');
        $csv = $this->site()->admin()->get('/gallery/admin/export/' . self::$s['gallery'] . '.csv');
        $this->assertSame(200, $csv->status);
        $this->assertStringContainsString('text/csv', $csv->headers['content-type'] ?? '');
        $this->assertStringContainsString(self::$s['image'], $csv->body);
        $this->assertStringContainsString('link', $csv->body);
        $this->assertPage(self::PAGE . '&g=' . self::$s['gallery'], 200, ['Download the favourites as a CSV file', 'Demo picture 01'], message: 'the administration shows them');

        $data = $this->mcpData('ext_client_galleries_favourites', ['gallery' => self::$s['gallery']]);
        $this->assertSame(1, count($data['favourites'] ?? []), 'Claude reads the favourites');
        $this->assertSame(self::$s['image'], $data['favourites'][0]['image_id'] ?? '');

        $visitor->post($this->url(), ['op' => 'favourite', 'image' => self::$s['image'], 'on' => '0']);
        $this->assertSame('0', $this->sq('SELECT COUNT(*) FROM tl_ext_cg_favourites'), 'unmarked');
        $this->site()->admin()->get($this->url()); // the administrator only previews
        $this->site()->admin()->post($this->url(), ['op' => 'favourite', 'image' => self::$s['image'], 'on' => '1']);
        $this->assertSame('0', $this->sq('SELECT COUNT(*) FROM tl_ext_cg_favourites'), 'a preview by the administrator marks nothing');
    }

    public function testDownloadsFollowWhatTheOwnerAllows(): void
    {
        $visitor = $this->site()->client('downloader');
        $this->admin(['op' => 'save', 'title' => 'Demo gallery', 'expires' => '', 'allow_web' => 1, 'allow_original' => 0, 'allow_zip' => 0]);
        $web = $visitor->get($this->url(self::$s['image'] . '/download?size=web'));
        $this->assertSame(200, $web->status, 'web size is allowed by default');
        $this->assertStringContainsString('attachment', $web->headers['content-disposition'] ?? '');
        $this->assertSame(404, $visitor->get($this->url(self::$s['image'] . '/download?size=original'))->status, 'originals are not');
        $this->assertSame(404, $visitor->get($this->url('zip?size=web'))->status, 'no zip');

        $this->admin(['op' => 'save', 'title' => 'Wedding', 'expires' => '', 'allow_web' => 0, 'allow_original' => 1, 'allow_zip' => 1]);
        $this->assertSame(404, $visitor->get($this->url(self::$s['image'] . '/download?size=web'))->status, 'web size switched off');
        $original = $visitor->get($this->url(self::$s['image'] . '/download?size=original'));
        $this->assertSame(200, $original->status);
        $this->assertSame(file_get_contents($this->site()->path('storage/galleries/' . self::$s['gallery'] . '/' . self::$s['image'] . '.orig.jpg')), $original->body, 'the original is delivered untouched');
        $zip = $visitor->get($this->url('zip?size=original'));
        $this->assertSame(200, $zip->status);
        $this->assertStringStartsWith('PK', $zip->body, 'a zip');
        $this->assertSame(404, $visitor->get($this->url('zip?size=web'))->status, 'the zip only in a size that is allowed');
        $this->assertStringContainsString('Wedding', $visitor->get($this->url())->body, 'renamed');
    }

    public function testAnExpiredGalleryStopsWorkingAndANewAddressReplacesTheOld(): void
    {
        $visitor = $this->site()->client('late');
        $this->site()->exec("UPDATE tl_ext_cg_galleries SET expires_at = ? WHERE public_id = ?", [date("Y-m-d H:i:s", time() - 86400), self::$s["gallery"]]);
        $expired = $visitor->get($this->url());
        $this->assertSame(410, $expired->status, 'expired');
        $this->assertStringContainsString('expired', $expired->body);
        $this->assertStringNotContainsString('cg-item', $expired->body);
        $this->assertSame(410, $visitor->get($this->url(self::$s['image'] . '/thumb'))->status === 200 ? 200 : 410, 'no image after the end');
        $this->assertNotSame(200, $visitor->get($this->url(self::$s['image'] . '/download?size=original'))->status, 'no download after the end');

        $this->admin(['op' => 'save', 'title' => 'Wedding', 'expires' => date('Y-m-d', strtotime('+10 days')), 'allow_web' => 1, 'allow_original' => 0, 'allow_zip' => 0]);
        $this->assertSame(200, $visitor->get($this->url())->status, 'a later day opens it again');

        $old = self::$s['token'];
        $this->admin(['op' => 'new_link']);
        $this->refresh();
        $this->assertNotSame($old, self::$s['token']);
        $this->assertSame(404, $visitor->get('/gallery/' . $old)->status, 'the old address is dead');
        $this->assertSame(200, $visitor->get($this->url())->status);
    }

    public function testAMemberGroupOpensTheGalleryOnlyForItsMembers(): void
    {
        $site = $this->site();
        $site->setting('extensions', $site->settingValue('extensions') . ',members');
        $site->exec("INSERT INTO tl_member_groups (public_id, name, created_at) VALUES (?, 'Wedding guests', NOW()), (?, 'Others', NOW())", [$this->uuid(), $this->uuid()]);
        $guests = (string) $site->value("SELECT public_id FROM tl_member_groups WHERE name = 'Wedding guests'");
        $others = (string) $site->value("SELECT public_id FROM tl_member_groups WHERE name = 'Others'");
        $this->admin(['op' => 'share', 'access' => 'group', 'group' => '']);
        $this->assertSame('link', $this->sq('SELECT access FROM tl_ext_cg_galleries WHERE public_id = ?', [self::$s['gallery']]), 'sharing with a group needs a group');
        $this->admin(['op' => 'share', 'access' => 'group', 'group' => $guests]);

        $anonymous = $site->client('anon-g');
        $login = $anonymous->get($this->url());
        $this->assertSame(403, $login->status, 'a visitor is asked to sign in');
        $this->assertStringContainsString('tl-member-email', $login->body);
        $this->assertStringNotContainsString('cg-item', $login->body);
        $this->assertSame(403, $anonymous->get($this->url(self::$s['image'] . '/thumb'))->status, 'and gets no image');

        $outsider = $this->member('outsider@example.org', $others);
        $this->assertSame(403, $site->client('out')->get($this->url(), [$outsider])->status, 'a member of another group is refused');
        $this->assertSame(403, $site->client('out')->get($this->url(self::$s['image'] . '/thumb'), [$outsider])->status);

        $guest = $this->member('guest@example.org', $guests);
        $client = $site->client('guest');
        $page = $client->get($this->url(), [$guest]);
        $this->assertSame(200, $page->status, 'a member of the group sees it');
        $this->assertStringContainsString('private, no-store', $page->headers['cache-control'] ?? '');
        $this->assertSame(200, $client->get($this->url(self::$s['image'] . '/thumb'), [$guest])->status);
        $client->post($this->url(), ['op' => 'favourite', 'image' => self::$s['image'], 'on' => '1'], [$guest]);
        $this->assertSame('1', $this->sq("SELECT COUNT(*) FROM tl_ext_cg_favourites WHERE who LIKE 'm%'"), 'favourites belong to the member');
        $this->assertStringContainsString('guest@example.org', $this->site()->admin()->get('/gallery/admin/export/' . self::$s['gallery'] . '.csv')->body, 'the CSV names the member');

        $this->assertSame(403, $site->client('link-only')->get($this->url())->status, 'the address alone no longer opens it');
    }

    public function testToolsForClaudeNeverHandOutAnAddressUnlessAsked(): void
    {
        $list = $this->mcpRawText('ext_client_galleries_galleries');
        $this->assertStringContainsString(self::$s['gallery'], $list);
        $this->assertStringNotContainsString(self::$s['token'], $list, 'the listing has no address');
        $this->assertStringNotContainsString('/gallery/', $list);

        $created = $this->mcpData('ext_client_galleries_create', ['title' => 'From Claude', 'expires' => '2999-01-31']);
        $this->assertArrayHasKey('access', $created, $this->mcpRawText('ext_client_galleries_create', ['title' => 'x']));
        $this->assertSame('closed', $created['access'] ?? '', 'created closed');
        $this->assertStringNotContainsString('/gallery/', json_encode($created), 'no address in the answer');

        $shared = $this->mcpData('ext_client_galleries_share', ['gallery' => $created['id'], 'access' => 'link']);
        $this->assertStringContainsString('/gallery/', (string) ($shared['address'] ?? ''), 'only "share" returns the address');
        $this->assertSame(200, $this->site()->client()->get((string) parse_url((string) $shared['address'], PHP_URL_PATH))->status, 'and it works');
        $closed = $this->mcpData('ext_client_galleries_share', ['gallery' => $created['id'], 'access' => 'closed']);
        $this->assertTrue(array_key_exists('address', $closed) && $closed['address'] === null, 'closing returns none');
        $this->assertStringContainsString('error', strtolower($this->mcpRawText('ext_client_galleries_share', ['gallery' => $created['id'], 'access' => 'group', 'group' => 'nope'])), 'a missing group is refused');
    }

    public function testUploadingAnImageAndDeleting(): void
    {
        $file = $this->site()->workDir('cg-upload') . '/Sunset_01.jpg';
        $im = imagecreatetruecolor(300, 200);
        imagejpeg($im, $file, 90);
        $admin = $this->site()->admin();
        $csrf = $admin->get(self::PAGE . '&g=' . self::$s['gallery'])->csrf();
        $answer = $admin->upload(self::PAGE . '&g=' . self::$s['gallery'], ['_csrf' => $csrf, 'op' => 'upload', 'g' => self::$s['gallery']], ['images[0]' => [$file, 'image/jpeg', 'Sunset_01.jpg']]);
        $this->assertStringContainsString('1 image(s) were added.', $answer->body);
        $this->assertSame('Sunset 01', $this->sq("SELECT name FROM tl_ext_cg_images WHERE name = 'Sunset 01'"), 'the name comes from the file name');

        $bad = $this->site()->workDir('cg-upload') . '/note.jpg';
        file_put_contents($bad, '<?php echo 1;');
        $csrf = $admin->get(self::PAGE)->csrf();
        $answer = $admin->upload(self::PAGE . '&g=' . self::$s['gallery'], ['_csrf' => $csrf, 'op' => 'upload', 'g' => self::$s['gallery']], ['images[0]' => [$bad, 'image/jpeg', 'note.jpg']]);
        $this->assertStringContainsString('Only JPG, PNG and WebP images are allowed.', $answer->body, 'what is not a picture is refused');

        $dir = $this->site()->path('storage/galleries/' . self::$s['gallery']);
        $this->admin(['op' => 'delete']);
        $this->assertSame('0', $this->sq('SELECT COUNT(*) FROM tl_ext_cg_galleries WHERE public_id = ?', [self::$s['gallery']]));
        $this->assertSame('0', $this->sq('SELECT COUNT(*) FROM tl_ext_cg_images WHERE gallery_id NOT IN (SELECT id FROM tl_ext_cg_galleries)'), 'its images and favourites are gone');
        $this->assertDirectoryDoesNotExist($dir, 'and its files');
    }

    public function testTooManyWrongAddressesAreTurnedAway(): void
    {
        $visitor = $this->site()->client('scanner');
        $codes = [];
        for ($i = 0; $i < 36; $i++) {
            $codes[] = $visitor->get('/gallery/' . bin2hex(random_bytes(16)))->status;
        }
        $this->assertContains(429, $codes, 'guessing addresses is limited');
    }
}
