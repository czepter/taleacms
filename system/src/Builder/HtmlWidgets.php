<?php

declare(strict_types=1);

namespace Talea\Builder;

use Dom\Element;
use Talea\Core\Html;

/**
 * The widgets of an old page – forms, questions and answers, tabs, videos, maps, embedded services and counters – found in the page's
 * markup before an import filters it (Core\WebImport). A widget is detected by structure: ARIA patterns (aria-controls / aria-expanded,
 * role tablist), <details>, the address of a frame, a numeric data attribute a counter script counts to, and the generic class tokens
 * builders share (…accordion-item, …tab-pane) – never by the name of one builder. Each widget is replaced by the marker
 * <talea-widget title="N"></talea-widget> and handed on as plain semantic HTML that Builder\HtmlConverter turns into the element
 * (the same shapes build_from_html reads), so the page keeps its place in the text.
 *
 * Pure: no database, no network.
 */
final class HtmlWidgets
{
    /** What a widget can be; the first five come back as their element, the last four are left to the converter or to a placeholder. */
    public const array KINDS = ['form', 'unreadable_form', 'accordion', 'tabs', 'video', 'video_file', 'map', 'embed', 'frame', 'counter'];

    public const int MAX = 60;

    private const int MAX_ITEMS = 30;

    /** Attributes a counter script counts to. */
    private const array COUNT_ATTRIBUTES = ['data-count', 'data-counter', 'data-countup', 'data-count-to', 'data-to-value', 'data-number-value', 'data-purecounter-end', 'data-to', 'data-target', 'data-end', 'data-value'];

    /** The generic attributes only count together with a hint in the name or a class. */
    private const array NEEDS_HINT = ['data-to', 'data-target', 'data-end', 'data-value'];

    private const string COUNTER_HINT = '/count|counter|number|odometer|stat(?!ic)|kpi/i';

    private const string VIDEO = '#(?:youtube(?:-nocookie)?\.com/(?:embed/|shorts/|watch\?(?:[^"\s]*&)?v=)|youtu\.be/)([\w-]{6,})|(?:player\.vimeo\.com/video/|//vimeo\.com/)(\d+)#i';

    /**
     * Finds the widgets under $root, replaces each with a marker and returns them in marker order (the marker's title is the index).
     *
     * @return list<array{kind: string, html: string, host: string}>
     */
    public static function extract(Element $root, int $max = self::MAX): array
    {
        foreach (iterator_to_array($root->querySelectorAll('talea-widget')) as $own) {
            $own->remove(); // the old page cannot place a marker of its own
        }
        $widgets = [];
        $mark = function (array $nodes, string $kind, string $html, string $host = '') use ($root, &$widgets, $max): void {
            if (count($widgets) >= $max || $nodes === [] || !$root->contains($nodes[0])) {
                return;
            }
            $marker = $root->ownerDocument->createElement('talea-widget');
            $marker->setAttribute('title', (string) count($widgets));
            $widgets[] = ['kind' => $kind, 'html' => $html, 'host' => $host];
            $nodes[0]->replaceWith($marker);
            foreach (array_slice($nodes, 1) as $extra) {
                $extra->remove();
            }
            // a marker is a block: it cannot stay inside text
            $outer = null;
            for ($up = $marker->parentElement; $up !== null && $up !== $root && in_array(strtolower($up->localName), ['p', 'span', 'a', 'em', 'strong', 'b', 'i', 'li'], true); $up = $up->parentElement) {
                $outer = $up;
            }
            if ($outer !== null && strtolower($outer->localName) !== 'li') {
                $outer->after($marker);
            }
        };

        // groups of several elements first: they contain the single ones (a form inside a tab is part of the tab)
        foreach (self::ariaTabs($root) + self::classTabs($root) as $found) {
            $mark($found['nodes'], 'tabs', $found['html']);
        }
        foreach (self::ariaAccordions($root) as $found) {
            $mark($found['nodes'], 'accordion', $found['html']);
        }
        foreach (self::classAccordions($root) as $found) {
            $mark($found['nodes'], 'accordion', $found['html']);
        }
        foreach (iterator_to_array($root->querySelectorAll('*')) as $el) {
            if (!$root->contains($el)) {
                continue; // inside a widget found before
            }
            $kind = self::kind($el);
            if ($kind === null) {
                continue;
            }
            [$html, $host, $node] = self::single($el, $kind);
            $mark([$node], $kind, $html, $host);
        }

        return $widgets;
    }

