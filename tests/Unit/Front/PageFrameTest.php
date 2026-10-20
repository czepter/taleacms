<?php

declare(strict_types=1);

namespace Talea\Tests\Unit\Front;

use PHPUnit\Framework\TestCase;

final class PageFrameTest extends TestCase
{
    /** themeless (1.6) */
    public function testThemeless(): void
    {
        $this->assertSame([true, false, false], [is_file(TALEA_ROOT . '/system/views/front/base.php'), is_dir(TALEA_ROOT . '/layout'), isset(\Talea\Core\Settings::DEFAULTS['layout'])], 'Themeless: the page frame is the system’s own, no layout folder and no layout setting');
    }
}
