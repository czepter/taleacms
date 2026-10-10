<?php

declare(strict_types=1);

namespace Kaleta\Tests\Site\AgentAddons;

use Kaleta\Tests\Site\Support\SiteTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * What a drafts-only connection may save, and Waiting for you, Core\PendingReview (was: section 94, 3.2). Uses the drafts-only token of
 * section 44 (see AgentHelpers); the Team collection ("tym", section 13) is recreated here. The tests run in order.
 */
#[Group('site')]
final class DraftsOnlyConnectionTest extends SiteTestCase
{
    use AgentHelpers;

    private static int $draftItem = 0;
    private static string $tomorrow = '';
    private static int $proposed = 0;

    public function testCollectionItemsAreSavedHiddenAndNeverMadeVisible(): void
    {
        $token = $this->draftsToken();
        // the Team collection with one visible member (section 13 made it with the admin form and a visible item)
        $this->adminPost('/admin.php?module=collections&action=save', ['collection_id' => 0, 'name' => 'Tým', 'detail' => 1, 'fields' => [['label' => 'Funkce', 'type' => 'text'], ['label' => 'Foto', 'type' => 'image'], ['label' => 'Medailonek', 'type' => 'html']]], '/admin.php?module=collections');
        $this->assertSame('1', $this->sq("SELECT COUNT(*) FROM ka_collections WHERE slug = 'tym'"), 'the Team collection exists');
        $this->site()->mcp('uloz_polozku_kolekce', ['kolekce' => 'tym', 'nazev' => 'Petr Svoboda', 'data' => ['funkce' => 'Mistr truhlář'], 'visible' => true]);

        $text = $this->mcpText('save_collection_item', ['collection' => 'tym', 'name' => 'Navrh Clena', 'values' => ['funkce' => 'Stolar'], 'visible' => true], $token);
        $created = json_decode($text, true);
        self::$draftItem = (int) $this->pick($created, 'id');
        $this->assertSame('0||1', $this->sq('SELECT visible FROM ka_collection_items WHERE item_id = ?', [self::$draftItem]) . '|' . $this->pick($created, 'visible') . '|' . $this->lines('Saved hidden', $text), '3.2: a drafts-only connection creates a collection item - hidden, whatever visible says, and says so');

        $this->site()->mcp('save_collection_item', ['collection' => 'tym', 'id' => self::$draftItem, 'values' => ['funkce' => 'Mistr stolar']], $token);
        $this->assertSame('0|1', $this->sq("SELECT CONCAT(visible, '|', data LIKE '%Mistr stolar%') FROM ka_collection_items WHERE item_id = ?", [self::$draftItem]), '3.2: a drafts-only connection changes a hidden item');

        $this->assertStringContainsString('cannot make an item visible', $this->mcpRawText('save_collection_item', ['collection' => 'tym', 'id' => self::$draftItem, 'visible' => true], $token), '3.2: a drafts-only connection cannot make an item visible (refused)');
        $this->assertSame('0', $this->sq('SELECT visible FROM ka_collection_items WHERE item_id = ?', [self::$draftItem]), '3.2: and the item stays hidden');

        $this->assertStringContainsString('cannot schedule an item', $this->mcpRawText('save_collection_item', ['collection' => 'tym', 'name' => 'Planovany Navrh', 'publish_at' => '2099-01-01 08:00'], $token), '3.2: a drafts-only connection cannot schedule an item (refused)');
        $this->assertSame('0', $this->sq("SELECT COUNT(*) FROM ka_collection_items WHERE name = 'Planovany Navrh'"), '3.2: and nothing is saved');

        $live = (int) $this->sq("SELECT p.item_id FROM ka_collection_items p JOIN ka_collections k ON k.collection_id = p.collection_id WHERE k.slug = 'tym' AND p.visible = 1 AND p.deleted_at IS NULL ORDER BY p.item_id LIMIT 1");
        $before = $this->sq('SELECT SHA2(CONCAT(name, data), 256) FROM ka_collection_items WHERE item_id = ?', [$live]);
        $this->assertNotSame('', $before, 'there is a visible item');
        $this->assertStringContainsString('propose', $this->mcpRawText('save_collection_item', ['collection' => 'tym', 'id' => $live, 'name' => 'Prepsano Claudem'], $token), '3.2: a drafts-only connection is told to propose the change of a visible item');
        $this->assertSame($before, $this->sq('SELECT SHA2(CONCAT(name, data), 256) FROM ka_collection_items WHERE item_id = ?', [$live]), '3.2: a drafts-only connection cannot change a visible item');
    }

