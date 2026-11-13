<?php

declare(strict_types=1);

namespace Talea\Builder;

use Dom\Element;
use Dom\HTMLDocument;
use Dom\Node;

/**
 * Converting HTML to a build: the language model (or an import) writes ordinary semantic HTML with a <style> block and a clean
 * build results from it – one element per tag, the appearance in shared classes; <form> becomes a Form element. What cannot be
 * converted (scripts, inline styles, complex selectors) is left out and reported, so that the author knows what to add in the builder.
 *
 * The class is pure (no database): it returns the build, the classes from <style> and the messages. The caller does saving and permission checks.
 */
final class HtmlConverter
{
    /** Tags converted to the content of a Text element (a continuous flow of text is merged into one element). */
    private const array TEXT_TAGS = ['p', 'ul', 'ol', 'table', 'pre', 'dl', 'address'];

    /** Wrapper tags without their own meaning for the build – only their content is converted. */
    private const array UNWRAP = ['html', 'body', 'main'];

    /** Tags that have no counterpart in the build and are always left out. */
    private const array SKIP = ['script', 'noscript', 'style', 'link', 'meta', 'template', 'input', 'select', 'textarea', 'button', 'label', 'canvas', 'object', 'embed'];

    /** @var list<string> */
    private array $messages = [];

    /** @var array<string, string> class => safe declarations */
    private array $classes = [];

    /** @var array<string, array<string, array<string, string>>> class => state (tablet, mobile, hover…) => style properties */
    private array $classStyles = [];

    /** @param list<array{kind: string, html: string, host: string}> $widgets the widgets an import took out of the old page (Builder\HtmlWidgets): a <talea-widget title="N"> marker stands for the Nth */
    private function __construct(private readonly bool $admin, private readonly array $widgets = [])
    {
    }

    /**
     * @param bool $admin a Custom HTML element may be created (for SVG and embedded maps)
     * @param list<array{kind: string, html: string, host: string}> $widgets the widgets behind the markers of an imported page
     * @return array{build: array<string, mixed>, classes: array<string, string>, class_styles: array<string, array<string, array<string, string>>>, notes: list<string>}
     */
    public static function convert(string $html, bool $admin = false, array $widgets = []): array
    {
        $conversion = new self($admin, $widgets);
        $document = HTMLDocument::createFromString('<!doctype html><html><body>' . $html . '</body></html>', LIBXML_NOERROR);
        foreach ($document->querySelectorAll('style') as $style) {
            $conversion->styles($style->textContent);
        }
        $elements = $conversion->children($document->body);

        // sections make up the top level of the build: continuous runs of other elements are wrapped into one section
        $root = [];
        $sequence = [];
        foreach ($elements as $p) {
            if ($p['type'] === 'section') {
                if ($sequence !== []) {
                    $root[] = Build::fresh('section', [], $sequence);
                    $sequence = [];
                }
                $root[] = $p;
            } else {
                $sequence[] = $p;
            }
        }
        if ($sequence !== []) {
            $root[] = Build::fresh('section', [], $sequence);
        }

        return ['build' => ['v' => Build::VERSION, 'children' => $root], 'classes' => $conversion->classes, 'class_styles' => $conversion->classStyles,
            'notes' => array_values(array_unique($conversion->messages))];
    }

    /**
     * HTML from the language model (MCP, the assistant in the builder) into the site: conversion, saving new classes from <style>
     * (an existing class of the site is overwritten only with $overwrite) and removing classes without a style.
     *
     * @return array{build: array<string, mixed>, notes: list<string>}
     */
    public static function saveToSite(\Talea\Core\Db $db, string $html, bool $admin, bool $overwrite = false, ?\Talea\Core\Settings $settings = null): array
    {
        $conversion = self::convert($html, $admin);
        $messages = $conversion['notes'];
        $existing = array_column($db->all('SELECT name FROM {classes}'), 'name');
        foreach (array_unique(array_merge(array_keys($conversion['classes']), array_keys($conversion['class_styles']))) as $className) {
            if (\Talea\Core\LookFamily::classRefusal($db, $className, false) !== null) {
                $messages[] = 'The class .' . $className . ' comes from the parent of this site\'s look family – left unchanged.';
                continue;
            }
            if (in_array($className, $existing, true) && !$overwrite) {
                $messages[] = 'The class .' . $className . ' already exists on the site – left unchanged.';
                continue;
            }
            if (in_array($className, $existing, true) && $settings !== null) {
                // a change of a class the site has goes to the draft look (Core\Look)
                \Talea\Core\Look::setClass($settings, $className, ['style' => $conversion['class_styles'][$className] ?? [], 'css' => $conversion['classes'][$className] ?? '']);
                $messages[] = 'Class .' . $className . ' changed in the draft look – the site shows it after publish_look.';
                continue;
            }
            $style = (string) json_encode($conversion['class_styles'][$className] ?? new \stdClass(), JSON_UNESCAPED_UNICODE);
            $db->upsert('classes', ['name' => $className, 'style' => $style, 'css' => $conversion['classes'][$className] ?? '', 'updated_at' => date('Y-m-d H:i:s')], ['name']);
        }
        $skipped = [];
        $build = self::withoutClasses($conversion['build'], array_merge($existing, array_keys($conversion['classes']), array_keys($conversion['class_styles'])), $skipped);
        if ($skipped !== []) {
            $messages[] = 'Classes without a style left out: ' . implode(', ', array_unique($skipped)) . '.';
        }

        return ['build' => $build, 'notes' => $messages];
    }