    /** The kind of a single element that is a widget on its own, or null. */
    public static function kind(Element $el): ?string
    {
        $tag = strtolower($el->localName);
        if ($tag === 'form') {
            return self::isSearchForm($el) ? null : (self::formFields($el) > 0 ? 'form' : 'unreadable_form');
        }
        if ($tag === 'iframe') {
            $src = self::source($el);
            return match (true) {
                $src === '' => null,
                preg_match(self::VIDEO, $src) === 1 => 'video',
                HtmlConverter::mapFrame($src) !== null => 'map',
                Elements\Embed::resolve($src) !== null => 'embed',
                preg_match('/form/i', (string) parse_url($src, PHP_URL_HOST)) === 1 => 'unreadable_form',
                default => 'frame',
            };
        }
        if ($tag === 'video') {
            return ($el->getAttribute('src') ?: $el->querySelector('source[src]')?->getAttribute('src')) ? 'video_file' : null;
        }
        if (self::coordinates($el) !== null) {
            return 'map';
        }
        if (self::counterValue($el) !== null) {
            return 'counter';
        }
        if ($el->querySelector('form, iframe, video') === null && self::isFormContainer($el)) {
            return 'unreadable_form';
        }
        if (!in_array($tag, ['iframe', 'a', 'img', 'script', 'html', 'body'], true) && $el->children->length === 0 && self::lazyVideo($el) !== null) {
            return 'video';
        }

        return null;
    }

    /** A search box, not a form to fill in: role=search, "search" in its class or action, or only search fields. */
    public static function isSearchForm(Element $form): bool
    {
        if (strtolower((string) $form->getAttribute('role')) === 'search' || preg_match('/search|hledat|suche/i', $form->getAttribute('class') . ' ' . $form->getAttribute('action')) === 1) {
            return true;
        }
        $fields = $form->querySelectorAll('input:not([type="hidden"]):not([type="submit"]):not([type="button"]), textarea, select');

        return $fields->length > 0 && $form->querySelectorAll('input:not([type="hidden"]):not([type="submit"]):not([type="button"]):not([type="search"]), textarea, select')->length === 0;
    }

    /** Fields a person fills in (a form of only buttons and hidden fields has none). */
    private static function formFields(Element $form): int
    {
        return $form->querySelectorAll('input:not([type="hidden"]):not([type="submit"]):not([type="button"]):not([type="reset"]):not([type="image"]), textarea, select')->length;
    }

    /** A block that is named like a form, holds a script or a form id and no field: a script draws the form. */
    private static function isFormContainer(Element $el): bool
    {
        if (!in_array(strtolower($el->localName), ['div', 'section', 'aside'], true) || self::formFields($el) > 0) {
            return false;
        }
        $named = preg_match('/(?<![a-z])forms?(?![a-z])|[a-z]forms?\b/i', $el->getAttribute('class') . ' ' . $el->getAttribute('id')) === 1;
        $driven = $el->querySelector('script') !== null || $el->hasAttribute('data-form-id') || $el->hasAttribute('data-formid') || $el->hasAttribute('data-form');

        return $named && $driven;
    }

    private static function source(Element $el): string
    {
        return trim($el->getAttribute('src') ?: $el->getAttribute('data-src') ?: $el->getAttribute('data-lazy-src') ?: '');
    }

    /** The video address of an element whose attribute holds one (a lazy player), or null. */
    private static function lazyVideo(Element $el): ?string
    {
        foreach ($el->attributes as $attribute) {
            if (preg_match(self::VIDEO, $attribute->value, $m) === 1) {
                return self::videoFrame($m);
            }
        }

        return null;
    }

    /** @param array<int, string> $m a match of VIDEO */
    private static function videoFrame(array $m): string
    {
        return ($m[1] ?? '') !== '' ? 'https://www.youtube.com/embed/' . $m[1] : 'https://player.vimeo.com/video/' . ($m[2] ?? '');
    }

    /** @return array{0: string, 1: string}|null latitude and longitude of an element that carries them as data attributes */
    private static function coordinates(Element $el): ?array
    {
        $lat = $el->getAttribute('data-lat') ?: $el->getAttribute('data-latitude');
        $lng = $el->getAttribute('data-lng') ?: $el->getAttribute('data-lon') ?: $el->getAttribute('data-longitude');

        return is_numeric($lat) && is_numeric($lng) ? [(string) $lat, (string) $lng] : null;
    }

