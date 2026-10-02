<?php

declare(strict_types=1);

namespace Kaleta\Builder\Elements;

use Kaleta\Core\Antispam;
use Kaleta\Builder\Context;
use Kaleta\Builder\Element;

/**
 * Enquiry / contact form. It is sent to /formular (Front\Forms): the server takes the fields from the published build
 * (not from the browser), verifies them, saves the enquiry („Administrace → Poptávky“, i.e. Admin → Enquiries) and sends a notification e-mail.
 * Protection without cookies and CAPTCHA (Core\Antispam), so the page with the form stays in the cache.
 */
final class Form extends Element
{
    public const string TYPE = 'formular';
    public const string EXTENSION = 'poptavky';
    public const string NAME = 'Form';
    public const string DESCRIPTION = 'An enquiry or question – submitted messages are in Enquiries and arrive by email.';
    public const string ICON = 'formular';
    public const string GROUP = 'Dynamic';
    public const array HTML_TAGS = ['form'];

    /** Form field types. */
    public const array FIELD_TYPES = ['text' => 'text', 'email' => 'e-mail', 'tel' => 'telefon', 'textarea' => 'longer text', 'vyber' => 'choice from a list',
        'volba' => 'single choice (radio buttons)', 'zaskrtnuti' => 'several choices (checkboxes)', 'datum' => 'datum', 'cislo' => 'číslo', 'soubor' => 'attachment (file)',
        'souhlas' => 'checkbox (consent)', 'skryte' => 'hidden value (e.g. the product the form is about)'];

    /** Form attachments: allowed types and the maximum size of one file. */
    /** Phone in the pattern attribute (the browser reads it with the v flag – parentheses, slash and hyphen in the class must be escaped). */
    public const string PHONE_PATTERN = '[+\\(\\)\\d\\s\\/.\\-]{6,30}';

