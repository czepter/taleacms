<?php

declare(strict_types=1);

namespace Talea\Builder;

/**
 * The logic of a form (rules with conditions and actions, result variants) and the visibility of its fields.
 * Pure: the server and the tests use it; image/web.js mirrors only the visibility part (never amounts).
 *
 * A rule is {label, broken?, match: all|any, when: [{field: key, op, value?, to?}], then: [{do, …}]}. A rule that names an option
 * the field no longer has is kept with broken: true so that the editor can show it, but rules() leaves it out: it does nothing on the site.
 */
final class FormLogic
{
    public const array OPERATORS = ['is', 'is_not', 'contains', 'not_contains', 'gt', 'lt', 'between', 'weekday', 'month', 'empty', 'not_empty'];
    public const array ACTIONS = ['add', 'quantity', 'multiply', 'skip', 'show_when', 'result'];
    public const int MAX_RULES = 50;
    public const int MAX_CONDITIONS = 10;
    public const int MAX_ACTIONS = 5;
    public const int MAX_RESULTS = 10;

    private const float MAX_AMOUNT = 10000000.0;
    private const array CHOICE_OPTIONS = ['select' => 'options', 'radio' => 'choices', 'checkboxes' => 'checkbox_options'];

    /**
     * @param list<array<string, mixed>> $fields fields of the form, each with its key
     * @param array<string, string> $errors
     * @return array{rules: list<array<string, mixed>>, results: list<array<string, mixed>>}
     */
    public static function sanitize(mixed $logic, array $fields, string $place, array &$errors): array
    {
        $logic = is_array($logic) ? $logic : [];
        $position = [];
        foreach ($fields as $i => $f) {
            if (self::str($f['key'] ?? '') !== '') {
                $position[self::str($f['key'])] ??= (int) $i;
            }
        }
        $results = self::sanitizeResults(is_array($logic['results'] ?? null) ? $logic['results'] : [], $place . '.results', $errors);
        $variants = array_column($results, 'key');

        $rules = [];
        foreach (self::cap($logic['rules'] ?? null, self::MAX_RULES, $place . '.rules', $errors) as $n => $raw) {
            $at = $place . '.rules.' . $n;
            if (!is_array($raw)) {
                continue;
            }
            $broken = false;
            $when = [];
            foreach (self::cap($raw['when'] ?? null, self::MAX_CONDITIONS, $at . '.when', $errors) as $c) {
                $key = is_array($c) ? self::str($c['field'] ?? '') : '';
                $op = is_array($c) ? self::str($c['op'] ?? '') : '';
                $known = isset($position[$key]);
                if (!$known || !in_array($op, self::OPERATORS, true)) {
                    // kept so that the editor can show it, but the whole rule does nothing: dropping it would make the rule fire more broadly
                    $broken = true;
                    $errors[$at . '.when'] = 'A condition names an unknown question or operator; the rule does nothing until you fix it.';
                    $key = preg_match('/^k[a-z0-9]{4}$/', $key) === 1 ? $key : '';
                    $op = in_array($op, self::OPERATORS, true) ? $op : '';
                }
                $one = ['field' => $key, 'op' => $op];
                if (!in_array($op, ['empty', 'not_empty'], true)) {
                    $value = is_scalar($c['value'] ?? null) ? trim((string) $c['value']) : '';
                    $to = is_scalar($c['to'] ?? null) ? trim((string) $c['to']) : '';
                    if (mb_strlen($value) > 200 || mb_strlen($to) > 200) {
                        $errors[$at . '.when'] = 'A condition value is longer than 200 characters and was dropped.';
                        continue;
                    }
                    $one['value'] = $value;
                    if ($op === 'between') {
                        $one['to'] = $to;
                    }
                    $options = $known ? self::options($fields[$position[$key]]) : null;
                    if (in_array($op, ['is', 'is_not', 'contains', 'not_contains'], true) && $options !== null && !in_array($value, $options, true)) {
                        $broken = true;
                        $errors[$at . '.when'] = 'A condition names an answer (' . mb_substr($value, 0, 40) . ') that the question does not offer; the rule does nothing until you fix it.';
                    }
                }
                $when[] = $one;
            }
            $latest = $when === [] ? -1 : max(array_map(fn (array $c): int => $position[$c['field']] ?? -1, $when));
            $then = [];
            $lookBack = true;
            foreach (self::cap($raw['then'] ?? null, self::MAX_ACTIONS, $at . '.then', $errors) as $a) {
                $action = is_array($a) ? self::action($a, $position, $variants, $broken) : null;
                if ($action === null) {
                    $errors[$at . '.then'] = 'An action is unknown or incomplete and was dropped.';
                    continue;
                }
                if (in_array($action['do'], ['skip', 'show_when'], true) && ($position[$action['field']] ?? PHP_INT_MAX) <= $latest) {
                    $lookBack = false;
                    continue;
                }
                $then[] = $action;
            }
            if (!$lookBack) {
                $errors[$at] = 'A rule can only hide or show a question that comes after the questions it asks about; the rule was dropped.';
            }
            if ($when === [] || $then === [] || !$lookBack) {
                $errors[$at] ??= 'A rule needs at least one condition and one action; the rule was dropped.';
                continue;
            }
            $rule = ['label' => mb_substr(trim(strip_tags(self::str($raw['label'] ?? ''))), 0, 120), 'match' => ($raw['match'] ?? '') === 'any' ? 'any' : 'all', 'when' => $when, 'then' => $then];
            $rules[] = $broken ? ['broken' => true] + $rule : $rule;
        }

        return ['rules' => $rules, 'results' => $results];
    }

