<?php

declare(strict_types=1);

namespace Kaleta\Tests\Site\LinksConnectors;

use Kaleta\Tests\Site\Support\SiteTestCase;
use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\Attributes\Group;

/** Social post drafts: a person posts them, the site never does (was: section 83, 2.13, Core\SocialDrafts). */
#[Group('site')]
final class SocialDraftsTest extends SiteTestCase
{
    use FakeServices;

    /** The lines of a draft text. @return list<string> */
    private function lines(string $text): array
    {
        return array_map(static fn (string $line): string => rtrim($line, "\r"), explode("\n", trim($text)));
    }

    public function testAnArticlePublishedThroughClaudeGetsDraftsForFacebookAndLinkedIn(): void
    {
        $site = $this->site();
        $site->mcp('create_news', ['title' => 'Nová hala pro výrobu', 'intro' => '<p>Otevřeli jsme novou výrobní halu &amp; sklad.</p>', 'category' => $this->newsCategory(),
            'tags' => 'nová hala F14, výroba F14, CNC stroje F14, čtvrtý F14', 'image' => 'media/foto.jpg', 'publish' => true]);
        $news = (int) $site->value("SELECT news_id FROM ka_news WHERE title = 'Nová hala pro výrobu'");
        $this->assertGreaterThan(0, $news);

        $result = $site->mcpResult('get_social_drafts', ['id' => $news]);
        $this->assertSame('facebook', $result['drafts'][0]['network'], 'social drafts: the first draft is for Facebook');
        $this->assertSame('linkedin', $result['drafts'][1]['network'], 'social drafts: the second draft is for LinkedIn');
        $this->assertArrayNotHasKey(2, $result['drafts'], 'social drafts: none for X by default');
        $this->assertTrue($result['published'], 'social drafts: the news item is published');

        $base = $site->base;
        $this->assertSame($base . '/novinky/nova-hala-pro-vyrobu?utm_source=facebook&utm_medium=social&utm_campaign=nova-hala-pro-vyrobu', $result['drafts'][0]['link'], 'social drafts: the tracked link for Facebook');
        $this->assertSame($base . '/novinky/nova-hala-pro-vyrobu?utm_source=linkedin&utm_medium=social&utm_campaign=nova-hala-pro-vyrobu', $result['drafts'][1]['link'], 'social drafts: the tracked link for LinkedIn');
        $this->assertSame($base . '/media/foto.jpg', $result['drafts'][0]['image'], 'social drafts: the news image');

        $lines = $this->lines($result['drafts'][0]['text']);
        $this->assertContains('Nová hala pro výrobu', $lines, 'social drafts: the title');
        $this->assertContains('Otevřeli jsme novou výrobní halu & sklad.', $lines, 'social drafts: the lead as plain text');
        $this->assertContains('#NovaHalaF14 #VyrobaF14 #CncStrojeF14', $lines, 'social drafts: three hashtags from the tags');
        $this->assertStringContainsString('utm_source=facebook', end($lines), 'social drafts: the link closes the text');

        // a user agent not seen today = a new visitor
        $site->client('social')->get($result['drafts'][0]['link'], [], 'Mozilla/5.0 (Windows NT 10.0) Chrome/120 Social');
        $this->assertSame('1', (string) $site->value("SELECT COALESCE(SUM(visits), 0) > 0 FROM ka_stats_campaigns WHERE campaign LIKE 'facebook / social / nova-hala-pro-vyrobu%'"), 'social drafts: a visit through the tracked link counts in the campaign statistics');
    }

    #[Depends('testAnArticlePublishedThroughClaudeGetsDraftsForFacebookAndLinkedIn')]
    public function testTheEditorPanelLetsAPersonEditCopyAndMarkAsPosted(): void
    {
        $site = $this->site();
        $news = (int) $site->value("SELECT news_id FROM ka_news WHERE title = 'Nová hala pro výrobu'");
        $editor = $site->admin()->get('/admin.php?module=news&action=edit&id=' . $news)->body;
        $this->assertStringContainsString('id="social-posts"', $editor, 'social drafts: the editor shows the panel');
        $this->assertSame(2, preg_match_all('/data-kopirovat="#social-text-[0-9]*"/', $editor), 'social drafts: a Copy button per draft');
        $this->assertStringContainsString('action=social_posted', $editor, 'social drafts: Mark as posted');
        $this->assertStringNotContainsString('action=social_suggest', $editor, 'social drafts: no assistant button while the assistant is off');

        $facebook = (int) $site->value("SELECT id FROM ka_social_drafts WHERE news_id = ? AND network = 'facebook'", [$news]);
        $editPage = '/admin.php?module=news&action=edit&id=' . $news;
        $this->adminPost('/admin.php?module=news&action=social_save', ['id' => (string) $facebook,
            'text' => 'Upravený text <b>bez HTML</b> ' . $site->base . '/novinky/nova-hala-pro-vyrobu?utm_source=facebook&utm_medium=social&utm_campaign=nova-hala-pro-vyrobu'], $editPage);
        $this->assertSame('1', (string) $site->value('SELECT text LIKE ? FROM ka_social_drafts WHERE id = ?', ['Upravený text bez HTML http%', $facebook]), 'social drafts: a draft edited in the admin before copying (HTML stripped)');

        $this->adminPost('/admin.php?module=news&action=social_posted', ['id' => (string) $facebook, 'posted' => '1'], $editPage);
        $this->assertSame('1', (string) $site->value('SELECT copied_at IS NOT NULL FROM ka_social_drafts WHERE id = ?', [$facebook]), 'social drafts: marked as posted');

        $list = $site->admin()->get('/admin.php?module=news');
        $this->assertSame(200, $list->status);
        $this->assertMatchesRegularExpression('~id=' . $news . '#social-posts"[^>]*>[^<]* \(1\)</a>~', $list->body, 'social drafts: the news list links the drafts still waiting to be posted');

        $linkedin = (int) $site->value("SELECT id FROM ka_social_drafts WHERE news_id = ? AND network = 'linkedin'", [$news]);
        $result = $site->mcpResult('update_social_draft', ['id' => $linkedin, 'text' => 'Text od Clauda']);
        $this->assertSame('Text od Clauda', $result['draft']['text'], 'social drafts: Claude polishes a draft with update_social_draft (answer)');
        $this->assertSame('Text od Clauda', $site->value('SELECT text FROM ka_social_drafts WHERE id = ?', [$linkedin]), 'social drafts: Claude polishes a draft with update_social_draft (stored)');
    }

