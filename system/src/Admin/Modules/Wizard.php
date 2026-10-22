<?php

declare(strict_types=1);

namespace Talea\Admin\Modules;

use Talea\Admin\Module;
use Talea\Builder\Looks;
use Talea\Core\Assistant;
use Talea\Core\Blueprint;
use Talea\Core\Guardrails;
use Talea\Core\Language;
use Talea\Core\Onboarding;
use Talea\Core\Response;
use Talea\Core\SiteWizard;

/**
 * Setting up a new site. Three questions (#46, Core\Onboarding) – what the site is about, what it should be able to do, how to start –
 * lead either to the looks gallery (re-ranked by the topic, a look tried on with its palettes and font pairs, then used as a draft
 * with the extensions and starter pages of the answers) or to the AI wizard (#31, Core\SiteWizard): questions about the business, a
 * plan to review (blueprint, look, pages), then the site as drafts. The owner's own AI key is used and only on the click of "Plan the
 * site"; without a key the blueprint and the look are still applied. Under the same guardrails as Claude over MCP; the whole run is
 * one session that Change log can undo. The steps are plain GET forms that carry the answers (topic, features).
 */
final class Wizard extends Module
{
    public const string IDENT = 'wizard';
    public const string NAME = 'Site wizard';
    public const string GROUP = 'Settings';
    public const string ICON = 'templates';
    public const bool ADMIN_ONLY = true;
    public const string PARENT = 'business';
    public const string HUB = 'business';

    /** Step 1: what the site is about. */
    protected function actionList(): Response
    {
        return $this->view('steps', 'Set up your site', ['step' => 1, 'answers' => $this->answers()]);
    }

    /** Step 2: what the site should be able to do. */
    protected function actionFeatures(): Response
    {
        return $this->view('steps', 'Set up your site', ['step' => 2, 'answers' => $this->answers()]);
    }

    /** Step 3: a look from the gallery, or the AI wizard (only with a key). */
    protected function actionStart(): Response
    {
        return $this->view('steps', 'Set up your site', ['step' => 3, 'answers' => $this->answers(), 'ready' => (new Assistant($this->app->settings()))->isReady()]);
    }

    /** The looks gallery, the best fits of the topic first (all looks are shown). */
    protected function actionLooks(): Response
    {
        $answers = $this->answers();

        return $this->view('looks', 'Choose a look', ['answers' => $answers, 'looks' => Looks::ranked($answers['topic']), 'library' => \Talea\Builder\DesignSystem::libraryFonts()]);
    }

    /** One look on the real site in a frame: palettes and font pairs are tried on (Front\Kernel ?look=), nothing is stored. */
    protected function actionLook(): Response
    {
        $look = Looks::get($this->request->get('key'));
        if ($look === null) {
            return $this->back('The look does not exist.', 'looks', $this->answers(), 'error');
        }
        $palette = isset($look['palettes'][$this->request->getInt('palette')]) ? $this->request->getInt('palette') : 0;
        $fonts = isset($look['fonts'][$this->request->getInt('fonts')]) ? $this->request->getInt('fonts') : 0;

        return $this->view('look', $look['name'], ['answers' => $this->answers(), 'look' => $look, 'palette' => $palette, 'fonts' => $fonts,
            'library' => \Talea\Builder\DesignSystem::libraryFonts(), 'preview' => $this->app->url('') . '?' . http_build_query(['look' => $look['key'], 'palette' => $palette, 'fonts' => $fonts])]);
    }

    /** Uses the look: draft look, extensions of the answers, starter pages as hidden drafts. */
    protected function actionUse(): Response
    {
        if (!$this->request->isPost()) {
            return $this->back();
        }
        $answers = $this->answers();
        $result = Onboarding::apply($this->app, $this->request->post('key'), $this->request->postInt('palette'), $this->request->postInt('fonts'), $answers['features']);
        if ($result === null) {
            return $this->back('The look does not exist.', 'looks', $this->answers(), 'error');
        }

        return $this->view('ready', 'Your site as drafts', ['result' => $result, 'preview' => Appearance::sitePreviewUrl($this->app, 24 * 60),
            'extensions' => \Talea\Core\Extensions::CATALOG]);
    }

