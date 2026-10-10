<?php

declare(strict_types=1);

namespace Talea\Builder;

use Talea\Admin\ChangeLog;
use Talea\Core\Antispam;
use Talea\Core\App;
use Talea\Core\Db;
use Talea\Core\Response;
use Talea\Front\Stats;

/**
 * A/B tests: two versions (A = what the page has, B = the alternative) of one element, one section or a whole page, a goal and
 * a result.
 *
 * Rendering keeps the page cache working: the cached page holds both versions, B is hidden by a rule in the head, and a tiny
 * inline script in the head picks one before the first paint (html[data-variants]) – at random on every view and storing nothing,
 * or, after the visitor accepted the cookie bar (the cookie talea_consent names analytics or marketing), the same one every
 * time through localStorage. Without JavaScript the page shows A.
 *
 * Counting follows Front\Stats and Core\Conversions: image/web.js sends a beacon per view and per goal to POST /experiment,
 * the server adds one to a daily count per experiment and variant – no identifiers, no bots, never for signed-in users.
 * Variant B of an element test is a component (edited in the builder like any other), of a page test another page.
 */
final class Experiments
{
    public const array KINDS = ['element' => 'A section or an element of a page', 'page' => 'A whole page'];

    /** goal => [name, what is counted] */
    public const array GOALS = [
        'form' => ['A form is sent', 'A form on the page is submitted.'],
        'click' => ['A link or button is clicked', 'A click on a link or button that leads to the address you give.'],
        'booking' => ['A booking is made', 'The booking form on the page is submitted.'],
        'page' => ['A page is reached', 'A visitor reaches the page you choose. Counted only for visitors who accepted the cookie bar, because the browser has to remember the variant between the two pages.'],
    ];

    /** The fixed guardrails of a result and of automatic promotion; shown in the admin, not editable. */
    public const int MIN_VIEWS = 200;
    public const int MIN_DAYS = 7;
    public const float MIN_PROBABILITY = 0.95;
    public const float MIN_UPLIFT = 0.05;

    /** Elements offered for a test and listed from a build: at most this many. */
    private const int MAX_CHOICES = 300;

    /* ---------- analysis ---------- */

    /** Standard normal distribution function (Abramowitz and Stegun 7.1.26 for erf, error below 1.5e-7). */
    public static function normalCdf(float $x): float
    {
        $z = abs($x) / M_SQRT2;
        $t = 1 / (1 + 0.3275911 * $z);
        $erf = 1 - (((((1.061405429 * $t - 1.453152027) * $t) + 1.421413741) * $t - 0.284496736) * $t + 0.254829592) * $t * exp(-$z * $z);

        return 0.5 * (1 + ($x < 0 ? -$erf : $erf));
    }

    /**
     * A simple Bayesian estimate: each variant's conversion rate has a Beta(1 + goals, 1 + views - goals) posterior, the difference
     * of the two is taken as normal. Returns the rates, the probability that B beats A and the expected relative uplift of B with
     * its 95% range.
     *
     * @return array{rate_a: float, rate_b: float, probability_b: float, uplift: float, uplift_low: float, uplift_high: float}
     */
    public static function analyze(int $viewsA, int $goalsA, int $viewsB, int $goalsB): array
    {
        $viewsA = max(0, $viewsA);
        $viewsB = max(0, $viewsB);
        $goalsA = max(0, min($goalsA, $viewsA));
        $goalsB = max(0, min($goalsB, $viewsB));
        $meanA = (1 + $goalsA) / (2 + $viewsA);
        $meanB = (1 + $goalsB) / (2 + $viewsB);
        $variance = $meanA * (1 - $meanA) / (3 + $viewsA) + $meanB * (1 - $meanB) / (3 + $viewsB);
        $sd = sqrt($variance);
        $difference = $meanB - $meanA;

        return [
            'rate_a' => $viewsA > 0 ? $goalsA / $viewsA : 0.0,
            'rate_b' => $viewsB > 0 ? $goalsB / $viewsB : 0.0,
            'probability_b' => self::normalCdf($difference / $sd),
            'uplift' => $difference / $meanA,
            'uplift_low' => ($difference - 1.96 * $sd) / $meanA,
            'uplift_high' => ($difference + 1.96 * $sd) / $meanA,
        ];
    }

