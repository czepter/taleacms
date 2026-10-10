<?php

declare(strict_types=1);

namespace Talea\Mcp\Handlers;

use Talea\Builder\Experiments;

/**
 * MCP tools of the A/B tests (Builder\Experiments): list, create, read the result, start, stop, undo and delete, and – only on
 * the user's explicit request – promote the winner. Part of Mcp\Tools.
 *
 * @phpstan-ignore trait.unused
 */
trait ExperimentTools
{
    /** list_experiments */
    private function toolListExperiments(string $name, array $a): mixed
    {
        $this->experimentAccess();

        return array_map($this->experiment(...), Experiments::all($this->app->db()));
    }

    /** create_experiment */
    private function toolCreateExperiment(string $name, array $a): mixed
    {
        $this->experimentAccess();
        $x = Experiments::create($this->app, [
            'name' => $a['name'] ?? '', 'kind' => $a['kind'] ?? 'element', 'page' => (int) ($a['page'] ?? 0), 'element' => $a['element'] ?? '', 'variant_page' => (int) ($a['variant_page'] ?? 0),
            'goal' => $a['goal'] ?? '', 'goal_target' => $a['goal_target'] ?? '', 'goal_page' => (int) ($a['goal_page'] ?? 0), 'auto_promote' => !empty($a['auto_promote']),
        ]);
        if (is_string($x)) {
            throw new \InvalidArgumentException($x);
        }
        if (!empty($a['start'])) {
            $problem = Experiments::start($this->app->db(), $x);
            if ($problem !== null) {
                throw new \DomainException('The experiment was created but did not start: ' . $problem . ' (id ' . $this->pid('experiments', (int) $x['experiment_id']) . ')');
            }
            $x = Experiments::byId($this->app->db(), (int) $x['experiment_id']) ?? $x;
        }

        return $this->experiment($x) + ['next' => $x['kind'] === 'element'
            ? 'Version B is a copy of the element, saved as the component in version_b (edit it with get_build / edit_build / publish_build and the component parameter). Start the experiment with update_experiment when B is ready.'
            : 'Version B is the other page. Start the experiment with update_experiment when it is ready.'];
    }

    /** get_experiment_result */
    private function toolGetExperimentResult(string $name, array $a): mixed
    {
        $this->experimentAccess();

        return $this->experiment($this->experimentRow($a), true);
    }

    /** update_experiment */
    private function toolUpdateExperiment(string $name, array $a): mixed
    {
        $this->experimentAccess();
        $db = $this->app->db();
        $x = $this->experimentRow($a);
        $action = (string) ($a['action'] ?? '');
        $problem = match ($action) {
            'start' => Experiments::start($db, $x),
            'stop' => (function () use ($db, $x): ?string {
                Experiments::stop($db, $x);

                return null;
            })(),
            'undo_promotion' => Experiments::undo($this->app, $x, $this->app->auth()->canPublish()),
            '' => null,
            default => 'The action must be start, stop or undo_promotion.',
        };
        if ($problem !== null) {
            throw new \DomainException($problem);
        }
        if (array_key_exists('auto_promote', $a)) {
            $db->update('experiments', ['auto_promote' => $a['auto_promote'] && $this->app->auth()->canPublish() ? 1 : 0], ['experiment_id' => $x['experiment_id']]);
        }
        \Talea\Front\Cache::clear();

        return $this->experiment(Experiments::byId($db, (int) $x['experiment_id']) ?? $x);
    }

