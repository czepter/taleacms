<?php

declare(strict_types=1);

namespace Talea\Admin\Modules;

use Talea\Admin\Module;
use Talea\Builder\Looks;
use Talea\Core\Assistant;
use Talea\Core\Blueprint;
use Talea\Core\Guardrails;
use Talea\Core\Language;
use Talea\Core\Response;
use Talea\Core\SiteWizard;

/**
 * The first-run wizard (#31, Core\SiteWizard): questions about the business, a plan to review (blueprint, look, pages), then the
 * site as drafts. The owner's own AI key is used and only on the click of "Plan the site"; without a key the blueprint and the
 * look are still applied. Under the same guardrails as Claude over MCP; the whole run is one session that Change log can undo.
 */
final class Wizard extends Module
{
    public const string IDENT = 'wizard';
    public const string NAME = 'Site wizard';
    public const string GROUP = 'Company';
    public const string ICON = 'templates';
    public const bool ADMIN_ONLY = true;
    public const string PARENT = 'business';
    public const string HUB = 'business';

    protected function actionList(): Response
    {
        $s = $this->app->settings();
        $assistant = new Assistant($s);

        return $this->view('start', 'Set up the site with the assistant', ['ready' => $assistant->isReady(), 'provider' => Assistant::PROVIDERS[$s->get('ai_provider')][0] ?? Assistant::PROVIDERS['anthropic'][0],
            'blueprints' => Blueprint::available(), 'languages' => $this->languages(), 'values' => $this->values()]);
    }

    /** The answers go to the model only now, on the click; the result is a plan the owner reviews and changes before anything is made. */
    protected function actionPlan(): Response
    {
        if (!$this->request->isPost()) {
            return $this->back();
        }
        $answers = SiteWizard::answers($this->request, $this->app->settings());
        if ($answers['name'] === '') {
            return $this->back('Enter the name of your business.', '', [], 'error');
        }
        if (($refusal = Guardrails::assistantRefusal($this->app)) !== null) {
            return $this->back($refusal, '', [], 'error');
        }
        set_time_limit(180);
        try {
            $plan = SiteWizard::plan($this->app, $answers);
        } catch (\RuntimeException $e) {
            return $this->back(t($e->getMessage()), '', [], 'error');
        }

        return $this->review($answers, $plan);
    }

    /** Applies the reviewed plan as drafts. */
    protected function actionApply(): Response
    {
        if (!$this->request->isPost()) {
            return $this->back();
        }
        $answers = SiteWizard::answers($this->request, $this->app->settings());
        $pages = [];
        foreach ((array) ($_POST['pages'] ?? []) as $page) {
            if (is_array($page) && !empty($page['use'])) {
                $pages[] = ['title' => (string) ($page['title'] ?? ''), 'brief' => (string) ($page['brief'] ?? '')];
            }
        }
        $plan = ['blueprint' => isset(Blueprint::available()[$this->request->post('plan_blueprint')]) ? $this->request->post('plan_blueprint') : '',
            'look' => isset(Looks::all()[$this->request->post('plan_look')]) ? $this->request->post('plan_look') : '', 'pages' => SiteWizard::pages($pages)];
        if ($answers['name'] === '') {
            return $this->back('Enter the name of your business.', '', [], 'error');
        }
        if (($refusal = Guardrails::assistantRefusal($this->app)) !== null) {
            return $this->back($refusal, '', [], 'error');
        }
        set_time_limit(600);
        $error = '';
        try {
            $result = SiteWizard::apply($this->app, $answers, $plan);
        } catch (\RuntimeException $e) {
            $error = t($e->getMessage());
            $result = null;
        }
        if ($result === null) {
            return $this->back($error . ' ' . t('What was made until then can be undone in the change log (Claude sessions).'), '', [], 'error');
        }

        return $this->view('done', 'Your site as drafts', ['result' => $result, 'undo' => $this->app->url('admin.php?module=changelog&action=sessions')]);
    }

    /** @param array<string, string> $answers @param array<string, mixed> $plan */
    private function review(array $answers, array $plan): Response
    {
        $blueprints = Blueprint::available();

        return $this->view('review', 'Review the plan', ['answers' => $answers, 'plan' => $plan, 'blueprints' => $blueprints, 'looks' => Looks::all(),
            'ready' => (bool) $plan['generated'], 'provider' => Assistant::PROVIDERS[$this->app->settings()->get('ai_provider')][0] ?? Assistant::PROVIDERS['anthropic'][0]]);
    }

    /** @return array<string, string> the form's starting values from what the site already knows */
    private function values(): array
    {
        $s = $this->app->settings();

        return ['name' => $s->get('company_name') ?: $s->get('site_name'), 'company_email' => $s->get('company_email'), 'company_phone' => $s->get('company_phone'),
            'company_street' => $s->get('company_street'), 'company_postcode' => $s->get('company_postcode'), 'company_city' => $s->get('company_city'), 'language' => Language::defaults($s)];
    }

    /** @return array<string, string> code => name of the languages the texts can be written in */
    private function languages(): array
    {
        $s = $this->app->settings();

        return array_combine([Language::defaults($s), ...Language::additional($s)], array_map(fn (string $c): string => Language::AVAILABLE[$c][0], [Language::defaults($s), ...Language::additional($s)]));
    }
}
