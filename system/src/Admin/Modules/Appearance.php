<?php

declare(strict_types=1);

namespace Talea\Admin\Modules;

use Talea\Admin\Module;
use Talea\Core\Images;
use Talea\Core\Look;
use Talea\Core\Response;
use Talea\Builder\DesignSystem;

/**
 * Site appearance: logo and design system (colors, fonts, sizes, width, rounding) with a live preview of the home page.
 * Both the layout and the builder take tokens from the design system, so a change here recolors the whole site – that is
 * why the design system goes to the draft look (Core\Look) with the classes and menus: previewed on the whole site,
 * published in one step, and the previous look kept as a version.
 */
final class Appearance extends Module
{
    public const string IDENT = 'appearance';
    public const string NAME = 'Site appearance';
    public const string GROUP = 'Appearance';
    public const string ICON = 'identity';
    public const bool ADMIN_ONLY = true;

    protected function actionList(): Response
    {
        $siteSettings = $this->app->settings();
        $ds = Look::designSystem($siteSettings); // the draft, when there is one – editing continues from it

        return $this->view('list', 'Site appearance', [
            'ds' => $ds, 'versions' => Look::versions($this->db),
            'contrasts' => DesignSystem::contrasts($ds),
            'presets' => array_map(fn (string $k): array => ['name' => DesignSystem::PRESETS[$k][0], 'description' => DesignSystem::PRESETS[$k][1], 'ds' => DesignSystem::preset($k)], array_combine(array_keys(DesignSystem::PRESETS), array_keys(DesignSystem::PRESETS))),
            'values' => ['logo' => $siteSettings->get('logo'), 'favicon' => $siteSettings->get('favicon'), 'dark_mode' => $siteSettings->get('dark_mode'), 'theme_switcher' => $siteSettings->get('theme_switcher'), 'site_name' => $siteSettings->get('site_name')],
        ]);
    }

    protected function actionSave(): Response
    {
        if (!$this->request->isPost()) {
            return $this->back();
        }
        $r = $this->request;
        $siteSettings = $this->app->settings();
        $siteSettings->set('logo', mb_substr($r->post('logo'), 0, 255));
        $icon = mb_substr($r->post('favicon'), 0, 255);
        if ($icon !== $siteSettings->get('favicon') || ($icon !== '' && !is_file(TALEA_ROOT . '/media/icon-180.png'))) {
            // icons for phones and for installing the site are prepared from the icon once, when saving
            $ok = $icon !== '' && preg_match('#^/?(?:[A-Za-z0-9_.-]+/){0,3}(media/[A-Za-z0-9/_.-]+)$#', $icon, $m) && !str_contains($m[1], '..') && Images::icons(TALEA_ROOT . '/' . $m[1]);
            if (!$ok) {
                array_map(fn (int $n): bool => @unlink(TALEA_ROOT . '/media/icon-' . $n . '.png'), Images::ICON_SIZES);
            }
        }
        $siteSettings->set('favicon', $icon);
        $siteSettings->set('dark_mode', in_array($r->post('dark_mode'), ['auto', 'dark'], true) ? $r->post('dark_mode') : 'off');
        $siteSettings->set('theme_switcher', $r->postBool('theme_switcher') ? '1' : '0');
        $inDraft = $this->toDraft($this->parseForm());
        $siteSettings->set('appearance_saved', '1'); // first steps: the appearance was chosen by the administrator, not by the starter site
        \Talea\Front\Cache::clear();

        return $this->back($inDraft ? 'Saved to the draft look – preview the whole site, then publish it.' : 'The site appearance has been saved.');
    }

