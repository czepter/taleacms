<?php

declare(strict_types=1);

namespace Talea\Front;

/**
 * The owner's own text of a form's e-mails (docs/specs/form-builder.md): {Field label} (or the field's technical name, {name}) and {Field label|text when empty} are
 * filled with the answers, {site name}, {next steps} and {result} with what the site knows. An e-mail to the VISITOR only takes
 * answers that cannot carry someone else's words – a choice from the form's options, a date, a number, a time range, and a
 * short name – so the form cannot be used to send anyone's text to any address. The owner's notification takes every answer.
 */
final class FormMail
{
    /** Answers an e-mail to the visitor may repeat: their value is one of the form's own options or has a fixed shape. */
    public const array SAFE_TYPES = ['select', 'radio', 'checkboxes', 'date', 'number', 'timerange'];

    public const string TOKEN = '/\{([^{}|\r\n]{1,200})(?:\|([^{}\r\n]{0,200}))?\}/u';

    /**
     * @param list<array{0: string, 1: string, name?: string}> $data the answers as the enquiry stores them: [label, value, name: technical name]
     * @param list<string>                      $types  the field type of each answer (Front\Forms)
     * @param array<string, string>             $extra  more placeholders by their lower-case name (site name, next steps, result)
     */
    public static function fill(string $template, array $data, array $types, bool $toVisitor, array $extra = []): string
    {
        $values = [];
        // the technical name first: it is unique per form and stable, so on a clash with a label (which may repeat or change) it wins
        $visible = static function (int $i, string $value) use ($types, $toVisitor): string {
            $type = $types[$i] ?? '';

            return !$toVisitor || in_array($type, self::SAFE_TYPES, true) || ($type === 'text' && self::isName($value)) ? $value : '';
        };
        foreach ($data as $i => $answer) {
            $technical = mb_strtolower(trim((string) ($answer['name'] ?? '')));
            if ($technical !== '' && !isset($values[$technical])) {
                $values[$technical] = $visible($i, trim($answer[1]));
            }
        }
        foreach ($data as $i => $answer) {
            $label = mb_strtolower(trim($answer[0]));
            if ($label === '' || isset($values[$label])) {
                continue;
            }
            $values[$label] = $visible($i, trim($answer[1]));
        }
        foreach ($extra as $name => $value) {
            $values[mb_strtolower($name)] = $value;
        }

        return (string) preg_replace_callback(self::TOKEN, function (array $m) use ($values): string {
            $name = mb_strtolower(trim($m[1]));
            if (!array_key_exists($name, $values)) {
                return $m[0]; // not a field of this form: the owner sees the mistake in the test e-mail
            }

            return $values[$name] !== '' ? $values[$name] : trim($m[2] ?? '');
        }, $template);
    }

    /** One line for a subject: no line breaks, at most 200 characters. */
    public static function subject(string $text): string
    {
        return mb_substr(trim((string) preg_replace('/\s+/u', ' ', $text)), 0, 200);
    }

    /** A short text the visitor typed that may stand in an e-mail to them: a name – no address, link, @ or angle bracket, at most 60 characters. */
    public static function isName(string $value): bool
    {
        return $value !== '' && mb_strlen($value) <= 60 && preg_match('#(://|www\.|@|<|>|\.[a-z]{2,})#iu', $value) !== 1; // a dot right before letters is a domain
    }
}