    /**
     * Whether a result can be trusted: 'collecting' (the guardrails of views and days are not met yet), 'winner' (B or A is
     * better with the probability the guardrails ask for) or 'inconclusive' (enough data, no clear difference).
     *
     * @param array{a: array{views: int, goals: int}, b: array{views: int, goals: int}} $counts
     * @return array{state: string, winner: ?string, analysis: array<string, float>, days: int, checks: list<array{text: string, met: bool}>}
     */
    public static function verdict(array $counts, int $days): array
    {
        $analysis = self::analyze($counts['a']['views'], $counts['a']['goals'], $counts['b']['views'], $counts['b']['goals']);
        $enough = $counts['a']['views'] >= self::MIN_VIEWS && $counts['b']['views'] >= self::MIN_VIEWS;
        $long = $days >= self::MIN_DAYS;
        $betterB = $analysis['probability_b'] >= self::MIN_PROBABILITY && $analysis['uplift'] >= self::MIN_UPLIFT;
        $betterA = 1 - $analysis['probability_b'] >= self::MIN_PROBABILITY;
        $state = !$enough || !$long ? 'collecting' : ($betterB || $betterA ? 'winner' : 'inconclusive');

        return [
            'state' => $state,
            'winner' => $state === 'winner' ? ($betterB ? 'b' : 'a') : null,
            'analysis' => $analysis,
            'days' => $days,
            'checks' => [
                ['text' => t('At least %d views of each version', self::MIN_VIEWS), 'met' => $enough],
                ['text' => t('Running for at least %d days', self::MIN_DAYS), 'met' => $long],
                ['text' => t('At least %d%% probability that one version is better', (int) round(self::MIN_PROBABILITY * 100)), 'met' => $betterB || $betterA],
                ['text' => t('B must also be at least %d%% better to replace A', (int) round(self::MIN_UPLIFT * 100)), 'met' => $analysis['uplift'] >= self::MIN_UPLIFT],
            ],
        ];
    }

    /** @return array{a: array{views: int, goals: int}, b: array{views: int, goals: int}} */
    public static function counts(Db $db, int $id): array
    {
        $counts = ['a' => ['views' => 0, 'goals' => 0], 'b' => ['views' => 0, 'goals' => 0]];
        foreach ($db->all('SELECT variant, SUM(views) AS views, SUM(goals) AS goals FROM {stats_experiments} WHERE experiment_id = ? GROUP BY variant', [$id]) as $r) {
            if (isset($counts[$r['variant']])) {
                $counts[$r['variant']] = ['views' => (int) $r['views'], 'goals' => (int) $r['goals']];
            }
        }

        return $counts;
    }

    /** Days between the start (or the end) of an experiment and now. */
    public static function daysRunning(array $row): int
    {
        if (empty($row['started_at'])) {
            return 0;
        }
        $end = !empty($row['ended_at']) ? strtotime((string) $row['ended_at']) : time();

        return max(0, (int) floor(((int) $end - (int) strtotime((string) $row['started_at'])) / 86400));
    }

    /** A row of the table with the counts and the verdict. @return array<string, mixed> */
    public static function describe(Db $db, array $row): array
    {
        $row['counts'] = self::counts($db, (int) $row['experiment_id']);
        $row['verdict'] = self::verdict($row['counts'], self::daysRunning($row));

        return $row;
    }

    /** @return list<array<string, mixed>> */
    public static function all(Db $db): array
    {
        return array_map(fn (array $r): array => self::describe($db, $r), $db->all(
            'SELECT x.*, p.title AS page_title, p.public_id AS page_public_id FROM {experiments} x INNER JOIN {pages} p ON p.page_id = x.page_id ORDER BY x.experiment_id DESC'));
    }

    public static function byId(Db $db, int $id): ?array
    {
        $r = $db->one('SELECT x.*, p.title AS page_title, p.public_id AS page_public_id FROM {experiments} x INNER JOIN {pages} p ON p.page_id = x.page_id WHERE x.experiment_id = ?', [$id]);

        return $r === null ? null : self::describe($db, $r);
    }