    /**
     * Removes classes that have no style (from third-party CSS frameworks, WordPress…) – they would only take up space.
     *
     * @param list<string> $known classes that have a style
     * @param list<string> $skipped the removed ones are written here
     */
    public static function withoutClasses(array $node, array $known, array &$skipped = []): array
    {
        foreach ($node['children'] ?? [] as $i => $p) {
            if (isset($p['classes'])) {
                $skipped = array_merge($skipped, array_diff($p['classes'], $known));
                $p['classes'] = array_values(array_intersect($p['classes'], $known));
                if ($p['classes'] === []) {
                    unset($p['classes']);
                }
            }
            $node['children'][$i] = self::withoutClasses($p, $known, $skipped);
        }

        return $node;
    }

    /** @return list<array<string, mixed>> */
    private function children(Node $parent, int $depth = 0): array
    {
        $output = [];
        $flow = '';          // continuous text (paragraphs, lists) waiting to be merged into one Text element
        $questions = [];       // continuous <details> waiting to be merged into an FAQ element
        $flushFlow = function () use (&$output, &$flow, &$questions): void {
            if (trim(strip_tags($flow, '<img>')) !== '') {
                $output[] = Build::fresh('text', ['html' => $flow]);
            }
            $flow = '';
            if ($questions !== []) {
                $output[] = Build::fresh('faq', ['items' => $questions]);
                $questions = [];
            }
        };

        foreach (iterator_to_array($parent->childNodes) as $node) {
            if ($node->parentNode === null) {
                continue; // a tab panel taken into a Tabs element
            }
            if ($node->nodeType === XML_TEXT_NODE) {
                if (trim($node->textContent) !== '') {
                    $flow .= '<p>' . e(trim($node->textContent)) . '</p>';
                }
                continue;
            }
            if (!$node instanceof Element) {
                continue;
            }
            $htmlTag = strtolower($node->localName);
            if (strtolower((string) $node->getAttribute('role')) === 'tablist' && ($tabs = $this->tabs($node, $parent)) !== null) {
                $flushFlow();
                $output[] = $tabs;
                continue;
            }
            if ($htmlTag === 'details' && ($question = $node->querySelector('summary')) !== null) {
                if ($flow !== '') {
                    $output[] = Build::fresh('text', ['html' => $flow]);
                    $flow = '';
                }
                $response = $node->cloneNode(true);
                $response->querySelector('summary')?->remove();
                $questions[] = ['question' => trim($question->textContent), 'answer' => trim($response->innerHTML)];
                continue;
            }
            // a paragraph without a class joins the continuous text; with a class it is a separate element, so that the class has a place
            if (in_array($htmlTag, self::TEXT_TAGS, true) && $this->classesOf($node) === [] && !$this->hasBlocks($node)) {
                if ($questions !== []) {
                    $flushFlow();
                }
                $flow .= self::html($node);
                continue;
            }
            $flushFlow();
            foreach ($this->element($node, $depth) as $p) {
                $output[] = $p;
            }
        }
        $flushFlow();

        return $output;
    }

