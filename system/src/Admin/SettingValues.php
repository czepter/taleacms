<?php

declare(strict_types=1);

namespace Talea\Admin;

final class SettingValues
{
    /** The value of a settings field cleaned by its type (the grammar is described at Modules\Settings::FIELDS); null = invalid. */
    public static function sanitize(string $type, string $value, bool $checked): ?string
    {
        [$kind, $parameter] = explode(':', $type, 2) + [1 => ''];

        return match ($kind) {
            'flag' => $checked ? '1' : '0',
            'text' => mb_substr(str_replace(["\r", "\n"], ' ', $value), 0, 500),
            'lines' => mb_substr($value, 0, 5000),
            'code' => mb_substr($value, 0, 20000),
            'email' => $value === '' || filter_var($value, FILTER_VALIDATE_EMAIL) ? $value : null,
            'emails' => (function () use ($value): ?string {
                $list = array_values(array_filter(preg_split('/[\s,;]+/', $value) ?: [], static fn (string $e): bool => $e !== ''));
                if (count($list) > \Talea\Core\MonthlyReport::MAX_RECIPIENTS || array_filter($list, static fn (string $e): bool => filter_var($e, FILTER_VALIDATE_EMAIL) === false) !== []) {
                    return null; // one bad address rejects the field, so the owner sees it instead of a silently dropped recipient
                }

                return implode("\n", $list);
            })(),
            'url' => $value === '' || (preg_match('#^https?://#i', $value) && filter_var($value, FILTER_VALIDATE_URL)) ? rtrim($value) : null,
            'number' => (function () use ($value, $parameter): string {
                [$min, $max] = array_map(intval(...), explode(':', $parameter));

                return (string) max($min, min($max, (int) $value));
            })(),
            'choice' => in_array($value, explode('|', $parameter), true) ? $value : null,
            'timezone' => in_array($value, \DateTimeZone::listIdentifiers(), true) ? $value : null,
            'pattern' => preg_match($parameter, $value) ? $value : null,
            'message' => mb_strlen($value) <= \Talea\Core\Messages::MAX && \Talea\Core\Messages::check($parameter, $value) === null ? str_replace("\r\n", "\n", $value) : null,
            'hours' => \Talea\Front\Company::parseOpeningHours($value) !== null ? mb_substr(trim($value), 0, 1000) : null,
            default => null,
        };
    }

}
