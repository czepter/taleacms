<?php

declare(strict_types=1);

namespace Talea\Core;

use Talea\Admin\ChangeLog;
use Talea\Builder\Build;
use Talea\Builder\HtmlConverter;
use Talea\Builder\Looks;

/**
 * The first-run wizard (#31): a few answers about the business become a first site – a blueprint (collections, facts), a look
 * (as a draft look) and pages with generated text, all as drafts: the pages are hidden with a build draft, the look waits in the
 * look draft, nothing is published. Uses the owner's own AI key (Core\Assistant); without a key the blueprint and the look are
 * applied and no text is written.
 *
 * What the model sends back is untrusted: the look and the blueprint must be keys the site ships, a page's HTML goes through
 * HtmlConverter and Build::sanitize like any build, and the whole run is one journal session (Core\AgentJournal) that can be
 * undone as a unit. Facts the site does not know are never invented: the pages carry [placeholders] and unknown {{fact.key}} tokens
 * and the result lists them for the owner to confirm.
 */
final class SiteWizard
{
    public const int MAX_PAGES = 6;

    /** Tones the owner can choose (key => the phrase for the model; the label is translated in the view). */
    public const array TONES = ['friendly' => 'friendly and personal', 'professional' => 'professional and factual', 'premium' => 'refined and premium', 'playful' => 'relaxed and playful'];

    /** Company details the wizard fills from the owner's answers (only where an answer was given). */
    private const array COMPANY_FIELDS = ['company_email', 'company_phone', 'company_street', 'company_postcode', 'company_city'];

    /**
     * The answers of the form, cleaned: single-line plain text with limits.
     *
     * @return array{name: string, blueprint: string, type: string, services: string, tone: string, language: string, company_email: string, company_phone: string, company_street: string, company_postcode: string, company_city: string}
     */
    public static function answers(Request $request, Settings $settings): array
    {
        $line = fn (string $key, int $max): string => mb_substr(trim((string) preg_replace('/\s+/u', ' ', strip_tags($request->post($key)))), 0, $max);
        $blueprint = $request->post('blueprint');
        $manifest = Blueprint::available()[$blueprint] ?? null;
        $languages = [Language::defaults($settings), ...Language::additional($settings)];
        $email = $line('company_email', 150);

        return [
            'name' => $line('name', 100),
            'blueprint' => $manifest !== null ? $blueprint : '',
            'type' => $manifest !== null ? Blueprint::text($manifest['name']) : $line('type', 100),
            'services' => $line('services', 600),
            'tone' => self::TONES[$request->post('tone')] ?? self::TONES['friendly'],
            'language' => in_array($request->post('language'), $languages, true) ? $request->post('language') : $languages[0],
            'company_email' => filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : '',
            'company_phone' => $line('company_phone', 50),
            'company_street' => $line('company_street', 150),
            'company_postcode' => $line('company_postcode', 20),
            'company_city' => $line('company_city', 100),
        ];
    }

    /**
     * The plan the owner reviews: the blueprint and the look (the model's choice when it is ready and the keys are ones we ship,
     * else the owner's choice or the first look) and the pages (the model's, none without a key).
     *
     * @param array<string, string> $answers
     * @return array{blueprint: string, look: string, pages: list<array{title: string, brief: string}>, generated: bool}
     * @throws \RuntimeException with a message for the user
     */
    public static function plan(App $app, array $answers): array
    {
        $assistant = new Assistant($app->settings());
        $blueprints = array_map(fn (array $m): string => Blueprint::text($m['name']), Blueprint::available());
        $looks = Looks::all();
        $plan = ['blueprint' => $answers['blueprint'], 'look' => array_key_first($looks) ?? '', 'pages' => [], 'generated' => false];
        if (!$assistant->isReady()) {
            return $plan;
        }
        $reply = $assistant->planSite($answers, $blueprints, array_map(fn (array $l): string => $l['name'] . ' – ' . $l['description'], $looks));
        if ($plan['blueprint'] === '' && is_string($reply['blueprint'] ?? null) && isset($blueprints[$reply['blueprint']])) {
            $plan['blueprint'] = $reply['blueprint'];
        }
        if (is_string($reply['look'] ?? null) && isset($looks[$reply['look']])) {
            $plan['look'] = $reply['look'];
        }
        $plan['pages'] = self::pages($reply['pages'] ?? []);
        $plan['generated'] = true;

        return $plan;
    }

    /**
     * Pages from a list (the model's reply or the review form): at most MAX_PAGES, a plain title and brief each.
     *
     * @return list<array{title: string, brief: string}>
     */
    public static function pages(mixed $list): array
    {
        $out = [];
        foreach (is_array($list) ? $list : [] as $p) {
            $title = is_array($p) && is_string($p['title'] ?? null) ? mb_substr(trim(strip_tags($p['title'])), 0, 80) : '';
            if ($title !== '' && count($out) < self::MAX_PAGES) {
                $out[] = ['title' => $title, 'brief' => is_string($p['brief'] ?? null) ? mb_substr(trim(strip_tags($p['brief'])), 0, 500) : ''];
            }
        }

        return $out;
    }

