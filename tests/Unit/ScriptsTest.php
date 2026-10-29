<?php

declare(strict_types=1);

namespace Talea\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class ScriptsTest extends TestCase
{
    /** admin and site scripts without system dialogs (they cannot be styled or translated, and browsers suppress them) */
    public function testNoNativeDialogs(): void
    {
        $nativeDialogs = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(TALEA_ROOT . '/image', \FilesystemIterator::SKIP_DOTS)) as $file) {
            $path = substr($file->getPathname(), strlen(TALEA_ROOT) + 1); // image/**/*.js: the builder's modules in image/builder/ too
            if (!str_ends_with($path, '.js') || str_starts_with($path, 'image/languages/') || str_starts_with($path, 'image/vendor/')) {
                continue; // scripts only, not the dictionaries or third-party code
            }
            $code = preg_replace(['#/\*.*?\*/#s', '#(^|[^:])//.*$#m'], ['', '$1'], (string) file_get_contents($file->getPathname())); // without comments
            if (preg_match_all('/\b(alert|prompt|confirm)\s*\(/', (string) $code, $m)) {
                $nativeDialogs[] = $path . ': ' . implode(', ', array_unique($m[1]));
            }
        }
        $this->assertSame([], $nativeDialogs, 'Skripty bez window.alert/prompt/confirm');
    }

    /** scripts: must not look for an element (data attribute) that is never created – that is how the Media dialog broke */
    public function testSearchedDataAttributesAreRendered(): void
    {
        $whereCreated = [
            'image/editor.js' => ['system/views/admin'], 'image/admin.js' => ['system/views/admin', 'system/src/Admin'], 'image/helper.js' => ['system/views/admin'],
            'image/web.js' => ['system/views/front', 'system/src/Front', 'system/src/Builder/Elements'],
            'image/vitals.js' => ['system/src/Front'],
            'image/forms.js' => ['system/src/Builder/Elements'],
            'image/print.js' => ['system/views/admin/settings'],
        ];
        foreach ($whereCreated as $script => $folders) {
            $source = (string) file_get_contents(TALEA_ROOT . '/' . $script);
            preg_match_all('/querySelector(?:All)?\(\'\[(data-[a-z0-9-]+)\]\'\)/', $source, $links);
            $withoutSearch = (string) preg_replace('/(querySelector(All)?|closest|matches)\([^)]*\)/', '', $source);
            $missing = [];
            foreach (array_unique($links[1]) as $attribute) {
                $inTemplates = false;
                foreach ($folders as $folder) {
                    foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(TALEA_ROOT . '/' . $folder, \FilesystemIterator::SKIP_DOTS)) as $file) {
                        $inTemplates = $inTemplates || str_contains((string) file_get_contents($file->getPathname()), $attribute);
                    }
                }
                if (!$inTemplates && !preg_match('/[\s"\']' . preg_quote($attribute, '/') . '[\s>="\']/', $withoutSearch) && !str_contains($withoutSearch, "setAttribute('" . $attribute . "'")) {
                    $missing[] = $attribute;
                }
            }
            $this->assertSame([], $missing, $script . ': every searched data-attribute is rendered somewhere');
        }
    }

    /** the admin has a Content-Security-Policy without 'unsafe-inline': no inline scripts or event handlers */
    public function testAdminHasNoInlineScripts(): void
    {
        $inline = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(TALEA_ROOT . '/system/views/admin', \FilesystemIterator::SKIP_DOTS)) as $file) {
            $source = (string) file_get_contents($file->getPathname());
            if (preg_match('#<script(?![^>]*\bsrc=)(?![^>]*type="application/json")[^>]*>|\son(?:click|change|input|submit|load|error|key\w+|mouse\w+)="#i', $source)) {
                $inline[] = substr($file->getPathname(), strlen(TALEA_ROOT) + 1);
            }
        }
        $this->assertSame([], $inline, 'admin templates contain no inline scripts (CSP)');
    }
}