    /** @return list<array<string, mixed>> one element, several elements (an unwrapped wrapper) or nothing */
    private function element(Element $el, int $depth): array
    {
        $htmlTag = strtolower($el->localName);
        if ($htmlTag === 'talea-widget') {
            return $this->widget($el, $depth);
        }
        if (in_array($htmlTag, self::UNWRAP, true)) {
            return $this->children($el, $depth);
        }
        if (in_array($htmlTag, self::SKIP, true)) {
            if ($htmlTag !== 'style') {
                $this->messages[] = 'The tag <' . $htmlTag . '> outside a form has no counterpart in the build – left out.';
            }

            return [];
        }
        if ($el->hasAttribute('style')) {
            $this->messages[] = 'Inline styles (the style attribute) are not converted – put the look into classes in <style>.';
        }
        if ($depth >= Build::MAX_DEPTH - 1) {
            $this->messages[] = 'Nesting is too deep – the deepest part was converted as text.';

            return [Build::fresh('text', ['html' => self::html($el)])];
        }

        $p = match (true) {
            $htmlTag === 'div' && ($counter = $this->counter($el)) !== null => $counter,
            in_array($htmlTag, ['section', 'header', 'footer', 'aside', 'article', 'nav', 'div', 'li'], true) => $this->wrapper($el, $htmlTag, $depth),
            (bool) preg_match('/^h[1-6]$/', $htmlTag) => ['tag' => $htmlTag] + Build::fresh('heading', ['text' => trim($el->innerHTML)]),
            in_array($htmlTag, self::TEXT_TAGS, true) => $this->textOrWrapper($el, $htmlTag, $depth),
            $htmlTag === 'img' => Build::fresh('image', ['src' => $el->getAttribute('src') ?? '', 'alt' => $el->getAttribute('alt') ?? '']),
            $htmlTag === 'figure' => $this->figure($el, $depth),
            $htmlTag === 'a' => $this->link($el, $depth),
            $htmlTag === 'blockquote' => $this->quote($el),
            $htmlTag === 'hr' => Build::fresh('divider'),
            $htmlTag === 'form' => $this->form($el),
            in_array($htmlTag, ['iframe', 'video'], true) && ($video = $this->video($el)) !== null => $video,
            $htmlTag === 'iframe' && ($frame = $this->mapOrEmbed($el)) !== false => $frame, // null: a map that could not be read (noted)
            in_array($htmlTag, ['svg', 'iframe', 'video', 'picture', 'audio'], true) => $this->customHtml($el),
            default => Build::fresh('text', ['html' => '<p>' . trim($el->innerHTML) . '</p>']),
        };
        if ($p === null) {
            return [];
        }
        if (($classes = $this->classesOf($el)) !== []) {
            $p['classes'] = $classes;
            if (array_intersect($classes, array_keys($this->classes + $this->classStyles)) !== []) {
                // the appearance comes from the class in <style>: the element's default style (container flex, section padding) would override it – the elements layer comes after the classes in the cascade
                $p['style'] = [];
            }
        }
        if (($id = $el->getAttribute('id')) !== null && preg_match('/^[a-z][a-z0-9-]{0,40}$/', $id)) {
            $p['anchor'] = $id;
        }

        return [$p];
    }

    /** The elements of an imported page's widget: its normalised HTML converted like any other HTML (the notes of the conversion are shared). */
    private function widget(Element $marker, int $depth): array
    {
        $widget = $this->widgets[(int) $marker->getAttribute('title')] ?? null;
        if ($widget === null || $widget['html'] === '') {
            return [];
        }
        $document = HTMLDocument::createFromString('<!doctype html><html><body>' . $widget['html'] . '</body></html>', LIBXML_NOERROR);

        return $this->children($document->body, $depth);
    }

    /** section/div/… – at the top level a section (section, header, footer), elsewhere a container or grid. */
    private function wrapper(Element $el, string $htmlTag, int $depth): array
    {
        $children = $this->children($el, $depth + 1);
        if ($depth === 0 && in_array($htmlTag, ['section', 'header', 'footer', 'aside', 'article'], true)) {
            // the site's inner wrapper (.container, .wrapper) is needless in a section – the section has its own; it stays only when its class has a style
            if (count($children) === 1 && $children[0]['type'] === 'container' && array_intersect($children[0]['classes'] ?? [], array_keys($this->classes)) === []) {
                $children = $children[0]['children'];
            }

            return ['tag' => $htmlTag] + Build::fresh('section', [], $children);
        }
        $htmlTag = in_array($htmlTag, Elements\Container::HTML_TAGS, true) ? $htmlTag : 'div';

        return ['tag' => $htmlTag] + Build::fresh('container', [], $children);
    }

    /** A list with a class or with complex items (cards in <ul>) is a container, a simple list with a class is a List element. */
    private function textOrWrapper(Element $el, string $htmlTag, int $depth): array
    {
        if (in_array($htmlTag, ['ul', 'ol'], true)) {
            if (!$this->hasBlocks($el)) {
                $items = [];
                foreach ($el->children as $li) {
                    $items[] = trim($li->textContent);
                }

                return ['tag' => $htmlTag] + Build::fresh('list', ['items' => implode("\n", $items)]);
            }

            return ['tag' => 'ul'] + Build::fresh('container', [], array_map(
                fn (array $p): array => $p['type'] === 'container' ? ['tag' => 'li'] + $p : ['tag' => 'li'] + Build::fresh('container', [], [$p]),
                $this->children($el, $depth + 1),
            ));
        }

        return Build::fresh('text', ['html' => self::html($el)]);
    }

