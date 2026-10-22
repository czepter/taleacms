<?php

declare(strict_types=1);

namespace Talea\Core;

/**
 * Site language and translations of template texts.
 *
 * Texts in templates and code are wrapped in the function t('Read more'). The source text is English (since 1.4.1; texts
 * not switched yet are still Czech) and is looked up in the dictionary system/languages/<code>.php (source text => translation),
 * Czech included (cs.php). What is missing in the dictionary is taken from the English one, otherwise the source text is
 * shown as it is - the site never breaks.
 * The language of the whole site is set in Settings (site_language); the extension "languages" adds more language versions
 * at URLs /en/… - each has its own pages, categories and news. A new language = dictionaries system/languages/<code>.php
 * (site), admin-<code>.php and install-<code>.php + an entry in AVAILABLE.
 */
final class Language
{
    /**
     * code => [name in that language, locale (Open Graph, date format), numeric date format for date()]. Arabic is the first
     * right-to-left language: the site writes <html dir> by dir() and its CSS uses logical properties.
     * Regional versions (en-gb, de-at, pt-br, zh-hant) are stored and addressed in lowercase (/pt-br/) and follow their language: they use
     * the dictionary of base() unless they have one of their own, and print their tag in the canonical case (tag(): pt-BR) in lang and hreflang.
     */
    public const array AVAILABLE = [
        'cs' => ['Čeština', 'cs_CZ', 'j. n. Y'],
        'en' => ['English', 'en_US', 'j M Y'],
        'en-gb' => ['English (UK)', 'en_GB', 'j M Y'],
        'bg' => ['Български', 'bg_BG', 'd.m.Y'],
        'ca' => ['Català', 'ca_ES', 'd/m/Y'],
        'da' => ['Dansk', 'da_DK', 'd.m.Y'],
        'de' => ['Deutsch', 'de_DE', 'd.m.Y'],
        'de-at' => ['Deutsch (Österreich)', 'de_AT', 'd.m.Y'],
        'el' => ['Ελληνικά', 'el_GR', 'd/m/Y'],
        'es' => ['Español', 'es_ES', 'd/m/Y'],
        'et' => ['Eesti', 'et_EE', 'd.m.Y'],
        'fi' => ['Suomi', 'fi_FI', 'j.n.Y'],
        'fr' => ['Français', 'fr_FR', 'd/m/Y'],
        'ga' => ['Gaeilge', 'ga_IE', 'd/m/Y'],
        'hr' => ['Hrvatski', 'hr_HR', 'd.m.Y.'],
        'hu' => ['Magyar', 'hu_HU', 'Y. m. d.'],
        'is' => ['Íslenska', 'is_IS', 'j.n.Y'],
        'it' => ['Italiano', 'it_IT', 'd/m/Y'],
        'lt' => ['Lietuvių', 'lt_LT', 'Y-m-d'],
        'lv' => ['Latviešu', 'lv_LV', 'd.m.Y.'],
        'mt' => ['Malti', 'mt_MT', 'd/m/Y'],
        'nl' => ['Nederlands', 'nl_NL', 'd-m-Y'],
        'no' => ['Norsk', 'nb_NO', 'd.m.Y'],
        'pl' => ['Polski', 'pl_PL', 'd.m.Y'],
        'pt' => ['Português', 'pt_PT', 'd/m/Y'],
        'pt-br' => ['Português (Brasil)', 'pt_BR', 'd/m/Y'],
        'ro' => ['Română', 'ro_RO', 'd.m.Y'],
        'sk' => ['Slovenčina', 'sk_SK', 'j. n. Y'],
        'sl' => ['Slovenščina', 'sl_SI', 'j. n. Y'],
        'sq' => ['Shqip', 'sq_AL', 'd.m.Y'],
        'sr' => ['Srpski', 'sr_RS', 'd.m.Y.'],
        'bs' => ['Bosanski', 'bs_BA', 'd.m.Y.'],
        'mk' => ['Македонски', 'mk_MK', 'd.m.Y'],
        'sv' => ['Svenska', 'sv_SE', 'Y-m-d'],
        'tr' => ['Türkçe', 'tr_TR', 'd.m.Y'],
        'uk' => ['Українська', 'uk_UA', 'd.m.Y'],
        'ru' => ['Русский', 'ru_RU', 'd.m.Y'],
        'hi' => ['हिन्दी', 'hi_IN', 'd/m/Y'],
        'id' => ['Bahasa Indonesia', 'id_ID', 'd/m/Y'],
        'ja' => ['日本語', 'ja_JP', 'Y/m/d'],
        'ko' => ['한국어', 'ko_KR', 'Y. m. d.'],
        'vi' => ['Tiếng Việt', 'vi_VN', 'd/m/Y'],
        'zh' => ['中文', 'zh_CN', 'Y-m-d'],
        'zh-hant' => ['中文（繁體）', 'zh_TW', 'Y/m/d'],
        'ar' => ['العربية', 'ar_SA', 'd/m/Y'],
    ];

