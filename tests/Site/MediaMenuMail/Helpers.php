<?php

declare(strict_types=1);

namespace Kaleta\Tests\Site\MediaMenuMail;

use Kaleta\Tests\Site\Support\Response;

/** Small helpers shared by the classes of this area (the old lib.sh functions they used). */
trait Helpers
{
    /** The administrator publishes the draft look from Site appearance (old publish_look). */
    private function publishLook(): void
    {
        $this->adminPost('/admin.php?module=appearance&action=publish_look', [], '/admin.php?module=appearance');
        $this->site()->clearPageCache();
    }

    /** A plain JPEG on disk; returns its path. */
    private function makeJpeg(string $name, int $width, int $height, array $rgb): string
    {
        $file = $this->site()->workDir('files') . '/' . $name;
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, ...$rgb));
        imagejpeg($image, $file);

        return $file;
    }

    /** Uploads one file to the media library; returns the answer. */
    private function uploadMedia(string $file): Response
    {
        $csrf = $this->site()->admin()->get('/admin.php?module=media')->csrf();

        return $this->site()->admin()->upload('/admin.php?module=media&action=upload', ['_csrf' => $csrf], ['soubory[]' => $file]);
    }

    /** The text of an MCP tool answer (the raw JSON string of the tool). */
    private function mcpText(string $tool, array|object $arguments = []): string
    {
        return (string) ($this->site()->mcp($tool, $arguments)['result']['content'][0]['text'] ?? '');
    }
}
