<?php

declare(strict_types=1);

namespace Talea\Builder;

use Talea\Core\App;
use Talea\Core\WpContent;

/**
 * Page build: a tree of elements {"v": 1, "children": [{"id", "type", "tag", "content", "style", "classes", "anchor", "children"}]}.
 *
 * The single validator for the editor, MCP and API (sanitize) and the single renderer in PHP (render) – what the editor shows is exactly the site.
 * An invalid part of the tree is fixed or discarded and reported during sanitizing; the public site never throws an exception.
 */
final class Build
{
    public const int VERSION = 1;
    public const int MAX_ELEMENTS = 800;
    public const int MAX_DEPTH = 12;
    public const string CLASS_PATTERN = '/^[a-z][a-z0-9-]{0,40}(__[a-z0-9-]{1,30})?(--[a-z0-9-]{1,30})?$/';

    /**
     * Custom attributes of an element: only harmless ones – no on…, style, href or src, and none of the hooks the site's scripts read
     * (image/web.js, image/vitals.js, the cookie bar and accessibility toolbar scripts, image/editor.js on the page): a hook such as
     * data-leaflet, data-attribution or data-basket makes a script load a file or build a link from the value (3.3.2, N25). The list is
     * checked by tests/Unit/SecurityReleaseTest.php against every data-* attribute those scripts mention; the renderer checks a stored build again.
     */
    public const string ATTRIBUTE_PATTERN = '/^(data-(?!tl-|(?:accessibility|accessibility-option|address|admin-url|advance|again|attribution|base|basket|basket-field|basket-list|basket-sent|before-after|book|booking|calendar|campaign|carousel|category|close|collection|compare|compare-url|confirmation|consent|conversion|cookies|copy|countdown|counter|currency|day|days|days-url|device|distance|done|draft|draft-url|editor|empty|enabled|end|estimate|experiment|field|file|form|frequency|fullscreen|gallery|gtm|image|insert|lat|leaflet|lng|locator|logic|map|message|nearest|no-script|open|option|part|popup|price|price-per|product|recaptcha|referrer|request|restore|search|selected|self|sent|services|share|slideshow|slots|step|steps|tabs|text|text-declined|text-error|text-sorted|texts|theme|theme-default|theme-option|times|title|trigger|upload|utm|value|variant|variants|vitals|wait|when|when-value)$)[a-z0-9-]{1,30}|aria-[a-z]{2,20}|title|lang|role|rel)$/i';

    /** Registry of element types (order = the order in the Add panel). @var list<class-string<Element>> */
    public const array ELEMENTS = [
        Elements\Section::class, Elements\Container::class, Elements\Grid::class,
        Elements\Heading::class, Elements\Text::class, Elements\Image::class, Elements\Button::class, Elements\BulletList::class,
        Elements\Quote::class, Elements\Faq::class, Elements\Video::class, Elements\Divider::class,
        Elements\Icon::class, Elements\Gallery::class, Elements\Tabs::class, Elements\Carousel::class, Elements\PricingTable::class, Elements\BeforeAfter::class, Elements\Hotspots::class, Elements\Timeline::class,
        Elements\Map::class, Elements\Embed::class, Elements\Breadcrumbs::class,
        Elements\Counter::class, Elements\Progress::class, Elements\Rating::class, Elements\Countdown::class, Elements\SocialLinks::class, Elements\Search::class,
        Elements\News::class, Elements\GoogleReviews::class, Elements\CollectionList::class, Elements\EnquiryButton::class, Elements\StoreLocator::class, Elements\Form::class, Elements\FormEmbed::class, Elements\Booking::class, Elements\Newsletter::class, Elements\Component::class, Elements\Html::class, Elements\BackToTop::class,
        Elements\Logo::class, Elements\Navigation::class, Elements\LanguageSwitcher::class, Elements\MemberMenu::class, Elements\StructuredData::class, Elements\CompanyDetails::class, Elements\PageContent::class,
    ];