    /** Codes from AVAILABLE for Settings field types (choice:… / list:…). */
    public const string CODES = 'cs|en|en-gb|bg|ca|da|de|de-at|el|es|et|fi|fr|ga|hr|hu|is|it|lt|lv|mt|nl|no|pl|pt|pt-br|ro|sk|sl|sq|sr|bs|mk|sv|tr|uk|ru|hi|id|ja|ko|vi|zh|zh-hant|ar';

    /** A stored language code as a regular expression (without delimiters): "de", "pt-br", "zh-hant". The only place the shape of a code is written. */
    public const string TAG = '[a-z]{2}(?:-[a-z]{2,4})?';

    /**
     * Languages written right to left: Arabic is offered; Hebrew, Persian, Urdu and the others are ready for the day a
     * dictionary comes. A script subtag decides first - az-arab is right to left, a Latin transliteration of an RTL language is not.
     */
    public const array RTL = ['ar', 'he', 'fa', 'ur', 'ps', 'sd', 'ug', 'yi', 'dv', 'ckb'];

    /** Scripts written right to left (ISO 15924, lowercase as a stored tag has them). */
    private const array RTL_SCRIPTS = ['arab', 'hebr', 'thaa', 'syrc', 'nkoo', 'adlm', 'rohg', 'mand', 'samr'];

    private static string $code = 'en';

    private static bool $loaded = false;
    private static string $column = '';

    /** @var array<string, string> */
    private static array $dictionary = [];

    /** Languages the administration is translated into (dictionary system/languages/admin-<code>.php). */
    public const array ADMIN_LANGUAGES = ['cs' => 'Čeština', 'en' => 'English', 'de' => 'Deutsch'];

    /**
     * German registers (issue #20): formal (Sie, the base dictionaries) and informal (du, an overlay system/languages/<set>de-du.php with only the
     * strings that contain a form of address). The register is a setting, not a language: the code stays "de". Informal is the default: Sie only by choice.
     */
    public const array REGISTERS = ['formal', 'informal'];

    private static string $register = 'informal';

    /** Register of the administration texts (the user's choice) and of the site texts (setting german_register), for code that switches language. */
    private static string $adminRegister = 'informal';

    private static string $siteRegister = 'informal';

    /** The language has no dictionary of its own, texts come from the English one (the date in words then comes from the intl extension, if the server has it). */
    private static bool $baseOnly = false;