    /* ---------- the page ---------- */

    /** Running experiments whose version A is this page. @return list<array<string, mixed>> */
    public static function forPage(Db $db, int $pageId): array
    {
        return $db->all("SELECT * FROM {experiments} WHERE page_id = ? AND status = 'running' ORDER BY experiment_id", [$pageId]);
    }

    /** Running experiments whose goal is reaching this page. @return list<array<string, mixed>> */
    public static function goalsOnPage(Db $db, int $pageId): array
    {
        return $db->all("SELECT * FROM {experiments} WHERE goal_page_id = ? AND status = 'running' ORDER BY experiment_id", [$pageId]);
    }

    /**
     * The page build with the variants of its running experiments in it: B stands next to A (after it, or after the last root of
     * the page), each marked through the render context (data-experiment, data-variant). Anything that cannot be found is left alone.
     *
     * @param list<array<string, mixed>> $experiments
     * @return array<string, mixed> the build
     */
    public static function expand(array $build, array $experiments, Db $db, Context $k): array
    {
        $children = $build['children'] ?? [];
        foreach ($experiments as $x) {
            $mark = fn (string $variant): string => ' data-experiment="' . e((string) $x['public_id']) . '" data-variant="' . $variant . '"';
            $variantRoots = self::variantRoots($db, $x);
            if ($variantRoots === []) {
                continue;
            }
            if ($x['kind'] === 'page') {
                foreach ($children as $root) {
                    $k->marks[(string) $root['id']] = $mark('a');
                }
                foreach ($variantRoots as $root) {
                    $k->marks[(string) $root['id']] = $mark('b');
                }
                $children = [...$children, ...$variantRoots];
                continue;
            }
            $found = false;
            $children = self::mapElements($children, (string) $x['element_id'], function (array $element) use (&$found, $k, $mark, $variantRoots): array {
                $found = true;
                $k->marks[(string) $element['id']] = $mark('a');
                foreach ($variantRoots as $root) {
                    $k->marks[(string) $root['id']] = $mark('b');
                }

                return [$element, ...$variantRoots];
            });
            if (!$found) {
                continue; // the element was deleted from the page: the experiment shows nothing new
            }
        }

        return ['children' => $children] + $build;
    }

    /** The elements of variant B. @return list<array<string, mixed>> */
    private static function variantRoots(Db $db, array $x): array
    {
        $json = $x['kind'] === 'page'
            ? ($x['variant_page_id'] !== null ? $db->value('SELECT build FROM {pages} WHERE page_id = ? AND deleted_at IS NULL AND ' . \Talea\Core\Members::notGated('page', 'page_id'), [(int) $x['variant_page_id']]) : null)
            : ($x['variant_component_id'] !== null ? $db->value('SELECT build FROM {components} WHERE component_id = ?', [(int) $x['variant_component_id']]) : null);

        return array_values(Build::fromJson($json === null ? null : (string) $json)['children'] ?? []);
    }

    /**
     * Replaces the element with the given id by the list the function returns (anywhere in the tree).
     *
     * @param list<array<string, mixed>> $children
     * @param callable(array<string, mixed>): list<array<string, mixed>> $replace
     * @return list<array<string, mixed>>
     */
    private static function mapElements(array $children, string $id, callable $replace): array
    {
        $result = [];
        foreach ($children as $element) {
            if ((string) ($element['id'] ?? '') === $id) {
                array_push($result, ...$replace($element));
                continue;
            }
            if (is_array($element['children'] ?? null)) {
                $element['children'] = self::mapElements(array_values($element['children']), $id, $replace);
            }
            $result[] = $element;
        }

        return $result;
    }

    /** The element with this id, or null. @param list<array<string, mixed>> $children */
    public static function findElement(array $children, string $id): ?array
    {
        foreach ($children as $element) {
            if ((string) ($element['id'] ?? '') === $id) {
                return $element;
            }
            if (is_array($element['children'] ?? null) && ($inside = self::findElement(array_values($element['children']), $id)) !== null) {
                return $inside;
            }
        }

        return null;
    }