    public function testEnquiriesOnlyTheTriageAndTheNotebook(): void
    {
        $token = $this->draftsToken();
        $this->site()->exec("INSERT INTO ka_enquiries (created_at, form, email, data) VALUES (NOW(), 'Navrh trideni', 'navrh@example.com', '[]')");
        $enquiry = (int) $this->sq("SELECT MAX(enquiry_id) FROM ka_enquiries WHERE form = 'Navrh trideni'");

        $this->assertStringContainsString('triage', $this->mcpRawText('update_enquiry', ['id' => $enquiry, 'status' => 'resolved', 'category' => 'sales'], $token), '3.2: setting the status of an enquiry is refused (triage only)');
        $this->assertSame('0|', $this->sq("SELECT CONCAT(status, '|', category) FROM ka_enquiries WHERE enquiry_id = ?", [$enquiry]), '3.2: a drafts-only connection cannot set the status of an enquiry (nothing saved)');

        $this->site()->mcp('update_enquiry', ['id' => $enquiry, 'category' => 'sales', 'priority' => 'high', 'draft_reply' => 'Dobrý den, ozveme se.'], $token);
        $this->assertSame('0|sales|3|claude', $this->sq("SELECT CONCAT(status, '|', category, '|', priority, '|', triaged_by) FROM ka_enquiries WHERE enquiry_id = ?", [$enquiry]), '3.2: a drafts-only connection saves the triage of an enquiry');
        $this->site()->exec('DELETE FROM ka_enquiries WHERE enquiry_id = ?', [$enquiry]);

        $this->site()->mcp('write_notebook', ['topic' => 'history', 'title' => 'Zprava z behu', 'text' => 'Navstevy rostou.'], $token);
        $this->assertSame('1', $this->sq("SELECT COUNT(*) FROM ka_notebook WHERE title = 'Zprava z behu'"), '3.2: a drafts-only connection writes a notebook note');
    }

    public function testOpeningHoursExceptionsAreOnlyProposed(): void
    {
        $token = $this->draftsToken();
        self::$tomorrow = date('Y-m-d', strtotime('+1 day'));
        $tomorrow = self::$tomorrow;

        $this->site()->mcp('save_hours_exception', ['from' => $tomorrow, 'note' => 'Platna vyjimka', 'notice_days' => 0]);
        $applied = (int) $this->sq("SELECT id FROM ka_hours_exceptions WHERE note = 'Platna vyjimka'");
        $this->assertStringContainsString('can only propose', $this->mcpRawText('save_hours_exception', ['id' => $applied, 'from' => $tomorrow, 'note' => 'Zmeneno Claudem'], $token), '3.2: a drafts-only connection cannot change an exception in use (refused)');
        $this->assertSame('Platna vyjimka|0', $this->sq("SELECT CONCAT(note, '|', proposed) FROM ka_hours_exceptions WHERE id = ?", [$applied]), '3.2: the exception in use is unchanged');
        $this->site()->exec('DELETE FROM ka_hours_exceptions WHERE id = ?', [$applied]);

        $text = $this->mcpText('save_hours_exception', ['from' => $tomorrow, 'note' => 'Navrh Clauda', 'notice_days' => 7], $token);
        self::$proposed = (int) $this->sq("SELECT id FROM ka_hours_exceptions WHERE note = 'Navrh Clauda'");
        $this->assertSame('1|1', $this->sq('SELECT proposed FROM ka_hours_exceptions WHERE id = ?', [self::$proposed]) . '|' . $this->lines('PROPOSAL', $text), '3.2: a drafts-only connection saves a PROPOSED exception and is told a person applies it');

        $this->site()->mcp('save_hours_exception', ['id' => self::$proposed, 'from' => $tomorrow, 'note' => 'Navrh Clauda', 'hours' => '9-12', 'notice_days' => 7], $token);
        $this->assertSame('1|0|9-12', $this->sq("SELECT CONCAT(proposed, '|', closed, '|', hours) FROM ka_hours_exceptions WHERE id = ?", [self::$proposed]), '3.2: a drafts-only connection changes its own proposal, which stays a proposal');

        $this->site()->clearPageCache();
        $home = $this->site()->client('visitor')->get('/');
        $this->assertStringNotContainsString('ka-notice-hours', $home->body, '3.2: the site ignores a proposed exception (no notice bar)');
        $this->assertStringNotContainsString('Navrh Clauda', $home->body, '3.2: the site ignores a proposed exception (no structured data)');

        $hours = $this->mcpData('list_hours');
        $this->assertSame('[]|Navrh Clauda', $this->pick($hours, 'exceptions') . '|' . $this->pick($hours, 'proposed', 0, 'note'), '3.2: list_hours keeps proposals apart from the exceptions in use');
        $this->assertPage('/admin.php?module=settings&action=hours_sign&exception=' . self::$proposed, 404, message: '3.2: a proposal has no door sign');
    }