    /**
     * The design system from the form goes to the draft look – unless it is the same as the published one (a save of the
     * logo alone does not create a draft, and going back to the published values removes the design system from the draft).
     */
    private function toDraft(array $ds): bool
    {
        $s = $this->app->settings();
        if ($ds == DesignSystem::load($s)) {
            $draft = Look::draft($s);
            unset($draft['design_system']);
            $s->set('look_draft', $draft === [] ? '' : (string) json_encode($draft, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return false;
        }
        Look::setDesignSystem($s, $ds);

        return true;
    }

    protected function actionPublishLook(): Response
    {
        if (!$this->request->isPost()) {
            return $this->back();
        }
        $summary = Look::publish($this->app);

        return $this->back($summary === [] ? 'There was nothing to publish.' : t('The look is published: %s', implode(' · ', $summary)));
    }

    protected function actionDiscardLook(): Response
    {
        if ($this->request->isPost()) {
            Look::discard($this->app->settings());
            \Talea\Admin\ChangeLog::write($this->app, 'appearance', 'discard look draft');
        }

        return $this->back('The unpublished look changes were discarded.');
    }

    /** A published look from the history goes back into the draft – check it in the preview, then publish. */
    protected function actionRestoreLook(): Response
    {
        if (!$this->request->isPost()) {
            return $this->back();
        }
        try {
            Look::restoreVersion($this->app, $this->request->postInt('id'));
        } catch (\InvalidArgumentException $e) {
            return $this->back(t($e->getMessage()), '', [], 'error');
        }

        return $this->back('The earlier look is in the draft – preview the whole site, then publish it.');
    }

    /** The whole site with all drafts and the draft look, through a signed link (also for a colleague, for a day). */
    protected function actionPreviewSite(): Response
    {
        return Response::redirect(self::sitePreviewUrl($this->app, 24 * 60));
    }

    /** Signed link to the whole-site preview (Core\Preview target "web"). */
    public static function sitePreviewUrl(\Talea\Core\App $app, int $minutes): string
    {
        return $app->request->origin() . $app->url('') . '?preview_key=' . \Talea\Core\Preview::key($app->db(), $app->settings(), 'web', $minutes);
    }

    /** Design tokens for download in the DTCG format (Figma, Tokens Studio, Style Dictionary). */
    protected function actionTokens(): Response
    {
        $json = (string) json_encode(DesignSystem::toDtcg(DesignSystem::load($this->app->settings())), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return new Response($json, 200, ['Content-Type' => 'application/json; charset=utf-8', 'Content-Disposition' => 'attachment; filename="tokens-' . date('Y-m-d') . '.tokens.json"']);
    }

    /** Import of DTCG tokens: the whole appearance from a Talea export, the colors from another tool. */
    protected function actionTokensImport(): Response
    {
        $file = $_FILES['tokens'] ?? null;
        $content = $this->request->isPost() && is_array($file) && ($file['error'] ?? 1) === UPLOAD_ERR_OK && (int) $file['size'] < 1_000_000 ? (string) file_get_contents((string) $file['tmp_name']) : '';
        $tokens = json_decode($content, true);
        $siteSettings = $this->app->settings();
        $ds = is_array($tokens) ? DesignSystem::fromDtcg($tokens, Look::designSystem($siteSettings)) : null;
        if ($ds === null) {
            return $this->back('The file contains no usable design tokens (a JSON file in the DTCG format is expected).', '', [], 'error');
        }
        $this->toDraft($ds);
        $siteSettings->set('appearance_saved', '1');

        return $this->back('Design tokens have been loaded into the draft look – preview the whole site, then publish it.');
    }

    /** Live preview: token CSS and a readability check for the unsaved form (JSON). Saves nothing. */
    protected function actionPreview(): Response
    {
        $ds = $this->parseForm();

        return Response::json(['css' => DesignSystem::css($ds, $this->app->request->basePath()), 'contrasts' => array_map(fn (array $k): array => ['description' => t($k['description'])] + $k, DesignSystem::contrasts($ds))]);
    }

    /** @return array<string, mixed> */
    private function parseForm(): array
    {
        $ds = is_array($_POST['ds'] ?? null) ? $_POST['ds'] : [];
        // sizes are entered in pixels in the form, the design system keeps them in rem
        foreach (['base_min', 'base_max', 'width', 'text_width'] as $key) {
            if (isset($ds[$key]) && is_numeric($ds[$key])) {
                $ds[$key] = (float) $ds[$key] / 16;
            }
        }

        return DesignSystem::sanitize($ds);
    }
}
