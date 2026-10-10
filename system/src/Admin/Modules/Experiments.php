<?php

declare(strict_types=1);

namespace Talea\Admin\Modules;

use Talea\Admin\Module;
use Talea\Builder\Build;
use Talea\Builder\Experiments as Tests;
use Talea\Core\Response;

/**
 * Experiments: A/B tests of a section, an element or a whole page against a goal. Visitors get a random version on every view
 * and nothing is stored; the counts are cookie-free (Builder\Experiments). A person promotes the winner (or opts in to the
 * automatic promotion within fixed guardrails); every promotion is in the change log and can be undone.
 */
final class Experiments extends Module
{
    public const string IDENT = 'experiments';
    public const string NAME = 'Experiments';
    public const string GROUP = 'Customers';
    public const string ICON = 'experiments';
    public const string EXTENSION = 'stats';
    public const string TABLE = 'experiments';

    protected function actionList(): Response
    {
        return $this->view('list', 'Experiments', ['experiments' => Tests::all($this->db)]);
    }

    /** Step one: the page; step two (with ?page=): what to test, version B and the goal. */
    protected function actionNew(): Response
    {
        $page = $this->db->byPublicId('pages', $this->request->get('page'));
        $build = $page !== null && $page['build'] !== null ? Build::fromJson((string) $page['build']) : null;

        return $this->view('new', 'New experiment', [
            'pages' => $this->builderPages(),
            'page' => $build !== null ? $page : null,
            'choices' => $build !== null ? Tests::choices($build['children']) : [],
        ]);
    }

    protected function actionCreate(): Response
    {
        $r = $this->request;
        if (!$r->isPost()) {
            return $this->back();
        }
        $x = Tests::create($this->app, [
            'name' => $r->post('name'), 'kind' => $r->post('kind'), 'element' => $r->post('element'), 'goal' => $r->post('goal'), 'goal_target' => $r->post('goal_target'),
            'auto_promote' => $r->postBool('auto_promote'),
            'page' => $this->db->internalId('pages', $r->post('page')), 'variant_page' => $this->db->internalId('pages', $r->post('variant_page')),
            'goal_page' => $this->db->internalId('pages', $r->post('goal_page')),
        ]);
        if (is_string($x)) {
            return $this->back(t($x), 'new', ['page' => $r->post('page')], 'error');
        }

        return $this->back('The experiment was created. Edit version B, then start it.', 'show', ['id' => $x['public_id']]);
    }

    protected function actionShow(): Response
    {
        $x = Tests::byId($this->db, $this->idParam());
        if ($x === null) {
            return $this->error('The experiment does not exist.', 404);
        }
        $component = $x['variant_component_id'] !== null ? $this->db->one('SELECT public_id FROM {components} WHERE component_id = ?', [(int) $x['variant_component_id']]) : null;
        $variantPage = $x['variant_page_id'] !== null ? $this->db->one('SELECT public_id, title FROM {pages} WHERE page_id = ?', [(int) $x['variant_page_id']]) : null;
        $goalPage = $x['goal_page_id'] !== null ? (string) $this->db->value('SELECT title FROM {pages} WHERE page_id = ?', [(int) $x['goal_page_id']]) : '';

        return $this->view('show', $x['name'], ['x' => $x, 'component' => $component, 'variantPage' => $variantPage, 'goalPage' => $goalPage,
            'canPublish' => $this->app->auth()->canPublish()]);
    }

    protected function actionStart(): Response
    {
        return $this->change(fn (array $x): ?string => Tests::start($this->db, $x), 'The experiment is running. Visitors now get a random version.');
    }

    protected function actionStop(): Response
    {
        return $this->change(function (array $x): ?string {
            Tests::stop($this->db, $x);

            return null;
        }, 'The experiment was stopped. Visitors see the original again.');
    }

    protected function actionPromote(): Response
    {
        $winner = $this->request->post('winner') === 'a' ? 'a' : 'b';
        $canPublish = $this->app->auth()->canPublish();

        return $this->change(fn (array $x): ?string => Tests::promote($this->app, $x, $winner, $canPublish, mb_substr(trim($this->request->post('reason')), 0, 200)),
            $winner === 'a' ? 'The original stays. The experiment is over.' : ($canPublish ? 'Version B replaced the original and is published. You can undo it here.' : 'Version B is saved as a draft of the page. Someone who may publish has to publish it.'));
    }

    protected function actionUndo(): Response
    {
        $canPublish = $this->app->auth()->canPublish();

        return $this->change(fn (array $x): ?string => Tests::undo($this->app, $x, $canPublish), 'The promotion was undone. The page is as it was before.');
    }

    protected function actionAuto(): Response
    {
        return $this->change(function (array $x): ?string {
            $this->db->update('experiments', ['auto_promote' => $this->request->postBool('auto_promote') && $this->app->auth()->canPublish() ? 1 : 0], ['experiment_id' => (int) $x['experiment_id']]);

            return null;
        }, 'The setting was saved.');
    }

    protected function actionDelete(): Response
    {
        $x = $this->request->isPost() ? Tests::byId($this->db, $this->idParam('experiment_id')) : null;
        if ($x !== null) {
            $this->db->delete('experiments', ['experiment_id' => (int) $x['experiment_id']]);
            if ($x['variant_component_id'] !== null && $x['status'] !== 'promoted') {
                $this->db->delete('components', ['component_id' => (int) $x['variant_component_id']]);
            }
            \Talea\Front\Cache::clear();
        }

        return $this->back('The experiment was deleted.');
    }

    /** Runs a change on the experiment named by the form and comes back to its screen. @param callable(array<string, mixed>): ?string $change */
    private function change(callable $change, string $done): Response
    {
        $x = $this->request->isPost() ? Tests::byId($this->db, $this->idParam('experiment_id')) : null;
        if ($x === null) {
            return $this->back();
        }
        $problem = $change($x);
        \Talea\Front\Cache::clear();

        return $problem !== null ? $this->back(t($problem), 'show', ['id' => $x['public_id']], 'error') : $this->back($done, 'show', ['id' => $x['public_id']]);
    }

    /** @return list<array{public_id: string, title: string}> published pages made in the builder */
    private function builderPages(): array
    {
        return $this->db->all('SELECT public_id, title FROM {pages} WHERE deleted_at IS NULL AND build IS NOT NULL ORDER BY language, sort_order, title LIMIT 500');
    }
}