    public function testWaitingForYouListsTheHiddenItemAndTheProposal(): void
    {
        $text = $this->mcpText('list_pending_review', [], $this->draftsToken());
        $this->assertStringContainsString('"kind":"hidden_items"', $text, '3.2: list_pending_review lists the hidden items');
        $this->assertStringContainsString('Navrh Clena (', $text, '3.2: with the hidden item');
        $this->assertStringContainsString('"kind":"proposed_hours"', $text, '3.2: and the proposal');
        $this->assertStringContainsString('"admin_url":"http', $text, '3.2: with admin links');

        $dashboard = $this->assertPage('/admin.php', 200, 'class="awaiting-you"', message: '3.2: the dashboard shows Waiting for you above the counters');
        $this->assertStringContainsString('data-kind="proposed_hours"', $dashboard->body, '3.2: Waiting for you has a row for the proposal');
        $this->assertStringContainsString('data-kind="hidden_items"', $dashboard->body, '3.2: Waiting for you has a row for the hidden item');
        preg_match('/class="(awaiting-you|tiles)"/', $dashboard->body, $first);
        $this->assertSame('awaiting-you', $first[1] ?? '', '3.2: Waiting for you is above the counters');
    }

    public function testAPersonAppliesOrDiscardsAProposal(): void
    {
        $business = $this->site()->admin()->get('/admin.php?module=business');
        $this->assertStringContainsString('id="proposed-hours"', $business->body, '3.2: Business details show the proposal');
        $this->assertStringContainsString('action=hours_apply', $business->body, '3.2: with Apply');
        $this->assertStringContainsString('action=hours_discard', $business->body, '3.2: and Discard');

        $this->adminPost('/admin.php?module=business&action=hours_apply', ['exception' => self::$proposed], '/admin.php?module=business');
        $this->assertSame('0', $this->sq('SELECT proposed FROM ka_hours_exceptions WHERE id = ?', [self::$proposed]), '3.2: a person applies the proposal');
        $home = $this->site()->client('visitor')->get('/');
        $this->assertStringContainsString('ka-notice-hours', $home->body, '3.2: the applied exception shows on the site (notice bar)');
        $this->assertStringContainsString('Navrh Clauda', $home->body, '3.2: the applied exception shows on the site (text)');

        $this->site()->mcp('save_hours_exception', ['from' => self::$tomorrow, 'note' => 'Druhy navrh'], $this->draftsToken());
        $second = (int) $this->sq("SELECT id FROM ka_hours_exceptions WHERE note = 'Druhy navrh'");
        $this->adminPost('/admin.php?module=business&action=hours_discard', ['exception' => self::$proposed], '/admin.php?module=business');
        $this->adminPost('/admin.php?module=business&action=hours_discard', ['exception' => $second], '/admin.php?module=business');
        $this->assertSame('0|1', $this->sq('SELECT COUNT(*) FROM ka_hours_exceptions WHERE id = ?', [$second]) . '|' . $this->sq('SELECT COUNT(*) FROM ka_hours_exceptions WHERE id = ?', [self::$proposed]), '3.2: Discard removes a proposal, never an exception in use');

        $this->site()->exec('DELETE FROM ka_hours_exceptions WHERE id = ?', [self::$proposed]);
        $this->site()->exec('DELETE FROM ka_collection_items WHERE item_id = ?', [self::$draftItem]);
        $this->site()->clearPageCache();
    }

    public function testToolsListOfADraftsOnlyConnection(): void
    {
        $list = (string) json_encode($this->site()->mcpRaw('{"jsonrpc":"2.0","id":1,"method":"tools/list"}', $this->draftsToken()), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        preg_match_all('/"name":"(save_collection_item|save_hours_exception|update_enquiry|write_notebook|list_pending_review|save_fact)"/', $list, $m);
        sort($m[1]);

        $this->assertSame(['list_pending_review', 'save_collection_item', 'save_hours_exception', 'update_enquiry', 'write_notebook'], $m[1], '3.2: tools/list of a drafts-only connection offers the four tools and list_pending_review, not save_fact');
    }
}
