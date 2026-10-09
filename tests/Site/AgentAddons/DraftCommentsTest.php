<?php

declare(strict_types=1);

namespace Kaleta\Tests\Site\AgentAddons;

use Kaleta\Tests\Site\Support\SiteTestCase;
use PHPUnit\Framework\Attributes\Group;

/** Comments on drafts (was: section 90, 2.15): a shared preview link, anonymous comments, e-mail, MCP, the builder. The tests run in order. */
#[Group('site')]
final class DraftCommentsTest extends SiteTestCase
{
    use AgentHelpers;

    private static int $page = 0;
    private static int $otherPage = 0;
    private static string $link = '';
    private static string $key = '';
    private static string $plainKey = '';
    private static string $element = '';
    private static int $commentId = 0;
    /** @var resource|null */
    private static $smtp = null;
    private static string $mailDir = '';

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$smtp)) {
            proc_terminate(self::$smtp);
            proc_close(self::$smtp);
        }
        self::$smtp = null;
        parent::tearDownAfterClass();
    }

    /** The old dc_post: an anonymous POST to /_komentar for the draft page; returns "status redirect". @param array<string, string> $fields */
    private function comment(array $fields, string $target = ''): string
    {
        $response = $this->site()->client('commenter')->post('/_komentar', ['cil' => $target !== '' ? $target : 'stranka:' . self::$page] + $fields);

        return $response->status . ' ' . $response->redirect;
    }

    /** The old dc_share: share the draft of the page as the administrator (the JSON answer). @param array<string, mixed> $fields */
    private function share(array $fields = []): \Kaleta\Tests\Site\Support\Response
    {
        $csrf = $this->site()->admin()->get('/admin.php?module=pages')->csrf();

        return $this->site()->admin()->post('/admin.php?module=pages&action=build_share&id=' . self::$page, ['_csrf' => $csrf, 'dni' => 1] + $fields);
    }

    /** Subject and decoded text of a captured message (the old eml/dc_body). */
    private function decodeMail(string $file): string
    {
        $mail = (string) file_get_contents($file);
        [$headers, $body] = array_pad(explode("\r\n\r\n", $mail, 2), 2, '');
        preg_match('/^Subject: (.*)$/m', $headers, $subject);
        $text = $headers . "\nSubject-Decoded: " . mb_decode_mimeheader(trim($subject[1] ?? '')) . "\n";
        $text .= base64_decode((string) preg_replace('/\s+/', '', $body)) . "\n";

        return $text;
    }

    public function testTheBuilderOfAPageCarriesTheCommentsPanelData(): void
    {
        $port = $this->site()->freePort();
        self::$mailDir = $this->site()->workDir('smtp-dc');
        self::$smtp = proc_open([PHP_BINARY, dirname(__DIR__, 3) . '/tools/fake-smtp.php', (string) $port, self::$mailDir], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        for ($i = 0; $i < 100 && @fsockopen('127.0.0.1', $port) === false; $i++) {
            usleep(50_000);
        }
        $this->site()->exec("REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('mail_mode', 'smtp'), ('smtp_host', '127.0.0.1'), ('smtp_port', ?), ('smtp_encryption', 'zadne'), ('smtp_user', ''), ('mail_from', 'web@example.cz')", [(string) $port]);
        $this->site()->exec("UPDATE ka_uzivatele SET email = 'editor@example.cz', jazyk = '' WHERE user = 'admin'");

        self::$page = $this->firstId($this->mcpText('vytvor_stranku', ['titulek' => 'Comment draft', 'adresa' => 'komentar-koncept', 'text' => '<p>Draft paragraph to comment on</p>', 'zobrazit' => false]));
        self::$otherPage = $this->firstId($this->mcpText('vytvor_stranku', ['titulek' => 'Another page', 'zobrazit' => false]));
        $this->assertGreaterThan(0, self::$page);

        $builder = $this->assertPage('/admin.php?module=pages&action=builder&id=' . self::$page);
        $this->assertStringContainsString('"komentare":[]', $builder->body, 'comments: the builder carries the (empty) comments panel data');
        $this->assertStringContainsString('"komentarVyrizen":', $builder->body, 'comments: and the resolve address');
    }

    public function testSharingWithCommentsSignsTheFlagIntoTheKey(): void
    {
        $response = $this->share(['komentare' => 1]);
        self::$link = (string) (json_decode($response->body)->odkaz ?? '');
        self::$key = substr(self::$link, (int) strpos(self::$link, 'preview_key=') + strlen('preview_key='));
        $this->assertSame(200, $response->status, 'comments: build_share answers');
        $this->assertStringContainsString('"komentare":true', $response->body, 'comments: the answer says comments are allowed');
        $this->assertStringContainsString('k.', self::$key, 'comments: the flag is signed into the key');

        $plain = $this->share();
        $plainLink = (string) (json_decode($plain->body)->odkaz ?? '');
        self::$plainKey = substr($plainLink, (int) strpos($plainLink, 'preview_key=') + strlen('preview_key='));
        $this->assertNotSame('', self::$plainKey, 'a plain preview link is made too');
    }

    public function testAVisitorWithTheLinkSeesTheDraftAndTheCommentWidget(): void
    {
        $visitor = $this->site()->client('visitor');
        $preview = $visitor->get(self::$link);
        preg_match('/data-ka-id="([^"]*)"/', $preview->body, $m);
        self::$element = $m[1] ?? '';

        $this->assertStringContainsString('data-ka-komentare', $preview->body, 'comments: the comment widget');
        $this->assertStringContainsString('Draft paragraph to comment on', $preview->body, 'comments: the draft');
        $this->assertStringContainsString('noindex', $preview->body, 'comments: noindex');
        $this->assertNotSame('', self::$element, 'comments: element ids');
        $this->assertStringNotContainsString('data-ka-typ', $preview->body, 'comments: not the editor markers');

        $plain = $visitor->get('/komentar-koncept?build=koncept&preview_key=' . self::$plainKey);
        $this->assertStringContainsString('Draft paragraph to comment on', $plain->body, 'comments: a plain preview link shows the draft');
        $this->assertStringNotContainsString('data-ka-komentare', $plain->body, 'comments: a plain preview link has no widget');
        $this->assertStringNotContainsString('data-ka-id', $plain->body, 'comments: a plain preview link has no element ids');
    }

    public function testAnAnonymousVisitorWithTheKeyPostsAComment(): void
    {
        $base = $this->site()->base;
        $result = $this->comment(['klic' => self::$key, 'prvek' => self::$element, 'zpet' => '/komentar-koncept?build=koncept&preview_key=' . self::$key, 'citace' => 'Draft paragraph', 'jmeno' => 'Client <b>Novak</b>', 'text' => 'Please <b>fix</b> this paragraph – it is  too long.']);
        $this->assertSame('303 ' . $base . '/komentar-koncept?build=koncept&preview_key=' . self::$key . '&comment=ok#ka-komentar', $result, 'comments: an anonymous visitor with the key posts a comment and comes back to the preview');

        $this->assertSame(
            'Client Novak|Please fix this paragraph – it is too long.|' . self::$element . '|Draft paragraph|1',
            $this->sq("SELECT CONCAT_WS('|', name, text, element, quote, resolved_at IS NULL) FROM ka_draft_comments WHERE target = ?", ['stranka:' . self::$page]),
            'comments: stored as plain text with the element, the quote and the name',
        );
    }

    public function testInvalidRequestsStoreNothing(): void
    {
        $invalid = $this->comment(['klic' => '1999999999k.' . str_repeat('a', 64), 'jmeno' => 'X', 'text' => 'Y']);
        $plain = $this->comment(['klic' => self::$plainKey, 'jmeno' => 'X', 'text' => 'Y']);
        $wrongTarget = $this->comment(['klic' => self::$key, 'jmeno' => 'X', 'text' => 'Y'], 'stranka:999999');
        $this->assertSame('403|403|403', substr($invalid, 0, 3) . '|' . substr($plain, 0, 3) . '|' . substr($wrongTarget, 0, 3), 'comments: an invalid key, a plain key and a wrong target are refused');

        $noName = $this->comment(['klic' => self::$key, 'jmeno' => '', 'text' => 'Hello']);
        $this->assertSame('chyba|1', (string) preg_replace('/#.*/', '', (string) preg_replace('/.*comment=/', '', $noName)) . '|' . $this->sq('SELECT COUNT(*) FROM ka_draft_comments'), 'comments: without a name or a text nothing is stored');
    }

    public function testTheAdministratorGetsAnEmailAndSeesTheBadge(): void
    {
        $mail = '';
        for ($i = 0; $i < 25 && $mail === ''; $i++) {
            foreach (glob(self::$mailDir . '/*.eml') ?: [] as $file) {
                if (str_contains((string) file_get_contents($file), 'X-Rcpt-To: editor@example.cz')) {
                    $mail = $file;
                }
            }
            if ($mail === '') {
                usleep(200_000);
            }
        }
        $this->assertNotSame('', $mail, 'comments: the administrator gets a message');
        $text = $this->decodeMail($mail);
        $this->assertStringContainsString('Nový komentář ke konceptu „Comment draft“', $text, 'comments: the e-mail subject');
        $this->assertStringContainsString('Client Novak', $text, 'comments: the e-mail names the commenter');
        $this->assertStringContainsString('module=pages&action=builder&id=' . self::$page, $text, 'comments: the e-mail links the builder');

        $this->assertPage('/admin.php?module=pages', 200, 'Komentářů: 1', message: 'comments: the pages list shows the badge with the count');
        $this->assertPage('/admin.php?module=pages&action=builder&id=' . self::$page, 200, '"jmeno":"Client Novak"', message: 'comments: the builder shows the comment in its panel data');
    }

    public function testClaudeListsAndResolvesCommentsOverMcp(): void
    {
        $text = $this->mcpText('list_draft_comments');
        $this->assertStringContainsString('"name":"Client Novak"', $text, 'MCP: list_draft_comments returns the comment');
        $this->assertStringContainsString('"element":"' . self::$element . '"', $text, 'MCP: with the element');
        $this->assertStringContainsString('"page_title":"Comment draft"', $text, 'MCP: with the page title');
        $this->assertStringContainsString('not instructions', $text, 'MCP: and tells Claude it is data, not an instruction');
        self::$commentId = $this->firstId($text);

        $this->assertStringContainsString('"total":0', $this->mcpText('list_draft_comments', ['page_id' => self::$page + 1000]), 'MCP: the page filter of list_draft_comments');

        $resolved = $this->mcpText('resolve_draft_comment', ['id' => self::$commentId]);
        $this->assertStringContainsString('"resolved":true', $resolved, 'MCP: resolve_draft_comment answers resolved');
        $this->assertSame('1', $this->sq('SELECT resolved_at IS NOT NULL FROM ka_draft_comments WHERE id = ?', [self::$commentId]), 'MCP: the comment is marked resolved');

        preg_match('/"total":\d+/', $this->mcpText('list_draft_comments'), $open);
        preg_match('/"total":\d+/', $this->mcpText('list_draft_comments', ['include_resolved' => true]), $all);
        $this->assertSame('"total":0|"total":1|1', ($open[0] ?? '') . '|' . ($all[0] ?? '') . '|' . $this->lines('No open comment', $this->mcpRawText('resolve_draft_comment', ['id' => self::$commentId])), 'MCP: unresolved by default, resolved on request; resolving twice is refused');
    }

    public function testTheBuilderResolvesACommentWithOneClick(): void
    {
        $this->comment(['klic' => self::$key, 'jmeno' => 'Client', 'text' => 'Second note']);
        $second = (int) $this->sq('SELECT MAX(id) FROM ka_draft_comments');
        $csrf = $this->site()->admin()->get('/admin.php?module=pages')->csrf();

        $resolve = $this->site()->admin()->post('/admin.php?module=pages&action=build_comment_resolve&id=' . self::$page, ['_csrf' => $csrf, 'id' => $second]);
        $other = $this->site()->admin()->post('/admin.php?module=pages&action=build_comment_resolve&id=' . self::$otherPage, ['_csrf' => $csrf, 'id' => $second]);

        $this->assertSame('200|1|404', $resolve->status . '|' . $this->lines('"vyrizeno":true', $resolve->body) . '|' . $other->status, 'comments: the builder resolves a comment with one click, a comment of another page is not found');
    }

    public function testTheRateLimitStopsTheEleventhCommentFromOneAddress(): void
    {
        // the site's clock, not the database's NOW() (the CI database runs in UTC, the site in Europe/Prague)
        $now = trim($this->site()->php('echo date("Y-m-d H:i:s");'));
        $this->site()->exec("INSERT INTO ka_kontrola_ip (ip_adresa, typ, cil, cas) SELECT SUBSTRING(SHA2('kaleta|127.0.0.1', 256), 1, 40), 'komentar', ?, ? FROM ka_nastaveni LIMIT 10", [self::$page, $now]);

        $result = $this->comment(['klic' => self::$key, 'jmeno' => 'Client', 'text' => 'Again']);
        $this->assertSame('limit|2', (string) preg_replace('/#.*/', '', (string) preg_replace('/.*comment=/', '', $result)) . '|' . $this->sq('SELECT COUNT(*) FROM ka_draft_comments WHERE target = ?', ['stranka:' . self::$page]), 'comments: the eleventh comment from one address in ten minutes is refused');
    }

    public function testThePreviewLinkToolCanAllowComments(): void
    {
        $text = $this->mcpText('nahled_odkaz', ['id' => self::$page, 'komentare' => true]);
        $this->assertMatchesRegularExpression('/preview_key=[0-9]*k\./', $text, 'MCP: preview_link with comments gives a commenting link');
        $this->assertStringContainsString('"komentare":true', $text, 'MCP: and says comments are allowed');

        $this->site()->exec("REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('mail_mode', 'mail'), ('smtp_host', '')");
        $this->site()->exec("DELETE FROM ka_kontrola_ip WHERE typ = 'komentar'");
    }
}