    /** @return class-string<Element>|null */
    public static function className(string $type): ?string
    {
        foreach (self::ELEMENTS as $className) {
            if ($className::TYPE === $type) {
                return $className;
            }
        }

        return null;
    }

    public static function newId(): string
    {
        return substr(bin2hex(random_bytes(4)), 0, 7);
    }

    /** A new element of the given type with the default content and style (for the editor, the library and the HTML conversion). */
    public static function fresh(string $type, array $content = [], array $children = []): array
    {
        $className = self::className($type) ?? throw new \InvalidArgumentException('Unknown element type ' . $type);
        $defaults = array_map(fn (array $field): mixed => $field['default'] ?? '', $className::properties());

        return ['id' => self::newId(), 'type' => $type, 'tag' => $className::HTML_TAGS[0], 'content' => $content + $defaults, 'style' => $className::defaultStyle(), 'classes' => [], 'children' => $children];
    }

    /**
     * Sanitizes a tree from anyone (editor, AI, import). Unknown types, properties and values are discarded, missing ones filled
     * with defaults, duplicate or missing ids created anew.
     *
     * @param bool $admin can change ADMIN_ONLY elements (custom HTML); for others their content is taken over from $previous
     * @param array<string, mixed>|null $previous the existing build (because of ADMIN_ONLY elements)
     * @return array{0: array<string, mixed>, 1: array<string, string>} [build, errors path => text]
     */
    public static function sanitize(mixed $input, bool $admin = true, ?array $previous = null): array
    {
        $errors = [];
        $used = [];
        $count = 0;
        $protected = $admin || $previous === null ? [] : self::elementsOfType($previous, fn (string $className): bool => $className::ADMIN_ONLY);
        $children = is_array($input) && is_array($input['children'] ?? null) ? $input['children'] : (is_array($input) && array_is_list($input) ? $input : []);
        if (!is_array($input)) {
            $errors['build'] = 'The build must be an object {"v": 1, "children": [...]}.';
        }
        $build = ['v' => self::VERSION, 'children' => self::sanitizeChildren($children, 'children', 1, $errors, $used, $count, $admin, $protected)];

        return [$build, $errors];
    }