    /**
     * The language's dictionary: its own (system/languages/<set><code>.php), and what is missing there, from the English one – a language
     * without a dictionary thus has template texts in English and the date in its own numeric format. Czech needs no dictionary (texts in the code are Czech).
     *
     * @param string $dictionarySet "" = site texts, "admin-" = administration texts, "install-" = installer texts
     * @param string|null $register formal | informal (only German has it); null = the register of the administration (admin-, install-) or of the site
     */
    public static function set(string $code, string $dictionarySet = '', ?string $register = null): void
    {
        self::$code = isset(self::AVAILABLE[$code]) ? $code : 'en';
        self::$register = in_array($register, self::REGISTERS, true) ? $register : ($dictionarySet === '' ? self::$siteRegister : self::$adminRegister);
        self::$loaded = true;
        self::$dictionary = [];
        self::$baseOnly = false;
        if (self::$code === 'cs') {
            // the source texts are English since 1.4.1; Czech is a dictionary like any other (texts still Czech pass through)
            $file = TALEA_SYSTEM . '/languages/' . $dictionarySet . 'cs.php';
            self::$dictionary = is_file($file) ? require $file : [];

            return;
        }
        // a regional version without a dictionary of its own uses the one of its language (de-at → de.php, en-gb → en.php)
        $file = TALEA_SYSTEM . '/languages/' . $dictionarySet . self::$code . '.php';
        $file = is_file($file) ? $file : TALEA_SYSTEM . '/languages/' . $dictionarySet . self::base(self::$code) . '.php';
        $custom = is_file($file) ? require $file : [];
        $overlay = TALEA_SYSTEM . '/languages/' . $dictionarySet . self::base(self::$code) . '-du.php';
        if (self::$register === 'informal' && is_file($overlay)) {
            $custom = (require $overlay) + $custom;
        }
        $baseDictionary = self::base(self::$code) !== 'en' && is_file(TALEA_SYSTEM . '/languages/' . $dictionarySet . 'en.php') ? require TALEA_SYSTEM . '/languages/' . $dictionarySet . 'en.php' : [];
        self::$dictionary = $custom + $baseDictionary;
        if (self::base(self::$code) !== 'en') {
            // the date format from the English dictionary is not taken over: its own, otherwise the language's numeric format and the date in words from the Czech keys of days and months
            if (!isset($custom['datum_format'])) {
                self::$dictionary['datum_format'] = self::AVAILABLE[self::$code][2];
            }
            if (!isset($custom['date_in_words'])) {
                unset(self::$dictionary['date_in_words']);
            }
            self::$baseOnly = $custom === [];
        }
    }

    /** Locale for the date in words through the intl extension – only for a language without its own dictionary; otherwise null. */
    public static function intlLocale(): ?string
    {
        return self::$baseOnly && class_exists(\IntlDateFormatter::class) ? self::AVAILABLE[self::$code][1] : null;
    }

    /**
     * Language of the currently shown version of the site; also remembers the value of the "language" column for queries.
     */
    public static function setSite(Settings $s, string $code): void
    {
        self::setSiteRegister($s->get('german_register'));
        self::set($code);
        self::$column = self::column($s, self::$code);
    }

    /**
     * Runs a function with the site texts in another language and switches the language back. For content whose language does not
     * depend on who is creating it – typically an e-mail to a visitor (triggered by an administrator in the administration or by a background task).
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public static function runWith(string $code, callable $callback, string $dictionarySet = '', ?string $register = null): mixed
    {
        [$previousCode, $previousDictionary, $previousBase, $previousRegister] = [self::$code, self::$dictionary, self::$baseOnly, self::$register];
        self::set($code, $dictionarySet, $register); // set "admin-" = an e-mail to an administration user in the language of their administration
        try {
            return $callback();
        } finally {
            [self::$code, self::$dictionary, self::$baseOnly, self::$register] = [$previousCode, $previousDictionary, $previousBase, $previousRegister];
        }
    }

    /** Value of the "language" column for the currently shown version of the site ('' = default language). Only '' or a code of TAG (de, pt-br). */
    public static function siteColumn(): string
    {
        return self::$column;
    }

    /** The language subtag of a stored code: pt-br → pt, zh-hant → zh, cs → cs. What a regional version shares with its language. */
    public static function base(string $code): string
    {
        return explode('-', $code, 2)[0];
    }

