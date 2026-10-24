<?php

declare(strict_types=1);

namespace Talea\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use Talea\Admin\Kernel;

final class AdminPathTest extends TestCase
{
    /** admin.php?module=…&action=…&id=… becomes /admin/<module>/<action>/<id>; the rest stays a query */
    public function testCleanAddresses(): void
    {
        $this->assertSame('admin', Kernel::path(''));
        $this->assertSame('admin/appearance/looks', Kernel::path('module=appearance&action=looks'));
        $this->assertSame('admin/news/edit/1b4e28ba-2fa1-4d3b-a3f5-ef19b5a7633b?page=2#top', Kernel::path('module=news&action=edit&id=1b4e28ba-2fa1-4d3b-a3f5-ef19b5a7633b&page=2#top'));
        $this->assertSame('admin/settings?tab=mail', Kernel::path('module=settings&tab=mail'));
        $this->assertSame('admin/redirects?id=5', Kernel::path('module=redirects&id=5'), 'an id without an action stays in the query');
        $this->assertSame('admin/account#claude', Kernel::path('action=account#claude'), 'the administration’s own action has no module');
        $this->assertSame('admin?action=look', Kernel::path('action=look'), 'any other action without a module would read as a module');
        $this->assertSame('admin/parts/builder?type=header&language=de%2Dat', Kernel::path('module=parts&action=builder&type=header&language=de%2Dat'));
        $this->assertSame('admin/media?id=a%2Fb', Kernel::path('module=media&id=a%2Fb'), 'a value that is no safe segment stays in the query');
        $this->assertSame('admin#not-found', Kernel::path('#not-found'));
    }
}