    /** @param array<string, array<string, mixed>> $protected */
    private static function sanitizeChildren(array $children, string $path, int $depth, array &$errors, array &$used, int &$count, bool $admin, array $protected, bool $compose = false): array
    {
        $output = [];
        foreach (array_values($children) as $i => $p) {
            $place = $path . '[' . $i . ']';
            if (!is_array($p) || ($className = self::className((string) ($p['type'] ?? ''))) === null) {
                $errors[$place] = 'Unknown element type “' . mb_substr((string) (is_array($p) ? ($p['type'] ?? '') : ''), 0, 30) . '” – left out. Types: ' . implode(', ', array_map(fn (string $t): string => $t::TYPE, self::ELEMENTS)) . '.';
                continue;
            }
            if (++$count > self::MAX_ELEMENTS) {
                $errors[$place] = 'The build has more than ' . self::MAX_ELEMENTS . ' elements – the rest is left out.';
                break;
            }
            $id = is_string($p['id'] ?? null) && preg_match('/^[a-z0-9]{3,16}$/', $p['id']) && !isset($used[$p['id']]) ? $p['id'] : self::newId();
            $used[$id] = true;
            if ($className::ADMIN_ONLY && !$admin) {
                if (!isset($protected[$id])) {
                    $errors[$place] = 'The element “' . $className::NAME . '” can only be inserted by the site administrator – left out.';
                    continue;
                }
                $output[] = $protected[$id]; // the custom HTML content does not change, it can only stay in place
                continue;
            }
            $htmlTag = in_array($p['tag'] ?? null, $className::HTML_TAGS, true) ? $p['tag'] : $className::HTML_TAGS[0];
            $clean = ['id' => $id, 'type' => $className::TYPE, 'tag' => $htmlTag, 'content' => self::sanitizeContent($className::properties(), is_array($p['content'] ?? null) ? $p['content'] : [], $place . '.content', $errors)];
            $clean['content'] = $className::sanitizeElement($clean['content'], $place . '.content', $errors);
            $style = Style::sanitize($p['style'] ?? [], $place . '.style', $errors);
            if (!$compose) {
                // placement on the grid and layers (so overlap) exist only for the direct children of a Compose section
                foreach ($style as $state => $properties) {
                    foreach (array_intersect_key($properties, array_flip(Style::COMPOSE_ONLY)) as $key => $value) {
                        unset($style[$state][$key]);
                        $errors[$place . '.style.' . $state . '.' . $key] = 'Only a direct child of a Section with the layout “compose” can be placed on the grid – left out.';
                    }
                    if ($style[$state] === []) {
                        unset($style[$state]);
                    }
                }
            }
            if ($style !== []) {
                $clean['style'] = $style;
            }
            $classes = array_values(array_unique(array_filter(is_array($p['classes'] ?? null) ? $p['classes'] : [], fn (mixed $t): bool => is_string($t) && preg_match(self::CLASS_PATTERN, $t) === 1)));
            if ($classes !== []) {
                $clean['classes'] = array_slice($classes, 0, 8);
            }
            if (is_string($p['anchor'] ?? null) && preg_match('/^[a-z][a-z0-9-]{0,40}$/', $p['anchor'])) {
                // anchor = id on the page: it must be unique and must not clash with a layout id or with another element's style (s-…)
                if (isset($used['kotva:' . $p['anchor']]) || !\Talea\Core\Html::isSafeId($p['anchor']) || preg_match('/^(s|ka)-/', $p['anchor'])) {
                    $errors[$place . '.anchor'] = 'The anchor “' . $p['anchor'] . '” is already used on the page by another element or the layout – left out.';
                } else {
                    $clean['anchor'] = $p['anchor'];
                    $used['kotva:' . $p['anchor']] = true;
                }
            }
            if (is_string($p['label'] ?? null) && trim($p['label']) !== '') {
                $clean['label'] = mb_substr(trim(strip_tags($p['label'])), 0, 60); // element name in the editor's tree
            }
            // the element's custom CSS: only safe declarations (as with classes)
            if (is_string($p['css'] ?? null) && trim($p['css']) !== '') {
                $discarded = [];
                $css = Style::customCss(mb_substr($p['css'], 0, 2000), $discarded);
                if ($css !== '') {
                    $clean['css'] = $css;
                }
                foreach ($discarded as $d) {
                    $errors[$place . '.css'] = 'Disallowed declaration: ' . mb_substr($d, 0, 60);
                }
            }
            // custom attributes: data-*, aria-*, title, lang, role, rel – the values are escaped when rendering
            if (is_array($p['attributes'] ?? null)) {
                $attributes = [];
                foreach (array_slice($p['attributes'], 0, 10, true) as $name => $value) {
                    if (is_string($name) && is_scalar($value) && preg_match(self::ATTRIBUTE_PATTERN, $name)) {
                        $attributes[strtolower($name)] = mb_substr((string) $value, 0, 200); // Renderer::customAttributes() repeats the check when rendering
                    } else {
                        $errors[$place . '.attributes'] = 'An attribute can only be data-…, aria-…, title, lang, role or rel.';
                    }
                }
                if ($attributes !== []) {
                    $clean['attributes'] = $attributes;
                }
            }
            // display conditions: signed-in / signed-out visitors, from and to a date (inclusive), language versions, a URL parameter
            if (is_array($p['conditions'] ?? null)) {
                $conditions = self::sanitizeConditions($p['conditions'], $place . '.conditions', $errors);
                if ($conditions !== []) {
                    $clean['conditions'] = $conditions;
                }
            }
            if (($p['locked'] ?? false) === true) {
                $clean['locked'] = true; // in the editor it cannot be selected or dragged on the canvas
            }
            if ($className::CONTAINER) {
                if ($depth >= self::MAX_DEPTH) {
                    $errors[$place . '.children'] = 'Nesting is too deep – the nested elements are left out.';
                    $clean['children'] = [];
                } else {
                    $clean['children'] = self::sanitizeChildren(is_array($p['children'] ?? null) ? $p['children'] : [], $place . '.children', $depth + 1, $errors, $used, $count, $admin, $protected, $className === Elements\Section::class && ($clean['content']['layout'] ?? '') === 'compose');
                }
            } elseif (!empty($p['children'])) {
                $errors[$place . '.children'] = 'The element “' . $className::NAME . '” cannot contain other elements – left out.';
            }
            $output[] = $clean;
        }

        return $output;
    }

