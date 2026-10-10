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
 * MCP tools: enquiries and pop-ups (one method per tool, see Mcp\Catalog). Part of Mcp\Tools.
 *
 * @phpstan-ignore trait.unused
 */
trait EnquiryAndPopupTools
{
    /** list_enquiries */
    private function toolListEnquiries(string $name, array $a): mixed
    {
        $auth = $this->app->auth();
        $db = $this->app->db();
        $siteSettings = $this->app->settings();

        if (!\Talea\Core\Extensions::isEnabled($siteSettings, 'enquiries') || !$auth->hasModule('enquiries')) {
            throw new \DomainException('Only users with access to Enquiries can read them (the Forms and enquiries extension must be on).');
        }
        $whereParts = [];
        $params = [];
        $statuses = ['new' => 0, 'read' => 1, 'resolved' => 2];
        if (isset($statuses[$a['status'] ?? ''])) {
            $whereParts[] = 'status = ?';
            $params[] = $statuses[$a['status']];
        }
        if (($a['search'] ?? '') !== '') {
            $whereParts[] = '(email LIKE ? OR data LIKE ?)';
            $search = '%' . addcslashes((string) $a['search'], '%_\\') . '%';
            array_push($params, $search, $search);
        }
        // the kind from triage (2.12): unsorted = not sorted yet; without a kind spam is left out
        $kind = (string) ($a['category'] ?? '');
        if ($kind === 'unsorted') {
            $whereParts[] = "category = ''";
        } elseif (isset(\Talea\Core\Triage::CATEGORIES[$kind])) {
            $whereParts[] = 'category = ?';
            $params[] = $kind;
        } else {
            $whereParts[] = "category <> 'spam'";
        }
        $limit = max(1, min(50, (int) ($a['limit'] ?? 20)));
        $statusNames = array_flip($statuses);

        // about (2.12): what the form was about – the collection item, page or pop-up it was on (Front\EnquiryTopic)
        return array_map(fn (array $p): array => ['id' => (int) $p['enquiry_id'], 'date' => substr((string) $p['created_at'], 0, 16), 'form' => $p['form'], 'page' => $p['page'], 'about' => $p['topic'] !== '' ? $p['topic'] : null,
            'campaign' => \Talea\Front\Forms::campaignText((string) $p['campaign']), 'first_page' => $p['landing_page'] !== '' ? $p['landing_page'] : null, 'came_from' => $p['referrer'] !== '' ? $p['referrer'] : null, 'email' => $p['email'], 'status' => $statusNames[(int) $p['status']] ?? '',
            'fields' => array_map(fn (array $d): array => ['label' => $d[0], 'value' => $d[1]], json_decode((string) $p['data'], true) ?: [])]
            + ($p['category'] !== '' ? ['category' => $p['category'], 'priority' => \Talea\Core\Triage::PRIORITIES[(int) $p['priority']] ?? null,
                'draft_reply' => $p['suggested_reply'] ?: null, 'triaged_by' => in_array($p['triaged_by'], ['claude', 'assistant', 'rule'], true) ? $p['triaged_by'] : 'person'] : []),
            $db->all('SELECT enquiry_id, created_at, form, page, topic, landing_page, referrer, campaign, email, status, category, priority, suggested_reply, triaged_by, data FROM {enquiries} WHERE ' . implode(' AND ', $whereParts) . ' ORDER BY enquiry_id DESC LIMIT ' . $limit, $params));
    }

    /** update_enquiry and delete_enquiry */
    private function toolUpdateEnquiry(string $name, array $a): mixed
    {
        $auth = $this->app->auth();
        $db = $this->app->db();
        $id = (int) ($a['id'] ?? 0);
        $need = function (bool $allowed, string $message): void {
            if (!$allowed) {
                throw new \DomainException($message);
            }
        };

        $need($auth->hasModule('enquiries'), 'Enquiries can be changed only by users with the Enquiries section.');
        $enquiry = $db->one('SELECT enquiry_id, data FROM {enquiries} WHERE enquiry_id = ?', [$id]) ?? throw new \InvalidArgumentException('The enquiry does not exist. Use list_enquiries.');
        if ($name === 'delete_enquiry') {
            \Talea\Admin\Modules\Enquiries::deleteAttachments([$enquiry]);
            $db->delete('enquiries', ['enquiry_id' => $id]);

            return ['deleted' => $id];
        }
        // a drafts-only connection (3.2) only suggests the triage – the status and the internal note are a person's
        if ($auth->draftsOnly() && (isset($a['status']) || isset($a['note']))) {
            throw new \DomainException('This connection can only save drafts: for an enquiry that is its triage – category, priority and draft_reply – as a suggestion a person checks. '
                . 'The status and the internal note are set by a person (or a connection with full access); put what you would change into the note of the request or the summary of the run.');
        }
        $changes = [];
        if (isset($a['status'])) {
            $status = ['new' => 0, 'read' => 1, 'resolved' => 2][(string) $a['status']] ?? throw new \InvalidArgumentException('status must be new, read or resolved.');
            $changes['status'] = $status;
        }
        if (isset($a['note'])) {
            $changes['note'] = mb_substr(trim((string) $a['note']), 0, 5000);
        }
        if ($changes !== []) {
            $db->update('enquiries', $changes, ['enquiry_id' => $id]);
        }
        if (isset($a['category']) || isset($a['priority']) || isset($a['draft_reply'])) {
            $triage = \Talea\Core\Triage::clean($a['category'] ?? null, isset($a['priority']) ? ['high' => 3, 'normal' => 2, 'low' => 1][(string) $a['priority']] ?? 0 : null, $a['draft_reply'] ?? null);
            if (isset($a['category']) && $triage['category'] === null) {
                throw new \InvalidArgumentException('category must be one of: ' . implode(', ', array_keys(\Talea\Core\Triage::CATEGORIES)) . '.');
            }
            if (\Talea\Core\Triage::save($db, $id, $triage, 'claude')) {
                $changes['triage'] = true;
            } else {
                return ['id' => $id, 'changed' => array_keys($changes), 'note' => 'A person sorted this enquiry already – their triage stays.'];
            }
        }

        return ['id' => $id, 'changed' => array_keys($changes)];
    }

