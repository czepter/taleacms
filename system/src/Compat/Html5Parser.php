<?php

declare(strict_types=1);

namespace Kaleta\Compat;

/**
 * The HTML5 parser for PHP 8.3 (3.7). PHP 8.4 parses HTML with its built-in Dom\HTMLDocument; on 8.3 the Dom\ classes in
 * system/compat wrap a legacy \DOMDocument that this class builds. It follows the WHATWG tokenizer and tree construction
 * so that a page splits into the same elements as in a browser and as in PHP 8.4 – the sanitizers (Core\Html, WpContent)
 * rely on that; tools/unit-tests.php compares it with PHP 8.4's output (including the "customizable <select>").
 *
 * Left out on purpose (Kaleta parses fragments of articles and imported pages, not applications): quirks mode, framesets,
 * the escape states of script data and <template> contents as a separate fragment (they stay children). Names the legacy
 * DOM cannot hold (a tag such as `<x<` or an attribute such as `@click`) are left out with their content kept; a tag name
 * with a colon (Word's <o:p>, <svg><x:g>) becomes an element without a namespace, as the legacy DOM would bind the prefix.
 *
 * Attributes (3.7, N37-1): a name is what the source says. Only the attributes the HTML standard adjusts in SVG and MathML
 * (xlink:href and its six siblings, xml:lang, xml:space) get a namespace; every other name – xml:onerror, xlink:onclick,
 * x:href, xmlns… – is one literal attribute without a namespace, as in PHP 8.4 and in browsers. The legacy setAttribute()
 * would bind such a prefix (xml: always) and hide the attribute behind its local name.
 *
 * Speed (3.7, N37-3): the scope checks read the topmost stack position of an element name or kind ($positions) instead of
 * walking the stack of open elements, and the tree is at most MAX_DEPTH deep (as in Chrome), so markup like 100,000 unclosed
 * <div>s parses in linear time. Pathological input that would still cost quadratic time in any HTML parser is bounded: at
 * most MAX_ATTRIBUTES attributes per element, MAX_FORMATTING active formatting elements after the last marker (Noah's Ark
 * plus a hard limit), and a budget for re-opened formatting elements and the adoption agency.
 */
final class Html5Parser
{
    public const string NS_HTML = 'http://www.w3.org/1999/xhtml';
    public const string NS_SVG = 'http://www.w3.org/2000/svg';
    public const string NS_MATHML = 'http://www.w3.org/1998/Math/MathML';
    private const string NS_XLINK = 'http://www.w3.org/1999/xlink';
    private const string NS_XML = 'http://www.w3.org/XML/1998/namespace';

    private const string WHITESPACE = "\t\n\f\r ";
    /** ASCII only: ctype_alpha() follows the locale and may take a UTF-8 byte for a letter. */
    private const string ALPHA = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
    private const string ALNUM = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';

    // tokenizer states the tree construction switches to
    private const int DATA = 0;
    private const int RCDATA = 1;
    private const int RAWTEXT = 2;
    private const int PLAINTEXT = 3;

    // token types
    private const int START = 0;
    private const int END = 1;
    private const int CHARS = 2;
    private const int COMMENT = 3;
    private const int DOCTYPE = 4;
    private const int EOF = 5;

    // insertion modes
    private const int INITIAL = 0;
    private const int BEFORE_HTML = 1;
    private const int BEFORE_HEAD = 2;
    private const int IN_HEAD = 3;
    private const int IN_HEAD_NOSCRIPT = 4;
    private const int AFTER_HEAD = 5;
    private const int IN_BODY = 6;
    private const int TEXT = 7;
    private const int IN_TABLE = 8;
    private const int IN_CAPTION = 9;
    private const int IN_COLUMN_GROUP = 10;
    private const int IN_TABLE_BODY = 11;
    private const int IN_ROW = 12;
    private const int IN_CELL = 13;
    private const int IN_TEMPLATE = 14;
    private const int AFTER_BODY = 16;
    private const int AFTER_AFTER_BODY = 17;

    private const array SPECIAL = ['address', 'applet', 'area', 'article', 'aside', 'base', 'basefont', 'bgsound', 'blockquote', 'body', 'br', 'button', 'caption',
        'center', 'col', 'colgroup', 'dd', 'details', 'dir', 'div', 'dl', 'dt', 'embed', 'fieldset', 'figcaption', 'figure', 'footer', 'form', 'frame', 'frameset',
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'head', 'header', 'hgroup', 'hr', 'html', 'iframe', 'img', 'input', 'keygen', 'li', 'link', 'listing', 'main', 'marquee',
        'menu', 'meta', 'nav', 'noembed', 'noframes', 'noscript', 'object', 'ol', 'p', 'param', 'plaintext', 'pre', 'script', 'search', 'section', 'select', 'source',
        'style', 'summary', 'table', 'tbody', 'td', 'template', 'textarea', 'tfoot', 'th', 'thead', 'title', 'tr', 'track', 'ul', 'wbr', 'xmp'];

    private const array FORMATTING = ['a', 'b', 'big', 'code', 'em', 'font', 'i', 'nobr', 's', 'small', 'strike', 'strong', 'tt', 'u'];

    /** Start tags that close an open <p> (in button scope) before they are inserted. */
    private const array CLOSES_P = ['address', 'article', 'aside', 'blockquote', 'center', 'details', 'dialog', 'dir', 'div', 'dl', 'fieldset', 'figcaption', 'figure',
        'footer', 'header', 'hgroup', 'main', 'menu', 'nav', 'ol', 'p', 'search', 'section', 'summary', 'ul'];

    /** End tags that close their element when it is in scope (with implied end tags). */
    private const array BLOCK_END = ['address', 'article', 'aside', 'blockquote', 'button', 'center', 'details', 'dialog', 'dir', 'div', 'dl', 'fieldset', 'figcaption',
        'figure', 'footer', 'header', 'hgroup', 'listing', 'main', 'menu', 'nav', 'ol', 'pre', 'search', 'section', 'summary', 'ul'];

    private const array HEADINGS = ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'];

    private const array IMPLIED_END = ['dd', 'dt', 'li', 'optgroup', 'option', 'p', 'rb', 'rp', 'rt', 'rtc'];

    /** Scope boundaries; <select> is one since the "customizable select" change (PHP 8.4 parses that way). */
    private const array SCOPE = ['applet', 'caption', 'html', 'table', 'td', 'th', 'marquee', 'object', 'template', 'select'];

    /** Elements that leave SVG or MathML: the HTML element is inserted where the foreign content started (<sup> stays inside in PHP 8.4). */
    private const array BREAKOUT = ['b', 'big', 'blockquote', 'body', 'br', 'center', 'code', 'dd', 'div', 'dl', 'dt', 'em', 'embed', 'h1', 'h2', 'h3', 'h4', 'h5',
        'h6', 'head', 'hr', 'i', 'img', 'li', 'listing', 'menu', 'meta', 'nobr', 'ol', 'p', 'pre', 'ruby', 's', 'small', 'span', 'strong', 'strike', 'sub',
        'table', 'tt', 'u', 'ul', 'var'];

    private const array SVG_TAGS = ['altGlyph', 'altGlyphDef', 'altGlyphItem', 'animateColor', 'animateMotion', 'animateTransform', 'clipPath', 'feBlend',
        'feColorMatrix', 'feComponentTransfer', 'feComposite', 'feConvolveMatrix', 'feDiffuseLighting', 'feDisplacementMap', 'feDistantLight', 'feDropShadow',
        'feFlood', 'feFuncA', 'feFuncB', 'feFuncG', 'feFuncR', 'feGaussianBlur', 'feImage', 'feMerge', 'feMergeNode', 'feMorphology', 'feOffset', 'fePointLight',
        'feSpecularLighting', 'feSpotLight', 'feTile', 'feTurbulence', 'foreignObject', 'glyphRef', 'linearGradient', 'radialGradient', 'textPath'];

    private const array SVG_ATTRIBUTES = ['attributeName', 'attributeType', 'baseFrequency', 'baseProfile', 'calcMode', 'clipPathUnits', 'diffuseConstant', 'edgeMode',
        'filterUnits', 'glyphRef', 'gradientTransform', 'gradientUnits', 'kernelMatrix', 'kernelUnitLength', 'keyPoints', 'keySplines', 'keyTimes', 'lengthAdjust',
        'limitingConeAngle', 'markerHeight', 'markerUnits', 'markerWidth', 'maskContentUnits', 'maskUnits', 'numOctaves', 'pathLength', 'patternContentUnits',
        'patternTransform', 'patternUnits', 'pointsAtX', 'pointsAtY', 'pointsAtZ', 'preserveAlpha', 'preserveAspectRatio', 'primitiveUnits', 'refX', 'refY',
        'repeatCount', 'repeatDur', 'requiredExtensions', 'requiredFeatures', 'specularConstant', 'specularExponent', 'spreadMethod', 'startOffset', 'stdDeviation',
        'stitchTiles', 'surfaceScale', 'systemLanguage', 'tableValues', 'targetX', 'targetY', 'textLength', 'viewBox', 'viewTarget', 'xChannelSelector',
        'yChannelSelector', 'zoomAndPan'];

    /** The attributes the standard puts in a namespace in SVG and MathML ("adjust foreign attributes"); xmlns and xmlns:xlink stay literal here. */
    private const array FOREIGN_ATTRIBUTES = ['xlink:actuate' => self::NS_XLINK, 'xlink:arcrole' => self::NS_XLINK, 'xlink:href' => self::NS_XLINK,
        'xlink:role' => self::NS_XLINK, 'xlink:show' => self::NS_XLINK, 'xlink:title' => self::NS_XLINK, 'xlink:type' => self::NS_XLINK,
        'xml:lang' => self::NS_XML, 'xml:space' => self::NS_XML];

    /** Elements whose position decides the insertion mode after a table part closes (resetting the insertion mode). */
    private const array RESET = ['td', 'th', 'tr', 'tbody', 'thead', 'tfoot', 'caption', 'colgroup', 'table', 'head', 'template', 'body', 'html'];

    /**
     * The depth of the tree (Chrome's limit, kMaximumHTMLParserDOMTreeDepth): deeper nodes become siblings. The legacy DOM
     * walks all ancestors on every insertion, so 100,000 unclosed <div>s would otherwise take quadratic time.
     */
    private const int MAX_DEPTH = 512;

    /** Attributes of one element: the legacy DOM looks for a duplicate on every one added (100,000 would take minutes). */
    private const int MAX_ATTRIBUTES = 256;

    /** Active formatting elements after the last marker: more are not re-opened (browsers keep three alike – Noah's Ark). */
    private const int MAX_FORMATTING = 64;

    /** Formatting elements re-opened (cloned) in one document; beyond it a misnested element is not re-opened any more. */
    private const int RECONSTRUCT_BUDGET = 100000;

    /** Stack entries the adoption agency may rewrite in one document; beyond it a misnested end tag just closes its element. */
    private const int ADOPTION_BUDGET = 300000;

