<?php

declare(strict_types=1);

namespace Talea\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Talea\Core\MigrationReport;

final class MigrationReportTest extends TestCase
{
    public function testAnalyse(): void
    {
        // 2.7: the migration report reads what the old page had, counts forms and images of a build, and old form entries are checked
        $oldPage = '<html><head><title>Services | Acme</title><meta name="description" content="What we do"></head><body><header><form role="search"><input type="search" name="s"></form></header>'
            . '<main><h1>Services</h1><p>' . str_repeat('We build kitchens and bathrooms. ', 5) . '</p><img src="/a.jpg"><img src="/b.jpg"><img src="/c.jpg"><form action="/contact"><input name="email"><textarea name="m"></textarea></form></main></body></html>';
        $searchOnly = '<html><body><main><p>' . str_repeat('Text of the page. ', 8) . '</p></main><form class="search-form"><input name="s"></form></body></html>';
        $this->assertSame([['title' => 'Services | Acme', 'description' => 'What we do', 'form' => true, 'images' => 3], false], [\Talea\Core\MigrationReport::analyse($oldPage, 'https://old.example/services/'), \Talea\Core\MigrationReport::analyse($searchOnly, 'https://old.example/')['form']], '2.7: MigrationReport::analyse');
        $this->assertSame([1, 4], \Talea\Core\MigrationReport::countElements([
            ['type' => 'section', 'children' => [['type' => 'image'], ['type' => 'gallery', 'content' => ['photos' => [['src' => 'a'], ['src' => 'b']]]], ['type' => 'container', 'children' => [['type' => 'form']]]]],
            ['type' => 'text', 'content' => ['html' => '<p><img src="x"></p>']]]), '2.7: MigrationReport::countElements');
        // a Component element counts what its build holds; one inside itself counts nothing, as it renders nothing
        $components = [
            7 => ['children' => [['type' => 'form'], ['type' => 'image'], ['type' => 'component', 'content' => ['component' => '7']]]],
            8 => ['children' => [['type' => 'section', 'children' => [['type' => 'component', 'content' => ['component' => '7']]]]]],
        ];
        $uses = [['type' => 'section', 'children' => [['type' => 'component', 'content' => ['component' => '7']]]], ['type' => 'component', 'content' => ['component' => '8']], ['type' => 'component', 'content' => ['component' => '99']]];
        $this->assertSame([[2, 2], [0, 0]], [\Talea\Core\MigrationReport::countElements($uses, fn (int $id): ?array => $components[$id] ?? null), \Talea\Core\MigrationReport::countElements($uses)],
            'MigrationReport::countElements: forms and images inside components (also nested), a component containing itself counted once');
        $this->assertSame([['date' => '2025-03-14 09:30:00', 'form' => 'Contact', 'page' => '/contact', 'email' => 'jana@example.cz', 'data' => [['Name', 'Jana'], ['E-mail', 'jana@example.cz'], ['Message', 'Hi']]],
            'date must be a date and time, e.g. 2025-03-14 09:30', 'fields are empty', ''], [
            \Talea\Mcp\Tools::enquiryEntry(['date' => '2025-03-14 09:30', 'form' => 'Contact', 'page' => '/contact', 'fields' => ['Name' => 'Jana', 'E-mail' => 'jana@example.cz', 'Message' => '<b>Hi</b>', 'Empty' => '']]),
            \Talea\Mcp\Tools::enquiryEntry(['date' => 'yesterday-ish?', 'fields' => ['a' => 'b']]), \Talea\Mcp\Tools::enquiryEntry(['date' => '2025-01-01', 'fields' => []]),
            \Talea\Mcp\Tools::enquiryEntry(['date' => '2025-01-01 10:00', 'email' => 'not-an-email', 'fields' => [['label' => 'Phone', 'value' => '777 123 456']]])['email']], '2.7: import_enquiries checks each entry');
    }

    public function testTitleChanged(): void
    {
        $this->assertFalse(MigrationReport::titleChanged('About us | Acme', 'About us'), 'the site name after the separator is not a change');
        $this->assertFalse(MigrationReport::titleChanged('About us', 'about  us'));
        $this->assertFalse(MigrationReport::titleChanged('About us | Acme', 'About us | Acme'));
        $this->assertTrue(MigrationReport::titleChanged('About us | Acme', 'Our story'));
        $this->assertFalse(MigrationReport::titleChanged('About us', ''), 'a target without a title says nothing');
    }

    public function testAFormOnEveryPageIsOneThingForTheSite(): void
    {
        $row = fn (bool $form, bool $read = true): array => ['status' => 'ok', 'problems' => $form ? ['form_missing', 'hidden'] : [], 'old_form' => $form, 'read' => $read];
        $all = MigrationReport::siteWideForm([$row(true), $row(true), $row(true)]);
        $this->assertTrue($all['siteForm']);
        $this->assertSame([['hidden'], ['hidden'], ['hidden']], array_column($all['rows'], 'problems'));
        $this->assertFalse(MigrationReport::siteWideForm([$row(true), $row(true)])['siteForm'], 'two pages are not enough to judge');
        $this->assertFalse(MigrationReport::siteWideForm([$row(true), $row(true), $row(false)])['siteForm'], 'a page without a form: the form is on those pages only');
        $this->assertFalse(MigrationReport::siteWideForm([$row(true), $row(true), $row(true, false)])['siteForm'], 'a page that was not read does not count');
    }

    public function testASiteThatCouldNotBeReachedIsSaidSo(): void
    {
        $unread = ['status' => 'ok', 'problems' => ['not_read'], 'old_form' => false, 'read' => false];
        $this->assertTrue(MigrationReport::summary([$unread, $unread], 2)['unreachable']);
        $this->assertFalse(MigrationReport::summary([$unread, ['read' => true] + $unread], 2)['unreachable']);
        $this->assertFalse(MigrationReport::summary([], 0)['unreachable']);
        $summary = MigrationReport::summary([['status' => 'ok', 'problems' => ['starter', 'title_changed', 'no_description'], 'read' => true]], 1);
        $this->assertSame([1, 1], [$summary['failed'], $summary['warnings']], 'a starter page is an error, a changed title only a note');
    }
}
