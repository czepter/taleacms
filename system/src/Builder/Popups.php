<?php

declare(strict_types=1);

namespace Talea\Builder;

use Talea\Core\Db;

/**
 * Popups as site parts: the content is built in the builder (target “popup:<id>”, versions in build_revisions), a popup has a type,
 * a trigger, display rules and a frequency.
 *
 * The rules the server knows (pages, collections, news, language, period) decide whether the popup is inserted into the page
 * at all. The rest (device, campaign, where the visitor came from, number of pages in the visit, frequency) is evaluated by
 * image/web.js in the browser – without cookies, only the visitor's sessionStorage and localStorage. The counters of views,
 * closes and conversions are incremented by POST /popup (Front\Kernel).
 */
final class Popups
{
    /** type => [name, popover] – the window and the full screen close with a click outside (auto), the panel and bars do not (manual) */
    public const array TYPES = [
        'window' => ['Window in the middle', 'auto'],
        'slide_in' => ['Slide-in panel in the corner', 'manual'],
        'top_bar' => ['Bar at the top', 'manual'],
        'bottom_bar' => ['Bar at the bottom', 'manual'],
        'fullscreen' => ['Full screen', 'auto'],
    ];

    /** trigger => [name, value unit ('' = without a value)] */
    public const array TRIGGERS = [
        'time' => ['After a number of seconds', 's'],
        'scroll' => ['After scrolling part of the page', '%'],
        'exit' => ['When the visitor is about to leave', ''],
        'idle' => ['After a number of seconds without activity', 's'],
        'pages' => ['After a number of pages in the visit', 'pages'],
        'click' => ['Only by clicking a link or button', ''],
    ];

    public const array FREQUENCIES = [
        'session' => 'Once per visit',
        'days' => 'Once every number of days',
        'until_closed' => 'Until the visitor closes it',
        'until_submitted' => 'Until the visitor sends the form in it',
        'always' => 'Every time the trigger is met',
    ];

    public const array DEVICES = ['all' => 'All devices', 'desktop' => 'Computer and tablet only', 'phone' => 'Phone only'];

    public const string ADDRESS_PATTERN = '/^[a-z0-9][a-z0-9-]{0,59}$/';

    /** Rules of a new popup: the whole site, all languages, no restrictions. */
    public static function defaultRules(): array
    {
        return ['where' => 'all', 'pages' => [], 'collections' => [], 'news' => false, 'language' => '', 'from' => '', 'to' => '',
            'device' => 'all', 'campaign' => '', 'referrer' => ''];
    }

    /** @param array<string, mixed> $p */
    public static function sanitizeRules(array $p): array
    {
        $date = fn (mixed $d): string => is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && checkdate((int) substr($d, 5, 2), (int) substr($d, 8, 2), (int) substr($d, 0, 4)) ? $d : '';
        $text = fn (mixed $t): string => mb_substr(trim(preg_replace('/[^\p{L}\p{N} ._\/-]/u', '', is_string($t) ? $t : '') ?? ''), 0, 80);

        return [
            'where' => ($p['where'] ?? '') === 'selected' ? 'selected' : 'all',
            'pages' => array_values(array_unique(array_filter(array_map('intval', is_array($p['pages'] ?? null) ? $p['pages'] : []), fn (int $i): bool => $i > 0))),
            'collections' => array_values(array_unique(array_filter(is_array($p['collections'] ?? null) ? $p['collections'] : [], fn (mixed $k): bool => is_string($k) && preg_match('/^[a-z0-9-]{1,110}$/', $k) === 1))),
            'news' => !empty($p['news']),
            'language' => is_string($p['language'] ?? null) && preg_match('/^[a-z]{2}$/', $p['language']) ? $p['language'] : '',
            'from' => $date($p['from'] ?? ''),
            'to' => $date($p['to'] ?? ''),
            'device' => isset(self::DEVICES[$p['device'] ?? '']) ? $p['device'] : 'all',
            'campaign' => $text($p['campaign'] ?? ''),
            'referrer' => $text($p['referrer'] ?? ''),
        ];
    }

