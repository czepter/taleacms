<?php

declare(strict_types=1);

namespace Talea\Tests\Site\Experiments;

use PHPUnit\Framework\Attributes\Group;
use Talea\Tests\Site\Support\SiteTestCase;

/** A/B tests (Builder\Experiments): both versions in the one cached page, cookie-free counting, promotion with undo, the MCP tools. */
#[Group('site')]
final class ExperimentsTest extends SiteTestCase
{
    private static string $page = '';
    private static string $experiment = '';
    private static string $component = '';

    private function heading(string $slug): string
    {
        $html = $this->site()->client('visitor')->get('/' . $slug)->body;
        preg_match('#<h2[^>]*data-variant="a"[^>]*>(.*?)</h2>#', $html, $m);

        return $m[1] ?? '';
    }

    public function testAnExperimentIsCreatedOnAPublishedElementAndVersionBIsAComponent(): void
    {
        $pageId = $this->site()->mcpResult('create_page', ['title' => 'Landing', 'slug' => 'landing'])['id'];
        self::$page = $pageId;
        $this->site()->mcp('save_build', ['id' => $pageId, 'publish' => true, 'build' => ['v' => 1, 'children' => [
            ['id' => 'sec1', 'type' => 'section', 'children' => [['id' => 'head1', 'type' => 'heading', 'tag' => 'h2', 'content' => ['text' => 'Original headline']]]],
        ]]]);

        $this->site()->exec('UPDATE tl_pages SET visible = 1 WHERE slug = ?', ['landing']);
        $x = $this->site()->mcpResult('create_experiment', ['name' => 'Headline test', 'page' => $pageId, 'element' => 'head1', 'goal' => 'form']);

        $this->assertSame('draft', $x['status']);
        $this->assertSame('head1', $x['element']);
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $x['id']);
        self::$experiment = $x['id'];
        self::$component = $x['version_b']['component'];
        $this->assertSame('Test: Headline test – version B', $this->site()->value('SELECT name FROM tl_components WHERE public_id = ?', [self::$component]));
        $this->assertStringNotContainsString('data-variant', $this->site()->client('visitor')->get('/landing')->body, 'nothing on the page before the experiment starts');
    }

    public function testRefusals(): void
    {
        $this->assertStringContainsString('element', json_encode($this->site()->mcp('create_experiment', ['name' => 'x', 'page' => self::$page, 'element' => 'nope', 'goal' => 'form'])));
        $this->assertStringContainsString('address', json_encode($this->site()->mcp('create_experiment', ['name' => 'x', 'page' => self::$page, 'element' => 'head1', 'goal' => 'click'])));
        $this->assertStringContainsString('another page', json_encode($this->site()->mcp('create_experiment', ['name' => 'x', 'page' => self::$page, 'kind' => 'page', 'variant_page' => self::$page, 'goal' => 'form'])));
    }

    public function testBothVersionsAreInTheCachedPageForEveryone(): void
    {
        // version B: the headline of the component copy
        $build = (string) $this->site()->value('SELECT build FROM tl_components WHERE public_id = ?', [self::$component]);
        $this->site()->exec('UPDATE tl_components SET build = ? WHERE public_id = ?', [str_replace('Original headline', 'Version B headline', $build), self::$component]);
        $this->site()->mcp('update_experiment', ['id' => self::$experiment, 'action' => 'start']);
        $this->site()->clearPageCache();

        $first = $this->site()->client('one')->get('/landing');
        $second = $this->site()->client('two')->get('/landing');

        $this->assertSame($first->body, $second->body, 'the same cached HTML for every visitor');
        $this->assertStringContainsString('Original headline', $first->body);
        $this->assertStringContainsString('Version B headline', $first->body);
        $this->assertSame(1, preg_match_all('/<[a-z0-9]+[^>]* data-variant="a"/', $first->body));
        $this->assertSame(1, preg_match_all('/<[a-z0-9]+[^>]* data-variant="b"/', $first->body));
        $this->assertStringContainsString('data-experiment="' . self::$experiment . '"', $first->body);
        $this->assertStringContainsString('html:not([data-variants~="' . self::$experiment . ':b"])', $first->body, 'B is hidden until the head script picks it');
        $this->assertStringContainsString('id="tl-experiments"', $first->body);
        $this->assertStringContainsString('/image/web.js', $first->body, 'the counting script stays on the page');
        $this->assertSame(0, preg_match('/Set-Cookie/i', json_encode($first->headers)), 'no cookie');
        $this->assertSame(1, substr_count($first->body, 'localStorage.setItem'), 'storage is written in one place only, behind the consent test');
    }

    public function testTheBeaconCountsViewsAndGoalsWithoutIdentifiers(): void
    {
        $visitor = $this->site()->client('counter');
        $this->assertSame(204, $visitor->post('/experiment', ['experiment' => self::$experiment, 'variant' => 'a', 'event' => 'view'])->status);
        $visitor->post('/experiment', ['experiment' => self::$experiment, 'variant' => 'b', 'event' => 'view']);
        $visitor->post('/experiment', ['experiment' => self::$experiment, 'variant' => 'b', 'event' => 'goal']);
        $visitor->post('/experiment', ['experiment' => self::$experiment, 'variant' => 'c', 'event' => 'view']);
        $visitor->post('/experiment', ['experiment' => 'not-an-id', 'variant' => 'a', 'event' => 'view']);
        $visitor->post('/experiment', ['experiment' => self::$experiment, 'variant' => 'a', 'event' => 'view'], userAgent: 'Googlebot/2.1');

        $rows = $this->site()->rows('SELECT variant, SUM(views) AS v, SUM(goals) AS g FROM tl_stats_experiments GROUP BY variant ORDER BY variant');
        $this->assertSame([['variant' => 'a', 'v' => 1, 'g' => 0], ['variant' => 'b', 'v' => 1, 'g' => 1]], array_map(fn (array $r): array => ['variant' => $r['variant'], 'v' => (int) $r['v'], 'g' => (int) $r['g']], $rows), 'a bot, a bad variant and an unknown id are not counted');
        $this->assertSame(['day', 'experiment_id', 'variant', 'views', 'goals'], array_keys($this->site()->rows('SELECT * FROM tl_stats_experiments')[0]), 'no identifiers are stored');
    }

    public function testTheResultIsNotTrustedUntilTheGuardrailsAreMet(): void
    {
        $result = $this->site()->mcpResult('get_experiment_result', ['id' => self::$experiment]);

        $this->assertSame('collecting', $result['result']['state']);
        $this->assertContains(false, array_column($result['guardrails'], 'met'));
        $this->assertStringContainsString('not trustworthy', json_encode($this->site()->mcp('promote_experiment_winner', ['id' => self::$experiment, 'winner' => 'b'])));
    }

    public function testPromotingTheWinnerReplacesTheOriginalAndCanBeUndone(): void
    {
        $this->site()->exec('DELETE FROM tl_stats_experiments');
        $this->site()->exec('UPDATE tl_experiments SET started_at = ? WHERE public_id = ?', [date('Y-m-d H:i:s', time() - 10 * 86400), self::$experiment]);
        $id = $this->site()->internalId('experiments', self::$experiment);
        $this->site()->exec('INSERT INTO tl_stats_experiments (day, experiment_id, variant, views, goals) VALUES (?, ?, ?, ?, ?), (?, ?, ?, ?, ?)',
            [date('Y-m-d'), $id, 'a', 1000, 50, date('Y-m-d'), $id, 'b', 1000, 90]);

        $result = $this->site()->mcpResult('get_experiment_result', ['id' => self::$experiment]);
        $this->assertSame('winner', $result['result']['state']);
        $this->assertSame('b', $result['result']['winner']);

        $promoted = $this->site()->mcpResult('promote_experiment_winner', ['id' => self::$experiment, 'reason' => 'the user asked']);
        $this->assertSame('promoted', $promoted['status']);
        $this->assertSame('published', $promoted['applied_as']);
        $this->site()->clearPageCache();
        $html = $this->site()->client('visitor')->get('/landing')->body;
        $this->assertStringContainsString('Version B headline', $html);
        $this->assertStringNotContainsString('Original headline', $html);
        $this->assertStringNotContainsString('data-experiment', $html, 'the experiment is over');
        $this->assertGreaterThan(0, (int) $this->site()->value("SELECT COUNT(*) FROM tl_change_log WHERE module = 'experiments' AND action = 'promote'"), 'in the change log');

        $this->site()->mcp('update_experiment', ['id' => self::$experiment, 'action' => 'undo_promotion']);
        $this->site()->clearPageCache();
        $html = $this->site()->client('visitor')->get('/landing')->body;
        $this->assertStringContainsString('Original headline', $html, 'undone');
        $this->assertStringNotContainsString('Version B headline', $html);
    }

    public function testTheAdminScreensWork(): void
    {
        $this->assertPage('/admin.php?module=experiments', 200, 'Headline test');
        $this->assertPage('/admin.php?module=experiments&action=show&id=' . self::$experiment, 200, 'Guardrails');
        $this->assertPage('/admin.php?module=experiments&action=new', 200, 'Continue');
        $this->assertPage('/admin.php?module=experiments&action=new&page=' . self::$page, 200, 'Original headline');
    }

    public function testAutomaticPromotionRunsOnlyWhenOptedIn(): void
    {
        $id = $this->site()->internalId('experiments', self::$experiment);
        $this->site()->exec('DELETE FROM tl_stats_experiments');
        $this->site()->mcp('update_experiment', ['id' => self::$experiment, 'action' => 'start']);
        $this->site()->exec('UPDATE tl_experiments SET started_at = ? WHERE experiment_id = ?', [date('Y-m-d H:i:s', time() - 10 * 86400), $id]);
        $this->site()->exec('INSERT INTO tl_stats_experiments (day, experiment_id, variant, views, goals) VALUES (?, ?, ?, ?, ?), (?, ?, ?, ?, ?)',
            [date('Y-m-d'), $id, 'a', 1000, 50, date('Y-m-d'), $id, 'b', 1000, 90]);

        $this->site()->php('Talea\\Builder\\Experiments::autoPromote(Talea\\Core\\App::boot());');
        $this->assertSame('running', $this->site()->value('SELECT status FROM tl_experiments WHERE experiment_id = ?', [$id]), 'not opted in: it keeps running');

        $this->site()->mcp('update_experiment', ['id' => self::$experiment, 'auto_promote' => true]);
        $this->site()->php('Talea\\Builder\\Experiments::autoPromote(Talea\\Core\\App::boot());');
        $this->assertSame('promoted', $this->site()->value('SELECT status FROM tl_experiments WHERE experiment_id = ?', [$id]));
        $this->assertSame(1, (int) $this->site()->value("SELECT COUNT(*) FROM tl_change_log WHERE module = 'experiments' AND reason LIKE 'Automatic%'"), 'the reason is in the change log');
    }

    public function testAWholePageTestShowsTheOtherPagesContentAndPromotesIt(): void
    {
        $other = $this->site()->mcpResult('create_page', ['title' => 'Landing two', 'slug' => 'landing-two'])['id'];
        $this->site()->mcp('save_build', ['id' => $other, 'publish' => true, 'build' => ['v' => 1, 'children' => [['type' => 'heading', 'tag' => 'h2', 'content' => ['text' => 'Second page headline']]]]]);
        $x = $this->site()->mcpResult('create_experiment', ['name' => 'Pages', 'kind' => 'page', 'page' => self::$page, 'variant_page' => $other, 'goal' => 'page', 'goal_page' => $other, 'start' => true]);
        $this->site()->clearPageCache();
        $html = $this->site()->client('visitor')->get('/landing')->body;

        $this->assertStringContainsString('Second page headline', $html);
        $this->assertStringContainsString('"g":[]', $html);
        $this->assertStringContainsString('"goal":"page"', $html);
        $this->site()->exec('UPDATE tl_pages SET visible = 1 WHERE slug = ?', ['landing-two']);
        $this->site()->clearPageCache();
        $this->assertStringContainsString('"g":[{"id":"' . $x['id'] . '","path":"/landing-two"}]', $this->site()->client('visitor')->get('/landing-two')->body, 'the goal page knows which experiments it ends');

        $this->site()->mcp('update_experiment', ['id' => $x['id'], 'action' => 'stop']);
        $result = $this->site()->mcpResult('promote_experiment_winner', ['id' => $x['id'], 'winner' => 'b', 'force' => true]);
        $this->assertSame('published', $result['applied_as']);
        $this->site()->clearPageCache();
        $this->assertStringContainsString('Second page headline', $this->site()->client('visitor')->get('/landing')->body);
    }
}
