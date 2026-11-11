<?php

declare(strict_types=1);

namespace Talea\Front;

use Talea\Core\App;
use Talea\Core\Language;
use Talea\Core\Response;

/** The page a personal form link opens (/_f/<token>, Core\FormLinks): the form or its link page, filled in; a closed link says so kindly. */
final class FormLinkPages
{
    public function __construct(private readonly App $app, private readonly PageFrame $frame, private readonly Kernel $kernel)
    {
    }

    /**
     * The form a personal link opens (Core\FormLinks): the published form inside the wrapper "form_link" – or the page the form names
     * as its link page (link_page, hidden or not), with the person's name for {name} – filled in with what the link suggests or
     * what the person saved for later; never cached, never indexed. A link that was used or ran out says so kindly.
     */
    public function linkForm(string $token): Response
    {
        $db = $this->app->db();
        $r = $this->app->request;
        $link = \Talea\Core\FormLinks::find($db, $token) ?? ($r->get('result') === 'ok' ? \Talea\Core\FormLinks::find($db, $token, 0, true) : null);
        if ($link === null) {
            $known = \Talea\Core\FormLinks::lookup($db, $token);

            return $known === null ? $this->kernel->notFound() : $this->linkClosed($known);
        }
        $form = \Talea\Builder\FormRecords::byId($db, (int) $link['form_id']);
        if ($form === null || (string) $form['build'] === '') {
            return $this->kernel->notFound();
        }
        $content = \Talea\Core\FormLinks::content($form);
        $k = $this->frame->context();
        $k->source = 'form:' . $form['public_id'];
        $k->linkToken = $token;
        $k->withoutCache = true;
        if ($link['used_at'] === null) {
            // the latest draft (our suggestion or what the person saved); with drafts on, an earlier one by ?version=n
            $drafts = \Talea\Core\FormLinks::drafts($link);
            $version = !empty($content['link_save']) && $r->has('version') ? $r->getInt('version', -1) : -1;
            $version = isset($drafts[$version]) ? $version : count($drafts) - 1;
            if ($version >= 0) {
                [$k->prefill, $k->prefillBy] = [(array) $drafts[$version]['values'], (string) ($drafts[$version]['by'] ?? 'site')];
            }
            $k->linkDrafts = array_map(static fn (array $d): array => ['at' => (string) $d['at'], 'by' => (string) ($d['by'] ?? 'site')], $drafts);
            $k->linkVersion = $version;
        }
        $page = $this->linkPage((string) ($content['link_page'] ?? ''));
        if ($page !== null) {
            // the page designed for the form: its placement of this form takes the link (Elements\FormEmbed); other forms on it stay as they are
            $k->linkForm = (int) $form['form_id'];
            $label = (string) $link['label'];
            $html = str_replace(['{name}', '{first name}'], [e($label), e((string) strtok($label, ' '))], \Talea\Builder\Renderer::html(\Talea\Builder\Build::fromJson($page['build']), $k));

            return $this->frame->page((string) $page['title'], $this->frame->view->render('page', ['page' => ['title' => ''], 'intro' => false, 'build' => $html]), ['build' => true, 'noindex' => true]);
        }
        $html = \Talea\Builder\Renderer::html(\Talea\Builder\FormRecords::definitionBuild(\Talea\Builder\Build::fromJson($form['build'])), $k);

        return $this->frame->page((string) $form['name'], $this->frame->view->render('page', ['page' => ['title' => ''], 'intro' => false, 'build' => $html]), ['build' => true, 'noindex' => true, 'part' => 'form_link']);
    }

    /** The page a form's links open (its address, in the language of the site the visitor is on first), published with a build; null for none. @return array<string, mixed>|null */
    private function linkPage(string $address): ?array
    {
        $slug = trim((string) parse_url(trim($address), PHP_URL_PATH), '/');
        if ($slug === '' || preg_match('#^[a-z0-9/_-]{1,190}$#', $slug) !== 1) {
            return null;
        }
        $page = $this->app->db()->one('SELECT * FROM {pages} WHERE slug = ? AND deleted_at IS NULL AND build IS NOT NULL ORDER BY CASE WHEN language = ? THEN 0 ELSE 1 END, page_id LIMIT 1', [$slug, Language::siteColumn()]);

        return $page !== null && (string) $page['build'] !== '' ? $page : null;
    }

    /** A link that was used, ran out or was revoked: a kind word and how to reach the site instead of an error page. @param array<string, mixed> $link */
    private function linkClosed(array $link): Response
    {
        $settings = $this->app->settings();
        $text = \Talea\Core\FormLinks::status($link) === 'used'
            ? t('You already sent this form – thank you! If you want to change something, just get in touch with us.')
            : t('This link no longer works. If you still want to send the form, get in touch with us and we will send you a new link.');
        $email = trim((string) $settings->get('company_email')); // the public contact; the site e-mail is never shown
        $phone = trim((string) $settings->get('company_phone'));
        $contact = ($email !== '' ? '<li><a href="mailto:' . e($email) . '">' . e($email) . '</a></li>' : '')
            . ($phone !== '' ? '<li><a href="tel:' . e((string) preg_replace('/[^+\d]/', '', $phone)) . '">' . e($phone) . '</a></li>' : '');
        $k = $this->frame->context();
        $k->types['form'] = true; // the note's style
        $k->withoutCache = true;
        $html = '<div class="tl-form-done" role="status"><p>' . e($text) . '</p>' . ($contact !== '' ? '<ul>' . $contact . '</ul>' : '') . '</div>';

        return $this->frame->page((string) $settings->get('site_name'), $this->frame->view->render('page', ['page' => ['title' => ''], 'intro' => false, 'build' => $html]), ['build' => true, 'noindex' => true, 'part' => 'form_link'], 410);
    }
}