    /** @param array<string, int> $position @param list<string> $variants */
    private static function action(array $a, array $position, array $variants, bool &$broken): ?array
    {
        $do = self::str($a['do'] ?? '');
        $field = self::str($a['field'] ?? '');
        if (in_array($do, ['quantity', 'skip', 'show_when'], true) && !isset($position[$field])) {
            $broken = true; // an unknown target: the rule does nothing, the editor shows it
            $field = preg_match('/^k[a-z0-9]{4}$/', $field) === 1 ? $field : '';
            $position[$field] = PHP_INT_MAX;
        }
        switch ($do) {
            case 'add':
                $amount = self::amount($a['amount'] ?? null);

                return $amount === null ? null : ['do' => 'add', 'amount' => $amount];
            case 'quantity':
                $amount = self::amount($a['amount'] ?? null);

                return $amount === null || !isset($position[$field]) ? null : ['do' => 'quantity', 'field' => $field, 'amount' => $amount];
            case 'multiply':
                $by = self::amount($a['by'] ?? null);

                return $by === null ? null : ['do' => 'multiply', 'by' => max(0.0, min(100.0, $by))];
            case 'skip':
            case 'show_when':
                return isset($position[$field]) ? ['do' => $do, 'field' => $field] : null;
            case 'result':
                $variant = self::str($a['variant'] ?? '');
                if (preg_match('/^v[a-z0-9]{1,8}$/', $variant) !== 1) {
                    return null;
                }
                $broken = $broken || !in_array($variant, $variants, true);

                return ['do' => 'result', 'variant' => $variant];
        }

        return null;
    }

    private static function str(mixed $v): string
    {
        return is_scalar($v) ? (string) $v : '';
    }

    /** The first $max entries of a list; the rest is reported. @return list<mixed> */
    private static function cap(mixed $list, int $max, string $at, array &$errors): array
    {
        $list = is_array($list) ? array_values($list) : [];
        if (count($list) > $max) {
            $errors[$at] = 'At most ' . $max . ' entries are kept; the rest was dropped.';
        }

        return array_slice($list, 0, $max);
    }

    private static function amount(mixed $v): ?float
    {
        $v = is_string($v) ? str_replace(',', '.', trim($v)) : $v;

        return is_numeric($v) ? max(-self::MAX_AMOUNT, min(self::MAX_AMOUNT, (float) $v)) : null;
    }

