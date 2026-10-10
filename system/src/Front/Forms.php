<?php

declare(strict_types=1);

namespace Talea\Front;

use Talea\Core\Antispam;
use Talea\Core\App;
use Talea\Core\Mail;
use Talea\Core\Response;
use Talea\Builder\SiteParts;
use Talea\Builder\Components;
use Talea\Builder\Elements\Form;
use Talea\Builder\Build;

/**
 * Submission of a builder form (POST /form). Fields and recipient are taken from the PUBLISHED build by source and
 * element id – the visitor cannot add a field or change the recipient. Result: an enquiry in tl_enquiries, an e-mail
 * notification and a return to the page with a result code (?form=<id>&result=ok|field|limit|too_fast|verification).
 */
final class Forms
{
    /** How many messages one IP address can send in 10 minutes. */
    private const int LIMIT = 5;

    /**
     * Where a lead came from (2.3): the first page of the visit, its campaign and the site that sent the visitor. The cookie
     * bar fills these fields only when the visitor allowed marketing (views/front/cookies.php); otherwise they stay empty.
     */
    public const string ATTRIBUTION_FIELDS = '<input type="hidden" name="tl_landing" value=""><input type="hidden" name="tl_campaign" value=""><input type="hidden" name="tl_referrer" value="">';

    /**
     * The attribution a form sent, checked: [first page (a path on the site), campaign (utm_* query), referring site (host)].
     *
     * @return array{0: string, 1: string, 2: string}
     */
    public static function attribution(\Talea\Core\Request $r): array
    {
        $landing = $r->post('tl_landing');
        $landing = preg_match('#^/[^\s\\\\<>"]{0,254}$#', $landing) && !str_starts_with($landing, '//') ? $landing : '';
        $campaign = self::campaign('https://site.invalid/?' . $r->post('tl_campaign'), 'https://site.invalid');
        $referrer = strtolower($r->post('tl_referrer'));

        return [$landing, $campaign, preg_match('/^[a-z0-9.-]{3,100}$/', $referrer) ? $referrer : ''];
    }

    public function __construct(private readonly App $app)
    {
    }