    /**
     * A stored code in the canonical case of BCP 47 for <html lang>, hreflang, feeds and structured data: the language lowercase, a
     * script titlecase, a region uppercase – pt-br → pt-BR, zh-hant → zh-Hant. A two-letter code stays as it is. URLs, settings keys and
     * the database keep the lowercase form.
     */
    public static function tag(string $code): string
    {
        $parts = explode('-', strtolower($code));
        foreach ($parts as $i => $part) {
            if ($i > 0) {
                $parts[$i] = strlen($part) === 4 ? ucfirst($part) : strtoupper($part);
            }
        }

        return implode('-', $parts);
    }

    /**
     * A code from another system (a WordPress locale pt_BR, a slug zh-tw, nb) as the site offers it: the longest part of it that is in
     * AVAILABLE (pt_BR → pt-br, zh-Hant-TW → zh-hant, en-US → en, nb → no); '' = a language this site cannot offer.
     */
    public static function fromForeign(string $tag): string
    {
        $tag = (string) preg_replace('/^n[bn](?=-|$)/', 'no', strtolower(str_replace('_', '-', trim($tag))));
        $tag = (string) preg_replace('/^zh-(tw|hk|mo)(?=-|$)/', 'zh-hant', $tag); // Traditional Chinese is written by its region as well
        if (preg_match('/^' . self::TAG . '(?:-[a-z0-9]{2,8})*$/D', $tag) !== 1) {
            return '';
        }
        for ($parts = explode('-', $tag); $parts !== []; array_pop($parts)) {
            if (isset(self::AVAILABLE[$code = implode('-', $parts)])) {
                return $code;
            }
        }

        return '';
    }

    /**
     * A code for a site with the languages $site, which an import uses so a source lands in the version the site has: a regional version
     * the site does not have, whose language it has, becomes that language (en-gb into a site with en); a language the site has only as
     * a regional version becomes that version (en into a site with en-gb); anything else stays.
     *
     * @param list<string> $site the default and the additional languages
     */
    public static function forSite(string $code, array $site): string
    {
        if ($code === '' || in_array($code, $site, true)) {
            return $code;
        }
        if (in_array(self::base($code), $site, true)) {
            return self::base($code);
        }
        foreach ($site as $have) {
            if (self::base($have) === $code) {
                return $have;
            }
        }

        return $code;
    }

    /**
     * Language of a user's administration: their choice (My account), otherwise the site's default language – a regional code gives the
     * administration of its language (de-at → de), one the administration is not translated into gives English.
     *
     * @param array<string, mixed>|null $user
     */
    public static function adminCode(?array $user, Settings $s): string
    {
        $language = self::base((string) ($user['language'] ?? '') ?: self::defaults($s));

        return isset(self::ADMIN_LANGUAGES[$language]) ? $language : 'en';
    }

    /**
     * The admin script dictionaries of the loaded language that exist (image/languages/admin-<code>.js, then for the informal register its
     * -du overlay), in the order a page loads them: every page with admin scripts (T()) prints all of them.
     *
     * @return list<string>
     */
    public static function scriptDictionaries(): array
    {
        $files = ['image/languages/admin-' . self::$code . '.js', ...(self::$register === 'informal' ? ['image/languages/admin-' . self::$code . '-du.js'] : [])];

        return array_values(array_filter($files, fn (string $file): bool => is_file(TALEA_ROOT . '/' . $file)));
    }

    /** Any value to formal | informal (anything but an explicit "formal", empty included, is informal). */
    public static function normalizeRegister(string $register): string
    {
        return $register === 'formal' ? 'formal' : 'informal';
    }

    /** Register of the administration of the person using it (My account); texts of the administration, installer and e-mails to staff follow it. */
    public static function setAdminRegister(string $register): void
    {
        self::$adminRegister = self::normalizeRegister($register);
    }

    /** Register of the site texts (setting german_register): the texts for visitors and e-mails to them. */
    public static function setSiteRegister(string $register): void
    {
        self::$siteRegister = self::normalizeRegister($register);
    }