    private function figure(Element $el, int $depth): ?array
    {
        $image = $el->querySelector('img');
        if ($image === null && ($counter = $this->counter($el)) !== null) {
            return $counter;
        }
        if ($image === null) {
            return ['tag' => 'div'] + Build::fresh('container', [], $this->children($el, $depth + 1));
        }

        return Build::fresh('image', ['src' => $image->getAttribute('src') ?? '', 'alt' => $image->getAttribute('alt') ?? '', 'caption' => trim($el->querySelector('figcaption')?->textContent ?? '')]);
    }

    /** A link with block content (a card) is a link container, a standalone text link is a button. */
    private function link(Element $el, int $depth): array
    {
        $url = $el->getAttribute('href') ?? '';
        if ($this->hasBlocks($el) || $el->querySelector('img') !== null) {
            return ['tag' => 'div'] + Build::fresh('container', ['link' => $url], $this->children($el, $depth + 1));
        }
        $className = strtolower((string) $el->getAttribute('class'));
        $variant = match (true) {
            (bool) preg_match('/outline|obrys|ghost|secondary|sekundar/', $className) => 'outline',
            (bool) preg_match('/\blink\b|odkaz/', $className) => 'link',
            default => 'primary',
        };

        return Build::fresh('button', ['text' => trim($el->textContent), 'link' => $url, 'variant' => $variant, 'new_window' => $el->getAttribute('target') === '_blank']);
    }

    /** Form → Form element: fields by the form controls and their labels; it always sends to the site's Enquiries. */
    private function form(Element $el): array
    {
        $field = [];
        $radios = []; // groups of <input type="radio"> by name → one choice field
        $trapped = false;
        foreach ($el->querySelectorAll('input, select, textarea') as $input) {
            if (self::isSpamTrap($input, $el)) {
                $trapped = true;
                continue;
            }
            $type = strtolower((string) ($input->getAttribute('type') ?? 'text'));
            if (in_array($type, ['hidden', 'submit', 'button', 'reset', 'image', 'file', 'password'], true)) {
                if (in_array($type, ['file', 'password'], true)) {
                    $this->messages[] = 'The form does not support fields of type ' . $type . ' – left out.';
                }
                continue;
            }
            $labelText = $this->fieldLabel($el, $input);
            $required = $input->hasAttribute('required');
            if ($type === 'radio') {
                $displayName = (string) $input->getAttribute('name');
                if (!isset($radios[$displayName])) {
                    $radios[$displayName] = count($field);
                    $group = $input->closest('fieldset')?->querySelector('legend')?->textContent;
                    $field[] = ['label' => trim($group ?? $displayName), 'type' => 'select', 'required' => $required, 'options' => ''];
                }
                $field[$radios[$displayName]]['options'] = ltrim($field[$radios[$displayName]]['options'] . "\n" . $labelText);
                continue;
            }
            $field[] = match (true) {
                strtolower($input->localName) === 'textarea' => ['label' => $labelText, 'type' => 'textarea', 'required' => $required, 'options' => ''],
                strtolower($input->localName) === 'select' => ['label' => $labelText, 'type' => 'select', 'required' => $required, 'options' => implode("\n", array_filter(array_map(
                    fn (Element $o): string => ($o->getAttribute('value') ?? 'x') === '' ? '' : trim($o->textContent), iterator_to_array($input->querySelectorAll('option')),
                )))],
                $type === 'checkbox' => ['label' => $labelText, 'type' => 'checkbox', 'required' => $required, 'options' => ''],
                default => ['label' => $labelText, 'type' => in_array($type, ['email', 'tel'], true) ? $type : 'text', 'required' => $required, 'options' => ''],
            };
        }
        if ($trapped) {
            $this->messages[] = 'Spam traps and captcha fields were left out – Talea protects its forms itself.';
        }
        $button = $el->querySelector('button:not([type="button"]):not([type="reset"]), input[type="submit"]');
        $text = trim($button === null ? '' : ($button->localName === 'input' ? (string) $button->getAttribute('value') : $button->textContent));
        $this->messages[] = 'The form was converted to a Form element: it is sent to the site\'s Enquiries and by e-mail (the action address is not used).';

        return Build::fresh('form', array_filter(['fields' => array_slice($field, 0, 20), 'button_text' => $text], fn (mixed $v): bool => $v !== '' && $v !== []));
    }