    /**
     * Applies the reviewed plan as drafts, in one journal session. Returns what was made and what the owner still has to confirm.
     *
     * @param array<string, string> $answers
     * @param array{blueprint: string, look: string, pages: list<array{title: string, brief: string}>} $plan
     * @return array{session: int, blueprint: string, look: string, pages: list<array{id: string, title: string, url: string, placeholders: int, unknown_facts: list<string>}>, text: bool, saved: list<string>}
     * @throws \RuntimeException with a message for the user (the pages made until then stay, and the session can be undone)
     */
    public static function apply(App $app, array $answers, array $plan): array
    {
        $db = $app->db();
        $settings = $app->settings();
        $assistant = new Assistant($settings);
        $text = $assistant->isReady() && $plan['pages'] !== [];
        $manifest = Blueprint::available()[$plan['blueprint']] ?? null;
        $look = Looks::get($plan['look']);
        $user = $app->auth()->user();
        $db->journal = AgentJournal::start($db, mb_substr('Assistant: ' . ((string) ($user['name'] ?? '') ?: (string) ($user['username'] ?? '')) . ' – setup wizard', 0, 100), 'wizard', true);
        $result = ['session' => (int) ($db->journal?->session ?? 0), 'blueprint' => '', 'look' => '', 'pages' => [], 'text' => $text, 'saved' => []];
        try {
            // what the owner typed is theirs: it fills the company details (and so the facts), empty answers change nothing
            foreach (self::COMPANY_FIELDS as $key) {
                if ($answers[$key] !== '' && $settings->get($key) !== $answers[$key]) {
                    $settings->set($key, $answers[$key]);
                    $result['saved'][] = $key;
                }
            }
            if ($answers['name'] !== '' && $settings->get('company_name') === '') {
                $settings->set('company_name', $answers['name']);
                $result['saved'][] = 'company_name';
            }
            if ($manifest !== null) {
                Blueprint::apply($app, $manifest);
                $result['blueprint'] = Blueprint::text($manifest['name']);
            }
            if ($look !== null) {
                Looks::apply($app, $look['key']);
                $settings->set('appearance_saved', '1');
                $result['look'] = $look['name'];
            }
            if ($text) {
                $known = array_keys(array_filter(Facts::all($app, $answers['language'] === Language::defaults($settings) ? '' : $answers['language']), fn (array $f): bool => $f['value'] !== ''));
                foreach ($plan['pages'] as $page) {
                    $result['pages'][] = self::page($app, $assistant, $answers, $page, $known);
                }
            }
        } finally {
            $db->journal = null;
        }
        ChangeLog::write($app, 'assistant', 'wizard', mb_substr($answers['name'] . ' – ' . $result['blueprint'] . ($result['look'] !== '' ? ', ' . $result['look'] : '') . ', ' . count($result['pages']) . ' draft pages', 0, 250),
            mb_substr($answers['type'] . ': ' . $answers['services'], 0, 255));

        return $result;
    }

    /**
     * One generated page as a hidden draft with a build draft; its [placeholders] and unknown {{fact.key}} tokens are counted for the owner.
     *
     * @param array{title: string, brief: string} $page
     * @param array<string, string> $answers
     * @param list<string> $known keys of facts that have a value
     * @return array{id: string, title: string, url: string, placeholders: int, unknown_facts: list<string>}
     */
    private static function page(App $app, Assistant $assistant, array $answers, array $page, array $known): array
    {
        $db = $app->db();
        $html = Language::runWith($answers['language'], fn (): string => $assistant->sitePage($answers, $page['title'], $page['brief'], $answers['language'], array_slice($known, 0, 20)));
        ['build' => $converted] = HtmlConverter::saveToSite($db, $html, false);
        [$build] = Build::sanitize($converted, false);
        if ($build['children'] === []) {
            throw new \RuntimeException('The assistant did not return a usable section. Try refining the description.');
        }
        $language = Language::column($app->settings(), $answers['language']);
        $slug = slugify($page['title'], 100);
        for ($i = 2; in_array($slug, \Talea\Admin\Modules\Pages::RESERVED_SLUGS, true) || isset(Language::AVAILABLE[$slug]) || Routes::isNewsSlug($slug, $db) || $db->value('SELECT page_id FROM {pages} WHERE slug = ?', [$slug]) !== null; $i++) {
            $slug = slugify($page['title'], 94) . '-' . $i;
        }
        $json = Build::toJson($build);
        $id = $db->insert('pages', ['title' => $page['title'], 'slug' => $slug, 'language' => $language, 'text' => '', 'visible' => 0, 'in_menu' => 1, 'build_draft' => $json, 'updated_at' => date('Y-m-d H:i:s')]);
        Menu::setPage($db, $id, $language, true);
        $facts = Facts::all($app);
        preg_match_all('/\{\{fact\.([a-z][a-z0-9_]{1,39})\}\}/', $json, $tokens);
        $unknown = array_values(array_unique(array_filter($tokens[1], fn (string $k): bool => ($facts[$k]['value'] ?? '') === '')));
        preg_match_all('/\[[^\]\[\n]{2,80}\]/u', strip_tags(Build::asText($build)), $placeholders);

        return ['id' => $db->publicId('pages', $id), 'title' => $page['title'], 'url' => $app->url('admin.php?module=pages&action=builder&id=' . $db->publicId('pages', $id)),
            'placeholders' => count($placeholders[0]), 'unknown_facts' => $unknown];
    }
}
