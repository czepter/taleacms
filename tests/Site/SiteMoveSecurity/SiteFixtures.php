<?php

declare(strict_types=1);

namespace Kaleta\Tests\Site\SiteMoveSecurity;

use Kaleta\Tests\Site\Support\Site;

/** State the old sections 37, 38 and 40 took from earlier sections (media upload, the "Tým" collection), recreated in the simplest way. */
trait SiteFixtures
{
    /** Uploads one generated JPEG into the media library as the administrator (old section 28). */
    protected function uploadPhoto(Site $site): void
    {
        $file = $site->workDir('fixtures') . '/foto.jpg';
        $image = imagecreatetruecolor(800, 600);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, 200, 120, 40));
        imagejpeg($image, $file);
        $site->admin()->upload('/admin.php?module=media&action=upload', ['_csrf' => $site->csrf()], ['soubory[]' => $file]);
    }

    /** The "Tým" collection with the visible Jana Nováková, a hidden member and Zuzana Zelena (old section 13). */
    protected function createTeam(Site $site): void
    {
        $site->admin()->post('/admin.php?module=collections&action=save', ['_csrf' => $site->csrf(), 'collection_id' => 0, 'name' => 'Tým', 'detail' => 1, 'fields' => [
            ['popisek' => 'Funkce', 'type' => 'text'], ['popisek' => 'Foto', 'type' => 'image'], ['popisek' => 'Medailonek', 'type' => 'html'],
        ]]);
        $idk = (int) $site->value("SELECT collection_id FROM ka_collections WHERE slug = 'tym'");
        $save = fn (array $fields) => $site->admin()->post('/admin.php?module=collections&action=save_item', ['_csrf' => $site->csrf(), 'collection_id' => $idk, 'item_id' => 0] + $fields);
        $save(['name' => 'Jana Nováková', 'data' => ['funkce' => 'Jednatelka', 'medailonek' => '<p>Dvacet let <b>v oboru</b>.</p>'], 'sort_order' => 1, 'visible' => 1]);
        $save(['name' => 'Skrytý Člen', 'data' => ['funkce' => 'Tajný'], 'poradi' => 2]);
        $site->mcp('uloz_polozku_kolekce', ['kolekce' => 'tym', 'nazev' => 'Zuzana Zelena', 'data' => ['funkce' => 'Jednatelka'], 'visible' => true]);
    }

    /** The raw JSON-RPC answer as text, for the old `grep` on an MCP response. */
    protected function mcpRawText(Site $site, string $tool, array|object $arguments = [], ?string $token = null): string
    {
        return (string) json_encode($site->mcp($tool, $arguments, $token), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** The newest backup file of the site (not the pre-restore ones). */
    protected function newestBackup(Site $site): string
    {
        $files = array_filter(glob($site->path('storage/zalohy/*')) ?: [], static fn (string $f): bool => is_file($f) && !str_contains($f, 'predobnovou'));
        usort($files, static fn (string $a, string $b): int => filemtime($b) <=> filemtime($a));

        return $files === [] ? '' : basename($files[0]);
    }
}