    /**
     * The elements of a build a test can be made on, in document order: [id, label, depth]. A section is the best choice for a
     * block, a heading or a button for a single piece of text.
     *
     * @param list<array<string, mixed>> $children
     * @return list<array{id: string, label: string, depth: int}>
     */
    public static function choices(array $children, int $depth = 0): array
    {
        $list = [];
        foreach ($children as $element) {
            $class = Build::className((string) ($element['type'] ?? ''));
            if ($class === null || count($list) >= self::MAX_CHOICES) {
                continue;
            }
            $text = '';
            foreach (['text', 'title', 'heading', 'html'] as $key) {
                if (is_string($element['content'][$key] ?? null) && trim(strip_tags($element['content'][$key])) !== '') {
                    $text = mb_strimwidth(trim((string) preg_replace('/\s+/', ' ', strip_tags($element['content'][$key]))), 0, 50, '…');
                    break;
                }
            }
            $list[] = ['id' => (string) $element['id'], 'label' => t($class::NAME) . ($text !== '' ? ': ' . $text : ''), 'depth' => $depth];
            if (is_array($element['children'] ?? null) && $depth < 4) {
                array_push($list, ...self::choices(array_values($element['children']), $depth + 1));
            }
        }

        return $list;
    }

    /**
     * What the head of the page needs: the hiding rules and the chooser script (always), the configuration of image/web.js (with
     * an endpoint: only where counting is allowed).
     *
     * @param array{x: list<array{id: string, goal: string, target: string}>, g: list<array{id: string, path: string}>} $config
     */
    public static function head(array $config, string $endpoint): string
    {
        if ($config['x'] === [] && $config['g'] === []) {
            return '';
        }
        $html = '';
        if ($config['x'] !== []) {
            // not in a cascade layer on purpose: the rule only hides the version the visitor does not get, and must win over the element's own style
            $css = '';
            foreach ($config['x'] as $x) {
                $id = e($x['id']);
                $css .= 'html:not([data-variants~="' . $id . ':b"]) [data-experiment="' . $id . '"][data-variant="b"]{display:none}'
                    . 'html[data-variants~="' . $id . ':b"] [data-experiment="' . $id . '"][data-variant="a"]{display:none}';
            }
            $ids = (string) json_encode(array_column($config['x'], 'id'));
            // before the first paint: a random version per view, and only with consent (the cookie bar's own cookie) one that is remembered
            $html .= '<style>' . $css . '</style>' . "\n"
                . '<script>(function(d){var i=' . $ids . ',o=[],s={},c=/(?:^|; )talea_consent=[^;]*(?:analytics|marketing)/.test(d.cookie);'
                . 'try{if(c){s=JSON.parse(localStorage.getItem("tl-x")||"{}")||{}}else if(localStorage.getItem("tl-x")){localStorage.removeItem("tl-x")}}catch(e){s={}}'
                . 'i.forEach(function(k){var v=s[k];if(v!=="a"&&v!=="b"){v=Math.random()<.5?"a":"b";s[k]=v}o.push(k+":"+v)});'
                . 'd.documentElement.setAttribute("data-variants",o.join(" "));'
                . 'if(c){try{localStorage.setItem("tl-x",JSON.stringify(s))}catch(e){}}})(document)</script>' . "\n";
        }
        $json = (string) json_encode(['url' => $endpoint] + $config, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP);

        return $html . '<script type="application/json" id="tl-experiments">' . $json . '</script>' . "\n";
    }

    /**
     * The configuration for a page: its running experiments (id, goal) and the experiments for which this page is the goal.
     *
     * @param list<array<string, mixed>> $running
     * @param list<array<string, mixed>> $reaching
     * @return array{x: list<array{id: string, goal: string, target: string}>, g: list<array{id: string, path: string}>}
     */
    public static function config(App $app, array $running, array $reaching, string $path): array
    {
        return [
            'x' => array_map(fn (array $x): array => ['id' => (string) $x['public_id'], 'goal' => (string) $x['goal_type'], 'target' => (string) $x['goal_target']], $running),
            'g' => array_map(fn (array $x): array => ['id' => (string) $x['public_id'], 'path' => $path], $reaching),
        ];
    }

