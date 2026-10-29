<?php

declare(strict_types=1);

namespace Talea\Tests\Site\AgentAddons;

use Talea\Tests\Site\Support\SiteTestCase;
use PHPUnit\Framework\Attributes\Group;

/** The reason of a change and the guardrails for Claude (was: section 86, 2.15). The tests run in order and build on each other. */
#[Group('site')]
final class ReasonAndGuardrailsTest extends SiteTestCase
{
    use AgentHelpers;

    private static int $guardPage = 0;
    private static int $freePage = 0;

    public function testAReasonOfAWriteToolIsInTheChangeLog(): void
    {
        self::$guardPage = $this->firstId($this->mcpText('create_page', ['title' => 'Guarded page', 'visible' => false]));
        self::$freePage = $this->firstId($this->mcpText('create_page', ['title' => 'Free page', 'visible' => false]));
        $this->assertGreaterThan(0, self::$guardPage);
        $this->assertGreaterThan(0, self::$freePage);

        $this->site()->mcp('update_page', ['id' => $this->site()->publicId('pages', self::$freePage), 'description' => 'New description', 'reason' => 'Request 7: the client asked for a shorter description']);

        $this->assertSame('Request 7: the client asked for a shorter description', $this->sq("SELECT reason FROM tl_change_log WHERE module = 'claude' ORDER BY log_id DESC LIMIT 1"), 'reason: a write tool\'s reason is in the change log');
        $this->assertStringContainsString('"reason":"Request 7', $this->mcpText('list_changes', ['by' => 'claude', 'limit' => 5]), 'reason: list_changes returns it');
    }

    public function testAProtectedPageRefusesChanges(): void
    {
        $this->site()->setting('claude_protected_pages', (string) self::$guardPage);

        $raw = $this->mcpRawText('update_page', ['id' => $this->site()->publicId('pages', self::$guardPage), 'description' => 'x']);
        $this->assertStringContainsString('isError', $raw, 'guardrails: a protected page refuses update_page (error)');
        $this->assertStringContainsString('protected from changes', $raw, 'guardrails: a protected page refuses update_page (reason)');

        $this->assertStringContainsString('protected from changes', $this->mcpRawText('save_build', ['id' => $this->site()->publicId('pages', self::$guardPage), 'build' => ['v' => 1, 'children' => []]]), 'guardrails: and its build');

        // 3.3.2 (N31): an empty other target does not take the protection off
        $popup = $this->mcpRawText('save_build', ['id' => $this->site()->publicId('pages', self::$guardPage), 'popup' => 0, 'build' => ['v' => 1, 'children' => []]]);
        $part = $this->mcpRawText('save_build', ['id' => $this->site()->publicId('pages', self::$guardPage), 'part' => '', 'build' => ['v' => 1, 'children' => []]]);
        $this->assertStringContainsString('protected from changes', $popup, 'guardrails: an empty pop-up does not unprotect the page');
        $this->assertStringContainsString('protected from changes', $part, 'guardrails: an empty part does not unprotect the page');

        $this->assertStringNotContainsString('isError', $this->mcpRawText('update_page', ['id' => $this->site()->publicId('pages', self::$freePage), 'description' => 'Still free']), 'guardrails: other pages stay free');
        $this->site()->setting('claude_protected_pages', '');
    }

