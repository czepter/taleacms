<?php

declare(strict_types=1);

namespace Kaleta\Tests\Site\MediaMenuMail;

use Kaleta\Tests\Site\Support\SiteTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Media library caption, DTCG design tokens, collection items through MCP (was: section 36 of tools/test.sh; the picture came from
 * section 28 and the "Tým" collection with Petr Svoboda from section 13).
 */
#[Group('site')]
final class MediaTokensCollectionsMcpTest extends SiteTestCase
{
    use Helpers;

    private static string $ownExport = '';

    public function testMediaSearchSortAndCaptionSavedInPlace(): void
    {
        $this->uploadMedia($this->makeJpeg('foto.jpg', 1600, 900, [200, 80, 40]));
        $ido = (int) $this->site()->value("SELECT ido FROM ka_media WHERE obr_poloha LIKE '%.jpg' ORDER BY ido DESC LIMIT 1");
        $this->assertGreaterThan(0, $ido, 'a picture is in the media library');

        $this->assertPage('/admin.php?module=media&search=jpg&sort=velikost', 200, 'data-popis-media=', message: 'media: search and sorting');

        $csrf = $this->site()->admin()->get('/admin.php?module=media')->csrf();
        $reply = $this->site()->admin()->post('/admin.php?module=media&action=save_caption', ['_csrf' => $csrf, 'ido' => $ido, 'popis' => 'Dilna zevnitr']);
        $this->assertSame('{"ok":true}|Dilna zevnitr', $reply->body . '|' . $this->site()->value('SELECT nazev FROM ka_media WHERE ido = ?', [$ido]), 'the picture caption without reloading');
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
        $this->assertSame('#aa3300', $this->site()->value("SELECT JSON_UNQUOTE(JSON_EXTRACT(hodnota, '$.barvy.primarni')) FROM ka_nastaveni WHERE promenna = 'design_system'"), 'import of colours from foreign tokens');

        $own = $this->site()->workDir('files') . '/tokeny.json';
        file_put_contents($own, self::$ownExport);
        $this->importTokens($own);
        $this->publishLook();
        $this->assertSame('1', (string) $this->site()->value("SELECT JSON_UNQUOTE(JSON_EXTRACT(hodnota, '$.barvy.primarni')) <> '#aa3300' FROM ka_nastaveni WHERE promenna = 'design_system'"), 'importing its own export brings the look back');
    }

    public function testCollectionItemsThroughMcp(): void
    {
        $site = $this->site();
        // the "Tým" collection with its text field "Funkce" and a displayed item (as the collections section made it)
        $this->adminPost('/admin.php?module=collections&action=save', ['idk' => 0, 'nazev' => 'Tým', 'detail' => 1,
            'pole' => [['popisek' => 'Funkce', 'typ' => 'text'], ['popisek' => 'Foto', 'typ' => 'obrazek'], ['popisek' => 'Medailonek', 'typ' => 'html']]], '/admin.php?module=collections');
        $site->mcp('uloz_polozku_kolekce', ['kolekce' => 'tym', 'nazev' => 'Petr Svoboda', 'data' => ['funkce' => 'Mistr truhlář'], 'zobrazit' => true]);

        $filtered = $this->mcpText('seznam_polozek_kolekce', ['kolekce' => 'tym', 'pole' => 'funkce', 'hodnota' => 'Mistr truhlář']);
        $this->assertStringContainsString('Petr Svoboda', $filtered, 'collection through MCP: filter by field (the item)');
        $this->assertStringContainsString('"celkem":1', $filtered, 'collection through MCP: filter by field (the total)');

        $idp = (int) $site->value("SELECT idp FROM ka_kolekce_polozky WHERE nazev = 'Petr Svoboda'");
        $site->mcp('uloz_polozku_kolekce', ['kolekce' => 'tym', 'id' => $idp, 'data' => ['funkce' => 'Vedouci dilny']]);
        $this->assertSame('Petr Svoboda|1', $site->value("SELECT CONCAT(nazev, '|', data LIKE '%Vedouci dilny%') FROM ka_kolekce_polozky WHERE idp = ?", [$idp]), 'collection through MCP: editing an item without a name keeps the name');
    }

    private function importTokens(string $file): void
    {
        $csrf = $this->site()->admin()->get('/admin.php?module=appearance')->csrf();
        $this->site()->admin()->upload('/admin.php?module=appearance&action=tokens_import', ['_csrf' => $csrf], ['tokeny' => $file]);
    }
}
