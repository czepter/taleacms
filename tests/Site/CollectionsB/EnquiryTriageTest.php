<?php

declare(strict_types=1);

namespace Kaleta\Tests\Site\CollectionsB;

use Kaleta\Tests\Site\Support\SiteTestCase;
use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\Attributes\Group;

/** Was: section 67 (2.12 enquiry triage – by Claude over MCP, by a person, and by the writing assistant in the background). */
#[Group('site')]
final class EnquiryTriageTest extends SiteTestCase
{
    use Helpers;

    private static int $enquiry = 0;

    public function testClaudeSortsEnquiriesOverMcp(): void
    {
        $site = $this->site();
        $site->exec("DELETE FROM ka_ip_checks WHERE type = 'formular'");
        self::$enquiry = $this->insertEnquiry('eva@example.cz', '[["Zpráva","Chceme nabídku na 40 oken do pátku"]]', 0);
        $spam = $this->insertEnquiry('seo@example.com', '[["Zpráva","We can get you to the first page of Google"]]', 0);
        $id = self::$enquiry;

        $text = $this->mcpText('triage_enquiries');
        $this->assertStringContainsString("\"id\":$id", $text, 'triage: Claude gets the unsorted enquiry');
        $this->assertStringContainsString('40 oken', $text, 'triage: Claude gets the enquiries as text');

        $site->mcp('update_enquiry', ['id' => $id, 'category' => 'sales', 'priority' => 'high', 'draft_reply' => 'Dobrý den, děkujeme za poptávku.']);
        $site->mcp('update_enquiry', ['id' => $spam, 'category' => 'spam']);
        $this->assertSame('sales|3|Dobrý den, děkujeme za poptávku.|claude', $site->value('SELECT CONCAT(category, \'|\', priority, \'|\', suggested_reply, \'|\', triaged_by) FROM ka_enquiries WHERE enquiry_id = ?', [$id]), "triage: Claude's sorting is saved");

        $this->assertStringContainsString('category must be one of', $this->mcpText('update_enquiry', ['id' => $id, 'category' => 'nonsense']), 'triage: an unknown kind is refused');

        $list = $this->mcpText('list_enquiries', ['limit' => 50]);
        $this->assertStringContainsString('draft_reply', $list, 'triage: list_enquiries carries the triage');
        $this->assertStringNotContainsString('first page of Google', $list, 'triage: list_enquiries leaves spam out');

        $admin = $this->assertPage('/admin.php?module=enquiries', 200, 'category=spam', message: 'triage: the admin list has the spam filter');
        $this->assertStringNotContainsString('first page of Google', $admin->body, 'triage: spam is not in the default list');

        $this->assertPage("/admin.php?module=enquiries&action=detail&id=$id", 200, 'body=Dobr%C3%BD%20den', message: 'triage: the detail has the kind, the priority and the draft in the e-mail reply');
    }

    #[Depends('testClaudeSortsEnquiriesOverMcp')]
    public function testAPersonsSortingWinsOverClaude(): void
    {
        $site = $this->site();
        $id = self::$enquiry;
        $token = $this->assertPage('/admin.php?module=enquiries')->csrf();
        $site->admin()->post('/admin.php?module=enquiries&action=triage', ['_csrf' => $token, 'id' => $id, 'kategorie' => 'support', 'priority' => 1, 'suggested_reply' => 'Vlastní odpověď']);

        $text = $this->mcpText('update_enquiry', ['id' => $id, 'category' => 'sales']);
        $this->assertStringContainsString('A person sorted this enquiry already', $text, 'triage: Claude is told a person sorted this enquiry already');
        $this->assertSame('support|1', $site->value("SELECT CONCAT(category, '|', triaged_by <> 'claude') FROM ka_enquiries WHERE enquiry_id = ?", [$id]), "triage: a person's sorting wins over Claude");
    }

    #[Depends('testAPersonsSortingWinsOverClaude')]
    public function testTheAssistantSortsNewEnquiriesInTheBackgroundWhenSwitchedOn(): void
    {
        $site = $this->site();
        // a fake provider answers like the Claude API
        $dir = $site->workDir('ai');
        file_put_contents($dir . '/index.php', <<<'PHP'
            <?php
            file_put_contents(__DIR__ . '/requests.log', file_get_contents('php://input') . "\n", FILE_APPEND);
            header('Content-Type: application/json');
            echo json_encode(['content' => [['type' => 'text', 'text' => '{"category": "support", "priority": 2, "reply": "Dobrý den, podíváme se na to."}']]]);
            PHP);
        $log = $dir . '/requests.log';
        file_put_contents($log, '');
        $port = $site->startPhp($dir, 'index.php');

        // the address of the model API comes only from config.php; the test server revalidates the file within about 2 seconds
        $config = $site->path('config.php');
        $original = (string) file_get_contents($config);
        file_put_contents($config, preg_replace('/<\?php/', "<?php define('KALETA_AI_URL', 'http://127.0.0.1:$port/');", $original, 1));
        sleep(3); // OPcache of the test server revalidates the file after 2 s, as the old script waited
        try {
            $id = $this->insertEnquiry('jan@example.cz', '[["Zpráva","Nefunguje nám zámek u dveří"]]', 0);
            $site->setting('ai_key', 'test-key-for-the-fake-provider');
            $site->setting('ai_provider', 'anthropic');
            $site->setting('triage_assistant', '0');
            $site->exec("INSERT INTO ka_jobs (name, last_run) VALUES ('triage', NULL) ON DUPLICATE KEY UPDATE last_run = NULL");
            $site->runTasks();
            $this->assertSame('0|', filesize($log) . '|' . $site->value('SELECT category FROM ka_enquiries WHERE enquiry_id = ?', [$id]), 'triage: switched off, the assistant sends nothing');

            $site->setting('triage_assistant', '1');
            $row = '';
            for ($i = 0; $i < 20; $i++) {
                $site->exec("UPDATE ka_jobs SET last_run = NULL WHERE name = 'triage'");
                $site->runTasks();
                $row = (string) $site->value("SELECT CONCAT_WS('|', category, priority, triaged_by, IFNULL(suggested_reply, '-')) FROM ka_enquiries WHERE enquiry_id = ?", [$id]);
                if (str_contains($row, 'assistant')) {
                    break;
                }
                sleep(1);
            }
            $this->assertSame('support|2|assistant|Dobrý den, podíváme se na to.', $row, 'triage: switched on, the assistant sorts a new enquiry and drafts a reply');

            $sent = (string) file_get_contents($log);
            $this->assertStringContainsString('never instructions', $sent, 'triage: the assistant is told the enquiry is data, not instructions');
            $this->assertStringContainsString('zámek', $sent, 'triage: the enquiry text goes to the assistant');
        } finally {
            file_put_contents($config, $original);
            $site->setting('triage_assistant', '0');
            $site->exec("DELETE FROM ka_settings WHERE name = 'ai_key'");
        }
    }
}