    #[Depends('testTheEditorPanelLetsAPersonEditCopyAndMarkAsPosted')]
    public function testADraftNewsItemHasNoDrafts(): void
    {
        $site = $this->site();
        $site->mcp('create_news', ['title' => 'Koncept bez příspěvků', 'category' => $this->newsCategory()]);
        $id = (int) $site->value("SELECT news_id FROM ka_news WHERE title = 'Koncept bez příspěvků'");
        $result = $site->mcpResult('get_social_drafts', ['id' => $id]);
        $this->assertEmpty($result['published'] ?? null, 'social drafts: an unpublished news item is not published');
        $this->assertSame([], $result['drafts'], 'social drafts: an unpublished news item has none (answer)');
        $this->assertSame('0', (string) $site->value('SELECT COUNT(*) FROM ka_social_drafts WHERE news_id = ?', [$id]), 'social drafts: an unpublished news item has none (stored)');
    }

    #[Depends('testADraftNewsItemHasNoDrafts')]
    public function testPublishingInTheAdminPreparesDraftsForTheChosenNetworksWithTheirLimits(): void
    {
        $site = $this->site();
        $site->exec("INSERT INTO ka_settings VALUES ('social_networks', 'facebook,linkedin,x,instagram') ON DUPLICATE KEY UPDATE value = VALUES(value)");
        $lead = '<p>' . str_repeat('Otevřeli jsme novou výrobní halu s moderními stroji. ', 12) . '</p>';
        $this->adminPost('/admin.php?module=news&action=save', [
            'news_id' => '0', 'title' => 'Dlouhá novinka pro X', 'category_id' => (string) $site->value("SELECT category_id FROM ka_categories WHERE language = '' ORDER BY category_id LIMIT 1"),
            'author_id' => (string) $site->value("SELECT user_id FROM ka_users WHERE username = 'admin'"), 'status' => 'vydany', 'intro' => $lead, 'stitky' => 'hala F14, stroje F14',
        ], '/admin.php?module=news&action=new');
        $news = (int) $site->value("SELECT news_id FROM ka_news WHERE title = 'Dlouhá novinka pro X'");
        $this->assertGreaterThan(0, $news, 'the news item was saved');
        $this->assertSame('facebook,instagram,linkedin,x', $site->value('SELECT GROUP_CONCAT(network ORDER BY network) FROM ka_social_drafts WHERE news_id = ?', [$news]), 'social drafts: publishing in the admin prepares a draft for each of the four chosen networks');

        $drafts = $site->mcpResult('get_social_drafts', ['id' => $news])['drafts'];
        $x = $drafts[2]['text'];
        $length = mb_strlen((string) preg_replace('#https?://\S+#', str_repeat('x', 23), trim($x)));
        $this->assertLessThanOrEqual(280, $length, 'social drafts: the X draft fits 280 characters with the link counted as 23');
        $this->assertGreaterThan(240, $length, 'social drafts: the X draft is not cut much shorter than needed');
        $this->assertStringContainsString('#HalaF14 #StrojeF14', $x, 'social drafts: the X hashtags are whole');
        $xLines = $this->lines($x);
        $this->assertStringEndsWith('utm_source=x&utm_medium=social&utm_campaign=dlouha-novinka-pro-x', end($xLines), 'social drafts: the X link is whole');

        $instagram = $drafts[3]['text'];
        $this->assertStringNotContainsString('http', $instagram, 'social drafts: Instagram has no link in the text');
        $this->assertTrue(str_contains($instagram, 'Odkaz v biu') || str_contains($instagram, 'Link in bio'), 'social drafts: Instagram says link in bio');
        $this->assertSame($site->base . '/novinky/dlouha-novinka-pro-x?utm_source=instagram&utm_medium=social&utm_campaign=dlouha-novinka-pro-x', $drafts[3]['link'], 'social drafts: the tracked link waits for the bio');

        $this->assertMatchesRegularExpression('~^' . preg_quote($site->base, '~') . '/og/[a-f0-9]+\.png$~', $drafts[0]['image'], 'social drafts: a news item without an image gets the picture the site draws (2.12)');

        $xId = (int) $site->value("SELECT id FROM ka_social_drafts WHERE news_id = ? AND network = 'x'", [$news]);
        $answer = json_encode($site->mcp('update_social_draft', ['id' => $xId, 'text' => str_repeat('a', 281)]), JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('X allows 280', $answer, 'social drafts: Claude cannot make an X draft longer than 280');

        $tools = json_encode($site->mcpRaw('{"jsonrpc":"2.0","id":1,"method":"tools/list"}'));
        $this->assertStringContainsString('"name":"update_social_draft"', $tools, 'social drafts: the tools are listed');
        $site->exec("DELETE FROM ka_settings WHERE name = 'social_networks'");
    }
}
