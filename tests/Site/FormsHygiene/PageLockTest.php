<?php

declare(strict_types=1);

namespace Talea\Tests\Site\FormsHygiene;

use Talea\Tests\Site\Support\Http;
use Talea\Tests\Site\Support\SiteTestCase;
use PHPUnit\Framework\Attributes\Group;

/** Password-protected pages (was: section 77,). */
#[Group('site')]
final class PageLockTest extends SiteTestCase
{
    use McpHelpers;

    private static int $page = 0;

    private function secretOn(Http $browser): bool
    {
        return $browser->get('/partner-ceny')->contains('Secret partner price');
    }

    public function testAVisitorSeesThePasswordFormNotTheContent(): void
    {
        self::$page = $this->createPage(['title' => 'Partner prices', 'slug' => 'partner-ceny', 'content' => '<p>Secret partner price 42</p>', 'visible' => true], 'create_page');
        $this->site()->exec('UPDATE tl_pages SET password_hash = ? WHERE page_id = ?', [password_hash('partner-2026', PASSWORD_DEFAULT), self::$page]);
        $this->site()->clearPageCache();

        $response = $this->site()->client('lock-visitor')->get('/partner-ceny');

        $this->assertTrue($response->contains('tl-password-page'), 'page lock: a visitor sees the password form');
        $this->assertFalse($response->contains('Secret partner price'), 'page lock: not the content');
        $this->assertTrue($response->contains('noindex'), 'page lock: the page is noindex');
    }

    public function testAWrongPasswordIsRefusedAndTheRightOneOpensThePageForThisVisitor(): void
    {
        $visitor = $this->site()->client('lock-visitor-2');
        $visitor->get('/partner-ceny');

        $this->assertSame(403, $visitor->post('/partner-ceny', ['tl_page_password' => 'wrong'])->status, 'page lock: a wrong password is refused');
        $this->assertSame(303, $visitor->post('/partner-ceny', ['tl_page_password' => 'partner-2026'])->status, 'page lock: the right password is accepted');
        $this->assertTrue($this->secretOn($visitor), 'page lock: the right password opens the page for this visitor');
        $this->assertFalse($this->secretOn($this->site()->client('lock-other')), 'page lock: another visitor still sees the form');
    }

    public function testPastThePageCapNoPasswordIsChecked(): void
    {
        // past the page's cap of wrong passwords from all addresses no guess gets an answer, the right one included;
        // a reader who opened the page before keeps it
        $reader = $this->site()->client('lock-reader');
        $reader->get('/partner-ceny');
        $reader->post('/partner-ceny', ['tl_page_password' => 'partner-2026']);
        $directory = $this->site()->path('storage/cache/limits');
        @mkdir($directory, 0775, true);
        $files = [];
        foreach ([0, 1] as $n) {
            $files[] = $directory . '/page-lock-all-' . (intdiv(time(), 900) + $n) . '-' . substr(hash('sha256', 'page-' . self::$page), 0, 24);
        }
        foreach ($files as $file) {
            file_put_contents($file, str_repeat('.', 100));
        }

        $visitor = $this->site()->client('lock-visitor-3');
        $visitor->get('/partner-ceny');
        $wrong = $visitor->post('/partner-ceny', ['tl_page_password' => 'wrong-again']);
        $right = $visitor->post('/partner-ceny', ['tl_page_password' => 'partner-2026']);
        $this->assertSame([403, true], [$wrong->status, $wrong->contains('Too many attempts')], 'page lock: past the cap a wrong password is refused as too many attempts');
        $this->assertSame([403, true], [$right->status, $right->contains('Too many attempts')], 'page lock: and the right one the same way');
        $this->assertFalse($this->secretOn($visitor), 'page lock: the page stays closed');
        $this->assertTrue($this->secretOn($reader), 'page lock: a reader who opened it before still sees it');

        array_map('unlink', $files);
    }

    public function testTheWrongPasswordThatReachesTheCapTellsTheOwnerOnce(): void
    {
        $directory = $this->site()->path('storage/cache/limits');
        @mkdir($directory, 0775, true);
        $window = intdiv(time(), 900);
        $file = $directory . '/page-lock-all-' . $window . '-' . substr(hash('sha256', 'page-' . self::$page), 0, 24);
        file_put_contents($file, str_repeat('.', \Talea\Core\PageLock::PAGE_ATTEMPTS - 2));
        $logged = fn (): int => (int) $this->site()->value("SELECT COUNT(*) FROM tl_change_log WHERE action = 'page password paused'");
        $reader = $this->site()->client('lock-cap-0');
        $reader->get('/partner-ceny');
        $reader->post('/partner-ceny', ['tl_page_password' => 'partner-2026']);
        clearstatcache();
        $this->assertSame(\Talea\Core\PageLock::PAGE_ATTEMPTS - 2, filesize($file), 'page lock: the right password gives its place in the page cap back');

        foreach (['lock-cap-1', 'lock-cap-2', 'lock-cap-3'] as $name) {
            $visitor = $this->site()->client($name);
            $visitor->get('/partner-ceny');
            $visitor->post('/partner-ceny', ['tl_page_password' => 'wrong-' . $name]);
        }
        if (intdiv(time(), 900) !== $window) {
            $this->markTestSkipped('the tries fell into two counting windows');
        }

        $this->assertSame(1, $logged(), 'page lock: reaching the cap writes one change-log row, the refused tries after it none');
        @unlink($file);
    }

    public function testALockedPageIsNeverInTheCacheTheSitemapOrTheSearch(): void
    {
        foreach (glob($this->site()->path('storage/cache/pages/*')) ?: [] as $file) {
            $this->assertStringNotContainsString('Secret partner price', (string) file_get_contents($file), 'page lock: not in the page cache (' . basename($file) . ')');
        }
        $visitor = $this->site()->client();
        $this->assertStringNotContainsString('partner-ceny', $visitor->get('/sitemap.xml')->body, 'page lock: not in the sitemap');
        $this->assertStringNotContainsString('Secret partner', $visitor->get('/search?q=partner')->body, 'page lock: not in the site search');
    }

    public function testClaudeSeesThatThePageIsProtectedNeverTheHash(): void
    {
        $text = $this->mcpText('get_page', ['id' => $this->site()->publicId('pages', self::$page)]);
        $raw = $this->mcpRawAnswer('get_page', ['id' => $this->site()->publicId('pages', self::$page)]);

        $this->assertStringContainsString('"password_protected":true', $text, 'page lock: Claude sees that the page is protected');
        $this->assertDoesNotMatchRegularExpression('/heslo_hash|\$2y\$/', $raw, 'page lock: never the hash');
    }

    public function testRemovingThePasswordInTheAdminMakesThePagePublic(): void
    {
        $this->adminPost('/admin.php?module=pages&action=save', ['page_id' => $this->site()->publicId('pages', self::$page), 'title' => 'Partner prices', 'slug' => 'partner-ceny', 'visible' => 1, 'remove_password' => 1, 'text' => '<p>Secret partner price 42</p>'],
            formPage: '/admin.php?module=pages&action=website&tab=publishing&id=' . $this->site()->publicId('pages', self::$page));

        $this->assertSame('1', (string) $this->site()->value('SELECT password_hash IS NULL FROM tl_pages WHERE page_id = ?', [self::$page]), 'page lock: removing the password in the admin clears the hash');
        $this->assertTrue($this->secretOn($this->site()->client('lock-anonymous')), 'page lock: the page is public');
    }
}
