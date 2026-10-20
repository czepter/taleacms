<?php

declare(strict_types=1);

namespace Talea\Tests\Unit;

use PHPUnit\Framework\TestCase;

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
        $this->assertSame([['date' => '2025-03-14 09:30:00', 'form' => 'Contact', 'page' => '/contact', 'email' => 'jana@example.cz', 'data' => [['Name', 'Jana'], ['E-mail', 'jana@example.cz'], ['Message', 'Hi']]],
            'date must be a date and time, e.g. 2025-03-14 09:30', 'fields are empty', ''], [
            \Talea\Mcp\Tools::enquiryEntry(['date' => '2025-03-14 09:30', 'form' => 'Contact', 'page' => '/contact', 'fields' => ['Name' => 'Jana', 'E-mail' => 'jana@example.cz', 'Message' => '<b>Hi</b>', 'Empty' => '']]),
            \Talea\Mcp\Tools::enquiryEntry(['date' => 'yesterday-ish?', 'fields' => ['a' => 'b']]), \Talea\Mcp\Tools::enquiryEntry(['date' => '2025-01-01', 'fields' => []]),
            \Talea\Mcp\Tools::enquiryEntry(['date' => '2025-01-01 10:00', 'email' => 'not-an-email', 'fields' => [['label' => 'Phone', 'value' => '777 123 456']]])['email']], '2.7: import_enquiries checks each entry');
    }
}
