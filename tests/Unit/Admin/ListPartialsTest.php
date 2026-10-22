<?php

declare(strict_types=1);

namespace Talea\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use Talea\Core\View;

/** The shared building blocks of admin lists (docs/specs/admin-lists.md). */
final class ListPartialsTest extends TestCase
{
    private View $view;

    protected function setUp(): void
    {
        $this->view = new View([TALEA_ROOT . '/system/views']);
    }

    public function testHeadFillsSlots(): void
    {
        $html = $this->view->render('admin/list/head', ['count' => '19 <pages>', 'links' => [['?a', 'Categories']], 'primary' => ['?new', 'New page']]);
        $this->assertSame('', $html, 'the head renders into the layout, not in place');
        $this->assertSame('19 &lt;pages&gt;', $this->view->slot('count'));
        $actions = $this->view->slot('actions');
        $this->assertStringContainsString('<a class="quiet-link" href="?a">Categories</a>', $actions);
        $this->assertMatchesRegularExpression('#<a class="btn" href="\?new"><svg[^>]*>.*</svg>New page</a>#s', $actions);
    }

    public function testSlotIsEmptyUntilFilled(): void
    {
        $this->assertSame('', $this->view->slot('count'));
    }
}
