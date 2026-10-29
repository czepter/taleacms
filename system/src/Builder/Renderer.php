<?php

declare(strict_types=1);

namespace Talea\Builder;

use Talea\Core\App;
use Talea\Core\Db;
use Talea\Core\Language;

/**
 * Renders a sanitized build to HTML and the page CSS (Build::render() calls it): elements, display conditions, collection items,
 * custom attributes and the CSS of only what the page uses. A broken element is left out on the site, never an exception.
 */
final class Renderer
{
    /** Stands for the page content in a site-part wrapper until the build's own tokens are filled (html()). */
    private const string CONTENT_MARK = "\u{E000}tl-page-content\u{E000}";

    /**
     * Renders a build: HTML and CSS of only what the page uses (type bases, used classes, element styles) in cascade layers.
     * In editor mode every element gets data-tl-id so that it can be selected on the canvas.
     *
     * @return array{html:string, css:string, faq:list<array{0:string, 1:string}>}
     */
    public static function render(App $app, array $build, bool $editor = false): array
    {
        $k = new Context($app, $editor);
        $html = self::html($build, $k);

        return ['html' => $html, 'css' => self::css($app->db(), $k), 'faq' => $k->faq];
    }

    /** HTML of a build in the page's shared context (this is how the site assembles the page, header and footer and outputs the CSS once via css()). */
    public static function html(array $build, Context $k): string
    {
        if ($k->editor) {
            return self::renderChildren($build['children'] ?? [], $k); // the builder keeps every token, so it stays in the build
        }
        // the content of a site-part wrapper (a news item, a list with the visitor's search query) was filled by Front\Kernel
        // already: it goes in only after the tokens of the build, so nothing a visitor sent is read as a token (3.3.2, N38)
        $content = $k->content;
        $k->content = $content === '' ? '' : self::CONTENT_MARK;
        try {
            $html = self::renderChildren($build['children'] ?? [], $k);
        } finally {
            $k->content = $content;
        }
        // business facts (2.10) and add-on tokens (3.0) filled for visitors
        $html = \Talea\Extension\Registry::fillTokens(\Talea\Core\Facts::fill($html, $k->app));

        return $content === '' ? $html : str_replace(self::CONTENT_MARK, $content, $html);
    }

    private static function renderChildren(array $children, Context $k): string
    {
        $html = '';
        foreach ($children as $p) {
            try {
                $html .= self::renderElement($p, $k);
            } catch (\Throwable $e) {
                // "doctor": a broken element is left out on the site, the editor shows a message
                error_log('Builder: prvek ' . ($p['id'] ?? '?') . ' – ' . $e->getMessage());                $html .= $k->editor ? '<div data-tl-id="' . e((string) ($p['id'] ?? '')) . '" style="padding:1rem;border:2px dashed #b3261e;color:#b3261e">' . e(t('This element could not be displayed.')) . '</div>' : '';
            }
        }

        return $html;
    }

    /**
     * Whether an element with the (sanitized) display conditions is shown to this visitor: every condition must hold.
     *
     * @param array{signed_in?: string, from?: string, to?: string, languages?: list<string>, url_parameter?: array{name: string, value?: string}} $conditions
     */
    public static function meetsConditions(array $conditions, Context $k): bool
    {
        $today = date('Y-m-d');
        if (isset($conditions['from']) && $today < $conditions['from'] || isset($conditions['to']) && $today > $conditions['to']) {
            return false;
        }
        // the language version being displayed, as the "language" column has it: '' = the site's default language
        if (isset($conditions['languages']) && !in_array($k->app->languagePrefix, $conditions['languages'], true)) {
            return false;
        }
        if (isset($conditions['url_parameter']['name'])) {
            $r = $k->app->request;
            if (!$r->has($conditions['url_parameter']['name']) || (isset($conditions['url_parameter']['value']) && $r->get($conditions['url_parameter']['name']) !== $conditions['url_parameter']['value'])) {
                return false;
            }
        }
        if (isset($conditions['signed_in'])) {
            $signedIn = $k->app->auth()->user() !== null;

            return $conditions['signed_in'] === 'yes' ? $signedIn : !$signedIn;
        }

        return true;
    }

