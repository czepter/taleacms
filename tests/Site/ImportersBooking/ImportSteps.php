<?php

declare(strict_types=1);

namespace Kaleta\Tests\Site\ImportersBooking;

use Kaleta\Tests\Site\Support\Response;

/** The steps of the "From another system" import (module transfer) shared by the importer tests; old helpers src_batch, *_run. */
trait ImportSteps
{
    /** POST of one transfer action as the administrator. @param array<string, mixed> $fields */
    private function transfer(string $action, array $fields = []): Response
    {
        $csrf = $this->site()->admin()->get('/admin.php?module=transfer')->csrf();

        return $this->site()->admin()->post('/admin.php?module=transfer&action=' . $action, ['_csrf' => $csrf] + $fields);
    }

    /** One batch of the import of a source file (old src_batch). */
    private function batch(string $file): Response
    {
        return $this->transfer('source_progress&file=' . $file);
    }

    private function preview(string $file): Response
    {
        return $this->site()->admin()->get('/admin.php?module=transfer&action=source_preview&file=' . $file);
    }

    /** Uploads a source file for a system (old: curl -F system=… -F soubor=@…). */
    private function uploadSource(string $system, string $path): void
    {
        $csrf = $this->site()->admin()->get('/admin.php?module=transfer')->csrf();
        $this->site()->admin()->upload('/admin.php?module=transfer&action=source_upload', ['_csrf' => $csrf, 'system' => $system], ['file' => $path]);
    }

    /** Starts the import with the usual options, then runs the first batch. @param array<string, mixed> $extra */
    private function runImport(string $file, array $extra = []): Response
    {
        $this->transfer('source_run', ['file' => $file, 'posts' => 'news', 'pages' => 'page', 'categories' => 'category', 'tags' => 'tag',
            'drafts' => '1', 'builder' => '1', 'redirects' => '1', 'default_category' => '0'] + $extra);

        return $this->batch($file);
    }

    /** Starts the image download and runs batches until it says it is done (old: for i in 1..10). */
    private function downloadImages(string $file): void
    {
        $this->transfer('source_images', ['file' => $file]);
        for ($i = 0; $i < 10; $i++) {
            $answer = $this->batch($file);
            if (str_contains($answer->body, 'images downloaded') || preg_match('/Staženo .* obrázků/', $answer->body) === 1) {
                return;
            }
        }
    }

    /** Status and redirect of an anonymous request: ['301', 'http://…']. @return array{int, string} */
    private function anonymous(string $path): array
    {
        $response = $this->site()->client('anon')->get($path);

        return [$response->status, $response->redirect];
    }

    private function skippedTile(Response $response, int $count): bool
    {
        return str_contains($response->body, 'dlazdice-polozka"><strong>' . $count . '</strong><span>Skipped') || str_contains($response->body, '<strong>' . $count . '</strong><span>Přeskočeno');
    }
}