    public function process(): Response
    {
        $r = $this->app->request;
        if (!$r->isPost()) {
            return new Response('', 405, ['Allow' => 'POST']);
        }
        $posted = $r->post('source'); // as the page printed it (public ids): the antispam signature covers this form
        $source = self::internalSource($this->app->db(), $posted);
        $back = $r->post('back');
        $back = preg_match('#^/[^\s\\\\]*$#', $back) && !str_starts_with($back, '//') ? $back : $this->app->url('');
        $element = $this->element($source, $r->post('element'));
        if ($element === null) {
            return Response::redirect($back, 303);
        }
        $redirectUri = fn (string $result, int $field = -1): Response => Response::redirect($back . '?form=' . rawurlencode($element['id']) . '&result=' . $result . ($field >= 0 ? '&field=' . $field : '') . '#' . Form::anchor($element), 303);

        $antispam = new Antispam($this->app->db(), $this->app->settings());
        $reason = $antispam->reason($r, 'form|' . $posted . '|' . $element['id']);
        if ($reason === 'robot') {
            return $redirectUri('ok'); // the robot does not learn that it failed
        }
        if ($reason !== null) {
            // a too-fast submission (autofill) has its own message: waiting a moment is enough, no need to reload the page
            return $redirectUri($reason === 'too_fast' ? 'too_fast' : 'verification');
        }
        if ($antispam->count($r->ip(), 'form', 0, 10) >= self::LIMIT) {
            return $redirectUri('limit');
        }
        // an event's registration (2.11): the server checks again that it is still open – the page may be older than the last place
        if (preg_match('/^collection:(\d+)$/', $source, $m) && ($state = \Talea\Core\Calendar::stateForSubmission($this->app->db(), (int) $m[1], $back)) !== null && $state !== 'open') {
            return $redirectUri($state === 'full' ? 'full' : 'closed');
        }
        if (empty($element['content']['no_captcha']) && !\Talea\Core\Captcha::accepted($this->app->settings(), \Talea\Core\Captcha::verify($this->app->settings(), $r))) {
            return $redirectUri('captcha');
        }

        $data = [];
        $email = '';
        $attachments = [];
        // multi-step forms and calculators (2.12): a field whose condition is not met is neither checked nor sent, and the
        // estimate is computed here from the answers – never taken from the browser
        $fields = $element['content']['fields'];
        $answers = [];
        foreach ($fields as $i => $f) {
            $answers[$i] = $f['type'] === 'checkboxes' ? array_values(array_intersect(Form::options($f), $r->postList('p' . $i))) : trim($r->post('p' . $i));
        }
        $visible = Form::visible($fields, $answers);
        $types = []; // the type of every $data entry, for the mapping to a CRM or a sheet (2.13, Core\EnquiryDelivery)
        foreach ($element['content']['fields'] as $i => $field) {
            if ($field['type'] === 'step' || !($visible[$i] ?? true)) {
                continue;
            }
            $types[] = $field['type'];
            if ($field['type'] === 'estimate') {
                $data[] = [$field['label'], Form::money(Form::estimate($fields, $answers, $visible, Form::price($field['base_price'] ?? '')), mb_substr(trim((string) ($field['currency'] ?? '')), 0, 10))];
                continue;
            }
            if ($field['type'] === 'file') {
                $file = $_FILES['p' . $i] ?? null;
                $uploaded = is_array($file) && ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK && is_uploaded_file((string) $file['tmp_name']);
                $extension = $uploaded ? strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION)) : '';
                if ($uploaded && (!in_array($extension, Form::ATTACHMENT_EXTENSIONS, true) || (int) $file['size'] > Form::MAX_ATTACHMENT)) {
                    return $redirectUri('field', $i);
                }
                if (!$uploaded && $field['required']) {
                    return $redirectUri('field', $i);
                }
                $data[] = [$field['label'], $uploaded ? mb_substr(basename((string) $file['name']), 0, 120) . ' (' . \Talea\Core\Files::size((int) $file['size']) . ')' : ''];
                if ($uploaded) {
                    $attachments[count($data) - 1] = [(string) $file['tmp_name'], $extension];
                }
                continue;
            }
            if ($field['type'] === 'basket') {
                // the enquiry basket (2.11): every line is rebuilt from the products in the database, nothing the visitor typed
                $lines = \Talea\Builder\Products::basketLines($this->app->db(), mb_substr($r->post('p' . $i), 0, 20000));
                if ($lines === null || ($field['required'] && $lines === [])) {
                    return $redirectUri('field', $i);
                }
                $data[] = [$field['label'], implode("\n", $lines)];
                continue;
            }
            if ($field['type'] === 'hidden') {
                // the form's own value, never the visitor's (2.3) – except when that value is a placeholder ({{nazev}} in a job's
                // item template, 2.11): the item page filled it and sent it back as a hidden input, so it is taken from the request,
                // but only as short plain text (tags and control characters removed) and only in that case
                $own = mb_substr(trim((string) ($field['value'] ?? '')), 0, 300);
                $data[] = [$field['label'], preg_match(\Talea\Builder\Collections::PLACEHOLDER_PATTERN, $own) === 1
                    ? mb_substr(trim(strip_tags((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $r->post('p' . $i)))), 0, 300) : $own];
                continue;
            }
            if ($field['type'] === 'checkboxes') {
                $ticked = array_values(array_intersect(Form::options($field), $r->postList('p' . $i)));
                if ($field['required'] && $ticked === []) {
                    return $redirectUri('field', $i);
                }
                $data[] = [$field['label'], implode(', ', $ticked)];
                continue;
            }
            $value = trim(str_replace("\r\n", "\n", $r->post('p' . $i)));
            $value = match ($field['type']) {
                'textarea' => mb_substr($value, 0, 5000),
                'email' => filter_var($value, FILTER_VALIDATE_EMAIL) !== false ? mb_substr($value, 0, 190) : ($value === '' ? '' : null),
                'tel' => $value === '' || preg_match('/^[+()\d\s\/.-]{6,30}$/', $value) ? $value : null,
                'select', 'radio' => $value === '' || in_array($value, Form::options($field), true) ? $value : null,
                'date' => $value === '' || (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) && checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4))) ? $value : null,
                'number' => $value === '' || preg_match('/^-?\d{1,12}([.,]\d{1,6})?$/', $value) ? $value : null,
                'checkbox' => $value === '1' ? t('yes') : '',
                default => mb_substr(str_replace("\n", ' ', $value), 0, 300),
            };
            if ($value === null || ($field['required'] && $value === '')) {
                return $redirectUri('field', $i);
            }
            if ($field['type'] === 'email' && $email === '') {
                $email = $value;
            }
            $data[] = [$field['label'], $value];
        }
        $antispam->write($r->ip(), 'form', 0);
        // a gated download (2.11, Core\Documents): the enquiry records which file the visitor got
        $gatedFile = $email !== '' ? \Talea\Core\Documents::gatedFile($element['content']) : '';
        if ($gatedFile !== '') {
            $data[] = [t('File sent by e-mail'), \Talea\Core\Documents::fileName($gatedFile)];
            $types[] = 'info';
        }
        // attachments outside public folders (storage/ is not reachable from the web); only a signed-in user can download
        // them in Enquiries
        foreach ($attachments as $index => [$tmp, $extension]) {
            $path = date('Y/m') . '/' . bin2hex(random_bytes(12)) . '.' . $extension;
            $target = TALEA_ROOT . '/storage/attachments/' . $path;
            if ((is_dir(dirname($target)) || mkdir(dirname($target), 0775, true)) && move_uploaded_file($tmp, $target)) {
                $data[$index][2] = $path;
            }
        }

        $db = $this->app->db();
        [$landing, $visitCampaign, $referrer] = self::attribution($r);
        // the campaign of the page with the form, otherwise the campaign the visit started with (with consent, 2.3)
        $campaign = self::campaign($r->referer(), $r->origin()) ?: $visitCampaign;
        // what the form was about (2.12): the item, page or pop-up it was on – looked up here, never taken from the request
        $about = EnquiryTopic::find($db, $source, $back);
        $idp = $db->insert('enquiries', [
            'created_at' => date('Y-m-d H:i:s'), 'form' => mb_substr((string) $element['content']['name'], 0, 120), 'source' => $source, 'element' => $element['id'],
            'page' => mb_substr($back, 0, 255), 'topic' => $about, 'landing_page' => $landing, 'referrer' => $referrer, 'campaign' => $campaign, 'email' => $email, 'data' => (string) json_encode($data, JSON_UNESCAPED_UNICODE), 'status' => 0,
        ]);
        \Talea\Core\Events::record($db, 'enquiry.received', 'info', t('Form “%s” sent from %s', mb_substr((string) $element['content']['name'], 0, 80), mb_substr($back, 0, 120)),
            ['enquiry' => $idp, 'form' => (string) $element['id'], 'source' => $source]); // the form and the page, never the sender
        \Talea\Core\Triage::afterSubmit($this->app, $idp); // what is certain is sorted at once (a job application, 2.12)
        $this->notify($idp, $element, $data, $email, $campaign, $about);
        \Talea\Core\Webhook::enquiryReceived($this->app, $idp, (string) $element['content']['name'], $data, $email, $back, $campaign, $landing, $referrer, (string) $element['id'], $about);
        // a sheet and the CRM (2.13): through the connector queue, never while the visitor waits
        \Talea\Core\EnquiryDelivery::enquiryReceived($this->app, $idp, $source, (string) $element['content']['name'], $about, $email, $back, \Talea\Core\EnquiryDelivery::fields($data, $types));
        if ($gatedFile !== '') {
            \Talea\Core\Documents::sendGated($this->app, $email, $gatedFile); // a signed link that works for a week
        }
        if (!empty($element['content']['confirmation']) && $email !== '') {
            // confirmation to the sender: only the thank-you text, the next steps (2.12) and the form name – not the message
            // content, so the form cannot be abused to send out other people's texts
            $siteSettings = $this->app->settings();
            $nextSteps = NextSteps::text($this->app, $element['content']);
            Mail::send($siteSettings, $email, t('Confirmation: %s', $siteSettings->get('site_name')), $element['content']['thank_you'] . ($nextSteps !== '' ? "\n\n" . $nextSteps : '')
                . "\n\n—\n" . $siteSettings->get('site_name') . "\n" . rtrim($siteSettings->get('site_url') ?: $r->origin(), '/'), '');
        }
        $thankYouUrl = (string) ($element['content']['thank_you_page'] ?? '');
        // the browser reads „/\cizi.cz“ as //cizi.cz – a backslash in the thank-you page URL is rejected
        if ($thankYouUrl !== '' && !str_contains($thankYouUrl, '\\') && (str_starts_with($thankYouUrl, '/') && !str_starts_with($thankYouUrl, '//') || preg_match('#^https://#', $thankYouUrl))) {
            // a URL on the site is the full path (including the language, /en/…), only the installation folder is added
            // ?sent=<name> on the thank-you page reports the conversion to analytics (image/web.js), just like the
            // in-place thank-you
            $thankYouUrl = (str_starts_with($thankYouUrl, '/') ? $r->basePath() . $thankYouUrl : $thankYouUrl);
            $thankYouUrl .= (str_contains($thankYouUrl, '?') ? '&' : '?') . 'sent=' . rawurlencode((string) $element['content']['name']);

            return Response::redirect($thankYouUrl, 303);
        }

        return $redirectUri('ok');
    }

    /**
     * The source a form carries (page:<uuid>, collection:<uuid>, popup:<uuid>, part:…) as the system stores it (with the integer key).
     * A page, collection or pop-up that is not a public id of an existing row gives '' – integer keys from outside are never accepted.
     */
    public static function internalSource(\Talea\Core\Db $db, string $source): string
    {
        if (!preg_match('/^(page|collection|popup):(.*)$/s', $source, $m)) {
            return $source;
        }
        $id = $db->internalId(['page' => 'pages', 'collection' => 'collections', 'popup' => 'popups'][$m[1]], $m[2]);

        return $id > 0 ? $m[1] . ':' . $id : '';
    }

    /** Form from the published build of a page or site part. @return array<string, mixed>|null */
    private function element(string $source, string $id): ?array
    {
        return self::findElement($this->app->db(), $source, $id, Form::TYPE);
    }

    /**
     * An element of a given type from the PUBLISHED build the source names (a page, a site part, a collection template, a
     * pop-up), by its id – also inside a component. The forms and the booking (3.0, Front\Booking) take their settings
     * from here, never from the browser.
     *
     * @return array<string, mixed>|null
     */
    public static function findElement(\Talea\Core\Db $db, string $source, string $id, string $type): ?array
    {
        $build = match (true) {
            (bool) preg_match('/^page:(\d+)$/', $source, $m) => Build::fromJson($db->value('SELECT build FROM {pages} WHERE page_id = ? AND visible = TRUE', [(int) $m[1]])),
            (bool) preg_match('/^part:([a-z]+):([a-z]{0,2})(?::([a-z0-9-]{1,40}))?$/', $source, $m) && isset(SiteParts::TYPES[$m[1]]) => SiteParts::build($db, $m[1], $m[2], false, $m[3] ?? ''),
            (bool) preg_match('/^collection:(\d+)$/', $source, $m) => Build::fromJson($db->value('SELECT build FROM {collections} WHERE collection_id = ? AND detail = TRUE', [(int) $m[1]])),
            (bool) preg_match('/^popup:(\d+)$/', $source, $m) => Build::fromJson($db->value('SELECT build FROM {popups} WHERE popup_id = ? AND active = TRUE', [(int) $m[1]])),
            default => null,
        };
        // the element can also be inside a component (its published build); depth as when rendering
        $find = function (array $children, array $nesting = []) use (&$find, $id, $db, $type): ?array {
            foreach ($children as $p) {
                if (($p['id'] ?? '') === $id) {
                    return ($p['type'] ?? '') === $type ? $p : null;
                }
                if (($found = $find($p['children'] ?? [], $nesting)) !== null) {
                    return $found;
                }
                $idm = ($p['type'] ?? '') === \Talea\Builder\Elements\Component::TYPE ? (int) ($p['content']['component'] ?? 0) : 0;
                if ($idm > 0 && !in_array($idm, $nesting, true) && count($nesting) < Components::MAX_NESTING) {
                    $component = Components::byId($db, $idm);
                    $inner = $component === null ? null : Build::fromJson($component['build'] ?? $component['build_draft']);
                    if ($inner !== null && ($found = $find($inner['children'] ?? [], [...$nesting, $idm])) !== null) {
                        return $found;
                    }
                }
            }

            return null;
        };

        return $build === null || $id === '' ? null : $find($build['children'] ?? []);
    }

    /** @param list<array{0:string, 1:string}> $data */
    public const array UTM = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'];

    /**
     * Campaign from the URL of the page with the form (Referer header of the submission): only utm_* parameters, only
     * from the own site. No cookies and no storage in the browser – the campaign is recorded when the form is directly on
     * the page the ad leads to.
     */
    public static function campaign(string $referer, string $origin): string
    {
        $host = strtolower((string) parse_url($referer, PHP_URL_HOST));
        if ($host === '' || $host !== strtolower((string) parse_url($origin, PHP_URL_HOST))) {
            return '';
        }
        parse_str((string) parse_url($referer, PHP_URL_QUERY), $query);
        $utm = [];
        foreach (self::UTM as $key) {
            $value = is_string($query[$key] ?? null) ? trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $query[$key])) : '';
            if ($value !== '') {
                $utm[$key] = mb_substr($value, 0, 80);
            }
        }
        $campaign = http_build_query($utm);

        return strlen($campaign) <= 255 ? $campaign : '';
    }

    /** Human-readable campaign: „google / cpc / jarni-akce“ (source / medium / campaign, optionally keyword and content). */
    public static function campaignText(string $campaign): string
    {
        parse_str($campaign, $utm);

        return implode(' / ', array_filter(array_map(fn (string $k): string => is_string($utm[$k] ?? null) ? $utm[$k] : '', self::UTM), fn (string $h): bool => $h !== ''));
    }

    private function notify(int $idp, array $element, array $data, string $email, string $campaign, string $about = ''): void
    {
        $siteSettings = $this->app->settings();
        $recipient = filter_var($element['content']['recipient'], FILTER_VALIDATE_EMAIL) !== false ? $element['content']['recipient'] : $siteSettings->get('site_email');
        if ($recipient === '') {
            return; // the enquiry is saved in the administration even without an e-mail
        }
        $url = rtrim($siteSettings->get('site_url') !== '' ? $siteSettings->get('site_url') : $this->app->request->origin(), '/');
        // what it was about comes first (2.12): the reader knows the service or product before the message
        $text = ($about !== '' ? t('Topic') . ":\n" . $about . "\n\n" : '')
            . implode("\n\n", array_map(fn (array $d): string => $d[0] . ":\n" . $d[1], $data))
            . ($campaign !== '' ? "\n\n" . t('Campaign') . ":\n" . self::campaignText($campaign) : '')
            . "\n\n—\n" . t('Enquiry in the administration: %s', $url . $this->app->url('admin.php?module=enquiries&action=detail&id=' . $this->app->db()->publicId('enquiries', $idp)));
        Mail::send($siteSettings, $recipient, t('%s: %s', $element['content']['name'], $siteSettings->get('site_name')), $text, '', $email !== '' ? ['Reply-To' => $email] : []);
    }
}