    /** The number a counter counts to, or null when the element is no counter. */
    private static function counterValue(Element $el): ?string
    {
        if (in_array(strtolower($el->localName), ['data', 'script', 'style'], true) || preg_match('/^\s*[\d.,]*\s*$/', $el->textContent) !== 1) {
            return null; // the element shows the start of the count (often 0), not text
        }
        foreach (self::COUNT_ATTRIBUTES as $name) {
            $value = $el->getAttribute($name);
            if ($value === null || preg_match('/^\d{1,9}(?:[.,]\d{1,2})?$/', trim($value)) !== 1) {
                continue;
            }
            // the generic attribute names say nothing: the element's class has to
            if (in_array($name, self::NEEDS_HINT, true) && preg_match(self::COUNTER_HINT, (string) $el->getAttribute('class')) !== 1) {
                continue;
            }

            return str_replace(',', '.', trim($value));
        }

        return null;
    }

    /**
     * The normalised HTML of a single widget: what Builder\HtmlConverter reads.
     *
     * @return array{0: string, 1: string, 2: Element} the HTML, for a frame its host (letters, digits, dots and dashes) and the element the marker replaces
     */
    private static function single(Element $el, string $kind): array
    {
        $host = fn (string $src): string => (string) preg_replace('/[^a-z0-9.-]/', '', strtolower((string) parse_url($src, PHP_URL_HOST)));
        switch ($kind) {
            case 'form':
                return [self::cleanForm($el), '', $el];
            case 'video':
                $src = strtolower($el->localName) === 'iframe' ? self::source($el) : (string) self::lazyVideo($el);
                preg_match(self::VIDEO, $src, $m);

                return ['<iframe src="' . e(self::videoFrame($m)) . '" title="' . e(mb_substr($el->getAttribute('title') ?? '', 0, 120)) . '"></iframe>', '', $el];
            case 'map':
                if (($c = self::coordinates($el)) !== null) {
                    return ['<iframe src="https://www.openstreetmap.org/export/embed.html?marker=' . e($c[0] . ',' . $c[1]) . '"></iframe>', '', $el];
                }

                return ['<iframe src="' . e(self::source($el)) . '"></iframe>', $host(self::source($el)), $el];
            case 'embed':
                return ['<iframe src="' . e(self::source($el)) . '" title="' . e(mb_substr($el->getAttribute('title') ?? '', 0, 120)) . '"></iframe>', '', $el];
            case 'counter':
                return self::counterHtml($el);
            case 'frame':
            case 'unreadable_form':
                return ['', $host(self::source($el)), $el];
            default:
                return ['', '', $el];
        }
    }

    /** The form as the converter needs it: fields, labels and the button – without honeypots, captchas, scripts, classes and styles. */
    private static function cleanForm(Element $form): string
    {
        $copy = $form->cloneNode(true);
        foreach (iterator_to_array($copy->querySelectorAll('input, select, textarea')) as $field) {
            if (HtmlConverter::isSpamTrap($field, $copy)) {
                $field->remove();
            }
        }
        foreach (iterator_to_array($copy->querySelectorAll('script, style, noscript, [data-sitekey], .g-recaptcha, .h-captcha, .cf-turnstile')) as $drop) {
            $drop->parentNode?->removeChild($drop); // a list with a comma selector may hold an element twice
        }
        $keep = ['name', 'type', 'for', 'id', 'value', 'placeholder', 'required', 'multiple', 'checked', 'selected', 'aria-label'];
        foreach (iterator_to_array($copy->querySelectorAll('*')) as $node) {
            foreach (iterator_to_array($node->attributes) as $attribute) {
                if (!in_array(strtolower($attribute->name), $keep, true)) {
                    $node->removeAttribute($attribute->name);
                }
            }
        }

        return Html::outer($copy);
    }