    /** promote_experiment_winner */
    private function toolPromoteExperimentWinner(string $name, array $a): mixed
    {
        $this->experimentAccess();
        $x = $this->experimentRow($a);
        $verdict = $x['verdict'];
        $winner = isset($a['winner']) ? (string) $a['winner'] : (string) $verdict['winner'];
        if (!in_array($winner, ['a', 'b'], true)) {
            throw new \InvalidArgumentException($verdict['state'] === 'winner' ? 'The winner must be a or b.' : 'There is no winner yet (' . $verdict['state'] . '). Read get_experiment_result; pass winner and force true only when the user insists.');
        }
        if ($verdict['state'] !== 'winner' && empty($a['force'])) {
            throw new \DomainException('The result is not trustworthy yet (' . $verdict['state'] . '): the guardrails are not met. Tell the user; pass force true only if they insist on promoting anyway.');
        }
        $problem = Experiments::promote($this->app, $x, $winner, $this->app->auth()->canPublish(), 'Promoted through Claude on the user\'s explicit request.');
        if ($problem !== null) {
            throw new \DomainException($problem);
        }
        $after = Experiments::byId($this->app->db(), (int) $x['experiment_id']) ?? $x;

        return $this->experiment($after) + ['applied_as' => $winner === 'b' ? $after['promoted_as'] : 'nothing changed (the original stays)',
            'next' => $after['promoted_as'] === 'draft' ? 'Version B is in the page draft; an editor or administrator publishes it. update_experiment with undo_promotion puts the page back.'
                : 'Undo with update_experiment action undo_promotion.'];
    }

    /** delete_experiment */
    private function toolDeleteExperiment(string $name, array $a): mixed
    {
        $this->experimentAccess();
        $db = $this->app->db();
        $x = $this->experimentRow($a);
        $db->delete('experiments', ['experiment_id' => $x['experiment_id']]);
        if ($x['variant_component_id'] !== null && $x['status'] !== 'promoted') {
            $db->delete('components', ['component_id' => $x['variant_component_id']]);
        }
        \Talea\Front\Cache::clear();

        return ['deleted' => $this->pid('experiments', (int) $x['experiment_id'])];
    }

    /** The experiment an id argument names (already an internal id here). @return array<string, mixed> */
    private function experimentRow(array $a): array
    {
        return Experiments::byId($this->app->db(), (int) ($a['id'] ?? 0)) ?? throw new \InvalidArgumentException('The experiment does not exist. Use list_experiments.');
    }

    private function experimentAccess(): void
    {
        if (!$this->app->auth()->hasModule('experiments')) {
            throw new \DomainException('This user has no access to Experiments – an administrator gives it in Users or Roles.');
        }
    }

    /** @return array<string, mixed> */
    private function experiment(array $x, bool $detail = false): array
    {
        $v = $x['verdict'];
        $out = [
            'id' => $this->pid('experiments', (int) $x['experiment_id']), 'name' => $x['name'], 'status' => $x['status'], 'kind' => $x['kind'],
            'page' => $this->pid('pages', (int) $x['page_id']), 'element' => $x['element_id'],
            'version_b' => $x['kind'] === 'page'
                ? ($x['variant_page_id'] !== null ? ['page' => $this->pid('pages', (int) $x['variant_page_id'])] : null)
                : ($x['variant_component_id'] !== null ? ['component' => $this->pid('components', (int) $x['variant_component_id'])] : null),
            'goal' => $x['goal_type'] . ($x['goal_type'] === 'click' ? ' ' . $x['goal_target'] : '') . ($x['goal_page_id'] !== null ? ' ' . $this->pid('pages', (int) $x['goal_page_id']) : ''),
            'auto_promote' => (bool) $x['auto_promote'], 'winner' => $x['winner'], 'started_at' => $x['started_at'], 'ended_at' => $x['ended_at'],
            'views' => ['a' => $x['counts']['a']['views'], 'b' => $x['counts']['b']['views']], 'conversions' => ['a' => $x['counts']['a']['goals'], 'b' => $x['counts']['b']['goals']],
            'result' => ['state' => $v['state'], 'winner' => $v['winner'], 'probability_b_better' => round($v['analysis']['probability_b'], 4), 'uplift' => round($v['analysis']['uplift'], 4),
                'uplift_range' => [round($v['analysis']['uplift_low'], 4), round($v['analysis']['uplift_high'], 4)], 'days' => $v['days']],
        ];
        if ($detail) {
            $out['guardrails'] = array_map(fn (array $c): array => ['rule' => $c['text'], 'met' => $c['met']], $v['checks']);
            $out['rates'] = ['a' => round($v['analysis']['rate_a'], 4), 'b' => round($v['analysis']['rate_b'], 4)];
            $out['admin_url'] = $this->app->request->origin() . $this->app->url('admin.php?module=experiments&action=show&id=' . $out['id']);
        }

        return $out;
    }
}
