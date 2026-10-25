<?php

declare(strict_types=1);

namespace Talea\Tests\Unit\Mcp;

use PHPUnit\Framework\TestCase;
use Talea\Mcp\Catalog;
use Talea\Mcp\Discovery;
use Talea\Mcp\Prompts;

final class PromptsTest extends TestCase
{
    /** #157: a prompt names only tools the site has, and tells an Essentials connection which runner reaches each of the others */
    public function testPromptsNameOnlyRealTools(): void
    {
        // every snake_case word of a prompt is a tool, a parameter of a tool, a setting, a prompt or an argument – or one of
        // these words (a placeholder, a status, a field of a tool's answer); anything else is a tool that does not exist
        $terms = ['cookie_table', 'in_progress', 'next_since_id', 'sending_problem', 'snake_case', 'talea_blueprint'];
        $known = [...array_keys(Catalog::TOOLS), ...array_keys(Catalog::META), ...array_keys(\Talea\Core\Settings::DEFAULTS), ...$terms];
        foreach ([...\Talea\Mcp\Tools::definitions(), ...Discovery::definitions()] as $tool) {
            $known = [...$known, ...array_keys((array) $tool['inputSchema']['properties'])];
        }
        foreach (Prompts::listAll() as $prompt) {
            $known = [...$known, $prompt['name'], ...array_column($prompt['arguments'], 'name')];
        }
        $unknown = $withoutRunner = [];
        foreach (Prompts::listAll() as $prompt) {
            $text = Prompts::get($prompt['name'], array_fill_keys(array_column($prompt['arguments'], 'name'), 'x'))['messages'][0]['content']['text'];
            [$task, $closing] = explode(Prompts::RUNNERS_HEADING, $text) + [1 => ''];
            preg_match_all('/\b[a-z0-9]+(?:_[a-z0-9]+)+\b/', $task, $words);
            foreach (array_diff(array_unique($words[0]), $known) as $word) {
                $unknown[] = $prompt['name'] . ': ' . $word;
            }
            foreach (Prompts::toolsIn($task) as $tool) {
                if (!in_array($tool, Catalog::ESSENTIALS, true) && preg_match('/^- ' . Discovery::runner($tool) . ' \(.*[ ,]' . $tool . '(,|$)/m', $closing) !== 1) {
                    $withoutRunner[] = $prompt['name'] . ': ' . $tool;
                }
            }
        }
        $this->assertSame([[], []], [$unknown, $withoutRunner], '#157: every tool a prompt names exists, and the closing section names the runner of each one an Essentials connection does not list');

        $this->assertSame(['', true, true, true], [Prompts::runners('Call site_info, then edit_build.'),
            str_contains(Prompts::runners('Call list_events.'), '- run_read_tool (reads): list_events'),
            str_contains(Prompts::runners('Then send_newsletter.'), '- run_destructive_tool ('),
            str_contains(Prompts::runners('save_redirect'), '- run_tool (drafts and changes): save_redirect')], '#157: the runners section is empty for the essentials and names the right runner otherwise');
    }

    /** #157: the runbooks – each starts with the ground rules, fills its arguments in and refuses a missing required one */
    public function testRunbooks(): void
    {
        $runbooks = ['migrate_site', 'launch_site', 'weekly_care', 'set_up_bookings', 'compliance_check', 'add_language_version', 'newsletter_release'];
        $text = fn (string $name, array $arguments = []): string => Prompts::get($name, $arguments)['messages'][0]['content']['text'];
        $missing = '';
        try {
            Prompts::get('add_language_version', []);
        } catch (\InvalidArgumentException $e) {
            $missing = $e->getMessage();
        }
        $this->assertSame([[], true, true, true, true, true, 'The prompt add_language_version needs the argument language.'], [
            array_values(array_filter($runbooks, fn (string $name): bool => !str_contains($text($name, ['old_url' => 'https://old.example', 'language' => 'de']), '## Ground rules'))),
            str_contains($text('launch_site'), 'for my business. The goal'),
            str_contains($text('launch_site', ['business' => 'a bakery']), 'for my business: a bakery. The goal'),
            str_contains($text('newsletter_release', ['topic' => 'the summer opening hours']), 'prepare the next newsletter about the summer opening hours.'),
            str_contains($text('add_language_version', ['language' => 'pt-br']), '`create_page` with `language` pt-br'),
            str_contains($text('migrate_site', ['old_url' => 'https://old.example', 'platform' => 'WordPress']), 'move the old site https://old.example (WordPress) to'),
            $missing,
        ], '#157: the runbooks start with the ground rules and fill in their arguments');
    }
}