    /** A honeypot (hidden from people, tab order and autofill) or a captcha field: a person never fills it in, Talea's own protection replaces it. */
    private static function isSpamTrap(Element $input, Element $form): bool
    {
        $name = strtolower((string) $input->getAttribute('name'));
        if (in_array($name, ['g-recaptcha-response', 'h-captcha-response', 'cf-turnstile-response'], true) || $input->closest('[data-sitekey]') !== null) {
            return true;
        }
        if ($input->getAttribute('tabindex') === '-1' && $input->getAttribute('autocomplete') === 'off') {
            return true;
        }
        for ($node = $input; $node instanceof Element && $node !== $form; $node = $node->parentElement) {
            $style = strtolower((string) $node->getAttribute('style'));
            if ($node->hasAttribute('hidden') || $node->getAttribute('aria-hidden') === 'true'
                || preg_match('/display\s*:\s*none|visibility\s*:\s*hidden/', $style) === 1) {
                return true;
            }
        }

        return false;
    }

    private function fieldLabel(Element $form, Element $input): string
    {
        $id = $input->getAttribute('id');
        $label = $id !== null && $id !== '' ? $form->querySelector('label[for="' . addcslashes($id, '"\\') . '"]') : null;
        $label ??= $input->closest('label');
        if ($label !== null) {
            $copy = $label->cloneNode(true);
            foreach ($copy->querySelectorAll('input, select, textarea') as $v) {
                $v->remove();
            }
            $text = trim((string) preg_replace('/\s+/', ' ', $copy->textContent));
            if ($text !== '') {
                return rtrim($text, ' *:');
            }
        }

        $firstOption = strtolower($input->localName) === 'select' ? $input->querySelector('option[value=""]')?->textContent : null;

        return trim((string) ($input->getAttribute('placeholder') ?? $input->getAttribute('aria-label') ?? $firstOption ?? $input->getAttribute('name') ?? ''));
    }

    private function quote(Element $el): array
    {
        $signature = $el->querySelector('footer, cite, figcaption');
        $author = trim($signature?->textContent ?? '');
        $signature?->remove();
        $text = trim(preg_replace('#</?p[^>]*>#', ' ', $el->innerHTML) ?? '');

        return Build::fresh('testimonial', ['text' => $text, 'author' => ltrim($author, "—–- \t")]);
    }

    private function video(Element $el): ?array
    {
        $src = $el->getAttribute('src') ?: $el->getAttribute('data-src') ?: $el->getAttribute('data-lazy-src') ?: $el->querySelector('source')?->getAttribute('src') ?: '';
        $title = $el->getAttribute('title') ?? '';
        if (preg_match('#(?:youtube\.com/embed/|youtube-nocookie\.com/embed/|youtu\.be/|youtube\.com/shorts/)([\w-]{6,})#', $src, $m) || preg_match('#youtube\.com/watch\?(?:[^"\s]*&)?v=([\w-]{6,})#', $src, $m)) {
            return Build::fresh('video', ['url' => 'https://www.youtube.com/watch?v=' . $m[1], 'title' => $title]);
        }
        if (preg_match('#(?:player\.vimeo\.com/video/|//vimeo\.com/)(\d+)#', $src, $m)) {
            return Build::fresh('video', ['url' => 'https://vimeo.com/' . $m[1], 'title' => $title]);
        }

        return null;
    }

    /**
     * A frame of a map service becomes a Map element, one of a known service (booking page, form, player) an Embed element.
     *
     * @return array<string, mixed>|false|null the element; null = a map frame whose address could not be read (noted); false = neither
     */
    private function mapOrEmbed(Element $el): array|false|null
    {
        $src = trim($el->getAttribute('src') ?: $el->getAttribute('data-src') ?: $el->getAttribute('data-lazy-src') ?: '');
        $map = self::mapFrame($src);
        if ($map !== null) {
            if ($map['address'] === '') {
                $this->messages[] = 'A map frame (' . preg_replace('/[^a-z0-9.-]/', '', strtolower((string) parse_url($src, PHP_URL_HOST))) . ') was not converted – add a Map element with the address.';

                return null;
            }

            return Build::fresh('map', ['address' => mb_substr($map['address'], 0, 200), 'zoom' => $map['zoom']]);
        }
        if ($src !== '' && Elements\Embed::resolve($src) !== null) {
            return Build::fresh('embed', ['address' => $src, 'title' => mb_substr($el->getAttribute('title') ?? '', 0, 120)]);
        }

        return false;
    }

