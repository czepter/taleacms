<?php

declare(strict_types=1);

namespace Talea\Front;

use Talea\Builder\Context;
use Talea\Builder\Elements\Text;
use Talea\Builder\Elements\Form;
use Talea\Builder\FormLogic;
use Talea\Core\App;
use Talea\Core\Preview;

/**
 * The result of a form that shows it after the contact details (calculation "after_contact"). The visitor comes back to the
 * form's page by a signed link (24 hours) that names the stored enquiry and the result variant; the screen is built from the
 * enquiry's stored estimate and breakdown, never from the request, and shows nothing the visitor typed. A link that does not
 * verify, an enquiry that is gone, deleted or anonymised, or a variant the form no longer has gives a short "no longer available".
 */
final class FormResult
{
    private const int MINUTES = 1440;

    /** The link back to the form's page that shows the result of an enquiry. */
    public static function url(App $app, string $back, array $element, string $enquiryPublicId, string $variant): string
    {
        $key = Preview::key($app->db(), $app->settings(), self::target($enquiryPublicId, $variant), self::MINUTES);

        return $back . '?form=' . rawurlencode($element['id']) . '&result=ok&enquiry=' . $enquiryPublicId . '&variant=' . rawurlencode($variant) . '&key=' . $key . '#' . Form::anchor($element);
    }

    /** The result screen for this request, or null when the request carries no result link. */
    public static function html(App $app, array $p, Context $k, string $a = ''): ?string
    {
        $r = $app->request;
        if ($r->get('form') !== $p['id'] || $r->get('result') !== 'ok' || $r->get('enquiry') === '') {
            return null;
        }
        $o = $p['content'];
        $id = str_contains($a, ' id="') ? '' : ' id="' . e(Form::anchor($p)) . '"';
        $data = self::stored($app, $p, $k->source, $r->get('enquiry'), $r->get('variant'), $r->get('key'));
        if ($data === null) {
            $k->types['button'] = true;

            return '<div' . Text::withClass($a, 'tl-form-done') . $id . ' role="status"><p>' . e(t('This result is no longer available.')) . '</p><p><a class="tl-button tl-button--primary" href="'
                . e($app->url($r->path()) . '#' . Form::anchor($p)) . '">' . e($o['button_text']) . '</a></p></div>';
        }
        [$shown, $lines, $variant] = $data;
        $amount = $variant === null || $variant['show_amount'];
        $html = '';
        if ($variant !== null) {
            $image = $variant['image'] !== '' ? '<img class="tl-result-image" src="' . e($k->image($variant['image'])) . '" alt="" loading="lazy">' : '';
            $link = $variant['button_text'] !== '' && $variant['button_link'] !== '' ? '<p><a class="tl-button tl-button--primary" href="' . e($variant['button_link']) . '">' . e($variant['button_text']) . '</a></p>' : '';
            $k->types['button'] = true;
            $html .= $image . ($variant['heading'] !== '' ? '<p class="tl-result-heading"><strong>' . e($variant['heading']) . '</strong></p>' : '')
                . ($variant['text'] !== '' ? '<p>' . e($variant['text']) . '</p>' : '');
            $tail = $link;
        } else {
            $html .= '<p>' . e($o['thank_you']) . '</p>';
            $tail = NextSteps::html($app, $o);
        }
        if ($amount) {
            $html .= '<p class="tl-result-amount"><strong>' . e($shown) . '</strong></p>'
                . ($lines === [] ? '' : '<ul class="tl-result-breakdown">' . implode('', array_map(fn (string $l): string => '<li><span>' . e(substr($l, 0, (int) strrpos($l, ': '))) . '</span><span>' . e(substr($l, (int) strrpos($l, ': ') + 2)) . '</span></li>', $lines)) . '</ul>');
        }

        // data-sent: image/web.js reports the conversion, as for the plain thank-you
        return '<div' . Text::withClass($a, 'tl-form-done tl-form-result') . $id . ' role="status" data-sent="' . e($o['name']) . '">' . $html . $tail . '</div>';
    }

