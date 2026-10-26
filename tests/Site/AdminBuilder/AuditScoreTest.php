<?php

declare(strict_types=1);

namespace Talea\Tests\Site\AdminBuilder;

use PHPUnit\Framework\Attributes\Group;
use Talea\Tests\Site\Support\SiteTestCase;

/** The site audit screen: score ring, category tabs, passed checks; site_audit returns the score. */
#[Group('site')]
final class AuditScoreTest extends SiteTestCase
{
    public function testScreenAndMcp(): void
    {
        $page = $this->assertPage('/admin.php?module=audit', 200, 'class="audit-ring audit-', message: 'the score ring');
        $this->assertMatchesRegularExpression('#<details class="audit-passed">\s*<summary>\d+ checks passed#', $page->body, 'passed checks in a closed details');
        $this->assertStringContainsString('/admin/audit?category=basics', $page->body, 'category tabs');
        $this->assertPage('/admin.php?module=audit&category=speed', 200, message: 'a category filter');

        $text = $this->site()->mcpText('site_audit');
        $this->assertStringContainsString('"score":', $text);
        $this->assertStringContainsString('"previous_score":', $text);
        $this->assertStringContainsString('"passed":', $text);
        $stored = $this->site()->value("SELECT value FROM tl_settings WHERE name = 'seo_audit_score'");
        $this->assertNotSame('', (string) $stored, 'the score is stored');
    }

    public function testPreviousIsTheLastDifferentScoreAndMcpWritesNothing(): void
    {
        $site = $this->site();
        $site->exec("DELETE FROM tl_settings WHERE name IN ('seo_audit_score', 'seo_audit_previous')");
        $this->assertPage('/admin.php?module=audit');
        $first = (string) $site->value("SELECT value FROM tl_settings WHERE name = 'seo_audit_score'");
        $site->exec("INSERT INTO tl_settings (name, value) VALUES ('seo_audit_previous', '')");
        $site->exec("UPDATE tl_settings SET value = '1' WHERE name = 'seo_audit_score'");
        $site->mcpText('site_audit');
        $this->assertSame('1', (string) $site->value("SELECT value FROM tl_settings WHERE name = 'seo_audit_score'"), 'site_audit stores nothing');
        $this->assertPage('/admin.php?module=audit');
        $this->assertSame('1', (string) $site->value("SELECT value FROM tl_settings WHERE name = 'seo_audit_previous'"), 'a changed score moves the old one to previous');
        $this->assertPage('/admin.php?module=audit');
        $this->assertSame('1', (string) $site->value("SELECT value FROM tl_settings WHERE name = 'seo_audit_previous'"), 'an identical run keeps previous');
        $this->assertSame($first, (string) $site->value("SELECT value FROM tl_settings WHERE name = 'seo_audit_score'"));
    }

    public function testCategoryFilterShowsOnlyItsFindings(): void
    {
        $this->site()->mcp('create_page', ['title' => 'Undescribed audit page', 'slug' => 'undescribed-audit', 'visible' => true]);
        $basics = $this->assertPage('/admin.php?module=audit&category=basics', 200, 'No description for search engines', message: 'a basics finding');
        $this->assertStringContainsString('things to fix', $basics->body);
        $speed = $this->assertPage('/admin.php?module=audit&category=speed', 200, message: 'another category');
        $this->assertStringNotContainsString('No description for search engines', $speed->body, 'a finding of another category is hidden');
        $this->assertStringNotContainsString('things to fix', $speed->body, 'the banner follows the selected category');
        $this->assertPage('/admin.php?module=audit&category=nonsense', 200, 'No description for search engines', message: 'an unknown category falls back to all');
    }

    public function testPreviousScoreIsReportedAfterAChange(): void
    {
        $site = $this->site();
        $site->exec("DELETE FROM tl_settings WHERE name IN ('seo_audit_score', 'seo_audit_previous')");
        $this->assertPage('/admin.php?module=audit');
        $now = (string) $site->value("SELECT value FROM tl_settings WHERE name = 'seo_audit_score'");
        $site->exec("UPDATE tl_settings SET value = '7' WHERE name = 'seo_audit_score'");
        $this->assertPage('/admin.php?module=audit');
        $this->assertSame('7', (string) $site->value("SELECT value FROM tl_settings WHERE name = 'seo_audit_previous'"));
        $this->assertStringContainsString('"previous_score":7', $site->mcpText('site_audit'));
        $this->assertSame($now, (string) $site->value("SELECT value FROM tl_settings WHERE name = 'seo_audit_score'"));
    }
}
