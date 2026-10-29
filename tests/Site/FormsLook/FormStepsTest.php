<?php

declare(strict_types=1);

namespace Talea\Tests\Site\FormsLook;

use Talea\Tests\Site\Support\SiteTestCase;
use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\Attributes\Group;

/** Drop-off counters per form step: a cookieless beacon, the Forms detail section and get_stats. */
#[Group('site')]
final class FormStepsTest extends SiteTestCase
{
    use SiteHelpers;

    private static string $source = '';
    private static string $element = '';
    private static int $page = 0;

    public function testPublishedThreeStepFormShowsItsSourceAndElement(): void
    {
        $this->call('create_page', ['title' => 'Quiz steps', 'slug' => 'quiz-steps', 'visible' => true]);
        self::$page = (int) $this->site()->value("SELECT page_id FROM tl_pages WHERE slug = 'quiz-steps'");
        $this->call('save_build', ['id' => $this->site()->publicId('pages', self::$page), 'publish' => true, 'build' => ['v' => 1, 'children' => [['type' => 'section', 'children' => [['type' => 'form', 'content' => [
            'name' => 'Roof quiz', 'fields' => [
                ['label' => 'Roof type', 'type' => 'text'],
                ['label' => 'Your home', 'type' => 'step'],
                ['label' => 'Size', 'type' => 'text'],
                ['label' => 'Contact', 'type' => 'step'],
                ['label' => 'Email', 'type' => 'email', 'required' => true],
            ]]]]]]]]);
        $this->site()->clearPageCache();
        $html = $this->site()->client()->get('/quiz-steps')->body;
        self::$source = $this->formField($html, 'source');
        self::$element = $this->formField($html, 'element');
        $this->assertSame('page:' . $this->site()->publicId('pages', self::$page), self::$source);
        $this->assertNotSame('', self::$element);
    }

    #[Depends('testPublishedThreeStepFormShowsItsSourceAndElement')]
    public function testBeaconCountsStepsWithoutACookie(): void
    {
        $visitor = $this->site()->client();
        foreach ([1, 1, 2] as $step) {
            $response = $visitor->post('/form-step', ['source' => self::$source, 'element' => self::$element, 'step' => (string) $step]);
            $this->assertSame(204, $response->status);
            $this->assertArrayNotHasKey('set-cookie', array_change_key_case($response->headers), 'the beacon sets no cookie');
        }
        $this->assertSame('1|2;2|1', implode(';', array_map(fn (array $r): string => $r['step'] . '|' . $r['reached'], $this->site()->rows(
            'SELECT step, reached FROM tl_form_steps WHERE element = ? ORDER BY step', [self::$element]))));
    }

    #[Depends('testBeaconCountsStepsWithoutACookie')]
    public function testJunkIsIgnored(): void
    {
        $visitor = $this->site()->client();
        $before = (int) $this->site()->value('SELECT COUNT(*) FROM tl_form_steps');
        foreach ([
            ['source' => 'page:' . self::$page, 'element' => self::$element, 'step' => '1'],          // an integer key from outside
            ['source' => 'page:00000000-0000-4000-8000-000000000000', 'element' => self::$element, 'step' => '1'],
            ['source' => '', 'element' => self::$element, 'step' => '1'],
            ['source' => self::$source, 'element' => 'NOT valid!', 'step' => '1'],
            ['source' => self::$source, 'element' => self::$element, 'step' => '0'],
            ['source' => self::$source, 'element' => self::$element, 'step' => '41'],
        ] as $junk) {
            $this->assertSame(204, $visitor->post('/form-step', $junk)->status);
        }
        $this->assertSame($before, (int) $this->site()->value('SELECT COUNT(*) FROM tl_form_steps'), 'junk writes no row');
    }

    #[Depends('testBeaconCountsStepsWithoutACookie')]
    public function testAdminDetailShowsTheStepsAndTheBiggestDrop(): void
    {
        $url = '/admin.php?module=forms&action=detail&source=' . rawurlencode(self::$source) . '&element=' . self::$element;
        $body = $this->site()->admin()->get($url)->body;
        $this->assertStringContainsString('<meter', $body);
        foreach (['Roof quiz', 'Your home', 'Contact'] as $title) {
            $this->assertStringContainsString($title, $body, "step title $title");
        }
        $this->assertStringContainsString('Most visitors leave', $body);
        $this->assertSame(200, $this->site()->admin()->get($url . '&days=365')->status);
    }

    #[Depends('testBeaconCountsStepsWithoutACookie')]
    public function testGetStatsReturnsTheFunnel(): void
    {
        $stats = $this->call('get_stats', []);
        $form = $stats['forms'][0] ?? [];
        $this->assertSame('Roof quiz', $form['form'] ?? null);
        $this->assertSame('Quiz steps', $form['page'] ?? null);
        $this->assertSame([['step' => 1, 'title' => 'Roof quiz', 'reached' => 2], ['step' => 2, 'title' => 'Your home', 'reached' => 1], ['step' => 3, 'title' => 'Contact', 'reached' => 0]], $form['steps'] ?? null);
        $this->assertSame(0, $form['sent'] ?? null);
    }
}