    /** T-14: every route that can serve, publish or restrict a protected page's content is refused, for an unprotected page it is not */
    public function testProtectedPagesCoverExperimentsAndAccessTools(): void
    {
        $page = fn (int $id): string => $this->site()->publicId('pages', $id);
        $uuid = static fn (): string => sprintf('%08x-0000-4000-8000-%012x', random_int(0, 0x7fffffff), random_int(0, 0x7fffffffffff));
        $now = date('Y-m-d H:i:s');
        $experiment = function (int $pageId) use ($uuid, $now): array {
            $component = $uuid();
            $this->site()->exec("INSERT INTO tl_components (public_id, name, properties, build, updated_at) VALUES (?, 'Variant', '[]', '{\"v\":1,\"children\":[]}', ?)", [$component, $now]);
            $componentId = (int) $this->sq('SELECT component_id FROM tl_components WHERE public_id = ?', [$component]);
            $public = $uuid();
            $this->site()->exec("INSERT INTO tl_experiments (public_id, name, kind, page_id, variant_component_id, goal_type, status, auto_promote, created_at) VALUES (?, 'T', 'element', ?, ?, 'form', 'stopped', FALSE, ?)",
                [$public, $pageId, $componentId, $now]);

            return [$public, $component];
        };
        [$guardExperiment, $guardComponent] = $experiment(self::$guardPage);
        [$freeExperiment, $freeComponent] = $experiment(self::$freePage);
        $guarded = $page(self::$guardPage);
        $free = $page(self::$freePage);
        $build = ['v' => 1, 'children' => []];
        $this->site()->setting('claude_protected_pages', (string) self::$guardPage);
        foreach ([
            'create_experiment page' => ['create_experiment', ['name' => 'X', 'page' => $guarded, 'kind' => 'page', 'variant_page' => $free, 'goal' => 'form']],
            'create_experiment variant_page' => ['create_experiment', ['name' => 'X', 'page' => $free, 'kind' => 'page', 'variant_page' => $guarded, 'goal' => 'form']],
            'save_build component' => ['save_build', ['component' => $guardComponent, 'build' => $build]],
            'update_experiment' => ['update_experiment', ['id' => $guardExperiment, 'action' => 'start']],
            'promote_experiment_winner' => ['promote_experiment_winner', ['id' => $guardExperiment, 'winner' => 'b', 'force' => true]],
            'delete_experiment' => ['delete_experiment', ['id' => $guardExperiment]],
            'save_component' => ['save_component', ['id' => $guardComponent, 'name' => 'Renamed']],
            'delete_component' => ['delete_component', ['id' => $guardComponent]],
            'set_content_groups' => ['set_content_groups', ['type' => 'page', 'id' => $guarded, 'groups' => []]],
        ] as $label => [$tool, $args]) {
            $this->assertStringContainsString('protected from changes', $this->mcpRawText($tool, $args), 'T-14: ' . $label . ' is refused for a protected page');
        }
        // not refused by the guardrails for an unprotected page (the tool itself may still answer an error)
        foreach ([['save_build', ['component' => $freeComponent, 'build' => $build]], ['update_experiment', ['id' => $freeExperiment, 'action' => 'start']],
            ['save_component', ['id' => $freeComponent, 'name' => 'Renamed']], ['delete_experiment', ['id' => $freeExperiment]]] as [$tool, $args]) {
            $this->assertStringNotContainsString('protected from changes', $this->mcpRawText($tool, $args), 'T-14: ' . $tool . ' is free for an unprotected page');
        }
        // a page test shows its variant page at the protected page's address: editing that page is refused while the test is live
        $this->site()->exec("INSERT INTO tl_experiments (public_id, name, kind, page_id, variant_page_id, goal_type, status, auto_promote, created_at) VALUES (?, 'V', 'page', ?, ?, 'form', 'running', FALSE, ?)",
            [$uuid(), self::$guardPage, self::$freePage, $now]);
        $this->assertStringContainsString('protected from changes', $this->mcpRawText('update_page', ['id' => $free, 'description' => 'Variant']), 'T-14: the variant page of a running test on a protected page is refused');
        $this->site()->exec("UPDATE tl_experiments SET status = 'stopped' WHERE variant_page_id = ?", [self::$freePage]);
        $this->assertStringNotContainsString('protected from changes', $this->mcpRawText('update_page', ['id' => $free, 'description' => 'Variant']), 'T-14: a stopped test no longer holds the variant page');
        $this->site()->setting('claude_protected_pages', '');

        // auto_promote publishes later, from the scheduler: a deferred destructive argument
        $this->site()->setting('claude_destructive', '0');
        $auto = ['name' => 'X', 'page' => $free, 'kind' => 'page', 'variant_page' => $guarded, 'goal' => 'form'];
        $this->assertStringContainsString('switched off deleting', $this->mcpRawText('create_experiment', $auto + ['auto_promote' => true]), 'T-14: auto_promote is refused while destructive calls are off');
        $this->assertStringNotContainsString('switched off deleting', $this->mcpRawText('create_experiment', $auto), 'T-14: an experiment without auto_promote is not');
        $own = ['id' => $freeExperiment];
        foreach (['auto_promote' => $own + ['auto_promote' => true], 'undo_promotion' => $own + ['action' => 'undo_promotion']] as $label => $args) {
            $this->assertStringContainsString('switched off deleting', $this->mcpRawText('update_experiment', $args), 'T-14: update_experiment ' . $label . ' is refused while destructive calls are off');
        }
        $this->assertStringNotContainsString('switched off deleting', $this->mcpRawText('update_experiment', $own + ['auto_promote' => false]), 'T-14: switching auto_promote off is not');
        $this->site()->setting('claude_destructive', '1');
    }