    /** Numeric references to C1 controls mean Windows-1252 characters. */
    private const array WINDOWS_1252 = [0x80 => 0x20AC, 0x82 => 0x201A, 0x83 => 0x0192, 0x84 => 0x201E, 0x85 => 0x2026, 0x86 => 0x2020, 0x87 => 0x2021,
        0x88 => 0x02C6, 0x89 => 0x2030, 0x8A => 0x0160, 0x8B => 0x2039, 0x8C => 0x0152, 0x8E => 0x017D, 0x91 => 0x2018, 0x92 => 0x2019, 0x93 => 0x201C,
        0x94 => 0x201D, 0x95 => 0x2022, 0x96 => 0x2013, 0x97 => 0x2014, 0x98 => 0x02DC, 0x99 => 0x2122, 0x9A => 0x0161, 0x9B => 0x203A, 0x9C => 0x0153,
        0x9E => 0x017E, 0x9F => 0x0178];

    /** @var array<string, string>|null references valid without a semicolon (&copy, &nbsp…) – the HTML 4 Latin-1 ones */
    private static ?array $legacy = null;

    private string $input = '';
    private int $pos = 0;
    private int $length = 0;
    private int $state = self::DATA;
    private string $lastStartTag = '';
    private string $text = '';

    private int $mode = self::INITIAL;
    private int $originalMode = self::INITIAL;
    /** @var list<\DOMElement> */
    private array $stack = [];
    /** @var list<\DOMElement|null> active formatting elements, null = marker */
    private array $formatting = [];
    private ?\DOMElement $head = null;
    private ?\DOMElement $form = null;
    private bool $fosterParenting = false;
    private bool $skipNewline = false;
    /** @var list<int> the stack of template insertion modes */
    private array $templateModes = [];

    /**
     * Stack positions (ascending) by key: "h:<name>" an HTML element, "f:<name>" a foreign one, and the kinds "html", "scope"
     * (a scope boundary), "special", "special-li" (special except address, div, p) and "reset". Kept by push() and pop().
     *
     * @var array<string, list<int>>
     */
    private array $positions = [];
    /** @var \SplObjectStorage<\DOMElement, array{int, list<string>, int}> the elements on the stack: position, keys and depth in the tree */
    private \SplObjectStorage $open;
    /** @var \SplObjectStorage<\DOMElement, string> the active formatting elements and their tag name with attributes */
    private \SplObjectStorage $active;
    /** @var \SplObjectStorage<\DOMElement, string> SVG/MathML elements with a colon in the name: the DOM holds them without a namespace */
    private \SplObjectStorage $literalNamespace;
    /** @var list<\DOMElement> stand-ins for elements whose name the legacy DOM cannot hold (<x<, <a">), removed after parsing */
    private array $placeholders = [];
    private int $reconstructBudget = self::RECONSTRUCT_BUDGET;
    private int $adoptionBudget = self::ADOPTION_BUDGET;

    /** @var array<string, array<string, true>>|null */
    private static ?array $sets = null;

    private function __construct(private readonly \DOMDocument $doc, private readonly bool $htmlNamespace)
    {
        $this->open = new \SplObjectStorage();
        $this->active = new \SplObjectStorage();
        $this->literalNamespace = new \SplObjectStorage();
    }

    /**
     * Parses a whole document (UTF-8) into an empty $doc: html, head and body always exist afterwards.
     *
     * @param bool $htmlNamespace false = HTML elements without a namespace (for XPath queries, Dom\HTML_NO_DEFAULT_NS)
     */
    public static function parse(\DOMDocument $doc, string $html, bool $htmlNamespace = true): void
    {
        $parser = new self($doc, $htmlNamespace);
        $parser->input = str_replace(["\r\n", "\r"], "\n", $html);
        $parser->length = strlen($parser->input);
        $parser->run();
        foreach ($parser->placeholders as $element) {
            // a name the legacy DOM cannot hold: the element goes, its content stays where PHP 8.4 has it
            while ($element->firstChild !== null) {
                $element->parentNode?->insertBefore($element->firstChild, $element);
            }
            $element->parentNode?->removeChild($element);
        }
    }

    /**
     * The source as UTF-8 like PHP 8.4 decodes it: the given encoding, else a byte order mark, else <meta charset> in the
     * first 1024 bytes, else UTF-8; invalid bytes become U+FFFD.
     */
    public static function utf8(string $source, ?string $encoding = null): string
    {
        if ($encoding === null) {
            if (str_starts_with($source, "\xEF\xBB\xBF")) {
                $source = substr($source, 3);
                $encoding = 'UTF-8';
            } elseif (str_starts_with($source, "\xFF\xFE") || str_starts_with($source, "\xFE\xFF")) {
                $encoding = $source[0] === "\xFF" ? 'UTF-16LE' : 'UTF-16BE';
                $source = substr($source, 2);
            } elseif (preg_match('/<meta[^>]+charset\s*=\s*["\']?\s*([a-z0-9_:.()-]+)/i', substr($source, 0, 1024), $m) === 1) {
                $encoding = $m[1];
            }
        }
        $encoding = strtoupper((string) $encoding);
        $previous = mb_substitute_character();
        mb_substitute_character(0xFFFD);
        try {
            if ($encoding !== '' && $encoding !== 'UTF-8' && $encoding !== 'UTF8') {
                try {
                    return (string) mb_convert_encoding($source, 'UTF-8', $encoding);
                } catch (\ValueError) {
                    // mbstring lacks Windows-1250 (common on Czech sites) – iconv has it
                    $converted = function_exists('iconv') ? @iconv($encoding, 'UTF-8//IGNORE', $source) : false;
                    if (is_string($converted)) {
                        return $converted;
                    }
                }
            }

            return mb_check_encoding($source, 'UTF-8') ? $source : (string) mb_scrub($source, 'UTF-8');
        } finally {
            mb_substitute_character($previous);
        }
    }

    /* ---------- tokenizer ---------- */

    private function run(): void
    {
        while ($this->pos < $this->length) {
            match ($this->state) {
                self::RCDATA, self::RAWTEXT => $this->rawText(),
                self::PLAINTEXT => $this->plaintext(),
                default => $this->data(),
            };
        }
        $this->flush();
        $this->process(['t' => self::EOF]);
    }

    private function data(): void
    {
        $n = strcspn($this->input, '<&', $this->pos);
        if ($n > 0) {
            $this->text .= substr($this->input, $this->pos, $n);
            $this->pos += $n;

            return;
        }
        if ($this->input[$this->pos] === '&') {
            $this->pos++;
            $this->text .= $this->reference(false);

            return;
        }
        $next = $this->input[$this->pos + 1] ?? '';
        if ($next === '!') {
            $this->pos += 2;
            $this->markupDeclaration();
        } elseif ($next === '/') {
            $this->pos += 2;
            $this->endTagOpen();
        } elseif ($next === '?') {
            $this->pos++; // a bogus comment whose text starts with the "?"
            $this->bogusComment();
        } elseif ($next !== '' && strspn($next, self::ALPHA) === 1) {
            $this->pos++;
            $this->tag(false);
        } else {
            $this->text .= '<';
            $this->pos++;
        }
    }

    private function endTagOpen(): void
    {
        $c = $this->input[$this->pos] ?? '';
        if ($c !== '' && strspn($c, self::ALPHA) === 1) {
            $this->tag(true);
        } elseif ($c === '>') {
            $this->pos++; // "</>" is dropped
        } elseif ($c === '') {
            $this->text .= '</';
        } else {
            $this->bogusComment();
        }
    }

    /** A start or end tag from its name on; a tag cut off by the end of the input is dropped. */
    private function tag(bool $end): void
    {
        $n = strcspn($this->input, "\t\n\f />", $this->pos);
        $name = self::nul(strtolower(substr($this->input, $this->pos, $n)));
        $this->pos += $n;
        /** @var array<string, string> $attributes */
        $attributes = [];
        $selfClosing = false;
        while (true) {
            $this->pos += strspn($this->input, "\t\n\f ", $this->pos);
            $c = $this->input[$this->pos] ?? '';
            if ($c === '') {
                return;
            }
            if ($c === '>') {
                $this->pos++;
                break;
            }
            if ($c === '/') {
                $this->pos++;
                if (($this->input[$this->pos] ?? '') === '>') {
                    $this->pos++;
                    $selfClosing = true;
                    break;
                }
                continue;
            }
            // an attribute name takes everything up to a space, "/", ">" or "=" (a leading "=" belongs to it)
            $n = 1 + strcspn($this->input, "\t\n\f />=", $this->pos + 1);
            $attribute = self::nul(strtolower(substr($this->input, $this->pos, $n)));
            $this->pos += $n;
            $this->pos += strspn($this->input, "\t\n\f ", $this->pos);
            $value = '';
            if (($this->input[$this->pos] ?? '') === '=') {
                $this->pos++;
                $this->pos += strspn($this->input, "\t\n\f ", $this->pos);
                $quote = $this->input[$this->pos] ?? '';
                if ($quote === '"' || $quote === "'") {
                    $this->pos++;
                    $value = $this->attributeValue($quote);
                } elseif ($quote !== '>') {
                    $value = $this->attributeValue('');
                }
            }
            if (!array_key_exists($attribute, $attributes) && count($attributes) < self::MAX_ATTRIBUTES) {
                $attributes[$attribute] = $value; // the first of duplicate attributes wins
            }
        }
        $this->flush();
        if ($end) {
            $this->process(['t' => self::END, 'name' => $name]);
        } else {
            $this->lastStartTag = $name;
            $this->process(['t' => self::START, 'name' => $name, 'attrs' => $attributes, 'self' => $selfClosing]);
        }
    }

    /** @param string $quote '"', "'" or '' for an unquoted value */
    private function attributeValue(string $quote): string
    {
        $value = '';
        $stop = $quote === '' ? "\t\n\f >&" : $quote . '&';
        while ($this->pos < $this->length) {
            $n = strcspn($this->input, $stop, $this->pos);
            $value .= substr($this->input, $this->pos, $n);
            $this->pos += $n;
            $c = $this->input[$this->pos] ?? '';
            if ($c === '&') {
                $this->pos++;
                $value .= $this->reference(true);
                continue;
            }
            if ($quote !== '' && $c === $quote) {
                $this->pos++;
            }
            break;
        }

        return self::nul($value);
    }

    private function markupDeclaration(): void
    {
        if (substr($this->input, $this->pos, 2) === '--') {
            $this->pos += 2;
            $this->comment();
        } elseif (strcasecmp(substr($this->input, $this->pos, 7), 'doctype') === 0) {
            $end = strpos($this->input, '>', $this->pos);
            $this->pos = $end === false ? $this->length : $end + 1;
            $this->flush();
            $this->process(['t' => self::DOCTYPE]);
        } elseif (substr($this->input, $this->pos, 7) === '[CDATA[' && $this->stack !== [] && !$this->isHtml($this->adjustedCurrent())) {
            $this->pos += 7;
            $end = strpos($this->input, ']]>', $this->pos);
            $this->text .= substr($this->input, $this->pos, $end === false ? null : $end - $this->pos);
            $this->pos = $end === false ? $this->length : $end + 3;
        } else {
            $this->bogusComment();
        }
    }

