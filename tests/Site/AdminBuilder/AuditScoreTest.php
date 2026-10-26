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
        $this->site()->mcpText('site_audit');
        $this->assertNotSame('', (string) $this->site()->value("SELECT value FROM tl_settings WHERE name = 'seo_audit_previous'"), 'the previous score is kept');
    }
}
