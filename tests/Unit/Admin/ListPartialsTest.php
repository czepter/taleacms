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

    public function testToolbarMarksActiveTabAndKeepsKeyboardSubmit(): void
    {
        $html = $this->view->render('admin/list/toolbar', [
            'action' => 'admin.php', 'hidden' => ['module' => 'pages'], 'tabsLabel' => 'Status',
            'tabs' => [['label' => 'All', 'count' => 19, 'url' => '?all', 'active' => false], ['label' => 'Hidden', 'count' => 1, 'url' => '?hidden', 'active' => true]],
            'search' => ['name' => 'search', 'value' => 'a"b', 'placeholder' => 'Name or address', 'label' => 'Search pages'],
        ]);
        $this->assertStringContainsString('<nav class="list-tabs" aria-label="Status">', $html);
        $this->assertMatchesRegularExpression('#<a href="\?hidden" aria-current="page">Hidden <span class="list-tab-count">1</span></a>#', $html);
        $this->assertStringContainsString('<a href="?all">All <span class="list-tab-count">19</span></a>', $html);
        $this->assertStringContainsString('method="get"', $html);
        $this->assertStringContainsString('<input type="hidden" name="module" value="pages">', $html);
        $this->assertStringContainsString('type="search" name="search" value="a&quot;b"', $html);
        $this->assertMatchesRegularExpression('#<button type="submit" class="visually-hidden">Search</button>#', $html);
    }

    public function testToolbarWithoutSearchHasNoForm(): void
    {
        $html = $this->view->render('admin/list/toolbar', ['tabs' => [['label' => 'All', 'url' => '?', 'active' => true]], 'tabsLabel' => 'Status']);
        $this->assertStringNotContainsString('<form', $html);
        $this->assertStringContainsString('aria-current="page">All</a>', $html);
    }

    public function testStatusRendersDotWordAndNote(): void
    {
        $html = $this->view->render('admin/list/status', ['kind' => 'hidden', 'label' => 'Hidden', 'note' => 'review by 01.11.']);
        $this->assertStringContainsString('<span class="status status-hidden">Hidden</span>', $html);
        $this->assertStringContainsString('<span class="status-note status-note-warning">review by 01.11.</span>', $html);
        $linked = $this->view->render('admin/list/status', ['kind' => 'error', 'label' => 'Failed', 'note' => '2 broken links', 'noteKind' => 'error', 'noteUrl' => '?links']);
        $this->assertStringContainsString('<a class="status-note status-note-error" href="?links">2 broken links</a>', $linked);
    }

    public function testStatusRendersSeveralNotes(): void
    {
        $html = $this->view->render('admin/list/status', ['kind' => 'live', 'label' => 'Published', 'notes' => [
            ['text' => 'Changes not published', 'url' => '?builder'], ['text' => '2 comments', 'kind' => 'error'],
        ]]);
        $this->assertStringContainsString('<a class="status-note status-note-warning" href="?builder">Changes not published</a>', $html);
        $this->assertStringContainsString('<span class="status-note status-note-error">2 comments</span>', $html);
    }

    public function testActionsMenuHasPopoverAndDangerLast(): void
    {
        $html = $this->view->render('admin/list/actions', [
            'id' => 'u1', 'name' => 'About us', 'edit' => '?builder', 'csrf' => '<input type="hidden" name="csrf" value="x">',
            'menu' => [['url' => '?edit', 'label' => 'Settings'], '-', ['post' => '?delete', 'fields' => ['page_id' => 'u1'], 'label' => 'Move to trash', 'confirm' => 'Sure?', 'danger' => true]],
        ]);
        $this->assertStringContainsString('<a class="icon-btn" href="?builder" aria-label="Edit: About us" title="Edit">', $html);
        $this->assertStringContainsString('popovertarget="row-menu-u1"', $html);
        $this->assertStringContainsString('aria-label="More actions for About us"', $html);
        $this->assertStringContainsString('id="row-menu-u1" popover', $html);
        $this->assertStringContainsString('name="csrf"', $html);
        $this->assertStringContainsString('<input type="hidden" name="page_id" value="u1">', $html);
        $this->assertStringContainsString('data-confirm="Sure?"', $html);
        $this->assertMatchesRegularExpression('#role="separator".*<button class="danger" type="submit">#s', $html);
    }

    public function testActionsWithoutMenuHasNoMoreButton(): void
    {
        $html = $this->view->render('admin/list/actions', ['id' => 'u1', 'name' => 'X', 'edit' => '?e', 'menu' => [], 'csrf' => '']);
        $this->assertStringNotContainsString('popovertarget', $html);
        $this->assertStringNotContainsString('popover', $html);
    }

    public function testActionsQuickFormAndMenuNote(): void
    {
        $html = $this->view->render('admin/list/actions', [
            'id' => 'u2', 'name' => 'Anna', 'edit' => null, 'csrf' => '',
            'quick' => ['action' => '?resolve', 'fields' => ['selected[]' => 'u2', 'bulk' => 'resolved'], 'label' => 'Mark as resolved', 'icon' => 'check', 'filled' => true],
            'menu' => [['note' => 'The home page cannot be deleted.']],
        ]);
        $this->assertStringNotContainsString('aria-label="Edit', $html);
        $this->assertMatchesRegularExpression('#<form class="inline" method="post" action="\?resolve">.*name="selected\[\]" value="u2".*<button class="icon-btn filled" type="submit" aria-label="Mark as resolved: Anna" title="Mark as resolved">#s', $html);
        $this->assertStringContainsString('<p class="row-menu-note">The home page cannot be deleted.</p>', $html);
    }

    public function testLongTitleIsEscapedInLabels(): void
    {
        $name = str_repeat('Hochzeit <im> Park ', 11);
        $html = $this->view->render('admin/list/actions', ['id' => 'u3', 'name' => $name, 'edit' => '?e', 'menu' => [['url' => '?s', 'label' => 'Settings']], 'csrf' => '']);
        $this->assertStringNotContainsString('<im>', $html);
        $this->assertStringContainsString('Hochzeit &lt;im&gt; Park', $html);
    }

    public function testSelectionBarSubmitsBulkValues(): void
    {
        $html = $this->view->render('admin/list/selection', [
            'form' => 'bulk', 'action' => '?bulk', 'csrf' => '<input type="hidden" name="csrf" value="x">', 'hidden' => ['collection_id' => 'c1'],
            'actions' => [['value' => 'visible', 'label' => 'Publish', 'kind' => 'primary'], ['value' => 'trash', 'label' => 'Move to trash', 'kind' => 'danger', 'confirm' => 'Move?']],
        ]);
        $this->assertStringContainsString('<form id="bulk" class="selection-bar" method="post" action="?bulk">', $html);
        $this->assertStringContainsString('<input type="hidden" name="collection_id" value="c1">', $html);
        $this->assertStringContainsString('<span class="selection-number"></span> selected', $html);
        $this->assertStringContainsString('<button class="btn" type="submit" name="bulk" value="visible">Publish</button>', $html);
        $this->assertStringContainsString('<button class="navigation danger" type="submit" name="bulk" value="trash" data-confirm="Move?">Move to trash</button>', $html);
        $this->assertStringContainsString('type="reset"', $html);
        $this->assertStringContainsString('aria-label="Clear selection"', $html);
    }

    public function testSelectionButtonsCanPostElsewhere(): void
    {
        $html = $this->view->render('admin/list/selection', ['form' => 'bulk', 'action' => '?restore', 'csrf' => '', 'actions' => [
            ['label' => 'Restore', 'kind' => 'primary'], ['label' => 'Delete permanently', 'kind' => 'danger', 'formaction' => '?delete_permanently', 'confirm' => 'Sure?'],
        ]]);
        $this->assertStringContainsString('<button class="btn" type="submit">Restore</button>', $html, 'an action without a value posts no bulk field');
        $this->assertStringContainsString('<button class="navigation danger" type="submit" formaction="?delete_permanently" data-confirm="Sure?">Delete permanently</button>', $html);
    }
}