    /**
     * <figure><data value="250">+250 %</data><figcaption>caption</figcaption></figure> from a counter element and the text around it: short
     * text next to the number is its prefix and suffix, the rest of the nearest block that holds little more is the caption.
     *
     * @return array{0: string, 1: string, 2: Element} the HTML, '' and the block the marker replaces
     */
    private static function counterHtml(Element $el): array
    {
        $value = (string) self::counterValue($el);
        $before = $after = '';
        $seen = false;
        foreach ($el->parentElement?->childNodes ?? [] as $node) {
            if ($node->isSameNode($el)) {
                $seen = true;
            } elseif (($text = trim($node->textContent)) !== '' && mb_strlen($text) <= 4) {
                $seen ? $after .= $text : $before .= $text;
            }
        }
        // the block grows while it holds little text and no second counter
        $block = $el;
        for ($i = 0; $i < 3; $i++) {
            $up = $block->parentElement;
            if ($up === null || in_array(strtolower($up->localName), ['body', 'main', 'html', 'section', 'article'], true) || mb_strlen(trim($up->textContent)) > 120 || $up->querySelector('h1, h2, h3, h4, h5, h6, talea-widget') !== null) {
                break;
            }
            $counters = 0;
            foreach ($up->querySelectorAll('*') as $inner) {
                $counters += (int) (self::counterValue($inner) !== null);
            }
            if ($counters > 1) {
                break;
            }
            $block = $up;
        }
        $el->setAttribute('data-tl-number', '1');
        $copy = $block->cloneNode(true);
        $el->removeAttribute('data-tl-number');
        $caption = '';
        if (!$block->isSameNode($el)) {
            $copy->querySelector('[data-tl-number]')?->remove();
            $caption = trim((string) preg_replace('/\s+/u', ' ', $copy->textContent));
        }
        foreach ([$before, $after] as $part) {
            $caption = $part !== '' ? trim(preg_replace('/' . preg_quote($part, '/') . '/u', '', $caption, 1) ?? $caption) : $caption;
        }

        return ['<figure><data value="' . e($value) . '">' . e(trim($before) . $value . trim($after)) . '</data><figcaption>' . e(mb_substr($caption, 0, 120)) . '</figcaption></figure>', '', $block];
    }

    /* ---------- groups ---------- */

    /**
     * Accordions of buttons with aria-controls and aria-expanded: at least two triggers that share a parent; a panel is the element the
     * trigger names.
     *
     * @return list<array{nodes: list<Element>, html: string}>
     */
    private static function ariaAccordions(Element $root): array
    {
        $groups = [];
        foreach ($root->querySelectorAll('[aria-controls][aria-expanded]') as $trigger) {
            $id = (string) $trigger->getAttribute('aria-controls');
            if (strtolower((string) $trigger->getAttribute('role')) === 'tab' || $id === '' || $trigger->closest('[role="tablist"]') !== null) {
                continue;
            }
            $panel = $root->querySelector('[id="' . addcslashes($id, '"\\') . '"]'); // not getElementById: $root may be a copy outside the document
            if ($panel === null || $panel->contains($trigger) || strtolower($panel->localName) === 'nav') {
                continue;
            }
            // the item is the trigger's ancestor that holds no other trigger; its parent groups the items
            $item = $trigger;
            while ($item->parentElement !== null && $item->parentElement !== $root && $item->parentElement->querySelectorAll('[aria-controls][aria-expanded]')->length === 1) {
                $item = $item->parentElement;
            }
            $parent = $item->parentElement;
            if ($parent === null) {
                continue;
            }
            $groups[$parent->getNodePath()]['parent'] = $parent;
            $groups[$parent->getNodePath()]['items'][] = [$item, $trigger, $panel];
        }
        $found = [];
        foreach ($groups as $group) {
            if (count($group['items']) < 2) {
                continue;
            }
            $questions = [];
            $nodes = [];
            foreach ($group['items'] as [$item, $trigger, $panel]) {
                $questions[] = [trim((string) preg_replace('/\s+/u', ' ', $trigger->textContent)), trim($panel->innerHTML)];
                $nodes[] = $item;
                if (!$item->contains($panel)) {
                    $nodes[] = $panel; // a flat list: the answer is a sibling of the question's item
                }
            }
            $found[] = ['nodes' => $nodes, 'html' => self::details($questions)];
        }

        return $found;
    }

    /**
     * Accordions that only the class names give away: items called …accordion-item, …faq-item, …toggle-section with a question and an
     * answer part, at least two next to each other.
     *
     * @return list<array{nodes: list<Element>, html: string}>
     */
    private static function classAccordions(Element $root): array
    {
        $groups = [];
        foreach ($root->querySelectorAll('[class]') as $item) {
            if (!self::hasToken($item, '/(?:^|[_-])(accordion|toggle|faq|collaps(?:e|ible))[_-]{0,2}(item|section|pane|panel|entry)$/i') || $item->parentElement === null) {
                continue;
            }
            $question = $answer = null;
            foreach ($item->children as $child) {
                if ($question === null && self::hasToken($child, '/(title|question|header|heading|trigger|toggler|summary)$/i')) {
                    $question = $child;
                } elseif ($answer === null && self::hasToken($child, '/(content|answer|body|panel|collapse|text)$/i')) {
                    $answer = $child;
                }
            }
            if ($question !== null && $answer !== null) {
                $groups[$item->parentElement->getNodePath()][] = [$item, trim((string) preg_replace('/\s+/u', ' ', $question->textContent)), trim($answer->innerHTML)];
            }
        }
        $found = [];
        foreach ($groups as $items) {
            if (count($items) >= 2) {
                $found[] = ['nodes' => array_column($items, 0), 'html' => self::details(array_map(fn (array $i): array => [$i[1], $i[2]], $items))];
            }
        }

        return $found;
    }

