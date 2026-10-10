<?php

declare(strict_types=1);

namespace Kaleta\Tests\Site\CollectionsB;

/** Small helpers shared by the CollectionsB classes (the old lib.sh mcp/preset_row/sq equivalents). Needs a SiteTestCase. */
trait Helpers
{
    /** The text an MCP tool returned (the old $WORK/response, for the substring checks). @param array<string, mixed> $arguments */
    protected function mcpText(string $tool, array $arguments = []): string
    {
        $answer = $this->site()->mcp($tool, $arguments);

        return (string) ($answer['result']['content'][0]['text'] ?? json_encode($answer, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /** "preset|item pages|schema type|number of fields|hidden list page" of a collection (old preset_row). */
    protected function presetRow(string $slug): string
    {
        return (string) $this->site()->value(
            "SELECT CONCAT(k.preset, '|', k.detail, '|', IFNULL(JSON_UNQUOTE(JSON_EXTRACT(k.schema_org, '\$.type')), '-'), '|', JSON_LENGTH(k.fields), '|', (SELECT COUNT(*) FROM ka_pages s WHERE s.slug = k.slug AND s.visible = 0)) FROM ka_collections k WHERE k.slug = ?",
            [$slug],
        );
    }

    /** The last inserted enquiry with these columns (old: INSERT … ; SELECT LAST_INSERT_ID()). */
    protected function insertEnquiry(string $email, string $data, int $state): int
    {
        $this->site()->exec("INSERT INTO ka_enquiries (created_at, form, source, page, email, data, status) VALUES (NOW(), 'Contact', 'page:1', '/contact', ?, ?, ?)", [$email, $data, $state]);

        return (int) $this->site()->pdo->lastInsertId();
    }
}
