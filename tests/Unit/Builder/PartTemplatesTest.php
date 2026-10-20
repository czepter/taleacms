<?php

declare(strict_types=1);

namespace Talea\Tests\Unit\Builder;

use PHPUnit\Framework\TestCase;

final class PartTemplatesTest extends TestCase
{
    public function testTemplates(): void
    {
        // Ready-made templates of site parts: each builds without errors, wrappers keep exactly one page content element, headers carry the logo
        $templateProblems = [];
        foreach (\Talea\Builder\PartTemplates::LIST as $partType => $partTemplates) {
            foreach (array_keys($partTemplates) as $templateKey) {
                $templateBuild = \Talea\Builder\PartTemplates::build($partType, $templateKey, 'en', ['newsletter_signup']);
                [, $templateErrors] = \Talea\Builder\Build::sanitize($templateBuild);
                $json = (string) json_encode($templateBuild);
                $contentCount = preg_match_all('/"type":"page_content"/', $json);
                if ($templateErrors !== [] || (in_array($partType, ['news_item', 'list', 'not_found'], true) ? $contentCount !== 1 : $contentCount !== 0)
                    || ($partType === 'header' && !str_contains($json, '"type":"logo"'))) {
                    $templateProblems[] = $partType . ':' . $templateKey;
                }
            }
        }
        $this->assertSame([], $templateProblems, 'Part templates: valid builds, one page content in wrappers, a logo in headers');
        $this->assertSame([false, ['plain']], [str_contains((string) json_encode(\Talea\Builder\PartTemplates::build('footer', 'columns', 'en', [])), '"type":"newsletter_signup"'),
            array_column(\Talea\Builder\PartTemplates::forType('list', []), 'key')], 'Part templates: the newsletter sign-up only with the Newsletter extension');
    }
}
