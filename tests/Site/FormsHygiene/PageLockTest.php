<?php

declare(strict_types=1);

namespace Kaleta\Tests\Site\FormsHygiene;

use Kaleta\Tests\Site\Support\Http;
use Kaleta\Tests\Site\Support\SiteTestCase;
use PHPUnit\Framework\Attributes\Group;

/** Password-protected pages (was: section 77, "2.14"). */
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
        $this->site()->exec('UPDATE ka_pages SET password_hash = ? WHERE page_id = ?', [password_hash('partner-2026', PASSWORD_DEFAULT), self::$page]);
        $this->site()->clearPageCache();

        $response = $this->site()->client('lock-visitor')->get('/partner-ceny');

        $this->assertTrue($response->contains('ka-password-page'), 'page lock: a visitor sees the password form');
        $this->assertFalse($response->contains('Secret partner price'), 'page lock: not the content');
        $this->assertTrue($response->contains('noindex'), 'page lock: the page is noindex');
    }

    public function testAWrongPasswordIsRefusedAndTheRightOneOpensThePageForThisVisitor(): void
    {
        $visitor = $this->site()->client('lock-visitor-2');
        $visitor->get('/partner-ceny');

        $this->assertSame(403, $visitor->post('/partner-ceny', ['ka_page_password' => 'wrong'])->status, 'page lock: a wrong password is refused');
        $this->assertSame(303, $visitor->post('/partner-ceny', ['ka_page_password' => 'partner-2026'])->status, 'page lock: the right password is accepted');
        $this->assertTrue($this->secretOn($visitor), 'page lock: the right password opens the page for this visitor');
        $this->assertFalse($this->secretOn($this->site()->client('lock-other')), 'page lock: another visitor still sees the form');
    }

    public function testPastThePageCapOnlyWrongPasswordsAreRefused(): void
    {
        // 3.3.3 (N58): past the page's cap of wrong passwords from all addresses only wrong ones are refused – the right one still opens
        $directory = $this->site()->path('storage/cache/firewall');
        @mkdir($directory, 0775, true);
        $files = [];
        foreach ([0, 1] as $n) {
            $files[] = $directory . '/page-lock-all-' . (intdiv(time(), 900) + $n) . '-' . substr(hash('sha256', 'page-' . self::$page), 0, 24);
        }
        foreach ($files as $file) {
            file_put_contents($file, str_repeat('.', 120));
        }

        $visitor = $this->site()->client('lock-visitor-3');
        $visitor->get('/partner-ceny');
        $wrong = $visitor->post('/partner-ceny', ['ka_page_password' => 'wrong-again']);
        $this->assertSame(403, $wrong->status, 'page lock: past the cap a wrong password is refused');
        $this->assertTrue($wrong->contains('Příliš mnoho pokusů'), 'page lock: as too many attempts');
        $this->assertSame(303, $visitor->post('/partner-ceny', ['ka_page_password' => 'partner-2026'])->status, 'page lock: the right password still passes');
        $this->assertTrue($this->secretOn($visitor), 'page lock: and opens the page');

        array_map('unlink', $files);
    }

    public function testALockedPageIsNeverInTheCacheTheSitemapOrTheSearch(): void
    {
        foreach (glob($this->site()->path('storage/cache/stranky/*')) ?: [] as $file) {
            $this->assertStringNotContainsString('Secret partner price', (string) file_get_contents($file), 'page lock: not in the page cache (' . basename($file) . ')');
        }
        $visitor = $this->site()->client();
        $this->assertStringNotContainsString('partner-ceny', $visitor->get('/sitemap.xml')->body, 'page lock: not in the sitemap');
        $this->assertStringNotContainsString('Secret partner', $visitor->get('/search?q=partner')->body, 'page lock: not in the site search');
    }

    public function testClaudeSeesThatThePageIsProtectedNeverTheHash(): void
    {
        $text = $this->mcpText('get_page', ['id' => self::$page]);
        $raw = $this->mcpRawAnswer('get_page', ['id' => self::$page]);

        $this->assertStringContainsString('"password_protected":true', $text, 'page lock: Claude sees that the page is protected');
        $this->assertDoesNotMatchRegularExpression('/heslo_hash|\$2y\$/', $raw, 'page lock: never the hash');
    }

    public function testRemovingThePasswordInTheAdminMakesThePagePublic(): void
    {
        $this->site()->admin()->get('/admin.php?module=pages&action=edit&id=' . self::$page);
        $this->adminPost('/admin.php?module=pages&action=save', ['page_id' => self::$page, 'title' => 'Partner prices', 'slug' => 'partner-ceny', 'visible' => 1, 'remove_password' => 1, 'text' => '<p>Secret partner price 42</p>'],
            formPage: '/admin.php?module=pages&action=edit&id=' . self::$page);

        $this->assertSame('1', (string) $this->site()->value('SELECT password_hash IS NULL FROM ka_pages WHERE page_id = ?', [self::$page]), 'page lock: removing the password in the admin clears the hash');
        $this->assertTrue($this->secretOn($this->site()->client('lock-anonymous')), 'page lock: the page is public');
    }
}
