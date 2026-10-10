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

/** Converting text to URL form: "Müller Straße" -> "muller-strasse". */
function slugify(string $text, int $maxLength = 120): string
{
    $map = [
        'á' => 'a', 'ä' => 'a', 'č' => 'c', 'ď' => 'd', 'é' => 'e', 'ě' => 'e', 'í' => 'i', // check-english: allow
        'ľ' => 'l', 'ĺ' => 'l', 'ň' => 'n', 'ó' => 'o', 'ô' => 'o', 'ö' => 'o', 'ŕ' => 'r', // check-english: allow
        'ř' => 'r', 'š' => 's', 'ť' => 't', 'ú' => 'u', 'ů' => 'u', 'ü' => 'u', 'ý' => 'y', // check-english: allow
        'ž' => 'z', 'ß' => 'ss', // check-english: allow
    ];
    $text = strtr(mb_strtolower($text), $map);
    $text = preg_replace('/[^a-z0-9]+/', '-', $text) ?? '';
    $text = trim(substr($text, 0, $maxLength), '-');

    return $text !== '' ? $text : 'n-a';
}

/** Decimal number in the site language: 4,5 in Czech, Slovak and German, 4.5 in English. */
function format_number(float|int $number, int $decimals = 1): string
{
    return number_format((float) $number, $decimals, Talea\Core\Language::code() === 'en' ? '.' : ',', '');
}

/** Count with a thousands separator in the site language: 12 345 in Czech and Slovak, 12,345 in English, 12.345 in German. */
function format_count(float|int $number, int $decimals = 0): string
{
    [$decimalSeparator, $thousandsSeparator] = match (Talea\Core\Language::code()) {
        'en' => ['.', ','],
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

    if (\Talea\Core\Language::code() === 'cs') {
        // Czech needs the genitive of the month ("7. října" check-english: allow), which the dictionary of nominative month names does not have
        $csDays = ['neděle', 'pondělí', 'úterý', 'středa', 'čtvrtek', 'pátek', 'sobota']; // check-english: allow
        $csMonths = [1 => 'ledna', 'února', 'března', 'dubna', 'května', 'června', 'července', 'srpna', 'září', 'října', 'listopadu', 'prosince']; // check-english: allow

        return $csDays[(int) $dt->format('w')] . ' ' . $dt->format('j') . '. ' . $csMonths[(int) $dt->format('n')] . ' ' . $dt->format('Y');
    }

    return t($days[(int) $dt->format('w')]) . ' ' . $dt->format('j') . '. ' . t($months[(int) $dt->format('n')]) . ' ' . $dt->format('Y');
}
