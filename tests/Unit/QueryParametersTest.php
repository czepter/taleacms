<?php

declare(strict_types=1);

namespace Talea\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class QueryParametersTest extends TestCase
{
    /** query parameters are English (the former Czech names must not come back) */
    public function testQueryParametersAreEnglish(): void
    {
        $czechParams = 'nahled_klic|nahled_konec|nahled|stavba|polozka|varianta|vysledek|upravit|uprava|uprav|strana|hledat|razeni|soubor|preklad_z|sekce|pohled|komentar|odhlasit|potvrdit|tema|typ|klic|stav|jazyk|cast|umisteni|pole|nova|nepouzite|osoba|sluzba|kdo|kde|kategorie|clanek|heslo|idr|dni|nadrazena|odber|mnozstvi|rezervace|formular|chyba|produkt';
        $czechFound = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(TALEA_ROOT . '/system/src', \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() === 'php') {
                $code = (string) file_get_contents($file->getPathname());
                if (preg_match_all('/(?:request|\$r)->(?:get|getInt|has)\([\'"](' . $czechParams . ')[\'"]|[?&](' . $czechParams . ')=/', $code, $m)) {
                    $czechFound[] = basename($file->getPathname()) . ': ' . implode(', ', array_filter(array_merge($m[1], $m[2])));
                }
            }
        }
        $this->assertSame([], $czechFound, 'query parameters: no Czech parameter name in the PHP code (the interface is English)');
    }
}
