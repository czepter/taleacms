<?php

declare(strict_types=1);

namespace Talea\Mcp\Handlers;

use Talea\Admin\Modules\Media;
use Talea\Admin\Modules\Categories;
use Talea\Admin\Modules\Pages;
use Talea\Core\App;
use Talea\Core\Language;
use Talea\Front\SiteIdentity;
use Talea\Builder\SiteParts;
use Talea\Builder\DesignSystem;
use Talea\Builder\Library;
use Talea\Builder\Collections;
use Talea\Builder\Publisher;
use Talea\Builder\Build;
use Talea\Builder\HtmlConverter;

/**
 * MCP tools: newsletter (one method per tool, see Mcp\Catalog). Part of Mcp\Tools.
 *
 * @phpstan-ignore trait.unused
 */
trait NewsletterTools
{
    /** list_newsletters */
    private function toolListNewsletters(string $name, array $a): mixed
    {
        $auth = $this->app->auth();
        $db = $this->app->db();
        if (!\Talea\Core\Extensions::isEnabled($this->app->settings(), 'newsletter_signup') || !$auth->hasModule('newsletters')) {
            throw new \DomainException('Newsletters need the Newsletter extension and a user with access to the Newsletters section.');
        }
        $mailing = \Talea\Core\Mailing::class;

        return ['newsletters' => array_map(fn (array $n): array => $this->newsletter($n), $mailing::all($db)),
            'confirmed_subscribers' => $mailing::confirmedCount($db), 'sending_problem' => $mailing::problem($this->app)];
    }

    /** draft_newsletter */
    private function toolDraftNewsletter(string $name, array $a): mixed
    {
        $auth = $this->app->auth();
        $db = $this->app->db();
        if (!\Talea\Core\Extensions::isEnabled($this->app->settings(), 'newsletter_signup') || !$auth->hasModule('newsletters')) {
            throw new \DomainException('Newsletters need the Newsletter extension and a user with access to the Newsletters section.');
        }
        $mailing = \Talea\Core\Mailing::class;
        $byId = fn (): array => $mailing::byId($db, (int) ($a['id'] ?? 0)) ?? throw new \InvalidArgumentException('The newsletter does not exist. Use list_newsletters.');

        $current = isset($a['id']) && (int) $a['id'] > 0 ? $byId() : null;
        $id = $mailing::save($this->app, array_intersect_key($a, array_flip(['subject', 'preheader', 'intro', 'news_mode', 'news_count', 'news_ids', 'button_label', 'button_url', 'language'])), (int) ($current['id'] ?? 0));
        $n = (array) $mailing::byId($db, $id);

        return $this->newsletter($n) + ['text' => str_replace($mailing::UNSUBSCRIBE, '(unsubscribe link)', $mailing::render($this->app, $n)[1]),
            'sending_problem' => $mailing::problem($this->app)];
    }

    /** send_test_newsletter */
    private function toolSendTestNewsletter(string $name, array $a): mixed
    {
        $auth = $this->app->auth();
        $db = $this->app->db();
        if (!\Talea\Core\Extensions::isEnabled($this->app->settings(), 'newsletter_signup') || !$auth->hasModule('newsletters')) {
            throw new \DomainException('Newsletters need the Newsletter extension and a user with access to the Newsletters section.');
        }
        $mailing = \Talea\Core\Mailing::class;
        $byId = fn (): array => $mailing::byId($db, (int) ($a['id'] ?? 0)) ?? throw new \InvalidArgumentException('The newsletter does not exist. Use list_newsletters.');

        $n = $byId();
        $email = (string) ($auth->user()['email'] ?? '');
        if ($email === '') {
            throw new \DomainException('The connected user has no e-mail address – add one under My account in the admin.');
        }
        if (!$mailing::sendTest($this->app, $n, $email)) {
            throw new \DomainException('The test e-mail could not be sent: ' . \Talea\Core\Mail::$error);
        }

        return ['sent_to' => $email];
    }

    /** send_newsletter */
    private function toolSendNewsletter(string $name, array $a): mixed
    {
        $auth = $this->app->auth();
        $db = $this->app->db();
        if (!\Talea\Core\Extensions::isEnabled($this->app->settings(), 'newsletter_signup') || !$auth->hasModule('newsletters')) {
            throw new \DomainException('Newsletters need the Newsletter extension and a user with access to the Newsletters section.');
        }
        $mailing = \Talea\Core\Mailing::class;
        $byId = fn (): array => $mailing::byId($db, (int) ($a['id'] ?? 0)) ?? throw new \InvalidArgumentException('The newsletter does not exist. Use list_newsletters.');

        $n = $byId();
        if (!$auth->canPublish()) {
            throw new \DomainException('Sending to subscribers needs the publishing permission.');
        }
        if (!empty($a['unschedule'])) {
            $mailing::unschedule($this->app, (int) $n['id']);
        } else {
            $mailing::send($this->app, (int) $n['id'], isset($a['at']) ? (string) $a['at'] : null);
        }

        return $this->newsletter((array) $mailing::byId($db, (int) $n['id']));
    }

    /** delete_newsletter */
    private function toolDeleteNewsletter(string $name, array $a): mixed
    {
        $auth = $this->app->auth();
        $db = $this->app->db();
        if (!\Talea\Core\Extensions::isEnabled($this->app->settings(), 'newsletter_signup') || !$auth->hasModule('newsletters')) {
            throw new \DomainException('Newsletters need the Newsletter extension and a user with access to the Newsletters section.');
        }
        $mailing = \Talea\Core\Mailing::class;
        $byId = fn (): array => $mailing::byId($db, (int) ($a['id'] ?? 0)) ?? throw new \InvalidArgumentException('The newsletter does not exist. Use list_newsletters.');

        $n = $byId();
        $mailing::delete($this->app, (int) $n['id']);

        return ['deleted' => (int) $n['id']];
    }
}
