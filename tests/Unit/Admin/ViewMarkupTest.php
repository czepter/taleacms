<?php

declare(strict_types=1);

namespace Talea\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;

final class ViewMarkupTest extends TestCase
{
    public function testNoBlockElementInsideAParagraph(): void
    {
        // a <p> ends where a <form>, <div>, list or table starts: the browser closes it there, so a <p class="navigation-row"> around
        // forms lost its flex row and the buttons touched (the requests to confirm in Bookings). A row of forms is a <div class="navigation-row">.
        $found = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(TALEA_SYSTEM . '/views', \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            // PHP blocks out, line breaks kept so the line numbers stay right
            $html = (string) preg_replace_callback('/<\?(?:php|=).*?(?:\?>|$)/s', fn (array $m): string => str_repeat("\n", substr_count($m[0], "\n")), (string) file_get_contents($file->getPathname()));
            preg_match_all('#<p(?:\s[^>]*)?>((?:(?!</p>).)*?)<(form|div|section|ul|ol|table|fieldset|details|dialog|h[1-6])\b#s', $html, $matches, PREG_OFFSET_CAPTURE);
            foreach ($matches[0] as $match) {
                $found[] = substr($file->getPathname(), strlen(TALEA_SYSTEM) + 1) . ':' . (substr_count($html, "\n", 0, $match[1]) + 1);
            }
        }
        $this->assertSame([], $found, 'a block element inside a <p> closes the paragraph and drops its layout');
    }
}