    /**
     * Decides the rules the server knows: language and period always apply, the choice of places only with “selected”.
     *
     * @param array<string, mixed> $rules sanitized rules
     * @param array{page_id: ?int, collection: ?string, news: bool, language: string, today: string} $whereParts the displayed page
     */
    public static function matches(array $rules, array $whereParts): bool
    {
        if ($rules['language'] !== '' && $rules['language'] !== $whereParts['language']) {
            return false;
        }
        if (($rules['from'] !== '' && $whereParts['today'] < $rules['from']) || ($rules['to'] !== '' && $whereParts['today'] > $rules['to'])) {
            return false;
        }
        if ($rules['where'] === 'all') {
            return true;
        }

        return ($whereParts['page_id'] !== null && in_array($whereParts['page_id'], $rules['pages'], true))
            || ($whereParts['collection'] !== null && in_array($whereParts['collection'], $rules['collections'], true))
            || ($whereParts['news'] && $rules['news']);
    }

    /** A database row with the rules decoded. */
    public static function prepare(array $r): array
    {
        $r['rules'] = self::sanitizeRules(json_decode((string) $r['rules'], true) ?: []);
        foreach (['popup_id', 'value', 'days', 'active', 'sort_order', 'impressions', 'closes', 'conversions'] as $number) {
            $r[$number] = (int) $r[$number];
        }

        return $r;
    }

    public static function byId(Db $db, int $id): ?array
    {
        $r = $db->one('SELECT * FROM {popups} WHERE popup_id = ?', [$id]);

        return $r === null ? null : self::prepare($r);
    }

    /** @return list<array<string, mixed>> */
    public static function all(Db $db): array
    {
        return array_map(self::prepare(...), $db->all('SELECT * FROM {popups} ORDER BY sort_order, name'));
    }

    /**
     * Published and enabled popups for the displayed page.
     *
     * @param array{page_id: ?int, collection: ?string, news: bool, language: string, today: string} $whereParts
     * @return list<array<string, mixed>>
     */
    public static function forPage(Db $db, array $whereParts): array
    {
        return array_values(array_filter(
            array_map(self::prepare(...), $db->all('SELECT * FROM {popups} WHERE active = TRUE AND build IS NOT NULL ORDER BY sort_order, name')),
            fn (array $p): bool => self::matches($p['rules'], $whereParts),
        ));
    }

    /** A free popup slug (#popup-<slug>) derived from the text. */
    public static function address(Db $db, string $z, int $idpp = 0): string
    {
        return \Talea\Core\Slug::makeUnique(slugify($z, 50) ?: 'popup', fn (string $a): bool => $db->value('SELECT popup_id FROM {popups} WHERE slug = ? AND popup_id <> ?', [$a, $idpp]) !== null, 60);
    }

    /**
     * Popup wrapper on the site. The content is an already rendered build; image/web.js reads data-* (trigger, frequency, browser rules).
     * $open: preview – the popup opens right after loading, regardless of trigger and frequency.
     */
    public static function wrapper(array $p, string $content, string $counterUrl, bool $open = false): string
    {
        $type = isset(self::TYPES[$p['type']]) ? $p['type'] : 'window';
        $id = 'popup-' . $p['slug'];
        $dialog = in_array($type, ['window', 'fullscreen'], true);
        $data = ['popup' => (string) $p['public_id'], 'trigger' => $p['trigger_type'], 'value' => (string) $p['value'], 'frequency' => $p['frequency'],
            'days' => (string) $p['days'], 'device' => $p['rules']['device'], 'campaign' => $p['rules']['campaign'], 'referrer' => $p['rules']['referrer'],
            'counter' => $counterUrl] + ($open ? ['open' => '1'] : []);

        return '<div id="' . e($id) . '" class="tl-popup tl-popup--' . e($type) . '" popover="' . self::TYPES[$type][1] . '" role="' . ($dialog ? 'dialog' : 'region') . '"'
            . ' aria-label="' . e($p['name']) . '"' . implode('', array_map(fn (string $k, string $v): string => ' data-' . $k . '="' . e($v) . '"', array_keys($data), $data)) . '>'
            . '<button type="button" class="tl-popup-close" popovertarget="' . e($id) . '" popovertargetaction="hide" aria-label="' . e(t('Close')) . '">×</button>'
            . '<div class="tl-popup-content build">' . $content . '</div></div>';
    }

