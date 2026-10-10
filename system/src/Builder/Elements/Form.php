<?php

declare(strict_types=1);

namespace Talea\Builder\Elements;

use Talea\Core\Antispam;
use Talea\Builder\Context;
use Talea\Builder\Element;

/**
 * Enquiry / contact form. It is sent to /form (Front\Forms): the server takes the fields from the published build
 * (not from the browser), verifies them, saves the enquiry ("Admin → Enquiries") and sends a notification e-mail.
 * Protection without cookies and CAPTCHA (Core\Antispam), so the page with the form stays in the cache.
 */
final class Form extends Element
{
    public const string TYPE = 'form';
    public const string EXTENSION = 'enquiries';
    public const string NAME = 'Form';
    public const string DESCRIPTION = 'An enquiry or question – submitted messages are in Enquiries and arrive by email.';
    public const string ICON = 'form';
    public const string GROUP = 'Dynamic';
    public const array HTML_TAGS = ['form'];

    /** Form field types. */
    public const array FIELD_TYPES = ['text' => 'text', 'email' => 'email', 'tel' => 'phone', 'textarea' => 'longer text', 'select' => 'choice from a list',
        'radio' => 'single choice (radio buttons)', 'checkboxes' => 'several choices (checkboxes)', 'date' => 'date', 'number' => 'number', 'file' => 'attachment (file)',
        'checkbox' => 'checkbox (consent)', 'hidden' => 'hidden value (e.g. the product the form is about)', 'basket' => 'enquiry basket (products added with Add to enquiry)',
        'step' => 'new step (a multi-step form – the label is the step title)', 'estimate' => 'price estimate (adds up the prices of the answers)'];

    /** An option with a price for the estimate (2.12): "Label | 1200" – the visitor sees and sends only the label. */
    private const string PRICED_OPTION = '/^(.*?)\s*\|\s*(-?\d[\d \x{a0}]*(?:[.,]\d+)?)\s*$/u';

    /** Form attachments: allowed types and the maximum size of one file. */
    /** Phone in the pattern attribute (the browser reads it with the v flag – parentheses, slash and hyphen in the class must be escaped). */
    public const string PHONE_PATTERN = '[+\\(\\)\\d\\s\\/.\\-]{6,30}';