    /** POST /experiment: one beacon per view or goal (image/web.js); always 204, anything unwanted is simply not counted. */
    public static function record(App $app): Response
    {
        $request = $app->request;
        $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
        $variant = $request->post('variant');
        $event = $request->post('event');
        if (!in_array($variant, ['a', 'b'], true) || !in_array($event, ['view', 'goal'], true) || !Stats::isOn($app) || $ua === '' || Stats::isBot($ua) || $app->auth()->user() !== null) {
            return new Response('', 204);
        }
        $db = $app->db();
        $id = $db->internalId('experiments', $request->post('experiment'));
        if ($id <= 0) {
            return new Response('', 204);
        }
        $antispam = new Antispam($db, $app->settings());
        if ($antispam->count($request->ip(), 'experiment', 0, 60) >= 120) {
            return new Response('', 204);
        }
        $antispam->write($request->ip(), 'experiment', 0);
        if ($db->value("SELECT experiment_id FROM {experiments} WHERE experiment_id = ? AND status = 'running'", [$id]) === null) {
            return new Response('', 204);
        }
        $column = $event === 'view' ? 'views' : 'goals';
        $db->upsert('stats_experiments', ['day' => date('Y-m-d'), 'experiment_id' => $id, 'variant' => $variant, 'views' => (int) ($column === 'views'), 'goals' => (int) ($column === 'goals')],
            ['day', 'experiment_id', 'variant'], [$column => '{old.' . $column . '} + 1']);

        return new Response('', 204);
    }

    /* ---------- creating, running, ending ---------- */

    /**
     * A new experiment (stopped, "draft"): for an element test variant B is a copy of the element as a component, for a page test the other page.
     *
     * @param array<string, mixed> $a name, page (internal id), kind, element, variant_page (internal id), goal, goal_target, goal_page (internal id), auto_promote
     * @return array<string, mixed>|string the row, or the English reason it cannot be created
     */
    public static function create(App $app, array $a): array|string
    {
        $db = $app->db();
        $name = mb_substr(trim((string) ($a['name'] ?? '')), 0, 100);
        $kind = (string) ($a['kind'] ?? 'element');
        $goal = (string) ($a['goal'] ?? '');
        $page = $db->one('SELECT page_id, title, build FROM {pages} WHERE page_id = ? AND deleted_at IS NULL', [(int) ($a['page'] ?? 0)]);
        if ($name === '') {
            return 'The experiment needs a name.';
        }
        if (!isset(self::KINDS[$kind]) || !isset(self::GOALS[$goal])) {
            return 'Choose what to test and the goal.';
        }
        if ($page === null) {
            return 'The page does not exist.';
        }
        $build = Build::fromJson($page['build'] === null ? null : (string) $page['build']);
        if ($build === null) {
            return 'Publish the page in the builder first: a test needs a published page made in the builder.';
        }
        $row = ['name' => $name, 'kind' => $kind, 'page_id' => (int) $page['page_id'], 'goal_type' => $goal, 'goal_target' => '', 'status' => 'draft',
            'auto_promote' => !empty($a['auto_promote']) && $app->auth()->canPublish() ? 1 : 0, 'created_at' => date('Y-m-d H:i:s')];
        if ($goal === 'click') {
            $row['goal_target'] = mb_substr(trim((string) ($a['goal_target'] ?? '')), 0, 255);
            if ($row['goal_target'] === '') {
                return 'A click goal needs the address the link or button leads to.';
            }
        }
        if ($goal === 'page') {
            $goalPage = (int) ($a['goal_page'] ?? 0);
            if ($db->value('SELECT page_id FROM {pages} WHERE page_id = ? AND deleted_at IS NULL', [$goalPage]) === null) {
                return 'Choose the page that counts as the goal.';
            }
            $row['goal_page_id'] = $goalPage;
        }
        $component = null;
        if ($kind === 'page') {
            $other = $db->one('SELECT page_id, build FROM {pages} WHERE page_id = ? AND deleted_at IS NULL AND ' . \Talea\Core\Members::notGated('page', 'page_id'), [(int) ($a['variant_page'] ?? 0)]);
            if ($other === null || (int) $other['page_id'] === (int) $page['page_id']) {
                return 'Choose another page as version B.';
            }
            if ($other['build'] === null) {
                return 'Version B must be a published page made in the builder.';
            }
            $row['variant_page_id'] = (int) $other['page_id'];
        } else {
            $element = self::findElement($build['children'], (string) ($a['element'] ?? ''));
            if ($element === null) {
                return 'The element is not in the published page.';
            }
            [$copy] = Build::sanitize(['v' => Build::VERSION, 'children' => ElementClipboard::fresh([$element])], true);
            if ($copy['children'] === []) {
                return 'This element cannot be tested.';
            }
            $row['element_id'] = (string) $element['id'];
            $component = ['name' => mb_substr(t('Test: %s – version B', $name), 0, 100), 'properties' => '[]', 'build' => Build::toJson($copy), 'updated_at' => date('Y-m-d H:i:s')];
        }
        $id = $db->transaction(function () use ($db, $row, $component): int {
            if ($component !== null) {
                $row['variant_component_id'] = $db->insert('components', $component);
            }

            return $db->insert('experiments', $row);
        });

        return self::byId($db, $id) ?? 'The experiment could not be created.';
    }