    /**
     * What a map frame shows: the place's name or its coordinates ("lat, lng") and a zoom of the Map element. Google Maps, OpenStreetMap
     * and Mapy.com addresses; null = not a map frame, an empty address = a map frame that says nothing readable.
     *
     * @return array{address: string, zoom: string}|null
     */
    public static function mapFrame(string $src): ?array
    {
        $c = parse_url($src);
        $host = strtolower((string) ($c['host'] ?? ''));
        $path = (string) ($c['path'] ?? '');
        parse_str((string) ($c['query'] ?? ''), $q);
        $q = array_map(fn (mixed $v): string => is_string($v) ? $v : '', $q);
        $number = fn (string $v): string => rtrim(rtrim(sprintf('%.6f', (float) $v), '0'), '.');
        $zoom = function (string $z): string {
            $best = '15';
            $distance = 99.0;
            foreach (['11', '13', '15', '17'] as $step) {
                if (is_numeric($z) && abs((float) $z - (int) $step) < $distance) {
                    $best = $step;
                    $distance = abs((float) $z - (int) $step);
                }
            }

            return $best;
        };
        $address = '';
        if (preg_match('/(^|\.)google\.[a-z.]+$/', $host) && str_starts_with($path, '/maps')) {
            $address = trim((string) ($q['q'] ?? $q['query'] ?? ''));
            if ($address === '' && ($pb = (string) ($q['pb'] ?? '')) !== '') {
                $name = rawurldecode(str_replace('+', ' ', preg_match('/!2s([^!]+)/', $pb, $m) ? $m[1] : ''));
                if (strlen($name) >= 4 && preg_match('/\pL/u', $name) === 1 && preg_match('/^[a-z]{2}(-[A-Za-z]{2,4})?$/', $name) !== 1) {
                    $address = $name;
                } elseif (preg_match('/!2d(-?\d+(?:\.\d+)?)/', $pb, $lng) && preg_match('/!3d(-?\d+(?:\.\d+)?)/', $pb, $lat)) {
                    $address = $number($lat[1]) . ', ' . $number($lng[1]);
                }
            }

            return ['address' => $address, 'zoom' => $zoom((string) ($q['z'] ?? ''))];
        }
        if (preg_match('/(^|\.)openstreetmap\.org$/', $host) && str_contains($path, '/export/embed')) {
            if (preg_match('/^(-?\d+(?:\.\d+)?),(-?\d+(?:\.\d+)?)$/', (string) ($q['marker'] ?? ''), $m)) {
                $address = $number($m[1]) . ', ' . $number($m[2]);
            } elseif (preg_match('/^(-?[\d.]+),(-?[\d.]+),(-?[\d.]+),(-?[\d.]+)$/', (string) ($q['bbox'] ?? ''), $m)) {
                $address = $number((string) (((float) $m[2] + (float) $m[4]) / 2)) . ', ' . $number((string) (((float) $m[1] + (float) $m[3]) / 2));
            }

            return ['address' => $address, 'zoom' => '15'];
        }
        if (preg_match('/(^|\.)mapy\.(cz|com)$/', $host)) {
            $address = trim((string) ($q['q'] ?? ''));
            if ($address === '' && is_numeric($q['x'] ?? null) && is_numeric($q['y'] ?? null)) {
                $address = $number($q['y']) . ', ' . $number($q['x']);
            }

            return ['address' => $address, 'zoom' => $zoom((string) ($q['z'] ?? ''))];
        }

        return null;
    }

    /**
     * A number that counts up (a figure with a <data> element, or a block of one <data> and a caption) becomes a Counter element.
     *
     * @return array<string, mixed>|null
     */
    private function counter(Element $el): ?array
    {
        $data = $el->querySelectorAll('data[value]');
        if ($data->length !== 1 || $el->querySelector('img, a, h1, h2, h3, h4, h5, h6, ul, ol') !== null) {
            return null;
        }
        $value = trim((string) $data->item(0)?->getAttribute('value'));
        if (preg_match('/^-?\d+(?:[.,]\d+)?$/', $value) !== 1) {
            return null;
        }
        $label = trim((string) $data->item(0)->textContent);
        $caption = trim((string) ($el->querySelector('figcaption, small, p, span')?->textContent ?? ''));
        // the surrounding text must be just the number and its caption
        $text = trim((string) preg_replace('/\s+/u', ' ', $el->textContent));
        if (mb_strlen($text) > mb_strlen($label) + mb_strlen($caption) + 4) {
            return null;
        }
        preg_match('/^(\D*)[\d.,]*(.*)$/su', $label, $parts);
        [$prefix, $suffix] = [$parts[1] ?? '', $parts[2] ?? ''];

        return Build::fresh('counter', ['number' => $value, 'prefix' => mb_substr(trim($prefix), 0, 10), 'suffix' => mb_substr($suffix, 0, 20), 'caption' => mb_substr($caption, 0, 120)]);
    }