    public const array ATTACHMENT_EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'heic', 'doc', 'docx', 'xls', 'xlsx', 'odt', 'ods', 'txt', 'zip', 'dwg', 'dxf'];
    public const int MAX_ATTACHMENT = 10 * 1024 * 1024;

    public static function properties(): array
    {
        return [
            'name' => ['type' => 'text', 'label' => 'Form name (in Enquiries and in the email)', 'default' => t('Enquiry'), 'max' => 120],
            'fields' => ['type' => 'items', 'label' => 'Form fields', 'max' => 20, 'fields' => [
                'label' => ['type' => 'text', 'label' => 'Label', 'default' => '', 'max' => 200],
                'type' => ['type' => 'choice', 'label' => 'Type', 'default' => 'text', 'options' => self::FIELD_TYPES],
                'required' => ['type' => 'boolean', 'label' => 'Required', 'default' => false],
                'options' => ['type' => 'lines', 'label' => 'Choice options (one per line)', 'default' => '', 'max' => 2000, 'when' => ['type' => 'select']],
                'choices' => ['type' => 'lines', 'label' => 'Options (one per line)', 'default' => '', 'max' => 2000, 'when' => ['type' => 'radio']],
                'checkbox_options' => ['type' => 'lines', 'label' => 'Options to tick (one per line)', 'default' => '', 'max' => 2000, 'when' => ['type' => 'checkboxes']],
                'value' => ['type' => 'text', 'label' => 'Value sent with the form (not shown to the visitor)', 'default' => '', 'max' => 300, 'when' => ['type' => 'hidden']],
                // a quote calculator (2.12): options carry their price ("Label | 1200"), a number field a price per unit, the estimate a base
                'unit_price' => ['type' => 'text', 'label' => 'Price per unit for the estimate (the number × this price)', 'default' => '', 'max' => 20, 'when' => ['type' => 'number']],
                'base_price' => ['type' => 'text', 'label' => 'Base price of the estimate', 'default' => '', 'max' => 20, 'when' => ['type' => 'estimate']],
                'currency' => ['type' => 'text', 'label' => 'Currency of the estimate (e.g. EUR, USD)', 'default' => '', 'max' => 10, 'when' => ['type' => 'estimate']],
                // conditions (2.12): the field shows only when another field has a value
                'show_when_field' => ['type' => 'text', 'label' => 'Show only when the field labelled…', 'default' => '', 'max' => 200],
                'show_when_value' => ['type' => 'text', 'label' => '…has this value (for options to tick: this one is ticked)', 'default' => '', 'max' => 200],
            ], 'default' => [
                ['label' => t('Your name'), 'type' => 'text', 'required' => true, 'options' => ''],
                ['label' => t('Email'), 'type' => 'email', 'required' => true, 'options' => ''],
                ['label' => t('Phone'), 'type' => 'tel', 'required' => false, 'options' => ''],
                ['label' => t('How can we help you?'), 'type' => 'textarea', 'required' => true, 'options' => ''],
                ['label' => t('I agree to the processing of my personal data for the purpose of handling this enquiry.'), 'type' => 'checkbox', 'required' => true, 'options' => ''],
            ]],
            'button_text' => ['type' => 'text', 'label' => 'Button text', 'default' => t('Send enquiry'), 'max' => 80],
            'thank_you' => ['type' => 'text', 'label' => 'Thank-you message', 'default' => t('Thank you, we have received your message. We will get back to you as soon as possible.'), 'max' => 400],
            'recipient' => ['type' => 'text', 'label' => 'Notification email (empty = site email from Settings)', 'default' => '', 'max' => 190],
            'thank_you_page' => ['type' => 'link', 'label' => 'After sending, go to a page (empty = thank-you message in place of the form)', 'default' => ''],
            'confirmation' => ['type' => 'boolean', 'label' => 'Send the sender a confirmation e-mail (thank-you only, without the message content)', 'default' => false],
            // a gated download (2.11, Core\Documents): the file goes out as a signed link that works for a week
            'send_file' => ['type' => 'link', 'label' => 'After sending, e-mail this file to the visitor (a file from Media; the form needs an e-mail field). A file in Media stays reachable by its own address – this stops casual sharing, not a determined person.', 'default' => '', 'media' => 'file'],
            'no_captcha' => ['type' => 'boolean', 'label' => 'Without the extra spam check (CAPTCHA from Settings → Privacy and cookies)', 'default' => false],
            // what happens next (2.12, Front\NextSteps): shown with the thank-you and sent in the confirmation e-mail
            'next_steps' => ['type' => 'lines', 'label' => 'What happens next (one step per line, shown with the thank-you)', 'default' => '', 'max' => 2000],
            'reply_within_hours' => ['type' => 'number', 'label' => 'We reply within (working hours by the opening hours in Business details; 0 = not shown)', 'default' => 0, 'min' => 0, 'max' => \Talea\Front\NextSteps::MAX_HOURS],
            'who_replies' => ['type' => 'text', 'label' => 'Who replies (e.g. “Jana from the office”)', 'default' => '', 'max' => 120],
        ];
    }

    public static function baseCss(): string
    {
        // the anchor after sending points to the form: an offset so that the heading above it is visible too and the sticky header does not cover it
        return '.tl-form { display: grid; gap: var(--tl-space-s); }
.tl-form, .tl-form-done { scroll-margin-top: 6rem; }
.tl-field { display: grid; gap: var(--tl-space-2xs); margin: 0; }
.tl-field > label { font-weight: 600; }
.tl-field input:not([type="checkbox"]):not([type="radio"]), .tl-field select, .tl-field textarea { box-sizing: border-box; width: 100%; padding: 0.7em 0.9em; border: 1px solid var(--tl-color-line); border-radius: var(--tl-radius); background: var(--tl-color-background); color: var(--tl-color-text); font: inherit; }
.tl-field textarea { min-height: 8em; resize: vertical; }
.tl-field :focus-visible { outline: 2px solid var(--tl-color-primary); outline-offset: 1px; }
.tl-field-consent label { display: flex; gap: var(--tl-space-xs); align-items: flex-start; }
.tl-field-consent input { margin-block-start: 0.3em; accent-color: var(--tl-color-primary); }
.tl-step { display: grid; gap: var(--tl-space-s); margin: 0; padding: 0; border: 0; }
.tl-step > legend { margin-bottom: var(--tl-space-xs); font-weight: 700; font-size: 1.1em; }
.tl-steps-navigation { display: flex; flex-wrap: wrap; gap: var(--tl-space-xs); align-items: center; }
.tl-steps-navigation span { margin-inline-end: auto; color: var(--tl-color-muted); font-size: 0.9em; }
.tl-estimate { display: flex; flex-wrap: wrap; align-items: baseline; gap: 0.5em; padding: 0.75em 1em; border-radius: var(--tl-radius-m); background: var(--tl-color-surface); }
.tl-estimate output { font-size: 1.4em; font-weight: 700; }
.tl-estimate small { flex-basis: 100%; }
.tl-basket { display: grid; gap: var(--tl-space-xs); margin: 0; padding: 0; list-style: none; }
.tl-basket li { display: flex; flex-wrap: wrap; align-items: center; gap: var(--tl-space-xs); padding: 0.5em 0.75em; border: 1px solid var(--tl-color-line); border-radius: var(--tl-radius-m); }
.tl-basket li > span { flex: 1 1 12rem; }
.tl-basket input { width: 5em; }
.tl-basket button { background: none; border: 0; color: inherit; text-decoration: underline; cursor: pointer; font: inherit; }
.tl-basket-empty { margin: 0; color: var(--tl-color-muted); }
.tl-field-policy { display: inline-block; margin-inline-start: 1.6em; font-size: var(--tl-step--1); }
.tl-required { color: var(--tl-color-primary); }
.tl-field fieldset { display: grid; gap: var(--tl-space-2xs); margin: 0; padding: 0; border: 0; }
.tl-field legend { margin-block-end: var(--tl-space-2xs); padding: 0; font-weight: 600; }
.tl-field fieldset label { display: flex; gap: var(--tl-space-xs); align-items: center; font-weight: 400; }
.tl-field fieldset input { accent-color: var(--tl-color-primary); }
.tl-field [aria-invalid="true"] { border-color: #c4281c !important; }
.tl-field-help { color: var(--tl-color-muted); font-size: var(--tl-step--1); }
.tl-field-error { color: color-mix(in oklch, #c4281c 80%, var(--tl-color-text)); font-size: var(--tl-step--1); }
.tl-form-done, .tl-form-error { margin: 0; padding: var(--tl-space-m); border-radius: var(--tl-radius); }
.tl-form-done { background: var(--tl-color-primary-soft); color: var(--tl-color-text); }
.tl-form-error { background: color-mix(in oklch, #c4281c 12%, var(--tl-color-background)); color: color-mix(in oklch, #c4281c 80%, var(--tl-color-text)); }
.tl-form-done p + p, .tl-form-done ol + p { margin-block-start: var(--tl-space-s); }
.tl-form-done .tl-steps-heading { font-weight: 600; }
.tl-form-done ol { margin: var(--tl-space-2xs) 0 0; padding-inline-start: 1.5em; }
.tl-form [hidden] { display: none !important; }'; // the display of steps, fields and buttons above would otherwise beat the hidden attribute
    }

    /** Form anchor (where the page returns after sending): the same as the id the form gets when rendered. */
    public static function anchor(array $p): string
    {
        return $p['anchor'] ?? (!empty($p['style']) ? 's-' . $p['id'] : 'form-' . $p['id']);
    }

    /** The CAPTCHA widget when the site has one (2.6); in the editor only a note, the provider's script does not load there. */
    public static function captcha(Context $k): string
    {
        if (\Talea\Core\Captcha::provider($k->app->settings()) === null) {
            return '';
        }

        return $k->editor ? '<p class="tl-field tl-captcha"><small>' . e(t('CAPTCHA is checked here when the form is sent.')) . '</small></p>'
            : '<div class="tl-field">' . \Talea\Core\Captcha::widget($k->app->settings()) . '</div>';
    }

    /** Message after sending by the code in the url (?form=<id>&result=<code>) – the text never comes from the url. */
    public static function messages(string $code): string
    {
        return match ($code) {
            'field' => t('Please check the highlighted field.'),
            'limit' => t('Too many messages have come from your address in a short time. Please try again later.'),
            'too_fast' => t('The form was sent before we could check that a person is sending it. Please wait a moment and send it again.'),
            'verification' => t('The form could not be verified. Reload the page and try again.'),
            'captcha' => t('Please confirm that you are not a robot and send the form again.'),
            'full' => t('This event is fully booked.'),
            'closed' => t('Registration is closed.'),
            default => t('The message could not be sent. Please try again.'),
        };
    }

    public static function render(array $p, string $a, string $children, Context $k): string
    {
        $o = $p['content'];
        $r = $k->app->request;
        $result = $r->get('form') === $p['id'] ? $r->get('result') : '';
        $id = str_contains($a, ' id="') ? '' : ' id="' . e(self::anchor($p)) . '"';
        $hasBasket = in_array('basket', array_column($o['fields'], 'type'), true);
        if ($result === 'ok') {
            // data-sent: image/web.js reports the conversion (the talea:form_sent event and dataLayer, when the site has it);
            // data-basket-sent: the enquiry basket was sent – the script empties it
            // after the thank-you text the next steps, the reply deadline and who replies (2.12, Front\NextSteps) when the form has them
            return '<div' . Text::withClass($a, 'tl-form-done') . $id . ' role="status" data-sent="' . e($o['name']) . '"' . ($hasBasket ? ' data-basket-sent' : '') . '><p>' . e($o['thank_you']) . '</p>'
                . \Talea\Front\NextSteps::html($k->app, $o) . '</div>';
        }
        // the registration form of an event (2.11, Core\Calendar): closed after the event or its deadline, or when it is full
        $registration = !$k->editor ? (string) ($k->item['_registration'][0] ?? '') : '';
        if ($registration === 'full' || $registration === 'closed') {
            return '<div' . Text::withClass($a, 'tl-form-done') . $id . ' role="status"><p>' . e(self::messages($registration === 'full' ? 'full' : 'closed')) . '</p></div>';
        }
        $k->types['button'] = true; // the form button looks like the Button element
        $html = $result !== '' ? '<p class="tl-form-error" role="alert">' . e(self::messages($result)) . '</p>' : '';
        $invalid = $result === 'field' ? $r->getInt('field', -1) : -1;
        [$steps, $stepTitle, $current] = [[], '', ''];
        foreach ($o['fields'] as $i => $field) {
            if ($field['type'] === 'hidden') {
                // the value is the form's own (Front\Forms) and the visitor normally neither sees nor sends it; on a collection item
                // page it may have been filled from the item ({{nazev}} in a job's template, 2.11), so there it travels with the form –
                // the server takes it only when its own value is a placeholder, and only as short plain text
                $current .= $k->item !== null && (string) ($field['value'] ?? '') !== '' ? '<input type="hidden" name="p' . $i . '" value="' . e((string) $field['value']) . '">' : '';
                continue;
            }
            if ($field['type'] === 'step') {
                $steps[] = [$stepTitle, $current]; // a new step of a multi-step form (2.12)
                [$stepTitle, $current] = [(string) $field['label'], ''];
                continue;
            }
            $one = match ($field['type']) {
                'basket' => self::basketField($field, $i, $p['id'], $i === $invalid, $k),
                'estimate' => self::estimateField($field, $o['fields']),
                default => self::fields($field, $i, $p['id'], $i === $invalid, \Talea\Core\Privacy::policyUrl($k->app->settings())),
            };
            // a condition (2.12): the script shows the field only for the answer; without the script it is always shown
            $when = mb_strtolower(trim((string) ($field['show_when_field'] ?? '')));
            $target = $when !== '' ? array_search($when, array_map(fn (array $f): string => mb_strtolower(trim((string) $f['label'])), $o['fields']), true) : false;
            $current .= $one !== '' && $target !== false ? '<div class="tl-condition" data-when="p' . (int) $target . '" data-when-value="' . e(trim((string) ($field['show_when_value'] ?? ''))) . '">' . $one . '</div>' : $one;
        }
        if ($steps === []) {
            $html .= $current;
        } else {
            // a multi-step form (2.12): one fieldset per step; the script shows one at a time with Back and Next, without it they are all shown
            $steps[] = [$stepTitle, $current];
            $html .= '<div class="tl-form-steps" data-steps>' . implode('', array_map(fn (array $step, int $n): string => '<fieldset class="tl-step"><legend>'
                . e($step[0] !== '' ? $step[0] : t('Step %d', $n + 1)) . '</legend>' . $step[1] . '</fieldset>', $steps, array_keys($steps))) . '</div>';
        }
        $antispam = new Antispam($k->app->db(), $k->app->settings());

        // data-form: after an error image/web.js puts back into the fields what the visitor filled in (only their browser keeps it)
        $files = in_array('file', array_column($o['fields'], 'type'), true) ? ' enctype="multipart/form-data"' : '';

        return '<form' . Text::withClass($a, 'tl-form') . $id . ' method="post" action="' . e($k->url('form')) . '"' . $files . ' data-form="' . e($p['id']) . '"' . ($result !== '' ? ' data-restore' : '') . '>'
            . '<input type="hidden" name="source" value="' . e($k->source) . '"><input type="hidden" name="element" value="' . e($p['id']) . '">'
            . '<input type="hidden" name="back" value="' . e($k->app->url($r->path())) . '">' . \Talea\Front\Forms::ATTRIBUTION_FIELDS
            . $antispam->fields('form|' . $k->source . '|' . $p['id'])
            . $html
            . (empty($o['no_captcha']) ? self::captcha($k) : '')
            . '<p class="tl-field"><button class="tl-button tl-button--primary" type="submit">' . e($o['button_text']) . '</button></p></form>';
    }

    /**
     * The enquiry basket field (2.11, Builder\Products): the products the visitor collected with Add to enquiry, which the
     * script keeps in the browser and writes into the hidden field as JSON. Without the script a product opened from Add to
     * enquiry (?product=collection/item&variant=…&quantity=…) is in it, checked like any basket line.
     */
    private static function basketField(array $field, int $i, string $element, bool $error, Context $k): string
    {
        $r = $k->app->request;
        $prefill = '[]';
        $list = '';
        if (preg_match('#^([a-z0-9-]{1,110})/([a-z0-9-]{1,160})$#', $r->get('product'), $m) === 1) {
            $line = ['c' => $m[1], 'i' => $m[2], 'v' => mb_substr($r->get('variant'), 0, 100), 'q' => max(1, min(9999, $r->getInt('quantity', 1)))];
            $lines = \Talea\Builder\Products::basketLines($k->app->db(), (string) json_encode([$line]));
            if ($lines !== null && $lines !== []) {
                $line['n'] = (string) $k->app->db()->value('SELECT p.name FROM {collection_items} p JOIN {collections} k ON k.collection_id = p.collection_id WHERE k.slug = ? AND p.slug = ? AND p.visible = 1 LIMIT 1', [$m[1], $m[2]]);
                $prefill = (string) json_encode([$line], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $list = '<li>' . e($lines[0]) . '</li>';
            }
        }
        $id = 'f-' . $element . '-' . $i;
        $star = $field['required'] ? ' <span class="tl-required" aria-hidden="true">*</span>' : '';
        $message = $error ? '<span class="tl-field-error" id="' . $id . '-error">' . e(t('Add at least one product to the enquiry.')) . '</span>' : '';

        return '<div class="tl-field tl-basket-field" id="poptavka"><span class="tl-caption" id="' . $id . '">' . e($field['label']) . $star . '</span>'
            . '<ul class="tl-basket" data-basket-list aria-labelledby="' . $id . '">' . $list . '</ul>'
            . '<p class="tl-basket-empty"' . ($list !== '' ? ' hidden' : '') . '>' . e(t('The enquiry is empty – add products with Add to enquiry.')) . '</p>'
            . '<input type="hidden" name="p' . $i . '" value="' . e($prefill) . '" data-basket-field' . ($field['required'] ? ' data-required' : '') . '>' . $message . '</div>';
    }

    private static function fields(array $field, int $i, string $element, bool $error = false, string $privacyPolicy = ''): string
    {
        $id = 'f-' . $element . '-' . $i;
        $displayName = 'p' . $i;
        $required = $field['required'] ? ' required' : '';
        $star = $field['required'] ? ' <span class="tl-required" aria-hidden="true">*</span>' : '';
        $labelText = e($field['label']);
        // a field the server rejected: marked and with a message that aria-describedby points to
        $marking = $error ? ' aria-invalid="true" aria-describedby="' . $id . '-error" autofocus' : '';
        $message = $error ? '<span class="tl-field-error" id="' . $id . '-error">' . e($field['type'] === 'email' ? t('Enter a valid e-mail address.') : t('Please fill in this field correctly.')) . '</span>' : '';
        if ($field['type'] === 'checkbox') {
            $link = $privacyPolicy !== '' ? ' <a class="tl-field-policy" href="' . e($privacyPolicy) . '" target="_blank">' . e(t('Privacy policy')) . '</a>' : '';

            return '<p class="tl-field tl-field-consent"><label><input type="checkbox" name="' . $displayName . '" value="1"' . $required . $marking . '> <span>' . $labelText . $star . '</span></label>' . $link . $message . '</p>';
        }
        if ($field['type'] === 'hidden') {
            return ''; // the value is the form's own (Front\Forms), the visitor neither sees nor sends it
        }
        if ($field['type'] === 'checkboxes') {
            $options = '';
            foreach (self::optionPrices($field) as $m => $price) {
                $options .= '<label><input type="checkbox" name="' . $displayName . '[]" value="' . e((string) $m) . '"' . self::priceAttribute($price) . $marking . '> ' . e((string) $m) . '</label>';
            }
            // required = at least one ticked; the browser cannot say that about a group, the server does (Front\Forms)
            return '<div class="tl-field tl-field-checkboxes"><fieldset' . ($field['required'] ? ' aria-required="true"' : '') . '><legend>' . $labelText . $star . '</legend>' . $options . '</fieldset>' . $message . '</div>';
        }
        if ($field['type'] === 'radio') {
            $options = '';
            $j = 0;
            foreach (self::optionPrices($field) as $m => $price) {
                $options .= '<label><input type="radio" name="' . $displayName . '" value="' . e((string) $m) . '"' . self::priceAttribute($price) . ($j++ === 0 ? $required . $marking : '') . '> ' . e((string) $m) . '</label>';
            }

            return '<div class="tl-field"><fieldset><legend>' . $labelText . $star . '</legend>' . $options . '</fieldset>' . $message . '</div>';
        }
        $label = '<label for="' . $id . '">' . $labelText . $star . '</label>';
        $input = match ($field['type']) {
            'textarea' => '<textarea id="' . $id . '" name="' . $displayName . '" maxlength="5000"' . $required . $marking . '></textarea>',
            'select' => '<select id="' . $id . '" name="' . $displayName . '"' . $required . $marking . '><option value="">' . e(t('— choose —')) . '</option>'
                . implode('', array_map(fn (string|int $m, float $price): string => '<option' . self::priceAttribute($price) . '>' . e((string) $m) . '</option>', array_keys($prices = self::optionPrices($field)), $prices)) . '</select>',
            'date' => '<input id="' . $id . '" name="' . $displayName . '" type="date"' . $required . $marking . '>',
            'number' => '<input id="' . $id . '" name="' . $displayName . '" type="number" step="any" inputmode="decimal"'
                . (self::price($field['unit_price'] ?? '') != 0.0 ? ' data-price-per="' . self::price($field['unit_price']) . '"' : '') . $required . $marking . '>',
            'file' => '<input id="' . $id . '" name="' . $displayName . '" type="file" accept=".' . implode(',.', self::ATTACHMENT_EXTENSIONS) . '"' . $required . $marking . '>'
                . '<small class="tl-field-help">' . e(t('Up to %d MB: PDF, image, document or ZIP.', (int) (self::MAX_ATTACHMENT / 1048576))) . '</small>',
            // phone: the same rule as on the server (Front\Forms), the browser checks it right away; the pattern is valid with the v flag too
            'tel' => '<input id="' . $id . '" name="' . $displayName . '" type="tel" autocomplete="tel" maxlength="30" pattern="' . self::PHONE_PATTERN . '" title="' . e(t('Phone number, for example +44 20 7946 0958.')) . '"' . $required . $marking . '>',
            default => '<input id="' . $id . '" name="' . $displayName . '" type="' . ($field['type'] === 'email' ? 'email" autocomplete="email' : 'text' . self::autocomplete($field['label'])) . '" maxlength="300"' . $required . $marking . '>',
        };

        return '<p class="tl-field">' . $label . $input . $message . '</p>';
    }

    /**
     * Autocomplete of a text field by its label (WCAG 1.3.5): name and company. The field type stays "text",
     * so that forms built earlier keep working.
     */
    private static function autocomplete(string $labelText): string
    {
        return match (true) {
            (bool) preg_match('/^(vaše |celé |your |full )?(jméno|name)\b/iu', trim($labelText)) => '" autocomplete="name', // check-english: allow
            (bool) preg_match('/^(firma|společnost|název firmy|company|organi[sz]ation)\b/iu', trim($labelText)) => '" autocomplete="organization', // check-english: allow
            default => '',
        };
    }

    /** @return list<string> options of a select or radio buttons */
    public static function options(array $field): array
    {
        return array_keys(self::optionPrices($field));
    }

    /**
     * The options of a choice field with their prices for the estimate (2.12): label => price (0 without one).
     *
     * @return array<string, float>
     */
    public static function optionPrices(array $field): array
    {
        $text = match ($field['type'] ?? '') {
            'radio' => (string) ($field['choices'] ?? ''),
            'checkboxes' => (string) ($field['checkbox_options'] ?? ''),
            default => (string) ($field['options'] ?? ''),
        };
        $out = [];
        foreach (explode("\n", $text) as $line) {
            $line = trim($line);
            $price = preg_match(self::PRICED_OPTION, $line, $m) === 1 ? self::price($m[2]) : null;
            $label = $price !== null ? trim($m[1]) : $line;
            if ($label !== '') {
                $out[$label] = $price ?? 0.0;
            }
        }

        return $out;
    }

    /**
     * The price estimate field (2.12): the label and the amount, which the script recalculates as the visitor answers;
     * without the script it shows the base price and the server computes the real estimate when the form is sent.
     *
     * @param list<array<string, mixed>> $fields
     */
    private static function estimateField(array $field, array $fields): string
    {
        $base = self::price($field['base_price'] ?? '');
        $currency = mb_substr(trim((string) ($field['currency'] ?? '')), 0, 10);

        return '<p class="tl-field tl-estimate" data-estimate data-base="' . $base . '" data-currency="' . e($currency) . '"><span>' . e((string) $field['label']) . '</span> <output aria-live="polite">'
            . e(self::money($base, $currency)) . '</output><small class="tl-field-help">' . e(t('An estimate from your answers – the final price follows in our reply.')) . '</small></p>';
    }

    /** The price of an option for the estimate script; nothing without one. */
    private static function priceAttribute(float $price): string
    {
        return $price != 0.0 ? ' data-price="' . $price . '"' : '';
    }

    /** A price written by the administrator ("1 200", "12,50") as a number; 0 when empty or not a number. */
    public static function price(mixed $value): float
    {
        $v = str_replace([' ', "\u{a0}", ','], ['', '', '.'], is_scalar($value) ? (string) $value : '');

        return is_numeric($v) ? (float) $v : 0.0;
    }

    /**
     * Which fields are shown for the given answers (2.12): a field with a condition only when the field with that label has
     * that value (a choice: equals it; options to tick: it is ticked; a "*" value: anything filled in). A condition on a
     * field that does not exist or is itself hidden hides the field. Pure – the server and the tests use it.
     *
     * @param list<array<string, mixed>> $fields
     * @param array<int, string|list<string>> $answers index => value (a list for options to tick)
     * @return array<int, bool>
     */
    public static function visible(array $fields, array $answers): array
    {
        $byLabel = [];
        foreach ($fields as $i => $f) {
            $byLabel[mb_strtolower(trim((string) ($f['label'] ?? '')))] ??= $i;
        }
        $visible = [];
        $resolve = function (int $i, int $depth) use (&$resolve, &$visible, $fields, $answers, $byLabel): bool {
            if (isset($visible[$i])) {
                return $visible[$i];
            }
            $when = mb_strtolower(trim((string) ($fields[$i]['show_when_field'] ?? '')));
            if ($when === '') {
                return $visible[$i] = true;
            }
            $target = $byLabel[$when] ?? null;
            if ($target === null || $target === $i || $depth > 10 || !$resolve($target, $depth + 1)) {
                return $visible[$i] = false;
            }
            $expected = trim((string) ($fields[$i]['show_when_value'] ?? ''));
            $answer = $answers[$target] ?? '';

            return $visible[$i] = is_array($answer) ? ($expected === '*' ? $answer !== [] : in_array($expected, $answer, true))
                : ($expected === '*' ? trim($answer) !== '' : trim($answer) === $expected);
        };
        foreach (array_keys($fields) as $i) {
            $resolve($i, 0);
        }

        return $visible;
    }

    /**
     * The price estimate (2.12): the base price, plus the price of every chosen or ticked option, plus every number × its
     * price per unit – only of the fields that are shown. Pure; the server computes it again, never trusting the browser.
     *
     * @param list<array<string, mixed>> $fields
     * @param array<int, string|list<string>> $answers
     * @param array<int, bool> $visible
     */
    public static function estimate(array $fields, array $answers, array $visible, float $base): float
    {
        $total = $base;
        foreach ($fields as $i => $f) {
            if (!($visible[$i] ?? true)) {
                continue;
            }
            $answer = $answers[$i] ?? '';
            $type = (string) ($f['type'] ?? '');
            if (in_array($type, ['select', 'radio', 'checkboxes'], true)) {
                $prices = self::optionPrices($f);
                foreach ((array) $answer as $chosen) {
                    $total += $prices[(string) $chosen] ?? 0.0;
                }
            } elseif ($type === 'number' && is_string($answer) && is_numeric(str_replace(',', '.', $answer))) {
                $total += (float) str_replace(',', '.', $answer) * self::price($f['unit_price'] ?? '');
            }
        }

        return round($total, 2);
    }

    /** An amount for visitors: whole numbers with the thousands separator of the language, the currency after it. */
    public static function money(float $amount, string $currency): string
    {
        $number = format_count($amount, abs($amount - round($amount)) < 0.005 ? 0 : 2);

        return trim($number . ($currency !== '' ? "\u{a0}" . $currency : ''));
    }
}
