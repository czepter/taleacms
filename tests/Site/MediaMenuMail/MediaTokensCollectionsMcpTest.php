<?php

declare(strict_types=1);

namespace Kaleta\Tests\Site\MediaMenuMail;

use Kaleta\Tests\Site\Support\SiteTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Media library caption, DTCG design tokens, collection items through MCP (was: section 36 of tools/test.sh; the picture came from
 * section 28 and the "Team" collection with Peter Smith from section 13).
 */
#[Group('site')]
final class MediaTokensCollectionsMcpTest extends SiteTestCase
{
    use Helpers;

    private static string $ownExport = '';

    public function testMediaSearchSortAndCaptionSavedInPlace(): void
    {
        $this->uploadMedia($this->makeJpeg('foto.jpg', 1600, 900, [200, 80, 40]));
        $ido = (int) $this->site()->value("SELECT media_id FROM ka_media WHERE image_path LIKE '%.jpg' ORDER BY media_id DESC LIMIT 1");
        $this->assertGreaterThan(0, $ido, 'a picture is in the media library');

        $this->assertPage('/admin.php?module=media&search=jpg&sort=size', 200, 'data-description-media=', message: 'media: search and sorting');

        $csrf = $this->site()->admin()->get('/admin.php?module=media')->csrf();
        $reply = $this->site()->admin()->post('/admin.php?module=media&action=save_caption', ['_csrf' => $csrf, 'media_id' => $this->site()->publicId('media', $ido), 'name' => 'Dilna zevnitr']);
        $this->assertSame('{"ok":true}|Dilna zevnitr', $reply->body . '|' . $this->site()->value('SELECT name FROM ka_media WHERE media_id = ?', [$ido]), 'the picture caption without reloading');
    }

    public function testDesignTokensExportAndImport(): void
    {
        $export = $this->site()->admin()->get('/admin.php?module=appearance&action=tokens');
        $this->assertStringContainsString('"$type": "color"', $export->body, 'DTCG tokens export: colour type');
        $this->assertStringContainsString('"cz.kaleta"', $export->body, 'DTCG tokens export: the Kaleta extension');
        self::$ownExport = $export->body;

        $foreign = $this->site()->workDir('files') . '/cizi.tokens.json';
        file_put_contents($foreign, '{"color":{"primary":{"$type":"color","$value":"#aa3300"}}}');
        $this->importTokens($foreign);
        $this->publishLook();
        $this->assertSame('#aa3300', $this->site()->value("SELECT JSON_UNQUOTE(JSON_EXTRACT(value, '$.colors.primary')) FROM ka_settings WHERE name = 'design_system'"), 'import of colours from foreign tokens');

        $own = $this->site()->workDir('files') . '/tokeny.json';
        file_put_contents($own, self::$ownExport);
        $this->importTokens($own);
        $this->publishLook();
        $this->assertSame('1', (string) $this->site()->value("SELECT JSON_UNQUOTE(JSON_EXTRACT(value, '$.colors.primary')) <> '#aa3300' FROM ka_settings WHERE name = 'design_system'"), 'importing its own export brings the look back');
    }

    public function testCollectionItemsThroughMcp(): void
    {
        $site = $this->site();
        // the "Team" collection with its text field "Role" and a displayed item (as the collections section made it)
        $this->adminPost('/admin.php?module=collections&action=save', ['collection_id' => 0, 'name' => 'Team', 'detail' => 1,
            'fields' => [['label' => 'Role', 'type' => 'text'], ['label' => 'Photo', 'type' => 'image'], ['label' => 'Bio', 'type' => 'html']]], '/admin.php?module=collections');
        $site->mcp('save_collection_item', ['collection' => 'team', 'name' => 'Peter Smith', 'values' => ['role' => 'Master carpenter'], 'visible' => true]);

        $filtered = $this->mcpText('list_collection_items', ['collection' => 'team', 'field' => 'role', 'value' => 'Master carpenter']);
        $this->assertStringContainsString('Peter Smith', $filtered, 'collection through MCP: filter by field (the item)');
        $this->assertStringContainsString('"total":1', $filtered, 'collection through MCP: filter by field (the total)');

        $idp = (int) $site->value("SELECT item_id FROM ka_collection_items WHERE name = 'Peter Smith'");
        $site->mcp('save_collection_item', ['collection' => 'team', 'id' => $site->publicId('collection_items', $idp), 'values' => ['role' => 'Workshop lead']]);
        $this->assertSame('Peter Smith|1', $site->value("SELECT CONCAT(name, '|', data LIKE '%Workshop lead%') FROM ka_collection_items WHERE item_id = ?", [$idp]), 'collection through MCP: editing an item without a name keeps the name');
    }

    private function importTokens(string $file): void
    {
        $csrf = $this->site()->admin()->get('/admin.php?module=appearance')->csrf();
        $this->site()->admin()->upload('/admin.php?module=appearance&action=tokens_import', ['_csrf' => $csrf], ['tokens' => $file]);
    }
}