    private static function renderElement(array $p, Context $k): string
    {
        $className = Build::className((string) $p['type']);
        if ($className === null) {
            return '';
        }
        if (($p['conditions'] ?? []) !== [] && !$k->editor) {
            // a page whose content depends on the visit (date, sign-in, URL parameter) is not cached – the page cache
            // ignores tracking parameters such as utm_campaign, so a cached copy could not tell the visits apart
            $k->withoutCache = $k->withoutCache || Build::conditionsBypassCache($p['conditions']);
            if (!self::meetsConditions($p['conditions'], $k)) {
                return '';
            }
        }
        if ($className::EXTENSION !== '' && !\Talea\Core\Extensions::isEnabled($k->app->settings(), $className::EXTENSION)) {
            // an element of a disabled extension (news, form): nothing on the site, a notice in the editor – the build stays, it comes back once enabled
            return $k->editor ? '<div data-tl-id="' . e((string) ($p['id'] ?? '')) . '" data-tl-type="' . e($className::TYPE) . '" style="padding:1rem;border:2px dashed currentColor;opacity:.6">'
                . e(t('%s – the feature is switched off and will not appear on the website.', t($className::NAME))) . '</div>' : '';
        }
        // a build saved by an older version may lack properties the element got later – they are filled with the default value
        $p['content'] = (is_array($p['content'] ?? null) ? $p['content'] : []) + array_map(fn (array $field): mixed => $field['default'] ?? '', $className::properties());
        $k->types[$className::TYPE] = true;
        if ($k->item !== null) {
            $p['content'] = self::fillItem($className::properties(), $p['content'] ?? [], $k->item);
        }
        $children = match (true) {
            $className === Elements\CollectionList::class => Elements\CollectionList::repeat($p, $k, fn (): string => self::renderChildren($p['children'] ?? [], $k)),
            $className === Elements\Component::class => Elements\Component::inner($p, $k, fn (array $build): string => self::renderChildren($build['children'] ?? [], $k)),
            $className::CONTAINER => self::renderChildren($p['children'] ?? [], $k),
            default => '',
        };
        $style = $p['style'] ?? [];
        // behaviour of a content property (a header transparent at the top): after the element's own style, so that the style cannot undo it
        $customCss = trim((string) ($p['css'] ?? '') . ' ' . $className::behaviourCss($p, $k));
        $hasStyle = $style !== [] || $customCss !== '';
        // inside a Collection list the element repeats: style through the class s-<id>, not through the id (an id must be on the page only once)
        $isRepeated = $k->inLoop > 0;
        $id = $isRepeated ? null : ($p['anchor'] ?? ($hasStyle ? 's-' . $p['id'] : null));
        $classes = array_merge($isRepeated && $hasStyle ? ['s-' . $p['id']] : [], $p['classes'] ?? []);
        if ($hasStyle && !isset($k->styles[$p['id']])) {
            $k->styles[$p['id']] = true;
            $k->css .= Style::css($isRepeated ? '.s-' . $p['id'] : '#' . $id, $style, $customCss, $k->app->request->basePath());
        }
        foreach ($p['classes'] ?? [] as $t) {
            $k->classes[$t] = true;
        }
        $custom = self::customAttributes($p['attributes'] ?? null);
        $a = ($id !== null ? ' id="' . e($id) . '"' : '')
            . ($classes !== [] ? ' class="' . e(implode(' ', $classes)) . '"' : '')
            . $custom
            . ($k->marks[$p['id'] ?? ''] ?? '')
            . (isset($k->seoMarks[$p['id'] ?? '']) ? ' data-seo-mark="' . e($k->seoMarks[$p['id']]) . '"' : '')
            . ($k->editor || $k->markIds ? ' data-tl-id="' . e((string) $p['id']) . '"' : '')
            . ($k->editor ? ' data-tl-type="' . e($className::TYPE) . '"' . (!empty($p['locked']) ? ' data-tl-lock' : '') : '');

        $html = $className::render($p, $a, $children, $k);
        if (isset($k->seoMarks[$p['id'] ?? '']) && str_starts_with($html, '<img')) {
            // an image has no ::before for the label of its mark: a wrapper carries it (marks are only on a signed-in preview)
            $html = '<span class="tl-seo-mark" data-seo-mark="' . e($k->seoMarks[$p['id']]) . '">' . $html . '</span>';
        }

        return $custom === '' ? $html : self::customAttributesLast($html, $custom);
    }

    /**
     * The custom attributes of an element as HTML. A stored build is checked again: a build saved before a hook was reserved
     * (data-leaflet, data-basket… – 3.3.2) loses that attribute quietly, the rest of the element renders as before.
     */
    public static function customAttributes(mixed $attributes): string
    {
        $html = '';
        foreach (is_array($attributes) ? array_slice($attributes, 0, 10, true) : [] as $name => $value) {
            if (is_string($name) && is_scalar($value) && preg_match(Build::ATTRIBUTE_PATTERN, $name)) {
                $html .= ' ' . strtolower($name) . '="' . e(mb_substr((string) $value, 0, 200)) . '"';
            }
        }

        return $html;
    }