    /** The AI wizard's questions; the topic and the features of the first steps fill what they can. */
    protected function actionAi(): Response
    {
        $s = $this->app->settings();
        $assistant = new Assistant($s);
        $answers = $this->answers();
        $values = $this->values() + ['type' => isset(Looks::TOPICS[$answers['topic']]) && $answers['topic'] !== 'other' ? t(Looks::TOPICS[$answers['topic']]) : $answers['topic'],
            'services' => implode(', ', array_map(fn (string $f): string => t(Onboarding::FEATURES[$f][0]), $answers['features']))];

        return $this->view('ai', 'Set up the site with the assistant', ['ready' => $assistant->isReady(), 'provider' => Assistant::PROVIDERS[$s->get('ai_provider')][0] ?? Assistant::PROVIDERS['anthropic'][0],
            'blueprints' => Blueprint::available(), 'languages' => $this->languages(), 'values' => $values, 'answers' => $answers]);
    }

    /**
     * The answers of the first steps, from the URL or the form: a topic key (or the owner's own words, kept for the AI wizard) and
     * the features.
     *
     * @return array{topic: string, features: list<string>}
     */
    private function answers(): array
    {
        $from = $this->request->isPost() ? $_POST : $_GET;
        $topic = mb_substr(trim((string) preg_replace('/\s+/u', ' ', strip_tags(is_string($from['topic'] ?? null) ? $from['topic'] : ''))), 0, 100);

        return ['topic' => Looks::topic($topic) ?: $topic, 'features' => Onboarding::features($from['features'] ?? [])];
    }

    /** Hidden fields that carry the answers of the first steps to the next form. */
    public function carry(array $answers): string
    {
        $html = '<input type="hidden" name="topic" value="' . e($answers['topic']) . '">';
        foreach ($answers['features'] as $feature) {
            $html .= '<input type="hidden" name="features[]" value="' . e($feature) . '">';
        }

        return $html;
    }

    /** The answers go to the model only now, on the click; the result is a plan the owner reviews and changes before anything is made. */
    protected function actionPlan(): Response
    {
        if (!$this->request->isPost()) {
            return $this->back();
        }
        $answers = SiteWizard::answers($this->request, $this->app->settings());
        if ($answers['name'] === '') {
            return $this->back('Enter the name of your business.', 'ai', $this->answers(), 'error');
        }
        if (($refusal = Guardrails::assistantRefusal($this->app)) !== null) {
            return $this->back($refusal, 'ai', $this->answers(), 'error');
        }
        set_time_limit(180);
        try {
            $plan = SiteWizard::plan($this->app, $answers);
        } catch (\RuntimeException $e) {
            return $this->back(t($e->getMessage()), 'ai', $this->answers(), 'error');
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
            return $this->back('Enter the name of your business.', 'ai', $this->answers(), 'error');
        }
        if (($refusal = Guardrails::assistantRefusal($this->app)) !== null) {
            return $this->back($refusal, 'ai', $this->answers(), 'error');
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
            return $this->back($error . ' ' . t('What was made until then can be undone in the change log (Claude sessions).'), 'ai', $this->answers(), 'error');
        }
        $features = Onboarding::features($_POST['features'] ?? []);
        if ($features !== []) {
            \Talea\Core\Extensions::save($this->app->settings(), array_values(array_unique([...\Talea\Core\Extensions::enabled($this->app->settings()),
                ...array_filter(array_map(fn (string $f): string => Onboarding::FEATURES[$f][1], $features))])));
        }

        return $this->view('done', 'Your site as drafts', ['result' => $result, 'undo' => $this->app->url('admin.php?module=changelog&action=sessions')]);
    }

    /** @param array<string, string> $answers @param array<string, mixed> $plan */
    private function review(array $answers, array $plan): Response
    {
        $blueprints = Blueprint::available();

        return $this->view('review', 'Review the plan', ['answers' => $answers, 'plan' => $plan, 'blueprints' => $blueprints, 'looks' => Looks::all(), 'onboarding' => $this->answers(),
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