    /** Wrapper in the builder editor: the popup stands on the canvas so that it can be edited (without popover and trigger). */
    public static function editorWrapper(array $p, string $content): string
    {
        $type = isset(self::TYPES[$p['type']]) ? $p['type'] : 'window';

        return '<div class="tl-popup tl-popup--' . e($type) . ' tl-popup--editor"><div class="tl-popup-content build">' . $content . '</div></div>';
    }

    /** Ready-made popups for a new popup: key => [name, description, type, trigger, value]. */
    public const array LIBRARY = [
        'newsletter_signup' => ['Newsletter sign-up', 'A heading, a short text and an e-mail field with confirmed sign-up.', 'window', 'scroll', 50],
        'lead_magnet' => ['Download for an e-mail', 'A guide or price list in exchange for contact details – the form goes to Enquiries.', 'window', 'exit', 0],
        'announcement_bar' => ['Announcement bar', 'A slim bar at the top with a short message and a link.', 'top_bar', 'time', 1],
        'discount' => ['Discount or offer', 'A bold offer with a code and a button.', 'window', 'time', 15],
        'event' => ['Event invitation', 'A corner panel with the date, the place and a registration link.', 'slide_in', 'time', 8],
        'blank' => ['Blank pop-up', 'A heading and a text – build the rest in the builder.', 'window', 'click', 0],
    ];

    /** Build of a ready-made popup in the content language. */
    public static function libraryBuild(string $key, string $language = 'en'): array
    {
        return \Talea\Core\Language::runWith($language, function () use ($key): array {
            $n = Build::fresh(...);
            $h = fn (string $text, string $htmlTag = 'h2'): array => ['tag' => $htmlTag] + $n('heading', ['text' => $text]);
            $children = match ($key) {
                'newsletter_signup' => [$h(t('News once a month')), $n('text', ['html' => '<p>' . e(t('Tips and news from our field. No spam – unsubscribe with one click.')) . '</p>']), $n('newsletter_signup')],
                'lead_magnet' => [$h(t('Download the free guide')), $n('text', ['html' => '<p>' . e(t('We will send it by e-mail. We use your contact only to reply.')) . '</p>']),
                    $n('form', ['name' => t('Guide download'), 'thank_you' => t('Thank you! We will send you the guide by e-mail.'), 'button_text' => t('Send me the guide'),
                        'fields' => [['label' => t('Your name'), 'type' => 'text', 'required' => false, 'options' => ''], ['label' => t('Email'), 'type' => 'email', 'required' => true, 'options' => ''],
                            ['label' => t('I agree to the processing of my personal data for the purpose of handling this enquiry.'), 'type' => 'checkbox', 'required' => true, 'options' => '']]])],
                'announcement_bar' => [$n('container', [], [$h(t('We are now also open on Saturday mornings.'), 'p'), $n('button', ['text' => t('More information'), 'link' => '#', 'variant' => 'link'])])],
                'discount' => [$h(t('10% off your first order')), $n('text', ['html' => '<p>' . e(t('Enter the code')) . ' <strong>' . e(t('WELCOME10')) . '</strong>.</p>']), $n('button', ['text' => t('Get the discount'), 'link' => '#'])],
                'event' => [$h(t('Open day'), 'h3'), $n('text', ['html' => '<p>' . e(t('Saturday 12 October, 10 am – 4 pm. Come and see how we work.')) . '</p>']), $n('button', ['text' => t('I want to come'), 'link' => '#'])],
                default => [$h(t('Window heading')), $n('text', ['html' => '<p>' . e(t('A short text for the window.')) . '</p>'])],
            };
            if ($key === 'announcement_bar') {
                $children[0]['style'] = ['base' => ['display' => 'flex', 'direction' => 'row', 'align_items' => 'center', 'justify_content' => 'center', 'gap' => 's']];
            }

            return Build::sanitize(['v' => Build::VERSION, 'children' => [$n('container', [], $children)]], true)[0];
        });
    }
}
