<?php

declare(strict_types=1);

namespace Kaleta\Tests\Site\MediaMenuMail;

use Kaleta\Tests\Site\Support\SiteTestCase;
use PHPUnit\Framework\Attributes\Group;

/** Media, redirects, users and roles, custom fonts, statistics switch (was: section 28 of tools/test.sh). */
#[Group('site')]
final class MediaUsersTest extends SiteTestCase
{
    use Helpers;

    public function testSvgIsUploadedAndSanitised(): void
    {
        $svg = $this->site()->workDir('files') . '/logo.svg';
        file_put_contents($svg, '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 10" onload="alert(1)"><script>alert(2)</script><rect width="20" height="10" fill="red"/></svg>');
        $this->uploadMedia($this->makeJpeg('foto.jpg', 1600, 900, [200, 80, 40]));
        $this->uploadMedia($svg);

        $path = (string) $this->site()->value("SELECT image_path FROM ka_media WHERE image_path LIKE '%.svg' ORDER BY media_id DESC LIMIT 1");
        $this->assertNotSame('', $path, 'SVG is in the media library');
        $content = (string) file_get_contents($this->site()->path($path));
        $this->assertStringNotContainsString('onload', $content, 'SVG: the event handler is gone');
        $this->assertStringNotContainsString('<script', $content, 'SVG: the script is gone');
        $this->assertStringContainsString('<rect', $content, 'SVG: the picture stays');
    }

    public function testFileOverTheServerLimitGetsAClearMessage(): void
    {
        $bytes = static fn (string $v): int => (int) $v * (['k' => 1024, 'm' => 1048576, 'g' => 1073741824][strtolower(substr(trim($v), -1))] ?? 1);
        $upload = $bytes((string) ini_get('upload_max_filesize'));
        $post = $bytes((string) ini_get('post_max_size'));
        if ($upload <= 0 || $upload + 4096 >= $post) {
            $this->markTestSkipped('upload_max_filesize is not below post_max_size (the old script skipped this check too).');
        }
        $file = $this->site()->workDir('files') . '/velky.zip';
        file_put_contents($file, str_repeat("\0", $upload + 1024));
        $csrf = $this->site()->admin()->get('/admin.php?module=media')->csrf();

        $response = $this->site()->admin()->upload('/admin.php?module=media&action=upload&format=json', ['_csrf' => $csrf], ['files[]' => $file]);

        $this->assertMatchesRegularExpression('/nejvýš [0-9,]* MB/u', $response->body, 'a file over the limit: the message names the limit in MB');
    }

    public function testReplaceKeepsTheAddressAndFocusIsSaved(): void
    {
        $ido = (int) $this->site()->value("SELECT media_id FROM ka_media WHERE image_path LIKE '%.jpg' ORDER BY media_id DESC LIMIT 1");
        $photo = (string) $this->site()->value('SELECT image_path FROM ka_media WHERE media_id = ?', [$ido]);
        $this->assertSame('', (string) $this->site()->value('SELECT name FROM ka_media WHERE media_id = ?', [$ido]), 'an uploaded image gets no alt text from its file name');

        $csrf = $this->site()->admin()->get('/admin.php?module=media')->csrf();
        $this->site()->admin()->upload('/admin.php?module=media&action=replace', ['_csrf' => $csrf, 'media_id' => (string) $ido], ['file' => $this->makeJpeg('nova.jpg', 800, 800, [20, 120, 200])]);
        $this->assertSame("$photo 800x800", $this->site()->value("SELECT CONCAT(image_path, ' ', image_width, 'x', image_height) FROM ka_media WHERE media_id = ?", [$ido]), 'replacing a file keeps the address and changes the size');

        $this->adminPost('/admin.php?module=media&action=save', ['media_id' => $ido, 'name' => 'Foto', 'focus_x' => 20, 'focus_y' => 80], '/admin.php?module=media');
        $this->assertSame('20% 80%', $this->site()->value('SELECT focal_point FROM ka_media WHERE media_id = ?', [$ido]), 'the crop focus point');
    }

    public function testRedirectsAndChangelog(): void
    {
        $this->adminPost('/admin.php?module=redirects&action=save', ['from_path' => '/akce-leto', 'to_path' => '/kontakty', 'type' => 302], '/admin.php?module=redirects');
        $this->assertSame(302, $this->site()->client()->get('/akce-leto')->status, 'a temporary redirect answers 302');
        $this->assertPage('/admin.php?module=redirects&search=akce-leto', 200, 'akce-leto', message: 'searching the redirects');
        $this->assertPage('/admin.php?module=changelog&area=stranky', 200, 'Protokol', message: 'changelog with a filter');
    }