    /**
     * An ARIA tab list with its panels becomes a Tabs element. The panel of a tab is the element its aria-controls names, else the
     * next panel (role tabpanel) after the list; the panels are taken out of the document so they are not converted twice.
     *
     * @return array<string, mixed>|null null = a tab list without panels (ordinary content)
     */
    private function tabs(Element $list, Node $parent): ?array
    {
        $panels = $parent instanceof Element ? iterator_to_array($parent->querySelectorAll('[role="tabpanel"]')) : [];
        $tabs = [];
        $used = [];
        foreach ($list->querySelectorAll('[role="tab"]') as $tab) {
            $panel = null;
            if (($id = $tab->getAttribute('aria-controls')) !== null && $id !== '') {
                foreach ($panels as $i => $candidate) {
                    if ($candidate->getAttribute('id') === $id) {
                        $panel = $candidate;
                        $used[$i] = true;
                        break;
                    }
                }
            }
            if ($panel === null) {
                foreach ($panels as $i => $candidate) {
                    if (!isset($used[$i]) && ($candidate->getAttribute('id') ?? '') === '') {
                        $panel = $candidate;
                        $used[$i] = true;
                        break;
                    }
                }
            }
            if ($panel === null) {
                continue;
            }
            $tabs[] = ['name' => mb_substr(trim((string) preg_replace('/\s+/u', ' ', $tab->textContent)), 0, 80), 'content' => trim($panel->innerHTML)];
        }
        if ($tabs === []) {
            return null;
        }
        foreach (array_keys($used) as $i) {
            $panels[$i]->remove();
        }
        if (count($tabs) > 12) {
            $this->messages[] = 'A tab list has more than 12 tabs – the first 12 were converted.';
        }

        return Build::fresh('tabs', ['tabs' => array_slice($tabs, 0, 12)]);
    }

    private function customHtml(Element $el): ?array
    {
        if (!$this->admin) {
            $this->messages[] = 'The tag <' . strtolower($el->localName) . '> can only be inserted as Custom HTML, which only the site administrator may do – left out.';

            return null;
        }

        return Build::fresh('custom_html', ['code' => self::html($el)]);
    }

    /** Does the element contain block tags (then it is not plain text but structure)? */
    private function hasBlocks(Element $el): bool
    {
        return $el->querySelector('div, section, article, header, footer, aside, nav, h1, h2, h3, h4, h5, h6, figure, img, blockquote, details, a.btn, a.button, a[class*="tlacitko"], talea-widget') !== null;
    }

    /** @return list<string> classes in a form the build accepts */
    private function classesOf(Element $el): array
    {
        $classes = preg_split('/\s+/', trim((string) $el->getAttribute('class'))) ?: [];
        if (array_filter($classes, Build::isReservedClass(...)) !== []) {
            $this->messages[] = Build::RESERVED_CLASS_NOTE;
        }

        return array_values(array_filter($classes, fn (string $t): bool => preg_match(Build::CLASS_PATTERN, $t) === 1));
    }