    /** request_testimonial (2.12) */
    private function toolRequestTestimonial(string $name, array $a): mixed
    {
        if (!$this->app->auth()->hasModule('enquiries')) {
            throw new \DomainException('Enquiries can be changed only by users with the Enquiries section.');
        }
        $result = \Talea\Core\Testimonials::request($this->app, (int) ($a['id'] ?? 0), !empty($a['send']));

        return $result + ['next' => $result['sent'] ? 'The customer got the link by e-mail. Their answer will be a hidden draft in References – publish it when the user approves.'
            : 'Pass the link to the customer (it works once, for 30 days). Their answer will be a hidden draft in References.'];
    }

    /** find_personal_data (2.14): counts and enquiry IDs for one address, never the content */
    private function toolFindPersonalData(string $name, array $a): mixed
    {
        $email = $this->personalDataEmail($a);
        $found = \Talea\Core\PersonalData::find($this->app->db(), $email);

        return ['email' => $email, 'found' => \Talea\Core\PersonalData::counts($found), 'enquiry_ids' => array_map('intval', array_column($found['enquiries'], 'enquiry_id')),
            'next' => 'Tell the user what was found. The file for the person: Enquiries → Personal data request in the administration. Erase only when the user asks: erase_personal_data.'];
    }

    /** erase_personal_data (2.14) */
    private function toolErasePersonalData(string $name, array $a): mixed
    {
        $email = $this->personalDataEmail($a);
        if (($a['confirm'] ?? false) !== true) {
            throw new \InvalidArgumentException('Erasing needs confirm=true – only when the user asked for it.');
        }
        $result = \Talea\Core\PersonalData::erase($this->app, $email);

        return $result + ['note' => $result['kept_testimonials'] !== [] ? 'Published testimonials of the person stay (collection items ' . implode(', ', $result['kept_testimonials']) . ') – ask the user whether to remove them too.' : ''];
    }

    private function personalDataEmail(array $a): string
    {
        if (!$this->app->auth()->isAdmin()) {
            throw new \DomainException('Personal data requests are handled only by administrators.');
        }
        $email = \Talea\Core\PersonalData::normalise(is_string($a['email'] ?? null) ? $a['email'] : '');
        if ($email === null) {
            throw new \InvalidArgumentException('email must be a valid e-mail address.');
        }

        return $email;
    }

    /** triage_enquiries (2.12): the unsorted enquiries as text to sort */
    private function toolTriageEnquiries(string $name, array $a): mixed
    {
        if (!\Talea\Core\Extensions::isEnabled($this->app->settings(), 'enquiries') || !$this->app->auth()->hasModule('enquiries')) {
            throw new \DomainException('Enquiries can be read only by users with the Enquiries section.');
        }
        $limit = max(1, min(20, (int) ($a['limit'] ?? 10)));
        $rows = $this->app->db()->all("SELECT * FROM {enquiries} WHERE category = '' ORDER BY enquiry_id DESC LIMIT " . $limit);

        return ['enquiries' => array_map(fn (array $p): array => ['id' => (int) $p['enquiry_id'], 'date' => substr((string) $p['created_at'], 0, 16), 'text' => \Talea\Core\Triage::text($p)], $rows),
            'categories' => array_keys(\Talea\Core\Triage::CATEGORIES), 'priorities' => ['high', 'normal', 'low'],
            'next' => $rows === [] ? 'Every enquiry is sorted.' : 'For each: update_enquiry {id, category, priority, draft_reply}. Spam gets no reply. Never promise prices, dates or facts the site does not state; the user checks and sends every reply.'];
    }

    /** delete_enquiry: the same as update_enquiry */
    private function toolDeleteEnquiry(string $name, array $a): mixed
    {
        return $this->toolUpdateEnquiry($name, $a);
    }

    /** list_popups */
    private function toolListPopups(string $name, array $a): mixed
    {
        $auth = $this->app->auth();
        $db = $this->app->db();
        $adminOnly = function () use ($auth): void {
            if (!$auth->isAdmin()) {
                throw new \DomainException('Only the site administrator can use this tool.');
            }
        };

        $adminOnly();

        return array_map($this->popup(...), \Talea\Builder\Popups::all($db));
    }

    /** save_popup */
    private function toolSavePopup(string $name, array $a): mixed
    {
        $auth = $this->app->auth();
        $adminOnly = function () use ($auth): void {
            if (!$auth->isAdmin()) {
                throw new \DomainException('Only the site administrator can use this tool.');
            }
        };

        $adminOnly();

        return $this->popup($this->savePopup($a), true);
    }

    /** delete_popup */
    private function toolDeletePopup(string $name, array $a): mixed
    {
        $auth = $this->app->auth();
        $db = $this->app->db();
        $id = (int) ($a['id'] ?? 0);
        $need = function (bool $allowed, string $message): void {
            if (!$allowed) {
                throw new \DomainException($message);
            }
        };

        $need($auth->isAdmin(), 'Pop-ups can be deleted only by an administrator.');
        $need($db->delete('popups', ['popup_id' => $id]) > 0, 'The pop-up does not exist. Use list_popups.');
        \Talea\Front\Cache::clear();

        return ['deleted' => $id];
    }
}