    public function testInvitedUserAndCustomRole(): void
    {
        $this->adminPost('/admin.php?module=users&action=save', ['user_id' => 0, 'username' => 'pozvany', 'email' => 'pozvany@example.cz', 'admin' => 2, 'invite' => 1], '/admin.php?module=users');
        $this->assertSame('1', (string) $this->site()->value("SELECT reset_token_hash <> '' AND reset_sent_at > NOW() FROM ka_users WHERE username = 'pozvany'"), 'an invited user has a link to set the password with a longer validity');

        $this->adminPost('/admin.php?module=roles&action=save', ['role_id' => 0, 'name' => 'Obchodník', 'level' => 0, 'modules' => ['enquiries', 'collections']], '/admin.php?module=roles');
        $idr = (int) $this->site()->value('SELECT MAX(role_id) FROM ka_role');
        $this->adminPost('/admin.php?module=users&action=save', ['user_id' => 0, 'username' => 'obchodnik', 'password' => $this->site()->password, 'admin' => "r$idr"], '/admin.php?module=users');
        $this->assertSame('collections,enquiries', $this->site()->value("SELECT GROUP_CONCAT(p.module ORDER BY p.module) FROM ka_users u JOIN ka_user_permissions p ON p.user_id = u.user_id WHERE u.username = 'obchodnik' AND u.role = ?", [$idr]), 'a custom role gives the user its sections');

        $this->adminPost('/admin.php?module=roles&action=save', ['role_id' => $idr, 'name' => 'Obchodník', 'level' => 1, 'modules' => ['enquiries']], '/admin.php?module=roles');
        $this->assertSame('1:enquiries', $this->site()->value("SELECT CONCAT(u.admin, ':', GROUP_CONCAT(p.module)) FROM ka_users u JOIN ka_user_permissions p ON p.user_id = u.user_id WHERE u.username = 'obchodnik' GROUP BY u.user_id"), 'a change of the role reaches its members');
        $this->assertPage('/admin.php?module=roles', 200, 'Obchodník', message: 'role overview');
        $this->assertPage('/admin.php?module=users', 200, 'Obchodník', message: 'users show the custom role');
    }

    public function testRequiredTwoFactorLetsOnlyMyAccountThrough(): void
    {
        $this->site()->setting('require_2fa', 'admins');
        $response = $this->site()->admin()->get('/admin.php?module=pages');
        $this->site()->setting('require_2fa', '');

        $this->assertSame(302, $response->status, 'required 2FA redirects');
        $this->assertStringContainsString('action=account', $response->redirect, 'required two-factor sign-in lets only My account through');
    }

    public function testTurningOnTwoFactorShowsQrCodeAndKey(): void
    {
        $this->adminPost('/admin.php?action=account', ['op' => 'totp_start'], '/admin.php?action=account');
        $page = $this->site()->admin()->get('/admin.php?action=account');

        $this->assertStringContainsString('<svg class="qr"', $page->body, 'the QR code');
        $this->assertStringContainsString('class="totp-key"', $page->body, 'the key for manual entry');
    }

    public function testCustomFontFromMedia(): void
    {
        $this->site()->exec("UPDATE ka_settings SET value = JSON_SET(IF(value = '' OR value IS NULL, '{}', value), '$.custom_fonts', JSON_ARRAY(JSON_OBJECT('name', 'Znacka Sans', 'file', 'media/2026/01/znacka.woff2', 'bold', '')), '$.font_heading', 'custom-1') WHERE name = 'design_system'");
        $this->site()->clearPageCache();

        $page = $this->site()->client()->get('/kontakt');

        $this->assertStringContainsString('@font-face { font-family: "Znacka Sans"; src: url("/media/2026/01/znacka.woff2")', $page->body, 'custom font face');
        $this->assertStringContainsString('--ka-font-heading: "Znacka Sans"', $page->body, 'custom font as the heading font');
    }

    public function testStatisticsSwitch(): void
    {
        $this->site()->client()->get('/kontakt');
        $this->assertSame('1', (string) $this->site()->value('SELECT COUNT(*) > 0 FROM ka_stats_pages'), 'statistics by page');

        // a phone number or e-mail anywhere on the page keeps web.js for the click counter while the statistics are on – off, the page does without it
        $this->statsFeature(false);
        $this->assertStringNotContainsString('image/web.js', $this->site()->client()->get('/o-nas')->body, 'web.js only where it is needed (a page without a form)');
        $this->assertPage('/admin.php?module=settings&tab=analytics', 200, [], message: '3.2: Analytics tab with the feature off');
        $off = $this->site()->admin()->get('/admin.php?module=settings&tab=analytics');
        $this->assertMatchesRegularExpression('/Off – nothing is measured|Vypnuto – nic se neměří/u', $off->body, '3.2: with the Statistics feature off the Analytics tab says so and links to Features');

        $this->statsFeature(true);
        $on = $this->assertPage('/admin.php?module=settings&tab=analytics', 200, 'admin.php?module=extensions', message: '3.2: the Analytics tab links to Features');
        $this->assertStringNotContainsString('name="stats"', $on->body, '3.2: one switch for the statistics – no second checkbox');
    }

    /** The Statistics feature is the only switch of the built-in statistics; cached pages go with it. */
    private function statsFeature(bool $on): void
    {
        $list = array_values(array_filter(explode(',', $this->site()->settingValue('extensions')), static fn (string $e): bool => $e !== '' && $e !== 'stats'));
        $on && $list[] = 'stats';
        $this->site()->setting('extensions', implode(',', $list));
        $this->site()->clearPageCache();
    }
}