    /** Name of a URL parameter a display condition may look at (short, letters, digits, _ and -). */
    public const string PARAMETER_NAME_PATTERN = '/^[a-z][a-z0-9_-]{0,39}$/i';

    /** Its expected value: short and without characters that would need escaping anywhere. */
    public const string PARAMETER_VALUE_PATTERN = '/^[a-z0-9_.-]{1,80}$/i';

    /**
     * Display conditions of an element: signed_in (yes|no), from/to (YYYY-MM-DD, inclusive), languages (language versions, '' = the
     * default language) and url_parameter {name, value?} (the page address has a query parameter, optionally with an exact value).
     *
     * @param array<string, mixed> $input
     * @param array<string, string> $errors
     * @return array<string, mixed>
     */
    public static function sanitizeConditions(array $input, string $place, array &$errors): array
    {
        $conditions = [];
        if (in_array($input['signed_in'] ?? '', ['yes', 'no'], true)) {
            $conditions['signed_in'] = $input['signed_in'];
        }
        foreach (['from', 'to'] as $bound) {
            $date = (string) ($input[$bound] ?? '');
            if ($date !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) && strtotime($date) !== false) {
                $conditions[$bound] = $date;
            } elseif ($date !== '') {
                $errors[$place] = 'The date of the display condition must be in the form YYYY-MM-DD.';
            }
        }
        if (is_array($input['languages'] ?? null)) {
            $languages = [];
            foreach ($input['languages'] as $code) {
                if (is_string($code) && ($code === '' || isset(\Talea\Core\Language::AVAILABLE[$code]))) {
                    $languages[$code] = true;
                } else {
                    $errors[$place] = 'The language of the display condition must be a language version code of the site ("" = the default language).';
                }
            }
            if ($languages !== []) {
                $conditions['languages'] = array_keys($languages);
            }
        }
        if (is_array($input['url_parameter'] ?? null) || is_string($input['url_parameter'] ?? null)) {
            // a bare string is the parameter name (shorthand for {name})
            $parameter = is_array($input['url_parameter']) ? $input['url_parameter'] : ['name' => $input['url_parameter']];
            $name = is_string($parameter['name'] ?? null) ? trim($parameter['name']) : '';
            $value = is_scalar($parameter['value'] ?? null) ? trim((string) $parameter['value']) : '';
            if ($name === '' || !preg_match(self::PARAMETER_NAME_PATTERN, $name)) {
                if ($name !== '' || $value !== '') {
                    $errors[$place] = 'URL parameter name: 1–40 characters, letters without diacritics, digits, _ and -.';
                }
            } elseif ($value !== '' && !preg_match(self::PARAMETER_VALUE_PATTERN, $value)) {
                $errors[$place] = 'URL parameter value: 1–80 characters, letters without diacritics, digits, _ . and -.';
            } else {
                $conditions['url_parameter'] = ['name' => $name] + ($value !== '' ? ['value' => $value] : []);
            }
        }

