<?php

declare(strict_types=1);

namespace Talea\Tests\Site\McpBuilder;

use PHPUnit\Framework\Attributes\Group;
use Talea\Tests\Site\Support\SiteTestCase;

/** try_form and the builder's try action run the logic of a form's draft (or published) build with given answers. */
#[Group('site')]
final class TryFormTest extends SiteTestCase
{
    use McpBuilderHelpers;

    /** @return array{case: array<string, mixed>, page: string, element: string, answers: array<string, mixed>} */
    private function wedding(): array
    {
        $cases = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/Fixtures/form-logic.json'), true);
        $case = $cases[0];
        $this->mcpData('create_page', ['title' => 'Quiz', 'slug' => 'try-quiz']);
        $page = (string) $this->site()->value("SELECT public_id FROM tl_pages WHERE slug = 'try-quiz'");
        $this->mcpData('save_build', ['id' => $page, 'build' => ['v' => 1, 'children' => [['id' => 'f1', 'type' => 'form', 'content' => $case['content']]]]]);
        $element = (string) $this->mcpData('get_build', ['id' => $page])['build']['children'][0]['id'];
        $keys = array_column($case['content']['fields'], 'key');
        $answers = [];
        foreach ($case['answers'] as $index => $value) {
            $answers[$keys[(int) $index]] = $value;
        }

        return ['case' => $case, 'page' => $page, 'element' => $element, 'answers' => $answers];
    }

    public function testDraftFormRunsWithTheFixtureAnswers(): void
    {
        $w = $this->wedding();
        $out = $this->mcpData('try_form', ['id' => $w['page'], 'element' => $w['element'], 'answers' => $w['answers'] + ['knope' => 'x']]);

        $this->assertEqualsWithDelta($w['case']['total'], $out['total'], 0.001);
        $this->assertSame($w['case']['shown'], str_replace("\u{a0}", ' ', $out['shown']));
        $this->assertSame('vabrd', $out['variant']);
        $this->assertSame(['knope'], $out['unknown_keys']);
        $this->assertContains('kcnt0', $out['visible']);
        $this->assertArrayHasKey('amount', $out['lines'][0]);
        $this->assertArrayHasKey('label', $out['lines'][0]);
    }

    public function testPublishedWithoutAPublishedFormIsAnError(): void
    {
        $w = $this->wedding();
        $text = $this->rawText('try_form', ['id' => $w['page'], 'element' => $w['element'], 'answers' => [], 'published' => true]);

        $this->assertStringContainsString('not in the build', $text);
    }

    public function testAdminActionReturnsTheSameJson(): void
    {
        $w = $this->wedding();
        $mcp = $this->mcpData('try_form', ['id' => $w['page'], 'element' => $w['element'], 'answers' => $w['answers']]);
        $admin = json_decode($this->site()->admin()->get('/admin.php?module=pages&action=build_form_try&id=' . $w['page'] . '&element=' . $w['element']
            . '&answers=' . rawurlencode((string) json_encode($w['answers'])))->body, true);

        $this->assertSame($mcp, $admin);
    }
}
