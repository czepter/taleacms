<?php

declare(strict_types=1);

namespace Talea\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Talea\Core\Blueprint;

final class BlueprintTest extends TestCase
{
    /** 2.11: industry blueprints (Core\Blueprint) */
    public function testBlueprints(): void
    {
        $blueprintInput = ['talea_blueprint' => 1, 'key' => 'dental_clinic', 'name' => ['en' => 'Dental clinic'], 'description' => 'For dentists', 'presets' => ['people', 'events', 'people'],
            'facts' => [['key' => 'insurers', 'label' => 'Insurers', 'type' => 'text'], ['key' => 'founded', 'label' => 'Founded', 'type' => 'year', 'schema' => 'foundingDate', 'value' => 'never copied']],
            'questions' => [['question' => 'Which insurers do you have contracts with?', 'fact' => 'insurers', 'help' => 'Comma separated']],
            'audit' => [['check' => 'fact', 'fact' => 'insurers', 'message' => 'Say which insurers you work with.'], ['check' => 'preset_items', 'preset' => 'people', 'min' => 2, 'message' => 'Add the doctors.'],
                ['check' => 'setting', 'setting' => 'company_hours', 'message' => 'Fill in the opening hours.'], ['check' => 'page', 'slugs' => ['cenik', 'price-list'], 'message' => 'Publish the price list.'],
                ['check' => 'stale_items', 'preset' => 'events', 'days' => 180, 'message' => 'No events for half a year.']],
            'claude' => 'Patients look for insurers and hours first. <b>Never</b> give medical advice.', 'unknown' => 'dropped'];
        [$blueprint, $blueprintErrors] = Blueprint::sanitize($blueprintInput);
        $this->assertSame([[], ['people', 'events'], ['insurers', 'founded'], false, 'foundingDate', 5, 2, 'Patients look for insurers and hours first. Never give medical advice.', false], [$blueprintErrors, $blueprint['presets'] ?? null, array_column($blueprint['facts'] ?? [], 'key'),
            isset($blueprint['facts'][1]['value']), $blueprint['facts'][1]['schema'] ?? null, count($blueprint['audit'] ?? []), $blueprint['audit'][1]['min'] ?? null, $blueprint['claude'] ?? null, isset($blueprint['unknown'])], '2.11 Blueprint::sanitize – a valid manifest keeps its parts, drops duplicates, unknown keys, fact values and tags');
        $blueprintBad = static fn (array $change): array => Blueprint::sanitize(array_replace($blueprintInput, $change))[1];
        $this->assertSame([null, true, true, true, true, true, true, true], [Blueprint::sanitize(['key' => 'x'])[0], $blueprintBad(['key' => 'Bad Key']) !== [], $blueprintBad(['presets' => ['shop']]) !== [],
            $blueprintBad(['questions' => [['question' => 'Q?', 'fact' => 'not_declared']]]) !== [], $blueprintBad(['audit' => [['check' => 'php', 'message' => 'x']]]) !== [],
            $blueprintBad(['audit' => [['check' => 'setting', 'setting' => 'smtp_password', 'message' => 'x']]]) !== [], $blueprintBad(['audit' => [['check' => 'stale_items', 'preset' => 'events', 'days' => 1, 'message' => 'x']]]) !== [],
            $blueprintBad(['facts' => [['key' => 'company_phone', 'label' => 'Phone']]]) !== []], '2.11 Blueprint::sanitize – refuses what it cannot trust');
        $this->assertSame(['plain', \Talea\Core\Language::code() === 'de' ? 'Praxis' : 'Clinic', 'Cabinet'], [Blueprint::text('plain'), Blueprint::text(['en' => 'Clinic', 'de' => 'Praxis']), Blueprint::text(['fr' => 'Cabinet'])], '2.11 Blueprint::text – the admin language, else English, else the first');
        $shipped = glob(TALEA_ROOT . '/system/blueprints/*.json') ?: [];
        $this->assertSame([], array_values(array_filter(array_map(function (string $file): string {
            [$m, $errs] = Blueprint::sanitize(json_decode((string) file_get_contents($file), true));

            return $m === null ? basename($file) . ': ' . implode(' ', $errs) : ($m['key'] !== basename($file, '.json') ? basename($file) . ': key differs' : '');
        }, $shipped))), '2.11 Blueprint: every shipped blueprint is valid and named by its key');
        // the six industry blueprints shipped with 2.11: each asks the owner something, checks the site and guides Claude, in English
        $shippedBlueprints = Blueprint::available();
        $blueprintTexts = function (array $m): array { // every text of a manifest that visitors of the administration may read
            $texts = [$m['name'], $m['description']];
            foreach ($m['facts'] as $f) {
                $texts[] = $f['label'];
            }
            foreach ($m['questions'] as $q) {
                $texts[] = $q['question'];
                $texts[] = $q['help'] ?? 'x'; // help is optional
            }
            foreach ($m['audit'] as $r) {
                $texts[] = $r['message'];
            }

            return $texts;
        };
        $shippedKeys = array_keys($shippedBlueprints);
        sort($shippedKeys);
        $this->assertSame(['accommodation', 'agency', 'auto_service', 'beauty_wellness', 'clinic', 'craftsman', 'driving_school', 'farm', 'fitness_studio', 'it_services', 'manufacturer', 'municipality', 'nonprofit', 'photographer', 'professional_services', 'real_estate', 'restaurant', 'retail_shop', 'school_courses', 'software_saas'], $shippedKeys, '2.11/3.3 Blueprint: the twenty industry blueprints are shipped');
        $this->assertSame([], array_values(array_filter(array_map(fn (array $m): string => $m['presets'] === [] || count($m['facts']) < 4 || count($m['facts']) > 8
            || count($m['questions']) < 1 || count(array_unique(array_column($m['questions'], 'fact'))) !== count($m['facts']) || count($m['audit']) < 3 || count($m['audit']) > 6 || mb_strlen($m['claude']) < 200 ? $m['key'] : '', $shippedBlueprints))), '2.11 Blueprint: every shipped blueprint has presets, 4–8 facts, a question for each, 3–6 checks and instructions for Claude');
        $this->assertSame([], array_values(array_filter(array_map(fn (array $m): string => array_filter($blueprintTexts($m),
            fn (string|array $t): bool => !is_string($t) || trim($t) === '') === [] ? '' : $m['key'], $shippedBlueprints))), '2.11 Blueprint: every text of a shipped blueprint is a non-empty English string');
        $this->assertSame([], array_values(array_filter(array_map(fn (array $m): string => array_filter($m['facts'],
            fn (array $f): bool => isset(\Talea\Core\Facts::BUILT_IN[$f['key']]) || isset($f['value'])) === [] ? '' : $m['key'], $shippedBlueprints))), '2.11 Blueprint: shipped blueprints define no built-in fact and give no fact a value');
        $this->assertSame([], array_values(array_filter(array_map(fn (array $m): string => in_array($m['group'], ['', 'other'], true) || !isset(Blueprint::GROUPS[$m['group']]) ? $m['key'] : '', $shippedBlueprints))), '3.3 Blueprint: every shipped blueprint sits in a group of the Blueprints screen (not "other")');
        $this->assertSame(['tech', 'other', 'other'], [
            Blueprint::sanitize(['group' => 'tech'] + $blueprintInput)[0]['group'] ?? null, Blueprint::sanitize(['group' => 'spaceships'] + $blueprintInput)[0]['group'] ?? null, Blueprint::sanitize($blueprintInput)[0]['group'] ?? null], '3.3 Blueprint::sanitize – the group is kept when known, "other" otherwise (a manifest from before 3.3 has none)');
        $this->assertSame([true, true, true], [
            str_contains(\Talea\Mcp\Prompts::get('draft_blueprint', [])['messages'][0]['content']['text'], 'kind of business. (1)'),
            str_contains(\Talea\Mcp\Prompts::get('draft_blueprint', ['business' => 'a bike shop'])['messages'][0]['content']['text'], 'kind of business: a bike shop.'),
            str_contains(\Talea\Mcp\Prompts::get('draft_blueprint', [])['messages'][0]['content']['text'], 'only after I agree')], '3.3 Prompt draft_blueprint: works without an argument, names the business when given, applies only after the owner agrees');
        $plansPreset = (array) \Talea\Builder\Presets::get('plans');
        $plansFields = \Talea\Builder\Collections::sanitizeFields(array_map(fn (array $f): array => ['key' => $f[0], 'label' => $f[1], 'type' => $f[2]], $plansPreset['fields']));
        $this->assertTrue(str_contains((string) json_encode(\Talea\Builder\Presets::listPage($plansPreset, 'Plans', 'plans', $plansFields)), '{{link}}'), '3.3.1 Presets: each card of the pricing plans has a button to the plan\'s link');
        $this->assertSame([], array_values(array_diff(['plans', 'menu', 'rooms', 'properties'], array_keys(\Talea\Builder\Presets::all()))), '3.3 Presets: pricing plans, a food and drink menu, rooms and property listings are offered');
    }
}
