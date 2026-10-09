<?php

declare(strict_types=1);

namespace Kaleta\Tests\Site\FormsHygiene;

use Kaleta\Tests\Site\Support\SiteTestCase;
use PHPUnit\Framework\Attributes\Group;

/** Personal data requests: find, export, erase (was: section 76, "2.14"). */
#[Group('site')]
final class PersonalDataTest extends SiteTestCase
{
    use McpHelpers;

    private static int $enquiry = 0;
    private static int $oldSession = 0;

    public function testClaudeFindsTheEnquiriesAndTheSubscriptionAsCountsOnly(): void
    {
        $this->site()->exec("INSERT INTO ka_enquiries (created_at, form, email, data) VALUES (NOW(), 'PD', 'pd.person@example.com', ?), (NOW(), 'PD', 'other@example.com', ?), (NOW(), 'PD', 'keep@example.com', ?)",
            ['[["Name","PD Person"]]', '[["Colleague","PD.Person@example.com"]]', '[["Name","Keep"]]']);
        $this->site()->exec("INSERT INTO ka_subscribers (email, status, token, created_at) VALUES ('pd.person@example.com', 1, '0123456789abcdef0123456789abcdef', NOW())");

        $text = $this->mcpText('find_personal_data', ['email' => ' PD.Person@Example.com ']);

        $this->assertStringContainsString('"enquiries":2', $text, 'personal data: the enquiries are found (sender and any field)');
        $this->assertStringContainsString('"subscriber":1', $text, 'personal data: the subscription is found');
        $this->assertStringNotContainsString('PD Person', $text, 'personal data: counts only, no content');
    }

    public function testTheAdministratorScreenListsAndExports(): void
    {
        $this->assertPage('/admin.php?module=enquiries&action=personal', 200, 'personal-email', message: "personal data: the administrator's screen lists what the site keeps");

        $export = $this->adminPost('/admin.php?module=enquiries&action=personal', ['email' => 'pd.person@example.com', 'bulk' => 'export'], formPage: '/admin.php?module=enquiries&action=personal');
        $json = $export->json();

        $this->assertIsArray($json, 'personal data: the export is JSON');
        $this->assertCount(2, $json['enquiries'] ?? [], 'personal data: the export has both enquiries');
        $this->assertSame('pd.person@example.com', $json['subscriber']['email'] ?? '', 'personal data: the export has the subscriber');
    }

    public function testErasingNeedsAnExplicitConfirmation(): void
    {
        $this->mcpText('erase_personal_data', ['email' => 'pd.person@example.com']);

        $this->assertSame('3', (string) $this->site()->value("SELECT COUNT(*) FROM ka_enquiries WHERE form = 'PD'"), 'personal data: erasing needs an explicit confirmation');
    }

    public function testErasingRemovesThePersonAndLeavesNoCopyInTheUndoJournal(): void
    {
        // 3.3.2 (N29): an enquiry a Claude session of an older release journaled, and a change of it now – which is no longer journaled
        self::$enquiry = (int) $this->site()->value("SELECT enquiry_id FROM ka_enquiries WHERE email = 'pd.person@example.com'");
        $this->site()->exec("INSERT INTO ka_agent_sessions (connection, started_at, last_at, calls) VALUES ('pd-old', NOW() - INTERVAL 3 HOUR, NOW() - INTERVAL 3 HOUR, 1)");
        self::$oldSession = (int) $this->site()->pdo->lastInsertId();
        $this->site()->exec("INSERT INTO ka_agent_journal (session_id, call_no, tool, tbl, row_key, before_row, after_row, created_at) SELECT ?, 1, 'delete_enquiry', 'enquiries', CONCAT('{\"enquiry_id\":', enquiry_id, '}'),
            JSON_OBJECT('enquiry_id', enquiry_id, 'created_at', created_at, 'form', form, 'email', email, 'data', data), NULL, NOW() - INTERVAL 3 HOUR FROM ka_enquiries WHERE enquiry_id = ?", [self::$oldSession, self::$enquiry]);

        $this->mcpText('update_enquiry', ['id' => self::$enquiry, 'status' => 'read']);
        $this->mcpText('erase_personal_data', ['email' => 'pd.person@example.com', 'confirm' => true]);

        $this->assertSame('1|0|1', (string) $this->site()->value("SELECT CONCAT((SELECT COUNT(*) FROM ka_enquiries WHERE form = 'PD'), '|', (SELECT COUNT(*) FROM ka_subscribers WHERE email = 'pd.person@example.com'), '|', (SELECT COUNT(*) FROM ka_events WHERE type = 'personal_data.erased' AND data NOT LIKE '%@%'))"),
            'personal data: erased – both enquiries and the subscriber; other people\'s enquiry stays; the log has no address');
        $this->assertSame('0|0|1', (string) $this->site()->value("SELECT CONCAT((SELECT COUNT(*) FROM ka_agent_journal WHERE LOWER(CONCAT_WS('|', before_row, after_row)) LIKE '%pd.person@example.com%'), '|', (SELECT COUNT(*) FROM ka_agent_journal WHERE tbl = 'enquiries' AND untracked IS NULL), '|', (SELECT COUNT(*) FROM ka_agent_journal WHERE session_id = ? AND untracked = 'personal data erased on request'))", [self::$oldSession]),
            '3.3.2 personal data: the undo journal holds no copy of the erased address – enquiries are not journaled and an older entry is redacted');
    }

    public function testUndoingTheOlderSessionDoesNotBringTheErasedEnquiryBack(): void
    {
        $text = $this->mcpText('undo_agent_session', ['id' => self::$oldSession, 'confirm' => true]);

        $this->assertSame('0', (string) $this->site()->value('SELECT COUNT(*) FROM ka_enquiries WHERE enquiry_id = ?', [self::$enquiry]), '3.3.2 personal data: undoing the older session does not bring the erased enquiry back');
        $this->assertSame(1, preg_match_all('/^.*personal data erased on request.*$/m', $text), '3.3.2 personal data: the undo names the redacted write');

        $this->site()->exec("DELETE FROM ka_enquiries WHERE form = 'PD'");
    }
}