    /** Starts (or restarts) an experiment. @return ?string the English reason it cannot start */
    public static function start(Db $db, array $x): ?string
    {
        if (in_array($x['status'], ['running', 'promoted'], true)) {
            return 'The experiment is already running or finished.';
        }
        $others = $db->all("SELECT kind, element_id FROM {experiments} WHERE page_id = ? AND status = 'running' AND experiment_id <> ?", [(int) $x['page_id'], (int) $x['experiment_id']]);
        foreach ($others as $o) {
            if ($o['kind'] === 'page' || $x['kind'] === 'page' || $o['element_id'] === $x['element_id']) {
                return 'Another experiment is running on this page and covers the same content. End it first.';
            }
        }
        if (self::variantRoots($db, $x) === []) {
            return 'Version B has no content yet. Publish it first.';
        }
        // restarting clears what was counted before: the numbers would mix two different set-ups
        if ($x['status'] === 'stopped') {
            $db->delete('stats_experiments', ['experiment_id' => (int) $x['experiment_id']]);
        }
        $db->update('experiments', ['status' => 'running', 'started_at' => date('Y-m-d H:i:s'), 'ended_at' => null, 'winner' => null], ['experiment_id' => (int) $x['experiment_id']]);
        \Talea\Front\Cache::clear();

        return null;
    }

    public static function stop(Db $db, array $x): void
    {
        if ($x['status'] === 'running') {
            $db->update('experiments', ['status' => 'stopped', 'ended_at' => date('Y-m-d H:i:s')], ['experiment_id' => (int) $x['experiment_id']]);
            \Talea\Front\Cache::clear();
        }
    }

    /**
     * Applies the winner. B replaces A as a normal build change: published through Builder\Publisher when the person may publish
     * (and $publish is true), otherwise saved as the page's draft. A just ends the test, nothing on the page changes. The
     * change log gets the reason; what was replaced is kept, so that undo() can put it back.
     *
     * @return ?string the English reason it cannot be applied
     */
    public static function promote(App $app, array $x, string $winner, bool $publish, string $reason): ?string
    {
        $db = $app->db();
        if (!in_array($x['status'], ['running', 'stopped'], true)) {
            return 'The experiment is not running or stopped: nothing to promote.';
        }
        $update = ['status' => 'promoted', 'ended_at' => date('Y-m-d H:i:s'), 'winner' => $winner];
        if ($winner === 'b') {
            $page = $db->one('SELECT * FROM {pages} WHERE page_id = ?', [(int) $x['page_id']]);
            $variant = self::variantRoots($db, $x);
            if ($page === null || $variant === []) {
                return 'Version B has no content to promote.';
            }
            $apply = fn (?string $json): ?string => self::withVariant($json, $x, $variant);
            $published = $apply((string) $page['build']);
            if ($published === null) {
                return 'The element is no longer in the published page.';
            }
            $draft = $page['build_draft'] !== null ? $apply((string) $page['build_draft']) : null;
            if ($publish) {
                $update += ['previous_build' => $page['build'], 'promoted_as' => 'published'];
                Publisher::page($app, ['build_draft' => $published] + $page);
                if ($page['build_draft'] !== null) {
                    $db->update('pages', ['build_draft' => $draft ?? $page['build_draft']], ['page_id' => $page['page_id']]);
                }
            } else {
                $update += ['previous_build' => $page['build_draft'], 'promoted_as' => 'draft'];
                $db->update('pages', ['build_draft' => $draft ?? $published], ['page_id' => $page['page_id']]);
            }
        }
        $db->update('experiments', $update, ['experiment_id' => (int) $x['experiment_id']]);
        \Talea\Front\Cache::clear();
        ChangeLog::write($app, 'experiments', $winner === 'b' ? 'promote' : 'keep_original', $x['name'] . ($winner === 'b' ? ' (' . ($update['promoted_as'] ?? '') . ')' : ''), $reason);

        return null;
    }

