<?php

declare(strict_types=1);

namespace Talea\Admin\Modules;

use Talea\Admin\Module;
use Talea\Admin\Modules\Settings as SettingsScreen;
use Talea\Core\Robots;
use Talea\Core\Response;
use Talea\Core\SeoTitle;
use Talea\Core\Settings;

/**
 * Titles and descriptions of the "Search engines" section: the separator, the title and description templates per kind
 * of content (Core\SeoTitle), the site-wide robots directives and the sharing defaults. The keys are the group
 * seo_titles of Modules\Settings::FIELDS; every value goes through Settings::verifyValue like a form or Claude would.
 */
final class SeoTitles extends Module
{
    public const string IDENT = 'seo_titles';
    public const string NAME = 'Titles and descriptions';
    public const string GROUP = 'Search engines';
    public const string ICON = 'search';
    public const bool ADMIN_ONLY = true;

    /**
     * kind => [name, keys the section saves and resets]. share_image is saved with the defaults but never reset:
     * a picture is not a default value.
     *
     * @var array<string, array{0: string, 1: list<string>}>
     */
    public const array KINDS = [
        'defaults' => ['Defaults', ['seo_separator', 'seo_capitalize', 'seo_robots', 'seo_hide_empty_terms', 'seo_card']],
        'home' => ['Home page', ['seo_title_home']],
        'page' => ['Pages', ['seo_title_page', 'seo_description_page']],
        'news' => ['News', ['seo_title_news', 'seo_description_news']],
        'item' => ['Collection items', ['seo_title_item', 'seo_description_item']],
        'category' => ['Categories', ['seo_title_category']],
        'tag' => ['Tags', ['seo_title_tag']],
    ];

    /** The separators offered as radio buttons; an own one (up to three characters) goes in the field next to them. */
    public const array SEPARATORS = ['–', '-', '|', '•', '·', '~', '«', '»', '/'];

    /** The screen lives next to the overview, in views/admin/seo/. */
    protected function view(string $template, string $heading, array $data = []): Response
    {
        return $this->kernel->page($heading, $this->app->view->render('admin/seo/' . $template, $data + ['app' => $this->app, 'module' => $this, 'csrf' => $this->app->session->csrfField()]));
    }

    private function kind(): string
    {
        $kind = $this->request->post('kind') ?: $this->request->get('kind');

        return isset(self::KINDS[$kind]) ? $kind : 'defaults';
    }

    protected function actionList(): Response
    {
        $kind = $this->kind();
        $settings = $this->app->settings();
        $values = [];
        foreach ([...self::KINDS[$kind][1], 'share_image'] as $key) {
            $values[$key] = $settings->get($key);
        }
        [$flags, $limits] = self::robotsParts($values['seo_robots'] ?? '');

        return $this->view('titles', 'Titles and descriptions', [
            'kind' => $kind, 'values' => $values, 'flags' => $flags, 'limits' => $limits, 'sample' => $this->sample($kind),
        ]);
    }

    /** @return array{0: list<string>, 1: array<string, string>} the flags and the limits of a stored robots value */
    private static function robotsParts(string $stored): array
    {
        $flags = [];
        $limits = [];
        foreach (array_filter(explode(',', $stored)) as $token) {
            if (str_contains($token, ':')) {
                [$name, $value] = explode(':', $token, 2);
                $limits[$name] = $value;
            } else {
                $flags[] = $token;
            }
        }

        return [$flags, $limits];
    }

    /** "Reads as": the <title> the saved template makes of the latest row of the kind. */
    private function sample(string $kind): string
    {
        $db = $this->db;
        $title = match ($kind) {
            'news' => $db->value('SELECT title FROM {news} WHERE deleted_at IS NULL ORDER BY news_id DESC LIMIT 1'),
            'item' => $db->value('SELECT name FROM {collection_items} WHERE deleted_at IS NULL ORDER BY item_id DESC LIMIT 1'),
            'category' => $db->value('SELECT name FROM {categories} ORDER BY category_id DESC LIMIT 1'),
            'tag' => $db->value('SELECT name FROM {tags} ORDER BY tag_id DESC LIMIT 1'),
            default => $db->value('SELECT title FROM {pages} WHERE deleted_at IS NULL AND visible = TRUE ORDER BY page_id DESC LIMIT 1'),
        };
        $title = trim((string) $title) !== '' ? (string) $title : t('[Title]');

        return SeoTitle::compose($this->app->settings(), $kind === 'defaults' ? 'page' : $kind, $title, false, ['category' => t('[Category]')]);
    }

    protected function actionSave(): Response
    {
        $kind = $this->kind();
        if (!$this->request->isPost()) {
            return $this->back('', '', ['kind' => $kind]);
        }
        $r = $this->request;
        $clean = [];
        foreach ([...self::KINDS[$kind][1], ...($kind === 'defaults' ? ['share_image'] : [])] as $key) {
            if ($key === 'seo_robots') {
                $tokens = $r->postList('seo_robots');
                foreach (['max-snippet' => 'robots_max_snippet', 'max-video-preview' => 'robots_max_video', 'max-image-preview' => 'robots_image'] as $name => $field) {
                    if ($r->post($field) !== '') {
                        $tokens[] = $name . ':' . $r->post($field);
                    }
                }
                $clean[$key] = Robots::normalize($tokens);
                continue;
            }
            if (!in_array($key, ['seo_capitalize', 'seo_hide_empty_terms'], true) && !$r->hasPost($key) && !($key === 'seo_separator' && $r->post('seo_separator_own') !== '')) {
                continue; // a field the form did not send keeps its value; an unticked box is simply not sent
            }
            $value = $key === 'seo_separator' && $r->post('seo_separator_own') !== '' ? $r->post('seo_separator_own') : $r->post($key);
            $verified = SettingsScreen::verifyValue($key, in_array($key, ['seo_capitalize', 'seo_hide_empty_terms'], true) ? ($r->postBool($key) ? '1' : '0') : $value);
            if ($verified === null) {
                return $this->back('The value of a field is not valid, nothing was saved. A separator has up to three characters.', '', ['kind' => $kind], 'error');
            }
            $clean[$key] = $verified;
        }
        foreach ($clean as $key => $value) {
            $this->app->settings()->set($key, $value);
        }

        return $this->back('Settings saved.', '', ['kind' => $kind]);
    }

    protected function actionReset(): Response
    {
        $kind = $this->kind();
        if (!$this->request->isPost()) {
            return $this->back('', '', ['kind' => $kind]);
        }
        foreach (self::KINDS[$kind][1] as $key) {
            $this->app->settings()->set($key, Settings::DEFAULTS[$key]);
        }

        return $this->back('The section is back to the defaults.', '', ['kind' => $kind]);
    }
}