    private function comment(): void
    {
        $data = '';
        if (($this->input[$this->pos] ?? '') === '>') {
            $this->pos++; // <!-->
        } elseif (substr($this->input, $this->pos, 2) === '->') {
            $this->pos += 2; // <!--->
        } else {
            $p = $this->pos;
            while (true) {
                $p = strpos($this->input, '--', $p);
                if ($p === false) {
                    $data = substr($this->input, $this->pos);
                    $this->pos = $this->length;
                    break;
                }
                $close = substr($this->input, $p + 2, 1) === '>' ? 3 : (substr($this->input, $p + 2, 2) === '!>' ? 4 : 0);
                if ($close > 0) {
                    $data = substr($this->input, $this->pos, $p - $this->pos);
                    $this->pos = $p + $close;
                    break;
                }
                $p++;
            }
        }
        $this->flush();
        $this->process(['t' => self::COMMENT, 'data' => self::nul($data)]);
    }

    private function bogusComment(): void
    {
        $end = strpos($this->input, '>', $this->pos);
        $data = substr($this->input, $this->pos, $end === false ? null : $end - $this->pos);
        $this->pos = $end === false ? $this->length : $end + 1;
        $this->flush();
        $this->process(['t' => self::COMMENT, 'data' => self::nul($data)]);
    }

    /** Text of title/textarea (RCDATA, with references) or style/script/xmp/iframe/noembed/noframes (RAWTEXT) up to its end tag. */
    private function rawText(): void
    {
        $name = $this->lastStartTag;
        $end = $name === 'script' ? $this->scriptEnd() : $this->length;
        for ($p = $this->pos; $name !== 'script' && ($lt = strpos($this->input, '</', $p)) !== false; $p = $lt + 2) {
            if (strcasecmp(substr($this->input, $lt + 2, strlen($name)), $name) === 0 && strspn($this->input[$lt + 2 + strlen($name)] ?? '', "\t\n\f />") === 1) {
                $end = $lt;
                break;
            }
        }
        if ($this->state === self::RCDATA) {
            while ($this->pos < $end) {
                $n = min(strcspn($this->input, '&', $this->pos), $end - $this->pos);
                $this->text .= substr($this->input, $this->pos, $n);
                $this->pos += $n;
                if ($this->pos < $end) {
                    $this->pos++;
                    $this->text .= $this->reference(false);
                }
            }
        } else {
            $this->text .= substr($this->input, $this->pos, $end - $this->pos);
        }
        $this->text = self::nul($this->text);
        $this->pos = $end;
        $this->state = self::DATA; // the end tag itself is read as a tag
    }

    /**
     * Where the text of a <script> ends: at </script, but not inside "<!--<script>…</script>" (the escaped and double escaped
     * states of script data: a browser keeps such text in the script, and so does PHP 8.4).
     */
    private function scriptEnd(): int
    {
        $input = $this->input;
        $state = 0; // 0 script data, 1 escaped (after <!--), 2 double escaped (after <!--<script)
        for ($p = $this->pos; ($p += strcspn($input, '<-', $p)) < $this->length;) {
            if ($input[$p] === '-') {
                $dashes = strspn($input, '-', $p);
                $p += $dashes;
                if ($state !== 0 && $dashes >= 2 && ($input[$p] ?? '') === '>') {
                    $state = 0; // -->
                    $p++;
                }
                continue;
            }
            if ($state === 0 && substr($input, $p, 4) === '<!--') {
                $state = 1;
                $p += 2; // the dashes count for a following "-->" (so <!--> and <!---> leave at once)
                continue;
            }
            $close = ($input[$p + 1] ?? '') === '/';
            $delimiter = $input[$p + ($close ? 8 : 7)] ?? '';
            if (strcasecmp(substr($input, $p + ($close ? 2 : 1), 6), 'script') === 0 && $delimiter !== '' && strspn($delimiter, "\t\n\f />") === 1) {
                if ($close && $state !== 2) {
                    return $p;
                }
                if ($close || $state === 1) {
                    $state = $close ? 1 : 2;
                }
            }
            $p++;
        }

        return $this->length;
    }

    private function plaintext(): void
    {
        $this->text .= self::nul(substr($this->input, $this->pos));
        $this->pos = $this->length;
    }