    /** A connection limited to drafts cannot save Custom HTML, so builder_schema does not offer the element to it either */
    public function testBuilderSchemaOffersCustomHtmlOnlyToConnectionsThatMayWriteCode(): void
    {
        $elements = fn (?string $token): string => $this->mcpText('builder_schema', [], $token);
        $this->assertStringContainsString('custom html', strtolower($elements(null)), 'a full-access connection is offered Custom HTML');
        $this->assertStringNotContainsString('custom html', strtolower($elements($this->draftsToken())), 'a drafts-only connection is not');
    }

    public function testSwitchingOffDeletingRefusesDestructiveTools(): void
    {
        $this->site()->setting('claude_destructive', '0');

        $raw = $this->mcpRawText('trash_page', ['id' => $this->site()->publicId('pages', self::$freePage)]);
        $this->assertStringContainsString('switched off deleting', $raw, 'guardrails: deleting switched off - trash_page refused');
        $this->assertSame('1', $this->sq('SELECT deleted_at IS NULL FROM tl_pages WHERE page_id = ?', [self::$freePage]), 'guardrails: the page stays');

        // 3.3.2 (N32): deleting, overwriting and sending through write tools count as destructive too
        $added = $this->mcpRawText('save_redirect', ['from' => '/n32-old', 'to' => '/n32-new']);
        $deleted = $this->mcpRawText('save_redirect', ['from' => '/n32-old', 'delete' => true]);
        $restored = $this->mcpRawText('restore_item_version', ['collection' => 'x', 'id' => 1, 'version' => 1]);
        $this->assertStringNotContainsString('isError', $added, 'guardrails: adding a redirect is not refused');
        $this->assertStringContainsString('switched off deleting', $deleted, 'guardrails: deleting a redirect is refused');
        $this->assertStringContainsString('switched off deleting', $restored, 'guardrails: restoring an item version is refused');
        $this->assertSame('1', $this->sq("SELECT COUNT(*) FROM tl_redirects WHERE from_path = 'n32-old'"), 'guardrails: the redirect is still there');

        $this->site()->setting('claude_destructive', '1');
        $this->site()->mcp('save_redirect', ['from' => '/n32-old', 'delete' => true]);
    }

    public function testTheHourlyLimitStopsAConnectionButNeverReading(): void
    {
        $this->site()->setting('claude_change_limit', '1');

        $this->assertStringContainsString('reached the limit of 1 changes an hour', $this->mcpRawText('update_page', ['id' => $this->site()->publicId('pages', self::$freePage), 'description' => 'Over the limit']), 'guardrails: the hourly limit stops a connection');
        $this->assertStringNotContainsString('isError', $this->mcpRawText('get_page', ['id' => $this->site()->publicId('pages', self::$freePage)]), 'guardrails: reading is never limited');

        $this->site()->setting('claude_change_limit', '0');
        $this->assertPage('/admin.php?module=settings&tab=ai', 200, 'claude_protected_pages', message: 'guardrails: the settings are in Settings → AI');
    }
}
