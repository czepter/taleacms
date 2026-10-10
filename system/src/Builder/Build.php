<?php

declare(strict_types=1);

namespace Kaleta\Builder;

use Kaleta\Core\App;
use Kaleta\Core\Db;
use Kaleta\Core\WpContent;

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
     * checked by tools/unit-tests.php against every data-* attribute those scripts mention; the renderer checks a stored build again.
     */
    public const string ATTRIBUTE_PATTERN = '/^(data-(?!ka-|(?:accessibility|accessibility-option|address|admin-url|again|attribution|base|basket|basket-field|basket-list|basket-sent|before-after|book|booking|calendar|campaign|carousel|category|close|collection|compare|compare-url|confirmation|consent|conversion|cookies|copy|countdown|counter|currency|day|days|days-url|device|distance|done|draft|draft-url|editor|empty|enabled|end|estimate|file|form|frequency|gtm|image|insert|lat|leaflet|lng|locator|map|message|nearest|no-script|open|option|part|popup|price|price-per|product|recaptcha|referrer|request|restore|search|selected|self|sent|services|share|slots|step|steps|tabs|text|text-declined|text-error|text-sorted|texts|theme|theme-default|theme-option|times|title|trigger|upload|utm|value|vitals|wait|when|when-value)$)[a-z0-9-]{1,30}|aria-[a-z]{2,20}|title|lang|role|rel)$/i';

    /** Stands for the page content in a site-part wrapper until the build's own tokens are filled (html()). */
    private const string CONTENT_MARK = "\u{E000}ka-page-content\u{E000}";

    /** Ids used by the site layout (skip to content, navigation, cookie bar) – an element's anchor must not repeat them. */
    public const array RESERVED_ANCHORS = ['main', 'navigation', 'cookies-bar', 'cookies-heading', 'cookies-reopen'];

    /** Registry of element types (order = the order in the Add panel). @var list<class-string<Element>> */
    public const array ELEMENTS = [
        Elements\Section::class, Elements\Container::class, Elements\Grid::class,
        Elements\Heading::class, Elements\Text::class, Elements\Image::class, Elements\Button::class, Elements\BulletList::class,
        Elements\Quote::class, Elements\Faq::class, Elements\Video::class, Elements\Divider::class,
        Elements\Icon::class, Elements\Gallery::class, Elements\Tabs::class, Elements\Carousel::class, Elements\PricingTable::class, Elements\BeforeAfter::class, Elements\Hotspots::class, Elements\Timeline::class,
        Elements\Map::class, Elements\Embed::class, Elements\Breadcrumbs::class,
        Elements\Counter::class, Elements\Progress::class, Elements\Rating::class, Elements\Countdown::class, Elements\SocialLinks::class, Elements\Search::class,
        Elements\News::class, Elements\GoogleReviews::class, Elements\CollectionList::class, Elements\EnquiryButton::class, Elements\StoreLocator::class, Elements\Form::class, Elements\Booking::class, Elements\Newsletter::class, Elements\Component::class, Elements\Html::class, Elements\BackToTop::class,
        Elements\Logo::class, Elements\Navigation::class, Elements\LanguageSwitcher::class, Elements\StructuredData::class, Elements\CompanyDetails::class, Elements\PageContent::class,
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
        $className = self::className($type) ?? throw new \InvalidArgumentException('Neznámý typ prvku ' . $type);
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
            $errors['build'] = 'Stavba musí být objekt {"v": 1, "children": [...]}.';
        }
        $build = ['v' => self::VERSION, 'children' => self::sanitizeChildren($children, 'children', 1, $errors, $used, $count, $admin, $protected)];

        return [$build, $errors];
    }

    /** @param array<string, array<string, mixed>> $protected */
    private static function sanitizeChildren(array $children, string $path, int $depth, array &$errors, array &$used, int &$count, bool $admin, array $protected): array
    {
        $output = [];
        foreach (array_values($children) as $i => $p) {
            $place = $path . '[' . $i . ']';
            if (!is_array($p) || ($className = self::className((string) ($p['type'] ?? ''))) === null) {
                $errors[$place] = 'Neznámý typ prvku „' . mb_substr((string) (is_array($p) ? ($p['type'] ?? '') : ''), 0, 30) . '“ – vynechán. Typy: ' . implode(', ', array_map(fn (string $t): string => $t::TYPE, self::ELEMENTS)) . '.';
                continue;
            }
            if (++$count > self::MAX_ELEMENTS) {
                $errors[$place] = 'Stavba má víc než ' . self::MAX_ELEMENTS . ' prvků – zbytek vynechán.';
                break;
            }
            $id = is_string($p['id'] ?? null) && preg_match('/^[a-z0-9]{3,16}$/', $p['id']) && !isset($used[$p['id']]) ? $p['id'] : self::newId();
            $used[$id] = true;
            if ($className::ADMIN_ONLY && !$admin) {
                if (!isset($protected[$id])) {
                    $errors[$place] = 'Prvek „' . $className::NAME . '“ smí vložit jen správce webu – vynechán.';
                    continue;
                }
                $output[] = $protected[$id]; // the custom HTML content does not change, it can only stay in place
                continue;
            }
            $htmlTag = in_array($p['tag'] ?? null, $className::HTML_TAGS, true) ? $p['tag'] : $className::HTML_TAGS[0];
            $clean = ['id' => $id, 'type' => $className::TYPE, 'tag' => $htmlTag, 'content' => self::sanitizeContent($className::properties(), is_array($p['content'] ?? null) ? $p['content'] : [], $place . '.content', $errors)];
            $style = Style::sanitize($p['style'] ?? [], $place . '.style', $errors);
            if ($style !== []) {
                $clean['style'] = $style;
            }
            $classes = array_values(array_unique(array_filter(is_array($p['classes'] ?? null) ? $p['classes'] : [], fn (mixed $t): bool => is_string($t) && preg_match(self::CLASS_PATTERN, $t) === 1)));
            if ($classes !== []) {
                $clean['classes'] = array_slice($classes, 0, 8);
            }
            if (is_string($p['anchor'] ?? null) && preg_match('/^[a-z][a-z0-9-]{0,40}$/', $p['anchor'])) {
                // anchor = id on the page: it must be unique and must not clash with a layout id or with another element's style (s-…)
                if (isset($used['kotva:' . $p['anchor']]) || in_array($p['anchor'], self::RESERVED_ANCHORS, true) || preg_match('/^(s|ka)-/', $p['anchor'])) {
                    $errors[$place . '.anchor'] = 'Kotvu „' . $p['anchor'] . '“ už na stránce používá jiný prvek nebo šablona – vynechána.';
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
                    $errors[$place . '.css'] = 'Nepovolená deklarace: ' . mb_substr($d, 0, 60);
                }
            }
            // custom attributes: data-*, aria-*, title, lang, role, rel – the values are escaped when rendering
            if (is_array($p['attributes'] ?? null)) {
                $attributes = [];
                foreach (array_slice($p['attributes'], 0, 10, true) as $name => $value) {
                    if (is_string($name) && is_scalar($value) && preg_match(self::ATTRIBUTE_PATTERN, $name)) {
                        $attributes[strtolower($name)] = mb_substr((string) $value, 0, 200); // Build::customAttributes() repeats the check when rendering
                    } else {
                        $errors[$place . '.attributes'] = 'Atribut může být jen data-…, aria-…, title, lang, role nebo rel.';
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
                    $errors[$place . '.children'] = 'Příliš hluboké vnoření – vnořené prvky vynechány.';
                    $clean['children'] = [];
                } else {
                    $clean['children'] = self::sanitizeChildren(is_array($p['children'] ?? null) ? $p['children'] : [], $place . '.children', $depth + 1, $errors, $used, $count, $admin, $protected);
                }
            } elseif (!empty($p['children'])) {
                $errors[$place . '.children'] = 'Prvek „' . $className::NAME . '“ nemůže obsahovat další prvky – vynechány.';
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
                $errors[$place] = 'Datum podmínky zobrazení musí být ve tvaru RRRR-MM-DD.';
            }
        }
        if (is_array($input['languages'] ?? null)) {
            $languages = [];
            foreach ($input['languages'] as $code) {
                if (is_string($code) && ($code === '' || isset(\Kaleta\Core\Language::AVAILABLE[$code]))) {
                    $languages[$code] = true;
                } else {
                    $errors[$place] = 'Jazyk podmínky zobrazení musí být kód jazykové verze webu („“ = výchozí jazyk).';
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
                    $errors[$place] = 'Název parametru adresy: 1–40 znaků, písmena bez diakritiky, číslice, _ a -.';
                }
            } elseif ($value !== '' && !preg_match(self::PARAMETER_VALUE_PATTERN, $value)) {
                $errors[$place] = 'Hodnota parametru adresy: 1–80 znaků, písmena bez diakritiky, číslice, _ . a -.';
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
                'code' => self::code(is_scalar($value) ? mb_substr((string) $value, 0, $max) : ''),
                'link' => self::link(is_scalar($value) ? (string) $value : '', $path . '.' . $key, $errors),
                'image' => is_string($value) && preg_match('#^(https://[^\s"\'<>]{1,500}|/?([A-Za-z0-9_.-]+/){0,3}media/[A-Za-z0-9/_.-]{1,300}|\{\{[a-z][a-z0-9_]{0,30}\}\})$#', $value) ? $value : '',
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
                $errors[$path . '.' . $key] = 'Obrázek musí být z Médií (media/…) nebo na adrese https://.';
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

    /** Elements the administrator's custom HTML never keeps: they run code, change the page's base or reload it, or hide markup from the parser. */
    private const array CODE_DISCARD = ['script', 'object', 'embed', 'applet', 'base', 'meta', 'link', 'frame', 'frameset', 'portal', 'template', 'noscript',
        'noembed', 'noframes', 'xmp', 'plaintext', 'math', 'animate', 'animatemotion', 'animatetransform', 'set', 'handler', 'foreignobject',
        // parsers disagree on what a <select> may hold (the relaxed content model of newer ones): markup the filter saw as inert
        // could be read as live elsewhere, so none of it is kept
        'select', 'option', 'optgroup', 'datalist', 'selectedcontent'];

    /** Elements whose text the parser does not read as markup: a < inside them is never kept (another parser or context could). */
    private const array CODE_RAWTEXT = ['style', 'iframe', 'script', 'xmp', 'noembed', 'noframes', 'plaintext', 'noscript'];

    /** Attributes holding an address: only http(s), mailto, tel or a relative one (checked after the parser decoded entities). */
    private const array CODE_URLS = ['href', 'src', 'action', 'formaction', 'poster', 'cite', 'data', 'background', 'lowsrc', 'ping', 'longdesc', 'codebase', 'manifest', 'xlink:href'];

    /**
     * The administrator's custom HTML (an administrator-only element). Embedded maps and service forms are <iframe>s and <form>s, those
     * stay; scripts, event handlers, srcdoc, script-like URLs (also entity-encoded or unquoted) and SVG animation of attributes are removed.
     * A DOM pass, not a regular expression – regular expressions over HTML can be bypassed. It repeats until the output parses back to
     * itself, so markup that a browser would read differently after serialization (mutation XSS) is cleaned in the form the browser sees.
     * Runs on save and again when rendering.
     */
    public static function code(string $html): string
    {
        for ($i = 0; $i < 4; $i++) {
            $clean = self::codePass($html);
            if ($clean === $html) {
                return $clean;
            }
            $html = $clean;
        }

        return ''; // never settles – such markup is not an embed code
    }

    private static function codePass(string $html): string
    {
        if (trim($html) === '' || !preg_match('/<|&/', $html)) {
            return $html;
        }
        $doc = \Dom\HTMLDocument::createFromString('<!DOCTYPE html><html><body>' . $html . '</body></html>', LIBXML_NOERROR, 'UTF-8');
        $body = $doc->body;
        if ($body === null) {
            return '';
        }
        self::codeNode($body);

        return \Kaleta\Core\Html::inner($body); // attribute values without a raw < or >
    }

    private static function codeNode(\Dom\Node $node): void
    {
        foreach (iterator_to_array($node->childNodes) as $n) {
            if ($n instanceof \Dom\Comment || ($n instanceof \Dom\Element && (in_array(strtolower($n->localName), self::CODE_DISCARD, true)
                || !preg_match('/^[a-z][a-z0-9-]*$/i', $n->localName)))) {
                $n->remove();
                continue;
            }
            if (!$n instanceof \Dom\Element) {
                continue;
            }
            if (strtolower($n->localName) === 'style' && preg_match('/javascript:|expression\s*\(|@import|behavior\s*:|-moz-binding/i', (string) $n->textContent)) {
                $n->remove();
                continue;
            }
            foreach (iterator_to_array($n->attributes) as $a) {
                $name = strtolower($a->name);
                $ok = !str_starts_with($name, 'on') && $name !== 'srcdoc' && $name !== 'http-equiv'
                    && (!in_array($name, self::CODE_URLS, true) && !str_ends_with($name, ':href') || WpContent::isSafeUrl($a->value) || trim($a->value) === '')
                    && ($name !== 'srcset' || !preg_match('/(javascript|data|vbscript):/i', $a->value))
                    && ($name !== 'style' || !preg_match('/javascript:|expression\s*\(|behavior\s*:|-moz-binding|url\s*\(\s*["\']?\s*(javascript|data|vbscript):/i', $a->value))
                    && ($name !== 'attributename' && $name !== 'formtarget');
                if (!$ok) {
                    $n->removeAttribute($a->name);
                }
            }
            // only HTML's own elements are raw text – an SVG <style> holds parsed markup and is cleaned like any other element
            if ($n->namespaceURI === 'http://www.w3.org/1999/xhtml' && in_array(strtolower($n->localName), self::CODE_RAWTEXT, true) && str_contains((string) $n->textContent, '<')) {
                // CSS keeps its meaning with the escape \3c; the text of a frame is never shown, so it goes
                $n->textContent = strtolower($n->localName) === 'style' ? str_replace('<', '\3c ', (string) $n->textContent) : '';
            }
            self::codeNode($n);
        }
    }

    private static function link(string $url, string $path, array &$errors): string
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
        $errors[$path] = 'Odkaz může být jen https://…, mailto:, tel:, #kotva nebo adresa na webu (/…).';

        return '';
    }

    /** @return array<string, array<string, mixed>> id => element for all elements whose class meets the condition */
    private static function elementsOfType(array $build, callable $condition): array
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

    /**
     * Renders a build: HTML and CSS of only what the page uses (type bases, used classes, element styles) in cascade layers.
     * In editor mode every element gets data-ka-id so that it can be selected on the canvas.
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
        $html = \Kaleta\Extension\Registry::fillTokens(\Kaleta\Core\Facts::fill($html, $k->app));

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
                error_log('Builder: prvek ' . ($p['id'] ?? '?') . ' – ' . $e->getMessage());
                $html .= $k->editor ? '<div data-ka-id="' . e((string) ($p['id'] ?? '')) . '" style="padding:1rem;border:2px dashed #b3261e;color:#b3261e">' . e(t('This element could not be displayed.')) . '</div>' : '';
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
        // the language version being displayed, as the "jazyk" column has it: '' = the site's default language
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
        $className = self::className((string) $p['type']);
        if ($className === null) {
            return '';
        }
        if (($p['conditions'] ?? []) !== [] && !$k->editor) {
            // a page whose content depends on the visit (date, sign-in, URL parameter) is not cached – the page cache
            // ignores tracking parameters such as utm_campaign, so a cached copy could not tell the visits apart
            $k->withoutCache = $k->withoutCache || self::conditionsBypassCache($p['conditions']);
            if (!self::meetsConditions($p['conditions'], $k)) {
                return '';
            }
        }
        if ($className::EXTENSION !== '' && !\Kaleta\Core\Extensions::isEnabled($k->app->settings(), $className::EXTENSION)) {
            // an element of a disabled extension (news, form): nothing on the site, a notice in the editor – the build stays, it comes back once enabled
            return $k->editor ? '<div data-ka-id="' . e((string) ($p['id'] ?? '')) . '" data-ka-type="' . e($className::TYPE) . '" style="padding:1rem;border:2px dashed currentColor;opacity:.6">'
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
            . ($k->editor || $k->markIds ? ' data-ka-id="' . e((string) $p['id']) . '"' : '')
            . ($k->editor ? ' data-ka-type="' . e($className::TYPE) . '"' . (!empty($p['locked']) ? ' data-ka-lock' : '') : '');

        $html = $className::render($p, $a, $children, $k);

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
            if (is_string($name) && is_scalar($value) && preg_match(self::ATTRIBUTE_PATTERN, $name)) {
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

    /** Page CSS: the base of the used types, the used classes (from ka_tridy) and the style of individual elements – each in its own layer. */
    public static function css(Db $db, Context $k): string
    {
        // in a build, spacing is controlled by the containers' gap, not by the layout's margins of headings and paragraphs; text inside a Text element keeps them
        $base = ':where(.build) :where(h1, h2, h3, h4, h5, h6, p, ul, ol, blockquote, figure, hr) { margin-block: 0; }' . "\n"
            . ':where(.build) :where(.ka-text) > * + * { margin-block-start: 1em; }' . "\n";
        foreach (self::ELEMENTS as $className) {
            if (isset($k->types[$className::TYPE]) && $className::baseCss() !== '') {
                $base .= $className::baseCss() . "\n";
            }
        }
        $classes = '';
        if ($k->classes !== []) {
            $names = array_keys($k->classes);
            foreach (\Kaleta\Core\Look::classesForCss($db, $names) as $r) { // the draft classes in a preview of the draft look
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
        if (str_contains($k->css, 'animation: ka-header-')) {
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
     * Schema description for the editor and for language models (MCP builder_schema): element types with fields, style properties and tokens.
     *
     * @return array<string, mixed>
     */
    /** @param list<string>|null $extensions enabled extensions (null = all) – elements of disabled ones are not offered */
    public static function schema(bool $admin = true, string $language = 'cs', bool $parts = false, ?array $extensions = null): array
    {
        // the default content of new elements is in the page language, the editor translates field labels into the admin language
        return \Kaleta\Core\Language::runWith($language, fn (): array => self::buildSchema($admin, $parts, $extensions));
    }

    /**
     * builder_schema (MCP): the schema in brief for the language model – each element and style property on one line (enumerations
     * have the default value marked with an asterisk, items list their fields in square brackets) – or, for the chosen element
     * types (or all of them with $full), their full definitions with labels, default style and default children.
     *
     * @param array<string, mixed> $schema output of schema()
     * @param list<string> $only element types for full definitions
     * @return array<string, mixed>
     */
    public static function overview(array $schema, array $only = [], bool $full = false): array
    {
        $admin = fn (string $text): string => \Kaleta\Core\Language::runWith('en', fn (): string => t($text), 'admin-');
        $definitions = [];
        $lines = [];
        foreach ($schema['elements'] as $p) {
            if ($full || in_array($p['type'], $only, true)) {
                $definitions[] = ['type' => $p['type'], 'name' => $admin($p['name']), 'description' => $admin($p['description']), 'container' => $p['container'], 'tags' => $p['tags'],
                    'fields' => self::fieldDefinitions((array) $p['properties'], $admin), 'default_style' => (array) $p['default_style'] ?: new \stdClass(), 'default_children' => $p['default_children']];
            }
            $style = [];
            foreach ((array) $p['default_style'] as $state => $properties) {
                foreach ((array) $properties as $k => $h) {
                    $style[] = ($state === 'base' ? '' : $state . '.') . $k . '=' . (is_string($h) ? $h : json_encode($h));
                }
            }
            $fields = self::fieldLine((array) $p['properties']);
            $lines[$p['type']] = $admin($p['name']) . ' – ' . $admin($p['description']) . ($p['container'] ? ' [CONTAINER]' : '') . ' | tags: ' . implode(',', $p['tags'])
                . ($fields !== '' ? ' | content: ' . $fields : '')
                . ($style !== [] ? ' | style of a new element in the builder: ' . implode(', ', $style) . ' (write it in the JSON yourself, otherwise the element has none)' : '');
        }
        if ($only !== [] && !$full) {
            return ['elements' => $definitions];
        }
        $style = [];
        foreach ($schema['style'] as $key => $v) {
            $style[$key] = $v['css'] . ': ' . (isset($v['options']) ? implode('|', array_map('strval', array_keys($v['options']))) : $v['type']);
        }
        $tokens = $schema['tokens'];
        $colors = array_keys($tokens['colors']);

        return [
            'version' => $schema['version'], 'elements' => $full ? $definitions : $lines, 'style' => $style, 'states' => $schema['states'],
            'tokens' => ['colors' => $colors, 'spaces' => $tokens['spaces'], 'steps' => $tokens['steps'], 'radii' => array_map('strval', $tokens['radii']), 'shadows' => $tokens['shadows']],
            'value_types' => [
                'space' => 'token ' . implode('|', $tokens['spaces']) . ' or a length (1.5rem)', 'step' => 'font size: token ' . implode('|', $tokens['steps']) . ' or a length',
                'color' => 'token (' . implode('|', $colors) . ') or #hex', 'radius' => 'token ' . implode('|', array_map('strval', $tokens['radii'])) . ' or a length',
                'shadow' => 'token s|m|l, none or “x y blur colour”', 'border' => 'an option or “2px solid colour”', 'length' => 'px, rem, %, vw, fr, auto, min()/max()/clamp()/calc()',
                'columns' => 'a number 1–12, “auto:16rem” (as many as fit) or “2fr 1fr”', 'rows' => 'a number or “auto 1fr”', 'areas' => 'rows separated by /, e.g. “a a / b c”',
            ],
            'node' => '{"id":"(optional, keep it when editing)","type":"…","tag":"(one of the tags; the first is the default)","content":{…},"style":{"base":{…},"tablet":{…},"mobile":{…},"hover":{…}},"classes":["…"],"anchor":"id-for-links","children":[…]}; leave out empty fields and default values',
            'conditions' => 'optional "conditions" of a node: {"signed_in":"yes|no","from":"YYYY-MM-DD","to":"YYYY-MM-DD","languages":["","de"] ("" = the default language),"url_parameter":{"name":"utm_campaign","value":"jaro"}} – the element is rendered only when all of them hold',
            'rules' => $schema['rules'],
        ];
    }

    /** Full field definitions of an element (builder_schema with element types). @param array<string, array<string, mixed>> $properties */
    private static function fieldDefinitions(array $properties, callable $admin): array
    {
        $out = [];
        foreach ($properties as $key => $v) {
            $field = ['type' => $v['type'], 'label' => $admin((string) ($v['label'] ?? ''))];
            if (array_key_exists('default', $v)) {
                $field['default'] = $v['default'];
            }
            if (isset($v['options']) && is_array($v['options'])) {
                $field['options'] = array_map('strval', array_is_list($v['options']) ? $v['options'] : array_keys($v['options']));
            }
            if (isset($v['fields']) && is_array($v['fields'])) {
                $item = [];
                foreach ($v['fields'] as $k => $f) {
                    $item[$k] = ['type' => $f['type'], 'label' => $admin((string) ($f['label'] ?? ''))]
                        + (isset($f['options']) && is_array($f['options']) ? ['options' => array_map('strval', array_keys($f['options']))] : []);
                }
                $field['item_fields'] = $item;
            }
            $out[$key] = $field;
        }

        return $out;
    }

    /** Fields of an element on one line: key:type(options, * = default) or key:items[fields]. @param array<string, array<string, mixed>> $properties */
    private static function fieldLine(array $properties): string
    {
        $parts = [];
        foreach ($properties as $key => $v) {
            $description = $key . ':' . $v['type'];
            if (isset($v['options']) && is_array($v['options'])) {
                $description .= '(' . implode('|', array_map(fn (string|int $m): string => (string) $m . ((string) $m === (string) ($v['default'] ?? '') ? '*' : ''),
                    array_is_list($v['options']) ? $v['options'] : array_keys($v['options']))) . ')';
            } elseif (isset($v['fields']) && is_array($v['fields'])) {
                $item = [];
                foreach ($v['fields'] as $k => $f) {
                    $item[] = $k . ':' . $f['type'] . (isset($f['options']) && is_array($f['options']) ? '(' . implode('|', array_map('strval', array_keys($f['options']))) . ')' : '');
                }
                $description .= '[' . implode('; ', $item) . ']';
            } elseif (in_array($v['type'], ['boolean', 'number'], true) && isset($v['default'])) {
                $description .= '=' . var_export($v['default'], true);
            }
            $parts[] = $description;
        }

        return implode('; ', $parts);
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
    private const array TECHNICAL_PROPERTIES = ['collection', 'sort_field', 'filter_field', 'location_field', 'component', 'category', 'recipient', 'target', 'value'];

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

    /** @param list<string>|null $extensions */
    public static function disabledTypes(?array $extensions): array
    {
        $types = [];
        foreach (self::ELEMENTS as $className) {
            if ($extensions !== null && $className::EXTENSION !== '' && !in_array($className::EXTENSION, $extensions, true)) {
                $types[] = $className::TYPE;
            }
        }

        return $types;
    }

    /** @return array<string, mixed> */
    private static function buildSchema(bool $admin, bool $parts, ?array $extensions): array
    {
        $elements = [];
        $disabled = self::disabledTypes($extensions);
        foreach (self::ELEMENTS as $className) {
            if (($className::ADMIN_ONLY && !$admin) || ($className::PARTS_ONLY && !$parts) || in_array($className::TYPE, $disabled, true)) {
                continue;
            }
            $elements[] = [
                'type' => $className::TYPE, 'name' => $className::NAME, 'description' => $className::DESCRIPTION, 'icon' => $className::ICON, 'group' => $className::GROUP,
                'container' => $className::CONTAINER, 'tags' => $className::HTML_TAGS, 'properties' => $className::properties(), 'default_style' => $className::defaultStyle() ?: new \stdClass(),
                'default_children' => $className::defaultChildren(),
            ];
        }
        $style = [];
        foreach (Style::PROPERTIES as $key => [$css, $type, $group, $labelText, $options]) {
            $style[$key] = ['css' => $css, 'type' => $type, 'group' => $group, 'label' => $labelText] + ($options !== null ? ['options' => $options] : []);
        }

        return [
            'version' => self::VERSION, 'elements' => $elements, 'style' => $style, 'style_groups' => Style::GROUPS, 'states' => array_keys(Style::STATUSES),
            'tokens' => ['colors' => DesignSystem::COLOR_TOKENS, 'spaces' => array_keys(DesignSystem::SPACES), 'steps' => DesignSystem::STEPS,
                'radii' => array_keys(DesignSystem::RADII), 'shadows' => array_keys(DesignSystem::SHADOWS)],
            'rules' => [
                'One element = one HTML tag; a section has at most one inner wrapper. Build page content from sections.',
                'Take style from tokens (space “l”, colour “primary”, step “2”); a free value only when no token fits.',
                'Style has the states base, tablet (up to 1023 px), mobile (up to 767 px), hover; editing one state leaves the others.',
                'A repeated look (cards, labels) belongs in a class, not in the style of every element.',
                'Grid columns: a number (“3”), “auto:16rem” (as many as fit) or a ratio (“2fr 1fr”).',
            ],
        ];
    }
}
