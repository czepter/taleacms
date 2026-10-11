<?php
/**
 * Global helper functions for templates and layouts.
 */

declare(strict_types=1);

/** Escaping output into HTML. */
function e(string|int|float|null $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * A value people typed (a name, an e-mail, a title) printed inside the administration's left-to-right text: escaped and
 * isolated in <bdi>, so an Arabic name keeps its order and does not pull the punctuation around it (looks the same for Latin text).
 */
function bdi(string|int|float|null $value): string
{
    return '<bdi>' . e($value) . '</bdi>';
}

/** Translation of a template text into the site language: t('Read more'), t('Page %s of %s', 2, 5). See Core\Language. */
function t(string $text, string|int ...$values): string
{
    return Talea\Core\Language::t($text, ...$values);
}

/** Text without diacritics (for URLs and search): "Müller Straße" -> "Muller Strasse". */
function remove_diacritics(string $text): string
{
    static $mapping = ['á' => 'a', 'ä' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'å' => 'a', 'č' => 'c', 'ć' => 'c', 'ç' => 'c', 'ď' => 'd', 'é' => 'e', 'ě' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i', 'ľ' => 'l', 'ĺ' => 'l', 'ň' => 'n', 'ń' => 'n', 'ñ' => 'n', 'ó' => 'o', 'ô' => 'o', 'ö' => 'o', 'ò' => 'o', 'õ' => 'o', 'ő' => 'o', 'ř' => 'r', 'ŕ' => 'r', 'š' => 's', 'ś' => 's', 'ť' => 't', 'ú' => 'u', 'ů' => 'u', 'ü' => 'u', 'ù' => 'u', 'û' => 'u', 'ű' => 'u', 'ý' => 'y', 'ÿ' => 'y', 'ž' => 'z', 'ź' => 'z', 'ż' => 'z', 'Á' => 'A', 'Ä' => 'A', 'À' => 'A', 'Â' => 'A', 'Ã' => 'A', 'Å' => 'A', 'Č' => 'C', 'Ć' => 'C', 'Ç' => 'C', 'Ď' => 'D', 'É' => 'E', 'Ě' => 'E', 'È' => 'E', 'Ê' => 'E', 'Ë' => 'E', 'Í' => 'I', 'Ì' => 'I', 'Î' => 'I', 'Ï' => 'I', 'Ľ' => 'L', 'Ĺ' => 'L', 'Ň' => 'N', 'Ń' => 'N', 'Ñ' => 'N', 'Ó' => 'O', 'Ô' => 'O', 'Ö' => 'O', 'Ò' => 'O', 'Õ' => 'O', 'Ő' => 'O', 'Ř' => 'R', 'Ŕ' => 'R', 'Š' => 'S', 'Ś' => 'S', 'Ť' => 'T', 'Ú' => 'U', 'Ů' => 'U', 'Ü' => 'U', 'Ù' => 'U', 'Û' => 'U', 'Ű' => 'U', 'Ý' => 'Y', 'Ÿ' => 'Y', 'Ž' => 'Z', 'Ź' => 'Z', 'Ż' => 'Z', 'ł' => 'l', 'Ł' => 'L', 'ß' => 'ss', 'đ' => 'd', 'Đ' => 'D', 'ø' => 'o', 'Ø' => 'O', 'æ' => 'ae', 'Æ' => 'AE']; // check-english: allow

    return strtr($text, $mapping);
}

/**
 * Converting text to URL form: "Müller Straße" -> "muller-strasse". Arabic (and Persian) letters get a plain Latin
 * transliteration without vowels, "صفحة الخدمات" -> "sfha-alkhdmat"; a title with nothing to transliterate gives "n-a"
 * (a new page, news item, category or item then takes its type and number, Core\Slug::fromId).
 */
function slugify(string $text, int $maxLength = 120): string
{
    $map = [
        'á' => 'a', 'ä' => 'a', 'č' => 'c', 'ď' => 'd', 'é' => 'e', 'ě' => 'e', 'í' => 'i', // check-english: allow
        'ľ' => 'l', 'ĺ' => 'l', 'ň' => 'n', 'ó' => 'o', 'ô' => 'o', 'ö' => 'o', 'ŕ' => 'r', // check-english: allow
        'ř' => 'r', 'š' => 's', 'ť' => 't', 'ú' => 'u', 'ů' => 'u', 'ü' => 'u', 'ý' => 'y', // check-english: allow
        'ž' => 'z', 'ß' => 'ss', // check-english: allow
    ];
    $text = strtr(mb_strtolower($text), $map);
    if (preg_match('/[\x{0600}-\x{06FF}]/u', $text) === 1) {
        $text = strtr((string) preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', $text), [
            'ا' => 'a', 'أ' => 'a', 'إ' => 'i', 'آ' => 'a', 'ٱ' => 'a', 'ء' => '', 'ؤ' => 'u', 'ئ' => 'y', 'ب' => 'b', 'ت' => 't', 'ث' => 'th',
            'ج' => 'j', 'ح' => 'h', 'خ' => 'kh', 'د' => 'd', 'ذ' => 'dh', 'ر' => 'r', 'ز' => 'z', 'س' => 's', 'ش' => 'sh', 'ص' => 's',
            'ض' => 'd', 'ط' => 't', 'ظ' => 'z', 'ع' => '', 'غ' => 'gh', 'ف' => 'f', 'ق' => 'q', 'ك' => 'k', 'ل' => 'l', 'م' => 'm',
            'ن' => 'n', 'ه' => 'h', 'ة' => 'a', 'و' => 'w', 'ي' => 'y', 'ى' => 'a', 'پ' => 'p', 'چ' => 'ch', 'ژ' => 'zh', 'گ' => 'g',
            'ک' => 'k', 'ی' => 'y', '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7',
            '٨' => '8', '٩' => '9', '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        ]);
    }
    $text = preg_replace('/[^a-z0-9]+/', '-', $text) ?? '';
    $text = trim(substr($text, 0, $maxLength), '-');

    return $text !== '' ? $text : 'n-a';
}

/** Decimal number in the site language: 4,5 in Czech, Slovak and German, 4.5 in English and Arabic (Western digits). */
function format_number(float|int $number, int $decimals = 1): string
{
<<<<<<< HEAD
    return number_format((float) $number, $decimals, Talea\Core\Language::base(Talea\Core\Language::code()) === 'en' ? '.' : ',', '');
=======
    return number_format((float) $number, $decimals, in_array(Talea\Core\Language::code(), ['en', 'ar'], true) ? '.' : ',', '');
>>>>>>> cdc50e21 (Arabic and right-to-left (1/4): Language::isRtl/dir, Arabic slugs, search and bidi isolation)
}

/** Count with a thousands separator in the site language: 12 345 in Czech and Slovak, 12,345 in English and Arabic, 12.345 in German. */
function format_count(float|int $number, int $decimals = 0): string
{
<<<<<<< HEAD
    [$decimalSeparator, $thousandsSeparator] = match (Talea\Core\Language::base(Talea\Core\Language::code())) {
        'en' => ['.', ','],
=======
    [$decimalSeparator, $thousandsSeparator] = match (Talea\Core\Language::code()) {
        'en', 'ar' => ['.', ','],
>>>>>>> cdc50e21 (Arabic and right-to-left (1/4): Language::isRtl/dir, Arabic slugs, search and bidi isolation)
        'de' => [',', '.'],
        default => [',', "\u{00A0}"],
    };

    return number_format((float) $number, $decimals, $decimalSeparator, $thousandsSeparator);
}

/** Czech date: 18. 9. 2026, optionally with the time. */
function format_date(string|\DateTimeInterface|null $value, bool $withTime = false): string
{
    if ($value === null || $value === '') {
        return '';
    }
    $dt = $value instanceof \DateTimeInterface ? $value : new \DateTimeImmutable($value);

    // date form by language: the dictionary may provide the key "datum_format" (a format for date()), e.g. "j M Y" for English
    $format = t('datum_format');
    $format = $format === 'datum_format' ? 'j. n. Y' : $format;

    return $dt->format($withTime ? $format . ' H:i' : $format);
}

/** Date in words: "Friday, 18. September 2026". */
function format_date_long(string|\DateTimeInterface|null $value = null): string
{
    $days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
    $months = [1 => 'January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
    $dt = $value instanceof \DateTimeInterface ? $value : new \DateTimeImmutable($value ?? 'now');
    // a language without its own dictionary: date in words by locale from the intl extension ("Freitag, 25. September 2026")
    if (($locale = \Talea\Core\Language::intlLocale()) !== null) {
        $text = (new \IntlDateFormatter($locale, \IntlDateFormatter::FULL, \IntlDateFormatter::NONE, $dt->getTimezone()))->format($dt);
        if (is_string($text) && $text !== '') {
            return $text;
        }
    }
    // the language dictionary may provide its own date form: the key "date_in_words" = a format for date(), e.g. "l j F Y"
    $format = t('date_in_words');
    if ($format !== 'date_in_words') {
        return preg_replace_callback('/[A-Za-zÀ-ž]{3,}/u', fn (array $m): string => t($m[0]), $dt->format($format)) ?? $dt->format($format); // check-english: allow
    }

    if (\Talea\Core\Language::base(\Talea\Core\Language::code()) === 'cs') {
        // Czech needs the genitive of the month ("7. října" check-english: allow), which the dictionary of nominative month names does not have
        $csDays = ['neděle', 'pondělí', 'úterý', 'středa', 'čtvrtek', 'pátek', 'sobota']; // check-english: allow
        $csMonths = [1 => 'ledna', 'února', 'března', 'dubna', 'května', 'června', 'července', 'srpna', 'září', 'října', 'listopadu', 'prosince']; // check-english: allow

        return $csDays[(int) $dt->format('w')] . ' ' . $dt->format('j') . '. ' . $csMonths[(int) $dt->format('n')] . ' ' . $dt->format('Y');
    }

    return t($days[(int) $dt->format('w')]) . ' ' . $dt->format('j') . '. ' . t($months[(int) $dt->format('n')]) . ' ' . $dt->format('Y');
}
