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
            "SELECT CONCAT(k.preset, '|', k.detail, '|', IFNULL(JSON_UNQUOTE(JSON_EXTRACT(k.schema_org, '\$.typ')), '-'), '|', JSON_LENGTH(k.pole), '|', (SELECT COUNT(*) FROM ka_stranky s WHERE s.seo_link = k.seo_link AND s.zobrazit = 0)) FROM ka_kolekce k WHERE k.seo_link = ?",
            [$slug],
        );
    }

    /** The last inserted enquiry with these columns (old: INSERT … ; SELECT LAST_INSERT_ID()). */
    protected function insertEnquiry(string $email, string $data, int $state): int
    {
        $this->site()->exec("INSERT INTO ka_poptavky (datum, formular, zdroj, stranka, email, data, stav) VALUES (NOW(), 'Kontakt', 'stranka:1', '/kontakt', ?, ?, ?)", [$email, $data, $state]);

        return (int) $this->site()->pdo->lastInsertId();
    }
}