    /** A character reference after "&" (the position is right after it); a bare "&" when it is none. */
    private function reference(bool $inAttribute): string
    {
        $c = $this->input[$this->pos] ?? '';
        if ($c === '#') {
            $p = $this->pos + 1;
            $hex = ($this->input[$p] ?? '') === 'x' || ($this->input[$p] ?? '') === 'X';
            $p += $hex ? 1 : 0;
            $digits = strspn($this->input, $hex ? '0123456789abcdefABCDEF' : '0123456789', $p);
            if ($digits === 0) {
                return '&';
            }
            $number = ltrim(substr($this->input, $p, $digits), '0');
            $p += $digits;
            $this->pos = ($this->input[$p] ?? '') === ';' ? $p + 1 : $p;
            $code = strlen($number) > 7 ? 0x110000 : ($number === '' ? 0 : ($hex ? (int) hexdec($number) : (int) $number));
            if ($code === 0 || $code > 0x10FFFF || ($code >= 0xD800 && $code <= 0xDFFF)) {
                $code = 0xFFFD;
            }

            return (string) mb_chr(self::WINDOWS_1252[$code] ?? $code, 'UTF-8');
        }
        if ($c === '' || strspn($c, self::ALNUM) === 0) {
            return '&';
        }
        $run = strspn($this->input, self::ALNUM, $this->pos);
        $name = substr($this->input, $this->pos, $run);
        if (($this->input[$this->pos + $run] ?? '') === ';') {
            $decoded = html_entity_decode('&' . $name . ';', ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($decoded !== '&' . $name . ';') {
                $this->pos += $run + 1;

                return $decoded;
            }
        }
        // the longest prefix that is valid without a semicolon: "&copyx" is "©x", "&notit;" is "¬it;"
        $legacy = self::legacy();
        for ($n = min($run, 6); $n >= 2; $n--) {
            if (isset($legacy[substr($name, 0, $n)])) {
                $after = $this->input[$this->pos + $n] ?? '';
                if ($inAttribute && ($after === '=' || ($after !== '' && strspn($after, self::ALNUM) === 1))) {
                    return '&'; // href="?a=1&copy=2" stays as written
                }
                $this->pos += $n;

                return $legacy[substr($name, 0, $n)];
            }
        }

        return '&';
    }

    /** @return array<string, string> */
    private static function legacy(): array
    {
        if (self::$legacy === null) {
            self::$legacy = ['AMP' => '&', 'LT' => '<', 'GT' => '>', 'QUOT' => '"', 'COPY' => "\u{A9}", 'REG' => "\u{AE}"];
            foreach (get_html_translation_table(HTML_ENTITIES, ENT_QUOTES | ENT_HTML401, 'UTF-8') as $char => $entity) {
                if ($entity[1] !== '#' && mb_ord($char, 'UTF-8') <= 0xFF) {
                    self::$legacy[substr($entity, 1, -1)] = $char;
                }
            }
        }

        return self::$legacy;
    }

    private static function nul(string $s): string
    {
        return str_contains($s, "\0") ? str_replace("\0", "\u{FFFD}", $s) : $s;
    }

    private function flush(): void
    {
        if ($this->text !== '') {
            $text = $this->text;
            $this->text = '';
            $this->process(['t' => self::CHARS, 'data' => $text]);
        }
    }

    /* ---------- tree construction ---------- */

    /** @param array{t: int, name?: string, attrs?: array<string, string>, self?: bool, data?: string} $t */
    private function process(array $t): void
    {
        if ($t['t'] === self::CHARS && $this->skipNewline) {
            $this->skipNewline = false;
            if (str_starts_with($t['data'] ?? '', "\n")) {
                $t['data'] = substr($t['data'], 1);
                if ($t['data'] === '') {
                    return;
                }
            }
        } elseif ($t['t'] !== self::CHARS) {
            $this->skipNewline = false;
        }
        if ($this->usesForeignRules($t)) {
            $this->foreign($t);

            return;
        }
        $this->dispatch($t);
    }

    /**
     * The rules of the current insertion mode.
     *
     * @param array{t: int, name?: string, attrs?: array<string, string>, self?: bool, data?: string} $t
     */
    private function dispatch(array $t): void
    {
        match ($this->mode) {
            self::INITIAL, self::BEFORE_HTML => $this->beforeHtml($t),
            self::BEFORE_HEAD => $this->beforeHead($t),
            self::IN_HEAD => $this->inHead($t),
            self::IN_HEAD_NOSCRIPT => $this->inHeadNoscript($t),
            self::AFTER_HEAD => $this->afterHead($t),
            self::TEXT => $this->inText($t),
            self::IN_TABLE => $this->inTable($t),
            self::IN_CAPTION => $this->inCaption($t),
            self::IN_COLUMN_GROUP => $this->inColumnGroup($t),
            self::IN_TABLE_BODY => $this->inTableBody($t),
            self::IN_ROW => $this->inRow($t),
            self::IN_CELL => $this->inCell($t),
            self::IN_TEMPLATE => $this->inTemplate($t),
            self::AFTER_BODY, self::AFTER_AFTER_BODY => $this->afterBody($t),
            default => $this->inBody($t),
        };
    }

    /** @param array{t: int, name?: string, attrs?: array<string, string>, self?: bool, data?: string} $t */
    private function beforeHtml(array $t): void
    {
        if ($t['t'] === self::DOCTYPE || ($t['t'] === self::CHARS && trim($t['data'] ?? '', self::WHITESPACE) === '')) {
            $this->mode = self::BEFORE_HTML;

            return;
        }
        if ($t['t'] === self::CHARS) {
            $t['data'] = ltrim($t['data'] ?? '', self::WHITESPACE);
        }
        if ($t['t'] === self::COMMENT) {
            $this->doc->appendChild($this->doc->createComment($t['data'] ?? ''));

            return;
        }
        $html = $this->createElement('html', $t['t'] === self::START && $t['name'] === 'html' ? $t['attrs'] ?? [] : [], self::NS_HTML);
        if ($html === null) {
            return;
        }
        $this->doc->appendChild($html);
        $this->push($html);
        $this->mode = self::BEFORE_HEAD;
        if (!($t['t'] === self::START && $t['name'] === 'html')) {
            $this->process($t);
        }
    }

    /** @param array{t: int, name?: string, attrs?: array<string, string>, self?: bool, data?: string} $t */
    private function beforeHead(array $t): void
    {
        if ($t['t'] === self::CHARS) {
            $t['data'] = ltrim($t['data'] ?? '', self::WHITESPACE);
            if ($t['data'] === '') {
                return;
            }
        }
        if ($t['t'] === self::COMMENT) {
            $this->insertComment($t['data'] ?? '');

            return;
        }
        if ($t['t'] === self::DOCTYPE || ($t['t'] === self::END && !in_array($t['name'], ['head', 'body', 'html', 'br'], true))) {
            return;
        }
        if ($t['t'] === self::START && $t['name'] === 'html') {
            $this->inBody($t);

            return;
        }
        $this->head = $this->insert('head', $t['t'] === self::START && $t['name'] === 'head' ? $t['attrs'] ?? [] : []);
        $this->mode = self::IN_HEAD;
        if (!($t['t'] === self::START && $t['name'] === 'head')) {
            $this->process($t);
        }
    }

    /** @param array{t: int, name?: string, attrs?: array<string, string>, self?: bool, data?: string} $t */
    private function inHead(array $t): void
    {
        $name = $t['name'] ?? '';
        if ($t['t'] === self::CHARS) {
            $data = $t['data'] ?? '';
            $space = strspn($data, self::WHITESPACE);
            if ($space > 0) {
                $this->insertText(substr($data, 0, $space));
            }
            if ($space === strlen($data)) {
                return;
            }
            $t['data'] = substr($data, $space);
        } elseif ($t['t'] === self::COMMENT) {
            $this->insertComment($t['data'] ?? '');

            return;
        } elseif ($t['t'] === self::DOCTYPE) {
            return;
        } elseif ($t['t'] === self::START) {
            if ($name === 'html') {
                $this->inBody($t);

                return;
            }
            if (in_array($name, ['base', 'basefont', 'bgsound', 'link', 'meta'], true)) {
                $this->insert($name, $t['attrs'] ?? []);
                $this->pop();

                return;
            }
            if ($name === 'title') {
                $this->rawElement($t, self::RCDATA);

                return;
            }
            if (in_array($name, ['noframes', 'style', 'script'], true)) {
                $this->rawElement($t, self::RAWTEXT);

                return;
            }
            if ($name === 'noscript') {
                $this->insert($name, $t['attrs'] ?? []);
                $this->mode = self::IN_HEAD_NOSCRIPT;

                return;
            }
            if ($name === 'template') {
                $this->insert($name, $t['attrs'] ?? []);
                $this->formatting[] = null;
                $this->mode = self::IN_TEMPLATE;
                $this->templateModes[] = self::IN_TEMPLATE;

                return;
            }
            if ($name === 'head') {
                return;
            }
        } elseif ($t['t'] === self::END) {
            if ($name === 'head') {
                $this->pop();
                $this->mode = self::AFTER_HEAD;

                return;
            }
            if ($name === 'template') {
                if ($this->inStack('template')) {
                    $this->generateImpliedEndTags();
                    $this->popUntil(['template']);
                    $this->clearFormattingToMarker();
                    array_pop($this->templateModes);
                    $this->resetMode();
                }

                return;
            }
            if (!in_array($name, ['body', 'html', 'br'], true)) {
                return;
            }
        }
        $this->pop(); // the head
        $this->mode = self::AFTER_HEAD;
        $this->process($t);
    }

    /** @param array{t: int, name?: string, attrs?: array<string, string>, self?: bool, data?: string} $t */
    private function inHeadNoscript(array $t): void
    {
        $name = $t['name'] ?? '';
        if ($t['t'] === self::DOCTYPE) {
            return;
        }
        if ($t['t'] === self::START && $name === 'html') {
            $this->inBody($t);

            return;
        }
        if ($t['t'] === self::END && $name === 'noscript') {
            $this->pop();
            $this->mode = self::IN_HEAD;

            return;
        }
        if ($t['t'] === self::COMMENT || ($t['t'] === self::CHARS && trim($t['data'] ?? '', self::WHITESPACE) === '')
            || ($t['t'] === self::START && in_array($name, ['basefont', 'bgsound', 'link', 'meta', 'noframes', 'style'], true))) {
            $this->inHead($t);

            return;
        }
        if (($t['t'] === self::START && in_array($name, ['head', 'noscript'], true)) || ($t['t'] === self::END && $name !== 'br')) {
            return;
        }
        $this->pop();
        $this->mode = self::IN_HEAD;
        $this->process($t);
    }

    /** @param array{t: int, name?: string, attrs?: array<string, string>, self?: bool, data?: string} $t */
    private function afterHead(array $t): void
    {
        $name = $t['name'] ?? '';
        if ($t['t'] === self::CHARS) {
            $data = $t['data'] ?? '';
            $space = strspn($data, self::WHITESPACE);
            if ($space > 0) {
                $this->insertText(substr($data, 0, $space));
            }
            if ($space === strlen($data)) {
                return;
            }
            $t['data'] = substr($data, $space);
        } elseif ($t['t'] === self::COMMENT) {
            $this->insertComment($t['data'] ?? '');

            return;
        } elseif ($t['t'] === self::DOCTYPE) {
            return;
        } elseif ($t['t'] === self::START) {
            if ($name === 'html') {
                $this->inBody($t);

                return;
            }
            if ($name === 'body') {
                $this->insert('body', $t['attrs'] ?? []);
                $this->mode = self::IN_BODY;

                return;
            }
            if (in_array($name, ['base', 'basefont', 'bgsound', 'link', 'meta', 'noframes', 'script', 'style', 'template', 'title'], true) && $this->head !== null) {
                $this->push($this->head);
                $this->inHead($t);
                $this->removeFromStack($this->head);

                return;
            }
            if ($name === 'head') {
                return;
            }
        } elseif ($t['t'] === self::END && $name === 'template') {
            $this->inHead($t);

            return;
        } elseif ($t['t'] === self::END && !in_array($name, ['body', 'html', 'br'], true)) {
            return;
        }
        $this->insert('body', []);
        $this->mode = self::IN_BODY;
        $this->process($t);
    }

    /** @param array{t: int, name?: string, attrs?: array<string, string>, self?: bool, data?: string} $t */
    private function inText(array $t): void
    {
        if ($t['t'] === self::CHARS) {
            $this->insertText($t['data'] ?? '');

            return;
        }
        if ($t['t'] === self::EOF) {
            $this->pop();
            $this->mode = $this->originalMode;
            $this->process($t);

            return;
        }
        if ($t['t'] === self::END) {
            $this->pop();
            $this->mode = $this->originalMode;
        }
    }

    /** @param array{t: int, name?: string, attrs?: array<string, string>, self?: bool, data?: string} $t */
    private function inBody(array $t): void
    {
        $name = $t['name'] ?? '';
        $attrs = $t['attrs'] ?? [];
        switch ($t['t']) {
            case self::CHARS:
                $data = str_replace("\0", '', $t['data'] ?? '');
                if ($data !== '') {
                    $this->reconstructFormatting();
                    $this->insertText($data);
                }

                return;
            case self::COMMENT:
                $this->insertComment($t['data'] ?? '');

                return;
            case self::DOCTYPE:
                return;
            case self::EOF:
                return;
            case self::START:
                $this->startInBody($name, $attrs, $t);

                return;
            default:
                $this->endInBody($name, $t);
        }
    }

    /**
     * @param array<string, string> $attrs
     * @param array{t: int, name?: string, attrs?: array<string, string>, self?: bool, data?: string} $t
     */
    private function startInBody(string $name, array $attrs, array $t): void
    {
        if ($name === 'html' || $name === 'body') {
            $target = $name === 'html' ? ($this->stack[0] ?? null) : ($this->stack[1] ?? null);
            if ($target !== null && $this->name($target) === $name && !$this->inStack('template')) {
                foreach ($attrs as $attribute => $value) {
                    if (self::attributeNode($target, (string) $attribute) === null) {
                        self::setAttribute($target, (string) $attribute, $value, false);
                    }
                }
            }

            return;
        }
        if (in_array($name, ['base', 'basefont', 'bgsound', 'link', 'meta', 'noframes', 'script', 'style', 'template', 'title'], true)) {
            $this->inHead($t);

            return;
        }
        if (in_array($name, ['frameset', 'head', 'caption', 'col', 'colgroup', 'frame', 'tbody', 'td', 'tfoot', 'th', 'thead', 'tr'], true)) {
            return; // parse errors, ignored
        }
        if (in_array($name, self::CLOSES_P, true)) {
            $this->closePInButtonScope();
            $this->insert($name, $attrs);

            return;
        }
        if (in_array($name, self::HEADINGS, true)) {
            $this->closePInButtonScope();
            if ($this->current() !== null && $this->isHtml($this->current()) && in_array($this->name($this->current()), self::HEADINGS, true)) {
                $this->pop();
            }
            $this->insert($name, $attrs);

            return;
        }
        if ($name === 'pre' || $name === 'listing') {
            $this->closePInButtonScope();
            $this->insert($name, $attrs);
            $this->skipNewline = true;

            return;
        }
        if ($name === 'form') {
            if ($this->form !== null && !$this->inStack('template')) {
                return;
            }
            $this->closePInButtonScope();
            $form = $this->insert($name, $attrs);
            if (!$this->inStack('template')) {
                $this->form = $form;
            }

            return;
        }
        if ($name === 'li' || $name === 'dd' || $name === 'dt') {
            // the nearest open li (dd, dt) closes unless a special element other than address, div and p is above it
            $i = $name === 'li' ? $this->top('h:li') : max($this->top('h:dd'), $this->top('h:dt'));
            if ($i >= 0 && $i >= $this->top('special-li')) {
                $open = $this->name($this->stack[$i]);
                $this->generateImpliedEndTags($open);
                $this->popUntil([$open]);
            }
            $this->closePInButtonScope();
            $this->insert($name, $attrs);

            return;
        }
        if ($name === 'plaintext') {
            $this->closePInButtonScope();
            $this->insert($name, $attrs);
            $this->state = self::PLAINTEXT;

            return;
        }
        if ($name === 'button') {
            if ($this->inScope('button')) {
                $this->generateImpliedEndTags();
                $this->popUntil(['button']);
            }
            $this->reconstructFormatting();
            $this->insert($name, $attrs);

            return;
        }
        if ($name === 'a') {
            for ($i = count($this->formatting) - 1; $i >= 0 && $this->formatting[$i] !== null; $i--) {
                $entry = $this->formatting[$i];
                if ($this->name($entry) === 'a') {
                    $this->adoptionAgency('a');
                    $this->removeFormatting($entry);
                    $this->removeFromStack($entry);
                    break;
                }
            }
            $this->reconstructFormatting();
            $element = $this->insert($name, $attrs);
            if ($element !== null) {
                $this->pushFormatting($element);
            }

            return;
        }
        if (in_array($name, self::FORMATTING, true)) {
            $this->reconstructFormatting();
            if ($name === 'nobr' && $this->inScope('nobr')) {
                $this->adoptionAgency('nobr');
                $this->reconstructFormatting();
            }
            $element = $this->insert($name, $attrs);
            if ($element !== null) {
                $this->pushFormatting($element);
            }

            return;
        }
        if (in_array($name, ['applet', 'marquee', 'object'], true)) {
            $this->reconstructFormatting();
            $this->insert($name, $attrs);
            $this->formatting[] = null;

            return;
        }
        if ($name === 'table') {
            $this->closePInButtonScope();
            $this->insert($name, $attrs);
            $this->mode = self::IN_TABLE;

            return;
        }
        if (in_array($name, ['area', 'br', 'embed', 'img', 'keygen', 'wbr', 'input', 'image'], true)) {
            if ($name === 'input' && $this->inScope('select')) {
                $this->popUntil(['select']);
            }
            $this->reconstructFormatting();
            $this->insert($name === 'image' ? 'img' : $name, $attrs);
            $this->pop();

            return;
        }
        if (in_array($name, ['param', 'source', 'track'], true)) {
            $this->insert($name, $attrs);
            $this->pop();

            return;
        }
        if ($name === 'hr') {
            $this->closePInButtonScope();
            if ($this->inScope('select')) {
                $this->generateImpliedEndTags();
            }
            $this->insert($name, $attrs);
            $this->pop();

            return;
        }
        if ($name === 'textarea') {
            $this->rawElement($t, self::RCDATA);
            $this->skipNewline = true;

            return;
        }
        if ($name === 'xmp') {
            $this->closePInButtonScope();
            $this->reconstructFormatting();
            $this->rawElement($t, self::RAWTEXT);

            return;
        }
        if ($name === 'iframe' || $name === 'noembed') {
            $this->rawElement($t, self::RAWTEXT);

            return;
        }
        if ($name === 'select') {
            if ($this->inScope('select')) {
                $this->popUntil(['select']); // a <select> inside a <select> closes it
            } else {
                $this->reconstructFormatting();
                $this->insert($name, $attrs);
            }

            return;
        }
        if ($name === 'optgroup' || $name === 'option') {
            if ($this->inScope('select')) {
                $this->generateImpliedEndTags($name === 'option' ? 'optgroup' : null);
            } elseif ($this->currentIs('option')) {
                $this->pop();
            }
            $this->reconstructFormatting();
            $this->insert($name, $attrs);

            return;
        }
        if ($name === 'rb' || $name === 'rtc' || $name === 'rp' || $name === 'rt') {
            if ($this->inScope('ruby')) {
                $this->generateImpliedEndTags($name === 'rp' || $name === 'rt' ? 'rtc' : null);
            }
            $this->insert($name, $attrs);

            return;
        }
        if ($name === 'math' || $name === 'svg') {
            $this->reconstructFormatting();
            $this->insertForeign($name, $attrs, $name === 'svg' ? self::NS_SVG : self::NS_MATHML);
            if (!empty($t['self'])) {
                $this->pop();
            }

            return;
        }
        $this->reconstructFormatting();
        $this->insert($name, $attrs);
    }

    /** @param array{t: int, name?: string, attrs?: array<string, string>, self?: bool, data?: string} $t */
    private function endInBody(string $name, array $t): void
    {
        if ($name === 'template') {
            $this->inHead($t);

            return;
        }
        if ($name === 'body' || $name === 'html') {
            if ($this->inScope('body')) {
                $this->mode = self::AFTER_BODY;
                if ($name === 'html') {
                    $this->process($t);
                }
            }

            return;
        }
        if (in_array($name, self::BLOCK_END, true)) {
            if ($this->inScope($name)) {
                $this->generateImpliedEndTags();
                $this->popUntil([$name]);
            }

            return;
        }
        if ($name === 'form' && $this->inStack('template')) {
            if ($this->inScope('form')) {
                $this->generateImpliedEndTags();
                $this->popUntil(['form']);
            }

            return;
        }
        if ($name === 'select') {
            if ($this->inScope('select')) {
                $this->popUntil(['select']);
            }

            return;
        }
        if ($name === 'form') {
            $form = $this->form;
            $this->form = null;
            if ($form === null || !$this->open->offsetExists($form) || !$this->elementInScope($form)) {
                return;
            }
            $this->generateImpliedEndTags();
            $this->removeFromStack($form);

            return;
        }
        if ($name === 'p') {
            if (!$this->inScope('p', ['button'])) {
                $this->insert('p', []);
            }
            $this->closeP();

            return;
        }
        if ($name === 'li' || $name === 'dd' || $name === 'dt') {
            if ($this->inScope($name, $name === 'li' ? ['ol', 'ul'] : [])) {
                $this->generateImpliedEndTags($name);
                $this->popUntil([$name]);
            }

            return;
        }
        if (in_array($name, self::HEADINGS, true)) {
            foreach (self::HEADINGS as $heading) {
                if ($this->inScope($heading)) {
                    $this->generateImpliedEndTags();
                    $this->popUntil(self::HEADINGS);
                    break;
                }
            }

            return;
        }
        if (in_array($name, self::FORMATTING, true)) {
            $this->adoptionAgency($name);

            return;
        }
        if (in_array($name, ['applet', 'marquee', 'object'], true)) {
            if ($this->inScope($name)) {
                $this->generateImpliedEndTags();
                $this->popUntil([$name]);
                $this->clearFormattingToMarker();
            }

            return;
        }
        if ($name === 'br') {
            $this->startInBody('br', [], ['t' => self::START, 'name' => 'br', 'attrs' => []]);

            return;
        }
        $this->anyOtherEndTag($name);
    }

    private function anyOtherEndTag(string $name): void
    {
        // the nearest open element of the name closes, unless a special element is above it
        $i = $this->top('h:' . $name);
        if ($i < 0 || $this->top('special') > $i) {
            return;
        }
        $this->generateImpliedEndTags($name);
        while (count($this->stack) > $i) {
            $this->pop();
        }
    }

    /** @param array{t: int, name?: string, attrs?: array<string, string>, self?: bool, data?: string} $t */
    private function rawElement(array $t, int $state): void
    {
        $this->insert($t['name'] ?? '', $t['attrs'] ?? []);
        $this->state = $state;
        $this->originalMode = $this->mode;
        $this->mode = self::TEXT;
    }

    /** @param array{t: int, name?: string, attrs?: array<string, string>, self?: bool, data?: string} $t */
    private function inTable(array $t): void
    {
        $name = $t['name'] ?? '';
        if ($t['t'] === self::CHARS && $this->current() !== null && in_array($this->name($this->current()), ['table', 'tbody', 'tfoot', 'thead', 'tr'], true)) {
            $data = str_replace("\0", '', $t['data'] ?? '');
            if (trim($data, self::WHITESPACE) === '') {
                $this->insertText($data);
            } else {
                $this->fosterParenting = true; // text inside a table but outside a cell goes before the table
                $this->inBody(['t' => self::CHARS, 'data' => $data]);
                $this->fosterParenting = false;
            }

            return;
        }
        if ($t['t'] === self::COMMENT) {
            $this->insertComment($t['data'] ?? '');

            return;
        }
        if ($t['t'] === self::DOCTYPE) {
            return;
        }
        if ($t['t'] === self::START) {
            switch ($name) {
                case 'caption':
                    $this->clearStackTo(['table', 'template', 'html']);
                    $this->formatting[] = null;
                    $this->insert($name, $t['attrs'] ?? []);
                    $this->mode = self::IN_CAPTION;

                    return;
                case 'colgroup':
                    $this->clearStackTo(['table', 'template', 'html']);
                    $this->insert($name, $t['attrs'] ?? []);
                    $this->mode = self::IN_COLUMN_GROUP;

                    return;
                case 'col':
                    $this->clearStackTo(['table', 'template', 'html']);
                    $this->insert('colgroup', []);
                    $this->mode = self::IN_COLUMN_GROUP;
                    $this->process($t);

                    return;
                case 'tbody':
                case 'tfoot':
                case 'thead':
                    $this->clearStackTo(['table', 'template', 'html']);
                    $this->insert($name, $t['attrs'] ?? []);
                    $this->mode = self::IN_TABLE_BODY;

                    return;
                case 'td':
                case 'th':
                case 'tr':
                    $this->clearStackTo(['table', 'template', 'html']);
                    $this->insert('tbody', []);
                    $this->mode = self::IN_TABLE_BODY;
                    $this->process($t);

                    return;
                case 'table':
                    if ($this->inScope('table', [], true)) {
                        $this->popUntil(['table']);
                        $this->resetMode();
                        $this->process($t);
                    }

                    return;
                case 'style':
                case 'script':
                case 'template':
                    $this->inHead($t);

                    return;
                case 'input':
                    if (strtolower($t['attrs']['type'] ?? '') === 'hidden') {
                        $this->insert($name, $t['attrs']);
                        $this->pop();

                        return;
                    }
                    break;
                case 'form':
                    if ($this->form === null && !$this->inStack('template')) {
                        $this->form = $this->insert($name, $t['attrs'] ?? []);
                        $this->pop();
                    }

                    return;
            }
        } elseif ($t['t'] === self::END) {
            if ($name === 'table') {
                if ($this->inScope('table', [], true)) {
                    $this->popUntil(['table']);
                    $this->resetMode();
                }

                return;
            }
            if (in_array($name, ['body', 'caption', 'col', 'colgroup', 'html', 'tbody', 'td', 'tfoot', 'th', 'thead', 'tr'], true)) {
                return;
            }
            if ($name === 'template') {
                $this->inHead($t);

                return;
            }
        } elseif ($t['t'] === self::EOF) {
            return;
        }
        $this->fosterParenting = true;
        $this->inBody($t);
        $this->fosterParenting = false;
    }

    /** @param array{t: int, name?: string, attrs?: array<string, string>, self?: bool, data?: string} $t */
    private function inCaption(array $t): void
    {
        $name = $t['name'] ?? '';
        $closes = ($t['t'] === self::START && in_array($name, ['caption', 'col', 'colgroup', 'tbody', 'td', 'tfoot', 'th', 'thead', 'tr'], true))
            || ($t['t'] === self::END && $name === 'table');
        if (($t['t'] === self::END && $name === 'caption') || $closes) {
            if (!$this->inScope('caption', [], true)) {
                return;
            }
            $this->generateImpliedEndTags();
            $this->popUntil(['caption']);
            $this->clearFormattingToMarker();
            $this->mode = self::IN_TABLE;
            if ($closes) {
                $this->process($t);
            }

            return;
        }
        if ($t['t'] === self::END && in_array($name, ['body', 'col', 'colgroup', 'html', 'tbody', 'td', 'tfoot', 'th', 'thead', 'tr'], true)) {
            return;
        }
        $this->inBody($t);
    }

    /** @param array{t: int, name?: string, attrs?: array<string, string>, self?: bool, data?: string} $t */
    private function inColumnGroup(array $t): void
    {
        $name = $t['name'] ?? '';
        if ($t['t'] === self::CHARS) {
            $data = $t['data'] ?? '';
            $space = strspn($data, self::WHITESPACE);
            if ($space > 0) {
                $this->insertText(substr($data, 0, $space));
            }
            if ($space === strlen($data)) {
                return;
            }
            $t['data'] = substr($data, $space);
        } elseif ($t['t'] === self::COMMENT) {
            $this->insertComment($t['data'] ?? '');

            return;
        } elseif ($t['t'] === self::START && $name === 'col') {
            $this->insert($name, $t['attrs'] ?? []);
            $this->pop();

            return;
        } elseif (($t['t'] === self::START || $t['t'] === self::END) && $name === 'template') {
            $this->inHead($t);

            return;
        } elseif ($t['t'] === self::END && ($name === 'colgroup' || $name === 'col')) {
            if ($name === 'colgroup' && $this->currentIs('colgroup')) {
                $this->pop();
                $this->mode = self::IN_TABLE;
            }

            return;
        } elseif ($t['t'] === self::EOF || $t['t'] === self::DOCTYPE) {
            return;
        }
        if (!$this->currentIs('colgroup')) {
            return;
        }
        $this->pop();
        $this->mode = self::IN_TABLE;
        $this->process($t);
    }

    /** @param array{t: int, name?: string, attrs?: array<string, string>, self?: bool, data?: string} $t */
    private function inTableBody(array $t): void
    {
        $name = $t['name'] ?? '';
        if ($t['t'] === self::START && $name === 'tr') {
            $this->clearStackTo(['tbody', 'tfoot', 'thead', 'template', 'html']);
            $this->insert($name, $t['attrs'] ?? []);
            $this->mode = self::IN_ROW;

            return;
        }
        if ($t['t'] === self::START && ($name === 'th' || $name === 'td')) {
            $this->clearStackTo(['tbody', 'tfoot', 'thead', 'template', 'html']);
            $this->insert('tr', []);
            $this->mode = self::IN_ROW;
            $this->process($t);

            return;
        }
        if ($t['t'] === self::END && in_array($name, ['tbody', 'tfoot', 'thead'], true)) {
            if ($this->inScope($name, [], true)) {
                $this->clearStackTo(['tbody', 'tfoot', 'thead', 'template', 'html']);
                $this->pop();
                $this->mode = self::IN_TABLE;
            }

            return;
        }
        if (($t['t'] === self::START && in_array($name, ['caption', 'col', 'colgroup', 'tbody', 'tfoot', 'thead'], true)) || ($t['t'] === self::END && $name === 'table')) {
            if ($this->inScope('tbody', [], true) || $this->inScope('thead', [], true) || $this->inScope('tfoot', [], true)) {
                $this->clearStackTo(['tbody', 'tfoot', 'thead', 'template', 'html']);
                $this->pop();
                $this->mode = self::IN_TABLE;
                $this->process($t);
            }

            return;
        }
        if ($t['t'] === self::END && in_array($name, ['body', 'caption', 'col', 'colgroup', 'html', 'td', 'th', 'tr'], true)) {
            return;
        }
        $this->inTable($t);
    }

    /** @param array{t: int, name?: string, attrs?: array<string, string>, self?: bool, data?: string} $t */
    private function inRow(array $t): void
    {
        $name = $t['name'] ?? '';
        if ($t['t'] === self::START && ($name === 'th' || $name === 'td')) {
            $this->clearStackTo(['tr', 'template', 'html']);
            $this->insert($name, $t['attrs'] ?? []);
            $this->mode = self::IN_CELL;
            $this->formatting[] = null;

            return;
        }
        $closesRow = ($t['t'] === self::START && in_array($name, ['caption', 'col', 'colgroup', 'tbody', 'tfoot', 'thead', 'tr'], true))
            || ($t['t'] === self::END && $name === 'table');
        if (($t['t'] === self::END && $name === 'tr') || $closesRow) {
            if ($this->inScope('tr', [], true)) {
                $this->clearStackTo(['tr', 'template', 'html']);
                $this->pop();
                $this->mode = self::IN_TABLE_BODY;
                if ($closesRow) {
                    $this->process($t);
                }
            }

            return;
        }
        if ($t['t'] === self::END && in_array($name, ['tbody', 'tfoot', 'thead'], true)) {
            if ($this->inScope($name, [], true) && $this->inScope('tr', [], true)) {
                $this->clearStackTo(['tr', 'template', 'html']);
                $this->pop();
                $this->mode = self::IN_TABLE_BODY;
                $this->process($t);
            }

            return;
        }
        if ($t['t'] === self::END && in_array($name, ['body', 'caption', 'col', 'colgroup', 'html', 'td', 'th'], true)) {
            return;
        }
        $this->inTable($t);
    }

    /** @param array{t: int, name?: string, attrs?: array<string, string>, self?: bool, data?: string} $t */
    private function inCell(array $t): void
    {
        $name = $t['name'] ?? '';
        if ($t['t'] === self::END && ($name === 'td' || $name === 'th')) {
            if ($this->inScope($name, [], true)) {
                $this->generateImpliedEndTags();
                $this->popUntil([$name]);
                $this->clearFormattingToMarker();
                $this->mode = self::IN_ROW;
            }

            return;
        }
        $closesCell = ($t['t'] === self::START && in_array($name, ['caption', 'col', 'colgroup', 'tbody', 'td', 'tfoot', 'th', 'thead', 'tr'], true))
            || ($t['t'] === self::END && in_array($name, ['table', 'tbody', 'tfoot', 'thead', 'tr'], true));
        if ($closesCell) {
            if (($t['t'] === self::END && !$this->inScope($name, [], true)) || (!$this->inScope('td', [], true) && !$this->inScope('th', [], true))) {
                return;
            }
            $this->generateImpliedEndTags();
            $this->popUntil(['td', 'th']);
            $this->clearFormattingToMarker();
            $this->mode = self::IN_ROW;
            $this->process($t);

            return;
        }
        if ($t['t'] === self::END && in_array($name, ['body', 'caption', 'col', 'colgroup', 'html'], true)) {
            return;
        }
        $this->inBody($t);
    }

    /**
     * The start of <template> content and what comes after a nested element closes: table parts switch to the table
     * modes, anything else is parsed as in the body.
     *
     * @param array{t: int, name?: string, attrs?: array<string, string>, self?: bool, data?: string} $t
     */
    private function inTemplate(array $t): void
    {
        $name = $t['name'] ?? '';
        if ($t['t'] === self::CHARS || $t['t'] === self::COMMENT || $t['t'] === self::DOCTYPE) {
            $this->inBody($t);

            return;
        }
        if ($t['t'] === self::START) {
            if (in_array($name, ['base', 'basefont', 'bgsound', 'link', 'meta', 'noframes', 'script', 'style', 'template', 'title'], true)) {
                $this->inHead($t);

                return;
            }
            $mode = match ($name) {
                'caption', 'colgroup', 'tbody', 'tfoot', 'thead' => self::IN_TABLE,
                'col' => self::IN_COLUMN_GROUP,
                'tr' => self::IN_TABLE_BODY,
                'td', 'th' => self::IN_ROW,
                default => self::IN_BODY,
            };
            array_pop($this->templateModes);
            $this->templateModes[] = $mode;
            $this->mode = $mode;
            $this->process($t);

            return;
        }
        if ($t['t'] === self::END) {
            if ($name === 'template') {
                $this->inHead($t);
            }

            return;
        }
        // the end of the input inside a template: close it
        if ($this->inStack('template')) {
            $this->popUntil(['template']);
            $this->clearFormattingToMarker();
            array_pop($this->templateModes);
            $this->resetMode();
            $this->process($t);
        }
    }

    /** @param array{t: int, name?: string, attrs?: array<string, string>, self?: bool, data?: string} $t */
    private function afterBody(array $t): void
    {
        if ($t['t'] === self::COMMENT) {
            $parent = $this->mode === self::AFTER_BODY ? ($this->stack[0] ?? $this->doc) : $this->doc;
            $parent->appendChild($this->doc->createComment($t['data'] ?? ''));

            return;
        }
        if ($t['t'] === self::DOCTYPE || $t['t'] === self::EOF) {
            return;
        }
        if ($t['t'] === self::CHARS && trim($t['data'] ?? '', self::WHITESPACE) === '') {
            $this->inBody($t);

            return;
        }
        if ($t['t'] === self::START && $t['name'] === 'html') {
            $this->inBody($t);

            return;
        }
        if ($t['t'] === self::END && $t['name'] === 'html') {
            $this->mode = self::AFTER_AFTER_BODY;

            return;
        }
        $this->mode = self::IN_BODY; // content after </body> belongs to the body
        $this->process($t);
    }

    /* ---------- foreign content (SVG, MathML) ---------- */

    /** @param array{t: int, name?: string, attrs?: array<string, string>, self?: bool, data?: string} $t */
    private function usesForeignRules(array $t): bool
    {
        if ($this->stack === [] || $t['t'] === self::EOF) {
            return false;
        }
        $node = $this->adjustedCurrent();
        if ($this->isHtml($node)) {
            return false;
        }
        $name = $t['name'] ?? '';
        if ($node->namespaceURI === self::NS_MATHML && in_array($node->localName, ['mi', 'mo', 'mn', 'ms', 'mtext'], true)
            && ($t['t'] === self::CHARS || ($t['t'] === self::START && $name !== 'mglyph' && $name !== 'malignmark'))) {
            return false;
        }
        if ($node->namespaceURI === self::NS_MATHML && $node->localName === 'annotation-xml' && $t['t'] === self::START && $name === 'svg') {
            return false;
        }

        return !($this->isHtmlIntegrationPoint($node) && ($t['t'] === self::START || $t['t'] === self::CHARS));
    }

    /** @param array{t: int, name?: string, attrs?: array<string, string>, self?: bool, data?: string} $t */
    private function foreign(array $t): void
    {
        $name = $t['name'] ?? '';
        if ($t['t'] === self::CHARS) {
            $this->insertText(str_replace("\0", "\u{FFFD}", $t['data'] ?? ''));

            return;
        }
        if ($t['t'] === self::COMMENT) {
            $this->insertComment($t['data'] ?? '');

            return;
        }
        if ($t['t'] === self::DOCTYPE) {
            return;
        }
        $attrs = $t['attrs'] ?? [];
        if (($t['t'] === self::START && (in_array($name, self::BREAKOUT, true) || ($name === 'font' && (isset($attrs['color']) || isset($attrs['face']) || isset($attrs['size'])))))
            || ($t['t'] === self::END && ($name === 'br' || $name === 'p'))) {
            while (($node = $this->current()) !== null && !$this->isHtml($node) && !$this->isHtmlIntegrationPoint($node)
                && !($node->namespaceURI === self::NS_MATHML && in_array($node->localName, ['mi', 'mo', 'mn', 'ms', 'mtext'], true))) {
                $this->pop();
            }
            $this->dispatch($t);

            return;
        }
        if ($t['t'] === self::START) {
            $namespace = (string) $this->namespace($this->adjustedCurrent());
            if ($namespace === self::NS_SVG) {
                $name = self::svgTags()[$name] ?? $name;
            }
            $this->insertForeign($name, $attrs, $namespace);
            if (!empty($t['self'])) {
                $this->pop();
            }

            return;
        }
        // an end tag closes the nearest foreign element of that name; an HTML element on the way hands it to the HTML rules
        $foreign = $this->top('f:' . $name);
        $html = $this->top('html');
        if ($foreign > $html && $foreign > 0) {
            while (count($this->stack) > $foreign) {
                $this->pop();
            }
        } elseif ($html > 0) {
            $this->dispatch($t);
        }
    }

    private function isHtmlIntegrationPoint(\DOMElement $node): bool
    {
        if ($node->namespaceURI === self::NS_SVG) {
            return in_array($node->localName, ['foreignObject', 'desc', 'title'], true);
        }

        return $node->namespaceURI === self::NS_MATHML && $node->localName === 'annotation-xml'
            && in_array(strtolower((string) $node->getAttribute('encoding')), ['text/html', 'application/xhtml+xml'], true);
    }

    /** @return array<string, string> */
    private static function svgTags(): array
    {
        static $map = null;

        return $map ??= array_combine(array_map('strtolower', self::SVG_TAGS), self::SVG_TAGS);
    }

    /** @param array<string, string> $attrs */
    private function insertForeign(string $name, array $attrs, string $namespace): void
    {
        $adjusted = [];
        $svgAttributes = $namespace === self::NS_SVG ? self::svgAttributes() : [];
        foreach ($attrs as $attribute => $value) {
            $adjusted[$svgAttributes[$attribute] ?? ($attribute === 'definitionurl' && $namespace === self::NS_MATHML ? 'definitionURL' : $attribute)] = $value;
        }
        $element = $this->createElement($name, $adjusted, $namespace);
        if ($element !== null) {
            $this->insertNode($element);
            $this->push($element);
        }
    }

    /** @return array<string, string> */
    private static function svgAttributes(): array
    {
        static $map = null;

        return $map ??= array_combine(array_map('strtolower', self::SVG_ATTRIBUTES), self::SVG_ATTRIBUTES);
    }

    /* ---------- the stack of open elements and the active formatting elements ---------- */

    /** @param array<string, string> $attrs */
    private function insert(string $name, array $attrs): ?\DOMElement
    {
        $element = $this->createElement($name, $attrs, self::NS_HTML);
        if ($element === null) {
            return null; // a name the legacy DOM cannot hold: its content goes to the parent
        }
        $this->insertNode($element);
        $this->push($element);

        return $element;
    }

    /** @param array<string, string> $attrs */
    private function createElement(string $name, array $attrs, string $namespace): ?\DOMElement
    {
        // a name with a colon stays whole (createElementNS() would bind its prefix, and descendants would inherit it)
        $literal = str_contains($name, ':');
        try {
            $element = ($namespace === self::NS_HTML && !$this->htmlNamespace) || $literal
                ? $this->doc->createElement($name)
                : $this->doc->createElementNS($namespace, $name);
        } catch (\DOMException) {
            // a stand-in named X<hex> (the tokenizer lowercases every name, so no tag can be called that) keeps the tree as
            // PHP 8.4 builds it – the stack, the scopes, where the content goes; parse() unwraps it at the end
            $element = $this->doc->createElementNS($namespace === self::NS_HTML && !$this->htmlNamespace ? null : $namespace, 'X' . bin2hex($name));
            $this->placeholders[] = $element;
            $literal = false;
        }
        if (!$element instanceof \DOMElement) {
            return null;
        }
        if ($literal && $namespace !== self::NS_HTML) {
            $this->literalNamespace[$element] = $namespace; // <svg><x:g> stays SVG for the parser
        }
        foreach ($attrs as $attribute => $value) {
            self::setAttribute($element, (string) $attribute, $value, $namespace !== self::NS_HTML); // a numeric name is an int key
        }

        return $element;
    }

    /**
     * Adds an attribute under exactly its name. Only the attributes the standard adjusts in SVG and MathML get a namespace;
     * any other name with a colon (xml:onerror, xlink:onload, x:href) and xmlns are literal attributes without one – the
     * legacy setAttribute() would bind the prefix, and the attribute would then be known by its local name (onerror).
     */
    private static function setAttribute(\DOMElement $element, string $name, string $value, bool $foreign): void
    {
        try {
            $namespace = $foreign ? (self::FOREIGN_ATTRIBUTES[$name] ?? null) : null;
            if ($namespace !== null) {
                $element->setAttributeNS($namespace, $name, $value);
            } elseif ($name === 'xmlns' || str_contains($name, ':')) {
                $element->setAttributeNode(new \DOMAttr($name, $value));
            } else {
                $element->setAttribute($name, $value);
            }
        } catch (\DOMException|\ValueError) {
            // a name like "@click" the legacy DOM cannot hold is left out
        }
    }

    /** The attribute of the qualified name (prefix included), as PHP 8.4 finds it. */
    private static function attributeNode(\DOMElement $element, string $name): ?\DOMAttr
    {
        foreach ($element->attributes as $attribute) {
            if ($attribute->nodeName === $name) {
                return $attribute;
            }
        }

        return null;
    }

    /** Inserts a node at the appropriate place: the current node, or before the table when foster parenting. */
    private function insertNode(\DOMNode $node, ?\DOMNode $override = null): void
    {
        [$parent, $before] = $this->insertionPlace($override);
        $parent->insertBefore($node, $before);
    }

    /** @return array{0: \DOMNode, 1: ?\DOMNode} */
    private function insertionPlace(?\DOMNode $override = null): array
    {
        $target = $override ?? $this->current() ?? $this->doc;
        if ($this->fosterParenting && $target instanceof \DOMElement && in_array($this->name($target), ['table', 'tbody', 'tfoot', 'thead', 'tr'], true)) {
            $template = $this->top('h:template');
            $table = $this->top('h:table');
            if ($template > $table) {
                return [$this->stack[$template], null]; // a template opened inside the table holds the content itself
            }
            if ($table >= 0) {
                $parent = $this->stack[$table]->parentNode;
                if ($parent !== null) {
                    return $this->shallow([$parent, $this->stack[$table]]);
                }

                return $this->shallow([$this->stack[$table - 1] ?? $this->doc, null]);
            }
        }

        return $this->shallow([$target, null]);
    }

    /**
     * A place at most MAX_DEPTH deep: deeper, a node goes to the ancestor at that depth (as in Chrome, which makes it a
     * sibling of its parent).
     *
     * @param array{0: \DOMNode, 1: ?\DOMNode} $place
     * @return array{0: \DOMNode, 1: ?\DOMNode}
     */
    private function shallow(array $place): array
    {
        $parent = $place[0];
        if (!$parent instanceof \DOMElement) {
            return $place;
        }
        $depth = $this->depthOf($parent);
        if ($depth < self::MAX_DEPTH) {
            return $place;
        }
        for (; $depth >= self::MAX_DEPTH && $parent->parentNode instanceof \DOMElement; $depth--) {
            $parent = $parent->parentNode;
        }

        return [$parent, null];
    }

    /** The depth of a node in the tree (the document element is 1): known for open elements, otherwise counted up to one. */
    private function depthOf(\DOMNode $node): int
    {
        $steps = 0;
        while ($node instanceof \DOMElement) {
            if ($this->open->offsetExists($node)) {
                return $steps + $this->open[$node][2];
            }
            $steps++;
            $node = $node->parentNode;
        }

        return $steps;
    }

    private function insertText(string $data): void
    {
        [$parent, $before] = $this->insertionPlace();
        if ($parent instanceof \DOMDocument) {
            return; // the document holds no text
        }
        $previous = $before === null ? $parent->lastChild : $before->previousSibling;
        if ($previous instanceof \DOMText && !$previous instanceof \DOMCdataSection) {
            $previous->appendData($data);
        } else {
            $parent->insertBefore($this->doc->createTextNode($data), $before);
        }
    }

    private function insertComment(string $data): void
    {
        $this->insertNode($this->doc->createComment($data));
    }

    private function current(): ?\DOMElement
    {
        return $this->stack === [] ? null : $this->stack[count($this->stack) - 1];
    }

    private function adjustedCurrent(): \DOMElement
    {
        return $this->stack[count($this->stack) - 1];
    }

    private function currentIs(string $name): bool
    {
        $current = $this->current();

        return $current !== null && $this->isHtml($current) && $this->name($current) === $name;
    }

    /** Every change of the stack goes through push() and pop(): they keep $positions and $open. */
    private function push(\DOMElement $element): void
    {
        $index = count($this->stack);
        $this->stack[] = $element;
        $keys = $this->keys($element);
        foreach ($keys as $key) {
            $this->positions[$key][] = $index;
        }
        $this->open[$element] = [$index, $keys, $this->depthOf($element)];
    }

    private function pop(): ?\DOMElement
    {
        $element = array_pop($this->stack);
        if ($element !== null) {
            foreach ($this->open[$element][1] as $key) {
                array_pop($this->positions[$key]);
            }
            $this->open->offsetUnset($element);
        }

        return $element;
    }

    /**
     * The stack from $from up becomes $tail – for the rare changes below the current node (a closed form, the adoption
     * agency); it costs the length of the replaced part.
     *
     * @param list<\DOMElement> $tail
     */
    private function replaceStack(int $from, array $tail): void
    {
        while (count($this->stack) > $from) {
            $this->pop();
        }
        foreach ($tail as $element) {
            $this->push($element);
        }
    }

    /**
     * The keys of an element in $positions.
     *
     * @return list<string>
     */
    private function keys(\DOMElement $node): array
    {
        $sets = self::sets();
        if (!$this->isHtml($node)) {
            $keys = ['f:' . strtolower($this->name($node))];
            if ($this->isScopeBoundary($node)) {
                array_push($keys, 'scope', 'special', 'special-li');
            }

            return $keys;
        }
        $name = $this->name($node);
        $keys = ['h:' . $name, 'html'];
        if (isset($sets['special'][$name])) {
            $keys[] = 'special';
            if ($name !== 'address' && $name !== 'div' && $name !== 'p') {
                $keys[] = 'special-li';
            }
        }
        if (isset($sets['scope'][$name])) {
            $keys[] = 'scope';
        }
        if (isset($sets['reset'][$name])) {
            $keys[] = 'reset';
        }

        return $keys;
    }

    /** @return array<string, array<string, true>> */
    private static function sets(): array
    {
        return self::$sets ??= [
            'special' => array_fill_keys(self::SPECIAL, true),
            'scope' => array_fill_keys(self::SCOPE, true),
            'reset' => array_fill_keys(self::RESET, true),
        ];
    }

    /** The topmost stack position of a key, -1 when none is open. */
    private function top(string $key): int
    {
        $list = $this->positions[$key] ?? [];

        return $list === [] ? -1 : $list[count($list) - 1];
    }

    /** The lowest stack position of a key above $index, -1 when none. */
    private function firstAbove(string $key, int $index): int
    {
        $list = $this->positions[$key] ?? [];
        $low = 0;
        $high = count($list);
        while ($low < $high) {
            $middle = ($low + $high) >> 1;
            if ($list[$middle] > $index) {
                $high = $middle;
            } else {
                $low = $middle + 1;
            }
        }

        return $list[$low] ?? -1;
    }

    private function indexOf(\DOMElement $element): int
    {
        return $this->open->offsetExists($element) ? $this->open[$element][0] : -1;
    }

    /** @param list<string> $names pops until (and including) an HTML element of one of the names */
    private function popUntil(array $names): void
    {
        while (($node = $this->pop()) !== null) {
            if ($this->isHtml($node) && in_array($this->name($node), $names, true)) {
                return;
            }
        }
    }

    /** @param list<string> $names */
    private function clearStackTo(array $names): void
    {
        while (($node = $this->current()) !== null && !($this->isHtml($node) && in_array($this->name($node), $names, true))) {
            $this->pop();
        }
    }

    private function removeFromStack(\DOMElement $element): void
    {
        $index = $this->indexOf($element);
        if ($index >= 0) {
            $this->replaceStack($index, array_slice($this->stack, $index + 1));
        }
    }

    private function inStack(string $name): bool
    {
        return $this->top('h:' . $name) >= 0;
    }

    /**
     * Whether an HTML element of the name is in scope (13.2.4.2): the nearest one is not below a boundary.
     *
     * @param list<string> $extra additional boundaries (button scope, list item scope)
     */
    private function inScope(string $name, array $extra = [], bool $table = false): bool
    {
        $target = $this->top('h:' . $name);
        if ($target < 0) {
            return false;
        }
        if ($table) {
            $boundary = max($this->top('h:html'), $this->top('h:table'), $this->top('h:template'));
        } else {
            $boundary = $this->top('scope');
            foreach ($extra as $other) {
                $boundary = max($boundary, $this->top('h:' . $other));
            }
        }

        return $target >= $boundary; // the same position = the element itself is a boundary (select, td…)
    }

    private function elementInScope(\DOMElement $element): bool
    {
        $index = $this->indexOf($element);

        return $index >= 0 && $index >= $this->top('scope');
    }

    private function isScopeBoundary(\DOMElement $node): bool
    {
        return match ($this->namespace($node)) {
            self::NS_MATHML => in_array($node->localName, ['mi', 'mo', 'mn', 'ms', 'mtext', 'annotation-xml'], true),
            self::NS_SVG => in_array($node->localName, ['foreignObject', 'desc', 'title'], true),
            default => isset(self::sets()['scope'][$this->name($node)]),
        };
    }

    private function generateImpliedEndTags(?string $except = null): void
    {
        while (($node = $this->current()) !== null && $this->isHtml($node) && in_array($this->name($node), self::IMPLIED_END, true) && $this->name($node) !== $except) {
            $this->pop();
        }
    }

    private function closePInButtonScope(): void
    {
        if ($this->inScope('p', ['button'])) {
            $this->closeP();
        }
    }

    private function closeP(): void
    {
        $this->generateImpliedEndTags('p');
        $this->popUntil(['p']);
    }

    private function resetMode(): void
    {
        $i = $this->top('reset'); // the nearest element that decides; anything else above it changes nothing
        $name = $i >= 0 ? $this->name($this->stack[$i]) : '';
        $this->mode = match (true) {
            $name === 'td' || $name === 'th' => $i > 0 ? self::IN_CELL : self::IN_BODY,
            $name === 'tr' => self::IN_ROW,
            in_array($name, ['tbody', 'thead', 'tfoot'], true) => self::IN_TABLE_BODY,
            $name === 'caption' => self::IN_CAPTION,
            $name === 'colgroup' => self::IN_COLUMN_GROUP,
            $name === 'table' => self::IN_TABLE,
            $name === 'head' => $i > 0 ? self::IN_HEAD : self::IN_BODY,
            $name === 'template' => $this->templateModes[count($this->templateModes) - 1] ?? self::IN_BODY,
            $name === 'html' => $this->head === null ? self::BEFORE_HEAD : self::AFTER_HEAD,
            default => self::IN_BODY,
        };
    }

    private function reconstructFormatting(): void
    {
        $count = count($this->formatting);
        if ($count === 0) {
            return;
        }
        $entry = $this->formatting[$count - 1];
        if ($entry === null || $this->open->offsetExists($entry)) {
            return;
        }
        $i = $count - 1;
        while ($i > 0 && ($previous = $this->formatting[$i - 1]) !== null && !$this->open->offsetExists($previous)) {
            $i--;
        }
        for (; $i < $count; $i++) {
            $old = $this->formatting[$i];
            if ($old === null || $this->reconstructBudget <= 0) {
                continue;
            }
            $this->reconstructBudget--;
            $clone = $old->cloneNode(false);
            if (!$clone instanceof \DOMElement) {
                continue;
            }
            $this->insertNode($clone);
            $this->push($clone);
            $this->replaceFormatting($i, $clone);
        }
    }

    /**
     * Pushes onto the list of active formatting elements: of three alike after the last marker (same name and attributes)
     * the earliest goes (Noah's Ark), and so does the earliest of MAX_FORMATTING.
     */
    private function pushFormatting(\DOMElement $element): void
    {
        $signature = (string) $element->localName;
        $pairs = [];
        foreach ($element->attributes as $attribute) {
            $pairs[$attribute->nodeName] = $attribute->value;
        }
        if ($pairs !== []) {
            ksort($pairs);
            $signature .= "\0" . serialize($pairs);
        }
        $alike = [];
        $first = -1;
        for ($i = count($this->formatting) - 1; $i >= 0 && ($entry = $this->formatting[$i]) !== null; $i--) {
            $first = $i;
            if ($this->active[$entry] === $signature) {
                $alike[] = $i;
            }
        }
        if (count($alike) >= 3) {
            $this->removeFormattingAt($alike[count($alike) - 1]);
        } elseif ($first >= 0 && count($this->formatting) - $first >= self::MAX_FORMATTING) {
            $this->removeFormattingAt($first);
        }
        $this->formatting[] = $element;
        $this->active[$element] = $signature;
    }

    private function replaceFormatting(int $index, \DOMElement $clone): void
    {
        $old = $this->formatting[$index];
        if ($old !== null) {
            $this->active[$clone] = $this->active[$old];
            $this->active->offsetUnset($old);
        }
        $this->formatting[$index] = $clone;
    }

    private function removeFormattingAt(int $index): void
    {
        $element = $this->formatting[$index] ?? null;
        array_splice($this->formatting, $index, 1);
        if ($element !== null) {
            $this->active->offsetUnset($element);
        }
    }

    /** The position in the list of active formatting elements, searched from the end (where the ones in use are). */
    private function formattingIndex(\DOMElement $element): int
    {
        if (!$this->active->offsetExists($element)) {
            return -1;
        }
        for ($i = count($this->formatting) - 1; $i >= 0; $i--) {
            if ($this->formatting[$i] === $element) {
                return $i;
            }
        }

        return -1;
    }

    private function clearFormattingToMarker(): void
    {
        while ($this->formatting !== [] && ($entry = array_pop($this->formatting)) !== null) {
            $this->active->offsetUnset($entry); // up to and including the last marker
        }
    }

    private function removeFormatting(\DOMElement $element): void
    {
        $index = $this->formattingIndex($element);
        if ($index >= 0) {
            $this->removeFormattingAt($index);
        }
    }

    /** The adoption agency algorithm (13.2.6.4.7): misnested formatting such as <b><p>x</b>y</p> as a browser repairs it. */
    private function adoptionAgency(string $subject): void
    {
        $current = $this->current();
        if ($current !== null && $this->isHtml($current) && $this->name($current) === $subject && !$this->active->offsetExists($current)) {
            $this->pop();

            return;
        }
        for ($outer = 0; $outer < 8; $outer++) {
            $formattingElement = null;
            for ($i = count($this->formatting) - 1; $i >= 0 && $this->formatting[$i] !== null; $i--) {
                if ($this->name($this->formatting[$i]) === $subject) {
                    $formattingElement = $this->formatting[$i];
                    break;
                }
            }
            if ($formattingElement === null) {
                $this->anyOtherEndTag($subject);

                return;
            }
            $stackIndex = $this->indexOf($formattingElement);
            if ($stackIndex < 0) {
                $this->removeFormatting($formattingElement);

                return;
            }
            if (!$this->elementInScope($formattingElement)) {
                return;
            }
            $furthestIndex = $this->firstAbove('special', $stackIndex);
            // past its budget (pathological markup only) the algorithm just closes the element, as without a furthest block
            $cost = count($this->stack) - $stackIndex;
            if ($furthestIndex < 0 || $this->adoptionBudget < $cost) {
                while (count($this->stack) > $stackIndex) {
                    $this->pop();
                }
                $this->removeFormatting($formattingElement);

                return;
            }
            $this->adoptionBudget -= $cost;
            $furthestBlock = $this->stack[$furthestIndex];
            $commonAncestor = $this->stack[$stackIndex - 1];
            $bookmark = $this->formattingIndex($formattingElement);
            // the stack from the formatting element up, changed here and put back at once (null = removed)
            $segment = array_slice($this->stack, $stackIndex);
            $nodeIndex = $furthestIndex - $stackIndex;
            $lastNode = $furthestBlock;
            for ($inner = 1; ; $inner++) {
                $nodeIndex--;
                $node = $segment[$nodeIndex];
                if ($node === null || $node === $formattingElement) {
                    break;
                }
                $formattingIndex = $this->formattingIndex($node);
                if ($inner > 3 && $formattingIndex >= 0) {
                    $this->removeFormattingAt($formattingIndex);
                    if ($formattingIndex < $bookmark) {
                        $bookmark--;
                    }
                    $formattingIndex = -1;
                }
                if ($formattingIndex < 0) {
                    $segment[$nodeIndex] = null;
                    continue;
                }
                $clone = $node->cloneNode(false);
                if (!$clone instanceof \DOMElement) {
                    return;
                }
                $this->replaceFormatting($formattingIndex, $clone);
                $segment[$nodeIndex] = $clone;
                $node = $clone;
                if ($lastNode === $furthestBlock) {
                    $bookmark = $formattingIndex + 1;
                }
                $node->appendChild($lastNode);
                $lastNode = $node;
            }
            $this->insertNode($lastNode, $commonAncestor);
            $replacement = $formattingElement->cloneNode(false);
            if (!$replacement instanceof \DOMElement) {
                return;
            }
            while ($furthestBlock->firstChild !== null) {
                $replacement->appendChild($furthestBlock->firstChild);
            }
            $furthestBlock->appendChild($replacement);
            $signature = $this->active[$formattingElement];
            $oldIndex = $this->formattingIndex($formattingElement);
            $this->removeFormattingAt($oldIndex);
            if ($oldIndex < $bookmark) {
                $bookmark--;
            }
            array_splice($this->formatting, min($bookmark, count($this->formatting)), 0, [$replacement]);
            $this->active[$replacement] = $signature;
            // the formatting element leaves the stack, its replacement goes right above the furthest block
            $tail = [];
            foreach ($segment as $k => $element) {
                if ($k === 0 || $element === null) {
                    continue;
                }
                $tail[] = $element;
                if ($element === $furthestBlock) {
                    $tail[] = $replacement;
                }
            }
            $this->replaceStack($stackIndex, $tail);
        }
    }

    /** The namespace the parser works with: <svg><x:g> has none in the DOM but is an SVG element. */
    private function namespace(\DOMElement $node): ?string
    {
        if ($node->namespaceURI !== null) {
            return $node->namespaceURI;
        }

        return $this->literalNamespace->offsetExists($node) ? $this->literalNamespace[$node] : null;
    }

    private function isHtml(\DOMElement $node): bool
    {
        $namespace = $this->namespace($node);

        return $namespace === self::NS_HTML || $namespace === null;
    }

    private function name(\DOMElement $node): string
    {
        $name = (string) $node->localName;

        return ($name[0] ?? '') === 'X' && $this->placeholders !== [] ? (string) hex2bin(substr($name, 1)) : $name;
    }
}
