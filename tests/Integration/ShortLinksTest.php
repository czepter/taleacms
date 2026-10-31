<?php

declare(strict_types=1);

namespace Talea\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use Talea\Core\App;
use Talea\Core\Request;
use Talea\Core\ShortLinks;
use Talea\Tests\Support\DatabaseTestCase;

#[CoversClass(ShortLinks::class)]
final class ShortLinksTest extends DatabaseTestCase
{
    private function app(): App
    {
        $app = new App(['db' => $this->dbConfig()], Request::fromGlobals());
        (new \ReflectionProperty(App::class, 'db'))->setValue($app, $this->db());

        return $app;
    }

    public function testNameError(): void
    {
        $db = $this->db();
        $db->insert('pages', ['slug' => 'about', 'title' => 'About', 'text' => '']);
        $db->insert('collections', ['slug' => 'team', 'name' => 'Team', 'fields' => '[]']);
        foreach (['s', 'news', 'de', 'pt-br', 'about', 'team', 'Ab', 'a', '-x'] as $name) {
            $this->assertNotNull(ShortLinks::nameError($db, $name), $name);
        }
        $this->assertNull(ShortLinks::nameError($db, 'flyer'));
    }

    public function testNameErrorKnowsRedirectsAndShortLinks(): void
    {
        $app = $this->app();
        $db = $app->db();
        $db->insert('redirects', ['from_path' => 'old-offer', 'to_path' => 'offers', 'match_type' => 'exact', 'type' => 301, 'active' => true, 'created_at' => date('Y-m-d H:i:s')]);
        $this->assertNotNull(ShortLinks::nameError($db, 'old-offer'));
        $row = ShortLinks::save($app, ['name' => 'flyer', 'target_type' => 'url', 'target_url' => '/offers']);
        $this->assertIsArray($row);
        $this->assertNotNull(ShortLinks::nameError($db, 'flyer'));
        $this->assertTrue(ShortLinks::takesName($db, 'flyer'));
        $this->assertFalse(ShortLinks::takesName($db, 'other'));
    }

    public function testSave(): void
    {
        $app = $this->app();
        $row = ShortLinks::save($app, ['target_type' => 'url', 'target_url' => '/offers', 'utm_source' => " fly\x01er "]);
        $this->assertIsArray($row);
        $this->assertMatchesRegularExpression('#^s/[23456789abcdefghjkmnpqrstuvwxyz]{6}$#', $row['path']);
        $this->assertSame('flyer', $row['utm_source']);

        $again = ShortLinks::save($app, ['name' => 'renamed', 'target_type' => 'url', 'target_url' => '/other'], (int) $row['short_link_id']);
        $this->assertIsArray($again);
        $this->assertSame($row['path'], $again['path']);
        $this->assertSame('other', $again['target_url']);

        $this->assertIsString(ShortLinks::save($app, ['target_type' => 'page', 'target_id' => 999999]));
        $this->assertIsString(ShortLinks::save($app, ['target_type' => 'url', 'target_url' => 'javascript:alert(1)']));
        $this->assertIsString(ShortLinks::save($app, ['target_type' => 'url', 'target_url' => '/javascript:alert(1)']), 'the slash does not hide a scheme');
        $this->assertIsString(ShortLinks::save($app, ['target_type' => 'url', 'target_url' => '/https://evil.example']), 'nor an external address');

        ShortLinks::delete($app, (int) $row['short_link_id']);
        $this->assertNull($app->db()->one('SELECT 1 FROM {short_links} WHERE short_link_id = ?', [$row['short_link_id']]));
    }

    public function testTargetUrl(): void
    {
        $app = $this->app();
        $db = $app->db();
        $home = $db->insert('pages', ['slug' => 'start', 'title' => 'Start', 'text' => '']);
        $trashed = $db->insert('pages', ['slug' => 'gone', 'title' => 'Gone', 'text' => '', 'deleted_at' => date('Y-m-d H:i:s')]);
        $live = $db->insert('pages', ['slug' => 'live', 'title' => 'Live', 'text' => '']);
        $app->settings()->set('home_page', (string) $home);

        $this->assertSame('', ShortLinks::targetUrl($app, ['target_type' => 'page', 'target_id' => $home, 'target_url' => null] + $this->utm()));
        $this->assertNull(ShortLinks::targetUrl($app, ['target_type' => 'page', 'target_id' => $trashed, 'target_url' => null] + $this->utm()));
        $this->assertSame('live', ShortLinks::targetUrl($app, ['target_type' => 'page', 'target_id' => $live, 'target_url' => null] + $this->utm()));
        $this->assertSame('offers?utm_source=a', ShortLinks::targetUrl($app, ['target_type' => 'url', 'target_id' => null, 'target_url' => 'offers', 'utm_source' => 'a'] + $this->utm()));
    }

    /** @return array<string, string> */
    private function utm(): array
    {
        return array_fill_keys(ShortLinks::UTM, '');
    }
}
