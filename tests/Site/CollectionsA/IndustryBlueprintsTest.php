<?php

declare(strict_types=1);

namespace Kaleta\Tests\Site\CollectionsA;

use Kaleta\Tests\Site\Support\SiteTestCase;
use PHPUnit\Framework\Attributes\Group;

/** 2.11 industry blueprints (was: section 59 of tools/test.sh). The earlier teams of section 56 never exist here, so the old "reset the preset" step is not needed. */
#[Group('site')]
final class IndustryBlueprintsTest extends SiteTestCase
{
    use CollectionsHelpers;

    /** @return array<string, mixed> */
    private function manifest(bool $withBadPreset): array
    {
        return [
            'kaleta_blueprint' => 1, 'key' => 'dental_test', 'name' => ['en' => 'Dental clinic'], 'description' => 'For dentists',
            'presets' => $withBadPreset ? ['people', 'faq_unknown_is_refused'] : ['people'],
            'facts' => [['key' => 'insurers', 'label' => 'Insurers', 'type' => 'text']],
            'questions' => [['question' => 'Which insurers do you have contracts with?', 'fact' => 'insurers']],
            'audit' => [['check' => 'fact', 'fact' => 'insurers', 'message' => 'Say which insurers you work with.'], ['check' => 'preset_items', 'preset' => 'people', 'min' => 1, 'message' => 'Add the doctors.']],
            'claude' => 'Patients look for insurers first; never give medical advice.',
        ];
    }

    public function testAnInvalidManifestIsRefusedWhole(): void
    {
        $text = $this->mcpText('apply_blueprint', ['manifest' => $this->manifest(true)]);
        $this->assertStringContainsString('faq_unknown_is_refused', $text, 'the unknown preset is named');
        $this->assertSame('0', $this->sq('SELECT COUNT(*) FROM ka_blueprints'), 'a manifest with an unknown preset is refused whole');
    }

    public function testApplyingCreatesTheTeamAndTheFact(): void
    {
        $text = $this->mcpText('apply_blueprint', ['manifest' => $this->manifest(false)]);
        $this->assertMatchesRegularExpression('/created_facts.*insurers/s', $text, 'apply_blueprint reports the created fact');
        $this->assertSame('1|Insurers=|dental_test', $this->sq("SELECT CONCAT((SELECT COUNT(*) FROM ka_collections WHERE preset = 'people'), '|', (SELECT CONCAT(label, '=', value) FROM ka_facts WHERE fact_key = 'insurers'), '|', (SELECT bkey FROM ka_blueprints WHERE name = 'Dental clinic'))"), 'applying creates the team collection, the fact without a value and keeps the manifest');

        $blueprint = $this->mcpText('get_blueprint');
        $this->assertStringContainsString('Which insurers do you have contracts with', $blueprint, 'Claude sees the open question');
        $this->assertStringContainsString('Say which insurers you work with', $blueprint, '... and the failing checks');
        $this->assertStringContainsString('Add the doctors', $blueprint);

        $initialize = $this->site()->mcpRaw('{"jsonrpc":"2.0","id":1,"method":"initialize","params":{}}');
        $this->assertStringContainsString('never give medical advice', (string) json_encode($initialize, JSON_UNESCAPED_UNICODE), "the instructions of every Claude connection include the blueprint's");

        $this->assertStringContainsString('Say which insurers you work with', $this->mcpText('site_audit', ['kind' => 'blueprint']), 'the site audit runs its checks');
    }

    public function testAnsweredBlueprintPassesAndExports(): void
    {
        $this->mcpText('save_fact', ['key' => 'insurers', 'value' => 'VZP, OZP']);
        $this->mcpText('save_collection_item', ['collection' => $this->sq("SELECT slug FROM ka_collections WHERE preset = 'people' LIMIT 1"), 'name' => 'MUDr. Test', 'visible' => true]);
        $audit = $this->mcpText('site_audit', ['kind' => 'blueprint']);
        $this->assertStringNotContainsString('Say which insurers', $audit, 'answered, the fact check passes');
        $this->assertStringNotContainsString('Add the doctors', $audit, '... and filled in, the team check passes');

        $this->assertPage('/admin.php?module=blueprints', 200, 'value="VZP, OZP"', message: 'the admin page shows the applied blueprint and its question with the answer');

        $export = $this->mcpText('export_blueprint', ['key' => 'my_clinic', 'name' => 'My clinic']);
        $this->assertStringContainsString('kaleta_blueprint', $export, 'the export is a blueprint');
        $this->assertStringContainsString('insurers', $export, '... with the facts');
        $this->assertStringNotContainsString('VZP', $export, '... never their values');
        $this->assertStringContainsString('people', $export, '... and the presets');

        $download = $this->site()->admin()->get('/admin.php?module=blueprints&action=export&key=my_clinic&name=Moje');
        $this->assertStringContainsString('filename="my_clinic.blueprint.json"', $download->headers['content-disposition'] ?? '', 'the admin downloads the site as a blueprint file');
        $this->assertSame(1, ($download->json()['kaleta_blueprint'] ?? null), 'the download is a blueprint');

        $this->mcpText('remove_blueprint', ['key' => 'dental_test']);
        $this->assertSame('0|1|VZP, OZP', $this->sq("SELECT CONCAT((SELECT COUNT(*) FROM ka_blueprints), '|', (SELECT COUNT(*) FROM ka_collections WHERE preset = 'people'), '|', (SELECT value FROM ka_facts WHERE fact_key = 'insurers'))"), 'removing keeps the collection and the fact');
    }

    /** 3.3: twenty blueprints in groups with a search; get_blueprint tells Claude what a manifest of its own may contain. */
    public function testShippedBlueprintsAreGroupedAndApplicable(): void
    {
        $screen = $this->assertPage('/admin.php?module=blueprints', 200, 'data-filter-cards', message: 'the screen groups the shipped blueprints, has a search');
        $this->assertGreaterThanOrEqual(5, substr_count($screen->body, 'data-filter-group'), 'at least five groups');
        $this->assertStringContainsString('name="quick" value="1"', $screen->body, 'the request form for a blueprint of one\'s own');

        $blueprint = $this->mcpText('get_blueprint');
        $this->assertStringContainsString('manifest_format', $blueprint, 'get_blueprint lists the manifest format for draft_blueprint');
        $this->assertStringContainsString('audit_rules', $blueprint);
        $this->assertMatchesRegularExpression('/group.*software_saas|software_saas.*group/s', $blueprint, '... and the groups');

        $before = (int) $this->sq('SELECT IFNULL(MAX(collection_id), 0) FROM ka_collections');
        $this->mcpText('apply_blueprint', ['key' => 'software_saas']);
        $this->assertSame('1', $this->sq("SELECT COUNT(*) FROM ka_collections WHERE preset = 'plans'"), 'the software company blueprint creates the pricing plans collection');

        // the site as before: the blueprint off, its collections and their hidden pages gone
        $this->mcpText('remove_blueprint', ['key' => 'software_saas']);
        foreach ($this->site()->rows('SELECT slug FROM ka_collections WHERE collection_id > ?', [$before]) as $row) {
            $this->mcpText('delete_collection', ['collection' => $row['slug']]);
            $this->site()->exec('DELETE FROM ka_pages WHERE slug IN (?, ?) AND visible = 0', [$row['slug'], $row['slug'] . '-archive']);
        }
    }
}
