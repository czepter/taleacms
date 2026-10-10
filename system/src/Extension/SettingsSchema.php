<?php

declare(strict_types=1);

namespace Talea\Extension;

use Talea\Core\Request;

/** The declared settings of an add-on (Api::settings, API 2): checking values and the form on the add-on's settings page. */
final class SettingsSchema
{
    /**
     * @param array<string, mixed> $schema
     * @return array<string, array{label: string, type: string, default: string, help: string}>
     */
    public static function normalize(array $schema): array
    {
        $out = [];
        foreach ($schema as $name => $field) {
            $type = is_array($field) && is_string($field['type'] ?? null) ? $field['type'] : '';
            if (preg_match('/^[a-z][a-z0-9_]{0,40}$/', (string) $name) !== 1 || !is_array($field) || !is_string($field['label'] ?? null)
                || preg_match('/^(text|lines|flag|email|url|number:-?\d{1,9}:-?\d{1,9}|choice:[^:]{1,200})$/', $type) !== 1) {
                throw new \InvalidArgumentException('The setting "' . $name . '" needs a label and a type (text, lines, flag, email, url, number:min:max, choice:a|b).');
            }
            $out[(string) $name] = ['label' => $field['label'], 'type' => $type, 'default' => (string) ($field['default'] ?? ''), 'help' => (string) ($field['help'] ?? '')];
        }

        return $out;
    }

    /** The clean value, null = not valid. A flag is '1' or '0'. */
    public static function sanitize(string $type, string $value): ?string
    {
        $value = trim($value);
        [$kind, $parameter] = explode(':', $type, 2) + [1 => ''];

        return match ($kind) {
            'flag' => in_array(strtolower($value), ['1', 'true', 'on', 'yes'], true) ? '1' : '0',
            'text' => mb_substr(str_replace(["\r", "\n"], ' ', $value), 0, 500),
            'lines' => mb_substr($value, 0, 5000),
            'email' => $value === '' || filter_var($value, FILTER_VALIDATE_EMAIL) !== false ? $value : null,
            'url' => $value === '' || (preg_match('#^https?://#i', $value) === 1 && filter_var($value, FILTER_VALIDATE_URL) !== false) ? $value : null,
            'number' => (function () use ($value, $parameter): ?string {
                [$min, $max] = array_map(intval(...), explode(':', $parameter));

                return preg_match('/^-?\d{1,9}$/', $value) === 1 ? (string) max($min, min($max, (int) $value)) : null;
            })(),
            'choice' => in_array($value, explode('|', $parameter), true) ? $value : null,
            default => null,
        };
    }

    /**
     * The page body: a form of the schema (saved when posted), then what the add-on adds ($extra).
     *
     * @param array<string, array{label: string, type: string, default: string, help: string}> $schema
     */
    public static function page(Api $api, array $schema, Request $request, ?callable $extra): string
    {
        $notice = '';
        if ($request->isPost() && $request->post('_ext_settings') === '1') {
            $invalid = [];
            foreach ($schema as $name => $field) {
                $clean = $field['type'] === 'flag' ? ($request->postBool($name) ? '1' : '0') : self::sanitize($field['type'], $request->post($name));
                if ($clean === null) {
                    $invalid[] = t($field['label']);
                } else {
                    $api->set($name, $clean);
                }
            }
            $notice = $invalid === [] ? '<p class="notice notice-ok">' . e(t('Saved.')) . '</p>'
                : '<p class="notice notice-error">' . e(t('These fields have an invalid format and were not saved: %s.', implode(', ', $invalid))) . '</p>';
        }
        $html = $notice . '<form method="post">' . $api->app()->session->csrfField() . '<input type="hidden" name="_ext_settings" value="1">';
        foreach ($schema as $name => $field) {
            $value = $api->get($name);
            $help = $field['help'] !== '' ? '<br><span class="small-text">' . e(t($field['help'])) . '</span>' : '';
            $html .= '<p><label>' . ($field['type'] === 'flag'
                ? '<input type="checkbox" name="' . e($name) . '" value="1"' . ($value === '1' ? ' checked' : '') . '> ' . e(t($field['label']))
                : e(t($field['label'])) . '<br>' . self::input($name, $field['type'], $value)) . '</label>' . $help . '</p>';
        }

        return $html . '<p><input class="btn" type="submit" value="' . e(t('Save settings')) . '"></p></form>' . ($extra !== null ? (string) $extra($request) : '');
    }

    private static function input(string $name, string $type, string $value): string
    {
        [$kind, $parameter] = explode(':', $type, 2) + [1 => ''];
        $name = e($name);
        $value = e($value);

        return match ($kind) {
            'lines' => '<textarea name="' . $name . '" rows="4" cols="60">' . $value . '</textarea>',
            'choice' => '<select name="' . $name . '">' . implode('', array_map(fn (string $option): string => '<option value="' . e($option) . '"' . ($option === html_entity_decode($value, ENT_QUOTES) ? ' selected' : '') . '>' . e($option === '' ? '–' : $option) . '</option>', explode('|', $parameter))) . '</select>',
            'number' => '<input type="number" name="' . $name . '" value="' . $value . '" min="' . e(explode(':', $parameter)[0]) . '" max="' . e(explode(':', $parameter)[1]) . '">',
            default => '<input type="' . ($kind === 'email' ? 'email' : ($kind === 'url' ? 'url' : 'text')) . '" name="' . $name . '" value="' . $value . '" size="40">',
        };
    }
}