    /** Puts back what the promotion replaced (published page: published again; draft: the draft as it was). @return ?string the English reason it cannot be undone */
    public static function undo(App $app, array $x, bool $publish): ?string
    {
        $db = $app->db();
        if ($x['status'] !== 'promoted' || $x['winner'] !== 'b' || $x['promoted_as'] === null) {
            return 'Nothing to undo: version B was not applied.';
        }
        $page = $db->one('SELECT * FROM {pages} WHERE page_id = ?', [(int) $x['page_id']]);
        if ($page === null) {
            return 'The page no longer exists.';
        }
        if ($x['promoted_as'] === 'published') {
            if (!$publish) {
                return 'Only someone who may publish can undo a published change.';
            }
            if ($x['previous_build'] === null) {
                return 'The previous version is not kept.';
            }
            Publisher::page($app, ['build_draft' => $x['previous_build']] + $page);
        } else {
            $db->update('pages', ['build_draft' => $x['previous_build']], ['page_id' => $page['page_id']]);
        }
        $db->update('experiments', ['status' => 'stopped', 'winner' => null, 'promoted_as' => null, 'previous_build' => null], ['experiment_id' => (int) $x['experiment_id']]);
        \Talea\Front\Cache::clear();
        ChangeLog::write($app, 'experiments', 'undo', (string) $x['name']);

        return null;
    }

    /** The build JSON with version B in place of A (new ids, the anchor of A kept). Null when A is not in it. @param list<array<string, mixed>> $variant */
    private static function withVariant(?string $json, array $x, array $variant): ?string
    {
        $build = Build::fromJson($json);
        if ($build === null) {
            return null;
        }
        $fresh = ElementClipboard::fresh($variant);
        if ($x['kind'] === 'page') {
            $children = $fresh;
        } else {
            $found = false;
            $children = self::mapElements($build['children'], (string) $x['element_id'], function (array $element) use (&$found, $fresh): array {
                $found = true;
                if (($element['anchor'] ?? '') !== '' && $fresh !== []) {
                    $fresh[0]['anchor'] = $element['anchor'];
                }

                return $fresh;
            });
            if (!$found) {
                return null;
            }
        }
        [$sanitized] = Build::sanitize(['v' => Build::VERSION, 'children' => $children], true);

        return $sanitized['children'] === [] ? null : Build::toJson($sanitized);
    }

    /** Scheduler job: running experiments that opted in and met every guardrail are promoted (published) or ended. */
    public static function autoPromote(App $app): string
    {
        $done = 0;
        foreach ($app->db()->all("SELECT * FROM {experiments} WHERE status = 'running' AND auto_promote = TRUE") as $row) {
            $x = self::describe($app->db(), $row);
            if ($x['verdict']['state'] !== 'winner') {
                continue;
            }
            $a = $x['verdict']['analysis'];
            $reason = t('Automatic: guardrails met after %d days (%d and %d views, probability %d%%, uplift %d%%).', $x['verdict']['days'], $x['counts']['a']['views'], $x['counts']['b']['views'],
                (int) round(($x['verdict']['winner'] === 'b' ? $a['probability_b'] : 1 - $a['probability_b']) * 100), (int) round($a['uplift'] * 100));
            if (self::promote($app, $x, (string) $x['verdict']['winner'], true, $reason) === null) {
                $done++;
            }
        }

        return $done > 0 ? 'promoted ' . $done : 'ok';
    }
}