    /** @param list<mixed> $results @param array<string, string> $errors */
    private static function sanitizeResults(array $results, string $place, array &$errors): array
    {
        $clean = [];
        $seen = [];
        foreach (self::cap($results, self::MAX_RESULTS, $place, $errors) as $n => $r) {
            $key = is_array($r) ? self::str($r['key'] ?? '') : '';
            if (preg_match('/^v[a-z0-9]{1,8}$/', $key) !== 1 || isset($seen[$key])) {
                $errors[$place . '.' . $n] = 'A result needs a unique key (v followed by letters or digits); it was dropped.';
                continue;
            }
            $seen[$key] = true;
            $image = is_string($r['image'] ?? null) ? $r['image'] : '';
            $okImage = $image !== '' && preg_match(Build::IMAGE_PATTERN, $image) === 1;
            if ($image !== '' && !$okImage) {
                $errors[$place . '.' . $n . '.image'] = 'The image must come from Media (media/…) or be at an https:// address.';
            }
            $clean[] = [
                'key' => $key,
                'heading' => mb_substr(trim(strip_tags(self::str($r['heading'] ?? ''))), 0, 120),
                'text' => mb_substr(strip_tags(self::str($r['text'] ?? '')), 0, 2000),
                'show_amount' => (bool) ($r['show_amount'] ?? false),
                'button_text' => mb_substr(trim(strip_tags(self::str($r['button_text'] ?? ''))), 0, 80),
                'button_link' => Build::link(is_scalar($r['button_link'] ?? null) ? (string) $r['button_link'] : '', $place . '.' . $n . '.button_link', $errors),
                'image' => $okImage ? $image : '',
            ];
        }

        return $clean;
    }

    /** The answers a choice field offers (a price after | is not part of the answer), or null for a field without options. @return list<string>|null */
    private static function options(array $field): ?array
    {
        $prop = self::CHOICE_OPTIONS[(string) ($field['type'] ?? '')] ?? null;
        if ($prop === null) {
            return null;
        }
        $out = [];
        foreach (preg_split('/\R/u', (string) ($field[$prop] ?? '')) ?: [] as $line) {
            $line = trim((string) preg_replace('/\s*\|\s*-?\d[\d \x{a0}]*(?:[.,]\d+)?\s*$/u', '', $line));
            if ($line !== '') {
                $out[] = $line;
            }
        }

        return $out;
    }

    /**
     * The rules that act on the site: the old one-condition fields (show_when_field / show_when_value) as rules, then the stored
     * rules that are not broken.
     *
     * @return list<array<string, mixed>>
     */
    public static function rules(array $content): array
    {
        $fields = array_values(is_array($content['fields'] ?? null) ? $content['fields'] : []);
        $byLabel = [];
        foreach ($fields as $f) {
            $byLabel[mb_strtolower(trim((string) ($f['label'] ?? '')))] ??= $f;
        }
        $rules = [];
        foreach ($fields as $f) {
            $label = mb_strtolower(trim((string) ($f['show_when_field'] ?? '')));
            if ($label === '' || ($f['key'] ?? '') === '') {
                continue;
            }
            $target = $byLabel[$label] ?? null;
            $expected = trim((string) ($f['show_when_value'] ?? ''));
            $condition = ['field' => (string) ($target['key'] ?? ''), 'op' => $expected === '*' ? 'not_empty' : (($target['type'] ?? '') === 'checkboxes' ? 'contains' : 'is')];
            if ($expected !== '*') {
                $condition['value'] = $expected;
            }
            $rules[] = ['label' => '', 'match' => 'all', 'when' => [$condition], 'then' => [['do' => 'show_when', 'field' => (string) $f['key']]]];
        }
        foreach (is_array($content['logic']['rules'] ?? null) ? $content['logic']['rules'] : [] as $rule) {
            if (is_array($rule) && empty($rule['broken'])) {
                $rules[] = $rule;
            }
        }

        return $rules;
    }

    /** The numeric value of an answer: a number (comma decimal allowed) or the hours of a time range (past midnight wraps); null otherwise. */
    public static function value(array $field, string|array $answer): ?float
    {
        if (is_array($answer)) {
            return null;
        }
        $answer = trim($answer);
        if (($field['type'] ?? '') === 'timerange') {
            if (preg_match('/^(\d{1,2}):(\d{2})-(\d{1,2}):(\d{2})$/', $answer, $m) !== 1 || $m[1] > 23 || $m[3] > 23 || $m[2] > 59 || $m[4] > 59) {
                return null;
            }
            $minutes = ((int) $m[3] * 60 + (int) $m[4]) - ((int) $m[1] * 60 + (int) $m[2]);

            return (float) ($minutes < 0 ? $minutes + 1440 : $minutes) / 60;
        }
        $answer = str_replace(',', '.', $answer);

        return is_numeric($answer) ? (float) $answer : null;
    }