    private static function hasToken(Element $el, string $pattern): bool
    {
        foreach (preg_split('/\s+/', trim($el->getAttribute('class') ?? '')) ?: [] as $token) {
            if ($token !== '' && preg_match($pattern, $token) === 1) {
                return true;
            }
        }

        return false;
    }

    /** @param list<array{0: string, 1: string}> $questions */
    private static function details(array $questions): string
    {
        $html = '';
        foreach (array_slice($questions, 0, self::MAX_ITEMS) as [$question, $answer]) {
            if ($question !== '') {
                $html .= '<details><summary>' . e(mb_substr($question, 0, 300)) . '</summary>' . $answer . '</details>';
            }
        }

        return $html;
    }

    /**
     * ARIA tabs: a tab list and its panels (named by aria-controls, else in order among the panels next to the list).
     *
     * @return list<array{nodes: list<Element>, html: string}>
     */
    private static function ariaTabs(Element $root): array
    {
        $found = [];
        foreach ($root->querySelectorAll('[role="tablist"]') as $list) {
            $scope = $list->parentElement;
            $panels = $scope === null ? [] : iterator_to_array($scope->querySelectorAll('[role="tabpanel"]'));
            $tabs = [];
            $nodes = [$list];
            $unnamed = array_values(array_filter($panels, fn (Element $p): bool => ($p->getAttribute('id') ?? '') === ''));
            foreach ($list->querySelectorAll('[role="tab"]') as $tab) {
                $id = (string) $tab->getAttribute('aria-controls');
                $panel = null;
                foreach ($panels as $candidate) {
                    if ($id !== '' && $candidate->getAttribute('id') === $id) {
                        $panel = $candidate;
                    }
                }
                $panel ??= array_shift($unnamed);
                if ($panel !== null) {
                    $tabs[] = [trim((string) preg_replace('/\s+/u', ' ', $tab->textContent)), trim($panel->innerHTML)];
                    $nodes[] = $panel;
                }
            }
            if ($tabs !== []) {
                $found[$list->getNodePath()] = ['nodes' => $nodes, 'html' => self::tabsHtml($tabs)];
            }
        }

        return $found;
    }

    /**
     * Tabs only the class names give away: a list called …tabs-nav / …tab-controls and panels called …tab-pane / …tab-content next to
     * it, matched by order.
     *
     * @return list<array{nodes: list<Element>, html: string}>
     */
    private static function classTabs(Element $root): array
    {
        $found = [];
        foreach ($root->querySelectorAll('[class]') as $list) {
            if (!self::hasToken($list, '/tabs?[_-]{0,2}(nav|navigation|list|controls|menu|titles)$/i') || $list->parentElement === null) {
                continue;
            }
            $panels = array_values(array_filter(iterator_to_array($list->parentElement->querySelectorAll('[class]')), fn (Element $p): bool => self::hasToken($p, '/tab[_-]{0,2}(pane|panel|content)$/i') && !$list->contains($p)));
            $names = array_values(array_filter(array_map(fn (Element $t): string => trim((string) preg_replace('/\s+/u', ' ', $t->textContent)), iterator_to_array($list->children)), fn (string $n): bool => $n !== ''));
            if ($panels === [] || $names === []) {
                continue;
            }
            $tabs = [];
            foreach (array_slice($names, 0, count($panels)) as $i => $name) {
                $tabs[] = [$name, trim($panels[$i]->innerHTML)];
            }
            $found[$list->getNodePath()] = ['nodes' => [$list, ...array_slice($panels, 0, count($tabs))], 'html' => self::tabsHtml($tabs)];
        }

        return $found;
    }

    /** @param list<array{0: string, 1: string}> $tabs */
    private static function tabsHtml(array $tabs): string
    {
        static $n = 0;
        $n++;
        $list = '';
        $panels = '';
        foreach (array_slice($tabs, 0, 12) as $i => [$name, $content]) {
            $id = 'tl-tab-' . $n . '-' . $i;
            $list .= '<button role="tab" aria-controls="' . $id . '">' . e(mb_substr($name, 0, 80)) . '</button>';
            $panels .= '<div role="tabpanel" id="' . $id . '">' . $content . '</div>';
        }

        return '<div role="tablist">' . $list . '</div>' . $panels;
    }
}