    /**
     * Moves the custom attributes to the end of the tag they are in: the element's own attributes come first, and HTML keeps
     * the first of two equal attributes – so a custom attribute can never override what the element itself sets (3.3.2, N25).
     * Values are escaped, so the only ">" outside quotes ends the tag.
     */
    private static function customAttributesLast(string $html, string $custom): string
    {
        $inside = '(?:[^>"\']|"[^"]*"|\'[^\']*\')*?';

        return (string) (preg_replace_callback('/(<[a-zA-Z][^\s\/>]*' . $inside . ')' . preg_quote($custom, '/') . '(' . $inside . ')(\s*\/?>)/',
            fn (array $m): string => $m[1] . $m[2] . $custom . $m[3], $html, 1) ?? $html);
    }

    /**
     * Collection item values into content fields by their type ({{nazev}} in a heading, {{foto}} in an image, {{url}} in a link…).
     *
     * @param array<string, array<string, mixed>> $properties
     * @param array<string, array{0: string, 1: string}> $values
     */
    private static function fillItem(array $properties, array $content, array $values): array
    {
        foreach ($properties as $key => $def) {
            if (is_string($content[$key] ?? null)) {
                $content[$key] = Collections::fill($content[$key], $def['type'], $values);
            } elseif ($def['type'] === 'values' && is_array($content[$key] ?? null)) {
                // a component in a collection list: the item's {{field}} in property values (checked only later by the property type)
                $content[$key] = array_map(fn (mixed $v): mixed => is_string($v) ? Collections::fill($v, 'text', $values) : $v, $content[$key]);
            } elseif ($def['type'] === 'items' && is_array($content[$key] ?? null)) {
                $content[$key] = array_map(fn (array $item): array => self::fillItem($def['fields'], $item, $values), $content[$key]);
            }
        }

        return $content;
    }

    /** Page CSS: the base of the used types, the used classes (from tl_classes) and the style of individual elements – each in its own layer. */
    public static function css(Db $db, Context $k): string
    {
        // in a build, spacing is controlled by the containers' gap, not by the layout's margins of headings and paragraphs; text inside a Text element keeps them,
        // and so does the system content inside the Page content element of a wrapper (.content: a news item's text looks the same as without the wrapper)
        $base = ':where(.build) :where(h1, h2, h3, h4, h5, h6, p, ul, ol, blockquote, figure, hr):not(:where(.content *)) { margin-block: 0; }' . "\n"
            . ':where(.build) :where(.tl-text) > * + * { margin-block-start: 1em; }' . "\n";
        $rtl = Language::isRtl(Language::code());
        foreach (Build::ELEMENTS as $className) {
            if (isset($k->types[$className::TYPE]) && $className::baseCss() !== '') {
                $base .= $className::baseCss() . "\n" . ($rtl && $className::rtlCss() !== '' ? $className::rtlCss() . "\n" : '');
            }
        }
        if ($k->compose) {
            $base .= Elements\Section::composeCss() . "\n";
        }
        if ($k->slider) {
            $base .= Elements\Carousel::sliderCss() . "\n";
        }
        $classes = '';
        if ($k->classes !== []) {
            $names = array_keys($k->classes);
            foreach (\Talea\Core\Look::classesForCss($db, $names) as $r) { // the draft classes in a preview of the draft look
                $classes .= Style::css('.' . $r['name'], json_decode((string) $r['style'], true) ?: [], Style::customCss((string) $r['css']), $k->app->request->basePath());
            }
        }
        $used = array_filter(Style::KEYFRAMES, fn (string $name): bool => str_contains($k->css . $classes, $name . ' linear both'), ARRAY_FILTER_USE_KEY);
        if ($used !== []) {
            // the scroll animations (reveal and motion while scrolling) the page uses; whoever does not want motion (system setting) sees the elements right away
            foreach ($used as $name => [$frames]) {
                $base .= '@keyframes ' . $name . ' { ' . $frames . " }\n";
            }
            $base .= '@media (prefers-reduced-motion: reduce) { :where(.build) * { animation: none !important; } }' . "\n";
        }
        if (str_contains($k->css, 'animation: tl-header-')) {
            $base .= Elements\Section::scrollCss() . "\n"; // the header that is transparent at the top or shrinks after scrolling – only when a header uses it
        }
        $css = DesignSystem::LAYERS . "\n";
        foreach (['builder' => $base, 'classes' => $classes, 'elements' => $k->css] as $layer => $content) {
            if (trim($content) !== '') {
                $css .= '@layer ' . $layer . " {\n" . $content . "}\n";
            }
        }

        return $css;
    }
}