    public static function holds(array $condition, array $field, string|array $answer): bool
    {
        $op = (string) ($condition['op'] ?? '');
        $value = trim((string) ($condition['value'] ?? ''));
        $list = is_array($answer) ? array_map(fn (mixed $v): string => trim((string) $v), $answer) : null;
        $text = is_array($answer) ? '' : trim($answer);
        switch ($op) {
            case 'is':
                return $list !== null ? in_array($value, $list, true) : $text === $value;
            case 'is_not':
                return !self::holds(['op' => 'is'] + $condition, $field, $answer);
            case 'contains':
                return $list !== null ? in_array($value, $list, true) : ($value !== '' && mb_stripos($text, $value) !== false);
            case 'not_contains':
                return !self::holds(['op' => 'contains'] + $condition, $field, $answer);
            case 'empty':
                return $list !== null ? $list === [] : $text === '';
            case 'not_empty':
                return !self::holds(['op' => 'empty'] + $condition, $field, $answer);
            case 'gt':
            case 'lt':
            case 'between':
                $n = self::value($field, $answer);
                $a = self::value([], $value);
                $b = self::value([], (string) ($condition['to'] ?? ''));
                if ($n === null || $a === null) {
                    return false;
                }

                return match ($op) {
                    'gt' => $n > $a,
                    'lt' => $n < $a,
                    default => $b !== null && $n >= min($a, $b) && $n <= max($a, $b),
                };
            case 'weekday':
            case 'month':
                if ($list !== null || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $text, $m) !== 1 || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
                    return false;
                }
                $date = new \DateTimeImmutable($text);

                return is_numeric($value) && (int) $date->format($op === 'weekday' ? 'N' : 'n') === (int) $value;
        }

        return false;
    }

    /**
     * Which fields are shown. Fields are walked in order: one is hidden when any skip rule for it holds; when it has show_when
     * rules, at least one must hold too. A condition sees the answer of a hidden field as empty, and a condition that names the
     * field itself or a later one is false.
     *
     * @param list<array<string, mixed>> $fields
     * @param list<array<string, mixed>> $rules
     * @param array<int, string|list<string>> $answers by field index
     * @return array<int, bool>
     */
    public static function visible(array $fields, array $rules, array $answers): array
    {
        $fields = array_values($fields);
        $position = [];
        foreach ($fields as $i => $f) {
            if (self::str($f['key'] ?? '') !== '') {
                $position[self::str($f['key'])] ??= $i;
            }
        }
        $visible = [];
        foreach ($fields as $i => $f) {
            $key = (string) ($f['key'] ?? '');
            $skipped = false;
            $shows = [];
            foreach ($rules as $rule) {
                foreach ($rule['then'] ?? [] as $action) {
                    if (($action['field'] ?? null) !== $key || !in_array($action['do'] ?? '', ['skip', 'show_when'], true)) {
                        continue;
                    }
                    $holds = self::ruleHolds($rule, $fields, $position, $visible, $answers, $i);
                    if ($action['do'] === 'skip') {
                        $skipped = $skipped || $holds;
                    } else {
                        $shows[] = $holds;
                    }
                }
            }
            $visible[$i] = !$skipped && ($shows === [] || in_array(true, $shows, true));
        }

        return $visible;
    }

    /** @param array<string, int> $position @param array<int, bool> $visible */
    private static function ruleHolds(array $rule, array $fields, array $position, array $visible, array $answers, int $before): bool
    {
        $results = [];
        foreach ($rule['when'] ?? [] as $c) {
            $p = $position[(string) ($c['field'] ?? '')] ?? null;
            if ($p === null || $p >= $before) {
                $results[] = false;
                continue;
            }
            $answer = ($visible[$p] ?? false) ? ($answers[$p] ?? '') : (is_array($answers[$p] ?? '') ? [] : '');
            $results[] = self::holds($c, $fields[$p], is_array($answer) || is_string($answer) ? $answer : (string) $answer);
        }
        if ($results === []) {
            return false;
        }

        return ($rule['match'] ?? 'all') === 'any' ? in_array(true, $results, true) : !in_array(false, $results, true);
    }
}