    /**
     * What goes into the confirmation e-mail: the range or the variant heading, the options the visitor chose among the
     * predefined ones and the breakdown. No free text, not even the name.
     *
     * @param array<string, mixed> $content
     * @param array<int, string|list<string>> $answers by field index
     * @param array<string, mixed> $evaluation FormLogic::evaluate()
     */
    public static function mailBlock(array $content, array $answers, array $evaluation): string
    {
        $variant = self::variant($content, (string) $evaluation['variant']);
        $out = [];
        if ($variant !== null && $variant['heading'] !== '') {
            $out[] = $variant['heading'];
        }
        if ($variant === null || $variant['show_amount']) {
            $out[] = (string) $evaluation['shown'];
        }
        foreach ($content['fields'] ?? [] as $i => $f) {
            if (in_array($f['type'], ['radio', 'select', 'checkboxes'], true) && ($evaluation['visible'][$i] ?? true)) {
                $chosen = array_values(array_filter((array) ($answers[$i] ?? []), fn (string $a): bool => in_array($a, Form::options($f), true)));
                if ($chosen !== []) {
                    $out[] = $f['label'] . ': ' . implode(', ', $chosen);
                }
            }
        }
        if (($variant === null || $variant['show_amount']) && $evaluation['lines'] !== []) {
            $out[] = '';
            $out[] = t('Breakdown') . ':';
            array_push($out, ...self::lines($content, $evaluation['lines']));
        }

        return implode("\n", $out);
    }

    /**
     * The breakdown as lines of text: "Label: +1,200 €".
     *
     * @param array<string, mixed> $content
     * @param list<array{0: string, 1: float}> $lines
     * @return list<string>
     */
    public static function lines(array $content, array $lines): array
    {
        $estimate = [];
        foreach ($content['fields'] ?? [] as $f) {
            if ($f['type'] === 'estimate') {
                $estimate = $f;
                break;
            }
        }
        $suffix = mb_substr(trim((string) (($estimate['unit_suffix'] ?? '') !== '' ? $estimate['unit_suffix'] : ($estimate['currency'] ?? ''))), 0, 10);
        $prefix = mb_substr(trim((string) ($estimate['unit_prefix'] ?? '')), 0, 10);

        return array_map(fn (array $l): string => $l[0] . ': ' . ($l[1] < 0 ? '-' : '+') . Form::money(abs($l[1]), $suffix, $prefix), $lines);
    }

    /**
     * The estimate text, the breakdown lines and the variant from the enquiry the verified link names, or null when anything
     * about it is not right.
     *
     * @return array{0: string, 1: list<string>, 2: array<string, mixed>|null}|null
     */
    private static function stored(App $app, array $p, string $printedSource, string $uuid, string $variantKey, string $key): ?array
    {
        $db = $app->db();
        if (!Preview::verify($db, $app->settings(), self::target($uuid, $variantKey), $key)) {
            return null;
        }
        $row = $db->byPublicId('enquiries', $uuid);
        $source = Forms::internalSource($db, $printedSource);
        if ($row === null || $row['deleted_at'] !== null || $row['anonymised_at'] !== null || (string) $row['element'] !== (string) $p['id'] || $source === '' || (string) $row['source'] !== $source) {
            return null;
        }
        $variant = null;
        if ($variantKey !== '') {
            $variant = self::variant($p['content'], $variantKey);
            if ($variant === null) {
                return null;
            }
        }
        $pairs = json_decode((string) $row['data'], true);
        $label = '';
        foreach ($p['content']['fields'] as $f) {
            if ($f['type'] === 'estimate') {
                $label = (string) $f['label'];
                break;
            }
        }
        foreach (is_array($pairs) ? $pairs : [] as $n => $pair) {
            if (($pair[0] ?? null) === $label) {
                $text = (string) ($pair[1] ?? '');
                $shown = $variant !== null && ($at = strrpos($text, "\n")) !== false ? substr($text, $at + 1) : $text;
                $next = $pairs[$n + 1] ?? null;
                $lines = is_array($next) && ($next[0] ?? null) === t('Breakdown') ? array_values(array_filter(explode("\n", (string) $next[1]), fn (string $l): bool => $l !== '')) : [];

                return [$shown, $lines, $variant];
            }
        }

        return null;
    }

    /** The result variant of a form by key (all its parts present), or null. @return array<string, mixed>|null */
    public static function variant(array $content, string $key): ?array
    {
        foreach ($content['logic']['results'] ?? [] as $v) {
            if (($v['key'] ?? null) === $key && $key !== '') {
                return $v + ['heading' => '', 'text' => '', 'show_amount' => false, 'button_text' => '', 'button_link' => '', 'image' => ''];
            }
        }

        return null;
    }

    private static function target(string $uuid, string $variant): string
    {
        return 'form-result:' . $uuid . ':' . $variant;
    }
}