        return $conditions;
    }

    /**
     * Whether a page with these conditions must be assembled anew for every visit: a language version has its own address
     * (and its own entry in the page cache), everything else (date, sign-in, URL parameter) can differ between two visits
     * of the same address.
     *
     * @param array<string, mixed> $conditions
     */
    public static function conditionsBypassCache(array $conditions): bool
    {
        return array_diff(array_keys($conditions), ['languages']) !== [];
    }

    /** An image property: an https:// address, a file from Media or a collection field. */
    public const string IMAGE_PATTERN = '#^(https://[^\s"\'<>]{1,500}|/?([A-Za-z0-9_.-]+/){0,3}media/[A-Za-z0-9/_.-]{1,300}|\{\{[a-z][a-z0-9_]{0,30}\}\})$#';

    /** @param array<string, array<string, mixed>> $field */
    private static function sanitizeContent(array $field, array $content, string $path, array &$errors): array
    {
        $clean = [];
        foreach ($field as $key => $def) {
            $value = $content[$key] ?? $def['default'] ?? '';
            $max = (int) ($def['max'] ?? 5000);
            $clean[$key] = match ($def['type']) {
                'text' => mb_substr(trim(strip_tags(is_scalar($value) ? (string) $value : '')), 0, $max),
                'lines' => mb_substr(strip_tags(is_scalar($value) ? (string) $value : ''), 0, $max),
                'inline_text' => self::inline(is_scalar($value) ? (string) $value : '', $max),
                'html' => WpContent::safeHtml(is_scalar($value) ? mb_substr((string) $value, 0, 200000) : ''),
                'code' => CodeFilter::code(is_scalar($value) ? mb_substr((string) $value, 0, $max) : ''),
                'link' => self::link(is_scalar($value) ? (string) $value : '', $path . '.' . $key, $errors),
                'image' => is_string($value) && preg_match(self::IMAGE_PATTERN, $value) ? $value : '',
                // the rules and results of a form: cleaned by Elements\Form::sanitizeElement (it knows the fields); here only the shape
                'form_logic' => is_array($value) ? $value : ['rules' => [], 'results' => []],
                // a form placement's widths: field key => columns of six (Builder\FormRecords)
                'form_widths' => FormRecords::widths($value),
                'choice' => is_scalar($value) && isset($def['options'][(string) $value]) ? (string) $value : (string) $def['default'],
                'number' => is_numeric($value) ? max((int) ($def['min'] ?? 0), min((int) ($def['max'] ?? 100), (int) $value)) : (int) $def['default'],
                'boolean' => (bool) $value,
                // typed schema.org data: {type, fields}, cleaned against the curated vocabulary (Builder\StructuredData); anything else becomes empty
                'structured' => StructuredData::sanitize($value)[0] ?? [],
                // component property values: only key => text; they are checked by the property type when rendering
                'values' => array_slice(array_filter(
                    array_map(fn (mixed $v): ?string => is_scalar($v) ? mb_substr((string) $v, 0, 20000) : null, is_array($value) ? $value : []),
                    fn (?string $v, int|string $k): bool => $v !== null && is_string($k) && preg_match('/^[a-z][a-z0-9_]{0,30}$/', $k) === 1,
                    ARRAY_FILTER_USE_BOTH,
                ), 0, 30, true),
                // a closure, not an arrow function: an arrow function copies $errors, so a rejected link or image inside an item would go unreported
                'items' => array_slice(array_values(array_map(
                    function (mixed $item) use ($def, $path, $key, &$errors): array { return self::sanitizeContent($def['fields'], is_array($item) ? $item : [], $path . '.' . $key, $errors); },
                    is_array($value) ? $value : [],
                )), 0, (int) ($def['max'] ?? 30)),
                default => '',
            };
            if ($def['type'] === 'image' && $value !== '' && $clean[$key] === '') {
                $errors[$path . '.' . $key] = 'The image must come from Media (media/…) or be at an https:// address.';
            }
        }

        return $clean;
    }

    /** Short text with bold, italic, highlight (mark = the accent color, e.g. a dot after a title), line break and link – nothing else. */
    private static function inline(string $html, int $max): string
    {
        $clean = WpContent::safeHtml('<p>' . mb_substr($html, 0, $max * 2) . '</p>');
        $clean = (string) preg_replace('#^<p>|</p>$#', '', trim($clean));
        $clean = strip_tags($clean, '<strong><b><em><i><mark><br><a><s><sub><sup>');

        return trim(str_replace(['</p>', '<p>'], ['<br>', ''], $clean));
    }

    /** @param array<string, string> $errors */
    public static function link(string $url, string $path, array &$errors): string
    {
        $url = trim($url);
        if ($url === '' || $url === '#' || preg_match('/^\{\{[a-z][a-z0-9_]{0,30}\}\}$/', $url)) {
            return $url; // {{url}} and other collection fields: filled in and checked when rendering
        }
        if (preg_match('/^(tel:|mailto:)?\{\{\s*fact\.[a-z][a-z0-9_]{1,39}\s*\}\}$/', $url)) {
            return $url; // a business fact (2.10): tel:{{fact.company_phone}}, mailto:{{fact.company_email}}, {{fact.booking_url}}
        }
        if (WpContent::isSafeUrl($url) && !preg_match('/[\s"<>]/', $url)) {
            return mb_substr($url, 0, 500);
        }
        $errors[$path] = 'A link can only be https://…, mailto:, tel:, #anchor or an address on the site (/…).';

        return '';
    }

    /** @return array<string, array<string, mixed>> id => element for all elements whose class meets the condition */
    public static function elementsOfType(array $build, callable $condition): array
    {
        $found = [];
        $walk = function (array $children) use (&$walk, &$found, $condition): void {
            foreach ($children as $p) {
                $className = is_array($p) ? self::className((string) ($p['type'] ?? '')) : null;
                if ($className !== null && $condition($className) && isset($p['id'])) {
                    $found[(string) $p['id']] = $p;
                }
                if (is_array($p['children'] ?? null)) {
                    $walk($p['children']);
                }
            }
        };
        $walk($build['children'] ?? []);

        return $found;
    }

    /** Renders a build: HTML, page CSS and FAQ entries (Renderer). @return array{html:string, css:string, faq:list<array{0:string, 1:string}>} */
    public static function render(App $app, array $build, bool $editor = false): array
    {
        return Renderer::render($app, $build, $editor);
    }

    /** A build from a page: the published one, or the draft (editor, preview). Invalid JSON = null. */
    public static function fromJson(?string $json): ?array
    {
        $data = $json === null ? null : json_decode($json, true);

        return is_array($data) && isset($data['children']) ? $data : null;
    }

    public static function toJson(array $build): string
    {
        return (string) json_encode($build, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Build content as plain semantic HTML without layout and style (headings, paragraphs, lists, links, images).
     * On publish it is saved into the text column – search, llms.txt, the .md version, API, MCP and export draw on it, and it is
     * also the page content if the page went back to text.
     */
    public static function asText(array $build): string
    {
        $html = '';
        $walk = function (array $children) use (&$walk, &$html): void {
            foreach ($children as $p) {
                $o = $p['content'] ?? [];
                $z = preg_match('/^h[1-6]$/', $p['tag'] ?? '') ? $p['tag'] : 'p';
                $html .= match ($p['type'] ?? '') {
                    'heading' => "<{$z}>" . ($o['text'] ?? '') . "</{$z}>\n",
                    'text' => ($o['html'] ?? '') . "\n",
                    'image' => ($o['src'] ?? '') !== '' ? '<figure><img src="' . e($o['src']) . '" alt="' . e($o['alt'] ?? '') . '">' . (($o['caption'] ?? '') !== '' ? '<figcaption>' . e($o['caption']) . '</figcaption>' : '') . "</figure>\n" : '',
                    'button' => ($o['text'] ?? '') !== '' ? '<p>' . (($o['link'] ?? '') !== '' ? '<a href="' . e($o['link']) . '">' . e($o['text']) . '</a>' : e($o['text'])) . "</p>\n" : '',
                    'list' => ($rows = array_filter(array_map('trim', explode("\n", (string) ($o['items'] ?? ''))))) !== []
                        ? "<{$p['tag']}>" . implode('', array_map(fn (string $r): string => '<li>' . e($r) . '</li>', $rows)) . "</{$p['tag']}>\n" : '',
                    'testimonial' => '<blockquote><p>' . ($o['text'] ?? '') . '</p>' . (($o['author'] ?? '') !== '' ? '<p>– ' . e($o['author']) . (($o['position'] ?? '') !== '' ? ', ' . e($o['position']) : '') . '</p>' : '') . "</blockquote>\n",
                    'faq' => implode('', array_map(fn (array $f): string => '<h3>' . e($f['question'] ?? '') . '</h3>' . ($f['answer'] ?? '') . "\n", $o['items'] ?? [])),
                    'video' => ($o['url'] ?? '') !== '' ? '<p><a href="' . e($o['url']) . '">' . e(($o['title'] ?? '') !== '' ? $o['title'] : $o['url']) . "</a></p>\n" : '',
                    'divider' => "<hr>\n",
                    // the counter states a number (or a fact token, 2.10) with its label – content, so facts see it too
                    'counter' => (string) ($o['number'] ?? '') !== '' ? '<p>' . e((string) ($o['prefix'] ?? '') . (string) $o['number'] . (string) ($o['suffix'] ?? '')) . ((string) ($o['caption'] ?? '') !== '' ? ' ' . e((string) $o['caption']) : '') . "</p>\n" : '',
                    // 2.12: plans, points and milestones are content too – search and the .md version see the prices and features
                    'pricing_table' => implode('', array_map(fn (array $plan): string => ($plan['name'] ?? '') === '' ? '' : '<h3>' . e($plan['name']) . '</h3>'
                        . (($plan['price'] ?? '') !== '' ? '<p>' . e(trim($plan['price'] . ' ' . ($plan['period'] ?? ''))) . '</p>' : '') . (($plan['description'] ?? '') !== '' ? '<p>' . e($plan['description']) . '</p>' : '')
                        . (($rows = Elements\PricingTable::features((string) ($plan['features'] ?? ''))) !== [] ? '<ul>' . implode('', array_map(fn (array $f): string => '<li>' . e($f[1]) . ($f[0] ? '' : ' (' . e(t('not included')) . ')') . '</li>', $rows)) . '</ul>' : '') . "\n", $o['plans'] ?? [])),
                    'hotspots' => ($rows = array_filter($o['points'] ?? [], fn (array $b): bool => ($b['name'] ?? '') !== '')) !== []
                        ? '<ol>' . implode('', array_map(fn (array $b): string => '<li>' . e($b['name']) . (($b['description'] ?? '') !== '' ? ' – ' . e($b['description']) : '') . '</li>', $rows)) . "</ol>\n" : '',
                    'timeline' => implode('', array_map(fn (array $m): string => ($m['name'] ?? '') === '' && ($m['date'] ?? '') === '' ? '' : '<h3>' . e(implode(' – ', array_filter([$m['date'] ?? '', $m['name'] ?? ''], fn (string $s): bool => $s !== ''))) . '</h3>' . ($m['content'] ?? '') . "\n", $o['milestones'] ?? [])),
                    default => '',
                };
                // the inside of a Collection list is a pattern with {{tags}}, not page content
                if (is_array($p['children'] ?? null) && ($p['type'] ?? '') !== 'collection_list') {
                    $walk($p['children']);
                }
            }
        };
        $walk($build['children'] ?? []);

        return trim($html);
    }

    /** A text page converted to a build: one narrow section with a heading and text (going back works through versions). */
    public static function fromText(string $title, string $html): array
    {
        return ['v' => self::VERSION, 'children' => [self::fresh('section', ['width' => 'narrow'], [
            ['tag' => 'h1'] + self::fresh('heading', ['text' => e($title)]),
            self::fresh('text', ['html' => $html !== '' ? $html : '<p></p>']),
        ])]];
    }

    /**
     * A build without what sanitize() fills in: default content, default tag, empty style, classes and children. The style stays whole –
     * only a new element from the builder gets the type's default style (container flex), a saved element without a style would lose it. For output to MCP –
     * the model reads and sends only what is set (the same build is roughly a third of the length).
     *
     * @param array<string, mixed> $build
     * @return array<string, mixed>
     */
    public static function compact(array $build): array
    {
        $node = function (array $p) use (&$node): array {
            $className = self::className((string) ($p['type'] ?? ''));
            if ($className !== null) {
                foreach ($className::properties() as $key => $v) {
                    if (array_key_exists($key, $p['content'] ?? []) && $p['content'][$key] === ($v['default'] ?? null)) {
                        unset($p['content'][$key]);
                    }
                }
                if (($p['tag'] ?? null) === $className::HTML_TAGS[0]) {
                    unset($p['tag']);
                }
            }
            foreach (['content', 'style', 'classes', 'children', 'conditions', 'attributes'] as $key) {
                if (array_key_exists($key, $p) && ($p[$key] === [] || $p[$key] === null || $p[$key] === '')) {
                    unset($p[$key]);
                }
            }
            if (isset($p['children'])) {
                $p['children'] = array_map($node, $p['children']);
            }

            return $p;
        };

        return ['v' => $build['v'] ?? self::VERSION, 'children' => array_map($node, $build['children'] ?? [])];
    }

    /** Element property types that carry text for the visitor or a link (build translation). */
    private const array TEXT_PROPERTIES = ['text', 'link', 'lines', 'html', 'inline_text', 'long_text', 'items', 'consent'];

    /** Text properties that are settings, not text for the visitor (collection and field keys, e-mail, date, rating number). */
    private const array TECHNICAL_PROPERTIES = ['collection', 'sort_field', 'filter_field', 'location_field', 'component', 'category', 'recipient', 'bcc', 'target', 'value'];

    /**
     * Build texts for translation: elements with an id and only those content properties that carry text or a link (without styles
     * and structure), plus labels for screen readers in the attributes. Changes come back through the “update” operation by id (Edits).
     *
     * @param array<string, mixed> $build
     * @return list<array{id: string, type: string, content?: array<string, mixed>, attributes?: array<string, string>}>
     */
    public static function texts(array $build): array
    {
        $result = [];
        $walk = function (array $elements) use (&$walk, &$result): void {
            foreach ($elements as $p) {
                $className = self::className((string) ($p['type'] ?? ''));
                $content = [];
                foreach ($className !== null ? $className::properties() : [] as $key => $v) {
                    $value = $p['content'][$key] ?? null;
                    if (in_array($v['type'] ?? '', self::TEXT_PROPERTIES, true) && !in_array($key, self::TECHNICAL_PROPERTIES, true) && $value !== null && $value !== '' && $value !== []) {
                        $content[$key] = $value;
                    }
                }
                $attributes = array_intersect_key(is_array($p['attributes'] ?? null) ? $p['attributes'] : [], ['aria-label' => 1, 'title' => 1]);
                if (($content !== [] || $attributes !== []) && isset($p['id'])) {
                    $result[] = ['id' => (string) $p['id'], 'type' => (string) $p['type']] + ($content !== [] ? ['content' => $content] : []) + ($attributes !== [] ? ['attributes' => $attributes] : []);
                }
                $walk(is_array($p['children'] ?? null) ? $p['children'] : []);
            }
        };
        $walk($build['children'] ?? []);

        return $result;
    }
}
