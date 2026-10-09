<?php

declare(strict_types=1);

namespace Kaleta\Tests\Site\Support;

use Dom\HTMLDocument;

/**
 * Czech-in-English checks (was tools/test-english.sh): the visible text of a page goes through tools/find-czech.php, which lives in
 * the project (not in the site copy). Used by the classes in tests/Site/EnglishInstall.
 */
trait CzechCheck
{
    /** Asserts that a body has no Czech (--de: German admin, where German words spelled like Czech ones are fine). */
    protected function assertNoCzech(string $body, string $label, bool $german = false): void
    {
        $file = tempnam(sys_get_temp_dir(), 'kaleta-czech-');
        file_put_contents($file, $body);
        try {
            $command = [PHP_BINARY, dirname(__DIR__, 3) . '/tools/find-czech.php', ...($german ? ['--de'] : []), $file];
            $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
            $code = proc_close($process);
        } finally {
            @unlink($file);
        }
        $this->assertSame(0, $code, "$label: Czech text found\n$output");
    }

    /** Fetches a page, checks the status and that it has no Czech. */
    protected function assertCzechFree(string $path, int $status = 200, ?Http $as = null, bool $german = false, string $label = ''): Response
    {
        $response = ($as ?? $this->site()->client('visitor'))->get($path);
        $label = ($label !== '' ? $label . ' ' : '') . "($path)";
        $this->assertSame($status, $response->status, "$label: status");
        $this->assertNoCzech($response->body, $label, $german);

        return $response;
    }

    /** The page has exactly one h1 in main and its headings do not skip a level (the builder's pre-publish check). */
    protected function assertHeadings(string $body, string $label): void
    {
        $document = HTMLDocument::createFromString($body, LIBXML_NOERROR);
        $levels = array_map(static fn ($e): int => (int) $e->tagName[1], iterator_to_array($document->querySelectorAll('main h1, main h2, main h3, main h4, main h5, main h6')));
        $this->assertSame(1, count(array_keys($levels, 1)), "$label: exactly one h1");
        foreach ($levels as $i => $level) {
            if ($i > 0) {
                $this->assertLessThanOrEqual($levels[$i - 1] + 1, $level, "$label: headings skip a level (h" . $levels[$i - 1] . " → h$level)");
            }
        }
    }
}
