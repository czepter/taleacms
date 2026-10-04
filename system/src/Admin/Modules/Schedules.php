<?php

declare(strict_types=1);

namespace Kaleta\Admin\Modules;

use Kaleta\Admin\Module;
use Kaleta\Core\AgentSchedules;
use Kaleta\Core\Response;

/**
 * Scheduled runs (2.17, Core\AgentSchedules): the schedules the site keeps for a routine in Claude – what, how often, when –
 * with the next due time and the last run, the history of each schedule, and the "Set up in Claude" panel with the routine
 * prompt. The site does not run Claude: the routine connects over a drafts-only connection and the site records the runs.
 */
final class Schedules extends Module
{
    public const string IDENT = 'schedules';
    public const string HUB = 'claude';
    public const string PARENT = 'claude_settings';
    public const string NAME = 'Scheduled runs';
    public const string GROUP = 'Claude';
    public const string ICON = 'plan';
    public const bool ADMIN_ONLY = true;

    protected function actionList(): Response
    {
        return $this->view('list', 'Scheduled runs', ['schedules' => AgentSchedules::all($this->db), 'prompt' => AgentSchedules::routinePrompt($this->app),
            'claudeOn' => \Kaleta\Core\Extensions::isEnabled($this->app->settings(), 'claude'),
            'draftTokens' => (int) $this->db->value("SELECT COUNT(*) FROM {api_tokeny} WHERE access = 'drafts' AND druh IN ('token', 'obnova')")]);
    }

    protected function actionEdit(): Response
    {
        $id = $this->request->getInt('id');
        $schedule = $id > 0 ? AgentSchedules::get($this->db, $id) : null;
        if ($id > 0 && $schedule === null) {
            return $this->back('The schedule does not exist.', '', [], 'chyba');
        }

        return $this->view('edit', $schedule !== null ? (string) $schedule['name'] : 'New schedule', ['s' => $schedule]);
    }

    protected function actionSave(): Response
    {
        if (!$this->request->isPost()) {
            return $this->back();
        }
        $id = $this->request->postInt('id');
        $cadence = $this->request->post('cadence');
        $data = ['name' => $this->request->post('name'), 'task' => $this->request->post('task'), 'text' => $this->request->post('text'), 'cadence' => $cadence,
            'day' => $cadence === 'monthly' ? $this->request->postInt('monthday') : $this->request->postInt('weekday'), 'time' => $this->request->post('time'), 'active' => $this->request->postBool('active')];
        $error = AgentSchedules::validate($data);
        if ($error !== null) {
            return $this->back($error, 'edit', $id > 0 ? ['id' => $id] : [], 'chyba');
        }
        AgentSchedules::save($this->app, $id, $data);

        return $this->back('The schedule is saved. The site hands the run to the routine in Claude when its time comes – set the routine up below if you have not yet.');
    }

    protected function actionDelete(): Response
    {
        if ($this->request->isPost() && AgentSchedules::delete($this->app, $this->request->postInt('id'))) {
            return $this->back('The schedule and its history are deleted.');
        }

        return $this->back();
    }

    /** Pauses or resumes a schedule; resuming computes the next due time from now, so a long pause does not come back as missed. */
    protected function actionToggle(): Response
    {
        $schedule = $this->request->isPost() ? AgentSchedules::get($this->db, $this->request->postInt('id')) : null;
        if ($schedule === null) {
            return $this->back();
        }
        $active = (int) $schedule['active'] === 1 ? 0 : 1;
        $this->db->update('agent_schedules', ['active' => $active, 'next_due' => $active === 1
            ? AgentSchedules::nextDue((string) $schedule['cadence'], (int) $schedule['day'], (string) $schedule['time'], new \DateTimeImmutable())->format('Y-m-d H:i:s') : $schedule['next_due']], ['id' => (int) $schedule['id']]);

        return $this->back($active === 1 ? 'The schedule is active again.' : 'The schedule is paused – no runs are handed out until you resume it.');
    }

    protected function actionHistory(): Response
    {
        $schedule = AgentSchedules::get($this->db, $this->request->getInt('id'));
        if ($schedule === null) {
            return $this->error('The schedule does not exist.', 404);
        }

        return $this->view('history', (string) $schedule['name'], ['s' => $schedule, 'runs' => AgentSchedules::runs($this->db, (int) $schedule['id'], 100),
            'instructions' => \Kaleta\Core\Language::runWith('en', fn (): string => AgentSchedules::instructions($schedule))]); // what Claude gets, word for word
    }
}