    public const array ATTACHMENT_EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'heic', 'doc', 'docx', 'xls', 'xlsx', 'odt', 'ods', 'txt', 'zip', 'dwg', 'dxf'];
    public const int MAX_ATTACHMENT = 10 * 1024 * 1024;

    public static function properties(): array
    {
        return [
            'nazev' => ['typ' => 'text', 'popisek' => 'Form name (in Enquiries and in the email)', 'vychozi' => t('Enquiry'), 'max' => 120],
            'pole' => ['typ' => 'polozky', 'popisek' => 'Form fields', 'max' => 20, 'pole' => [
                'popisek' => ['typ' => 'text', 'popisek' => 'Label', 'vychozi' => '', 'max' => 200],
                'typ' => ['typ' => 'vyber', 'popisek' => 'Typ', 'vychozi' => 'text', 'moznosti' => self::FIELD_TYPES],
                'povinne' => ['typ' => 'prepinac', 'popisek' => 'Required', 'vychozi' => false],
                'moznosti' => ['typ' => 'radky', 'popisek' => 'Choice options (one per line)', 'vychozi' => '', 'max' => 2000, 'kdyz' => ['typ' => 'vyber']],
                'moznosti_volby' => ['typ' => 'radky', 'popisek' => 'Options (one per line)', 'vychozi' => '', 'max' => 2000, 'kdyz' => ['typ' => 'volba']],
                'moznosti_zaskrtnuti' => ['typ' => 'radky', 'popisek' => 'Options to tick (one per line)', 'vychozi' => '', 'max' => 2000, 'kdyz' => ['typ' => 'zaskrtnuti']],
                'hodnota' => ['typ' => 'text', 'popisek' => 'Value sent with the form (not shown to the visitor)', 'vychozi' => '', 'max' => 300, 'kdyz' => ['typ' => 'skryte']],
            ], 'vychozi' => [
                ['popisek' => t('Jméno'), 'typ' => 'text', 'povinne' => true, 'moznosti' => ''],
                ['popisek' => t('Email'), 'typ' => 'email', 'povinne' => true, 'moznosti' => ''],
                ['popisek' => t('Phone'), 'typ' => 'tel', 'povinne' => false, 'moznosti' => ''],
                ['popisek' => t('How can we help you?'), 'typ' => 'textarea', 'povinne' => true, 'moznosti' => ''],
                ['popisek' => t('I agree to the processing of my personal data for the purpose of handling this enquiry.'), 'typ' => 'souhlas', 'povinne' => true, 'moznosti' => ''],
            ]],
            'tlacitko' => ['typ' => 'text', 'popisek' => 'Button text', 'vychozi' => t('Send enquiry'), 'max' => 80],
            'dekujeme' => ['typ' => 'text', 'popisek' => 'Thank-you message', 'vychozi' => t('Thank you, we have received your message. We will get back to you as soon as possible.'), 'max' => 400],
            'prijemce' => ['typ' => 'text', 'popisek' => 'Notification email (empty = site email from Settings)', 'vychozi' => '', 'max' => 190],
            'dekovna' => ['typ' => 'odkaz', 'popisek' => 'After sending, go to a page (empty = thank-you message in place of the form)', 'vychozi' => ''],
            'potvrzeni' => ['typ' => 'prepinac', 'popisek' => 'Send the sender a confirmation e-mail (thank-you only, without the message content)', 'vychozi' => false],
            'bez_captcha' => ['typ' => 'prepinac', 'popisek' => 'Without the extra spam check (CAPTCHA from Settings → Privacy and cookies)', 'vychozi' => false],
        ];
    }

    public static function baseCss(): string
    {
        // the anchor after sending points to the form: an offset so that the heading above it is visible too and the sticky header does not cover it
        return '.ka-formular { display: grid; gap: var(--ka-mezera-s); }
.ka-formular, .ka-formular-hotovo { scroll-margin-top: 6rem; }
.ka-pole { display: grid; gap: var(--ka-mezera-2xs); margin: 0; }
.ka-pole > label { font-weight: 600; }
.ka-pole input:not([type="checkbox"]), .ka-pole select, .ka-pole textarea { box-sizing: border-box; width: 100%; padding: 0.7em 0.9em; border: 1px solid var(--ka-barva-linka); border-radius: var(--ka-zaobleni); background: var(--ka-barva-pozadi); color: var(--ka-barva-text); font: inherit; }
.ka-pole textarea { min-height: 8em; resize: vertical; }
.ka-pole :focus-visible { outline: 2px solid var(--ka-barva-primarni); outline-offset: 1px; }
.ka-pole-souhlas label { display: flex; gap: var(--ka-mezera-xs); align-items: flex-start; }
.ka-pole-souhlas input { margin-block-start: 0.3em; accent-color: var(--ka-barva-primarni); }
.ka-pole-zasady { display: inline-block; margin-inline-start: 1.6em; font-size: var(--ka-krok--1); }
.ka-povinne { color: var(--ka-barva-primarni); }
.ka-pole fieldset { display: grid; gap: var(--ka-mezera-2xs); margin: 0; padding: 0; border: 0; }
.ka-pole legend { margin-block-end: var(--ka-mezera-2xs); padding: 0; font-weight: 600; }
.ka-pole fieldset label { display: flex; gap: var(--ka-mezera-xs); align-items: center; font-weight: 400; }
.ka-pole fieldset input { accent-color: var(--ka-barva-primarni); }
.ka-pole [aria-invalid="true"] { border-color: #c4281c !important; }
.ka-pole-napoveda { color: var(--ka-barva-tlumeny); font-size: var(--ka-krok--1); }
.ka-pole-chyba { color: color-mix(in oklch, #c4281c 80%, var(--ka-barva-text)); font-size: var(--ka-krok--1); }
.ka-formular-hotovo, .ka-formular-chyba { margin: 0; padding: var(--ka-mezera-m); border-radius: var(--ka-zaobleni); }
.ka-formular-hotovo { background: var(--ka-barva-primarni-jemna); color: var(--ka-barva-text); }
.ka-formular-chyba { background: color-mix(in oklch, #c4281c 12%, var(--ka-barva-pozadi)); color: color-mix(in oklch, #c4281c 80%, var(--ka-barva-text)); }';
    }

    /** Form anchor (where the page returns after sending): the same as the id the form gets when rendered. */
    public static function anchor(array $p): string
    {
        return $p['kotva'] ?? (!empty($p['styl']) ? 's-' . $p['id'] : 'formular-' . $p['id']);
    }

    /** The CAPTCHA widget when the site has one (2.6); in the editor only a note, the provider's script does not load there. */
    public static function captcha(Context $k): string
    {
        if (\Kaleta\Core\Captcha::provider($k->app->settings()) === null) {
            return '';
        }

        return $k->editor ? '<p class="ka-pole ka-captcha"><small>' . e(t('CAPTCHA is checked here when the form is sent.')) . '</small></p>'
            : '<div class="ka-pole">' . \Kaleta\Core\Captcha::widget($k->app->settings()) . '</div>';
    }

    /** Message after sending by the code in the url (?formular=<id>&vysledek=<code>) – the text never comes from the url. */
    public static function messages(string $code): string
    {
        return match ($code) {
            'pole' => t('Please check the highlighted field.'),
            'limit' => t('Too many messages have come from your address in a short time. Please try again later.'),
            'rychle' => t('The form was sent before we could check that a person is sending it. Please wait a moment and send it again.'),
            'overeni' => t('The form could not be verified. Reload the page and try again.'),
            'captcha' => t('Please confirm that you are not a robot and send the form again.'),
            default => t('The message could not be sent. Please try again.'),
        };
    }

    public static function render(array $p, string $a, string $children, Context $k): string
    {
        $o = $p['obsah'];
        $r = $k->app->request;
        $result = $r->get('formular') === $p['id'] ? $r->get('vysledek') : '';
        $id = str_contains($a, ' id="') ? '' : ' id="' . e(self::anchor($p)) . '"';
        if ($result === 'ok') {
            // data-odeslano: image/web.js reports the conversion (the kaleta:odeslano event and dataLayer, when the site has it)
            return '<div' . Text::withClass($a, 'ka-formular-hotovo') . $id . ' role="status" data-odeslano="' . e($o['nazev']) . '"><p>' . e($o['dekujeme']) . '</p></div>';
        }
        $k->types['tlacitko'] = true; // the form button looks like the Button element
        $html = $result !== '' ? '<p class="ka-formular-chyba" role="alert">' . e(self::messages($result)) . '</p>' : '';
        $invalid = $result === 'pole' ? $r->getInt('pole', -1) : -1;
        foreach ($o['pole'] as $i => $field) {
            if ($field['typ'] === 'skryte') {
                // the value is the form's own (Front\Forms) and the visitor normally neither sees nor sends it; on a collection item
                // page it may have been filled from the item ({{nazev}} in a job's template, 2.11), so there it travels with the form –
                // the server takes it only when its own value is a placeholder, and only as short plain text
                $html .= $k->item !== null && (string) ($field['hodnota'] ?? '') !== '' ? '<input type="hidden" name="p' . $i . '" value="' . e((string) $field['hodnota']) . '">' : '';
                continue;
            }
            $html .= self::fields($field, $i, $p['id'], $i === $invalid, $k->app->settings()->get('cookies_policy_url'));
        }
        $antispam = new Antispam($k->app->db(), $k->app->settings());

        // data-formular: after an error image/web.js puts back into the fields what the visitor filled in (only their browser keeps it)
        $files = in_array('soubor', array_column($o['pole'], 'typ'), true) ? ' enctype="multipart/form-data"' : '';

        return '<form' . Text::withClass($a, 'ka-formular') . $id . ' method="post" action="' . e($k->url('formular')) . '"' . $files . ' data-formular="' . e($p['id']) . '"' . ($result !== '' ? ' data-obnovit' : '') . '>'
            . '<input type="hidden" name="zdroj" value="' . e($k->source) . '"><input type="hidden" name="prvek" value="' . e($p['id']) . '">'
            . '<input type="hidden" name="zpet" value="' . e($k->app->url($r->path())) . '">' . \Kaleta\Front\Forms::ATTRIBUTION_FIELDS
            . $antispam->fields('formular|' . $k->source . '|' . $p['id'])
            . $html
            . (empty($o['bez_captcha']) ? self::captcha($k) : '')
            . '<p class="ka-pole"><button class="ka-tlacitko ka-tlacitko--primarni" type="submit">' . e($o['tlacitko']) . '</button></p></form>';
    }

    private static function fields(array $field, int $i, string $element, bool $error = false, string $privacyPolicy = ''): string
    {
        $id = 'f-' . $element . '-' . $i;
        $displayName = 'p' . $i;
        $required = $field['povinne'] ? ' required' : '';
        $star = $field['povinne'] ? ' <span class="ka-povinne" aria-hidden="true">*</span>' : '';
        $labelText = e($field['popisek']);
        // a field the server rejected: marked and with a message that aria-describedby points to
        $marking = $error ? ' aria-invalid="true" aria-describedby="' . $id . '-chyba" autofocus' : '';
        $message = $error ? '<span class="ka-pole-chyba" id="' . $id . '-chyba">' . e($field['typ'] === 'email' ? t('Enter a valid e-mail address.') : t('Please fill in this field correctly.')) . '</span>' : '';
        if ($field['typ'] === 'souhlas') {
            $link = $privacyPolicy !== '' ? ' <a class="ka-pole-zasady" href="' . e($privacyPolicy) . '" target="_blank">' . e(t('Privacy policy')) . '</a>' : '';

            return '<p class="ka-pole ka-pole-souhlas"><label><input type="checkbox" name="' . $displayName . '" value="1"' . $required . $marking . '> <span>' . $labelText . $star . '</span></label>' . $link . $message . '</p>';
        }
        if ($field['typ'] === 'skryte') {
            return ''; // the value is the form's own (Front\Forms), the visitor neither sees nor sends it
        }
        if ($field['typ'] === 'zaskrtnuti') {
            $options = '';
            foreach (self::options($field) as $m) {
                $options .= '<label><input type="checkbox" name="' . $displayName . '[]" value="' . e($m) . '"' . $marking . '> ' . e($m) . '</label>';
            }
            // required = at least one ticked; the browser cannot say that about a group, the server does (Front\Forms)
            return '<div class="ka-pole ka-pole-zaskrtnuti"><fieldset' . ($field['povinne'] ? ' aria-required="true"' : '') . '><legend>' . $labelText . $star . '</legend>' . $options . '</fieldset>' . $message . '</div>';
        }
        if ($field['typ'] === 'volba') {
            $options = '';
            foreach (self::options($field) as $j => $m) {
                $options .= '<label><input type="radio" name="' . $displayName . '" value="' . e($m) . '"' . ($j === 0 ? $required . $marking : '') . '> ' . e($m) . '</label>';
            }

            return '<div class="ka-pole"><fieldset><legend>' . $labelText . $star . '</legend>' . $options . '</fieldset>' . $message . '</div>';
        }
        $label = '<label for="' . $id . '">' . $labelText . $star . '</label>';
        $input = match ($field['typ']) {
            'textarea' => '<textarea id="' . $id . '" name="' . $displayName . '" maxlength="5000"' . $required . $marking . '></textarea>',
            'vyber' => '<select id="' . $id . '" name="' . $displayName . '"' . $required . $marking . '><option value="">' . e(t('— choose —')) . '</option>'
                . implode('', array_map(fn (string $m): string => '<option>' . e($m) . '</option>', self::options($field))) . '</select>',
            'datum' => '<input id="' . $id . '" name="' . $displayName . '" type="date"' . $required . $marking . '>',
            'cislo' => '<input id="' . $id . '" name="' . $displayName . '" type="number" step="any" inputmode="decimal"' . $required . $marking . '>',
            'soubor' => '<input id="' . $id . '" name="' . $displayName . '" type="file" accept=".' . implode(',.', self::ATTACHMENT_EXTENSIONS) . '"' . $required . $marking . '>'
                . '<small class="ka-pole-napoveda">' . e(t('Up to %d MB: PDF, image, document or ZIP.', (int) (self::MAX_ATTACHMENT / 1048576))) . '</small>',
            // phone: the same rule as on the server (Front\Forms), the browser checks it right away; the pattern is valid with the v flag too
            'tel' => '<input id="' . $id . '" name="' . $displayName . '" type="tel" autocomplete="tel" maxlength="30" pattern="' . self::PHONE_PATTERN . '" title="' . e(t('Phone number, for example +44 20 7946 0958.')) . '"' . $required . $marking . '>',
            default => '<input id="' . $id . '" name="' . $displayName . '" type="' . ($field['typ'] === 'email' ? 'email" autocomplete="email' : 'text' . self::autocomplete($field['popisek'])) . '" maxlength="300"' . $required . $marking . '>',
        };

        return '<p class="ka-pole">' . $label . $input . $message . '</p>';
    }

    /**
     * Autocomplete of a text field by its label (WCAG 1.3.5): name and company. The field type stays "text",
     * so that forms built earlier keep working.
     */
    private static function autocomplete(string $labelText): string
    {
        return match (true) {
            (bool) preg_match('/^(vaše |celé |your |full )?(jméno|name)\b/iu', trim($labelText)) => '" autocomplete="name',
            (bool) preg_match('/^(firma|společnost|název firmy|company|organi[sz]ation)\b/iu', trim($labelText)) => '" autocomplete="organization',
            default => '',
        };
    }

    /** @return list<string> options of a select or radio buttons */
    public static function options(array $field): array
    {
        $text = match ($field['typ'] ?? '') {
            'volba' => (string) ($field['moznosti_volby'] ?? ''),
            'zaskrtnuti' => (string) ($field['moznosti_zaskrtnuti'] ?? ''),
            default => (string) ($field['moznosti'] ?? ''),
        };

        return array_values(array_filter(array_map('trim', explode("\n", $text)), fn (string $m): bool => $m !== ''));
    }
}
