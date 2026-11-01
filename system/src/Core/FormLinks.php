<?php

declare(strict_types=1);

namespace Talea\Core;

use Talea\Builder\Build;
use Talea\Builder\FormRecords;

/**
 * Personal links to a form (the form's setting access = link): /_f/<token>, 32 random hex characters, valid for the days the form
 * says and for one submission; only a hash is stored. A link is made for an enquiry (its address is the person's own, so the
 * link can be mailed) or for a name only (it is returned to pass on, never mailed to an address the site did not receive).
 * What the person sent arrives wherever the form sends answers; the link keeps the consent they ticked and when.
 */
final class FormLinks
{
    public const int DAYS = 30;

    /**
     * Creates a link to a form; sends the e-mail when asked. Returns the link (absolute) and whether the e-mail went out.
     *
     * @param array<string, mixed> $form a row of forms
     * @return array{link: string, sent: bool}
     * @throws \DomainException when there is nobody to name the link after, or no e-mail address to send it to
     */
    public static function create(App $app, array $form, int $enquiry, bool $send, string $label = '', string $email = ''): array
    {
        $db = $app->db();
        $content = (array) (FormRecords::definition(Build::fromJson($form['build'] ?? null))['content'] ?? []);
        $row = $enquiry > 0 ? $db->one('SELECT enquiry_id, form, email FROM {enquiries} WHERE enquiry_id = ?', [$enquiry]) : null;
        if ($enquiry > 0 && $row === null) {
            throw new \DomainException('The enquiry does not exist.');
        }
        $email = trim($row !== null ? (string) $row['email'] : $email);
        $label = mb_substr(trim(strip_tags($label)), 0, 190);
        if ($row === null && $label === '') {
            throw new \DomainException('Name the person the link is for (label), or pass an enquiry.');
        }
        if ($send && $row === null) {
            throw new \DomainException('Only a link for an enquiry can be e-mailed – the address must come from the person themself. Return the link and pass it on.');
        }
        if (($send || $email !== '') && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new \DomainException($send ? 'The enquiry has no e-mail address to send the link to.' : 'The e-mail address is not valid.');
        }
        $days = max(1, min(365, (int) ($content['link_days'] ?? self::DAYS)));
        $token = bin2hex(random_bytes(16));
        $db->insert('form_links', ['enquiry_id' => $enquiry > 0 ? $enquiry : null, 'form_id' => (int) $form['form_id'], 'token_hash' => hash('sha256', $token), 'email' => $email, 'label' => $label,
            'created_at' => date('Y-m-d H:i:s'), 'expires_at' => date('Y-m-d H:i:s', time() + $days * 86400)]);
        $link = Mailing::absolute($app, '_f/' . $token);
        $sent = false;
        if ($send) {
            $site = (string) $app->settings()->get('site_name');
            // in the language of the site the person wrote to, not of the admin who clicked
            [$subject, $text] = Language::runWith($app->settings()->get('site_language') ?: 'en', function () use ($content, $form, $link, $days, $site): array {
                $values = ['link' => $link, 'days' => (string) $days, 'site name' => $site];
                $fill = static fn (string $s): string => (string) preg_replace_callback('/\{([a-z ]+)\}/', static fn (array $m): string => $values[$m[1]] ?? $m[0], $s);
                $subject = trim((string) ($content['link_subject'] ?? ''));
                $body = trim((string) ($content['link_text'] ?? ''));

                return [$subject !== '' ? $fill($subject) : t('%s: %s', (string) $form['name'], $site),
                    $body !== '' ? $fill($body) : t("Hello,\n\nplease fill in this short form:\n\n%s\n\nThe link works for %d days.\n\n%s", $link, $days, $site)];
            });
            $sent = Mail::send($app->settings(), $email, $subject, $text);
        }
        \Talea\Admin\ChangeLog::write($app, 'enquiries', 'form_link', mb_substr($row !== null ? (string) $row['form'] : $label, 0, 80) . ($sent ? ' (e-mail)' : ''));

        return ['link' => $link, 'sent' => $sent];
    }

    /** The open link of a token: not used, not expired (and made for this form, when given); null otherwise. With $justUsed: a link used within the last hour. @return array<string, mixed>|null */
    public static function find(Db $db, string $token, int $formId = 0, bool $justUsed = false): ?array
    {
        if (preg_match('/^[a-f0-9]{32}$/', $token) !== 1) {
            return null;
        }
        // $justUsed: the person was just sent back to the page of their link – it shows the thank-you for an hour after the submission
        $link = $justUsed ? $db->one('SELECT * FROM {form_links} WHERE token_hash = ? AND used_at > ?', [hash('sha256', $token), date('Y-m-d H:i:s', time() - 3600)])
            : $db->one('SELECT * FROM {form_links} WHERE token_hash = ? AND used_at IS NULL AND expires_at > ?', [hash('sha256', $token), date('Y-m-d H:i:s')]);

        return $link !== null && ($formId === 0 || (int) $link['form_id'] === $formId) ? $link : null; // $formId 0: the page of a link asks for the link's own form
    }

    /** Takes the link for one submission: false when somebody else took it a moment ago. */
    public static function claim(Db $db, array $link): bool
    {
        return $db->run('UPDATE {form_links} SET used_at = ? WHERE id = ? AND used_at IS NULL', [date('Y-m-d H:i:s'), (int) $link['id']])->rowCount() === 1;
    }

    /** The submission failed: the link can be used again. */
    public static function release(Db $db, array $link): void
    {
        $db->run('UPDATE {form_links} SET used_at = NULL WHERE id = ?', [(int) $link['id']]);
    }

    /** The submission arrived: what the person agreed to, and the item it made. */
    public static function finish(Db $db, array $link, int $itemId, string $consent): void
    {
        $db->update('form_links', ['item_id' => $itemId > 0 ? $itemId : null, 'consent' => $consent], ['id' => (int) $link['id']]);
    }

    /** The links of an enquiry for its detail: when, to whom, whether it came back and the draft it made. @return list<array<string, mixed>> */
    public static function ofEnquiry(Db $db, int $enquiry): array
    {
        return $db->all('SELECT id, form_id, email, label, created_at, expires_at, used_at, item_id FROM {form_links} WHERE enquiry_id = ? ORDER BY id DESC', [$enquiry]);
    }
}
