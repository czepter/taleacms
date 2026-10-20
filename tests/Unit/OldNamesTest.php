<?php

declare(strict_types=1);

namespace Talea\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class OldNamesTest extends TestCase
{
    /** old class names */
    public function testOldClassNamesAreGone(): void
    {
        // 2.0: the old (Czech) class names and helpers of 1.3 are gone; 2.0.1 dropped the empty alias file (it rides along in packages only)
        $this->assertSame([false, false, false, false, false, false], [is_file(TALEA_SYSTEM . '/class-aliases.php'), class_exists('Talea\\Jadro\\Nastaveni'), function_exists('date_in_words'),
            class_exists('Talea\\Admin\\LegacyUrls'), class_exists('Talea\\Front\\Api'), class_exists('Talea\\Builder\\Elements\\Modal')], '2.0: old class names and helpers no longer exist');
    }
}