    /**
     * Rules ".class { … }" from <style> become shared classes. ".class:hover" and @media (max-width: …) are converted to class
     * states (hover, tablet up to 1023 px, mobile up to 767 px) – the declarations that have a counterpart in the builder style. The rest is reported.
     */
    private function styles(string $css): void
    {
        $css = (string) preg_replace('#/\*.*?\*/#s', '', $css);
        if (preg_match('/(?<![\w-])\.tl-[a-z0-9_-]/i', $css)) {
            $this->messages[] = Build::RESERVED_CLASS_NOTE;
        }
        // @media with max-width = a builder breakpoint; the block is processed and removed from the CSS
        $css = (string) preg_replace_callback('/@media\s*([^{]*)\{((?:[^{}]*\{[^{}]*\})*[^{}]*)\}/i', function (array $m): string {
            $state = $this->stateFromMedia($m[1]);
            if ($state === null) {
                $this->messages[] = 'The rule @media ' . trim(mb_substr($m[1], 0, 60)) . ' is not converted – the builder has the breakpoints @media (max-width: 1023px) = tablet and (max-width: 767px) = mobile; style from the desktop down.';

                return '';
            }
            preg_match_all('/([^{}]+)\{([^{}]*)\}/', $m[2], $rules, PREG_SET_ORDER);
            foreach ($rules as [, $selectors, $declarations]) {
                foreach (array_map('trim', explode(',', $selectors)) as $selector) {
                    if (preg_match('/^\.([a-z][a-z0-9_-]*)(:hover|:focus-visible)?$/', $selector, $t) && preg_match(Build::CLASS_PATTERN, $t[1])) {
                        $this->addClassState($t[1], isset($t[2]) ? 'hover_' . $state : $state, $declarations);
                    } elseif ($selector !== '') {
                        $this->messages[] = 'Inside @media only single-class selectors are converted; left out: ' . mb_substr($selector, 0, 60) . '.';
                    }
                }
            }

            return '';
        }, $css);
        if (preg_match_all('/@(media|supports|container|keyframes|font-face|import|layer)\b/i', $css, $m)) {
            $this->messages[] = 'The rules @' . implode(', @', array_unique(array_map('strtolower', $m[1]))) . ' are not converted – set them in the style of an element or a class in the builder.';
            // nested blocks are removed so that their declarations do not end up in the wrong classes
            do {
                $css = (string) preg_replace('/@[a-z-]+[^{;]*\{(?:[^{}]*\{[^{}]*\})*[^{}]*\}|@[a-z-]+[^{;]*;/i', '', $css, -1, $count);
            } while ($count > 0);
        }
        preg_match_all('/([^{}]+)\{([^{}]*)\}/', $css, $rules, PREG_SET_ORDER);
        $other = [];
        foreach ($rules as [, $selectors, $declarations]) {
            foreach (array_map('trim', explode(',', $selectors)) as $selector) {
                if (preg_match('/^\.([a-z][a-z0-9_-]*)$/', $selector, $t) && preg_match(Build::CLASS_PATTERN, $t[1])) {
                    $discarded = [];
                    $safe = Style::customCss($declarations, $discarded);
                    $this->classes[$t[1]] = trim(($this->classes[$t[1]] ?? '') . ' ' . $safe);
                    foreach ($discarded as $d) {
                        $this->messages[] = 'Class .' . $t[1] . ': the declaration “' . mb_substr($d, 0, 60) . '” is not allowed – left out.';
                    }
                } elseif (preg_match('/^\.([a-z][a-z0-9_-]*)(:hover|:focus-visible|:active)$/', $selector, $t) && preg_match(Build::CLASS_PATTERN, $t[1])) {
                    $this->addClassState($t[1], $t[2] === ':active' ? 'active' : 'hover', $declarations);
                } elseif ($selector !== '') {
                    $other[] = $selector;
                }
            }
        }
        if ($other !== []) {
            $this->messages[] = 'Only single-class selectors are converted (.card, .card:hover); left out: ' . mb_substr(implode(', ', array_unique($other)), 0, 200) . '.';
        }
    }

    /** Builder breakpoint by the @media condition: max-width up to 767 px = mobile, up to 1023 px = tablet; another condition = null. */
    private function stateFromMedia(string $condition): ?string
    {
        if (!preg_match('/^\s*(?:screen\s+and\s+)?\(\s*max-width\s*:\s*(\d+(?:\.\d+)?)(px|rem|em)\s*\)\s*$/i', $condition, $m)) {
            return null;
        }
        $px = (float) $m[1] * (strtolower($m[2]) === 'px' ? 1 : 16);

        return match (true) {
            $px >= 600 && $px < 900 => 'mobile',
            $px >= 900 && $px <= 1280 => 'tablet',
            default => null,
        };
    }

    /** Declarations into a class state (hover, tablet…) as style properties; what cannot be converted is reported. */
    private function addClassState(string $className, string $state, string $declarations): void
    {
        if (!isset(Style::STATUSES[$state])) {
            return;
        }
        foreach (preg_split('/;(?![^(]*\))/', $declarations) ?: [] as $d) {
            if (!str_contains($d, ':')) {
                continue;
            }
            [$property, $value] = array_map('trim', explode(':', $d, 2));
            $properties = Style::fromCss($property, $value);
            if ($properties === null) {
                $this->messages[] = 'Class .' . $className . ' (' . $state . '): the builder cannot do the declaration “' . mb_substr(trim($d), 0, 60) . '” in this state – left out.';
                continue;
            }
            $this->classStyles[$className][$state] = array_merge($this->classStyles[$className][$state] ?? [], $properties);
        }
    }

    /** The element's whole HTML including the tag. Dom\Element has the outerHTML property only from PHP 8.5 – Talea runs on 8.4 too. */
    private static function html(\Dom\Element $el): string
    {
        return $el->ownerDocument->saveHtml($el);
    }
}
