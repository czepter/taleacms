<?php

declare(strict_types=1);

namespace Kaleta\Tests\Site\SiteMoveSecurity;

use Kaleta\Tests\Site\Support\Site;

/** State the old sections 37, 38 and 40 took from earlier sections (media upload, the "Team" collection), recreated in the simplest way. */
trait SiteFixtures
{
    /** Uploads one generated JPEG into the media library as the administrator (old section 28). */
    protected function uploadPhoto(Site $site): void
    {
        $file = $site->workDir('fixtures') . '/photo.jpg';
        $image = imagecreatetruecolor(800, 600);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, 200, 120, 40));
        imagejpeg($image, $file);
        $site->admin()->upload('/admin.php?module=media&action=upload', ['_csrf' => $site->csrf()], ['files[]' => $file]);
    }

    /** The "Team" collection with the visible Jane Novak, a hidden member and Susan Green (old section 13). */
    protected function createTeam(Site $site): void
    {
        $site->admin()->post('/admin.php?module=collections&action=save', ['_csrf' => $site->csrf(), 'collection_id' => 0, 'name' => 'Team', 'detail' => 1, 'fields' => [
            ['label' => 'Role', 'type' => 'text'], ['label' => 'Photo', 'type' => 'image'], ['label' => 'Bio', 'type' => 'html'],
        ]]);
        $idk = (int) $site->value("SELECT collection_id FROM ka_collections WHERE slug = 'team'");
        $save = fn (array $fields) => $site->admin()->post('/admin.php?module=collections&action=save_item', ['_csrf' => $site->csrf(), 'collection_id' => $site->publicId('collections', $idk), 'item_id' => 0] + $fields);
        $save(['name' => 'Jane Novak', 'data' => ['role' => 'Managing director', 'bio' => '<p>Twenty years <b>in the trade</b>.</p>'], 'sort_order' => 1, 'visible' => 1]);
        $save(['name' => 'Hidden Member', 'data' => ['role' => 'Secret'], 'sort_order' => 2]);
        $site->mcp('save_collection_item', ['collection' => 'team', 'name' => 'Susan Green', 'values' => ['role' => 'Managing director'], 'visible' => true]);
    }

    /** The raw JSON-RPC answer as text, for the old `grep` on an MCP response. */
    protected function mcpRawText(Site $site, string $tool, array|object $arguments = [], ?string $token = null): string
    {
        return (string) json_encode($site->mcp($tool, $arguments, $token), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** The newest backup file of the site (not the pre-restore ones). */
    protected function newestBackup(Site $site): string
    {
        $files = array_filter(glob($site->path('storage/backups/*')) ?: [], static fn (string $f): bool => is_file($f) && !str_contains($f, 'before_restore'));
        usort($files, static fn (string $a, string $b): int => filemtime($b) <=> filemtime($a));

        return $files === [] ? '' : basename($files[0]);
    }
}
