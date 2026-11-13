<?php

declare(strict_types=1);

namespace Talea\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use Talea\Builder\Looks;
use Talea\Core\Settings;
use Talea\Core\StarterPages;
use Talea\Install\Installer;
use Talea\Install\Starter;
use Talea\Tests\Support\DatabaseTestCase;

#[CoversClass(Starter::class)]
final class StarterForMoveTest extends DatabaseTestCase
{
    public function testAnEmptySiteForAMoveHoldsOnlyAPlaceholderHomePageAndThePrivacyPolicy(): void
    {
        $db = $this->db();
        $looks = Looks::ranked(Installer::topicsOf('move'));
        $look = reset($looks);
        Starter::createForMove($db, 'Acme', 'en', $look, ['news', 'enquiries'], '', ['site_email' => 'a@example.com']);

        $pages = $db->all('SELECT page_id, public_id, slug, visible, in_menu, text FROM {pages} ORDER BY page_id');
        $this->assertCount(2, $pages);
        $this->assertSame('home', $pages[0]['slug']);
        $this->assertSame(1, (int) $pages[0]['visible']);
        $this->assertSame(0, (int) $pages[0]['in_menu']);
        $this->assertStringContainsString('[The home page of the moved site goes here', $pages[0]['text']);
        $this->assertSame(0, (int) $pages[1]['visible']);
        $this->assertSame(0, (int) $pages[1]['in_menu']);

        $settings = new Settings($db);
        $this->assertSame((int) $pages[0]['page_id'], $settings->int('home_page'));
        $this->assertSame([$pages[0]['public_id']], array_keys(StarterPages::recorded($settings) ?? []));
        $this->assertSame(0, (int) $db->value('SELECT COUNT(*) FROM {news}'));
        $this->assertGreaterThanOrEqual(1, (int) $db->value("SELECT COUNT(*) FROM {site_parts} WHERE type IN ('header', 'footer')"));
        $this->assertSame('a@example.com', $settings->get('site_email'));
        $this->assertSame('/privacy-policy', $settings->get('cookies_policy_url'));
        $footer = json_decode((string) $db->value("SELECT items FROM {menus} WHERE location = 'footer'"), true);
        $this->assertSame((int) $pages[1]['page_id'], $footer[0]['page_id'], 'the footer menu links the privacy policy');
    }
}
