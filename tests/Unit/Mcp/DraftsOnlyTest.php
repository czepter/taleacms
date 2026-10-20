<?php

declare(strict_types=1);

namespace Talea\Tests\Unit\Mcp;

use PHPUnit\Framework\TestCase;

final class DraftsOnlyTest extends TestCase
{
    /** 3.1.1: what Claude is told to do as drafts matches what a drafts-only connection may call */
    public function testInstructionsMatchTheTools(): void
    {
        // every tool a drafts routine is told to use is allowed for drafts-only connections, unless its sentence says it needs
        // full access or is conditional ("when this connection may"); the texts: the work_requests and scheduled_run prompts,
        // the tasks of scheduled runs, and the next step list_requests hands out
        $draftTexts = ['work_requests' => \Talea\Mcp\Prompts::get('work_requests', [])['messages'][0]['content']['text'], 'scheduled_run' => \Talea\Mcp\Prompts::get('scheduled_run', [])['messages'][0]['content']['text']]
            + array_map(fn (array $task): string => $task[1], \Talea\Core\AgentSchedules::TASKS)
            + ['next of list_requests' => (string) (preg_match("/'next' => '([^']+)'/", (string) file_get_contents(TALEA_SYSTEM . '/src/Mcp/Handlers/RequestTools.php'), $nextMatch) ? $nextMatch[1] : '')];
        $draftGaps = [];
        foreach ($draftTexts as $where => $text) {
            foreach (preg_split('/(?<=[.;:])\s+/', $text) ?: [] as $sentence) {
                preg_match_all('/\b[a-z]+(?:_[a-z]+)+\b/', $sentence, $names);
                foreach (array_unique($names[0]) as $tool) {
                    if (isset(\Talea\Mcp\Catalog::TOOLS[$tool]) && !\Talea\Mcp\Catalog::allows('drafts', $tool) && !preg_match('/full access|when this connection may/', $sentence)) {
                        $draftGaps[] = $where . ': ' . $tool;
                    }
                }
            }
        }
        $this->assertSame([], $draftGaps, '3.1.1: drafts routines are only told to use tools a drafts-only connection may call (or told what needs full access)');

        $this->assertSame([true, true, false, []], [
            in_array('requests', \Talea\Admin\Modules\Roles::PRESETS['client'][3], true), in_array('requests', \Talea\Admin\Modules\Roles::PRESETS['office'][3], true), in_array('requests', \Talea\Admin\Modules\Roles::PRESETS['writer'][3], true),
            array_values(array_diff(array_map(fn (string $c): string => $c::ICON, \Talea\Admin\Kernel::MODULES), (function (): array { $icon = require TALEA_SYSTEM . '/views/admin/icons.php'; preg_match_all("/^    '([a-z_-]+)' =>/m", (string) file_get_contents(TALEA_SYSTEM . '/views/admin/icons.php'), $m); return $m[1]; })()))], '3.1.1: the Client and Enquiries only presets can ask Claude; every module icon exists and no two menu sections share one by accident');
    }

    /** 3.2: what a drafts-only connection may save, and Waiting for you */
    public function testWhatADraftsOnlyConnectionMaySave(): void
    {
        $migrationFiles = glob(TALEA_SYSTEM . '/database/migrations/*.php') ?: [];
        $migrationSource = implode("\n", array_map(fn (string $f): string => (string) file_get_contents($f), $migrationFiles));
        $adminCs = require TALEA_SYSTEM . '/languages/admin-cs.php';
        $adminDe = require TALEA_SYSTEM . '/languages/admin-de.php';
        $this->assertSame([[true, true, true, true, true, false, false, false, false, false], true, false, [true, true, true], true], [
            array_map(fn (string $tool): bool => \Talea\Mcp\Catalog::allows('drafts', $tool), ['save_collection_item', 'save_hours_exception', 'update_enquiry', 'write_notebook', 'list_pending_review', 'save_fact', 'delete_hours_exception', 'delete_notebook_entry', 'delete_collection_item', 'restore_item_version']),
            \Talea\Mcp\Catalog::allows('read', 'list_pending_review'), \Talea\Mcp\Catalog::allows('read', 'save_collection_item'),
            // each of the four refuses a drafts-only connection what is more than a draft (the handler asks Auth::draftsOnly)
            array_map(fn (string $file): bool => str_contains((string) file_get_contents(TALEA_SYSTEM . '/src/Mcp/Handlers/' . $file . '.php'), 'draftsOnly()'), ['CollectionTools', 'FactTools', 'EnquiryAndPopupTools']),
            str_contains(\Talea\Mcp\Prompts::get('review_pending', [])['messages'][0]['content']['text'], 'list_pending_review')], '3.2: a drafts-only connection may save hidden items, proposed hours, triage and notes – not facts, not deletes; list_pending_review is a read');
        $this->assertSame([[], [], true, true, true], [
            array_values(array_diff(array_column(\Talea\Core\PendingReview::KINDS, 1), array_map(fn (string $c): string => $c::IDENT, \Talea\Admin\Kernel::MODULES))),
            array_values(array_filter(array_column(\Talea\Core\PendingReview::KINDS, 0), fn (string $label): bool => !isset($adminCs[$label], $adminDe[$label]))),
            str_contains($migrationSource, "'proposed', 'boolean'"),
            // the site, the export and the door sign read only applied exceptions; proposals have their own list
            (bool) preg_match("/hours_exceptions} WHERE proposed = FALSE/", (string) file_get_contents(TALEA_SYSTEM . '/src/Core/Hours.php')),
            str_contains((string) file_get_contents(TALEA_SYSTEM . '/src/Core/SiteExport.php'), 'AND proposed = FALSE')], '3.2: Waiting for you – every kind opens an admin section that exists, its label is translated (cs, de), and the migration adds the proposed column');
    }
}