    /**
     * Form of address of the German texts for visitors, or null when no version of the site is German – it is what Claude needs to know
     * when it writes pages, news or replies to enquiries for visitors (the administration may use the other form).
     */
    public static function visitorAddress(Settings $s): ?string
    {
        return in_array('de', array_map(self::base(...), array_merge([self::defaults($s)], self::additional($s))), true) ? self::normalizeRegister($s->get('german_register')) : null;
    }

    /** Register of the currently loaded dictionary: only German has an informal one. */
    public static function register(): string
    {
        return self::$register;
    }

    /** Is the language written right to left? By the script subtag when the code has one, otherwise by the language (RTL). */
    public static function isRtl(string $code): bool
    {
        $parts = explode('-', strtolower($code));
        foreach (array_slice($parts, 1) as $part) {
            if (strlen($part) === 4 && ctype_alpha($part)) {
                return in_array($part, self::RTL_SCRIPTS, true);
            }
        }

        return in_array($parts[0], self::RTL, true);
    }

    /** The value of the HTML dir attribute for a language: rtl or ltr. */
    public static function dir(string $code): string
    {
        return self::isRtl($code) ? 'rtl' : 'ltr';
    }

    /**
     * ' dir="rtl"' for a right-to-left language, otherwise '' - a left-to-right page keeps its markup as it was (ltr is the
     * default of HTML). For <html>, e-mails and content areas of the administration.
     */
    public static function dirAttribute(string $code): string
    {
        return self::isRtl($code) ? ' dir="rtl"' : '';
    }

    public static function code(): string
    {
        return self::$code;
    }

    public static function t(string $text, string|int ...$values): string
    {
        if (!self::$loaded) {
            self::set(self::$code); // texts before the first set() (early errors, tools) use the default language too
        }
        $translation = self::$dictionary[$text] ?? $text;
        if ($values !== [] && self::isRtl(self::$code)) {
            // a name, an address or a date inside a right-to-left sentence is its own direction island (first strong isolate),
            // so "(5 cm)" of a Latin product name does not lose its bracket to the Arabic around it
            $values = array_map(fn (string|int $v): string|int => is_string($v) && $v !== '' ? "\u{2068}" . $v . "\u{2069}" : $v, $values);
        }

        return $values === [] ? $translation : sprintf($translation, ...$values);
    }

    /** Default language of the site. */
    public static function defaults(Settings $s): string
    {
        return isset(self::AVAILABLE[$s->get('site_language')]) ? $s->get('site_language') : 'en';
    }

    /**
     * Additional language versions of the site (without the default language); empty when the extension is disabled.
     *
     * @return list<string>
     */
    public static function additional(Settings $s): array
    {
        if (!Extensions::isEnabled($s, 'languages')) {
            return [];
        }

        return array_values(array_diff(array_intersect(explode(',', $s->get('additional_languages')), array_keys(self::AVAILABLE)), [self::defaults($s)]));
    }

    /**
     * Additional languages the site offers to visitors and search engines (language switcher, hreflang, sitemap). When the home
     * of the site is a page, only languages with its published translation – a language version in progress is not shown yet.
     *
     * @return list<string>
     */
    public static function published(Settings $s, Db $db): array
    {
        $additional = self::additional($s);
        $home = $s->int('home_page');
        if ($additional === [] || $home === 0) {
            return $additional;
        }
        $done = array_column($db->all('SELECT DISTINCT language FROM {pages} WHERE translation_of = ? AND visible = TRUE AND deleted_at IS NULL', [$home]), 'language');

        return array_values(array_intersect($additional, $done));
    }

    /** Language of content by the "language" column (page, category, news item): empty = the site's default language. */
    public static function ofContent(Settings $s, string $column): string
    {
        return isset(self::AVAILABLE[$column]) ? $column : self::defaults($s);
    }

    /** Value of the "language" column for the given language: the site's default language is stored as an empty string. */
    public static function column(Settings $s, string $code): string
    {
        $code = self::forSite($code, [self::defaults($s), ...self::additional($s)]); // en-gb into a site with English only is English

        return $code === self::defaults($s) || !in_array($code, self::additional($s), true) ? '' : $code;
    }
}
